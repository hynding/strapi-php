<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Types\Core\StrapiDirectories;

/**
 * Not an upstream file. Node Strapi runs where its `engines` allow and everything else ships in
 * node_modules; a PHP host is configured by someone else (shared hosting) and differs from the
 * machine that ran `composer install`: a PDO driver or `intl` missing, `.tmp` not writable, opcache
 * off, the JIT on, `.user.ini` not read, `NODE_ENV` unset, the `.env.example` secrets kept.
 *
 * {@see self::check()} lists what is wrong. It runs from `strapi requirements:check`, from the
 * bootstrap that settles a deployment (its findings are logged once, not on every request) and
 * when a boot fails under a web server (`HttpServer::bootFailed()`, logged to PHP's error log).
 *
 * @phpstan-type Finding array{level: 'error'|'warning', name: string, message: string}
 * @phpstan-type Platform array{sapi?: string, phpVersion?: string, extensions?: list<string>, ini?: \Closure(string): (string|false), env?: array<string, string>, frankenphpWorker?: bool}
 */
final class Requirements
{
    public const string PHP_VERSION = '8.3.0';

    /** Extensions without which Strapi does not run (Composer's platform check knows only `php`). */
    public const array EXTENSIONS = [
        'ctype' => 'string checks of the validators',
        'json' => 'every request and response body',
        'mbstring' => 'string handling throughout',
        'openssl' => 'admin and API tokens, encryption, signed URLs',
        'pdo' => 'the database',
    ];

    /** The PDO driver each `database.connection.client` needs. */
    public const array DRIVERS = [
        'sqlite' => 'pdo_sqlite',
        'better-sqlite3' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'pg' => 'pdo_pgsql',
    ];

    /** Extensions Strapi runs without, with what goes missing. */
    public const array RECOMMENDED = [
        'intl' => 'schema tables are sorted by byte instead of as Node sorts them (a database shared with Node Strapi re-syncs at each switch), and upload names are not Unicode-normalized',
        'gd' => 'uploaded images get no thumbnails, responsive formats or dimensions',
        'fileinfo' => 'files under public/ are typed by their extension only',
        'zlib' => 'responses are not compressed',
    ];

    /** The variables of `.env.example` whose example value must be replaced before going live. */
    public const array SECRETS = ['APP_KEYS', 'API_TOKEN_SALT', 'ADMIN_JWT_SECRET', 'TRANSFER_TOKEN_SALT', 'JWT_SECRET', 'ENCRYPTION_KEY'];

