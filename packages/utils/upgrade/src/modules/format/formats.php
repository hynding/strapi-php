<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Format;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Timer\Constants as TimerConstants;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Port of packages/utils/upgrade/src/modules/format/formats.ts (`import * as f from
 * '../format'`). Tables are drawn with Symfony Console's `Table` where upstream uses
 * `cli-table3`.
 *
 * @phpstan-import-type CodemodReport from \Strapi\Upgrade\Modules\Report\Types
 */
final class Formats
{
    public static function path(string $path): string
    {
        return Chalk::blue($path);
    }

    public static function version(NodeSemVer|string $version): string
    {
        return Chalk::italic(Chalk::yellow("v{$version}"));
    }

    public static function codemodUID(string $uid): string
    {
        return Chalk::bold(Chalk::cyan($uid));
    }

    public static function projectDetails(Project $project): string
    {
        $paths = implode(',', array_map(self::path(...), $project->paths));

        return 'Project: TYPE=' . self::projectType($project->type()) . '; CWD=' . self::path($project->cwd) . "; PATHS={$paths}";
    }

    public static function projectType(string $type): string
    {
        return Chalk::cyan($type);
    }

    public static function versionRange(SemverRange $range): string
    {
        return Chalk::italic(Chalk::yellow($range->raw));
    }

    public static function transform(string $transformFilePath): string
    {
        return Chalk::cyan($transformFilePath);
    }

    public static function highlight(mixed $arg): string
    {
        return Chalk::bold(Chalk::underline($arg));
    }

    /** @param array{0: int, 1: int} $step */
    public static function upgradeStep(string $text, array $step): string
    {
        return Chalk::bold("({$step[0]}/{$step[1]}) {$text}...");
    }

    /** @param list<CodemodReport> $reports */
    public static function reports(array $reports): string
    {
        $rows = [];
        foreach ($reports as $i => ['codemod' => $codemod, 'report' => $report]) {
            $fTimeElapsed = $i === 0
                ? "{$report['timeElapsed']}s " . Chalk::dim(Chalk::italic('(cold start)'))
                : "{$report['timeElapsed']}s";

            $rows[] = [
                Chalk::grey($i),
                Chalk::magenta($codemod->version),
                Chalk::yellow($codemod->kind),
                Chalk::cyan($codemod->format()),
                $report['ok'] > 0 ? Chalk::green($report['ok']) : Chalk::grey(0),
                $report['ok'] === 0 ? Chalk::red($report['nochange']) : Chalk::grey($report['nochange']),
                $fTimeElapsed,
            ];
        }

        return self::table([
            Chalk::bold(Chalk::grey('N°')),
            Chalk::bold(Chalk::magenta('Version')),
            Chalk::bold(Chalk::yellow('Kind')),
            Chalk::bold(Chalk::cyan('Name')),
            Chalk::bold(Chalk::green('Affected')),
            Chalk::bold(Chalk::red('Unchanged')),
            Chalk::bold(Chalk::blue('Duration')),
        ], $rows);
    }

    /** @param list<Codemod> $codemods */
    public static function codemodList(array $codemods): string
    {
        $rows = [];
        foreach ($codemods as $index => $codemod) {
            $rows[] = [
                Chalk::grey($index),
                Chalk::magenta($codemod->version),
                Chalk::yellow($codemod->kind),
                Chalk::blue($codemod->format()),
                self::codemodUID($codemod->uid),
            ];
        }

        return self::table([
            Chalk::bold(Chalk::grey('N°')),
            Chalk::bold(Chalk::magenta('Version')),
            Chalk::bold(Chalk::yellow('Kind')),
            Chalk::bold(Chalk::blue('Name')),
            Chalk::bold(Chalk::cyan('UID')),
        ], $rows);
    }

    public static function durationMs(int|float $elapsedMs): string
    {
        $elapsedSeconds = number_format($elapsedMs / TimerConstants::ONE_SECOND_MS, 3, '.', '');

        return "{$elapsedSeconds}s";
    }

    /**
     * @param list<string> $head
     * @param list<list<string>> $rows
     */
    private static function table(array $head, array $rows): string
    {
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
        $table = new Table($output);
        $table->setHeaders($head);
        $table->setRows($rows);
        $table->render();

        return rtrim($output->fetch(), "\n");
    }
}
