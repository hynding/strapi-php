<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

/**
 * PHP port: once a bootstrap has completed for given inputs on a database, later boots with the
 * same inputs on the same database skip the bootstrap lock (`Strapi::bootstrap()`), and a
 * steady-state boot writes nothing. Outside development only. A boot that settles end to end:
 * the admin package's SettledBootTest and PerRequestLaneTest.
 */
final class BootstrapSettledTest extends BootedAppTestCase
{
    private static function settledFile(): string
    {
        return self::strapi()->dirs()->root . '/.tmp/bootstrap.settled';
    }

    protected function tearDown(): void
    {
        @unlink(self::settledFile());
    }

    /** The fingerprint as a production boot computes it (the shared test app runs in development). */
    private static function fingerprint(): string
    {
        $fingerprint = self::inEnvironment('production', static fn (): mixed => (new \ReflectionMethod(self::strapi(), 'bootstrapFingerprint'))->invoke(self::strapi()));
        self::assertIsString($fingerprint);

        return $fingerprint;
    }

    private static function inEnvironment(string $environment, \Closure $run): mixed
    {
        $config = self::strapi()->config();
        $before = $config->get('environment');
        $config->set('environment', $environment);

        try {
            return $run();
        } finally {
            $config->set('environment', $before);
        }
    }

    private static function isSettled(string $fingerprint): bool
    {
        return (bool) (new \ReflectionMethod(self::strapi(), 'matchesSettledRecord'))->invoke(self::strapi(), $fingerprint);
    }

    private static function record(string $fingerprint): void
    {
        (new \ReflectionMethod(self::strapi(), 'markBootstrapSettled'))->invoke(self::strapi(), $fingerprint);
    }

    public function testDevelopmentNeverSettles(): void
    {
        // code changes between requests in ways the fingerprint does not see: every boot bootstraps
        self::assertSame('development', self::strapi()->config()->get('environment'));
        self::assertNull((new \ReflectionMethod(self::strapi(), 'bootstrapFingerprint'))->invoke(self::strapi()));
    }

    public function testARecordMatchesItsInputsAndDatabase(): void
    {
        $latest = self::strapi()->db()->schema->schemaStorage->latest();
        self::assertNotNull($latest);

        self::record(self::fingerprint());

        $recorded = (string) file_get_contents(self::settledFile());
        self::assertSame(self::fingerprint() . ':' . $latest['id'] . ':' . $latest['hash'] . ':' . $latest['time'], $recorded);
        self::assertTrue(self::isSettled(self::fingerprint()));
    }

    public function testADatabaseSyncedAgainIsNotSettled(): void
    {
        // dropped and synced again with the same schemas: the same id and hash, another time
        $latest = self::strapi()->db()->schema->schemaStorage->latest();
        self::assertNotNull($latest);
        file_put_contents(self::settledFile(), self::fingerprint() . ":{$latest['id']}:{$latest['hash']}:1999-01-01 00:00:00");

        self::assertFalse(self::isSettled(self::fingerprint()));
    }

    public function testTheFingerprintIgnoresTheLaunchTimestamp(): void
    {
        $before = self::fingerprint();
        self::strapi()->config()->set('launchedAt', 1);

        self::assertSame($before, self::fingerprint());
    }

    public function testChangedInputsAreNotSettled(): void
    {
        self::record(self::fingerprint());
        $before = self::fingerprint();
        self::strapi()->config()->set('server.bootstrapSettledTest', true);

        try {
            self::assertNotSame($before, self::fingerprint());
            self::assertFalse(self::isSettled(self::fingerprint()));
        } finally {
            self::strapi()->config()->set('server.bootstrapSettledTest', null);
        }
    }

    public function testAChangedFileUnderSrcOrConfigIsNotSettled(): void
    {
        // a lifecycle, a policy, a local plugin: code the other inputs do not describe
        foreach (['/src/index.php', '/config/server.php'] as $path) {
            $file = self::strapi()->dirs()->root . $path;
            $mtime = (int) filemtime($file);
            $before = self::fingerprint();
            touch($file, $mtime + 10);
            clearstatcache();

            try {
                self::assertNotSame($before, self::fingerprint(), $path);
            } finally {
                touch($file, $mtime);
                clearstatcache();
            }
            self::assertSame($before, self::fingerprint(), $path);
        }
    }

    public function testFilesBootstrapWritesAreNotInputs(): void
    {
        // outside production the documentation plugin rewrites its spec at every boot
        $file = self::strapi()->dirs()->src . '/extensions/documentation/documentation/1.0.0/full_documentation.json';
        self::assertFileExists($file);
        $mtime = (int) filemtime($file);
        $before = self::fingerprint();
        touch($file, $mtime + 10);
        clearstatcache();

        try {
            self::assertSame($before, self::fingerprint());
        } finally {
            touch($file, $mtime);
            clearstatcache();
        }
    }

    public function testUnsettleMakesTheNextBootRunTheWholeBootstrap(): void
    {
        self::record(self::fingerprint());
        self::assertTrue(self::isSettled(self::fingerprint()));

        self::strapi()->unsettle();

        self::assertFalse(self::isSettled(self::fingerprint()));
    }

    public function testADirectoryThatCannotBeOpenedIsSkipped(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root opens every directory');
        }
        $dir = sys_get_temp_dir() . '/strapi-file-stats-' . bin2hex(random_bytes(6));
        mkdir("{$dir}/private", 0o777, true);
        file_put_contents("{$dir}/index.php", '<?php');
        chmod("{$dir}/private", 0);

        try {
            $stats = (new \ReflectionMethod(self::strapi(), 'fileStats'))->invoke(null, $dir);
            self::assertSame(["{$dir}/index.php"], array_keys($stats));
            self::assertSame([], (new \ReflectionMethod(self::strapi(), 'fileStats'))->invoke(null, "{$dir}/private"));
        } finally {
            chmod("{$dir}/private", 0o777);
            rmdir("{$dir}/private");
            unlink("{$dir}/index.php");
            rmdir($dir);
        }
    }

    public function testAnotherDatabaseIsNotSettled(): void
    {
        // the recorded schema row is not this database's latest one: a reset or another database
        file_put_contents(self::settledFile(), self::fingerprint() . ':999999:other');

        self::assertFalse(self::isSettled(self::fingerprint()));
    }

    public function testAMissingRecordIsNotSettled(): void
    {
        @unlink(self::settledFile());

        self::assertFalse(self::isSettled(self::fingerprint()));
    }

    public function testCoreStoreSetSkipsAnUpdateThatChangesNothing(): void
    {
        $store = self::strapi()->store();
        $connection = self::strapi()->db()->connection;
        $key = ['type' => 'plugin', 'name' => 'bootstrap-settled-test', 'key' => 'value'];

        $store->set([...$key, 'value' => ['a' => 1]]);
        $changes = (int) $connection->fetchOne('SELECT total_changes()');

        $store->set([...$key, 'value' => ['a' => 1]]);
        self::assertSame($changes, (int) $connection->fetchOne('SELECT total_changes()'), 'same value: no write');

        $store->set([...$key, 'value' => ['a' => 2]]);
        self::assertSame($changes + 1, (int) $connection->fetchOne('SELECT total_changes()'));
        self::assertSame(['a' => 2], $store->get($key));
    }
}
