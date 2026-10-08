<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;

/**
 * Port of src/strapi/transfer-policy.ts: what a remote push may and may not write (admin types
 * are protected).
 *
 * @phpstan-import-type RestoreOptions from \Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore\Restore
 */
final class TransferPolicy
{
    private const string ADMIN_UID_PREFIX = 'admin::';

    public const array OFFICIAL_TRANSFER_IGNORED_TYPES = [
        'plugin::content-releases.release',
        'plugin::content-releases.release-action',
    ];

    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    public static function isProtectedRemotePushType(string $uid): bool
    {
        return str_starts_with($uid, self::ADMIN_UID_PREFIX);
    }

    public static function isIgnoredOfficialTransferType(string $uid): bool
    {
        return self::isProtectedRemotePushType($uid) || in_array($uid, self::OFFICIAL_TRANSFER_IGNORED_TYPES, true);
    }

    /** @return list<string> */
    public static function getIgnoredOfficialTransferTypes(): array
    {
        return self::OFFICIAL_TRANSFER_IGNORED_TYPES;
    }

    /** @return list<string> */
    public static function getProtectedTransferUIDs(Strapi $strapi): array
    {
        $contentTypes = array_keys($strapi->contentTypes());
        $models = array_map(static fn (array $model): string => (string) ($model['uid'] ?? ''), $strapi->get('models')->get());

        return array_values(array_unique(array_filter(
            [...array_map('strval', $contentTypes), ...$models],
            self::isProtectedRemotePushType(...)
        )));
    }

    /**
     * @param RestoreOptions|array<string, mixed> $restore
     *
     * @return RestoreOptions|array<string, mixed>
     */
    public static function normalizeRemoteRestoreOptions(Strapi $strapi, array $restore = []): array
    {
        $protectedUIDs = self::getProtectedTransferUIDs($strapi);
        $entities = is_array($restore['entities'] ?? null) ? $restore['entities'] : [];

        unset($entities['filters']);

        $normalized = $entities;
        if (isset($entities['include']) && is_array($entities['include'])) {
            $normalized['include'] = array_values(array_filter($entities['include'], static fn (mixed $uid): bool => !is_string($uid) || !self::isProtectedRemotePushType($uid)));
        }
        $normalized['exclude'] = array_values(array_unique([...(is_array($entities['exclude'] ?? null) ? $entities['exclude'] : []), ...$protectedUIDs]));

        return [
            ...$restore,
            'entities' => $normalized,
        ];
    }

    private static function protectedTypeError(string $uid, string $path): ProviderTransferError
    {
        return new ProviderTransferError("Remote push cannot transfer protected type \"{$uid}\" at {$path}");
    }

    private static function invalidPayloadError(string $path, string $reason): ProviderTransferError
    {
        return new ProviderTransferError("Remote push received invalid relation payload at {$path}: {$reason}");
    }

    /** @return array<string, mixed> */
    private static function getSchema(Strapi $strapi, string $uid, string $path): array
    {
        $schema = $strapi->getModel($uid);

        if ($schema === null) {
            throw self::invalidPayloadError($path, "unknown schema \"{$uid}\"");
        }

        return ['attributes' => $schema->attributes];
    }

    /** @return array<string, mixed> */
    private static function getDatabaseSchema(Strapi $strapi, string $uid, string $path): array
    {
        if (!$strapi->db()->metadata->has($uid)) {
            throw self::invalidPayloadError($path, "unknown database schema \"{$uid}\"");
        }

        return $strapi->db()->metadata->get($uid);
    }

    private static function assertPolymorphicValueAllowed(mixed $value, string $typeField, string $path): void
    {
        $values = is_array($value) && array_is_list($value) ? $value : [$value];

        foreach ($values as $entry) {
            if (!self::isRecord($entry) || !is_string($entry[$typeField] ?? null)) {
                throw self::invalidPayloadError($path, "missing polymorphic discriminator \"{$typeField}\"");
            }

            $target = $entry[$typeField];
            if (self::isProtectedRemotePushType($target)) {
                throw self::protectedTypeError($target, $path);
            }
        }
    }

