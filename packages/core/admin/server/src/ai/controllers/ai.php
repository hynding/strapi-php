<?php

declare(strict_types=1);

namespace Strapi\Admin\Ai\Controllers;

use Strapi\Admin\Ai\Services\Ai as AiAdminService;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/** Port of server/src/ai/controllers/ai.ts (`admin::ai`). */
final class Ai
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** `strapi.ai.admin` */
    private function aiAdmin(): AiAdminService
    {
        return $this->strapi->get('ai.admin');
    }

    public function getAiToken(Context $ctx): mixed
    {
        if ($this->aiAdmin()->isStrapiManagedAiEnabled() === false) {
            $ctx->notFound();

            return null;
        }

        try {
            // `admin::isAuthenticatedAdmin` only checks `ctx.state.isAuthenticated`, not `ctx.state.user`.
            // With the current admin JWT strategy, a successful auth run always sets both; this handler
            // still requires `user` because `getAiToken()` needs an admin identity. Keeps us safe if the
            // strategy/policy contract ever diverges or this action is invoked outside the normal pipeline.
            if (empty($ctx->state()->get('user'))) {
                $ctx->unauthorized('Authentication required');

                return null;
            }

            $aiToken = $this->aiAdmin()->getAiToken();

            $ctx->setBody([
                'data' => $aiToken,
            ]);
        } catch (\Throwable) {
            $ctx->internalServerError('AI token request failed. Check server logs for details.');
        }

        return null;
    }

    public function getAiUsage(Context $ctx): mixed
    {
        if ($this->aiAdmin()->isStrapiManagedAiEnabled() === false) {
            $ctx->notFound();

            return null;
        }

        try {
            $usage = $this->aiAdmin()->getAiUsage();
            $ctx->setBody($usage);
        } catch (\Throwable) {
            $ctx->internalServerError('AI usage data request failed. Check server logs for details.');
        }

        return null;
    }

    public function getAiFeatureConfig(Context $ctx): mixed
    {
        if ($this->aiAdmin()->isAvailable() === false) {
            $ctx->notFound();

            return null;
        }

        try {
            $aiFeatureConfig = $this->aiAdmin()->getAiFeatureConfig();

            $ctx->setBody([
                'data' => $aiFeatureConfig,
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('AI feature config request failed', ['error' => $error]);
            $ctx->internalServerError('AI feature config request failed. Check server logs for details.');
        }

        return null;
    }
}
