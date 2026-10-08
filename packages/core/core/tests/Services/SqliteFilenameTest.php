<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;

/** A relative SQLite filename resolves against the project root, never the web root (cwd = public/). */
final class SqliteFilenameTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(string $client, string $filename): array
    {
        return ['connection' => ['client' => $client, 'connection' => ['filename' => $filename]]];
    }

    public function testRelativeFilenameResolvesAgainstTheProjectRoot(): void
    {
        $resolved = Strapi::resolveSqliteFilename(self::config('sqlite', '.tmp/data.db'), '/srv/app');

        self::assertSame('/srv/app/.tmp/data.db', $resolved['connection']['connection']['filename']);
    }

    public function testAbsoluteMemoryAndUriFilenamesAreKept(): void
    {
        foreach (['/var/db/data.db', ':memory:', 'file:data.db?mode=ro', 'C:\\db\\data.db'] as $filename) {
            $resolved = Strapi::resolveSqliteFilename(self::config('sqlite', $filename), '/srv/app');
            self::assertSame($filename, $resolved['connection']['connection']['filename']);
        }
    }

    public function testOtherClientsAreUntouched(): void
    {
        $config = ['connection' => ['client' => 'postgres', 'connection' => ['filename' => 'x']]];

        self::assertSame($config, Strapi::resolveSqliteFilename($config, '/srv/app'));
    }
}
