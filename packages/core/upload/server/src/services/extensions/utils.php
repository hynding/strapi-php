<?php

declare(strict_types=1);

namespace Strapi\Upload\Services\Extensions;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Utils\Utils as UploadUtils;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\TraverseEntity;

/**
 * Port of server/src/services/extensions/utils.ts.
 *
 * A sign cache maps a bare (unsigned) URL to its signed URL (or null when the provider does not
 * own it); one per document service call or migration run (`\ArrayObject` so it is shared by
 * reference, as upstream's `Map`).
 *
 * @phpstan-type SignCache \ArrayObject<string, string|null>
 */
final class Utils
{
    /**
     * URL bearing syntaxes found in a richtext (markdown) value. Each pattern
     * captures a prefix (group 1) and the URL (group 2) so the rebuild loop in
     * `mapRichtextUrls` can splice a new URL in place without touching anything
     * else.
     */
    private const array RICHTEXT_URL_REGEXES = [
        '/(!?\[[^\]]*\]\()([^)\s]+)/',
        '/(<(?:img|a|source|video|audio)\b[^>]*?\b(?:src|href)=")([^"]+)/i',
        "/(<(?:img|a|source|video|audio)\\b[^>]*?\\b(?:src|href)=')([^']+)/i",
    ];

    /** Cheap short circuit for richtext values that cannot contain a URL we would rewrite. */
    private const array RICHTEXT_URL_HINTS = ['](', 'src=', 'href='];

    /** @return SignCache */
    public static function createSignCache(): \ArrayObject
    {
        return new \ArrayObject();
    }

    /** @param array<string, mixed>|null $attribute */
    private static function isFile(mixed $value, ?array $attribute): bool
    {
        if (empty($value) || ($attribute['type'] ?? null) !== 'media') {
            return false;
        }

        return true;
    }

    private static function stripQueryString(string $url): string
    {
        return explode('?', $url)[0];
    }

    private static function decodeQueryParamName(string $pair): string
    {
        $name = explode('=', $pair)[0];

        $decoded = rawurldecode($name);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $name;
    }

    /**
     * Split a URL into what precedes the `?`, the raw `key=value` pairs of its
     * query string and the `#fragment`, if any.
     *
     * @return array{base: string, pairs: list<string>, fragment: string}
     */
    private static function splitUrl(string $url): array
    {
        $hashIndex = strpos($url, '#');
        $fragment = $hashIndex === false ? '' : substr($url, $hashIndex);
        $withoutFragment = $hashIndex === false ? $url : substr($url, 0, $hashIndex);

        $queryIndex = strpos($withoutFragment, '?');

        if ($queryIndex === false) {
            return ['base' => $withoutFragment, 'pairs' => [], 'fragment' => $fragment];
        }

        return [
            'base' => substr($withoutFragment, 0, $queryIndex),
            'pairs' => array_values(array_filter(explode('&', substr($withoutFragment, $queryIndex + 1)), static fn (string $p): bool => $p !== '')),
            'fragment' => $fragment,
        ];
    }

    /** @return list<string> Names of the query parameters of `url`, decoded, in order. */
    private static function getQueryParamNames(string $url): array
    {
        return array_map(self::decodeQueryParamName(...), self::splitUrl($url)['pairs']);
    }

    /**
     * `url` with every query parameter whose name is in `names` removed.
     *
     * @param list<string> $names
     */
    private static function removeQueryParams(string $url, array $names): string
    {
        ['base' => $base, 'pairs' => $pairs, 'fragment' => $fragment] = self::splitUrl($url);

        if ($pairs === [] || $names === []) {
            return $url;
        }

        $kept = array_values(array_filter($pairs, static fn (string $pair): bool => !in_array(self::decodeQueryParamName($pair), $names, true)));

        return $kept === [] ? "{$base}{$fragment}" : "{$base}?" . implode('&', $kept) . $fragment;
    }

    /**
     * Build the minimal file shape `signFileUrls` needs out of a bare URL, as found
     * in a richtext value.
     *
     * @return array<string, mixed>
     */
    private static function getFileFromUrl(Strapi $strapi, string $url): array
    {
        $unsignedUrl = self::stripQueryString($url);
        $provider = $strapi->config()->get('plugin::upload.provider');
        $segments = explode('/', $unsignedUrl);
        $last = (string) end($segments);
        $ext = \Strapi\Upload\Utils\MimeTypes::extname($last);
        $name = $ext !== '' ? substr($last, 0, -strlen($ext)) : $last;

        return ['url' => $unsignedUrl, 'hash' => $name, 'ext' => $ext, 'provider' => $provider];
    }

    /**
     * Ask the provider to sign `file` and return the signed URL if the provider
     * owns it, otherwise `null`. Memoised in `cache` by the bare URL.
     *
     * @param array<string, mixed> $file
     * @param SignCache $cache
     */
    private static function signFileIfOwned(Strapi $strapi, array $file, \ArrayObject $cache): ?string
    {
        $bareUrl = is_string($file['url'] ?? null) ? $file['url'] : '';
        if ($cache->offsetExists($bareUrl)) {
            return $cache[$bareUrl];
        }

        $signedFile = UploadUtils::getService('file', $strapi)->signFileUrls($file);
        $signedUrl = $signedFile['url'] ?? null;
        $result = is_string($signedUrl) && $signedUrl !== '' && $signedUrl !== $bareUrl ? $signedUrl : null;

        $cache[$bareUrl] = $result;

        return $result;
    }

