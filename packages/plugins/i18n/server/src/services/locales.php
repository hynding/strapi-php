<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Constants\Constants;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Utils\AuditLogs;

/**
 * Port of server/src/services/locales.ts.
 *
 * `delete` is a reserved word only as a function name in global scope: the method keeps upstream's
 * name. `setDefaultLocale()` returns null (core-store `set()` is void in this port).
 */
final class Locales
{
    private const string UID = 'plugin::i18n.locale';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function find(array $params = []): array
    {
        return $this->strapi->db()->query(self::UID)->findMany(['where' => $params]);
    }

    /** @return array<string, mixed>|null */
    public function findById(mixed $id): ?array
    {
        return $this->strapi->db()->query(self::UID)->findOne(['where' => ['id' => $id]]);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(mixed $code): ?array
    {
        return $this->strapi->db()->query(self::UID)->findOne(['where' => ['code' => $code]]);
    }

    /** @param array<string, mixed> $params */
    public function count(array $params = []): int
    {
        return $this->strapi->db()->query(self::UID)->count(['where' => $params]);
    }

    /**
     * @param array<string, mixed> $locale
     * @param array{isDefault?: bool} $options
     * @return array<string, mixed>
     */
    public function create(array $locale, array $options = []): array
    {
        $isDefault = $options['isDefault'] ?? false;

        $result = $this->strapi->db()->query(self::UID)->create(['data' => $locale]);

        Utils::metrics($this->strapi)->sendDidUpdateI18nLocalesEvent();

        if (($result['id'] ?? null) !== null) {
            AuditLogs::emitAudit($this->strapi, Constants::AUDITED_EVENTS['LOCALE_CREATE'], [
                'localeId' => $result['id'],
                'name' => $result['name'] ?? null,
                'code' => $result['code'] ?? null,
                'isDefault' => $isDefault,
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $updates
     * @return array<string, mixed>|null
     */
    public function update(array $params, array $updates): ?array
    {
        $previous = $this->strapi->db()->query(self::UID)->findOne(['where' => $params]);

        $result = $this->strapi->db()->query(self::UID)->update(['where' => $params, 'data' => $updates]);

        Utils::metrics($this->strapi)->sendDidUpdateI18nLocalesEvent();

        if ($result !== null && ($previous['name'] ?? null) !== ($result['name'] ?? null)) {
            AuditLogs::emitAudit($this->strapi, Constants::AUDITED_EVENTS['LOCALE_UPDATE'], [
                'localeId' => $result['id'] ?? null,
                'name' => $result['name'] ?? null,
                'code' => $result['code'] ?? null,
                'changes' => ['name' => ['before' => $previous['name'] ?? null, 'after' => $result['name'] ?? null]],
            ]);
        }

        return $result;
    }

    /**
     * @param array{id: mixed} $params
     * @return array<string, mixed>|null
     */
    public function delete(array $params): ?array
    {
        $id = $params['id'];
        $localeToDelete = $this->findById($id);

        if ($localeToDelete !== null) {
            $this->deleteAllLocalizedEntriesFor(['locale' => $localeToDelete['code'] ?? null]);
            $result = $this->strapi->db()->query(self::UID)->delete(['where' => ['id' => $id]]);

            Utils::metrics($this->strapi)->sendDidUpdateI18nLocalesEvent();

            AuditLogs::emitAudit($this->strapi, Constants::AUDITED_EVENTS['LOCALE_DELETE'], [
                'localeId' => $localeToDelete['id'] ?? null,
                'name' => $localeToDelete['name'] ?? null,
                'code' => $localeToDelete['code'] ?? null,
            ]);

            return is_array($result) ? $result : null;
        }

        return $localeToDelete;
    }

    /** @param array<string, mixed> $locale a locale (only `code` is read) */
    public function setDefaultLocale(array $locale): mixed
    {
        $code = $locale['code'] ?? null;
        $previousCode = $this->getDefaultLocale();
        $hasChanged = $previousCode !== $code;

        // Look up the rows before the write: if this fails afterwards, the default is already
        // switched and nothing recorded it.
        $previous = null;
        $next = null;
        if ($hasChanged) {
            $previous = $previousCode !== null && $previousCode !== '' ? $this->findByCode($previousCode) : null;
            $next = $this->findByCode($code);
        }

        Utils::getCoreStore($this->strapi)->set(['key' => 'default_locale', 'value' => $code]);

        if ($hasChanged && ($next['id'] ?? null) !== null) {
            $before = $previousCode !== null && $previousCode !== '' ? ['id' => $previous['id'] ?? null, 'code' => $previousCode] : null;

            AuditLogs::emitAudit($this->strapi, Constants::AUDITED_EVENTS['LOCALE_DEFAULT_UPDATE'], [
                'localeId' => $next['id'],
                'name' => $next['name'] ?? null,
                'code' => $next['code'] ?? null,
                'changes' => ['defaultLocale' => ['before' => $before, 'after' => ['id' => $next['id'], 'code' => $next['code'] ?? null]]],
            ]);
        }

        return null;
    }

    public function getDefaultLocale(): ?string
    {
        $value = Utils::getCoreStore($this->strapi)->get(['key' => 'default_locale']);

        return is_string($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed>|null $locales
     * @return list<array<string, mixed>>|array<string, mixed>|null
     */
    public function setIsDefault(?array $locales): ?array
    {
        if ($locales === null) {
            return $locales;
        }

        $actualDefault = $this->getDefaultLocale();

        if (array_is_list($locales)) {
            return array_map(static fn (mixed $locale): array => [...(array) $locale, 'isDefault' => $actualDefault === ((array) $locale)['code']], $locales);
        }

        // single locale
        return [...$locales, 'isDefault' => $actualDefault === ($locales['code'] ?? null)];
    }

    public function initDefaultLocale(): void
    {
        $existingLocalesNb = $this->strapi->db()->query(self::UID)->count();
        if ($existingLocalesNb === 0) {
            $defaultLocale = Constants::DEFAULT_LOCALE();
            $this->create($defaultLocale);
            $this->setDefaultLocale(['code' => $defaultLocale['code']]);
        }
    }

    /** @param array{locale: mixed} $params */
    private function deleteAllLocalizedEntriesFor(array $params): void
    {
        $contentTypes = Utils::contentTypes($this->strapi);

        $localizedModels = array_filter($this->strapi->contentTypes(), $contentTypes->isLocalizedContentType(...));

        foreach ($localizedModels as $model) {
            // FIXME: delete many content & their associations
            $this->strapi->db()->query($model->uid)->deleteMany(['where' => ['locale' => $params['locale']]]);
        }
    }
}
