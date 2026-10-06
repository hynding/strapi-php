<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Types\Schema\Schema;

/**
 * The `proto` of core-api/controller/index.ts: `transformResponse`, `sanitizeOutput`, `sanitizeInput`,
 * `sanitizeQuery`, `validateQuery`, `validateInput`, shared by the collection and single type controllers.
 */
abstract class Base
{
    public readonly string $uid;

    public function __construct(protected readonly Strapi $strapi, public readonly Schema $contentType)
    {
        $this->uid = $contentType->uid;
    }

    /** @return array<string, mixed> `ctx.state.auth ?? {}` */
    protected static function getAuthFromKoaContext(Context $ctx): array
    {
        return $ctx->state()->auth() ?? [];
    }

    /**
     * Build options for contentAPI.sanitize/validate from the request context (auth, route, strictParams).
     *
     * @return array{auth: mixed, route: array<string, mixed>|null, strictParams?: bool}
     */
    protected function getContentAPIOptions(Context $ctx): array
    {
        $auth = self::getAuthFromKoaContext($ctx);
        $route = $ctx->state()->route();
        $options = ['auth' => $auth === [] ? null : $auth, 'route' => $route];
        $apiStrictParams = $this->strapi->config()->get('api.rest.strictParams');
        if (is_bool($apiStrictParams)) {
            $options['strictParams'] = $apiStrictParams;
        }

        return $options;
    }

    /** @param array<string, mixed> $meta */
    public function transformResponse(mixed $data, array $meta = []): mixed
    {
        $ctx = $this->strapi->requestContext()->get();

        return Transform::transformResponse($this->strapi, $data, $meta, [
            'contentType' => $this->contentType,
            'useJsonAPIFormat' => $ctx?->header('strapi-response-format') === 'v4',
            'encodeSourceMaps' => $ctx?->header('strapi-encode-source-maps') === 'true',
        ]);
    }

    public function sanitizeOutput(mixed $data, Context $ctx): mixed
    {
        $auth = self::getAuthFromKoaContext($ctx);

        return $this->strapi->contentAPI()->sanitize()->output($data, $this->contentType, ['auth' => $auth === [] ? null : $auth]);
    }

    public function sanitizeInput(mixed $data, Context $ctx): mixed
    {
        return $this->strapi->contentAPI()->sanitize()->input($data, $this->contentType, $this->getContentAPIOptions($ctx));
    }

    /** @return array<string, mixed> */
    public function sanitizeQuery(Context $ctx): array
    {
        return $this->strapi->contentAPI()->sanitize()->query($ctx->query(), $this->contentType, $this->getContentAPIOptions($ctx));
    }

    public function validateQuery(Context $ctx): void
    {
        $this->strapi->contentAPI()->validate()->query($ctx->query(), $this->contentType, $this->getContentAPIOptions($ctx));
    }

    public function validateInput(mixed $data, Context $ctx): void
    {
        $this->strapi->contentAPI()->validate()->input($data, $this->contentType, $this->getContentAPIOptions($ctx));
    }
}
