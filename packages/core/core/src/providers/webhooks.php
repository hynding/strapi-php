<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\WebhookRunner;
use Strapi\Core\Services\WebhookStore;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/webhooks.ts. */
final class Webhooks extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->get('models')->add(WebhookStore::webhookModel());

        $strapi->add('webhookStore', static fn (): WebhookStore => WebhookStore::createWebhookStore($strapi->db()));
        $strapi->add('webhookRunner', static function () use ($strapi): WebhookRunner {
            $configuration = $strapi->config()->get('server.webhooks', []);

            return WebhookRunner::createWebhookRunner([
                'eventHub' => $strapi->eventHub(),
                'logger' => $strapi->log(),
                'configuration' => is_array($configuration) ? $configuration : [],
                'fetch' => $strapi->fetch(),
            ]);
        });
    }

    public function bootstrap(Strapi $strapi): void
    {
        $webhooks = $strapi->get('webhookStore')->findWebhooks();

        foreach ($webhooks as $webhook) {
            $strapi->get('webhookRunner')->add($webhook);
        }
    }
}
