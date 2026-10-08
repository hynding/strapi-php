<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

use Strapi\Core\Strapi;
use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;

/** Port of packages/core/openapi/src/assemblers/document/security.ts. */
final class DocumentSecurityAssembler implements Assembler\Document
{
    private const DEFAULT_BEARER_AUTH = [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'description' => 'JWT Bearer token for authentication',
    ];

    public function assemble(Context $context): void
    {
        $strapi = $context->strapi;

        $securitySchemes = $this->getSecuritySchemes($strapi);
        $security = $this->getGlobalSecurity($strapi);

        if (!isset($context->output->data['components'])) {
            $context->output->data['components'] = [];
        }

        $context->output->data['components']['securitySchemes'] = $securitySchemes;
        $context->output->data['security'] = $security;
    }

    /**
     * @param Strapi $strapi
     *
     * @return array<string, mixed>
     */
    private function getSecuritySchemes(object $strapi): array
    {
        $securityConfig = $strapi->config()->get('openapi.security');
        $securityConfig = is_array($securityConfig) ? $securityConfig : [];

        $schemes = [];

        $bearerAuth = $securityConfig['bearerAuth'] ?? null;
        if ($bearerAuth === null || $bearerAuth === false || $bearerAuth === 0 || $bearerAuth === '') {
            $schemes['bearerAuth'] = self::DEFAULT_BEARER_AUTH;
        }

        foreach ($securityConfig as $name => $config) {
            $schemes[(string) $name] = $config;
        }

        return $schemes;
    }

    /**
     * @param Strapi $strapi
     *
     * @return list<mixed>
     */
    private function getGlobalSecurity(object $strapi): array
    {
        $globalSecurity = $strapi->config()->get('openapi.globalSecurity');

        if (is_array($globalSecurity)) {
            return array_values($globalSecurity);
        }

        return [['bearerAuth' => []]];
    }
}
