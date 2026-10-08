<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli;

use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\Range;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** Port of packages/utils/upgrade/src/cli/options.ts: the shared options, added to Symfony commands. */
final class Options
{
    public static function projectPathOption(Command $command): Command
    {
        return $command->addOption('project-path', 'p', InputOption::VALUE_REQUIRED, 'Root path to the Strapi application or plugin');
    }

    public static function dryOption(Command $command): Command
    {
        return $command->addOption('dry', 'n', InputOption::VALUE_NONE, 'Simulate the upgrade without updating any files');
    }

    public static function debugOption(Command $command): Command
    {
        return $command->addOption('debug', 'd', InputOption::VALUE_NONE, 'Get more logs in debug mode');
    }

    public static function silentOption(Command $command): Command
    {
        return $command->addOption('silent', 's', InputOption::VALUE_NONE, "Don't log anything");
    }

    public static function autoConfirmOption(Command $command): Command
    {
        return $command->addOption('yes', 'y', InputOption::VALUE_NONE, 'Automatically answer "yes" to any prompts that the CLI might print on the command line.');
    }

    public static function rangeOption(Command $command): Command
    {
        return $command->addOption('range', 'r', InputOption::VALUE_REQUIRED, 'Use a custom semver range for the codemods execution.');
    }

    /** the `--range` argument parser */
    public static function parseRange(InputInterface $input): ?SemverRange
    {
        $range = $input->getOption('range');
        if (!is_string($range)) {
            return null;
        }

        if (!Range::isValidStringifiedRange($range)) {
            throw new InvalidArgumentException('Expected a valid semver range');
        }

        return Range::rangeFactory($range);
    }

    public static function projectPath(InputInterface $input): ?string
    {
        $path = $input->getOption('project-path');

        return is_string($path) ? $path : null;
    }
}
