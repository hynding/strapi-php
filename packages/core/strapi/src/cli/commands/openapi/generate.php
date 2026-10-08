<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Openapi;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/openapi/generate.ts: the `openapi generate` action
 * (the command is openapi/index.php).
 *
 * @experimental
 */
final class Generate
{
    private const EXPERIMENTAL_MSG = <<<'TXT'

⚠️  The OpenAPI generation feature is currently experimental.
    Its behavior and output might change in future releases without following semver.
    Please report any issues you encounter on https://github.com/strapi/strapi/issues/new?template=BUG_REPORT.yml.

TXT;

    /**
     * @param array{output?: string|null} $options
     * @param callable(): \Strapi\Core\Strapi $createStrapi `createStrapi(compileStrapi())`
     */
    public static function action(array $options, string $cwd, OutputInterface $output, callable $createStrapi): int
    {
        $output->writeln('<fg=yellow>' . self::EXPERIMENTAL_MSG . '</>');

        $filePath = $options['output'] ?? $cwd . '/specification.json';
        if (!str_starts_with($filePath, '/')) {
            $filePath = $cwd . '/' . $filePath;
        }

        $app = self::createStrapiApp($createStrapi);

        ['document' => $document, 'durationMs' => $durationMs] = \Strapi\Openapi\Exports::generate($app, ['type' => 'content-api']);

        self::writeDocumentToFile($document, $filePath);
        self::summarize($app, $durationMs, $filePath, $cwd, $output);

        $app->destroy();

        return Command::SUCCESS;
    }

    /** @param callable(): \Strapi\Core\Strapi $createStrapi */
    private static function createStrapiApp(callable $createStrapi): \Strapi\Core\Strapi
    {
        $app = $createStrapi();
        // Load internals
        $app->load();
        // Make sure the routes are mounted before generating the specification
        $app->server()->mount();

        return $app;
    }

    /** `fse.outputFileSync(filePath, JSON.stringify(document, null, 2))` */
    private static function writeDocumentToFile(mixed $document, string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $json = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        file_put_contents($filePath, (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json));
    }

    private static function summarize(\Strapi\Core\Strapi $app, int|float $durationMs, string $filePath, string $cwd, OutputInterface $output): void
    {
        $info = $app->config()->get('info');
        $name = is_array($info) ? (string) ($info['name'] ?? '') : '';
        $version = is_array($info) ? (string) ($info['version'] ?? '') : '';
        $relativeFilePath = str_starts_with($filePath, $cwd . '/') ? substr($filePath, strlen($cwd) + 1) : $filePath;

        $output->writeln("<options=bold>Generated an OpenAPI specification for \"<fg=cyan>{$name}</> <fg=cyan>v{$version}</>\" at <fg=magenta>{$relativeFilePath}</> in <fg=green>{$durationMs}ms</></>");
    }
}
