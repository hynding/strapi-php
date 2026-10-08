<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Runner;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Runner\Code\CodeRunner;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/**
 * Port of src/modules/runner/__tests__/code.test.ts. Upstream checks delegation to jscodeshift;
 * here the runner's own PHP engine runs and its jscodeshift-style outcomes are checked.
 */
final class CodeTest extends TestCase
{
    private string $cwd;

    /** @var list<string> */
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = $this->volume([
            'codemods' => ['1.2.3' => [
                'upper.code.php' => '<?php return static fn (array $file): string => str_contains($file["source"], "change") ? strtoupper($file["source"]) : $file["source"];',
                'skip.code.php' => '<?php return static fn (array $file): ?string => null;',
                'throw.code.php' => '<?php return static function (array $file): string { throw new RuntimeException("x"); };',
                'none.code.php' => '<?php return null;',
                'foo.json.php' => '<?php return null;',
            ]],
            'a.php' => '<?php // change me',
            'b.php' => '<?php // keep',
            'c.ts' => 'const c = 1;',
        ]);
        $this->paths = ["{$this->cwd}/a.php", "{$this->cwd}/b.php", "{$this->cwd}/c.ts"];
    }

    private function codemod(string $filename, string $kind = 'code'): Codemod
    {
        return Codemod::codemodFactory(['kind' => $kind, 'baseDirectory' => "{$this->cwd}/codemods", 'filename' => $filename, 'version' => new SemVer('1.2.3')]);
    }

    private function runner(bool $dry = true): CodeRunner
    {
        return CodeRunner::codeRunnerFactory($this->paths, ['dry' => $dry, 'cwd' => $this->cwd, 'extensions' => 'php', 'silent' => true]);
    }

    public function testValidReturnsTrueForCodeCodemodsOnly(): void
    {
        self::assertTrue($this->runner()->valid($this->codemod('upper.code.php')));
        self::assertFalse($this->runner()->valid($this->codemod('foo.json.php', 'json')));
    }

    public function testRunsTheTransformOnFilesWithTheConfiguredExtensions(): void
    {
        $report = $this->runner()->run($this->codemod('upper.code.php'));

        self::assertSame([1, 1, 0, 0], [$report['ok'], $report['nochange'], $report['skip'], $report['error']]);
        // dry: nothing written
        self::assertSame('<?php // change me', file_get_contents("{$this->cwd}/a.php"));

        $this->runner(false)->run($this->codemod('upper.code.php'));
        self::assertSame('<?PHP // CHANGE ME', file_get_contents("{$this->cwd}/a.php"));
    }

    public function testNoOutputIsASkipAndExceptionsAreErrors(): void
    {
        self::assertSame(2, $this->runner()->run($this->codemod('skip.code.php'))['skip']);
        self::assertSame(2, $this->runner()->run($this->codemod('none.code.php'))['skip']);
        self::assertSame(2, $this->runner()->run($this->codemod('throw.code.php'))['error']);
    }

    public function testThrowsOnInvalidCodemod(): void
    {
        $this->expectExceptionMessage('Invalid codemod provided to the runner: foo.json.php');

        $this->runner()->run($this->codemod('foo.json.php', 'json'));
    }
}
