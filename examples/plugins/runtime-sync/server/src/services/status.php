<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Services;

use Strapi\Core\Strapi;
use StrapiPlugin\RuntimeSync\Engine\Status as ProjectStatus;

/**
 * Mirrors services/status.js: the admin status document. The runtime answering, what this boot
 * found in the database (Preflight), both runtimes' last boots, whether they agree, and the
 * project's file pairs (engine Status).
 */
final readonly class Status
{
    public function __construct(private Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $preflight = $this->strapi->plugin('runtime-sync')->service('preflight');
        \assert($preflight instanceof Preflight);

        $boots = $preflight->boots();
        $database = $preflight->database();
        if ($database !== null) {
            $database['storedBy'] = self::storedBy($database['storedHash'], $boots);
        }

        return [
            'runtime' => $preflight->runtime(),
            'database' => $database,
            'boots' => $boots,
            'agreement' => self::agreement($boots),
            'project' => ProjectStatus::report($this->strapi->dirs()->root),
        ];
    }

    /**
     * The boot that stored the current schema hash, if a recorded one did.
     *
     * @param array<string, array<string, mixed>> $boots
     * @return array{runtime: string, version: string, time: string}|null
     */
    public static function storedBy(?string $storedHash, array $boots): ?array
    {
        $match = null;
        foreach ($boots as $boot) {
            if ($storedHash !== null && ($boot['schemaHash'] ?? null) === $storedHash && ($match === null || strcmp((string) $boot['time'], (string) $match['time']) > 0)) {
                $match = $boot;
            }
        }

        return $match === null ? null : ['runtime' => (string) $match['runtime'], 'version' => (string) $match['version'], 'time' => (string) $match['time']];
    }

    /**
     * Do the two runtimes' last boots agree? null until both have booted.
     *
     * @param array<string, array<string, mixed>> $boots
     * @return array{sameVersion: ?bool, sameSchema: ?bool, samePlugins: ?bool, pluginsNodeOnly: list<string>, pluginsPhpOnly: list<string>}
     */
    public static function agreement(array $boots): array
    {
        $node = $boots['node'] ?? null;
        $php = $boots['php'] ?? null;
        if ($node === null || $php === null) {
            return ['sameVersion' => null, 'sameSchema' => null, 'samePlugins' => null, 'pluginsNodeOnly' => [], 'pluginsPhpOnly' => []];
        }
        $nodePlugins = array_map(strval(...), (array) ($node['plugins'] ?? []));
        $phpPlugins = array_map(strval(...), (array) ($php['plugins'] ?? []));

        return [
            'sameVersion' => ($node['version'] ?? null) === ($php['version'] ?? null),
            'sameSchema' => ($node['schemaHash'] ?? null) === ($php['schemaHash'] ?? null),
            'samePlugins' => $nodePlugins === $phpPlugins,
            'pluginsNodeOnly' => array_values(array_diff($nodePlugins, $phpPlugins)),
            'pluginsPhpOnly' => array_values(array_diff($phpPlugins, $nodePlugins)),
        ];
    }
}
