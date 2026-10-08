<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/template.ts: `--template` from a local
 * directory (path or `file://` URL), a GitHub shorthand (`owner/repo[/sub/path]`), a GitHub URL
 * (`https://github.com/owner/repo/tree/branch/sub/path`) or an official template name.
 *
 * A template is a PHP-edition Strapi project (config/*.php, src/index.php...). Official templates
 * are looked up in this port's repository ({@see self::OFFICIAL_REPO}, `templates/<name>`) rather
 * than strapi/strapi, whose templates are Node projects. GitHub tarballs are downloaded with PHP
 * streams and extracted with PharData (upstream: fetch + tar).
 *
 * @phpstan-import-type Scope from Types
 */
final class Template
{
    /** @var array{owner: string, repo: string} */
    public const OFFICIAL_REPO = ['owner' => 'hynding', 'repo' => 'strapi-php'];

    private const OFFICIAL_NAME_REGEX = '/^[a-zA-Z]*$/';

    /** @var (\Closure(string, string): array{status: int, body: string})|null HTTP client override (tests) */
    public static ?\Closure $fetch = null;

    private static function stripTrailingSlash(string $str): string
    {
        return str_ends_with($str, '/') ? substr($str, 0, -1) : $str;
    }

    /**
     * Merge template with new project being created.
     *
     * @param Scope $scope
     */
    public static function copyTemplate(array $scope, string $rootPath): void
    {
        $template = $scope['template'];

        if ($template === null || $template === '') {
            throw new \RuntimeException('Missing template or example app option');
        }

        if (self::isOfficialTemplate($template, $scope['templateBranch'])) {
            self::retry(static fn () => self::downloadGithubRepo($rootPath, [
                'owner' => self::OFFICIAL_REPO['owner'],
                'repo' => self::OFFICIAL_REPO['repo'],
                'branch' => $scope['templateBranch'],
                'subPath' => "templates/{$template}",
            ]));

            return;
        }

        if (self::isLocalTemplate($template)) {
            $filePath = str_starts_with($template, 'file://') ? self::fileURLToPath($template) : CheckInstallPath::resolve($template);

            self::copyDirectory($filePath, $rootPath);
        }

        if (self::isGithubShorthand($template)) {
            $segments = explode('/', $template);
            [$owner, $repo] = [$segments[0], $segments[1]];
            $pathSegments = array_slice($segments, 2);
            $subPath = $pathSegments !== [] ? implode('/', $pathSegments) : $scope['templatePath'];

            self::retry(static fn () => self::downloadGithubRepo($rootPath, ['owner' => $owner, 'repo' => $repo, 'branch' => $scope['templateBranch'], 'subPath' => $subPath]));

            return;
        }

        if (self::isGithubRepo($template)) {
            $path = self::stripTrailingSlash(substr((string) parse_url($template, PHP_URL_PATH), 1));
            $parts = explode('/', $path);
            $owner = $parts[0];
            $repo = $parts[1] ?? '';
            $t = $parts[2] ?? null;
            $branch = $parts[3] ?? null;
            $pathSegments = array_slice($parts, 4);

            if ($t !== null && $t !== 'tree') {
                throw new \RuntimeException("Invalid GitHub template URL: {$template}");
            }

            if ($scope['templateBranch'] !== null && $scope['templateBranch'] !== '') {
                self::retry(static fn () => self::downloadGithubRepo($rootPath, [
                    'owner' => $owner,
                    'repo' => $repo,
                    'branch' => $scope['templateBranch'],
                    'subPath' => $scope['templatePath'],
                ]));

                return;
            }

            self::retry(static fn () => self::downloadGithubRepo($rootPath, [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch !== null ? rawurldecode($branch) : $scope['templateBranch'],
                'subPath' => $pathSegments !== [] ? rawurldecode(implode('/', $pathSegments)) : $scope['templatePath'],
            ]));

            // upstream throws "Invalid GitHub template URL" here even after a successful download
            // (a missing `return`); the port returns
            return;
        }
    }

    /** `async-retry` with `retries: 3`. */
    private static function retry(callable $fn): void
    {
        $attempt = 0;
        while (true) {
            try {
                $fn();

                return;
            } catch (\Throwable $err) {
                $attempt++;
                if ($attempt > 3) {
                    throw $err;
                }
                fwrite(STDOUT, "Retrying to download the template. Attempt {$attempt}. Error: {$err->getMessage()}\n");
                usleep(200_000 * $attempt);
            }
        }
    }

    /**
     * @param array{owner: string, repo: string, branch?: ?string, subPath?: ?string} $info
     */
    private static function downloadGithubRepo(string $rootPath, array $info): void
    {
        ['owner' => $owner, 'repo' => $repo] = $info;
        $branch = $info['branch'] ?? null;
        $subPath = $info['subPath'] ?? null;
        $filePath = $subPath !== null && $subPath !== '' ? implode('/', explode('/', $subPath)) : null;

        $checkContentUrl = "https://api.github.com/repos/{$owner}/{$repo}/contents";
        if ($filePath !== null) {
            $checkContentUrl .= "/{$filePath}";
        }
        if ($branch !== null && $branch !== '') {
            $checkContentUrl .= "?ref={$branch}";
        }

        $checkRes = self::request('HEAD', $checkContentUrl);

        if ($checkRes['status'] !== 200) {
            throw new \RuntimeException(
                "Could not find a template at https://github.com/{$owner}/{$repo}"
                . ($branch !== null && $branch !== '' ? " on branch {$branch}" : '')
                . ($filePath !== null ? " at path {$filePath}" : ''),
            );
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/tarball";
        if ($branch !== null && $branch !== '') {
            $url .= "/{$branch}";
        }

        $res = self::request('GET', $url);
        if ($res['status'] !== 200 || $res['body'] === '') {
            throw new \RuntimeException("Failed to download {$url}");
        }

        self::extractTarball($res['body'], $rootPath, $filePath);
    }

    /**
     * `tar.x({ strip, filter })`: the archive's top directory (and `$filePath`) are stripped and
     * only entries under `$filePath` are extracted.
     */
    public static function extractTarball(string $contents, string $rootPath, ?string $filePath): void
    {
        $tmp = sys_get_temp_dir() . '/strapi-template-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0777, true);
        $archive = $tmp . '/template.tar.gz';
        file_put_contents($archive, $contents);

        try {
            $extractDir = $tmp . '/x';
            (new \PharData($archive))->extractTo($extractDir, null, true);

            $tops = array_values(array_diff(scandir($extractDir) ?: [], ['.', '..']));
            if (count($tops) !== 1 || !is_dir($extractDir . '/' . $tops[0])) {
                throw new \RuntimeException('Unexpected template archive layout');
            }

            $source = $extractDir . '/' . $tops[0] . ($filePath !== null ? '/' . $filePath : '');
            if (!is_dir($source)) {
                throw new \RuntimeException("Path {$filePath} not found in the template archive");
            }

            self::copyDirectory($source, $rootPath);
        } finally {
            self::removeDirectory($tmp);
        }
    }

    /** @return array{status: int, body: string} */
    private static function request(string $method, string $url): array
    {
        if (self::$fetch !== null) {
            return (self::$fetch)($method, $url);
        }

        $headers = ['User-Agent: create-strapi-app (strapi-php)', 'Accept: application/vnd.github+json'];
        $token = getenv('GITHUB_TOKEN');
        if (is_string($token) && $token !== '') {
            $headers[] = "Authorization: Bearer {$token}";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 1,
            'timeout' => 60,
        ]]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        // after redirects the last status line wins
        // `$http_response_header` is only defined when the server answered
        $responseHeaders = get_defined_vars()['http_response_header'] ?? [];
        foreach (is_array($responseHeaders) ? $responseHeaders : [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    private static function isLocalTemplate(string $template): bool
    {
        return str_starts_with($template, 'file://') || file_exists(CheckInstallPath::resolve($template));
    }

    private static function isGithubShorthand(string $value): bool
    {
        if (self::isValidUrl($value)) {
            return false;
        }

        return preg_match('#^[\w-]+/[\w\-.]+(/[\w\-.]+)*$#', $value) === 1;
    }

    private static function isGithubRepo(string $value): bool
    {
        $parts = parse_url($value);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'github.com' && !isset($parts['port']);
    }

    private static function isValidUrl(string $value): bool
    {
        return preg_match('#^[a-zA-Z][a-zA-Z\d+\-.]*:#', $value) === 1 && parse_url($value) !== false;
    }

    private static function isOfficialTemplate(string $template, ?string $branch): bool
    {
        if (self::isValidUrl($template) || preg_match(self::OFFICIAL_NAME_REGEX, $template) !== 1) {
            return false;
        }

        $res = self::request(
            'HEAD',
            'https://api.github.com/repos/' . self::OFFICIAL_REPO['owner'] . '/' . self::OFFICIAL_REPO['repo'] . "/contents/templates/{$template}?" . ($branch !== null && $branch !== '' ? "ref={$branch}" : ''),
        );

        return $res['status'] === 200;
    }

    private static function fileURLToPath(string $url): string
    {
        return rawurldecode((string) parse_url($url, PHP_URL_PATH));
    }

    /** `fse.copy(src, dest)`: recursive, creates `dest`, overwrites files. */
    public static function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0777, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $dest . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
            } else {
                copy($item->getPathname(), $target);
                @chmod($target, $item->getPerms() & 0777);
            }
        }
    }

    /** `fse.remove(path)`. */
    public static function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
