<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/controllers/validate-locale-creation.ts: a Koa middleware handler
 * `(ctx, next)`. `$model` is the `:model` segment of the matched path (upstream: `ctx.params`).
 *
 * TODO: v5 if implemented in the CM => delete this middleware
 */
final class ValidateLocaleCreation
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function __invoke(Context $ctx, callable $next, ?string $model = null): mixed
    {
        $model ??= (string) ($ctx->params()['model'] ?? '');
        $query = $ctx->query();

        // Prevent empty body (the body is written back: keep its JSON `{}` markers, see EmptyObject)
        $body = $ctx->requestBody(true);
        if ($body === null || $body === '' || $body === false) {
            $body = [];
            $this->setRequestBody($ctx, $body);
        }

        $contentTypes = Utils::contentTypes($this->strapi);

        $modelDef = $this->strapi->getModel($model);

        if (!$contentTypes->isLocalizedContentType($modelDef)) {
            return $next();
        }

        $body = is_array($body) ? $body : [];

        // Prevent empty string locale
        $locale = self::truthy($query['locale'] ?? null) ? $query['locale'] : (self::truthy($body['locale'] ?? null) ? $body['locale'] : null);

        // cleanup to avoid creating duplicates in single types
        $ctx->setQuery([]);

        try {
            $entityLocale = $contentTypes->getValidLocale($locale);
        } catch (\Throwable) {
            throw new ApplicationError("This locale doesn't exist");
        }

        $body['locale'] = $entityLocale;
        $this->setRequestBody($ctx, $body);

        if ($modelDef !== null && $modelDef->kind === 'singleType') {
            $entity = $this->strapi->entityService()->findMany($modelDef->uid, [
                'locale' => $entityLocale,
            ]);

            $ctx->setQuery(['locale' => $body['locale']]);

            // updating
            if ($entity) {
                return $next();
            }
        }

        return $next();
    }

    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false && $value !== 0 && $value !== [];
    }

    private function setRequestBody(Context $ctx, mixed $body): void
    {
        if (method_exists($ctx, 'setRequestBody')) {
            $ctx->setRequestBody($body);
        }
    }
}