    /**
     * @param string|null $databaseClient `database.connection.client` (null: unknown, no driver check)
     * @param Platform $platform what to check instead of the running PHP (tests)
     * @return list<Finding> errors first
     */
    public static function check(StrapiDirectories $dirs, ?string $databaseClient, array $platform = []): array
    {
        $sapi = $platform['sapi'] ?? PHP_SAPI;
        $phpVersion = $platform['phpVersion'] ?? PHP_VERSION;
        $extensions = array_map('strtolower', $platform['extensions'] ?? get_loaded_extensions());
        $ini = $platform['ini'] ?? static fn (string $name): string|false => ini_get($name);
        $env = $platform['env'] ?? self::environment();
        $loaded = static fn (string $extension): bool => in_array($extension, $extensions, true);

        $findings = [];

        if (version_compare($phpVersion, self::PHP_VERSION, '<')) {
            $findings[] = self::finding('error', 'php', "PHP {$phpVersion} is too old: Strapi needs PHP " . self::PHP_VERSION . ' or later.');
        }

        foreach (self::EXTENSIONS as $extension => $usedFor) {
            if (!$loaded($extension)) {
                $findings[] = self::finding('error', "ext-{$extension}", "The {$extension} extension is missing ({$usedFor}).");
            }
        }

        if ($databaseClient !== null) {
            $driver = self::DRIVERS[$databaseClient] ?? null;
            if ($driver === null) {
                $findings[] = self::finding('error', 'database', "Unknown database client \"{$databaseClient}\" (database.connection.client): use one of " . implode(', ', array_keys(self::DRIVERS)) . '.');
            } elseif (!$loaded($driver)) {
                $findings[] = self::finding('error', "ext-{$driver}", "The {$driver} extension is missing: the {$databaseClient} database client needs it.");
            }
        }

        if (!self::writable($dirs->tmp)) {
            $findings[] = self::finding('error', '.tmp', "{$dirs->tmp} is not writable: the bootstrap lock, rate limits, cron state and the default SQLite database live there.");
        }
        if (!self::writable($dirs->uploads)) {
            $findings[] = self::finding('warning', 'uploads', "{$dirs->uploads} is not writable: the local upload provider cannot store files.");
        }
        if (!self::writable($dirs->root . '/.strapi')) {
            $findings[] = self::finding('warning', '.strapi', "{$dirs->root}/.strapi is not writable: settled boots cannot cache their database metadata and routes, and take longer.");
        }

        foreach (self::RECOMMENDED as $extension => $without) {
            if (!$loaded($extension)) {
                $findings[] = self::finding('warning', "ext-{$extension}", "The {$extension} extension is missing: {$without}.");
            }
        }

        $perRequest = Sapi::isPerRequest($sapi, $platform['frankenphpWorker'] ?? null);
        $opcacheOn = $loaded('zend opcache') && self::flag($ini($sapi === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'));

        if ($perRequest && !$opcacheOn) {
            $findings[] = self::finding('warning', 'opcache', 'OPcache is off: every request compiles Strapi again (several times slower). Enable it (opcache.enable=1).');
        }
        if ($opcacheOn && self::jitOn($ini)) {
            $findings[] = self::finding('warning', 'opcache.jit', 'The OPcache JIT is on: PHP 8.3\'s JIT has crashed some first boots of Strapi (segmentation fault). Set opcache.jit=disable.');
        }
        if ($perRequest && self::flag($ini('enable_post_data_reading'))) {
            $findings[] = self::finding('warning', 'enable_post_data_reading', 'enable_post_data_reading is on (public/.user.ini or the .htaccess php_flag was not read): of several files uploaded under one name, only the last is kept.');
        }

        $nodeEnv = $env['NODE_ENV'] ?? '';
        if ($perRequest && $sapi !== 'cli-server' && $nodeEnv !== 'production') {
            $findings[] = self::finding('warning', 'NODE_ENV', 'NODE_ENV is ' . ($nodeEnv === '' ? 'not set' : "\"{$nodeEnv}\"") . ': the app runs in development, where every request runs the whole bootstrap under a lock. Set NODE_ENV=production in .env.');
        }
        if ($nodeEnv === 'production') {
            $kept = array_values(array_filter(self::SECRETS, static fn (string $name): bool => isset($env[$name]) && stripos($env[$name], 'tobemodified') !== false));
            if ($kept !== []) {
                $findings[] = self::finding('warning', 'secrets', implode(', ', $kept) . ' still have the example values of .env.example: anyone can sign admin and API tokens. Generate new ones.');
            }
        }

        usort($findings, static fn (array $a, array $b): int => ($a['level'] === 'error' ? 0 : 1) <=> ($b['level'] === 'error' ? 0 : 1));

        return $findings;
    }

    /** @param list<Finding> $findings */
    public static function hasErrors(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding['level'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @param list<Finding> $findings one line per finding */
    public static function format(array $findings): string
    {
        return implode("\n", array_map(static fn (array $f): string => "[{$f['level']}] {$f['message']}", $findings));
    }

    /**
     * @param 'error'|'warning' $level
     * @return Finding
     */
    private static function finding(string $level, string $name, string $message): array
    {
        return ['level' => $level, 'name' => $name, 'message' => $message];
    }

    /** A directory is writable, or can be created. */
    private static function writable(string $dir): bool
    {
        while (!file_exists($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }

        return is_dir($dir) && is_writable($dir);
    }

    private static function flag(string|false $value): bool
    {
        return $value !== false && in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }

    /** @param \Closure(string): (string|false) $ini */
    private static function jitOn(\Closure $ini): bool
    {
        $jit = strtolower(trim((string) $ini('opcache.jit')));
        $buffer = trim((string) $ini('opcache.jit_buffer_size'));

        return !in_array($jit, ['', '0', 'off', 'disable'], true) && $buffer !== '' && $buffer !== '0' && (int) $buffer !== 0;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = [];
        foreach (['NODE_ENV', ...self::SECRETS] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