    /** @param SignCache $cache */
    private static function signIfOwned(Strapi $strapi, string $url, \ArrayObject $cache): ?string
    {
        return self::signFileIfOwned($strapi, self::getFileFromUrl($strapi, $url), $cache);
    }

    /**
     * Returns a richtext URL with its signature removed, if we own it.
     *
     * @param SignCache $cache
     */
    public static function stripSignedUrl(Strapi $strapi, string $url, \ArrayObject $cache): string
    {
        // Nothing to strip, and no reason to ask the provider
        if (!str_contains($url, '?')) {
            return $url;
        }

        $signedUrl = self::signIfOwned($strapi, $url, $cache);

        if ($signedUrl === null) {
            return $url;
        }

        return self::removeQueryParams($url, self::getQueryParamNames($signedUrl));
    }

    /**
     * Returns a freshly signed richtext URL, if we own it.
     *
     * @param SignCache $cache
     */
    private static function signUrl(Strapi $strapi, string $url, \ArrayObject $cache): string
    {
        return self::signIfOwned($strapi, $url, $cache) ?? $url;
    }

    /**
     * Apply `mapUrl` to every markdown image / link URL and every raw HTML
     * `src` / `href` URL of a richtext value.
     *
     * @param callable(string): string $mapUrl
     */
    public static function mapRichtextUrls(mixed $value, callable $mapUrl): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $hasHint = false;
        foreach (self::RICHTEXT_URL_HINTS as $hint) {
            if (str_contains($value, $hint)) {
                $hasHint = true;
                break;
            }
        }
        if (!$hasHint) {
            return $value;
        }

        // The rebuild loop splices by match index, so matches must be in ascending order
        $matches = [];
        foreach (self::RICHTEXT_URL_REGEXES as $regex) {
            if (preg_match_all($regex, $value, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found as $match) {
                    $matches[] = ['index' => $match[0][1], 'prefix' => $match[1][0], 'url' => $match[2][0]];
                }
            }
        }
        usort($matches, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        if ($matches === []) {
            return $value;
        }

        $urls = array_map(static fn (array $match): string => $mapUrl($match['url']), $matches);

        $result = '';
        $cursor = 0;

        foreach ($matches as $index => $match) {
            $urlStart = $match['index'] + strlen($match['prefix']);

            // Two patterns matched overlapping text: keep the first, never splice twice
            if ($urlStart < $cursor) {
                continue;
            }

            $result .= substr($value, $cursor, $urlStart - $cursor) . $urls[$index];
            $cursor = $urlStart + strlen($match['url']);
        }

        return $result . substr($value, $cursor);
    }

