<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Code;

use Strapi\Upgrade\Modules\Report\Types as ReportTypes;
use Strapi\Upgrade\Modules\Runner\Json\Transform as JsonTransform;

/**
 * PHP-only: the code runner's engine, standing where upstream calls jscodeshift's
 * `Runner.run(transformFile, paths, options)`. Same per-file outcome rules as jscodeshift's
 * worker: no output → `skip`, unchanged output → `nochange`, new output → `ok` (written unless
 * `dry`), an exception → `error`; only files with one of `extensions` are processed.
 *
 * @phpstan-import-type CodeRunnerConfiguration from Types
 * @phpstan-import-type ReportData from ReportTypes
 */
final class Transform
{
    /**
     * @param list<string> $paths
     * @param CodeRunnerConfiguration $config
     * @return ReportData
     */
    public static function transformCode(string $codemodPath, array $paths, array $config): array
    {
        $dry = $config['dry'] ?? false;
        $extensions = array_filter(explode(',', $config['extensions'] ?? 'php'));
        $startTime = hrtime(true);

        $report = ReportTypes::empty();

        $transform = JsonTransform::load($codemodPath);

        if ($transform !== null && !is_callable($transform)) {
            throw new \UnexpectedValueException('Transform must be a function or null. Found ' . get_debug_type($transform));
        }

        $api = new TransformAPI($config['cwd'] ?? (string) getcwd());

        foreach ($paths as $path) {
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
                continue;
            }

            if ($transform === null) {
                ++$report['skip'];
                continue;
            }

            try {
                $source = (string) file_get_contents($path);
                $out = $transform(['path' => $path, 'source' => $source], $api, $config);

                if ($out === null || $out === '' || $out === $source) {
                    ++$report[$out === null || $out === '' ? 'skip' : 'nochange'];
                    continue;
                }

                if (!$dry) {
                    file_put_contents($path, $out);
                }
                ++$report['ok'];
            } catch (\Throwable $e) {
                if (!($config['silent'] ?? true)) {
                    fwrite(\STDERR, "{$path}: {$e->getMessage()}\n");
                }
                ++$report['error'];
            }
        }

        $report['stats'] = $api->stats;
        $report['timeElapsed'] = number_format((hrtime(true) - $startTime) / 1e9, 3, '.', '');

        return $report;
    }
}
