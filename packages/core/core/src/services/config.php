<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Psr\Log\LoggerInterface;
use Strapi\Types\Modules\Config\Config as ConfigContract;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/services/config.ts (`createConfigProvider`): dot-path access over
 * the merged configuration. `plugin.<name>` paths are rewritten to `plugin::<name>` with a
 * deprecation warning, as upstream does.
 */
final class Config implements ConfigContract
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var \Closure(): (LoggerInterface|null) */
    private \Closure $logger;

    /**
     * @param array<string, mixed> $initialConfig
     * @param callable(): (LoggerInterface|null)|null $logger resolved lazily: the logger may not exist yet
     */
    public function __construct(array $initialConfig = [], ?callable $logger = null)
    {
        $this->config = $initialConfig; // not deep clone because it would break some config
        $this->logger = $logger !== null ? $logger(...) : static fn (): ?LoggerInterface => null;
    }

    public static function createConfigProvider(array $initialConfig = [], ?callable $logger = null): self
    {
        return new self($initialConfig, $logger);
    }

    /**
     * Accessing model configs with dot (.) was deprecated between v4->v5, but to avoid a major breaking change
     * we will still support certain namespaces, currently only 'plugin.'
     */
    private function transformPathString(string $path): string
    {
        if (str_starts_with($path, 'plugin.')) {
            $newPath = 'plugin::' . substr($path, strlen('plugin.'));

            $message = "Using dot notation for model config namespaces is deprecated, for example \"plugin::myplugin\" should be used instead of \"plugin.myplugin\". Modifying requested path {$path} to {$newPath}";
            $log = ($this->logger)();
            if ($log !== null) {
                $log->warning($message);
            } else {
                fwrite(STDERR, $message . PHP_EOL);
            }

            return $newPath;
        }

        return $path;
    }

    /**
     * @param string|list<string|int> $path
     * @return string|list<string>
     */
    private function transformDeprecatedPaths(string|array $path): string|array
    {
        if (is_string($path)) {
            return $this->transformPathString($path);
        }

        foreach ($path as $part) {
            if (!is_string($part) && !is_int($part)) {
                /** @var list<string> $path */
                return array_map('strval', $path);
            }
        }

        return $this->transformPathString(implode('.', array_map('strval', $path)));
    }

    /**
     * `strapi.config.get('server.port', 1337)`. A namespaced key such as `plugin::upload` is one
     * segment: the path is split on dots only after the `::` namespace prefix.
     *
     * @param string|list<string|int> $path
     */
    public function get(string|array $path, mixed $default = null): mixed
    {
        $segments = self::segments($this->transformDeprecatedPaths($path));
        if ($segments === []) {
            return $default;
        }

        return Objects::get($this->config, $segments, $default);
    }

    /** @param string|list<string|int> $path */
    public function set(string|array $path, mixed $value): void
    {
        $segments = self::segments($this->transformDeprecatedPaths($path));
        if ($segments === []) {
            return;
        }

        $this->config = Objects::set($this->config, $segments, $value);
    }

    /** @param string|list<string|int> $path */
    public function has(string|array $path): bool
    {
        $segments = self::segments($this->transformDeprecatedPaths($path));

        return $segments !== [] && Objects::has($this->config, $segments);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Lodash splits `'a.b.c'` on dots; `plugin::upload.config.sizeLimit` must keep the namespaced
     * key `plugin::upload` whole (upstream stores plugin config under that exact key).
     *
     * @param string|list<string> $path
     * @return list<string>
     */
    private static function segments(string|array $path): array
    {
        if (is_array($path)) {
            return array_values(array_map('strval', $path));
        }
        if ($path === '') {
            return [];
        }

        if (preg_match('/^((?:api|plugin|admin|strapi|global)::[a-z0-9-_]+)(?:\.(.*))?$/i', $path, $m) === 1) {
            $rest = isset($m[2]) && $m[2] !== '' ? Objects::toPath($m[2]) : [];

            return [$m[1], ...$rest];
        }

        return Objects::toPath($path);
    }
}
