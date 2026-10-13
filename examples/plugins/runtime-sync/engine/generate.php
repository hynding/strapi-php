<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/generate.js: plans and writes the files a project lacks for a runtime. A plan
 * is a list of actions the CLI shows (`--dry-run`) or applies; nothing existing is overwritten
 * unless `force`, and manifests (package.json, .gitignore) are merged, never replaced.
 *
 * @phpstan-type Action array{path: string, target: 'node'|'php', generator: string, source: ?string, action: 'write'|'merge'|'skip'|'error', content: ?string, review: bool, notes: list<string>}
 * @phpstan-type Options array{to?: 'node'|'php'|'both'|null, language?: 'js'|'ts'|null, force?: bool}
 */
final class Generate
{
    /**
     * @param Options $options
     * @return array{targets: list<'node'|'php'>, actions: list<Action>}
     */
    public static function plan(string $root, array $options = []): array
    {
        $root = Files::realRoot($root);
        $project = Project::inspect($root);
        $scan = Scan::run($root);
        $targets = self::targets($project, $options['to'] ?? null);
        $language = $options['language'] ?? $project['node']['language'];
        $force = $options['force'] ?? false;

        $actions = [];
        foreach ($targets as $target) {
            $ext = $target === 'php' ? 'php' : $language;
            $lang = $ext;

            foreach ($scan['paired'] as $pair) {
                if ($pair['state'] !== "missing-{$target}") {
                    continue;
                }
                $source = $target === 'php' ? $pair['node'] : $pair['php'];
                \assert($source !== null);
                $path = $pair['stem'] . '.' . $ext;
                $facet = Spec::pairedFacet($pair['stem']) ?? ['id' => $pair['facet'], 'glob' => '', 'generator' => 'stub'];
                $actions[] = self::pairedAction($root, $path, $target, $lang, $source, $facet, $force);
            }

            array_push($actions, ...self::manifestActions($root, $project, $target, $force));
        }

        return ['targets' => $targets, 'actions' => $actions];
    }

    /**
     * @param array{node: array{present: bool}, php: array{present: bool}} $project
     * @return list<'node'|'php'>
     */
    public static function targets(array $project, ?string $to): array
    {
        if ($to === 'node' || $to === 'php') {
            return [$to];
        }
        if ($to === 'both' || $project['node']['present'] === $project['php']['present']) {
            return ['node', 'php'];
        }

        return $project['node']['present'] ? ['php'] : ['node'];
    }

    /**
     * @param 'node'|'php' $target
     * @param 'php'|'js'|'ts' $lang
     * @param array{id: string, glob: string, generator?: string, factory?: string, shape?: string} $facet
     * @return Action
     */
    private static function pairedAction(string $root, string $path, string $target, string $lang, string $source, array $facet, bool $force): array
    {
        $action = ['path' => $path, 'target' => $target, 'generator' => $facet['generator'] ?? 'stub', 'source' => $source, 'action' => 'write', 'content' => null, 'review' => false, 'notes' => []];
        if (is_file($root . '/' . $path) && !$force) {
            return [...$action, 'action' => 'skip', 'notes' => ['exists']];
        }

        $code = Files::read($root . '/' . $source);
        $sourceLang = Files::extension($source) === 'php' ? 'php' : (Files::extension($source) === 'ts' ? 'ts' : 'js');

        try {
            switch ($facet['generator'] ?? 'stub') {
                case 'config':
                    $template = Templates::counterpart($source, $code, $lang);
                    if ($template !== null) {
                        $action['content'] = $template;
                        $action['notes'][] = 'unchanged template: the other runtime\'s template';
                        break;
                    }
                    $doc = EvaluateConfig::evaluate($root, $source);
                    $emitted = Emit::config($doc, $lang, $path);
                    $action['content'] = $emitted['content'];
                    $action['review'] = $emitted['review'];
                    foreach ($doc['warnings'] as $warning) {
                        $action['notes'][] = $warning;
                    }
                    break;

                case 'app-entry':
                    $action['content'] = Templates::read($target, "src/index.{$lang}") ?? throw new \RuntimeException("no template for src/index.{$lang}");
                    $action['review'] = Source::normalize($code, $sourceLang) !== Source::normalize((string) Templates::read($target === 'php' ? 'node' : 'php', $source), $sourceLang);
                    if ($action['review']) {
                        $action['notes'][] = "{$source} has custom register/bootstrap code: port it by hand";
                        $action['content'] .= "\n/* Original ({$source}):\n" . str_replace('*/', '* /', rtrim($code)) . "\n*/\n";
                    }
                    break;

                case 'core-factory':
                    $factory = Source::detectCoreFactory($code, $sourceLang);
                    if ($factory !== null && $factory['plain']) {
                        $action['content'] = Emit::coreFactory($factory['factory'], $factory['uid'], $lang);
                    } elseif ($factory === null && ($data = self::asData($root, $source)) !== null) {
                        // a routes file is data (`{ routes: [...] }`): translated like a config file
                        $action['content'] = Emit::config($data, $lang, $path)['content'];
                        $action['notes'][] = 'data file: translated';
                    } else {
                        $shape = $factory === null ? 'module' : $factory['kind'];
                        $action['content'] = Emit::stub($shape, $lang, $source, $code, $factory['uid'] ?? null);
                        $action['review'] = true;
                        $action['notes'][] = $factory === null ? 'no core factory found: stub' : "customised {$factory['factory']}: stub";
                    }
                    break;

                default:
                    $action['content'] = Emit::stub($facet['shape'] ?? 'module', $lang, $source, $code);
                    $action['review'] = true;
                    $action['notes'][] = 'stub: port by hand';
            }
        } catch (\Throwable $e) {
            return [...$action, 'action' => 'error', 'notes' => [$e->getMessage()]];
        }

        return $action;
    }

