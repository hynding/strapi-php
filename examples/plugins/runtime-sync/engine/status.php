<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/status.js: the project report (`strapi-runtime-sync status`, and the `project`
 * part of the admin status route). Manifests and files only; the database part comes from the
 * server half, which has a booted Strapi.
 *
 * @phpstan-type Problem array{level: 'error'|'warning'|'info', code: string, message: string}
 */
final class Status
{
    /** @return array<string, mixed> */
    public static function report(string $root): array
    {
        $root = Files::realRoot($root);
        $project = Project::inspect($root);
        $scan = Scan::run($root);
        $manifests = Spec::manifests();
        $problems = [];

        $node = $project['node'];
        $php = $project['php'];

        if (!$node['present'] && !$php['present']) {
            $problems[] = self::problem('error', 'no-runtime', 'Neither runtime is set up here: no config/*.js|ts or src/index.js|ts for Node, no config/*.php or src/index.php for strapi-php.');
        }

        $lockstep = null;
        if ($node['upstreamVersion'] !== null && $php['upstreamVersion'] !== null) {
            $lockstep = Project::versionsAgree($node['upstreamVersion'], $php['upstreamVersion']);
            if (!$lockstep) {
                $problems[] = self::problem('error', 'versions-differ', "package.json runs Strapi {$node['upstreamVersion']} but composer.json runs strapi-php {$php['version']} (Strapi {$php['upstreamVersion']}): the two must mirror the same release to share a database (VERSIONING.md).");
            }
        }

        $counts = ['paired' => 0, 'missingNode' => 0, 'missingPhp' => 0, 'shared' => $scan['shared']['count']];
        foreach ($scan['paired'] as $pair) {
            $counts[match ($pair['state']) {
                'paired' => 'paired', 'missing-node' => 'missingNode', default => 'missingPhp'
            }]++;
        }
        if ($counts['missingNode'] > 0) {
            $problems[] = self::problem($node['present'] ? 'warning' : 'info', 'node-files-missing', "{$counts['missingNode']} file(s) have no Node twin: `strapi-runtime-sync generate --to node`.");
        }
        if ($counts['missingPhp'] > 0) {
            $problems[] = self::problem($php['present'] ? 'warning' : 'info', 'php-files-missing', "{$counts['missingPhp']} file(s) have no strapi-php twin: `strapi-runtime-sync generate --to php`.");
        }

        // manifests
        $deps = Project::dependencies($project['packageJson']);
        $nodeMissingDeps = [];
        foreach ($manifests['node']['dependencies'] as $dep => $constraint) {
            if (!isset($deps[$dep])) {
                $nodeMissingDeps[] = $dep;
            }
        }
        $driver = $manifests['node']['databaseDrivers'][$project['env']['databaseClient']] ?? null;
        if ($driver !== null && !isset($deps[$driver])) {
            $nodeMissingDeps[] = $driver;
        }
        $phpMissingFiles = array_values(array_filter($manifests['php']['files'], static fn (string $f): bool => !is_file($root . '/' . $f)));
        $phpMissingDeps = [];
        foreach ($manifests['php']['dependencies'] as $dep => $constraint) {
            if (!isset($deps[$dep])) {
                $phpMissingDeps[] = $dep;
            }
        }
        if ($node['present'] && $nodeMissingDeps !== []) {
            $problems[] = self::problem('warning', 'node-manifest', 'package.json lacks ' . implode(', ', $nodeMissingDeps) . ' (`generate --to node` adds them).');
        }
        if ($php['present'] && ($phpMissingFiles !== [] || $phpMissingDeps !== [])) {
            $problems[] = self::problem('warning', 'php-manifest', 'strapi-php needs ' . implode(', ', [...$phpMissingFiles, ...$phpMissingDeps]) . ' (`generate --to php` adds them).');
        }

        // environment
        $missingKeys = [];
        if (!$project['env']['present']) {
            $problems[] = self::problem('warning', 'env-missing', 'No .env: both runtimes read the same .env (copy .env.example and set the secrets).');
        } else {
            $missingKeys = array_values(array_diff($manifests['env'], $project['env']['keys']));
            if ($missingKeys !== []) {
                $problems[] = self::problem('warning', 'env-keys-missing', '.env lacks ' . implode(', ', $missingKeys) . ': tokens and sessions issued by one runtime are only valid on the other with the same secrets.');
            }
        }

        // plugins
        $plugins = self::plugins($root, $project);
        $ignored = $manifests['pluginsNotCompared'] ?? [];
        $nodeOnly = array_values(array_diff($plugins['node'], $plugins['php'], $ignored));
        $phpOnly = array_values(array_diff($plugins['php'], $plugins['node'], $ignored));
        if ($node['present'] && $php['present'] && ($nodeOnly !== [] || $phpOnly !== [])) {
            $parts = [];
            if ($nodeOnly !== []) {
                $parts[] = 'Node only: ' . implode(', ', $nodeOnly);
            }
            if ($phpOnly !== []) {
                $parts[] = 'strapi-php only: ' . implode(', ', $phpOnly);
            }
            $problems[] = self::problem('warning', 'plugins-differ', 'Plugins differ (' . implode('; ', $parts) . '). A plugin with content types must be enabled on both runtimes, or each boot alters the shared database: install it on the other side or disable it in config/plugins, then `db:check`.');
        }

        if ($scan['unsupported'] !== []) {
            $problems[] = self::problem('warning', 'unsupported', 'Not generated, port by hand: ' . implode(', ', $scan['unsupported']) . ' (a dual plugin looks like examples/plugins/announcements).');
        }

        if (!$project['adminBuild']['present']) {
            $problems[] = self::problem('info', 'admin-build', 'No admin build: run `strapi build` on either runtime; both serve build/ (dist/build for a TypeScript project).');
        }

        return [
            'root' => $root,
            'name' => $project['name'],
            'runtimes' => ['node' => $node, 'php' => $php],
            'versions' => ['lockstep' => $lockstep, 'upstream' => $node['upstreamVersion'] ?? $php['upstreamVersion']],
            'counts' => $counts,
            'files' => $scan['paired'],
            'shared' => $scan['shared'],
            'unsupported' => $scan['unsupported'],
            'manifests' => [
                'node' => ['missingDependencies' => $nodeMissingDeps, 'missingFiles' => array_values(array_filter($manifests['node']['files'], static fn (string $f): bool => !is_file($root . '/' . $f)))],
                'php' => ['missingDependencies' => $phpMissingDeps, 'missingFiles' => $phpMissingFiles],
            ],
            'env' => ['present' => $project['env']['present'], 'missingKeys' => $missingKeys, 'databaseClient' => $project['env']['databaseClient']],
            'adminBuild' => $project['adminBuild'],
            'plugins' => [...$plugins, 'nodeOnly' => $nodeOnly, 'phpOnly' => $phpOnly],
            'problems' => $problems,
        ];
    }

