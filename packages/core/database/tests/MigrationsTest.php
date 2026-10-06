<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Migrations\Internal;
use Strapi\Database\Migrations\InternalMigrations\InternalMigrations;
use Strapi\Database\Migrations\Runner;
use Strapi\Database\Migrations\Storage;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/** Port of migrations/__tests__/runner.test.ts and storage.test.ts, plus user migration discovery. */
final class MigrationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-php-migrations-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testRunnerAppliesPendingMigrationsInOrderAndRevertsLast(): void
    {
        $db = GetstartedDatabase::create([], []);
        $storage = new Storage($db, 'strapi_migrations_test');
        $log = [];
        $ran = [];

        $migrations = static function () use (&$ran): array {
            return [
                ['name' => 'a', 'up' => static function () use (&$ran): void { $ran[] = 'a:up'; }, 'down' => static function () use (&$ran): void { $ran[] = 'a:down'; }],
                ['name' => 'b', 'up' => static function () use (&$ran): void { $ran[] = 'b:up'; }, 'down' => static function () use (&$ran): void { $ran[] = 'b:down'; }],
            ];
        };
        $runner = new Runner($storage, static function (mixed $m) use (&$log): void { $log[] = $m['event'] . ':' . $m['name']; }, $migrations);

        self::assertSame([['name' => 'a'], ['name' => 'b']], $runner->pending());
        self::assertSame([['name' => 'a'], ['name' => 'b']], $runner->up());
        self::assertSame(['a:up', 'b:up'], $ran);
        self::assertSame(['a', 'b'], $storage->executed());
        self::assertSame(['migrating:a', 'migrated:a', 'migrating:b', 'migrated:b'], $log);

        self::assertSame([], $runner->pending());
        self::assertSame([], $runner->up());

        self::assertSame([['name' => 'b']], $runner->down());
        self::assertSame(['a:up', 'b:up', 'b:down'], $ran);
        self::assertSame(['a'], $storage->executed());
        self::assertSame([['name' => 'b']], $runner->pending());
    }

    public function testRunnerWrapsErrors(): void
    {
        $db = GetstartedDatabase::create([], []);
        $runner = new Runner(new Storage($db, 'strapi_migrations_test'), static function (): void {}, static fn (): array => [
            ['name' => 'boom', 'up' => static function (): void { throw new \RuntimeException('nope'); }, 'down' => static function (): void {}],
        ]);

        $this->expectExceptionMessage('Migration boom (up) failed: Original error: nope');
        $runner->up();
    }

    public function testUserMigrationsRunBeforeSchemaSync(): void
    {
        file_put_contents($this->dir . '/.hidden.php', '<?php throw new \RuntimeException("must be skipped");');
        file_put_contents($this->dir . '/2024.01.01T00.00.00.first.php', <<<'PHP'
            <?php
            return [
                'up' => function (\Doctrine\DBAL\Connection $trx, \Strapi\Database\Database $db): void {
                    $trx->executeStatement('CREATE TABLE user_custom (id INTEGER PRIMARY KEY, v TEXT)');
                    $trx->executeStatement("INSERT INTO user_custom (v) VALUES ('from-migration')");
                },
                'down' => function (\Doctrine\DBAL\Connection $trx): void {
                    $trx->executeStatement('DROP TABLE user_custom');
                },
            ];
            PHP);
        file_put_contents($this->dir . '/2024.01.02T00.00.00.second.sql', "INSERT INTO user_custom (v) VALUES ('from-sql')");

        $db = GetstartedDatabase::create(['migrations' => ['dir' => $this->dir]]);
        self::assertTrue($db->migrations->shouldRun());
        self::assertSame('CHANGED', $db->schema->sync());

        self::assertSame(['from-migration', 'from-sql'], $db->connection->fetchFirstColumn('select v from user_custom order by id'));
        self::assertSame(['2024.01.01T00.00.00.first.php', '2024.01.02T00.00.00.second.sql'], $db->connection->fetchFirstColumn('select name from strapi_migrations order by id'));
        self::assertSame(InternalMigrations::NAMES, $db->connection->fetchFirstColumn('select name from strapi_migrations_internal order by id'));
        self::assertFalse($db->migrations->shouldRun());
        self::assertSame('UNCHANGED', $db->schema->sync());

        // the user table is not tracked by the schema and survives syncs
        $db->schema->syncSchema();
        self::assertSame(2, (int) $db->connection->fetchOne('select count(*) from user_custom'));

        // runMigrations: false ignores pending user migrations
        file_put_contents($this->dir . '/2024.01.03T00.00.00.third.sql', "INSERT INTO user_custom (v) VALUES ('third')");
        $db2 = GetstartedDatabase::create(['migrations' => ['dir' => $this->dir], 'runMigrations' => false]);
        self::assertFalse($db2->migrations->users->shouldRun());
    }

    public function testInternalProviderRegister(): void
    {
        $db = GetstartedDatabase::create([], []);
        $internal = new Internal($db);
        $ran = false;
        $internal->register(['name' => 'custom', 'up' => static function () use (&$ran): void { $ran = true; }, 'down' => static function (): void {}]);
        self::assertTrue($internal->shouldRun());
        $internal->up();
        self::assertTrue($ran);
        self::assertFalse($internal->shouldRun());
    }
}