    private static function assertComponentValueAllowed(Strapi $strapi, string $componentUID, mixed $value, string $path, bool $repeatable): void
    {
        if (!$repeatable && $value === null) {
            return;
        }

        if ($repeatable && !(is_array($value) && array_is_list($value))) {
            throw self::invalidPayloadError($path, 'expected component value');
        }

        $values = $repeatable ? $value : [$value];

        foreach ($values as $entry) {
            if (!self::isRecord($entry)) {
                throw self::invalidPayloadError($path, 'expected component object');
            }
            self::assertSchemaDataAllowed($strapi, $componentUID, $entry, $path);
        }
    }

    /** @param list<string> $componentUIDs */
    private static function assertDynamicZoneValueAllowed(Strapi $strapi, array $componentUIDs, mixed $value, string $path): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalidPayloadError($path, 'expected dynamic zone array');
        }

        foreach ($value as $entry) {
            if (!self::isRecord($entry) || !is_string($entry['__component'] ?? null)) {
                throw self::invalidPayloadError($path, 'missing dynamic zone component discriminator');
            }

            $componentUID = $entry['__component'];
            if (!in_array($componentUID, $componentUIDs, true)) {
                throw self::invalidPayloadError($path, "unsupported dynamic zone component \"{$componentUID}\"");
            }

            self::assertSchemaDataAllowed($strapi, $componentUID, $entry, $path);
        }
    }

    /** @param array<string, mixed> $data */
    private static function assertSchemaDataAllowed(Strapi $strapi, string $uid, array $data, string $path): void
    {
        $schema = self::getSchema($strapi, $uid, $path);
        $databaseSchema = self::getDatabaseSchema($strapi, $uid, $path);

        foreach ($data as $field => $value) {
            $field = (string) $field;
            $attribute = $schema['attributes'][$field] ?? null;
            if (!is_array($attribute)) {
                $protectedOwnerRelation = null;
                foreach ($databaseSchema['attributes'] ?? [] as $candidate) {
                    if (
                        is_array($candidate)
                        && ($candidate['type'] ?? null) === 'relation'
                        && ($candidate['owner'] ?? null) === true
                        && is_string($candidate['target'] ?? null)
                        && self::isProtectedRemotePushType($candidate['target'])
                        && ($candidate['joinColumn']['name'] ?? null) === $field
                    ) {
                        $protectedOwnerRelation = $candidate;
                        break;
                    }
                }

                if ($protectedOwnerRelation !== null) {
                    throw self::protectedTypeError((string) $protectedOwnerRelation['target'], "{$path}.{$field}");
                }
                continue;
            }

            $fieldPath = "{$path}.{$field}";
            $type = $attribute['type'] ?? null;
            if ($type === 'relation') {
                if (is_string($attribute['target'] ?? null) && self::isProtectedRemotePushType($attribute['target'])) {
                    throw self::protectedTypeError($attribute['target'], $fieldPath);
                }
                if (str_starts_with((string) ($attribute['relation'] ?? ''), 'morph')) {
                    self::assertPolymorphicValueAllowed($value, (string) ($attribute['morphColumn']['typeField'] ?? '__type'), $fieldPath);
                }
            } elseif ($type === 'component' && is_string($attribute['component'] ?? null)) {
                self::assertComponentValueAllowed($strapi, $attribute['component'], $value, $fieldPath, ($attribute['repeatable'] ?? false) === true);
            } elseif ($type === 'dynamiczone') {
                self::assertDynamicZoneValueAllowed($strapi, array_values(array_map('strval', $attribute['components'] ?? [])), $value, $fieldPath);
            }
        }
    }

    /** @param array<string, mixed> $entity */
    public static function assertRemoteEntityAllowed(Strapi $strapi, array $entity): void
    {
        $type = (string) ($entity['type'] ?? '');
        if (self::isProtectedRemotePushType($type)) {
            throw self::protectedTypeError($type, $type);
        }

        $data = $entity['data'] ?? null;
        if (!self::isRecord($data)) {
            throw self::invalidPayloadError($type, 'expected entity data object');
        }

        self::assertSchemaDataAllowed($strapi, $type, $data, $type);
    }

    /** @param array<string, mixed> $link */
    public static function assertRemoteLinkAllowed(array $link): void
    {
        $leftType = (string) ($link['left']['type'] ?? '');
        $rightType = (string) ($link['right']['type'] ?? '');

        if (self::isProtectedRemotePushType($leftType)) {
            throw self::protectedTypeError($leftType, 'link.left');
        }
        if (self::isProtectedRemotePushType($rightType)) {
            throw self::protectedTypeError($rightType, 'link.right');
        }
    }
}
