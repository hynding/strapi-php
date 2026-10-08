<?php

declare(strict_types=1);

namespace Strapi\Openapi\Generator;

use Strapi\Core\Strapi;
use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\DocumentContextFactory;
use Strapi\Openapi\PostProcessor\PostProcessor;
use Strapi\Openapi\PreProcessor\PreProcessor;
use Strapi\Openapi\Routes\RouteCollector;
use Strapi\Openapi\Utils\Debug;

/**
 * Port of packages/core/openapi/src/generator/generator.ts.
 *
 * @phpstan-import-type GeneratorOptions from Types
 * @phpstan-import-type GeneratorOutput from Types
 *
 * @phpstan-type OpenAPIGeneratorConfig array{preProcessors?: list<PreProcessor>, assemblers?: list<Assembler\Document>, postProcessors?: list<PostProcessor>}
 */
final class OpenAPIGenerator
{
    // Config
    /** @var list<Assembler\Document> */
    private readonly array $assemblers;

    /** @var list<PreProcessor> */
    private readonly array $preProcessors;

    /** @var list<PostProcessor> */
    private readonly array $postProcessors;

    /** @var \Closure(string, mixed...): void */
    private readonly \Closure $debug;

    /**
     * @param OpenAPIGeneratorConfig $config
     * @param Strapi $strapi
     */
    public function __construct(
        // Config
        array $config,
        // Dependencies
        private readonly object $strapi,
        private readonly RouteCollector $routeCollector,
        // Factories
        private readonly DocumentContextFactory $contextFactory,
    ) {
        $this->assemblers = $config['assemblers'] ?? [];
        $this->preProcessors = $config['preProcessors'] ?? [];
        $this->postProcessors = $config['postProcessors'] ?? [];
        $this->debug = Debug::createDebugger('generator');
    }

    /**
     * @param GeneratorOptions|null $options
     *
     * @return GeneratorOutput
     */
    public function generate(?array $options = null): array
    {
        ($this->debug)('generating a new OpenAPI document with the following options: %O', $options);

        $context = $this->initContext($this->strapi);

        $this
            // Init timers
            ->bootstrap($context)
            // Run registered pre-processors
            ->preProcess($context)
            // Run registered section assemblers
            ->assemble($context)
            // Run registered post-processors
            ->postProcess($context)
            // Clean up and set necessary properties
            ->finalize($context);

        $data = $context->output->data;
        $stats = $context->output->stats;

        return ['document' => $data, 'durationMs' => $stats['time']['elapsedTime']];
    }

    /** @param Strapi $strapi */
    private function initContext(object $strapi): Context
    {
        ($this->debug)('collecting registered routes...');
        $routes = $this->routeCollector->collect();

        ($this->debug)('creating the initial document generation context...');

        return $this->contextFactory->create(['strapi' => $strapi, 'routes' => $routes]);
    }

    private function bootstrap(Context $context): self
    {
        $timer = $context->timer;

        $timer->reset();

        $startedAt = $timer->start();

        ($this->debug)('started generation: %o', self::toISOString($startedAt));

        return $this;
    }

    private function finalize(Context $context): self
    {
        $output = $context->output;

        $output->stats['time'] = $context->timer->stop();

        ['endTime' => $endTime, 'elapsedTime' => $elapsedTime] = $output->stats['time'];

        ($this->debug)('completed generation: %O (elapsed: %Oms)', self::toISOString($endTime), $elapsedTime);

        return $this;
    }

    private function preProcess(Context $context): self
    {
        foreach ($this->preProcessors as $preProcessor) {
            ($this->debug)('running pre-processor: %s...', $preProcessor::class);

            $preProcessor->preProcess($context);
        }

        return $this;
    }

    private function assemble(Context $context): self
    {
        foreach ($this->assemblers as $assembler) {
            ($this->debug)('running assembler: %s...', $assembler::class);

            $assembler->assemble($context);
        }

        return $this;
    }

    private function postProcess(Context $context): self
    {
        foreach ($this->postProcessors as $postProcessor) {
            ($this->debug)('running post-processor: %s...', $postProcessor::class);

            $postProcessor->postProcess($context);
        }

        return $this;
    }

    private static function toISOString(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
}
