<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Domain\Locale;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Plugin\I18n\Validation\Locales as LocalesValidation;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\SetCreatorFields;

/** Port of server/src/controllers/locales.ts. */
final class Locales
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function sanitizeLocale(mixed $locale): mixed
    {
        $model = $this->strapi->getModel('plugin::i18n.locale');

        return $this->strapi->contentAPI()->sanitize()->output($locale, $model);
    }

    /**
     * `ctx.state.user` (an authenticated admin: `setCreatorFields` reads its `id`)
     *
     * @return array{id: int|string}|object
     */
    private static function creator(mixed $user): array|object
    {
        if (is_object($user)) {
            return $user;
        }
        $id = is_array($user) ? ($user['id'] ?? null) : null;

        if (!is_int($id) && !is_string($id)) {
            throw new \TypeError("Cannot read properties of undefined (reading 'id')");
        }

        return ['id' => $id];
    }

    /** @return array<string, mixed>|list<array<string, mixed>>|null */
    private static function asLocales(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    public function listLocales(Context $ctx): void
    {
        $localesService = Utils::locales($this->strapi);

        $locales = $localesService->find();
        $sanitizedLocales = $this->sanitizeLocale($locales);

        $ctx->setBody($localesService->setIsDefault(self::asLocales($sanitizedLocales)));
    }

    public function createLocale(Context $ctx): void
    {
        $user = $ctx->state()->get('user');
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $isDefault = $body['isDefault'] ?? null;
        $localeToCreate = array_diff_key($body, ['isDefault' => true]);

        LocalesValidation::validateCreateLocaleInput($body);

        $localesService = Utils::locales($this->strapi);

        $existingLocale = $localesService->findByCode($body['code'] ?? null);
        if ($existingLocale !== null) {
            throw new ApplicationError('This locale already exists');
        }

        $localeToPersist = SetCreatorFields::create(['user' => self::creator($user)])(Locale::formatLocale($localeToCreate));

        $locale = $localesService->create($localeToPersist, ['isDefault' => (bool) $isDefault]);

        if ($isDefault) {
            $localesService->setDefaultLocale($locale);
        }

        $sanitizedLocale = $this->sanitizeLocale($locale);

        $ctx->setBody($localesService->setIsDefault(self::asLocales($sanitizedLocale)));
    }

    public function updateLocale(Context $ctx): void
    {
        $user = $ctx->state()->get('user');
        $id = $ctx->params()['id'] ?? null;
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $isDefault = $body['isDefault'] ?? null;
        $updates = array_diff_key($body, ['isDefault' => true]);

        LocalesValidation::validateUpdateLocaleInput($body);

        $localesService = Utils::locales($this->strapi);

        $existingLocale = $localesService->findById($id);
        if ($existingLocale === null) {
            $ctx->notFound('locale.notFound');

            return;
        }

        $allowedParams = ['name'];
        $cleanUpdates = SetCreatorFields::create(['user' => self::creator($user), 'isEdition' => true])(
            array_intersect_key($updates, array_flip($allowedParams)),
        );

        $updatedLocale = $localesService->update(['id' => $id], $cleanUpdates);

        if ($isDefault) {
            $localesService->setDefaultLocale($updatedLocale ?? []);
        }

        $sanitizedLocale = $this->sanitizeLocale($updatedLocale);

        $ctx->setBody($localesService->setIsDefault(self::asLocales($sanitizedLocale)));
    }

    public function deleteLocale(Context $ctx): void
    {
        $id = $ctx->params()['id'] ?? null;

        $localesService = Utils::locales($this->strapi);

        $existingLocale = $localesService->findById($id);
        if ($existingLocale === null) {
            $ctx->notFound('locale.notFound');

            return;
        }

        $defaultLocaleCode = $localesService->getDefaultLocale();
        if (($existingLocale['code'] ?? null) === $defaultLocaleCode) {
            throw new ApplicationError('Cannot delete the default locale');
        }

        $localesService->delete(['id' => $id]);

        $sanitizedLocale = $this->sanitizeLocale($existingLocale);

        $ctx->setBody($localesService->setIsDefault(self::asLocales($sanitizedLocale)));
    }
}
