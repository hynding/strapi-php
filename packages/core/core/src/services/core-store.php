<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Database\Database;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Modules\CoreStore\CoreStore as CoreStoreContract;

/**
 * Port of packages/core/core/src/services/core-store.ts: key/value settings in
 * `strapi_core_store_settings`. `$store->scoped($defaults)` is upstream's callable form
 * `strapi.store({ type: 'plugin', name: 'upload' })`.
 *
 * @phpstan-type Params array{key?: string, value?: mixed, type?: string, environment?: string|null, name?: string|null, tag?: string|null}
 */
final class CoreStore implements CoreStoreContract
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public static function coreStoreModel(): array
    {
        return SchemaFactory::coreStoreModel();
    }

    public static function createCoreStore(Database $db): self
    {
        return new self($db);
    }

    /** @param Params $defaultParams */
    public function scoped(array $defaultParams): ScopedCoreStore
    {
        return new ScopedCoreStore($this, $defaultParams);
    }

    /** @param Params $defaultParams */
    public function __invoke(array $defaultParams): ScopedCoreStore
    {
        return $this->scoped($defaultParams);
    }

    /**
     * @param Params $params
     * @return array{key: string, environment: string|null, tag: string|null}
     */
    private static function where(array $params): array
    {
        $type = $params['type'] ?? 'core';
        $name = $params['name'] ?? null;
        $prefix = $type . ($name ? "_{$name}" : '');

        return [
            'key' => "{$prefix}_" . ($params['key'] ?? ''),
            'environment' => ($params['environment'] ?? null) ?: null,
            'tag' => ($params['tag'] ?? null) ?: null,
        ];
    }

    public function get(array $params): mixed
    {
        $where = self::where($params);

        $data = $this->db->query('strapi::core-store')->findOne(['where' => $where]);

        if ($data === null) {
            return null;
        }

        if (in_array($data['type'], ['object', 'array', 'boolean', 'string'], true)) {
            try {
                return json_decode((string) $data['value'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $date = strtotime((string) $data['value']);

                return $date === false ? null : (new \DateTimeImmutable('@' . $date))->format('Y-m-d\TH:i:s.v\Z');
            }
        }

        if ($data['type'] === 'number') {
            return (float) $data['value'] == (int) $data['value'] ? (int) $data['value'] : (float) $data['value'];
        }

        return null;
    }

    public function set(array $params): void
    {
        $where = self::where($params);
        $value = $params['value'] ?? null;

        $data = $this->db->query('strapi::core-store')->findOne(['where' => $where]);

        $payload = ['value' => self::stringify($value), 'type' => self::typeOf($value)];

        if ($data !== null) {
            $this->db->query('strapi::core-store')->update(['where' => ['id' => $data['id']], 'data' => $payload]);

            return;
        }

        $this->db->query('strapi::core-store')->create(['data' => [...$where, ...$payload]]);
    }

    public function delete(array $params): void
    {
        $this->db->query('strapi::core-store')->delete(['where' => self::where($params)]);
    }

    /** `JSON.stringify(value) || toString(value)` */
    private static function stringify(mixed $value): string
    {
        if ($value instanceof \JsonSerializable || is_array($value) || is_scalar($value) || $value === null) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json === false ? '' : $json;
        }
        if ($value instanceof \DateTimeInterface) {
            return json_encode($value->format('Y-m-d\TH:i:s.v\Z')) ?: '';
        }
        if (is_object($value)) {
            $json = json_encode(get_object_vars($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json === false ? '' : $json;
        }

        return '';
    }

    /** JS `typeof value` */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            // typeof null / typeof [] / typeof {} are all 'object' in JS
            $value === null, is_array($value), is_object($value) => 'object',
            default => 'undefined',
        };
    }
}
