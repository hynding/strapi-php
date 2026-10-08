<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\GetApiResponses;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\LoopContentTypeNames;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\PascalCase;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\QueryParams;
use Strapi\Plugin\Documentation\Services\Helpers\Utils\Routes;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/helpers/build-api-endpoint-path.ts. Upstream reads the global
 * `strapi`; here it is passed explicitly. `pathToRegexp.parse` (path-to-regexp 8.4.2) and
 * lodash's `_.set` path syntax are ported inline.
 *
 * @phpstan-import-type Api from \Strapi\Plugin\Documentation\Types
 * @phpstan-import-type ApiInfo from \Strapi\Plugin\Documentation\Types
 *
 * @phpstan-type Token array{type: string, value?: string, name?: string, tokens?: list<mixed>}
 */
final class BuildApiEndpointPath
{
    /** Parses a route with ':variable' (the route's path property). */
    private static function parsePathWithVariables(string $routePath): string
    {
        $tokens = self::parse($routePath);

        return implode('', array_map(static function (array $token): string {
            switch ($token['type']) {
                case 'text':
                    return $token['value'] ?? '';
                case 'param':
                case 'wildcard':
                    return '{' . ($token['name'] ?? '') . '}';
                case 'group':
                    // Handle group tokens by mapping them within the same function context
                    // (upstream joins the token objects: `[object Object]`)
                    return '(' . self::parsePathWithVariables(str_repeat('[object Object]', count($token['tokens'] ?? []))) . ')';
                default:
                    throw new \RuntimeException("Unknown token type: {$token['type']}");
            }
        }, $tokens));
    }

    /**
     * Builds the required object for a path parameter.
     *
     * @return list<array<string, mixed>> Swagger path params objects
     */
    private static function getPathParams(string $routePath): array
    {
        $tokens = self::parse($routePath);

        $acc = [];
        foreach ($tokens as $param) {
            // Skip non-parameter tokens
            if ($param['type'] !== 'param') {
                continue;
            }

            $acc[] = [
                'name' => $param['name'] ?? '',
                'in' => 'path',
                'description' => '',
                'deprecated' => false,
                'required' => true,
                'schema' => ['type' => ($param['name'] ?? '') === 'id' ? 'string' : 'number'],
            ];
        }

        return $acc;
    }

    /** @param array<string, mixed> $route */
    private static function getPathWithPrefix(?string $prefix, array $route): string
    {
        // When the prefix is set on the routes and
        // the current route is not trying to remove it
        $hasPrefixConfig = is_array($route['config'] ?? null) && array_key_exists('prefix', $route['config']);
        if ($prefix !== null && $prefix !== '' && !$hasPrefixConfig) {
            // Add the prefix to the path
            return $prefix . (string) $route['path'];
        }

        // Otherwise just return path
        return (string) $route['path'];
    }

