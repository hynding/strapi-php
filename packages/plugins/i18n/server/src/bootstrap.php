<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/bootstrap.ts. */
final class Bootstrap
{
    public static function registerModelsHooks(Strapi $strapi): void
    {
        $syncSuperAdminPermissionsWithLocales = static function () use ($strapi): void {
            Utils::permissions($strapi)->actions->syncSuperAdminPermissionsWithLocales();
        };

        $strapi->db()->lifecycles->subscribe([
            'models' => ['plugin::i18n.locale'],

            'afterCreate' => $syncSuperAdminPermissionsWithLocales,

            'afterDelete' => $syncSuperAdminPermissionsWithLocales,
        ]);

        $strapi->documentService()->use(static function (array $context, callable $next) use ($strapi): mixed {
            $schema = $context['contentType'] ?? null;

            if (!in_array($context['action'] ?? null, ['create', 'update', 'discardDraft', 'publish'], true)) {
                return $next($context);
            }

            $contentTypes = Utils::contentTypes($strapi);

            if (!$schema instanceof Schema || !$contentTypes->isLocalizedContentType($schema)) {
                return $next($context);
            }

            // Build a populate array for all non localized fields within the schema
            $attributesToPopulate = $contentTypes->getNestedPopulateOfNonLocalizedAttributes($schema->uid);

            // Get original data before the update to compare what actually changed
            $params = is_array($context['params'] ?? null) ? $context['params'] : [];
            $originalData = array_key_exists('documentId', $params) && !empty($params['documentId'])
                ? $strapi->db()->query($schema->uid)->findOne([
                    'where' => ['documentId' => $params['documentId']],
                    'populate' => $attributesToPopulate,
                ])
                : null;

            // Get the result of the document service action
            $result = $next($context);

            // We may not have received a result with everything populated that we need
            // Use the id and populate built from non localized fields to get the full
            // result
            // TODO: fix bug where an empty array can be returned
            if (is_array($result) && is_array($result['entries'] ?? null) && !empty($result['entries'][0]['id'])) {
                $resultID = $result['entries'][0]['id'];
            } elseif (is_array($result) && !empty($result['id'])) {
                $resultID = $result['id'];
            } else {
                return $result;
            }

            $populatedResult = $strapi->db()->query($schema->uid)->findOne([
                'where' => ['id' => $resultID],
                'populate' => $attributesToPopulate,
            ]);

            $originalFields = $contentTypes->copyNonLocalizedAttributes($schema, $originalData);
            $currentFields = $contentTypes->copyNonLocalizedAttributes($schema, $populatedResult);

            // Only sync if there are actual changes to non-localized fields
            $shouldSync = $originalData === null;
            if (!$shouldSync) {
                foreach (array_keys($currentFields) as $key) {
                    if (!self::isEqual($currentFields[$key], $originalFields[$key] ?? null)) {
                        $shouldSync = true;
                        break;
                    }
                }
            }

            if ($shouldSync && $populatedResult !== null) {
                Utils::localizations($strapi)->syncNonLocalizedAttributes($populatedResult, $schema);
            }

            return $result;
        });
    }

    /** lodash `isEqual` on decoded rows (dates may come back as objects) */
    private static function isEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface || $b instanceof \DateTimeInterface) {
            $a = $a instanceof \DateTimeInterface ? $a->format('Y-m-d\TH:i:s.uP') : $a;
            $b = $b instanceof \DateTimeInterface ? $b->format('Y-m-d\TH:i:s.uP') : $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::isEqual($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    public function __invoke(Strapi $strapi): void
    {
        $metrics = Utils::metrics($strapi);
        $locales = Utils::locales($strapi);
        $permissions = Utils::permissions($strapi);

        // Data
        $locales->initDefaultLocale();

        // Sections Builder
        $permissions->sectionsBuilder->registerLocalesPropertyHandler();

        // Actions
        $permissions->actions->registerI18nActions();
        $permissions->actions->registerI18nActionsHooks();
        $permissions->actions->updateActionsProperties();

        // Engine/Permissions
        $permissions->engine->registerI18nPermissionsHandlers();

        // Hooks & Models
        self::registerModelsHooks($strapi);

        // Absent in CE, without the audit-logs license, or when disabled by config;
        // get() throws for services that were never added, so probe first.
        if ($strapi->has('audit-logs-lifecycle')) {
            $auditLogsLifecycle = $strapi->get('audit-logs-lifecycle');
            if (is_object($auditLogsLifecycle)) {
                AuditLogs::registerAuditEvents($auditLogsLifecycle);
            }
        }

        // AI Localizations
        if ($strapi->has('ai.admin') && Services\AiTranslations::aiAdminCall($strapi, 'isAvailable')) {
            $aiTranslations = Utils::aiTranslations($strapi);

            if (!$aiTranslations->hasProvider() && Services\AiTranslations::aiAdminCall($strapi, 'isStrapiManagedAiEnabled')) {
                $aiTranslations->registerStrapiManagedProvider();
            }

            Utils::aiLocalizations($strapi)->setupMiddleware();
        }

        $metrics->sendDidInitializeEvent();
    }
}
