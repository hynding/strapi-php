<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Admin\Services\Constants as AdminConstants;
use Strapi\Admin\Services\Permission;
use Strapi\ContentManager\Services\DocumentMetadata;
use Strapi\ContentManager\Services\PermissionChecker;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Plugin\I18n\Validation\ContentTypes as ContentTypesValidation;
use Strapi\Types\Core\Context;
use Strapi\Utils\ContentTypes as ContentTypeUtils;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/controllers/content-types.ts. */
final class ContentTypes
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>
     */
    private static function pickLocaleFields(?array $entry): array
    {
        $out = [];
        foreach (['id', 'locale', ContentTypeUtils::PUBLISHED_AT_ATTRIBUTE] as $field) {
            if (is_array($entry) && array_key_exists($field, $entry)) {
                $out[$field] = $entry[$field];
            }
        }

        return $out;
    }

    private function permissionChecker(Ability $userAbility, string $model): PermissionChecker
    {
        $service = $this->strapi->plugin('content-manager')->service('permission-checker');
        assert($service instanceof PermissionChecker);

        return $service->create(['userAbility' => $userAbility, 'model' => $model]);
    }

    public function getNonLocalizedAttributes(Context $ctx): void
    {
        $user = $ctx->state()->get('user');
        $userAbility = $ctx->state()->get('userAbility');
        assert($userAbility instanceof Ability);
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $model = $body['model'] ?? null;
        $id = $body['id'] ?? null;
        $locale = $body['locale'] ?? null;

        // upstream validates `{ model, id, locale }` (undefined keys are absent)
        $input = [];
        foreach (['model' => $model, 'id' => $id, 'locale' => $locale] as $key => $value) {
            if (array_key_exists($key, $body)) {
                $input[$key] = $value;
            }
        }
        ContentTypesValidation::validateGetNonLocalizedAttributesInput($this->strapi, $input);
        $model = (string) $model;

        $permissionChecker = $this->permissionChecker($userAbility, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return;
        }

        $contentTypesService = Utils::contentTypes($this->strapi);

        $modelDef = $this->strapi->contentTypes()[$model] ?? null;
        $attributesToPopulate = $contentTypesService->getNestedPopulateOfNonLocalizedAttributes($model);

        if ($modelDef === null || !$contentTypesService->isLocalizedContentType($modelDef)) {
            throw new ApplicationError("Model {$model} is not localized");
        }

        $params = $modelDef->kind === 'singleType' ? [] : ['id' => $id];

        $entity = $this->strapi->db()->query($model)->findOne(['where' => $params, 'populate' => $attributesToPopulate]);

        if ($entity === null) {
            $ctx->notFound();

            return;
        }

        $permissionService = $this->strapi->service('admin::permission');
        assert($permissionService instanceof Permission);
        $roles = is_array($user) && is_array($user['roles'] ?? null) ? $user['roles'] : [];
        $permissions = $permissionService->findMany([
            'where' => [
                'action' => [AdminConstants::READ_ACTION, AdminConstants::CREATE_ACTION],
                'subject' => $model,
                'role' => [
                    'id' => array_values(array_map(static fn (mixed $role): mixed => is_array($role) ? ($role['id'] ?? null) : null, $roles)),
                ],
            ],
        ]);

        $localePermissions = [];
        foreach ($permissions as $perm) {
            $permLocales = $perm['properties']['locales'] ?? [];
            if (is_array($permLocales) && in_array($locale, $permLocales, true)) {
                $localePermissions[] = $perm['properties']['fields'] ?? null;
            }
        }

        // pipe(flatten, getFirstLevelPath, uniq)
        $permittedFields = [];
        foreach ($localePermissions as $fields) {
            foreach (is_array($fields) ? $fields : [$fields] as $path) {
                $first = explode('.', (string) $path)[0];
                if (!in_array($first, $permittedFields, true)) {
                    $permittedFields[] = $first;
                }
            }
        }

        $nonLocalizedFields = $contentTypesService->copyNonLocalizedAttributes($modelDef, $entity);
        $pickedFields = [];
        foreach ($permittedFields as $field) {
            if (array_key_exists($field, $nonLocalizedFields)) {
                $pickedFields[$field] = $nonLocalizedFields[$field];
            }
        }

        // Guard relations: omit fields that point to content types the user cannot read
        $sanitizedNonLocalizedFields = array_filter($pickedFields, static function (string $key) use ($modelDef, $userAbility): bool {
            $attribute = $modelDef->attributes[$key] ?? null;
            if (($attribute['type'] ?? null) === 'relation' && !empty($attribute['target'])) {
                return $userAbility->can(AdminConstants::READ_ACTION, (string) $attribute['target']);
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);

        $documentMetadata = $this->strapi->plugin('content-manager')->service('document-metadata');
        assert($documentMetadata instanceof DocumentMetadata);
        $availableLocalesResult = $documentMetadata->getMetadata($model, $entity, [
            'availableLocales' => true,
        ]);

        $availableLocales = array_map(
            static fn (array $localeResult): array => self::pickLocaleFields($localeResult),
            $availableLocalesResult['availableLocales'],
        );

        $ctx->setBody([
            'nonLocalizedFields' => $sanitizedNonLocalizedFields === [] ? new \stdClass() : $sanitizedNonLocalizedFields,
            'localizations' => [...$availableLocales, self::pickLocaleFields($entity)],
        ]);
    }

    public function getFillFromLocaleData(Context $ctx): void
    {
        $userAbility = $ctx->state()->get('userAbility');
        assert($userAbility instanceof Ability);
        $model = (string) ($ctx->params()['model'] ?? '');

        ContentTypesValidation::validateFillFromLocaleInput($ctx->query());

        $query = $ctx->query();
        $documentId = isset($query['documentId']) && is_string($query['documentId']) ? $query['documentId'] : null;
        $sourceLocale = (string) ($query['sourceLocale'] ?? '');
        $targetLocale = (string) ($query['targetLocale'] ?? '');

        $permissionChecker = $this->permissionChecker($userAbility, $model);

        if ($permissionChecker->cannot('read')) {
            $ctx->forbidden();

            return;
        }

        $fillFromLocaleService = Utils::fillFromLocale($this->strapi);

        $rawDocument = $fillFromLocaleService->fetchRawDocument($model, $sourceLocale, $documentId);

        if ($rawDocument === null) {
            $ctx->notFound();

            return;
        }

        // Field-level filtering: strip fields the user cannot read on this content type
        $sanitizedDocument = $permissionChecker->sanitizeOutput($rawDocument);

        // Transform relations to target locale, skipping those the user cannot read
        $data = $fillFromLocaleService->transformDocument(
            is_array($sanitizedDocument) ? $sanitizedDocument : [],
            $model,
            $targetLocale,
            $userAbility,
        );

        $ctx->setBody(['data' => $data === [] ? new \stdClass() : $data]);
    }
}
