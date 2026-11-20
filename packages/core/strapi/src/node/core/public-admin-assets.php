<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

use Strapi\Cli\Cli\Utils\Logger;

/**
 * Not an upstream file. Node serves the admin bundle from `build/` with koa-static inside the one
 * long-lived process. A per-request PHP backend (PHP-FPM, CGI, mod_php, LiteSpeed) would boot for
 * every chunk of it, and `build/` is outside the document root, so `strapi build` (and `develop`)
 * also copy the bundle to `public/<admin.path>/`: Apache, nginx (`try_files $uri ...`), LiteSpeed,
 * Caddy and PHP's built-in server then serve the assets as files, without PHP.
 *
 * `index.html` is left out: the admin's pages (`/admin`, `/admin/content-manager/...`) and its API
 * (`/admin/login`, ...) share the path, so they keep going to `index.php`, which sends the shell
 * with `Cache-Control: no-cache` as upstream does. The copy carries an `.htaccess` that sends the
 * hashed assets with upstream's `public, max-age=31536000, immutable` and marks the directory as
 * written by the build: a directory without it is the project's own and is never replaced. With
 * no index in it, `/admin/` (the directory itself) still reaches `index.php`: Apache's rewrite,
 * nginx's `try_files $uri /index.php...` and Caddy's `php_server` all pass it on, the latter to
 * the worker in worker mode (an `index.php` here would run outside it, a boot per admin load).
 */
final class PublicAdminAssets
{
    /** First line of the `.htaccess` written into the copy; marks the directory as the build's. */
    public const MARKER = '# Written by `strapi build`';

    /** Files of `build/` that are not copied: the shell goes through PHP (see the class comment). */
    private const SKIP = ['index.html'];

    private const HTACCESS = <<<'HTACCESS'
        # Written by `strapi build`: a copy of build/ (the admin panel) for the web server to serve without
        # PHP. Every build replaces it; delete the directory to have PHP serve the admin assets again.
        # index.html is not copied: the admin's pages go to the front controller, which sends the shell
        # with no-cache.

        Options -Indexes

        <IfModule mod_headers.c>
            Header set Cache-Control "public, max-age=31536000, immutable"
        </IfModule>

        <FilesMatch "(?i)\.(php[0-9]?|pht|phtm|phtml|phar|phps)$">
            Require all denied
        </FilesMatch>

        HTACCESS;

    /**
     * Copies `$buildDir` (without `index.html`) to `$publicDir/<adminPath>`, replacing an earlier
     * copy. Returns the directory written, or null when nothing was (admin at `/`, no build, a
     * directory of the project's own at that path, or a copy that failed: warned, nothing left).
     */
    public static function publish(string $buildDir, string $publicDir, string $adminPath, Logger $logger): ?string
    {
        // `/cms//admin/` and `/./admin` name the same directory as `/cms/admin` and `/admin`
        $relative = implode('/', array_filter(explode('/', str_replace('\\', '/', $adminPath)), static fn (string $part): bool => $part !== '' && $part !== '.'));
        if ($relative === '' || in_array('..', explode('/', $relative), true) || !is_dir($buildDir) || !is_dir($publicDir)) {
            // the admin at the site root would take public/ over: PHP keeps serving it
            return null;
        }

        $target = rtrim($publicDir, '/') . '/' . $relative;

        if ((file_exists($target) || is_link($target)) && !self::isPublished($target)) {
            $logger->warn("{$target} exists and was not written by `strapi build`: the admin assets are not copied there, PHP serves them from build/.");

            return null;
        }

        // build the new copy next to the old one and swap them, so a running site never sees a
        // half-written directory; whatever fails, nothing is left behind under public/
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0o755, true) && !is_dir($parent)) {
            $logger->warn("Cannot create {$parent}: PHP serves the admin assets from build/.");

            return null;
        }
        // what a build killed midway left (the assets themselves, nothing secret); a build running
        // now has written to its own within the hour
        $leftovers = [...(glob($parent . '/.' . basename($target) . '.new-*', GLOB_ONLYDIR) ?: []), ...(glob($parent . '/.' . basename($target) . '.old-*', GLOB_ONLYDIR) ?: [])];
        foreach ($leftovers as $leftover) {
            if ((int) @filemtime($leftover) < time() - 3600) {
                self::remove($leftover);
            }
        }
        $suffix = getmypid() . '-' . bin2hex(random_bytes(3));
        $staging = $parent . '/.' . basename($target) . '.new-' . $suffix;
        $old = $parent . '/.' . basename($target) . '.old-' . $suffix;
        try {
            self::copyTree($buildDir, $staging);
            self::write($staging . '/.htaccess', self::HTACCESS);
            // a build output, wherever admin.path puts it
            self::write($staging . '/.gitignore', "*\n");

            $hadOld = file_exists($target);
            if ($hadOld && !@rename($target, $old)) {
                throw new \RuntimeException("Cannot replace {$target}.");
            }
            if (!@rename($staging, $target)) {
                if ($hadOld) {
                    @rename($old, $target);
                }
                throw new \RuntimeException("Cannot move the admin assets to {$target}.");
            }
        } catch (\RuntimeException $error) {
            // the build itself succeeded: PHP serves the assets from build/ as before
            $logger->warn($error->getMessage() . ' PHP serves the admin assets from build/.');

            return null;
        } finally {
            if (is_dir($staging)) {
                self::remove($staging);
            }
            // the previous copy goes once the new one (or, failing that, itself) is back in place
            if (is_dir($old) && is_dir($target)) {
                self::remove($old);
            }
        }

        // a copy at an earlier admin.path is stale: the web server would keep serving it
        foreach (self::copiesUnder(rtrim($publicDir, '/')) as $stale) {
            if ($stale !== $target) {
                self::remove($stale);
                $logger->debug("Removed the admin assets of an earlier admin.path: {$stale}");
            }
        }

        $logger->debug("Copied the admin assets to {$target}");

        return $target;
    }

    /**
     * The copies {@see self::publish()} wrote under `$publicDir`, three levels deep at most (an
     * `admin.path` such as `/cms/admin`), uploads left out.
     *
     * @return list<string>
     */
    public static function copiesUnder(string $publicDir, int $depth = 3): array
    {
        $found = [];
        foreach (glob($publicDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === 'uploads' || is_link($dir)) {
                continue;
            }
            if (self::isPublished($dir)) {
                $found[] = $dir;
            } elseif ($depth > 1) {
                array_push($found, ...self::copiesUnder($dir, $depth - 1));
            }
        }

        return $found;
    }

    /** Whether `$dir` is a copy written by {@see self::publish()}. */
    public static function isPublished(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir) || !is_file($dir . '/.htaccess')) {
            return false;
        }
        $handle = fopen($dir . '/.htaccess', 'r');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle);
        fclose($handle);

        return str_starts_with($first, self::MARKER);
    }

    private static function write(string $file, string $contents): void
    {
        if (@file_put_contents($file, $contents) === false) {
            throw new \RuntimeException("Cannot write {$file}.");
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!@mkdir($to, 0o755, true)) {
            throw new \RuntimeException("Cannot create {$to}.");
        }
        $from = rtrim($from, '/');
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            if (in_array($relative, self::SKIP, true)) {
                continue;
            }
            $dest = $to . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0o755, true);
                }
                continue;
            }
            if (!@copy($item->getPathname(), $dest)) {
                throw new \RuntimeException("Cannot copy {$item->getPathname()} to {$dest}.");
            }
        }
    }

    private static function remove(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
