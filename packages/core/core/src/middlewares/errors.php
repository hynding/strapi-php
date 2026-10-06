<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Errors as ErrorsService;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\HttpError;

/**
 * Port of packages/core/core/src/middlewares/errors.ts: turns thrown errors into the
 * `{ data: null, error: { status, name, message, details } }` envelope and answers 404 when no
 * route set a status. Unknown errors are logged and hidden behind a 500 (message exposed only in
 * development, like http-errors' `expose`).
 */
final class Errors
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        return static function (Context $ctx, callable $next) use ($strapi): void {
            try {
                $next();

                if (!$ctx->hasExplicitStatus()) {
                    $ctx->notFound();
                }
            } catch (HttpError $error) {
                ['status' => $status, 'body' => $body] = ErrorsService::formatHttpError($error);
                $ctx->setStatus($status);
                $ctx->setBody($body);
            } catch (ApplicationError $error) {
                ['status' => $status, 'body' => $body] = ErrorsService::formatApplicationError($error);
                $ctx->setStatus($status);
                $ctx->setBody($body);
            } catch (\Throwable $error) {
                $strapi->log()->error($error->getMessage(), ['exception' => $error]);

                ['status' => $status, 'body' => $body] = ErrorsService::formatInternalError($error);
                if ($strapi->config()->get('environment') === 'development') {
                    $body['error']['message'] = $error->getMessage();
                }
                $ctx->setStatus($status);
                $ctx->setBody($body);
            }
        };
    }
}
