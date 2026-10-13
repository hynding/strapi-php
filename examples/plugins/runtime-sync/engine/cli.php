<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\Logger;
use Strapi\Cli\Strapi as CliStrapi;
use StrapiPlugin\RuntimeSync\PreflightAbort;
use Symfony\Component\Process\Process;

/**
 * Mirrors engine/cli.js: `strapi-runtime-sync`, the same commands and words from both runtimes.
 * Everything but `db:check` is file work the engine does; `db:check` boots this runtime's Strapi
 * up to the schema sync, where the plugin's preflight aborts it with the report.
 */
final class Cli
{
    public const USAGE = <<<'TXT'
        strapi-runtime-sync: one Strapi project, two runtimes (Strapi on Node, strapi-php)

        usage: strapi-runtime-sync <command> [options]

          status                 the project's runtimes, file pairs, manifests and problems
          generate               write the files the other runtime lacks
                                   --to node|php|both   (default: the runtime that is missing)
                                   --ts                 write TypeScript on the Node side
                                   --force              overwrite generated files that exist
                                   --dry-run            show the plan, write nothing
          db:check               boot up to the schema sync and report what a boot would change
                                   --runtime node|php   (default: this CLI's runtime; the other
                                                         runtime's CLI is run for the other)
          handoff --to node|php  go / no-go for running the project on that runtime

        options: --root <dir> (default: the current directory), --json, --help

        exit codes: 0 fine, 1 something to fix, 2 usage
        TXT;

    /** The runtime this CLI belongs to. */
    public const RUNTIME = 'php';

    /** @var resource */
    private $out;

    /** @param resource|null $out */
    public function __construct($out = null)
    {
        $this->out = $out ?? STDOUT;
    }

    /**
     * @param list<string> $argv without the program name
     */
    public static function run(array $argv, string $cwd, mixed $out = null): int
    {
        return (new self($out))->dispatch($argv, $cwd);
    }

    /** @param list<string> $argv */
    public function dispatch(array $argv, string $cwd): int
    {
        ['command' => $command, 'options' => $options] = self::parse($argv);
        $root = Files::realRoot((string) ($options['root'] ?? $cwd));

        try {
            return match ($command) {
                'status' => $this->status($root, $options),
                'generate' => $this->generate($root, $options),
                'db:check' => $this->dbCheck($root, $options),
                'handoff' => $this->handoff($root, $options),
                'help', null => $this->write(self::USAGE . "\n") ?? ($command === null && $argv !== [] ? 2 : 0),
                default => $this->write("unknown command: {$command}\n\n" . self::USAGE . "\n") ?? 2,
            };
        } catch (\Throwable $e) {
            $this->write(($options['json'] ?? false) ? json_encode(['error' => $e->getMessage()]) . "\n" : "ERROR {$e->getMessage()}\n");

            return 1;
        }
    }

    /**
     * `--key value`, `--key=value`, `--flag`; the first bare word is the command.
     *
     * @param list<string> $argv
     * @return array{command: ?string, options: array<string, string|bool>}
     */
    public static function parse(array $argv): array
    {
        $command = null;
        $options = [];
        $valued = ['to', 'root', 'runtime'];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                $value = true;
                if (str_contains($name, '=')) {
                    [$name, $value] = explode('=', $name, 2);
                } elseif (in_array($name, $valued, true) && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                    $value = $argv[++$i];
                }
                $options[$name] = $value;
            } elseif ($arg === '-h') {
                $options['help'] = true;
            } elseif ($command === null) {
                $command = $arg;
            }
        }
        if ($options['help'] ?? false) {
            $command = 'help';
        }

