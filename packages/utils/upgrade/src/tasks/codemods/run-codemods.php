<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Codemods;

use Strapi\Upgrade\Modules\CodemodRunner\CodemodRunner;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Timer\Timer;

/**
 * Port of packages/utils/upgrade/src/tasks/codemods/run-codemods.ts.
 *
 * @phpstan-import-type RunCodemodsOptions from Types
 */
final class RunCodemods
{
    /** @param RunCodemodsOptions $options */
    public static function runCodemods(array $options): void
    {
        $timer = Timer::timerFactory();
        $logger = $options['logger'];
        $uid = $options['uid'] ?? null;

        // Make sure we're resolving the correct working directory based on the given input
        $cwd = Utils::resolvePath($options['cwd'] ?? null);

        $project = Project::projectFactory($cwd);
        $range = Utils::findRangeFromTarget($project, $options['target']);

        $logger->debug(F::projectDetails($project));
        $logger->debug('Range: set to ' . F::versionRange($range));

        $factory = $options['codemodRunnerFactory'] ?? CodemodRunner::codemodRunnerFactory(...);
        $codemodRunner = $factory($project, $range)
            ->dry($options['dry'] ?? false)
            ->onSelectCodemods($options['selectCodemods'] ?? null)
            ->setLogger($logger);

        // If uid is defined, only run the selected codemod
        if ($uid !== null) {
            $logger->debug('Running a single codemod: ' . F::codemodUID($uid));
            $report = $codemodRunner->runByUID($uid);
        }
        // By default, only filter using the specified range
        else {
            $report = $codemodRunner->run();
        }

        if (!$report['success']) {
            throw $report['error'];
        }

        $timer->stop();

        $logger->info('Completed in ' . F::durationMs($timer->elapsedMs()));
    }
}
