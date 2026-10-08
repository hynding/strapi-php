<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Assemblers\Document\DocumentSecurityAssembler;
use Strapi\Openapi\Assemblers\Document\DocumentServerAssembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\DocumentContextFactory;
use Strapi\Openapi\PostProcessor\ComponentsWriter;
use Strapi\Openapi\Tests\Mocks\StrapiConfigMock;

/** Port of __tests__/document-assemblers.test.ts. */
final class DocumentAssemblersTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function createDocumentContext(array $config = []): Context
    {
        return (new DocumentContextFactory())->create(['strapi' => new StrapiConfigMock($config), 'routes' => []]);
    }

    // --- DocumentSecurityAssembler ---------------------------------------------------------

    public function testIncludesDefaultBearerAuthInComponentsSecuritySchemes(): void
    {
        $assembler = new DocumentSecurityAssembler();
        $context = $this->createDocumentContext();

        $assembler->assemble($context);

        self::assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT Bearer token for authentication',
        ], $context->output->data['components']['securitySchemes']['bearerAuth']);
        self::assertSame([['bearerAuth' => []]], $context->output->data['security']);
    }

    public function testPreservesBearerAuthAfterComponentsWriterPostProcessing(): void
    {
        $assembler = new DocumentSecurityAssembler();
        $postProcessor = new ComponentsWriter();
        $context = $this->createDocumentContext();

        $assembler->assemble($context);
        $postProcessor->postProcess($context);

        self::assertArrayHasKey('bearerAuth', $context->output->data['components']['securitySchemes']);
        self::assertArrayHasKey('schemas', $context->output->data['components']);
    }

    public function testUsesOpenapiSecuritySchemesWhenConfigured(): void
    {
        $assembler = new DocumentSecurityAssembler();
        $customBearerAuth = [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'Project JWT',
        ];
        $apiKey = [
            'type' => 'apiKey',
            'name' => 'X-API-Key',
            'in' => 'header',
            'description' => 'Service API key',
        ];
        $context = $this->createDocumentContext([
            'openapi.security' => [
                'bearerAuth' => $customBearerAuth,
                'apiKey' => $apiKey,
            ],
        ]);

        $assembler->assemble($context);

        self::assertSame($customBearerAuth, $context->output->data['components']['securitySchemes']['bearerAuth']);
        self::assertSame($apiKey, $context->output->data['components']['securitySchemes']['apiKey']);
    }

    public function testUsesOpenapiGlobalSecurityWhenConfigured(): void
    {
        $assembler = new DocumentSecurityAssembler();
        $globalSecurity = [['apiKey' => []]];
        $context = $this->createDocumentContext(['openapi.globalSecurity' => $globalSecurity]);

        $assembler->assemble($context);

        self::assertSame($globalSecurity, $context->output->data['security']);
    }

    // --- DocumentServerAssembler -----------------------------------------------------------

    public function testIncludesADefaultServerFromServerUrl(): void
    {
        $assembler = new DocumentServerAssembler();
        $context = $this->createDocumentContext(['server.url' => 'https://api.example.com']);

        $assembler->assemble($context);

        self::assertSame([['url' => 'https://api.example.com', 'description' => 'Default server']], $context->output->data['servers']);
    }

    public function testPrefersOpenapiServersOverServerUrl(): void
    {
        $assembler = new DocumentServerAssembler();
        $context = $this->createDocumentContext([
            'openapi.servers' => [['url' => 'https://staging.example.com', 'description' => 'Staging']],
            'server.url' => 'https://api.example.com',
        ]);

        $assembler->assemble($context);

        self::assertSame([['url' => 'https://staging.example.com', 'description' => 'Staging']], $context->output->data['servers']);
    }

    public function testFallsBackToLocalhostWhenNoServerConfigIsSet(): void
    {
        $assembler = new DocumentServerAssembler();
        $context = $this->createDocumentContext();

        $assembler->assemble($context);

        self::assertSame([['url' => 'http://localhost:1337', 'description' => 'Default server']], $context->output->data['servers']);
    }
}
