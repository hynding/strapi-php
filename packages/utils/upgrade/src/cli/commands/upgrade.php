<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli\Commands;

use Strapi\Upgrade\Cli\Errors;
use Strapi\Upgrade\Cli\Options;
use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Version\Semver;
use Strapi\Upgrade\Modules\Version\Types as VersionTypes;
use Strapi\Upgrade\Tasks\Upgrade\Upgrade as UpgradeTask;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Port of packages/utils/upgrade/src/cli/commands/upgrade.ts: `latest`, `major`, `minor`,
 * `patch` and `to <target>`.
 *
 * @phpstan-import-type UpgradeCommandOptions from \Strapi\Upgrade\Cli\Types
 */
final class Upgrade
{
    /**
     * @param UpgradeCommandOptions $options
     * @param (\Closure(string): bool)|null $prompt
     */
    public static function upgrade(array $options, ?\Closure $prompt = null): int
    {
        try {
            $logger = Logger::loggerFactory(['silent' => $options['silent'], 'debug' => $options['debug']]);

            $logger->warn("Please make sure you've created a backup of your codebase and files before upgrading");

            $yes = $options['yes'] ?? false;
            $confirm = static function (string $message) use ($yes, $prompt): bool {
                if ($yes) {
                    return true;
                }

                // If there is no way to answer, default to false
                return $prompt !== null ? $prompt($message) : false;
            };

            UpgradeTask::upgrade([
                'logger' => $logger,
                'confirm' => $confirm,
                'dry' => $options['dry'],
                'cwd' => $options['projectPath'] ?? null,
                'target' => $options['target'],
                'codemodsTarget' => $options['codemodsTarget'] ?? null,
            ]);

            return 0;
        } catch (\Throwable $err) {
            return Errors::handleError($err, $options['silent']);
        }
    }

    /** @return \Closure(string): bool */
    public static function prompter(Command $command, InputInterface $input, OutputInterface $output): \Closure
    {
        return static function (string $message) use ($command, $input, $output): bool {
            if (!$input->isInteractive()) {
                return false;
            }
            $helper = $command->getHelper('question');
            assert($helper instanceof QuestionHelper);

            return (bool) $helper->ask($input, $output, new ConfirmationQuestion("{$message} (y/N) ", false));
        };
    }

    /** Registers upgrade related commands. */
    public static function register(Application $program): void
    {
        $addReleaseUpgradeCommand = static function (string $releaseType, string $description) use ($program): void {
            $command = new Command($releaseType);
            $command->setDescription($description);
            foreach ([Options::projectPathOption(...), Options::dryOption(...), Options::debugOption(...), Options::silentOption(...), Options::autoConfirmOption(...)] as $add) {
                $add($command);
            }
            $command->setCode(static fn (InputInterface $input, OutputInterface $output): int => self::upgrade([
                ...self::commonOptions($input),
                'target' => $releaseType,
            ], self::prompter($command, $input, $output)));
            $program->add($command);
        };

        // upgrade latest
        $addReleaseUpgradeCommand(VersionTypes::LATEST, 'Upgrade to the latest available version of Strapi');

        // upgrade major
        $addReleaseUpgradeCommand(VersionTypes::MAJOR, 'Upgrade to the next available major version of Strapi');

        // upgrade minor
        $addReleaseUpgradeCommand(VersionTypes::MINOR, 'Upgrade to the latest minor and patch version of Strapi for the current major');

        // upgrade patch
        $addReleaseUpgradeCommand(VersionTypes::PATCH, 'Upgrade to latest patch version of Strapi for the current major and minor');

        // upgrade to <target>
        $to = new Command('to');
        $to->setDescription('Upgrade to a specific version of Strapi')
            ->addArgument('target', InputArgument::REQUIRED, 'Target version');
        foreach ([Options::projectPathOption(...), Options::dryOption(...), Options::debugOption(...), Options::silentOption(...), Options::autoConfirmOption(...)] as $add) {
            $add($to);
        }
        $to->addOption('codemods-target', 'c', InputOption::VALUE_REQUIRED, 'Use a custom target for the codemods execution. Useful when targeting pre-releases');
        $to->setCode(static function (InputInterface $input, OutputInterface $output) use ($to): int {
            $target = (string) $input->getArgument('target');
            if (!Semver::isValidSemVer($target)) {
                throw new InvalidArgumentException("Invalid target supplied, expected a valid semver but got \"{$target}\"");
            }

            $codemodsTarget = $input->getOption('codemods-target');
            if (is_string($codemodsTarget) && !Semver::isLiteralSemVer($codemodsTarget)) {
                throw new InvalidArgumentException('Expected a version with the following format: "<number>.<number>.<number>"');
            }

            return self::upgrade([
                ...self::commonOptions($input),
                'target' => Semver::semVerFactory($target),
                'codemodsTarget' => is_string($codemodsTarget) ? Semver::semVerFactory($codemodsTarget) : null,
            ], self::prompter($to, $input, $output));
        });
        $program->add($to);
    }

    /** @return array{debug: bool, silent: bool, projectPath: string|null, yes: bool, dry: bool} */
    private static function commonOptions(InputInterface $input): array
    {
        return [
            'debug' => (bool) $input->getOption('debug'),
            'silent' => (bool) $input->getOption('silent'),
            'projectPath' => Options::projectPath($input),
            'yes' => (bool) $input->getOption('yes'),
            'dry' => (bool) $input->getOption('dry'),
        ];
    }
}
