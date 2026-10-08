<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Codemods;

use Strapi\Upgrade\Modules\CodemodRepository\CodemodRepository;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Project\Project;

/**
 * Port of packages/utils/upgrade/src/tasks/codemods/list-codemods.ts.
 *
 * @phpstan-import-type ListCodemodsOptions from Types
 */
final class ListCodemods
{
    /** @param ListCodemodsOptions $options */
    public static function listCodemods(array $options): void
    {
        $logger = $options['logger'];

        $cwd = Utils::resolvePath($options['cwd'] ?? null);
        $project = Project::projectFactory($cwd);
        $range = Utils::findRangeFromTarget($project, $options['target']);

        $logger->debug(F::projectDetails($project));
        $logger->debug('Range: set to ' . F::versionRange($range));

        // Create a codemod repository targeting the default location of the codemods
        $repo = CodemodRepository::codemodRepositoryFactory();

        // Make sure all the codemods are loaded
        $repo->refresh();

        // Find groups of codemods matching the given range
        $groups = $repo->find(['range' => $range]);

        // Flatten the groups into a simple codemod array
        $codemods = array_merge(...array_map(static fn (array $c): array => $c['codemods'], $groups));

        // Debug
        $logger->debug('Found ' . F::highlight(count($codemods)) . ' codemods');

        // Don't log an empty table
        if ($codemods === []) {
            $logger->info('Found no codemods matching ' . F::versionRange($range));

            return;
        }

        // Format the list to a pretty table
        $logger->raw(F::codemodList($codemods));
    }
}
