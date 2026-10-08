<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Tasks;

use Strapi\Upgrade\Modules\CodemodRunner\CodemodRunner;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tasks\Codemods\ListCodemods;
use Strapi\Upgrade\Tasks\Codemods\RunCodemods;
use Strapi\Upgrade\Tasks\Codemods\Utils;
use Strapi\Upgrade\Tests\TestCase;

/**
 * Port of src/tasks/__tests__/codemods.test.ts (the codemod runner is replaced through the
 * task's `codemodRunnerFactory` option), plus `codemods ls` and real runs on a project.
 */
final class CodemodsTest extends TestCase
{
    /** @var array{success: true, error: null}|array{success: false, error: \Throwable} */
    private array $runReport = ['success' => true, 'error' => null];

    private ?SemverRange $range = null;

    /** @return array<string, mixed> */
    private function options(string|SemverRange $target = Types::MAJOR): array
    {
        [$logger] = self::memoryLogger();
        $cwd = $this->volume(self::appTree('3.6.0'));

        return [
            'target' => $target,
            'logger' => $logger,
            'dry' => false,
            'cwd' => $cwd,
            'selectCodemods' => static fn (array $codemods): array => $codemods,
            'codemodRunnerFactory' => function (Project $project, SemverRange $range): CodemodRunner {
                $this->range = $range;
                $report = $this->runReport;

                return new class ($project, $range, $report) extends CodemodRunner {
                    /** @param array{success: true, error: null}|array{success: false, error: \Throwable} $report */
                    public function __construct(Project $project, SemverRange $range, private array $report)
                    {
                        parent::__construct($project, $range);
                    }

                    public function run(?string $codemodsDirectory = null): array
                    {
                        return $this->report;
                    }
                };
            },
        ];
    }

    public function testCompletesCodemodExecutionSuccessfully(): void
    {
        RunCodemods::runCodemods($this->options());

        self::assertSame('3', $this->range?->raw);
    }

    public function testThrowsAnErrorOnCodemodExecutionFailure(): void
    {
        $this->runReport = ['success' => false, 'error' => new \RuntimeException('Mock error')];

        $this->expectExceptionMessage('Mock error');
        RunCodemods::runCodemods($this->options());
    }

    public function testHandlesInvalidTargetVersion(): void
    {
        $this->expectExceptionMessage('Invalid target set');

        RunCodemods::runCodemods($this->options('invalid'));
    }

    public function testRangesFromTargets(): void
    {
        $project = Project::projectFactory($this->volume(self::appTree('5.56.0-beta.1')));

        self::assertSame('5', Utils::findRangeFromTarget($project, Types::MAJOR)->raw);
        self::assertSame('5.56', Utils::findRangeFromTarget($project, Types::MINOR)->raw);
        self::assertSame('5.56.0', Utils::findRangeFromTarget($project, Types::PATCH)->raw);
        self::assertSame('>=5', Utils::findRangeFromTarget($project, new SemverRange('>=5'))->raw);

        $plugin = Project::projectFactory($this->volume(['package.json' => '{"strapi": {"kind": "plugin"}}']));
        self::assertSame('*', Utils::findRangeFromTarget($plugin, Types::MAJOR)->raw);
    }

    public function testListCodemods(): void
    {
        [$logger, $out] = self::memoryLogger();
        $cwd = $this->volume(self::appTree('5.56.0'));

        ListCodemods::listCodemods(['logger' => $logger, 'cwd' => $cwd, 'target' => new SemverRange('5.0.0')]);
        $table = self::read($out);
        self::assertStringContainsString('5.0.0-entity-service-document-service-code', $table);
        self::assertStringContainsString('dependency upgrade react router dom', $table);

        ListCodemods::listCodemods(['logger' => $logger, 'cwd' => $cwd, 'target' => Types::PATCH]);
        self::assertStringContainsString('Found no codemods matching 5.56.0', self::read($out));
    }

    public function testRunsABundledCodemodByUid(): void
    {
        [$logger, $out] = self::memoryLogger();
        $cwd = $this->volume(self::appTree('5.56.0', extra: ['src' => ['api' => ['a' => ['services' => ['a.php' => "<?php\n\nreturn fn (\$strapi) => \$strapi->config()->get('plugin.upload.x');\n"]]]]]));

        RunCodemods::runCodemods(['logger' => $logger, 'cwd' => $cwd, 'target' => Types::MAJOR, 'uid' => '5.0.0-use-uid-for-config-namespace-code']);

        self::assertStringContainsString("get('plugin::upload.x')", (string) file_get_contents("{$cwd}/src/api/a/services/a.php"));
        self::assertMatchesRegularExpression('/use uid for config namespace\s*\|\s*1\s*\|/', self::read($out));
    }

    public function testUnknownUid(): void
    {
        [$logger] = self::memoryLogger();

        $this->expectExceptionMessage('Unknown codemod UID provided: 5.0.0-nope-code');
        RunCodemods::runCodemods(['logger' => $logger, 'cwd' => $this->volume(self::appTree('5.56.0')), 'target' => Types::MAJOR, 'uid' => '5.0.0-nope-code']);
    }
}
