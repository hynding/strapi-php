<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Utils\GetPluginsThatNeedDocumentation;
use Symfony\Component\Yaml\Yaml;

/**
 * Port of server/src/services/override.ts.
 *
 * `registeredOverrides` / `excludedFromGeneration` are the arrays upstream exposes (mutated in
 * place). A YAML override (`users-permissions`' `documentation/content-api.yaml`) is parsed with
 * symfony/yaml instead of the `yaml` npm package (1.x, YAML 1.2 core schema); mappings become
 * associative arrays, an empty mapping (`{}`) a `\stdClass`, and plain timestamps stay strings as
 * in YAML 1.2 (symfony/yaml would turn them into Unix timestamps).
 *
 * @phpstan-import-type PluginConfig from \Strapi\Plugin\Documentation\Types
 */
final class Override
{
    /** @var list<PluginConfig> */
    public array $registeredOverrides = [];

    /** @var list<string> */
    public array $excludedFromGeneration = [];

    /** @param Strapi $strapi */
    public function __construct(private readonly object $strapi)
    {
    }

    /** @param string|list<string> $api The name of the api or an array of apis to exclude from generation */
    public function excludeFromGeneration(string|array $api): void
    {
        if (is_array($api)) {
            array_push($this->excludedFromGeneration, ...array_values($api));

            return;
        }

        $this->excludedFromGeneration[] = $api;
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->excludedFromGeneration, true);
    }

    /**
     * @param PluginConfig|string $override an OpenAPI (partial) document, or its YAML source
     * @param array{pluginOrigin?: string, excludeFromGeneration?: list<string>}|null $opts
     */
    public function registerOverride(array|string $override, ?array $opts = null): void
    {
        $pluginOrigin = $opts['pluginOrigin'] ?? null;
        $excludeFromGeneration = $opts['excludeFromGeneration'] ?? [];

        $config = $this->strapi->config()->get('plugin::documentation');
        $pluginsThatNeedDocumentation = GetPluginsThatNeedDocumentation::getPluginsThatNeedDocumentation(is_array($config) ? $config : null);
        // Don't apply the override if the plugin is not in the list of plugins that need documentation
        if ($pluginOrigin !== null && $pluginOrigin !== '' && !in_array($pluginOrigin, $pluginsThatNeedDocumentation, true)) {
            return;
        }

        if (count($excludeFromGeneration) > 0) {
            $this->excludeFromGeneration($excludeFromGeneration);
        }

        $overrideToRegister = $override;
        // Parse yaml if we receive a string
        if (is_string($overrideToRegister)) {
            $overrideToRegister = self::parseYaml($overrideToRegister);
        }
        // receive an object we can register it directly
        $this->registeredOverrides[] = $overrideToRegister;
    }

    /** YAML 1.1 timestamps (symfony/yaml's `Inline::getTimestampRegex()`), plain scalars in block context. */
    private const TIMESTAMP_LINE = '/^(\s*(?:-\s+)*(?:[^\s#\'"][^:#]*:\s+)?(?:-\s+)*)([0-9]{4}-[0-9]{1,2}-[0-9]{1,2}(?:(?:[Tt]|[ \t]+)[0-9]{1,2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]*)?(?:[ \t]*(?:Z|[-+][0-9]{1,2}(?::[0-9]{2})?))?)?)([ \t]*(?:#.*)?)$/m';

    /** @return array<string, mixed> */
    private static function parseYaml(string $source): array
    {
        // YAML 1.2 (the `yaml` npm package) has no timestamp type: keep them as strings
        $source = (string) preg_replace(self::TIMESTAMP_LINE, "$1'$2'$3", $source);

        $parsed = self::toArrays(Yaml::parse($source, Yaml::PARSE_OBJECT_FOR_MAP));

        return is_array($parsed) ? $parsed : [];
    }

    /** `\stdClass` mappings (PARSE_OBJECT_FOR_MAP) to arrays, keeping empty ones as `\stdClass` (`{}`). */
    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return $value;
            }
            $value = $vars;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toArrays($item);
            }
        }

        return $value;
    }
}