    /**
     * Gets all paths based on routes.
     *
     * @param ApiInfo $apiInfo
     *
     * @return array<string, mixed>
     */
    private static function getPaths(array $apiInfo): array
    {
        $routeInfo = $apiInfo['routeInfo'];
        $uniqueName = $apiInfo['uniqueName'];
        $contentTypeInfo = $apiInfo['contentTypeInfo'];
        $kind = $apiInfo['kind'];

        $pluralName = (string) ($contentTypeInfo['pluralName'] ?? '');
        $singularName = (string) ($contentTypeInfo['singularName'] ?? '');

        // Get the routes for the current content type
        $contentTypeRoutes = array_filter(
            is_array($routeInfo['routes'] ?? null) ? $routeInfo['routes'] : [],
            static fn (array $route): bool => str_contains((string) ($route['path'] ?? ''), $pluralName)
                || str_contains((string) ($route['path'] ?? ''), $singularName),
        );

        $prefix = isset($routeInfo['prefix']) && is_string($routeInfo['prefix']) ? $routeInfo['prefix'] : null;

        $acc = [];
        foreach ($contentTypeRoutes as $route) {
            // TODO: Find a more reliable way to determine list of entities vs a single entity
            $isListOfEntities = Routes::hasFindMethod($route['handler'] ?? null);
            $methodVerb = strtolower((string) ($route['method'] ?? ''));
            $hasPathParams = str_contains((string) $route['path'], '/:');
            $pathWithPrefix = self::getPathWithPrefix($prefix, $route);
            $routePath = $hasPathParams ? self::parsePathWithVariables($pathWithPrefix) : $pathWithPrefix;

            $responses = GetApiResponses::getApiResponse([
                'uniqueName' => $uniqueName,
                'route' => $route,
                'isListOfEntities' => $kind !== 'singleType' && $isListOfEntities,
            ]);

            $swaggerConfig = [
                'responses' => $responses,
                'tags' => [Strings::upperFirst($uniqueName)],
                'parameters' => [],
                'operationId' => "{$methodVerb}{$routePath}",
            ];

            if ($isListOfEntities) {
                array_push($swaggerConfig['parameters'], ...QueryParams::PARAMS);
            }

            if ($hasPathParams) {
                $pathParams = self::getPathParams((string) $route['path']);
                array_push($swaggerConfig['parameters'], ...$pathParams);
            }

            if (in_array($methodVerb, ['post', 'put'], true)) {
                $refName = 'Request';
                $requestBody = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/' . PascalCase::pascalCase($uniqueName) . $refName,
                            ],
                        ],
                    ],
                ];

                $swaggerConfig['requestBody'] = $requestBody;
            }

            self::lodashSet($acc, "{$routePath}.{$methodVerb}", $swaggerConfig);
        }

        return $acc;
    }

    /**
     * Builds the Swagger paths object for each api.
     *
     * @param Strapi $strapi
     * @param Api $api
     *
     * @return array<string, mixed>
     */
    public static function buildApiEndpointPath(object $strapi, array $api): array
    {
        // A reusable loop for building paths and component schemas
        // Uses the api param to build a new set of params for each content type
        // Passes these new params to the function provided
        return LoopContentTypeNames::loopContentTypeNames($strapi, $api, self::getPaths(...));
    }

    /**
     * `pathToRegexp.parse(str).tokens` of path-to-regexp 8.4.2.
     *
     * @return list<Token>
     */
    public static function parse(string $str): array
    {
        $chars = mb_str_split($str);
        $index = 0;

        return self::consumeUntil('', $chars, $index, $str);
    }

    /**
     * @param list<string> $chars
     *
     * @return list<Token>
     */
    private static function consumeUntil(string $end, array $chars, int &$index, string $str): array
    {
        $output = [];
        $path = '';
        $count = count($chars);

        while ($index < $count) {
            $value = $chars[$index++];

            if ($value === $end) {
                if ($path !== '') {
                    $output[] = self::token('text', value: $path);
                }

                return $output;
            }

            if ($value === '\\') {
                if ($index === $count) {
                    throw self::pathError("Unexpected end after \\ at index {$index}", $str);
                }
                $path .= $chars[$index++];
                continue;
            }

            if ($value === ':' || $value === '*') {
                $type = $value === ':' ? 'param' : 'wildcard';
                $name = '';

                if (isset($chars[$index]) && preg_match('/^[$_\p{ID_Start}]$/u', $chars[$index]) === 1) {
                    do {
                        $name .= $chars[$index++];
                    } while (isset($chars[$index]) && preg_match('/^[$\x{200c}\x{200d}\p{ID_Continue}]$/u', $chars[$index]) === 1);
                } elseif (($chars[$index] ?? null) === '"') {
                    $quoteStart = $index;
                    while ($index < $count) {
                        if (($chars[++$index] ?? null) === '"') {
                            $index++;
                            $quoteStart = 0;
                            break;
                        }
                        // Increment over escape characters.
                        if (($chars[$index] ?? null) === '\\') {
                            $index++;
                        }
                        $name .= $chars[$index] ?? 'undefined';
                    }
                    if ($quoteStart !== 0) {
                        throw self::pathError("Unterminated quote at index {$quoteStart}", $str);
                    }
                }

                if ($name === '') {
                    throw self::pathError("Missing parameter name at index {$index}", $str);
                }

                if ($path !== '') {
                    $output[] = self::token('text', value: $path);
                    $path = '';
                }
                $output[] = self::token($type, name: $name);
                continue;
            }

            if ($value === '{') {
                if ($path !== '') {
                    $output[] = self::token('text', value: $path);
                    $path = '';
                }
                $output[] = self::token('group', tokens: self::consumeUntil('}', $chars, $index, $str));
                continue;
            }

            if (in_array($value, ['}', '(', ')', '[', ']', '+', '?', '!'], true)) {
                throw self::pathError('Unexpected ' . $value . ' at index ' . ($index - 1), $str);
            }

            $path .= $value;
        }

        if ($end !== '') {
            throw self::pathError("Unexpected end at index {$index}, expected {$end}", $str);
        }

        if ($path !== '') {
            $output[] = self::token('text', value: $path);
        }

        return $output;
    }

    /**
     * @param list<mixed>|null $tokens
     *
     * @return Token
     */
    private static function token(string $type, ?string $value = null, ?string $name = null, ?array $tokens = null): array
    {
        $token = ['type' => $type];
        if ($value !== null) {
            $token['value'] = $value;
        }
        if ($name !== null) {
            $token['name'] = $name;
        }
        if ($tokens !== null) {
            $token['tokens'] = $tokens;
        }

        return $token;
    }

    private static function pathError(string $message, string $originalPath): \TypeError
    {
        $text = $message;
        if ($originalPath !== '') {
            $text .= ": {$originalPath}";
        }
        $text .= '; visit https://git.new/pathToRegexpError for info';

        return new \TypeError($text);
    }

    /**
     * lodash `_.set(object, path, value)` with a string path (`a.b`, `a[0]`, `a["b.c"]`).
     *
     * @param array<array-key, mixed> $object
     */
    private static function lodashSet(array &$object, string $path, mixed $value): void
    {
        $keys = self::stringToPath($path);
        $ref = &$object;
        $last = count($keys) - 1;
        foreach ($keys as $i => $key) {
            if ($i === $last) {
                $ref[$key] = $value;
                break;
            }
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        unset($ref);
    }

    /**
     * lodash `stringToPath`.
     *
     * @return list<string>
     */
    private static function stringToPath(string $string): array
    {
        $result = [];
        if (str_starts_with($string, '.')) {
            $result[] = '';
        }
        preg_match_all('/[^.[\]]+|\[(?:([^"\'][^[]*)|(["\'])((?:(?!\2)[^\\\\]|\\\\.)*?)\2)\]|(?=(?:\.|\[\])(?:\.|\[\]|$))/', $string, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '') {
                $result[] = (string) preg_replace('/\\\\(\\\\)?/', '$1', $match[3] ?? '');
            } elseif (($match[1] ?? '') !== '') {
                $result[] = $match[1];
            } else {
                $result[] = $match[0];
            }
        }

        return $result;
    }
}
