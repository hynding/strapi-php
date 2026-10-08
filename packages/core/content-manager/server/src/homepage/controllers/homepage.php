<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Homepage\Controllers;

use Strapi\ContentManager\Homepage\Services\Homepage as HomepageService;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodError;

/** Port of server/src/homepage/controllers/homepage.ts (`createHomepageController`). */
final class Homepage
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createHomepageController(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @return HomepageService */
    private function homepageService(): object
    {
        /** @var HomepageService $service */
        $service = $this->strapi->plugin('content-manager')->service('homepage');

        return $service;
    }

    /** @return array{data: list<array<string, mixed>>} */
    public function getRecentDocuments(Context $ctx): array
    {
        $recentDocumentParamsSchema = z::object([
            'action' => z::enum(['update', 'publish']),
        ]);

        try {
            $parsed = $recentDocumentParamsSchema->parse($ctx->query());
            $action = is_array($parsed) ? ($parsed['action'] ?? null) : null;
        } catch (ZodError $error) {
            throw new ValidationError(isset($error->issues[0]['message']) ? (string) $error->issues[0]['message'] : 'Validation error');
        }

        if ($action === 'publish') {
            return ['data' => $this->homepageService()->getRecentlyPublishedDocuments()];
        }

        return ['data' => $this->homepageService()->getRecentlyUpdatedDocuments()];
    }

    /** @return array{data: array{draft: int, published: int, modified: int}} */
    public function getCountDocuments(Context $ctx): array
    {
        return ['data' => $this->homepageService()->getCountDocuments()];
    }
}
