<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StrapiPlugin\RuntimeSync\Engine\Emit;
use StrapiPlugin\RuntimeSync\Engine\Generate;
use StrapiPlugin\RuntimeSync\Engine\Glob;
use StrapiPlugin\RuntimeSync\Engine\Ir;
use StrapiPlugin\RuntimeSync\Engine\Migrations;
use StrapiPlugin\RuntimeSync\Engine\Source;
use StrapiPlugin\RuntimeSync\Engine\Status;

/**
 * The PHP engine against the fixtures both engines share (tests/fixtures/engine/*.json for the
 * pure functions, tests/fixtures/projects/* with tests/fixtures/expected/* for status and
 * generate). The Node twin is tests/node/engine.test.mjs: both must produce the goldens byte
 * for byte. `UPDATE_FIXTURES=1` rewrites the goldens from this engine.
 */
final class EngineTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures';

    /** @return iterable<string, array{string}> */
    public static function projects(): iterable
    {
        foreach (glob(self::FIXTURES . '/projects/*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [basename($dir)];
        }
    }

    public function testGlob(): void
    {
        foreach (self::json('engine/glob.json') as $case) {
            self::assertSame($case['expected'], Glob::match($case['pattern'], $case['path']), "{$case['pattern']} vs {$case['path']}");
        }
    }

    public function testMigrations(): void
    {
        foreach (self::json('engine/migrations.json') as $case) {
            self::assertSame($case['twinsToLog'], Migrations::twinsToLog($case['executed'], $case['files']), $case['description']);
            self::assertSame($case['pending'], Migrations::pending($case['executed'], $case['files']), $case['description']);
            foreach ($case['discoverable'] as $runtime => $expected) {
                self::assertSame($expected, Migrations::discoverable($case['files'], $runtime), "{$case['description']} ({$runtime})");
            }
        }
    }

    public function testCoreFactoryDetection(): void
    {
        foreach (self::json('engine/core-factory.json') as $i => $case) {
            self::assertSame($case['expected'], Source::detectCoreFactory($case['code'], $case['lang']), "case {$i}");
        }
    }

    public function testEmit(): void
    {
        // decoded as objects so `{}` and `[]` stay distinct, as the IR requires
        $cases = json_decode((string) file_get_contents(self::FIXTURES . '/engine/emit.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $i => $case) {
            $doc = Ir::decode((string) json_encode($case->doc));
            foreach ($case->targets as $lang => $target) {
                $golden = "expected/emit/{$i}." . basename($target);
                $actual = Emit::config($doc, $lang, $target)['content'];
                self::assertGolden($golden, $actual, "{$case->description} ({$lang})");
            }
        }
    }

    #[DataProvider('projects')]
    public function testStatusAndGenerate(string $project): void
    {
        $root = self::FIXTURES . '/projects/' . $project;

        $status = Status::report($root);
        $status['root'] = '<root>';
        self::assertGolden("expected/{$project}/status.json", self::encode($status), "status of {$project}");

        $plan = Generate::plan($root);
        $summary = ['targets' => $plan['targets'], 'actions' => []];
        foreach ($plan['actions'] as $action) {
            $summary['actions'][] = array_diff_key($action, ['content' => true]);
            if ($action['content'] !== null) {
                self::assertGolden("expected/{$project}/generated/{$action['target']}/{$action['path']}", $action['content'], "{$project}: {$action['path']}");
            }
        }
        self::assertGolden("expected/{$project}/plan.json", self::encode($summary), "plan of {$project}");
    }

    public function testApplyWritesThePlanAndSkipsExistingFiles(): void
    {
        $tmp = sys_get_temp_dir() . '/runtime-sync-' . uniqid();
        self::copyDir(self::FIXTURES . '/projects/node-js', $tmp);

        try {
            $results = Generate::apply($tmp, Generate::plan($tmp, ['to' => 'php']));
            $written = array_column(array_filter($results, static fn (array $r): bool => $r['action'] === 'written'), 'path');
            self::assertContains('config/server.php', $written);
            self::assertContains('composer.json', $written);
            self::assertFileExists($tmp . '/bin/strapi');
            self::assertTrue(is_executable($tmp . '/bin/strapi'));
            self::assertStringContainsString('"build:php": "php bin/strapi build"', (string) file_get_contents($tmp . '/package.json'));

            $again = Generate::plan($tmp, ['to' => 'php']);
            $writes = array_filter($again['actions'], static fn (array $a): bool => $a['action'] === 'write' || $a['action'] === 'merge');
            self::assertSame([], array_column($writes, 'path'), 'a second run has nothing left to write');
            self::assertTrue(Status::report($tmp)['runtimes']['php']['present']);
        } finally {
            self::removeDir($tmp);
        }
    }

    /** @return array<int|string, mixed> */
    private static function json(string $rel): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . '/' . $rel), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function assertGolden(string $rel, string $actual, string $message): void
    {
        $file = self::FIXTURES . '/' . $rel;
        if (getenv('UPDATE_FIXTURES') === '1') {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0o777, true);
            }
            file_put_contents($file, $actual);
        }
        self::assertFileExists($file, "{$message}: golden {$rel} missing (UPDATE_FIXTURES=1 writes it)");
        self::assertSame((string) file_get_contents($file), $actual, "{$message}: {$rel}");
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . '/' . substr((string) $item, strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0o777, true) : copy((string) $item, $target);
        }
    }

    private static function removeDir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
