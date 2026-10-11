<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\CreateStrapi;
use Strapi\Utils\EnvHelper;

/**
 * Port of packages/cli/create-strapi-app/src/__tests__/templates-database.test.ts.
 *
 * Every scaffolded template ships a `config/database` file that validates the `DATABASE_CLIENT`
 * env var against the clients Strapi actually supports. These tests load each template's database
 * config (using the real `env` helper the generated app runs with) and assert it fails loudly when
 * an unsupported client is provided, rather than silently building an invalid connection.
 */
final class TemplatesDatabaseTest extends TestCase
{
    /** @return list<string> */
    private static function templates(): array
    {
        $dir = CreateStrapi::templatesDir();

        return array_values(array_filter(
            array_diff(scandir($dir) ?: [], ['.', '..']),
            static fn (string $entry): bool => is_dir($dir . '/' . $entry),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function templateProvider(): iterable
    {
        foreach (self::templates() as $template) {
            yield $template => [$template];
        }
    }

    /** @return callable(EnvHelper): mixed */
    private static function loadDatabaseConfig(string $template): callable
    {
        $file = CreateStrapi::templatesDir() . "/{$template}/config/database.php";

        if (!is_file($file)) {
            throw new \RuntimeException("No database config found for template \"{$template}\"");
        }

        $config = require $file;
        self::assertIsCallable($config);

        return $config;
    }

    public function testDiscoversAtLeastOneTemplate(): void
    {
        self::assertGreaterThan(0, count(self::templates()));
    }

    #[DataProvider('templateProvider')]
    public function testThrowsOnAnUnsupportedDatabaseClient(string $template): void
    {
        $config = self::loadDatabaseConfig($template);

        $this->expectExceptionMessage('Unsupported DATABASE_CLIENT');
        $config(new EnvHelper(['DATABASE_CLIENT' => 'oracle']));
    }

    #[DataProvider('templateProvider')]
    public function testBuildsAConfigForASupportedDatabaseClient(string $template): void
    {
        $config = self::loadDatabaseConfig($template)(new EnvHelper(['DATABASE_CLIENT' => 'sqlite']));

        self::assertIsArray($config);
        self::assertSame('sqlite', $config['connection']['client'] ?? null);
    }

    #[DataProvider('templateProvider')]
    public function testAnEmptySqliteFilenameFallsBackToTheDefault(string $template): void
    {
        // `--dbclient sqlite` without `--dbfile` writes `DATABASE_FILENAME=` to the generated .env
        $templateDir = CreateStrapi::templatesDir() . "/{$template}";
        foreach (['' => '/.tmp/data.db', 'db/custom.db' => '/db/custom.db'] as $filename => $expected) {
            $config = self::loadDatabaseConfig($template)(new EnvHelper(['DATABASE_CLIENT' => 'sqlite', 'DATABASE_FILENAME' => $filename]));

            self::assertSame($templateDir . $expected, $config['connection']['connection']['filename']);
        }
    }
}