    /**
     * Apply `mapImage` to every `image` node of a blocks value, recursively.
     * A new array is always returned, the original value is never mutated.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mapImage
     */
    public static function mapBlocksImages(mixed $value, callable $mapImage): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            return $value;
        }

        return array_map(static function (mixed $node) use ($mapImage): mixed {
            if (!is_array($node) || $node === []) {
                return $node;
            }

            $result = $node;

            if (($node['type'] ?? null) === 'image' && !empty($node['image']) && is_array($node['image'])) {
                $result = [...$result, 'image' => $mapImage($node['image'])];
            }

            if (is_array($node['children'] ?? null) && array_is_list($node['children'])) {
                $result = [...$result, 'children' => self::mapBlocksImages($node['children'], $mapImage)];
            }

            return $result;
        }, $value);
    }

    /**
     * @param array<string, mixed> $image
     * @return array<string, array<string, mixed>|mixed>
     */
    private static function getImageFormats(array $image): array
    {
        return is_array($image['formats'] ?? null) ? $image['formats'] : [];
    }

    /**
     * True when the node still carries anything `unsignImage` would remove.
     *
     * @param array<string, mixed> $image
     */
    private static function hasSignatureTraces(array $image): bool
    {
        if (array_key_exists('isUrlSigned', $image) || str_contains(is_string($image['url'] ?? null) ? $image['url'] : '', '?')) {
            return true;
        }

        foreach (self::getImageFormats($image) as $format) {
            if (is_array($format) && (array_key_exists('isUrlSigned', $format) || str_contains(is_string($format['url'] ?? null) ? $format['url'] : '', '?'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the signature from a blocks `image` node.
     *
     * @param array<string, mixed> $image
     * @param SignCache|null $cache
     * @return array<string, mixed>
     */
    public static function unsignImage(Strapi $strapi, array $image, ?\ArrayObject $cache = null): array
    {
        $cache ??= self::createSignCache();

        // Already bare: nothing to do, and no reason to ask the provider
        if (!self::hasSignatureTraces($image)) {
            return $image;
        }

        $url = is_string($image['url'] ?? null) ? $image['url'] : '';
        $bareUrl = self::stripQueryString($url);
        $signedUrl = self::signFileIfOwned($strapi, [...$image, 'url' => $bareUrl], $cache);

        // Not one of ours (different provider, external URL, public provider): the
        // provider hands the URL back unchanged, leave the node as is
        if ($signedUrl === null) {
            return $image;
        }

        $names = self::getQueryParamNames($signedUrl);
        $result = [...$image, 'url' => self::removeQueryParams($url, $names)];
        unset($result['isUrlSigned']);

        if (!empty($image['formats'])) {
            $formats = [];
            foreach (self::getImageFormats($image) as $key => $format) {
                if (is_array($format)) {
                    $format = [
                        ...$format,
                        ...(!empty($format['url']) && is_string($format['url']) ? ['url' => self::removeQueryParams($format['url'], $names)] : []),
                    ];
                    unset($format['isUrlSigned']);
                }
                $formats[$key] = $format;
            }
            $result['formats'] = $formats;
        }

        return $result;
    }

    /**
     * Visitor function to sign media URLs
     *
     * @param SignCache $cache
     */
    private static function createSignEntityMediaVisitor(Strapi $strapi, \ArrayObject $cache): \Closure
    {
        return static function (VisitorOptions $options, VisitorUtils $utils) use ($strapi, $cache): void {
            $fileService = UploadUtils::getService('file', $strapi);
            $signFile = static fn (array $file): array => $fileService->signFileUrls($file);
            $attribute = $options->attribute;
            $key = $options->key;
            $value = $options->value;

            if ($attribute === null) {
                return;
            }

            switch ($attribute['type'] ?? null) {
                case 'blocks':
                    $utils->set($key, self::mapBlocksImages($value, $signFile));

                    return;

                case 'richtext':
                    $utils->set($key, self::mapRichtextUrls($value, static fn (string $url): string => self::signUrl($strapi, $url, $cache)));

                    return;

                case 'media':
                    if (self::isFile($value, $attribute) && is_array($value)) {
                        // If the attribute is repeatable sign each file
                        if (!empty($attribute['multiple'])) {
                            $utils->set($key, array_map(static fn (mixed $file): mixed => is_array($file) ? $signFile($file) : $file, $value));

                            return;
                        }

                        // If the attribute is not repeatable only sign a single file
                        $utils->set($key, $signFile($value));
                    }
                    break;

                default:
                    break;
            }
        };
    }

    /**
     * Visitor function to remove the signature from richtext / blocks URLs before
     * they are persisted. Media attributes are left alone: the files table already
     * holds the unsigned URL.
     *
     * @param SignCache $cache
     */
    private static function createUnsignEntityMediaVisitor(Strapi $strapi, \ArrayObject $cache): \Closure
    {
        return static function (VisitorOptions $options, VisitorUtils $utils) use ($strapi, $cache): void {
            $attribute = $options->attribute;
            if ($attribute === null) {
                return;
            }

            if (($attribute['type'] ?? null) === 'blocks') {
                $utils->set($options->key, self::mapBlocksImages($options->value, static fn (array $image): array => self::unsignImage($strapi, $image, $cache)));

                return;
            }

            if (($attribute['type'] ?? null) === 'richtext') {
                $utils->set($options->key, self::mapRichtextUrls($options->value, static fn (string $url): string => self::stripSignedUrl($strapi, $url, $cache)));
            }
        };
    }

    /**
     * Iterate through an entity manager result
     * Check which modelAttributes are media and pre sign the image URLs
     * if they are from the current upload provider
     *
     * @param SignCache|null $cache one per document service call, see `SignCache`
     */
    public static function signEntityMedia(Strapi $strapi, mixed $entity, string $uid, ?\ArrayObject $cache = null): mixed
    {
        $cache ??= self::createSignCache();

        if (empty($entity)) {
            return $entity;
        }

        // If the entity itself is a file, sign it directly
        if ($uid === Constants::FILE_MODEL_UID) {
            return is_array($entity) ? UploadUtils::getService('file', $strapi)->signFileUrls($entity) : $entity;
        }

        // If the entity is a regular content type, look for media attributes
        $model = $strapi->getModel($uid);

        return TraverseEntity::traverse(
            self::createSignEntityMediaVisitor($strapi, $cache),
            ['schema' => $model, 'getModel' => static fn (string $uid) => $strapi->getModel($uid)],
            $entity,
        );
    }

    /**
     * Iterate through the input data of a create / update / clone and replace
     * every signed provider URL found in a richtext or blocks attribute with its
     * unsigned form, so that a short lived signature is never persisted.
     *
     * @param SignCache|null $cache
     */
    public static function unsignEntityMedia(Strapi $strapi, mixed $data, string $uid, ?\ArrayObject $cache = null): mixed
    {
        $cache ??= self::createSignCache();

        if (empty($data) || $uid === Constants::FILE_MODEL_UID) {
            return $data;
        }

        $model = $strapi->getModel($uid);

        return TraverseEntity::traverse(
            self::createUnsignEntityMediaVisitor($strapi, $cache),
            ['schema' => $model, 'getModel' => static fn (string $uid) => $strapi->getModel($uid)],
            $data,
        );
    }
}
