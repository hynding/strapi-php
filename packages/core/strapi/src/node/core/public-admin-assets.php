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
 * written by the build: a directory without it is the project's own and is never replaced. Its
 * `index.php` hands `/admin/` (the directory itself) to the front controller.
 */
final class PublicAdminAssets
{
    /** First line of the `.htaccess` written into the copy; marks the directory as the build's. */
    public const MARKER = '# Written by `strapi build`';

    /**
     * Files of `build/` that are not copied: the shell goes through PHP (see the class comment), and
     * `index.php` is the copy's own.
     */
    private const SKIP = ['index.html', 'index.php'];

    private const HTACCESS = <<<'HTACCESS'
        # Written by `strapi build`: a copy of build/ (the admin panel) for the web server to serve without
        # PHP. Every build replaces it; delete the directory to have PHP serve the admin assets again.
        # index.html is not copied: the admin's pages go to the front controller (index.php here hands
        # /admin/ itself to it), which sends the shell with no-cache.

        Options -Indexes

        <IfModule mod_headers.c>
            <FilesMatch "^(?!index\.php$)">
                Header set Cache-Control "public, max-age=31536000, immutable"
            </FilesMatch>
        </IfModule>

        # no PHP here but the index.php the build writes
        <FilesMatch "(?i)^(?!index\.php$).*\.(php[0-9]?|pht|phtm|phtml|phar|phps)$">
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
        $relative = trim(str_replace('\\', '/', $adminPath), '/');
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
        $suffix = getmypid() . '-' . bin2hex(random_bytes(3));
        $staging = $parent . '/.' . basename($target) . '.new-' . $suffix;
        $old = $parent . '/.' . basename($target) . '.old-' . $suffix;
        try {
            self::copyTree($buildDir, $staging);
            self::write($staging . '/.htaccess', self::HTACCESS);
            self::write($staging . '/index.php', self::indexStub(count(explode('/', $relative))));

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
            foreach ([$staging, $old] as $leftover) {
                if (is_dir($leftover)) {
                    self::remove($leftover);
                }
            }
        }

        $logger->debug("Copied the admin assets to {$target}");

        return $target;
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

    /**
     * `<adminPath>/` is the copy's directory: a web server that looks for its index (Apache's
     * `DirectoryIndex index.php`, nginx's `index index.php`, where `try_files $uri` finds the
     * directory) runs this, which hands the request to the front controller as any admin page.
     */
    private static function indexStub(int $depth): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            // Written by `strapi build` (see .htaccess): the admin's pages are the front controller's
            require dirname(__DIR__, {$depth}) . '/index.php';

            PHP;
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
