<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Node\Core\PublicAdminAssets;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream command (Strapi deploys with Strapi Cloud or a Node host that runs `npm install`
 * and `strapi build`). Many PHP hosts have neither Composer nor Node: `strapi bundle` packs the
 * project as it runs here, `vendor/` and the built admin panel (`build/`, `public/admin/`)
 * included, into one archive to upload and extract on the host.
 *
 * Left out: `.env` (secrets; the host gets its own, see `.env.example`), `.tmp` (the SQLite
 * database, locks, rate limits: the host's data), `.strapi`, `node_modules`, `dist`, `.git`, the
 * uploaded files under `public/uploads` and editor and test caches. Symlinks are archived as the
 * files they point to (a Composer path repository installs packages as symlinks).
 */
final class Bundle extends StrapiCommand
{
    /** Top-level entries never bundled. */
    public const array EXCLUDE = [
        '.env', '.git', '.tmp', '.strapi', 'node_modules', 'dist', 'coverage', 'exports',
        '.idea', '.vscode', '.phpunit.cache', '.phpunit.result.cache', '.php-cs-fixer.cache', '.DS_Store',
    ];

    /** Entries never bundled, at any depth. */
    public const array EXCLUDE_EVERYWHERE = ['.env', '.git', 'node_modules', '.DS_Store', 'Thumbs.db'];

    /** Files kept under `public/uploads` (the rest is the uploaded media). */
    private const array UPLOADS_KEEP = ['.gitkeep', '.htaccess'];

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('bundle')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'The archive to write (.zip or .tar.gz)', null)
            ->addOption('without-admin', null, InputOption::VALUE_NONE, 'Bundle without a built admin panel (API only)')
            ->setDescription('Pack the project with vendor/ and the built admin panel, to upload to a host without Composer or Node');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $root = rtrim($this->ctx->cwd, '/');
        $name = self::projectName($root);
        $option = $input->getOption('output');
        $archive = is_string($option) && $option !== ''
            ? (str_starts_with($option, '/') ? $option : getcwd() . '/' . $option)
            : $root . '/dist/' . $name . (class_exists(\ZipArchive::class) ? '.zip' : '.tar.gz');

        try {
            $app = $this->createStrapi();
            $result = self::create($root, $archive, [
                'name' => $name,
                'withoutAdmin' => (bool) $input->getOption('without-admin'),
                'adminPath' => (string) $app->config()->get('admin.path', '/admin'),
                'publicDir' => self::relativeTo($root, $app->dirs()->public),
            ]);
        } catch (\RuntimeException $error) {
            $this->ctx->logger->error($error->getMessage());

            return Command::FAILURE;
        }

        $archive = realpath($archive) ?: $archive;
        foreach ($result['warnings'] as $warning) {
            $this->ctx->logger->warn($warning);
        }
        $size = number_format($result['bytes'] / 1048576, 1);
        $this->ctx->logger->success("Bundled {$result['files']} files into {$archive} ({$size} MB)");
        $this->ctx->logger->info(implode("\n", [
            'On the host:',
            "  1. Upload and extract it, and make {$name}/public the site's document root.",
            '  2. Create .env from .env.example with NODE_ENV=production, the database settings and new secrets.',
            '  3. Check the host: `php bin/strapi requirements:check` over SSH, or the PHP error log after the first request.',
        ]));

        return Command::SUCCESS;
    }

    /**
     * Writes the archive of `$root` to `$archive` (`.zip`, or `.tar.gz` / `.tgz`), its entries under
     * `<name>/`.
     *
     * @param array{name?: string, withoutAdmin?: bool, adminPath?: string, publicDir?: string} $options
     *        `adminPath`: `admin.path`; `publicDir`: the public directory relative to the root
     * @return array{files: int, bytes: int, warnings: list<string>}
     */
    public static function create(string $root, string $archive, array $options = []): array
    {
        $root = rtrim($root, '/');
        $name = $options['name'] ?? self::projectName($root);
        $warnings = [];

        if (!is_file($root . '/vendor/autoload.php')) {
            throw new \RuntimeException('vendor/ is missing: run `composer install --no-dev --optimize-autoloader` first.');
        }
        if (!($options['withoutAdmin'] ?? false) && !is_file($root . '/build/index.html')) {
            throw new \RuntimeException('The admin panel is not built: run `php bin/strapi build` first (or bundle with --without-admin).');
        }
        $public = trim($options['publicDir'] ?? 'public', '/');
        $adminDir = trim($options['adminPath'] ?? '/admin', '/');
        if (!($options['withoutAdmin'] ?? false) && $adminDir !== '' && !PublicAdminAssets::isPublished("{$root}/{$public}/{$adminDir}")) {
            $warnings[] = "{$public}/{$adminDir}/ was not written by `strapi build`: PHP will serve the admin assets (rebuild to have the web server serve them).";
        }
        $devPackages = self::devPackages($root);
        if ($devPackages !== []) {
            $warnings[] = 'vendor/ holds development packages (' . implode(', ', array_slice($devPackages, 0, 3)) . (count($devPackages) > 3 ? ', ...' : '') . '): `composer install --no-dev --optimize-autoloader` before bundling makes the archive smaller.';
        }

        $files = self::files($root, $archive, "{$public}/uploads");

        if (!is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0o755, true);
        }
        @unlink($archive);

        if (preg_match('/\.(tar\.gz|tgz)$/i', $archive) === 1) {
            self::writeTar($archive, $name, $files);
        } elseif (preg_match('/\.zip$/i', $archive) === 1) {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('Writing a .zip needs the zip extension: install it, or write a .tar.gz (--output <name>.tar.gz).');
            }
            self::writeZip($archive, $name, $files);
        } else {
            throw new \RuntimeException("Unsupported archive {$archive}: use .zip or .tar.gz.");
        }

        return ['files' => count($files), 'bytes' => (int) filesize($archive), 'warnings' => $warnings];
    }

    /**
     * The files to bundle: archive path (relative to the root) → file on disk (symlinks resolved).
     *
     * @return array<string, string>
     */
    public static function files(string $root, ?string $archive = null, string $uploads = 'public/uploads'): array
    {
        $root = rtrim($root, '/');
        $uploads = trim($uploads, '/');
        $skip = $archive !== null ? (realpath($archive) ?: $archive) : null;
        $files = [];

        /** @param array<string, true> $ancestors */
        $walk = static function (string $dir, string $prefix, array $ancestors) use (&$walk, &$files, $skip, $uploads): void {
            $real = realpath($dir);
            if ($real === false || isset($ancestors[$real])) {
                return; // a symlink to one of its own parents
            }
            $ancestors[$real] = true;

            $entries = scandir($dir);
            if ($entries === false) {
                return;
            }
            sort($entries, SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $relative = $prefix === '' ? $entry : "{$prefix}/{$entry}";
                if (self::excluded($relative, $uploads)) {
                    continue;
                }
                $path = "{$dir}/{$entry}";
                if (is_dir($path)) {
                    $walk($path, $relative, $ancestors);
                } elseif (is_file($path)) {
                    $resolved = realpath($path) ?: $path;
                    if ($resolved !== $skip) {
                        $files[$relative] = $resolved;
                    }
                }
                // anything else is a dangling symlink: left out
            }
        };
        $walk($root, '', []);

        return $files;
    }

    private static function excluded(string $relative, string $uploads): bool
    {
        $parts = explode('/', $relative);
        $base = end($parts);

        if (count($parts) === 1 && in_array($base, self::EXCLUDE, true)) {
            return true;
        }
        // at any depth: a package installed from source (`--prefer-source`) keeps its clone, and
        // one from a Composer path repository (a symlink to a checkout) its node_modules and .env
        if (in_array($base, self::EXCLUDE_EVERYWHERE, true) || (str_starts_with($base, '.env.') && $base !== '.env.example')) {
            return true;
        }

        return dirname($relative) === $uploads && !in_array($base, self::UPLOADS_KEEP, true);
    }

    private static function relativeTo(string $root, string $path): string
    {
        $root = rtrim($root, '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : 'public';
    }

    /** @param array<string, string> $files */
    private static function writeZip(string $archive, string $name, array $files): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot write {$archive}.");
        }
        foreach ($files as $relative => $path) {
            $entry = "{$name}/{$relative}";
            $zip->addFile($path, $entry);
            // keep the mode (bin/strapi stays executable)
            $zip->setExternalAttributesName($entry, \ZipArchive::OPSYS_UNIX, ((fileperms($path) ?: 0o100644) & 0xFFFF) << 16);
        }
        if (!$zip->close()) {
            throw new \RuntimeException("Cannot write {$archive}: " . $zip->getStatusString());
        }
    }

    /** @param array<string, string> $files */
    private static function writeTar(string $archive, string $name, array $files): void
    {
        // PharData names its compressed copy after everything before the first dot of the file name
        $dir = sys_get_temp_dir() . '/strapi-bundle-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $tarPath = $dir . '/bundle.tar';
        try {
            $tar = new \PharData($tarPath, 0, null, \Phar::TAR);
            // unbuffered, Phar rewrites the whole archive after each change
            $tar->startBuffering();
            foreach ($files as $relative => $path) {
                $tar->addFile($path, "{$name}/{$relative}");
                $tar["{$name}/{$relative}"]->chmod((fileperms($path) ?: 0o644) & 0o777);
            }
            $tar->stopBuffering();
            $tar->compress(\Phar::GZ);
            unset($tar);
            if (!@rename($dir . '/bundle.tar.gz', $archive) && !(copy($dir . '/bundle.tar.gz', $archive))) {
                throw new \RuntimeException("Cannot write {$archive}.");
            }
        } finally {
            @unlink($tarPath);
            @unlink($dir . '/bundle.tar.gz');
            @rmdir($dir);
        }
    }

    /** @return list<string> */
    private static function devPackages(string $root): array
    {
        $installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
        if (!is_array($installed) || ($installed['dev'] ?? false) !== true) {
            return [];
        }

        return array_values(array_filter($installed['dev-package-names'] ?? [], 'is_string'));
    }

    private static function projectName(string $root): string
    {
        $package = json_decode((string) @file_get_contents($root . '/package.json'), true);
        $name = is_array($package) && is_string($package['name'] ?? null) ? $package['name'] : basename($root);
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name));

        return $name === '' ? 'strapi' : $name;
    }
}
