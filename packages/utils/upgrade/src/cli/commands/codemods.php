<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli\Commands;

use Strapi\Upgrade\Cli\Errors;
use Strapi\Upgrade\Cli\Options;
use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Version\Types as VersionTypes;
use Strapi\Upgrade\Tasks\Codemods\ListCodemods;
use Strapi\Upgrade\Tasks\Codemods\RunCodemods;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Port of packages/utils/upgrade/src/cli/commands/codemods.ts: `codemods run [uid]` and
 * `codemods ls`. Symfony Console has no sub-commands, so they are registered as `codemods:run`
 * and `codemods:ls`; `Cli` maps upstream's `codemods run …` spelling onto them.
 *
 * @phpstan-import-type RunCodemodsCommandOptions from \Strapi\Upgrade\Cli\Types
 * @phpstan-import-type CLIListCodemodsOptions from \Strapi\Upgrade\Cli\Types
 * @phpstan-import-type VersionedCollection from \Strapi\Upgrade\Modules\Codemod\Types
 */
final class Codemods
{
    private const DEFAULT_TARGET = VersionTypes::MAJOR;

    /**
     * @param RunCodemodsCommandOptions $options
     * @param (\Closure(list<string>): list<int>)|null $choose picks among codemod titles (all selected by default); null selects all
     */
    public static function runCodemods(array $options, ?\Closure $choose = null): int
    {
        $logger = Logger::loggerFactory(['silent' => $options['silent'], 'debug' => $options['debug']]);

        $logger->warn("Please make sure you've created a backup of your codebase and files before running the codemods");

        /**
         * @param list<VersionedCollection> $codemods
         * @return list<VersionedCollection>
         */
        $selectCodemods = static function (array $codemods) use ($logger, $choose): array {
            /** @var list<Codemod> $selectable */
            $selectable = [];
            $titles = [];
            foreach ($codemods as $collection) {
                foreach ($collection['codemods'] as $codemod) {
                    $selectable[] = $codemod;
                    $titles[] = "({$collection['version']}) {$codemod->format()}";
                }
            }

            if ($selectable === []) {
                $logger->info('No codemods to run');

                return [];
            }

            $selected = $choose !== null ? $choose($titles) : array_keys($titles);

            if ($selected === []) {
                $logger->info('No codemods selected');

                return [];
            }

            return array_map(static fn (int $i): array => ['version' => $selectable[$i]->version, 'codemods' => [$selectable[$i]]], $selected);
        };

        try {
            RunCodemods::runCodemods([
                'logger' => $logger,
                'selectCodemods' => $selectCodemods,
                'dry' => $options['dry'],
                'cwd' => $options['projectPath'] ?? null,
                'target' => $options['range'] ?? self::DEFAULT_TARGET,
                'uid' => $options['uid'],
            ]);

            return 0;
        } catch (\Throwable $err) {
            return Errors::handleError($err, $options['silent']);
        }
    }

    /** @param CLIListCodemodsOptions $options */
    public static function listCodemods(array $options): int
    {
        $logger = Logger::loggerFactory(['silent' => $options['silent'], 'debug' => $options['debug']]);

        try {
            ListCodemods::listCodemods([
                'cwd' => $options['projectPath'] ?? null,
                'target' => $options['range'] ?? self::DEFAULT_TARGET,
                'logger' => $logger,
            ]);

            return 0;
        } catch (\Throwable $err) {
            return Errors::handleError($err, $options['silent']);
        }
    }

    /** Registers codemods related commands. */
    public static function register(Application $program): void
    {
        // upgrade codemods run [options] [uid]
        $run = new Command('codemods:run');
        $run->setDescription('Executes a set of codemods on the current project')
            ->setHelp(<<<'TXT'
                Executes a set of codemods on the current project.

                If the optional UID argument is provided, the command specifically runs the codemod associated with that UID.
                Without the UID, the command produces a list of all available codemods for your project.

                By default, when executed on a Strapi application project, it offers codemods matching the current major version of the app.
                When executed on a Strapi plugin project, it shows every codemods.
                TXT)
            ->addArgument('uid', InputArgument::OPTIONAL);
        foreach ([Options::projectPathOption(...), Options::dryOption(...), Options::debugOption(...), Options::silentOption(...), Options::rangeOption(...)] as $add) {
            $add($run);
        }
        $run->setCode(static function (InputInterface $input, OutputInterface $output) use ($run): int {
            $uid = $input->getArgument('uid');
            $choose = static function (array $titles) use ($run, $input, $output): array {
                if (!$input->isInteractive()) {
                    return array_keys($titles);
                }
                $helper = $run->getHelper('question');
                assert($helper instanceof QuestionHelper);
                $question = new ChoiceQuestion('Choose the codemods you would like to run (comma-separated):', $titles, implode(',', array_keys($titles)));
                $question->setMultiselect(true);
                $answers = (array) $helper->ask($input, $output, $question);

                return array_values(array_map(static fn (mixed $a): int => (int) array_search($a, $titles, true), $answers));
            };

            return self::runCodemods([
                'debug' => (bool) $input->getOption('debug'),
                'silent' => (bool) $input->getOption('silent'),
                'projectPath' => Options::projectPath($input),
                'range' => Options::parseRange($input),
                'dry' => (bool) $input->getOption('dry'),
                'uid' => is_string($uid) ? $uid : null,
            ], $choose);
        });
        $program->add($run);

        // upgrade codemods ls [options]
        $ls = new Command('codemods:ls');
        $ls->setDescription('List available codemods');
        foreach ([Options::projectPathOption(...), Options::debugOption(...), Options::silentOption(...), Options::rangeOption(...)] as $add) {
            $add($ls);
        }
        $ls->setCode(static fn (InputInterface $input): int => self::listCodemods([
            'debug' => (bool) $input->getOption('debug'),
            'silent' => (bool) $input->getOption('silent'),
            'projectPath' => Options::projectPath($input),
            'range' => Options::parseRange($input),
        ]));
        $program->add($ls);
    }
}