    /**
     * A file that evaluates to plain data (no env reads, nothing untranslatable), else null.
     *
     * @return array{source: string, form: string, value: mixed, warnings: list<string>}|null
     */
    private static function asData(string $root, string $source): ?array
    {
        try {
            $doc = EvaluateConfig::evaluate($root, $source);
        } catch (\Throwable) {
            return null;
        }
        if ($doc['form'] !== 'value' || $doc['warnings'] !== [] || Ir::countTodos($doc['value']) > 0 || Ir::usesEnv($doc['value'])) {
            return null;
        }

        return $doc;
    }

    /**
     * @param array<string, mixed> $project the Project::inspect() result
     * @param 'node'|'php' $target
     * @return list<Action>
     */
    private static function manifestActions(string $root, array $project, string $target, bool $force): array
    {
        $manifests = Spec::manifests();
        $spec = $manifests[$target];
        $version = Project::bestVersion([$project['node']['upstreamVersion'], $project['php']['upstreamVersion']]);
        $actions = [];


        if ($target === 'php') {
            if (!is_file($root . '/composer.json') || $force) {
                $actions[] = $version === null
                    ? self::manifestAction($target, 'composer.json', 'manifest', 'error', null, ['cannot tell the Strapi version: package.json has no @strapi/strapi dependency'])
                    : self::manifestAction($target, 'composer.json', 'manifest', 'write', self::composerJson($project, $version));
            }
            foreach (['bin/strapi', 'public/index.php'] as $file) {
                if (!is_file($root . '/' . $file) || $force) {
                    $actions[] = self::manifestAction($target, $file, 'template', 'write', Templates::read('php', $file));
                }
            }
        }

        // package.json: dependencies and scripts, merged
        $packageJson = $project['packageJson'] ?? [];
        $packageJson = is_array($packageJson) ? $packageJson : [];
        $added = [];
        $merged = self::mergePackageJson($packageJson, $spec, $target, $version, (string) ($project['env']['databaseClient'] ?? 'sqlite'), (string) $project['name'], $added);
        if ($version === null) {
            $added[] = 'Strapi version unknown: @strapi/* dependencies not added';
        }
        if ($project['packageJson'] === null) {
            $actions[] = self::manifestAction($target, 'package.json', 'manifest', 'write', Files::encodeJson($merged), $added);
        } elseif ($added !== [] && $merged !== $packageJson) {
            $actions[] = self::manifestAction($target, 'package.json', 'manifest', 'merge', Files::encodeJson($merged, self::indentOf($root . '/package.json')), $added);
        } else {
            $actions[] = self::manifestAction($target, 'package.json', 'manifest', 'skip', null, ['up to date', ...$added]);
        }

        // .gitignore: lines appended
        $gitignore = is_file($root . '/.gitignore') ? Files::read($root . '/.gitignore') : '';
        $lines = array_map(trim(...), preg_split('/\r?\n/', $gitignore) ?: []);
        $missing = array_values(array_filter($spec['gitignore'], static fn (string $line): bool => !in_array($line, $lines, true) && !in_array('/' . $line, $lines, true)));
        if ($missing !== []) {
            $content = rtrim($gitignore) . ($gitignore === '' ? '' : "\n") . implode("\n", $missing) . "\n";
            $actions[] = self::manifestAction($target, '.gitignore', 'manifest', $gitignore === '' ? 'write' : 'merge', $content, $missing);
        }

        return $actions;
    }

