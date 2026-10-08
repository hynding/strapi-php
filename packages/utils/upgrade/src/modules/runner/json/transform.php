<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Json;

use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Modules\Json\JSONTransformAPI;
use Strapi\Upgrade\Modules\Report\Types as ReportTypes;

/**
 * Port of packages/utils/upgrade/src/modules/runner/json/transform.ts (`transformJSON`). The
 * codemod file is `require`d and must return a callable, like upstream's default export.
 *
 * @phpstan-import-type JSONRunnerConfiguration from Types
 * @phpstan-import-type ReportData from ReportTypes
 */
final class Transform
{
    /**
     * @param list<string> $paths
     * @param JSONRunnerConfiguration $config
     * @return ReportData
     */
    public static function transformJSON(string $codemodPath, array $paths, array $config): array
    {
        $dry = $config['dry'] ?? false;
        $startTime = hrtime(true);

        $report = ReportTypes::empty();

        $codemod = self::load($codemodPath);

        if (!is_callable($codemod)) {
            throw new \UnexpectedValueException('Codemod must be a function. Found ' . get_debug_type($codemod));
        }

        foreach ($paths as $path) {
            try {
                $json = File::readJSON($path);

                // Make sure the JSON value is a JSON object
                if ($json instanceof \stdClass) {
                    $json = [];
                } elseif (!is_array($json) || ($json !== [] && array_is_list($json))) {
                    throw new \UnexpectedValueException("{$path} is not a JSON object");
                }

                /** @var array<string, mixed> $json */
                $file = ['path' => $path, 'json' => $json];
                $params = ['cwd' => $config['cwd'], 'json' => JSONTransformAPI::createJSONTransformAPI(...)];

                $out = $codemod($file, $params);

                if ($out === null) {
                    ++$report['error'];
                }
                // If the json object has modifications
                elseif (!JSONTransformAPI::isEqual($json, $out)) {
                    if (!$dry) {
                        File::saveJSON($path, $out);
                    }
                    ++$report['ok'];
                }
                // No changes
                else {
                    ++$report['nochange'];
                }
            } catch (\Throwable) {
                ++$report['error'];
            }
        }

        $report['timeElapsed'] = number_format((hrtime(true) - $startTime) / 1e9, 3, '.', '');

        return $report;
    }

    /** `require(codemodPath)` in an isolated scope */
    public static function load(string $codemodPath): mixed
    {
        if (!is_file($codemodPath)) {
            throw new \RuntimeException("Cannot find module '{$codemodPath}'");
        }

        return (static fn (string $__file): mixed => require $__file)($codemodPath);
    }
}
