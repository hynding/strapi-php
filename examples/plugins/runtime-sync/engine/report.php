<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/** Mirrors engine/report.js: the text the CLIs print. Same words on both runtimes. */
final class Report
{
    /** @param array<string, mixed> $report Status::report() */
    public static function status(array $report): string
    {
        $node = $report['runtimes']['node'];
        $php = $report['runtimes']['php'];
        $lines = ["runtime-sync status: {$report['name']}", ''];
        $lines[] = '  node  ' . ($node['present'] ? 'present   Strapi ' . ($node['version'] ?? '?') . " ({$node['language']})" . ($node['installed'] ? '' : ', node_modules not installed') : 'absent');
        $lines[] = '  php   ' . ($php['present'] ? 'present   strapi-php ' . ($php['version'] ?? '?') . ' (Strapi ' . ($php['upstreamVersion'] ?? '?') . ')' . ($php['installed'] ? '' : ', vendor not installed') : 'absent');
        $lines[] = '  versions: ' . ($report['versions']['lockstep'] === null ? 'only one runtime declared' : ($report['versions']['lockstep'] ? "in lockstep ({$report['versions']['upstream']})" : 'DIFFER'));
        $lines[] = '';

        $c = $report['counts'];
        $lines[] = "files: {$c['paired']} paired, {$c['missingNode']} missing on node, {$c['missingPhp']} missing on php, {$c['shared']} shared";
        foreach ($report['files'] as $file) {
            if ($file['state'] === 'paired') {
                continue;
            }
            $have = $file['state'] === 'missing-node' ? $file['php'] : $file['node'];
            $lines[] = sprintf('  %-12s %s  (%s)', $file['state'], $have, $file['facet']);
        }
        if ($report['unsupported'] !== []) {
            $lines[] = '  unsupported  ' . implode(', ', $report['unsupported']);
        }
        $lines[] = '';

        $lines[] = 'plugins: node [' . implode(', ', $report['plugins']['node']) . '], php [' . implode(', ', $report['plugins']['php']) . ']';
        $lines[] = 'database: ' . $report['env']['databaseClient'] . ' (.env)' . ($report['adminBuild']['present'] ? ", admin build in {$report['adminBuild']['dir']}/" : ', no admin build');
        $lines[] = '';

        if ($report['problems'] === []) {
            $lines[] = 'ok: nothing to do';
        } else {
            foreach ($report['problems'] as $problem) {
                $lines[] = self::badge($problem['level']) . ' ' . $problem['message'];
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{path: string, action: string, review: bool, notes: list<string>}> $results */
    public static function generate(array $results, string $root): string
    {
        $lines = ["runtime-sync generate: {$root}", ''];
        $review = [];
        foreach ($results as $result) {
            $lines[] = sprintf('  %-12s %s%s', $result['action'], $result['path'], $result['notes'] === [] ? '' : '  (' . implode('; ', $result['notes']) . ')');
            if ($result['review']) {
                $review[] = $result['path'];
            }
        }
        $lines[] = '';
        if ($review !== []) {
            $lines[] = self::badge('warning') . ' review before running: ' . implode(', ', $review);
        }
        $written = count(array_filter($results, static fn (array $r): bool => in_array($r['action'], ['written', 'merged'], true)));
        $lines[] = $written > 0 ? "{$written} file(s) written. Install the runtime's dependencies next (composer install / npm install), then `strapi-runtime-sync db:check`." : 'nothing written';

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $db the preflight report of the server half */
    public static function database(array $db): string
    {
        $r = $db['runtime'];
        $d = $db['database'];
        $lines = ["runtime-sync db:check on {$r['kind']} (Strapi {$r['version']}, {$r['engine']})", ''];
        $lines[] = "  client:        {$d['client']}";
        $lines[] = "  schema hash:   {$d['schemaHash']}";
        $lines[] = '  stored hash:   ' . ($d['storedHash'] ?? '(none: first boot creates the schema)') . (isset($d['storedBy']) ? " (stored by {$d['storedBy']['runtime']} {$d['storedBy']['version']} at {$d['storedBy']['time']})" : '');
        $lines[] = "  status:        {$d['status']}";
        foreach (['added', 'updated', 'removed'] as $change) {
            if (($d['tables'][$change] ?? []) !== []) {
                $lines[] = sprintf('  tables %-8s%s', $change . ':', implode(', ', $d['tables'][$change]));
            }
        }
        $lines[] = '  migrations:    ' . (($d['migrations']['pending'] ?? []) === [] ? 'none pending' : 'pending ' . implode(', ', $d['migrations']['pending']))
            . (($d['migrations']['twinsToLog'] ?? []) === [] ? '' : '; already ran on the other runtime, will be logged: ' . implode(', ', $d['migrations']['twinsToLog']));
        $lines[] = '';
        foreach ($db['boots'] ?? [] as $kind => $boot) {
            $lines[] = sprintf('  last boot %-5s %s %s, schema %s, %d plugin(s)', $kind, $boot['version'], $boot['time'], substr((string) $boot['schemaHash'], 0, 12), count($boot['plugins'] ?? []));
        }
        $lines[] = '';
        $lines[] = $d['status'] === 'UNCHANGED'
            ? 'ok: booting this runtime changes nothing in the database'
            : ($d['status'] === 'NEW' ? self::badge('info') . ' the database has no schema yet: the first boot creates it' : self::badge('warning') . ' booting this runtime would alter the database: check the tables above are expected (a plugin or content type missing on this side?)');

        return implode("\n", $lines) . "\n";
    }

    /** @param array{target: string, checks: list<array{name: string, ok: bool, blocking: bool, detail: string}>, ready: bool, commands: array{develop: string, start: string}} $result */
    public static function handoff(array $result): string
    {
        $lines = ["runtime-sync handoff to {$result['target']}", ''];
        foreach ($result['checks'] as $check) {
            $mark = $check['ok'] ? 'ok  ' : ($check['blocking'] ? 'FAIL' : 'warn');
            $lines[] = sprintf('  %s  %-32s %s', $mark, $check['name'], $check['detail']);
        }
        $lines[] = '';
        $lines[] = $result['ready']
            ? "ready: run `{$result['commands']['develop']}` (development) or `{$result['commands']['start']}` (production); the other runtime can keep running on the same database until you switch traffic"
            : 'not ready: fix the FAIL lines above and run handoff again';

        return implode("\n", $lines) . "\n";
    }

    public static function badge(string $level): string
    {
        return match ($level) {
            'error' => 'ERROR',
            'warning' => 'WARN ',
            default => 'info ',
        };
    }
}
