<?php

declare(strict_types=1);

namespace Strapi\Cli\Node;

use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Cli\Node\Core\AdminCustomisations;
use Strapi\Cli\Node\Core\Env;
use Strapi\Cli\Node\Core\Files;
use Strapi\Cli\Node\Core\Plugins;
use Strapi\Cli\Strapi as CliStrapi;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/strapi/src/node/create-build-context.ts.
 *
 * The context the admin build needs: the admin/server URLs from the project configuration, the
 * `STRAPI_ADMIN_*` env vars to inline in the bundle, the enabled plugins with an admin part, the
 * user customisations (`src/admin/app.js`) and the `.strapi/client` runtime directory. The
 * `unstableNextDesignSystem` flag (Tailwind) is honoured for `nextDesignSystem` but no scan roots
 * are computed (the stylesheet is only emitted with the plain `@import`).
 *
 * @phpstan-type BuildContext array{
 *   appDir: string, adminPath: string, basePath: string, bundler: string, customisations: array{path: string, modulePath: string}|null,
 *   cwd: string, distDir: string, distPath: string, entry: string, env: array<string, string>, features: mixed, logger: Logger,
 *   nextDesignSystem: bool, options: array<string, mixed>, plugins: list<array<string, mixed>>, runtimeDir: string, scanRoots: list<string>,
 *   strapi: Strapi, target: list<string>
 * }
 */
final class CreateBuildContext
{
    public const DEFAULT_BROWSERSLIST = ['last 3 major versions', 'Firefox ESR', 'last 2 Opera versions', 'not dead'];

    private const NEXT_DESIGN_SYSTEM_FLAG = 'unstableNextDesignSystem';