        return ['command' => $command, 'options' => $options];
    }

    /** @param array<string, string|bool> $options */
    private function status(string $root, array $options): int
    {
        $report = Status::report($root);
        $this->write(($options['json'] ?? false) ? self::json($report) : Report::status($report));

        return Status::hasErrors($report) ? 1 : 0;
    }

    /** @param array<string, string|bool> $options */
    private function generate(string $root, array $options): int
    {
        $to = is_string($options['to'] ?? null) ? $options['to'] : null;
        if ($to !== null && !in_array($to, ['node', 'php', 'both'], true)) {
            $this->write("--to must be node, php or both\n");

            return 2;
        }
        $plan = Generate::plan($root, ['to' => $to, 'language' => ($options['ts'] ?? false) ? 'ts' : null, 'force' => (bool) ($options['force'] ?? false)]);
        $results = Generate::apply($root, $plan, (bool) ($options['dry-run'] ?? false));
        $this->write(($options['json'] ?? false) ? self::json(['targets' => $plan['targets'], 'results' => $results]) : Report::generate($results, $root));

        return array_filter($results, static fn (array $r): bool => $r['action'] === 'error') === [] ? 0 : 1;
    }

    /** @param array<string, string|bool> $options */
    private function dbCheck(string $root, array $options): int
    {
        $runtime = is_string($options['runtime'] ?? null) ? $options['runtime'] : self::RUNTIME;
        if (!in_array($runtime, ['node', 'php'], true)) {
            $this->write("--runtime must be node or php\n");

            return 2;
        }
        $report = $runtime === self::RUNTIME ? self::preflight($root, (bool) ($options['verbose'] ?? false)) : self::preflightWithOtherCli($root, $runtime);
        $this->write(($options['json'] ?? false) ? self::json($report) : Report::database($report));

        return $report['database']['status'] === 'CHANGED' ? 1 : 0;
    }

    /** @param array<string, string|bool> $options */
    private function handoff(string $root, array $options): int
    {
        $to = is_string($options['to'] ?? null) ? $options['to'] : null;
        if (!in_array($to, ['node', 'php'], true)) {
            $this->write("handoff needs --to node or --to php\n");

            return 2;
        }
        $result = self::handoffChecks($root, $to, static fn (string $runtime): array => $runtime === self::RUNTIME ? self::preflight($root, false) : self::preflightWithOtherCli($root, $runtime));
        $this->write(($options['json'] ?? false) ? self::json($result) : Report::handoff($result));

        return $result['ready'] ? 0 : 1;
    }

    /**
     * The go / no-go list for running the project on `$to`. Shared by both CLIs.
     *
     * @param callable(string): array<string, mixed> $dbCheck runs the preflight on a runtime
     * @return array{target: string, checks: list<array{name: string, ok: bool, blocking: bool, detail: string}>, ready: bool, commands: array{develop: string, start: string}}
     */
    public static function handoffChecks(string $root, string $to, callable $dbCheck): array
    {
        $report = Status::report($root);
        $runtime = $report['runtimes'][$to];
        $missing = $report['counts'][$to === 'node' ? 'missingNode' : 'missingPhp'];
        $manifest = $report['manifests'][$to];
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail = '', bool $blocking = true) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'blocking' => $blocking, 'detail' => $detail];
        };

        $check("{$to} runtime set up", $runtime['present'], $runtime['present'] ? 'Strapi ' . ($runtime['upstreamVersion'] ?? '?') : "run `strapi-runtime-sync generate --to {$to}`");
        $check("no files missing on {$to}", $missing === 0, $missing === 0 ? '' : "{$missing} file(s): run `strapi-runtime-sync generate --to {$to}`");
        $check('manifests complete', $manifest['missingFiles'] === [] && $manifest['missingDependencies'] === [], implode(', ', [...$manifest['missingFiles'], ...$manifest['missingDependencies']]));
        $check('versions in lockstep', $report['versions']['lockstep'] !== false, $report['versions']['lockstep'] === null ? 'only one runtime declared' : '');
        $check('.env complete', $report['env']['present'] && $report['env']['missingKeys'] === [], $report['env']['present'] ? implode(', ', $report['env']['missingKeys']) : 'no .env');
        $check('dependencies installed', $runtime['installed'], $runtime['installed'] ? '' : ($to === 'php' ? 'run `composer install`' : 'run `npm install`'));
        $pluginParts = [];
        if ($report['plugins']['nodeOnly'] !== []) {
            $pluginParts[] = 'node only: ' . implode(', ', $report['plugins']['nodeOnly']);
        }
        if ($report['plugins']['phpOnly'] !== []) {
            $pluginParts[] = 'php only: ' . implode(', ', $report['plugins']['phpOnly']);
        }
        $check('plugins match', $pluginParts === [], implode('; ', $pluginParts), false);
        $check('admin build present', $report['adminBuild']['present'], $report['adminBuild']['present'] ? $report['adminBuild']['dir'] . '/' : 'run `strapi build` on either runtime', false);

        if ($runtime['present'] && $runtime['installed']) {
            try {
                $db = $dbCheck($to);
                $status = $db['database']['status'];
                $check('database: a boot alters nothing', $status !== 'CHANGED', $status === 'NEW' ? 'no schema yet: the first boot creates it' : ($status === 'CHANGED' ? 'tables ' . json_encode($db['database']['tables']) : 'schema ' . substr((string) $db['database']['schemaHash'], 0, 12) . ' unchanged'));
            } catch (\Throwable $e) {
                $check('database: a boot alters nothing', false, $e->getMessage());
            }
        } else {
            $check('database: a boot alters nothing', false, 'skipped: the runtime is not set up or installed');
        }

        $ready = array_filter($checks, static fn (array $c): bool => $c['blocking'] && !$c['ok']) === [];

        return [
            'target' => $to,
            'checks' => $checks,
            'ready' => $ready,
            'commands' => $to === 'php'
                ? ['develop' => 'php bin/strapi develop', 'start' => 'php bin/strapi start']
                : ['develop' => 'npx strapi develop', 'start' => 'npx strapi start'],
        ];
    }

    /**
     * Boots this project's strapi-php up to the schema sync; the plugin aborts it with the report.
     *
     * @return array<string, mixed>
     */
    public static function preflight(string $root, bool $verbose): array
    {
        putenv('STRAPI_RUNTIME_SYNC_PREFLIGHT=1');
        $_ENV['STRAPI_RUNTIME_SYNC_PREFLIGHT'] = '1';
        putenv('STRAPI_NO_EXIT=1');

        $strapi = CliStrapi::createStrapi([...CliStrapi::compileStrapi(['appDir' => $root]), 'serveAdminPanel' => false]);
        $logger = $strapi->log();
        if (!$verbose && $logger instanceof Logger) {
            foreach ($logger->getHandlers() as $handler) {
                if ($handler instanceof AbstractHandler) {
                    $handler->setLevel(Level::Error);
                }
            }
        }

        try {
            $strapi->load();
        } catch (PreflightAbort $e) {
            try {
                $strapi->db()->destroy();
            } catch (\Throwable) {
                // the connection is all that was opened
            }

            return $e->report;
        }

        $strapi->destroy();

        throw new \RuntimeException('the runtime-sync plugin is not enabled in this project: `composer require strapi-plugin/runtime-sync` and enable it in config/plugins.php');
    }

    /**
     * The other runtime's CLI, for `db:check --runtime <other>`: its report is the last JSON line it prints.
     *
     * @return array<string, mixed>
     */
    public static function preflightWithOtherCli(string $root, string $runtime): array
    {
        $command = self::otherCliCommand($root, $runtime);
        if ($command === null) {
            throw new \RuntimeException("the {$runtime} CLI is not installed here (" . ($runtime === 'php' ? 'vendor/bin/strapi-runtime-sync' : 'node_modules/.bin/strapi-runtime-sync') . ')');
        }
        $process = new Process([...$command, 'db:check', '--json', '--root', $root], $root, null, null, 600);
        $process->run();
        $decoded = self::lastJsonObject($process->getOutput());
        if (!is_array($decoded) || !isset($decoded['database'])) {
            throw new \RuntimeException("the {$runtime} CLI gave no report: " . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $decoded;
    }

    /**
     * The last JSON object printed at column 0 (the report follows whatever the boot logged).
     *
     * @return array<string, mixed>|null
     */
    public static function lastJsonObject(string $output): ?array
    {
        $output = "\n" . trim($output);
        $start = strrpos($output, "\n{");
        if ($start === false) {
            return null;
        }
        $decoded = json_decode(substr($output, $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string>|null */
    public static function otherCliCommand(string $root, string $runtime): ?array
    {
        if ($runtime === 'node') {
            foreach (["{$root}/node_modules/strapi-plugin-runtime-sync/bin/strapi-runtime-sync.js", dirname(__DIR__) . '/bin/strapi-runtime-sync.js'] as $script) {
                if (is_file($script)) {
                    return [getenv('RUNTIME_SYNC_NODE') ?: 'node', $script];
                }
            }

            return null;
        }
        foreach (["{$root}/vendor/strapi-plugin/runtime-sync/bin/strapi-runtime-sync", dirname(__DIR__) . '/bin/strapi-runtime-sync'] as $script) {
            if (is_file($script)) {
                return [getenv('RUNTIME_SYNC_PHP') ?: 'php', $script];
            }
        }

        return null;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    private function write(string $text): mixed
    {
        fwrite($this->out, $text);

        return null;
    }
}
