<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\OperationContextFactory;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/operation.ts. */
final class OperationAssembler implements Assembler\PathItem
{
    /** `OpenAPIV3.HttpMethods` */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private readonly OperationContextFactory $contextFactory;

    /** @param list<Assembler\Operation> $assemblers */
    public function __construct(
        private readonly array $assemblers,
        ?OperationContextFactory $contextFactory = null,
    ) {
        $this->contextFactory = $contextFactory ?? new OperationContextFactory();
    }

    public function assemble(Context $context, string $path, array $routes): void
    {
        $debug = Debug::createDebugger('assembler:operation');

        foreach ($routes as $route) {
            $method = (string) ($route['method'] ?? '');

            $methodIndex = strtolower($method);
            $operationContext = $this->contextFactory->create([
                'strapi' => $context->strapi,
                'routes' => $context->routes,
                'timer' => $context->timer,
                'registries' => $context->registries,
            ]);

            $this->validateHTTPIndex($methodIndex);

            $debug('assembling operation object for %o %o...', $method, $path);

            foreach ($this->assemblers as $assembler) {
                $debug('running assembler: %s...', $assembler::class);

                $assembler->assemble($operationContext, $route);
            }

            $operationObject = $operationContext->output->data;

            $this->validateOperationObject($operationObject);

            $debug('assembled operation object for %o %o', $method, $path);

            $context->output->data[$methodIndex] = $operationObject;
        }
    }

    /** @param array<string, mixed> $operation */
    private function validateOperationObject(array $operation): void
    {
        if (!array_key_exists('responses', $operation)) {
            throw new \RuntimeException('Invalid operation object: missing "responses" property');
        }
    }

    private function validateHTTPIndex(string $method): void
    {
        if (!in_array($method, self::HTTP_METHODS, true)) {
            throw new \RuntimeException("Invalid HTTP method object: {$method}. Expected one of " . implode(',', self::HTTP_METHODS));
        }
    }
}
