<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Runner;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Runner\Upstream\Types;
use Strapi\Upgrade\Modules\Runner\Upstream\UpstreamRunner;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** PHP-only: upstream's codemods on the project's JS/TS files, through a fake upstream tool. */
final class UpstreamTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = $this->volume(['src' => ['admin' => ['app.tsx' => "export default {};\n"], 'index.ts' => "export {};\n"]]);
    }

    private static function codemod(string $filename = 'deprecate-helper-plugin.code.php', string $kind = 'code'): Codemod
    {
        return Codemod::codemodFactory(['kind' => $kind, 'baseDirectory' => '/codemods', 'filename' => $filename, 'version' => new SemVer('5.0.0')]);
    }

    /** @param list<string>|null $command */
    private function runner(bool $dry, ?array $command = [PHP_BINARY, __DIR__ . '/../../fixtures/fake-upstream-upgrade.php']): UpstreamRunner
    {
        return UpstreamRunner::upstreamRunnerFactory(["{$this->cwd}/src/admin/app.tsx", "{$this->cwd}/src/index.ts"], [
            'dry' => $dry,
            'cwd' => $this->cwd,
            'strapiVersion' => '5.56.0',
            'command' => $command,
        ]);
    }

    public function testValidForCodeCodemodsWhenThereAreFiles(): void
    {
        self::assertTrue($this->runner(true)->valid(self::codemod()));
        self::assertFalse($this->runner(true)->valid(self::codemod('x.json.php', 'json')));
        self::assertFalse(UpstreamRunner::upstreamRunnerFactory([], ['cwd' => $this->cwd, 'strapiVersion' => '5.56.0'])->valid(self::codemod()));
    }

    public function testDryRunsReportWithoutWriting(): void
    {
        $report = $this->runner(true)->run(self::codemod());

        self::assertSame([1, 1, 0, 0], [$report['ok'], $report['nochange'], $report['skip'], $report['error']]);
        self::assertSame("export default {};\n", file_get_contents("{$this->cwd}/src/admin/app.tsx"));
    }

    public function testRunsCopyChangedFilesBack(): void
    {
        $this->runner(false)->run(self::codemod());

        self::assertSame("export default {};\n// 5.0.0-deprecate-helper-plugin-code @strapi/strapi@5.56.0\n", file_get_contents("{$this->cwd}/src/admin/app.tsx"));
        self::assertSame("export {};\n", file_get_contents("{$this->cwd}/src/index.ts"));
    }

    public function testFailingCommandsAreErrors(): void
    {
        $report = $this->runner(false)->run(self::codemod('x-fail.code.php'));

        self::assertSame(2, $report['error']);
        self::assertSame(1, $report['stats']['upstream-exit-code']);
    }

    public function testWithoutNodeEveryFileIsSkipped(): void
    {
        $previous = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $report = $this->runner(false, null)->run(self::codemod());
        } finally {
            putenv("PATH={$previous}");
        }

        self::assertSame(2, $report['skip']);
        self::assertSame(2, $report['stats'][Types::STAT_UNAVAILABLE]);
    }
}