    /**
     * The plugins each runtime would enable, from the manifests (best effort: the boot markers
     * the server half records are the truth).
     *
     * @param array<string, mixed> $project
     * @return array{node: list<string>, php: list<string>}
     */
    public static function plugins(string $root, array $project): array
    {
        $node = [];
        foreach (array_keys(Project::dependencies($project['packageJson'])) as $dep) {
            $dep = (string) $dep;
            if (str_starts_with($dep, '@strapi/plugin-')) {
                $node[] = substr($dep, strlen('@strapi/plugin-'));
                continue;
            }
            $pkg = Files::readJson("{$root}/node_modules/{$dep}/package.json");
            if (is_array($pkg) && ($pkg['strapi']['kind'] ?? null) === 'plugin') {
                $node[] = (string) ($pkg['strapi']['name'] ?? $dep);
            }
        }

        $php = [];
        $require = is_array($project['composerJson']['require'] ?? null) ? $project['composerJson']['require'] : [];
        foreach (array_keys($require) as $dep) {
            $dep = (string) $dep;
            if (str_starts_with($dep, 'strapi/plugin-')) {
                $php[] = substr($dep, strlen('strapi/plugin-'));
            }
        }
        if (isset($require['hynding/strapi-php'])) {
            array_push($php, ...Spec::manifests()['php']['bundledPlugins']);
        }
        $installed = Files::readJson($root . '/vendor/composer/installed.json');
        $list = is_array($installed['packages'] ?? null) ? $installed['packages'] : ($installed ?? []);
        foreach ($list as $package) {
            if (is_array($package) && ($package['extra']['strapi']['kind'] ?? null) === 'plugin') {
                $php[] = (string) ($package['extra']['strapi']['name'] ?? basename((string) $package['name']));
            }
        }

        $node = array_values(array_unique($node));
        $php = array_values(array_unique($php));
        sort($node, SORT_STRING);
        sort($php, SORT_STRING);

        return ['node' => $node, 'php' => $php];
    }

    /**
     * @param 'error'|'warning'|'info' $level
     * @return Problem
     */
    public static function problem(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }

    /** @param array<string, mixed> $report */
    public static function hasErrors(array $report): bool
    {
        foreach ($report['problems'] ?? [] as $problem) {
            if (($problem['level'] ?? null) === 'error') {
                return true;
            }
        }

        return false;
    }
}
