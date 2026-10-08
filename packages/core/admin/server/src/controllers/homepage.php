<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/homepage.ts (`admin::homepage`). */
final class Homepage
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function homepageService(): \Strapi\Admin\Services\Homepage
    {
        return Utils::getService($this->strapi, 'homepage');
    }

    /** @return array{data: array<string, mixed>} */
    public function getKeyStatistics(): array
    {
        return ['data' => $this->homepageService()->getKeyStatistics()];
    }

    /** @return array{data: array<string, mixed>|null} */
    public function getHomepageLayout(Context $ctx): array
    {
        $user = $ctx->state()->get('user');
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
        $data = $this->homepageService()->getHomepageLayout($userId);

        // `{ data: null }`: JSON keeps a null
        return ['data' => $data];
    }

    /** @return array{data: array<string, mixed>} */
    public function updateHomepageLayout(Context $ctx): array
    {
        $user = $ctx->state()->get('user');
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
        $body = $ctx->requestBody();
        $data = $this->homepageService()->updateHomepageLayout($userId, $body);

        return ['data' => $data];
    }
}
