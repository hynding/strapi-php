<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/registries/custom-fields.ts.
 *
 * @phpstan-type CustomField array{name: string, type: string, plugin?: string, inputSize?: array{default: int, isResizable: bool}}
 */
final class CustomFields
{
    private const ALLOWED_TYPES = [
        'biginteger', 'boolean', 'date', 'datetime', 'decimal', 'email', 'enumeration', 'float',
        'integer', 'json', 'password', 'richtext', 'string', 'text', 'time', 'uid',
    ];

    /** @var array<string, CustomField> */
    private array $customFields = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, CustomField> */
    public function getAll(): array
    {
        return $this->customFields;
    }

    /** @return CustomField */
    public function get(string $customField): array
    {
        if (isset($this->customFields[$customField])) {
            return $this->customFields[$customField];
        }

        // PHP-port fallback: a schema may reference `plugin::<plugin>.<name>` while the field was
        // registered without that plugin being loaded (it then lives under `global::<name>`).
        if (preg_match('/^plugin::[a-z0-9-_]+\.(.+)$/i', $customField, $m) === 1 && isset($this->customFields["global::{$m[1]}"])) {
            return $this->customFields["global::{$m[1]}"];
        }

        throw new \RuntimeException("Could not find Custom Field: {$customField}");
    }

    /**
     * Register one custom field (`{ name, type, plugin?, inputSize? }`) or a list of them.
     *
     * @param array<array-key, mixed> $customField a CustomField or a list<CustomField>
     */
    public function add(array $customField): void
    {
        $customFieldList = array_is_list($customField) ? $customField : [$customField];

        foreach ($customFieldList as $cf) {
            if (!is_array($cf) || !array_key_exists('name', $cf) || !array_key_exists('type', $cf)) {
                throw new \RuntimeException("Custom fields require a 'name' and 'type' key");
            }

            $name = $cf['name'];
            $plugin = $cf['plugin'] ?? null;
            $type = $cf['type'];
            $inputSize = $cf['inputSize'] ?? null;

            if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
                $label = is_scalar($type) ? (string) $type : get_debug_type($type);

                throw new \RuntimeException("Custom field type: '{$label}' is not a valid Strapi type or it can't be used with a Custom Field");
            }

            if (!is_string($name) || preg_match('/^(?![0-9])[a-zA-Z0-9$_-]+$/', $name) !== 1) {
                $label = is_scalar($name) ? (string) $name : get_debug_type($name);

                throw new \RuntimeException("Custom field name: '{$label}' is not a valid object key");
            }

            // Validate inputSize when provided
            if ($inputSize) {
                if (!is_array($inputSize) || !array_key_exists('default', $inputSize) || !array_key_exists('isResizable', $inputSize)) {
                    throw new \RuntimeException("inputSize should be an object with 'default' and 'isResizable' keys");
                }
                if (!in_array($inputSize['default'], [4, 6, 8, 12], true)) {
                    throw new \RuntimeException('Custom fields require a valid default input size');
                }
                if (!is_bool($inputSize['isResizable'])) {
                    throw new \RuntimeException('Custom fields should specify if their input is resizable');
                }
            }

            // When no plugin is specified, or it isn't found in Strapi, default to global
            $plugin = is_scalar($plugin) && (bool) $plugin ? (string) $plugin : null;
            $uid = $plugin !== null && $this->strapi->hasPlugin($plugin) ? "plugin::{$plugin}.{$name}" : "global::{$name}";

            if (array_key_exists($uid, $this->customFields)) {
                throw new \RuntimeException("Custom field: '{$uid}' has already been registered");
            }

            $field = ['name' => $name, 'type' => $type];
            if ($plugin !== null) {
                $field['plugin'] = $plugin;
            }
            if (is_array($inputSize)) {
                $field['inputSize'] = ['default' => (int) $inputSize['default'], 'isResizable' => (bool) $inputSize['isResizable']];
            }

            $this->customFields[$uid] = $field;
        }
    }
}
