<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use StrapiPlugin\RuntimeSync\Engine\Cli;
use StrapiPlugin\RuntimeSync\PreflightAbort;
use StrapiPlugin\RuntimeSync\Services\Status;

/**
 * The server half on a file database, as a project switching runtimes sees it: a migration log
 * left by Node Strapi, a first strapi-php boot, then a preflight boot (`db:check`). The Node twin
 * of these checks is the tail of conformance/run-strapi.mjs.
 */
final class ServerTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['LOG_LEVEL'] = 'error';

        $this->db = sys_get_temp_dir() . '/runtime-sync-' . uniqid() . '.db';
        putenv("RUNTIME_SYNC_TEST_DB={$this->db}");
        $_ENV['RUNTIME_SYNC_TEST_DB'] = $this->db;

        // a Node Strapi ran the .js twin of tests/php/app/database/migrations/*.noop.php on this database
        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->exec('CREATE TABLE strapi_migrations (id integer primary key autoincrement, name varchar(255), time datetime)');
        $pdo->exec("INSERT INTO strapi_migrations (name, time) VALUES ('2024.01.01T00.00.00.noop.js', '2024-01-01 00:00:00')");
    }

    protected function tearDown(): void
    {
        putenv('RUNTIME_SYNC_TEST_DB');
        putenv('STRAPI_RUNTIME_SYNC_PREFLIGHT');
        unset($_ENV['RUNTIME_SYNC_TEST_DB'], $_ENV['STRAPI_RUNTIME_SYNC_PREFLIGHT']);
        @unlink($this->db);
    }

    public function testFirstBootLogsTheTwinRecordsItselfAndThePreflightFindsNothingToChange(): void
    {
        $strapi = Core::createStrapi(['appDir' => __DIR__ . '/app'])->load();
        try {
            $status = $strapi->plugin('runtime-sync')->service('status');
            \assert($status instanceof Status);
            $report = $status->report();
        } finally {
            $strapi->destroy();
        }

        self::assertSame('php', $report['runtime']['kind']);
        self::assertSame('NEW', $report['database']['status'], 'the database had no schema');
        self::assertSame(['2024.01.01T00.00.00.noop.php'], $report['database']['migrations']['twinsToLog']);
        self::assertSame([], $report['database']['migrations']['pending'], 'the .php twin of a logged .js migration is not pending');
        self::assertSame($report['database']['schemaHash'], $report['boots']['php']['schemaHash'], 'this boot is recorded with its schema hash');
        self::assertContains('runtime-sync', $report['boots']['php']['plugins']);
        self::assertNull($report['agreement']['sameSchema'], 'Node has not booted on this database');
        self::assertSame('<root>', str_replace(realpath(__DIR__ . '/app') ?: '', '<root>', $report['project']['root']));

        $log = $this->column('SELECT name FROM strapi_migrations ORDER BY name');
        self::assertSame(['2024.01.01T00.00.00.noop.js', '2024.01.01T00.00.00.noop.php'], $log, 'the twin is logged, the migration (which throws) did not run');

        // second boot, as `strapi-runtime-sync db:check` does it: aborted before writing anything
        $preflight = Cli::preflight(__DIR__ . '/app', false);
        self::assertSame('UNCHANGED', $preflight['database']['status']);
        self::assertTrue($preflight['database']['hashMatches']);
        self::assertSame($report['database']['schemaHash'], $preflight['database']['storedHash']);
        self::assertSame([], $preflight['database']['migrations']['pending']);
        self::assertSame('php', $preflight['boots']['php']['runtime']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $preflight['database']['storedAt']);
    }

    public function testPreflightAbortCarriesTheReportAndWritesNothing(): void
    {
        putenv('STRAPI_RUNTIME_SYNC_PREFLIGHT=1');
        $_ENV['STRAPI_RUNTIME_SYNC_PREFLIGHT'] = '1';

        $strapi = Core::createStrapi(['appDir' => __DIR__ . '/app']);
        try {
            $strapi->load();
            self::fail('the boot must be aborted');
        } catch (PreflightAbort $e) {
            self::assertSame('NEW', $e->report['database']['status']);
            self::assertSame([], $e->report['boots'], 'nothing recorded');
        } finally {
            $strapi->db()->destroy();
        }

        $tables = $this->column("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        self::assertSame(['strapi_migrations'], $tables, 'no table was created, the twin was not logged');
        self::assertSame(['2024.01.01T00.00.00.noop.js'], $this->column('SELECT name FROM strapi_migrations'));
    }

    /** @return list<string> */
    private function column(string $sql): array
    {
        $statement = (new \PDO('sqlite:' . $this->db))->query($sql);
        self::assertNotFalse($statement, $sql);

        return array_values(array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testStatusHelpersAgreeAcrossRuntimes(): void
    {
        $boots = [
            'node' => ['runtime' => 'node', 'version' => '5.56.0', 'time' => '2026-10-13T10:00:00.000Z', 'schemaHash' => 'abc', 'plugins' => ['i18n', 'runtime-sync', 'users-permissions']],
            'php' => ['runtime' => 'php', 'version' => '5.56.0', 'time' => '2026-10-13T11:00:00.000Z', 'schemaHash' => 'abc', 'plugins' => ['graphql', 'i18n', 'runtime-sync', 'users-permissions']],
        ];
        self::assertSame(['runtime' => 'php', 'version' => '5.56.0', 'time' => '2026-10-13T11:00:00.000Z'], Status::storedBy('abc', $boots), 'the most recent boot with that hash');
        self::assertNull(Status::storedBy('other', $boots));
        self::assertSame(
            ['sameVersion' => true, 'sameSchema' => true, 'samePlugins' => false, 'pluginsNodeOnly' => [], 'pluginsPhpOnly' => ['graphql']],
            Status::agreement($boots),
        );
        self::assertNull(Status::agreement(['php' => $boots['php']])['sameSchema']);
    }
}
