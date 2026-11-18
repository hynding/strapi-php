<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

/**
 * PHP port: once a bootstrap has completed for given inputs on a database, later boots with the
 * same inputs on the same database skip the bootstrap lock (`Strapi::bootstrap()`), and a
 * steady-state boot writes nothing.
 */
final class BootstrapSettledTest extends BootedAppTestCase
{
    private static function settledFile(): string
    {
        return self::strapi()->dirs()->root . '/.tmp/bootstrap.settled';
    }

    private static function fingerprint(): string
    {
        $fingerprint = (new \ReflectionMethod(self::strapi(), 'bootstrapFingerprint'))->invoke(self::strapi());
        self::assertIsString($fingerprint);

        return $fingerprint;
    }

    private static function isSettled(string $fingerprint): bool
    {
        return (bool) (new \ReflectionMethod(self::strapi(), 'isBootstrapSettled'))->invoke(self::strapi(), $fingerprint);
    }

    public function testACompletedBootstrapRecordsItsInputsAndDatabase(): void
    {
        $latest = self::strapi()->db()->schema->schemaStorage->latest();
        self::assertNotNull($latest);

        // the inputs as bootstrap started: bootstrap itself may change them (users-permissions sets
        // a generated jwtSecret in config when JWT_SECRET is missing), so they are not recomputed here
        $recorded = (string) file_get_contents(self::settledFile());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}:' . $latest['id'] . ':' . preg_quote($latest['hash'], '/') . '$/', $recorded);
        self::assertTrue(self::isSettled(explode(':', $recorded)[0]));
    }

    public function testTheFingerprintIgnoresTheLaunchTimestamp(): void
    {
        $before = self::fingerprint();
        self::strapi()->config()->set('launchedAt', 1);

        self::assertSame($before, self::fingerprint());
    }

    public function testChangedInputsAreNotSettled(): void
    {
        $before = self::fingerprint();
        self::strapi()->config()->set('server.bootstrapSettledTest', true);

        try {
            self::assertNotSame($before, self::fingerprint());
            self::assertFalse(self::isSettled(self::fingerprint()));
        } finally {
            self::strapi()->config()->set('server.bootstrapSettledTest', null);
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
