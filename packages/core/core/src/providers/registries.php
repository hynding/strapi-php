<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Loaders\Loaders;
use Strapi\Core\Migrations\Migrations as SyncMigrations;
use Strapi\Core\Registries as R;
use Strapi\Core\Strapi;
use Strapi\Utils\Hooks;

/** Port of packages/core/core/src/providers/registries.ts. */
final class Registries extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi
            ->add('content-types', static fn (): R\ContentTypes => new R\ContentTypes())
            ->add('components', static fn (): R\Components => new R\Components())
            ->add('services', static fn (): R\Services => new R\Services($strapi))
            ->add('policies', static fn (): R\Policies => new R\Policies())
            ->add('middlewares', static fn (): R\Middlewares => new R\Middlewares())
            ->add('hooks', static fn (): R\Hooks => new R\Hooks())
            ->add('controllers', static fn (): R\Controllers => new R\Controllers($strapi))
            ->add('modules', static fn (): R\Modules => new R\Modules($strapi))
            ->add('plugins', static fn (): R\Plugins => new R\Plugins($strapi))
            ->add('custom-fields', static fn (): R\CustomFields => new R\CustomFields($strapi))
            ->add('apis', static fn (): R\Apis => new R\Apis($strapi))
            ->add('models', static fn (): R\Models => new R\Models())
            ->add('sanitizers', new R\Sanitizers())
            ->add('validators', new R\Validators());
    }

    public function register(Strapi $strapi): void
    {
        Loaders::loadApplicationContext($strapi);

        $strapi->get('hooks')->set('strapi::content-types.beforeSync', Hooks::createAsyncParallelHook());
        $strapi->get('hooks')->set('strapi::content-types.afterSync', Hooks::createAsyncParallelHook());

        // Content migration to enable draft and publish
        $strapi->hook('strapi::content-types.beforeSync')->register(static fn (mixed $ctx) => SyncMigrations::disable($strapi, self::syncInput($ctx)));
        $strapi->hook('strapi::content-types.afterSync')->register(static fn (mixed $ctx) => SyncMigrations::enable($strapi, self::syncInput($ctx)));

        // Database migrations: the v5 discard-drafts migration is registered as a no-op by the
        // database package's InternalMigrations (see strapi/database README)
    }

    /**
     * The `{ oldContentTypes, contentTypes }` context `Strapi::bootstrap()` hands to the sync hooks.
     *
     * @return array{oldContentTypes: array<string, mixed>|null, contentTypes: array<string, \Strapi\Types\Schema\Schema>}
     */
    private static function syncInput(mixed $ctx): array
    {
        $oldContentTypes = is_array($ctx) ? ($ctx['oldContentTypes'] ?? null) : null;
        $contentTypes = is_array($ctx) ? ($ctx['contentTypes'] ?? []) : [];

        return [
            'oldContentTypes' => is_array($oldContentTypes) ? $oldContentTypes : null,
            'contentTypes' => is_array($contentTypes) ? $contentTypes : [],
        ];
    }
}
