<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Openapi;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/openapi/{index,generate}.ts:
 * `$ strapi openapi generate [-o, --output <path>]`, here `openapi:generate` (Symfony Console
 * names subcommands with a colon).
 *
 * @experimental
 */
final class Generate extends StrapiCommand
{
    private const EXPERIMENTAL_MSG = <<<'TXT'

⚠️  The OpenAPI generation feature is currently experimental.
    Its behavior and output might change in future releases without following semver.
    Please report any issues you encounter on https://github.com/strapi/strapi/issues/new?template=BUG_REPORT.yml.

TXT;

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('openapi:generate')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path for the OpenAPI specification')
            ->setDescription('Generate an OpenAPI specification for the current Strapi application');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<fg=yellow>' . self::EXPERIMENTAL_MSG . '</>');

        $cwd = $this->ctx->cwd;
        $option = $input->getOption('output');
        $filePath = is_string($option) && $option !== '' ? $option : $cwd . '/specification.json';
        if (!str_starts_with($filePath, '/')) {
            $filePath = $cwd . '/' . $filePath;
        }

        $app = $this->createStrapi();
        // Load internals
        $app->load();
        // Make sure the routes are mounted before generating the specification
        $app->server()->mount();

        ['document' => $document, 'durationMs' => $durationMs] = \Strapi\Openapi\Exports::generate($app, ['type' => 'content-api']);

        // fse.outputFileSync(filePath, JSON.stringify(document, null, 2))
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        file_put_contents($filePath, (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json));

        $info = $app->config()->get('info');
        $name = is_array($info) ? (string) ($info['name'] ?? '') : '';
        $version = is_array($info) ? (string) ($info['version'] ?? '') : '';
        $relativeFilePath = str_starts_with($filePath, $cwd . '/') ? substr($filePath, strlen($cwd) + 1) : $filePath;

        $output->writeln("<options=bold>Generated an OpenAPI specification for \"<fg=cyan>{$name}</> <fg=cyan>v{$version}</>\" at <fg=magenta>{$relativeFilePath}</> in <fg=green>{$durationMs}ms</></>");

        $app->destroy();

        return Command::SUCCESS;
    }
}