    /**
     * @param 'node'|'php' $target
     * @param 'write'|'merge'|'skip'|'error' $action
     * @param list<string> $notes
     * @return Action
     */
    private static function manifestAction(string $target, string $path, string $generator, string $action, ?string $content, array $notes = []): array
    {
        return ['path' => $path, 'target' => $target, 'generator' => $generator, 'source' => null, 'action' => $action, 'content' => $content, 'review' => false, 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $packageJson
     * @param array<string, mixed> $spec the runtime's manifest spec
     * @param list<string> $added filled with what changed
     * @return array<string, mixed>
     */
    public static function mergePackageJson(array $packageJson, array $spec, string $target, ?string $version, string $databaseClient, string $name, array &$added): array
    {
        $out = $packageJson;
        if ($packageJson === []) {
            $out = ['name' => self::kebab($name), 'version' => '0.1.0', 'private' => true];
        }
        $deps = is_array($out['dependencies'] ?? null) ? $out['dependencies'] : [];
        $all = Project::dependencies($out);

        $wanted = $spec['dependencies'];
        if ($target === 'node') {
            $driver = $spec['databaseDrivers'][$databaseClient] ?? null;
            if ($driver !== null) {
                $wanted[$driver] = $spec['databaseDriverVersions'][$driver] ?? '*';
            }
        }
        foreach ($wanted as $dep => $constraint) {
            if (isset($all[$dep])) {
                continue;
            }
            if ($constraint === '<version>') {
                if ($version === null) {
                    continue;
                }
                $constraint = $version;
            }
            $deps[$dep] = $constraint;
            $added[] = "dependency {$dep}@{$constraint}";
        }
        if ($deps !== []) {
            ksort($deps, SORT_STRING);
            $out['dependencies'] = $deps;
        }

        $scripts = is_array($out['scripts'] ?? null) ? $out['scripts'] : [];
        foreach ($spec['scripts'] as $key => $command) {
            if (!isset($scripts[$key])) {
                $scripts[$key] = $command;
                $added[] = "script {$key}";
            } elseif ($scripts[$key] !== $command && !isset($scripts["{$key}:{$target}"])) {
                $scripts["{$key}:{$target}"] = $command;
                $added[] = "script {$key}:{$target}";
            }
        }
        $out['scripts'] = $scripts;

        return $out;
    }

    /**
     * The composer.json create-strapi-app would write for this project.
     *
     * @param array<string, mixed> $project
     */
    public static function composerJson(array $project, string $version): string
    {
        $client = (string) ($project['env']['databaseClient'] ?? 'sqlite');
        $ext = ['sqlite' => 'ext-pdo_sqlite', 'postgres' => 'ext-pdo_pgsql', 'mysql' => 'ext-pdo_mysql', 'mariadb' => 'ext-pdo_mysql'][$client] ?? null;
        $preRelease = preg_match('/-(alpha|beta|rc)/i', $version) === 1;
        $constraint = $preRelease ? $version : (preg_match('/^(\d+)\.(\d+)/', $version, $m) === 1 ? "^{$m[1]}.{$m[2]}" : $version);

        $require = ['php' => '>=8.3'];
        if ($ext !== null) {
            $require[$ext] = '*';
        }
        $require['hynding/strapi-php'] = $constraint;

        $composer = [
            'description' => 'A Strapi application',
            'type' => 'project',
            'require' => $require,
            'scripts' => Files::readJson(Templates::dir() . '/php/composer.json')['scripts'] ?? [],
            'config' => ['sort-packages' => true, 'process-timeout' => 0, 'allow-plugins' => false],
        ];
        $uuid = $project['packageJson']['strapi']['uuid'] ?? null;
        if (is_string($uuid)) {
            $composer['extra'] = ['strapi' => ['uuid' => $uuid]];
        }
        if ($preRelease) {
            $composer['minimum-stability'] = 'beta';
            $composer['prefer-stable'] = true;
        }

        return Files::encodeJson($composer, 4);
    }

    public static function kebab(string $name): string
    {
        $name = (string) preg_replace('#^@?[^/]+/#', '', $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'strapi-app';
    }

    /** The indentation a JSON file uses (2 or 4 spaces). */
    public static function indentOf(string $file): int
    {
        $content = is_file($file) ? Files::read($file) : '';

        return preg_match('/^\n?\{\n( +)/', $content, $m) === 1 ? strlen($m[1]) : 2;
    }

    /**
     * @param array{targets: list<string>, actions: list<Action>} $plan
     * @return list<array{path: string, action: string, review: bool, notes: list<string>}>
     */
    public static function apply(string $root, array $plan, bool $dryRun = false): array
    {
        $results = [];
        foreach ($plan['actions'] as $action) {
            $result = ['path' => $action['path'], 'action' => $action['action'], 'review' => $action['review'], 'notes' => $action['notes']];
            if (($action['action'] === 'write' || $action['action'] === 'merge') && $action['content'] !== null) {
                if ($dryRun) {
                    $result['action'] = 'would-' . $action['action'];
                } else {
                    Files::write($root . '/' . $action['path'], $action['content']);
                    if ($action['path'] === 'bin/strapi') {
                        chmod($root . '/' . $action['path'], 0o755);
                    }
                    $result['action'] = $action['action'] === 'write' ? 'written' : 'merged';
                }
            }
            $results[] = $result;
        }

        return $results;
    }
}
