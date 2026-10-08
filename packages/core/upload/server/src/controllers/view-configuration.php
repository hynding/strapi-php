<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\Admin\ConfigureView;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/controllers/view-configuration.ts. */
final class ViewConfiguration
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function updateViewConfiguration(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $userAbility = $ctx->state()->get('userAbility');

        if ($userAbility->cannot(Constants::ACTIONS['configureView'])) {
            $ctx->forbidden();

            return;
        }

        $data = ConfigureView::validateViewConfiguration($body);

        Utils::getService('upload', $this->strapi)->setConfiguration($data);

        $ctx->setBody(['data' => $data]);
    }

    public function findViewConfiguration(Context $ctx): void
    {
        $data = Utils::getService('upload', $this->strapi)->getConfiguration();

        $ctx->setBody(['data' => $data]);
    }
}
