<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder;

use Strapi\Admin\Ai\Services\Ai as AiAdminService;
use Strapi\Core\Strapi;
use Strapi\Utils\Security;

/**
 * Port of server/src/register.ts.
 *
 * When Strapi-managed AI is enabled, the security middleware's CSP gains the Strapi AI S3
 * domains for `img-src` / `media-src`.
 */
final class Register
{
    private const array S3_DOMAINS = [
        'strapi-ai-staging.s3.us-east-1.amazonaws.com',
        'strapi-ai-production.s3.us-east-1.amazonaws.com',
    ];

    public function __invoke(Strapi $strapi): void
    {
        // `ai.admin` is registered by the admin package
        $aiAdmin = $strapi->has('ai.admin') ? $strapi->ai()->admin() : null;
        $isAiEnabled = $aiAdmin instanceof AiAdminService && $aiAdmin->isStrapiManagedAiEnabled();

        if (!$isAiEnabled) {
            return;
        }

        $defaultImgSrc = Security::CSP_DEFAULTS['img-src'];
        $defaultMediaSrc = Security::CSP_DEFAULTS['media-src'];

        // Extend the security middleware configuration to include S3 domains + defaults
        $middlewares = [];
        foreach ((array) $strapi->config()->get('middlewares', []) as $middleware) {
            if (is_string($middleware) || is_array($middleware)) {
                $middlewares[] = $middleware;
            }
        }

        $configuredMiddlewares = Security::extendMiddlewareConfiguration($middlewares, [
            'name' => 'strapi::security',
            'config' => [
                'contentSecurityPolicy' => [
                    'directives' => [
                        'img-src' => [...$defaultImgSrc, ...self::S3_DOMAINS],
                        'media-src' => [...$defaultMediaSrc, ...self::S3_DOMAINS],
                    ],
                ],
            ],
        ]);

        $strapi->config()->set('middlewares', $configuredMiddlewares);
    }
}
