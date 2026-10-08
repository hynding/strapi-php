<?php

declare(strict_types=1);

namespace Strapi\Generators;

use Strapi\Generators\Plops\Utils\Files;

/**
 * Port of src/index.ts.
 *
 * - `generate(name, options, ['dir' => ...])` runs a generator's actions with the given answers
 *   (no prompt), writing under `<dir>/src` — what the content-type builder calls.
 * - `runCLI(...)` is the interactive runner behind `strapi generate` (plop's CLI upstream): pick a
 *   generator, answer its prompts through an {@see Inquirer}, run its actions.
 *
 * Actions follow plop: `add` fails when the file exists (unless `skipIfExists`), `modify` leaves
 * a missing file alone, a callable action runs with the answers. Templates are this package's
 * `src/templates/php/*.tpl` files, rendered by {@see Template}.
 *
 * @phpstan-import-type Generator from Plop
 * @phpstan-type Change array{type: string, path: string}
 */
final class Generators
{
    /** Builds the Plop API for a project and runs the plopfile on it. */
    public static function plop(string $dir): Plop
    {
        $plop = new Plop(self::resolve($dir) . '/src');
        (new Plopfile())($plop);

        return $plop;
    }

    /**
     * @param array<string, mixed> $options the answers
     * @param array{dir?: string} $generateOptions
     * @return array{success: true, changes: list<Change>}
     */
    public static function generate(string $generatorName, array $options, array $generateOptions = []): array
    {
        $resolvedDir = self::resolve($generateOptions['dir'] ?? (string) getcwd());
        $plop = self::plop($resolvedDir);

        $generator = $plop->getGenerator($generatorName);
        if ($generator === null) {
            throw new \RuntimeException("Generator \"{$generatorName}\" not found");
        }

        $actions = ($generator['actions'])($options);

        $changes = self::executeActions($actions, $options, $resolvedDir, $plop);

        return ['success' => true, 'changes' => $changes];
    }

    /**
     * The interactive runner (`strapi generate [generator]`).
     *
     * @param list<string> $bypass plop's positional answers, in prompt order (`_` skips one)
     * @param callable(string): void|null $log
     * @return array{success: true, changes: list<Change>}
     */
    public static function runCLI(Inquirer $inquirer, string $dir, ?string $generatorName = null, array $bypass = [], ?callable $log = null): array
    {
        $resolvedDir = self::resolve($dir);
        $plop = self::plop($resolvedDir);
        $generators = $plop->getGenerators();

        if ($generatorName === null || $generatorName === '') {
            $generatorName = (string) ($inquirer->prompt([
                [
                    'type' => 'list',
                    'name' => 'generator',
                    'message' => $plop->getWelcomeMessage(),
                    'choices' => array_map(
                        static fn (string $name, array $generator): array => ['name' => "{$name} - {$generator['description']}", 'value' => $name],
                        array_keys($generators),
                        array_values($generators),
                    ),
                ],
            ])['generator'] ?? '');
        }

        $generator = $generators[$generatorName] ?? null;
        if ($generator === null) {
            throw new \RuntimeException("Generator \"{$generatorName}\" not found. Available generators: " . implode(', ', array_keys($generators)));
        }

        $prompts = $generator['prompts'];
        if (is_callable($prompts)) {
            if ($bypass !== [] && $inquirer instanceof ConsoleInquirer && $bypass !== ['_']) {
                throw new \RuntimeException("The \"{$generatorName}\" generator asks dynamic questions: pass its answers as --<name>=<value> after --, not as positional arguments");
            }
            $answers = $prompts($inquirer);
        } else {
            if ($inquirer instanceof ConsoleInquirer) {
                $presets = [];
                foreach (array_values($bypass) as $index => $value) {
                    if ($value === '_' || !isset($prompts[$index])) {
                        continue;
                    }
                    $presets[$prompts[$index]['name']] ??= $value;
                }
                $inquirer->addPresets(array_filter($presets, static fn (string $name): bool => !$inquirer->hasPreset($name), ARRAY_FILTER_USE_KEY));
            }
            $answers = $inquirer->prompt($prompts);
        }

        $actions = ($generator['actions'])($answers);
        $changes = self::executeActions($actions, $answers, $resolvedDir, $plop);

        if ($log !== null) {
            foreach ($changes as $change) {
                $log(match ($change['type']) {
                    'add' => "✔  ++ /{$change['path']}",
                    'modify' => "✔  +- /{$change['path']}",
                    'skip' => "⚠  skipped /{$change['path']} (exists)",
                    default => "✔  {$change['path']}",
                });
            }
        }

        return ['success' => true, 'changes' => $changes];
    }

    /**
     * Executes generator actions: add or modify files as specified
     *
     * @param list<mixed> $actions
     * @param array<string, mixed> $options
     * @return list<Change>
     */
    private static function executeActions(array $actions, array $options, string $dir, Plop $plop): array
    {
        $changes = [];
        $helpers = $plop->getHelpers();

        foreach ($actions as $action) {
            if (is_callable($action)) {
                $result = $action($options);
                $changes[] = ['type' => 'function', 'path' => is_string($result) ? $result : ''];
                continue;
            }

            if (!is_array($action)) {
                continue;
            }

            $outputPath = Template::render((string) $action['path'], $options, $helpers);
            $fullPath = self::normalize($dir . '/src/' . $outputPath);
            $relativePath = str_starts_with($fullPath, $dir . '/') ? substr($fullPath, strlen($dir) + 1) : $fullPath;

            if ($action['type'] === 'add' && isset($action['templateFile'])) {
                if (file_exists($fullPath)) {
                    if (($action['skipIfExists'] ?? false) === true) {
                        $changes[] = ['type' => 'skip', 'path' => $relativePath];
                        continue;
                    }

                    throw new \RuntimeException("File already exists\n -> {$fullPath}");
                }

                $templatePath = __DIR__ . '/' . $action['templateFile'];
                $templateContent = file_get_contents($templatePath);
                if ($templateContent === false) {
                    throw new \RuntimeException("Template not found: {$templatePath}");
                }
                $data = is_array($action['data'] ?? null) ? $action['data'] : [];
                $output = Template::render($templateContent, [...$options, ...$data], $helpers);

                Files::outputFile($fullPath, $output);
                $changes[] = ['type' => 'add', 'path' => $relativePath];
            }

            if ($action['type'] === 'modify') {
                if (file_exists($fullPath)) {
                    $content = (string) file_get_contents($fullPath);
                    $transform = $action['transform'] ?? null;
                    $modified = is_callable($transform) ? $transform($content) : $content;
                    Files::outputFile($fullPath, is_string($modified) ? $modified : $content);
                    $changes[] = ['type' => 'modify', 'path' => $relativePath];
                }
            }
        }

        return $changes;
    }

    private static function resolve(string $dir): string
    {
        if (!str_starts_with($dir, '/')) {
            $dir = getcwd() . '/' . $dir;
        }

        return self::normalize($dir);
    }

    /** Resolves `.` and `..` segments (path.join). */
    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/' . implode('/', $parts);
    }
}