    /**
     * @param array{cwd: string, logger: Logger, strapi?: Strapi|null, options?: array<string, mixed>, dev?: bool} $args
     * @return BuildContext
     */
    public static function createBuildContext(array $args): array
    {
        $cwd = $args['cwd'];
        $logger = $args['logger'];
        $options = $args['options'] ?? [];

        /**
         * If you make a new strapi instance when one already exists,
         * you will overwrite the global and the app will _most likely_ crash and die.
         */
        $strapiInstance = $args['strapi'] ?? CliStrapi::createStrapi([
            // Directories
            'appDir' => $cwd,
            'distDir' => $cwd,
            // Options
            'autoReload' => true,
            'serveAdminPanel' => false,
        ]);

        $serverAbsoluteUrl = (string) $strapiInstance->config()->get('server.absoluteUrl');
        $adminAbsoluteUrl = (string) $strapiInstance->config()->get('admin.absoluteUrl');
        $adminPath = (string) $strapiInstance->config()->get('admin.path');

        // NOTE: Checks that both the server and admin will be served from the same origin (protocol, host, port)
        $sameOrigin = self::origin($adminAbsoluteUrl) === self::origin($serverAbsoluteUrl);

        $adminPublicPath = (string) (parse_url($adminAbsoluteUrl, PHP_URL_PATH) ?: '/');
        $serverPublicPath = (string) (parse_url($serverAbsoluteUrl, PHP_URL_PATH) ?: '/');

        $appDir = $strapiInstance->dirs()->root;

        Env::loadEnv($cwd);

        $env = Env::getStrapiAdminEnvVars([
            'ADMIN_PATH' => $adminPublicPath,
            'STRAPI_ADMIN_BACKEND_URL' => $sameOrigin ? $serverPublicPath : $serverAbsoluteUrl,
            'STRAPI_TELEMETRY_DISABLED' => $strapiInstance->telemetry()->isDisabled() ? 'true' : 'false',
            'STRAPI_AI_URL' => rtrim((string) (getenv('STRAPI_AI_URL') ?: 'https://strapi-ai.apps.strapi.io'), '/'),
            'STRAPI_ANALYTICS_URL' => (string) (getenv('STRAPI_ANALYTICS_URL') ?: 'https://analytics.strapi.io'),
        ]);

        // NOTE: Transports `admin.auth.cookie.name` / `path` / `domain` into the bundle
        $env['STRAPI_ADMIN_AUTH_COOKIE_NAME'] = (string) ($strapiInstance->config()->get('admin.auth.cookie.name') ?: '');
        $env['STRAPI_ADMIN_AUTH_COOKIE_PATH'] = (string) ($strapiInstance->config()->get('admin.auth.cookie.path') ?: '');
        $env['STRAPI_ADMIN_AUTH_COOKIE_DOMAIN'] = (string) ($strapiInstance->config()->get('admin.auth.cookie.domain') ?: ($strapiInstance->config()->get('admin.auth.domain') ?: ''));

        if ($env !== []) {
            $logger->debug("Including the following ENV variables as part of the JS bundle:\n" . implode("\n", array_map(static fn (string $key): string => "    - {$key}", array_keys($env))));
        }

        $distPath = $strapiInstance->dirs()->root . '/build';
        $distDir = Files::relative($cwd, $distPath);

        /**
         * If the distPath already exists, clean it
         */
        if (is_dir($distPath)) {
            $logger->debug("Cleaning dist folder: {$distPath}");
            self::rmdir($distPath);
            $logger->debug('Cleaned dist folder');
        } else {
            $logger->debug('There was no dist folder to clean');
        }

        $runtimeDir = $cwd . '/.strapi/client';
        $entry = Files::relative($cwd, $runtimeDir . '/app.js');

        $plugins = Plugins::getEnabledPlugins($cwd, $logger, $runtimeDir, $strapiInstance);

        $logger->debug('Enabled plugins', $plugins);

        $pluginsWithFront = Plugins::getMapOfPluginsWithAdmin($plugins, $cwd);

        $logger->debug('Enabled plugins with FE', $pluginsWithFront);

        $target = self::loadBrowserslist($cwd) ?? self::DEFAULT_BROWSERSLIST;

        $customisations = AdminCustomisations::loadUserAppFile($appDir, $runtimeDir);

        $features = $strapiInstance->config()->get('features');

        $bundler = (string) ($options['bundler'] ?? 'vite');
        unset($options['bundler']);

        $flagEnabled = $strapiInstance->features()->futureIsEnabled(self::NEXT_DESIGN_SYSTEM_FLAG);

        if ($flagEnabled && $bundler !== 'vite') {
            $logger->warn('The ' . self::NEXT_DESIGN_SYSTEM_FLAG . " future flag needs Vite. Tailwind is not available under {$bundler}, so this build has no next design system");
        }

        $nextDesignSystem = $flagEnabled && $bundler === 'vite';

        return [
            'appDir' => $appDir,
            'adminPath' => $adminPath,
            'basePath' => $adminPublicPath,
            'bundler' => $bundler,
            'customisations' => $customisations,
            'cwd' => $cwd,
            'distDir' => $distDir,
            'distPath' => $distPath,
            'entry' => $entry,
            'env' => $env,
            'features' => $features,
            'logger' => $logger,
            'nextDesignSystem' => $nextDesignSystem,
            'options' => $options,
            'plugins' => $pluginsWithFront,
            'runtimeDir' => $runtimeDir,
            'scanRoots' => [],
            'strapi' => $strapiInstance,
            'target' => $target,
        ];
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * `browserslist.loadConfig({ path })`: the `browserslist` key of package.json or a `.browserslistrc`.
     *
     * @return list<string>|null
     */
    public static function loadBrowserslist(string $cwd): ?array
    {
        $packageJson = Plugins::readJson($cwd . '/package.json');
        $list = $packageJson['browserslist'] ?? null;
        if (is_array($list) && $list !== []) {
            return array_values(array_map('strval', array_is_list($list) ? $list : ($list['production'] ?? $list['defaults'] ?? [])));
        }
        $rc = $cwd . '/.browserslistrc';
        if (is_file($rc)) {
            $lines = array_values(array_filter(array_map('trim', (array) file($rc)), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '[')));

            return $lines === [] ? null : $lines;
        }

        return null;
    }

    public static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
