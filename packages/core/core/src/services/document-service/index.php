<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Services\DocumentService\Middlewares\Errors;
use Strapi\Core\Services\DocumentService\Middlewares\MiddlewareManager;
use Strapi\Core\Services\EntityValidator\EntityValidator;
use Strapi\Core\Strapi;

/**
 * Port of services/document-service/index.ts (`createDocumentService`): the `strapi.documents`
 * factory. `$documents('api::article.article')` (or `->get(uid)`) returns the content type's
 * {@see DocumentServiceInstance}, whose actions run through the middleware stack registered with
 * `use()` (`callable(array $ctx, callable $next): mixed`, `$ctx = ['uid', 'contentType', 'action', 'params']`).
 *
 * @phpstan-import-type Options from Transform\Types as TransformOptions
 */
final class DocumentService
{
    /** @var array<string, DocumentServiceInstance> */
    private array $repositories = [];

    private readonly MiddlewareManager $middlewares;

    private readonly EntityValidator $validator;

    public function __construct(private readonly Strapi $strapi, ?EntityValidator $validator = null)
    {
        $this->validator = $validator ?? $strapi->entityValidator();
        $this->middlewares = MiddlewareManager::createMiddlewareManager();
        $this->middlewares->use(Errors::databaseErrorsMiddleware(...));
    }

    public static function createDocumentService(Strapi $strapi, ?EntityValidator $validator = null): self
    {
        return new self($strapi, $validator);
    }

    public function __invoke(string $uid): DocumentServiceInstance
    {
        return $this->get($uid);
    }

    public function get(string $uid): DocumentServiceInstance
    {
        if (isset($this->repositories[$uid])) {
            return $this->repositories[$uid];
        }

        $contentType = $this->strapi->contentType($uid);
        $repository = Repository::createContentTypeRepository($this->strapi, $uid, $this->validator);

        $instance = new DocumentServiceInstance($repository, $this->middlewares, ['uid' => $uid, 'contentType' => $contentType]);

        $this->repositories[$uid] = $instance;

        return $instance;
    }

    /**
     * `strapi.documents.use(middleware)`
     *
     * @return \Closure(): void unsubscribe
     */
    public function use(callable $middleware): \Closure
    {
        return $this->middlewares->use($middleware);
    }

    /**
     * `strapi.documents.utils.transformData(data, opts)`
     *
     * @param array<string, mixed> $data
     * @param TransformOptions $opts
     * @return array<string, mixed>
     */
    public function transformData(array $data, array $opts): array
    {
        return Transform\Data::transformData($this->strapi, $data, $opts);
    }
}
