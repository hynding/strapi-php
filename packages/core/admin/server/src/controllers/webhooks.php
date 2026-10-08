<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\AuditLogs\Webhooks as WebhookAudit;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\AuditLogs;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/**
 * Port of server/src/controllers/webhooks.ts (`admin::webhooks`).
 *
 * @phpstan-import-type Webhook from \Strapi\Core\Services\WebhookStore
 */
final class Webhooks
{
    private const URL_REGEX = '/^(?:([a-z0-9+.-]+):\/\/)(?:\S+(?::\S*)?@)?(?:(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9_]-*)*[a-z\x{00a1}-\x{ffff}0-9_]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9_]-*)*[a-z\x{00a1}-\x{ffff}0-9_]+)*\.?)(?::\d{2,5})?(?:[\/?#]\S*)?$/u';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * `is-localhost-ip`: whether a hostname is (or resolves to) a loopback, unspecified or
     * link-local address, or one of this machine's addresses.
     */
    public static function isLocalhostIp(string $hostname): bool
    {
        $host = trim($hostname, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === [] && in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return true;
        }

        $own = [];
        $self = gethostname();
        if (is_string($self)) {
            $own = gethostbynamel($self) ?: [];
        }

        foreach ($addresses as $address) {
            $packed = @inet_pton($address);
            if ($packed === false) {
                continue;
            }
            if (in_array($address, $own, true)) {
                return true;
            }
            if (strlen($packed) === 4) {
                $first = ord($packed[0]);
                if ($first === 127 || $first === 0 || ($first === 169 && ord($packed[1]) === 254)) {
                    return true;
                }
            } elseif ($packed === inet_pton('::1') || $packed === inet_pton('::') || (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80)) {
                return true;
            }
        }

        return false;
    }

    private function isProduction(): bool
    {
        $nodeEnv = getenv('NODE_ENV');

        return $nodeEnv === 'production';
    }

    public function webhookValidator(): YupObject
    {
        return Yup::object([
            'name' => Yup::string()->required(),
            'url' => Yup::string()
                ->matches(self::URL_REGEX, 'url must be a valid URL')
                ->required()
                ->test(
                    'is-public-url',
                    "Url is not supported because it isn't reachable over the public internet",
                    function (mixed $url): bool {
                        if (!$this->isProduction()) {
                            return true;
                        }

                        $hostname = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
                        if (!is_string($hostname) || $hostname === '') {
                            return false;
                        }

                        return !self::isLocalhostIp($hostname);
                    }
                ),
            // upstream's `_.mapValues(data, () => { yup.string().min(1).required(); })` returns
            // `undefined` for every header: the shape validates no header value
            'headers' => Yup::lazy(static fn (mixed $data): YupObject => Yup::object()->required()),
            'events' => Yup::array()->of(Yup::string())->required(),
        ])->noUnknown();
    }

    public function updateWebhookValidator(): YupObject
    {
        return $this->webhookValidator()->shape([
            'isEnabled' => Yup::boolean(),
        ]);
    }

    /**
     * The validated request body as the store's webhook shape.
     *
     * @param array<mixed> $data
     * @return Webhook
     */
    private static function toWebhook(array $data): array
    {
        $headers = [];
        foreach (is_array($data['headers'] ?? null) ? $data['headers'] : [] as $name => $value) {
            $headers[(string) $name] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return [
            ...(isset($data['id']) && is_scalar($data['id']) ? ['id' => (string) $data['id']] : []),
            'name' => is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
            'url' => is_scalar($data['url'] ?? null) ? (string) $data['url'] : '',
            'headers' => $headers,
            'events' => array_values(array_map(static fn (mixed $e): string => is_scalar($e) ? (string) $e : '', is_array($data['events'] ?? null) ? $data['events'] : [])),
            'isEnabled' => ($data['isEnabled'] ?? true) !== false,
        ];
    }

    private function webhookStore(): \Strapi\Core\Services\WebhookStore
    {
        return $this->strapi->get('webhookStore');
    }

    private function webhookRunner(): \Strapi\Core\Services\WebhookRunner
    {
        return $this->strapi->get('webhookRunner');
    }

    public function listWebhooks(Context $ctx): mixed
    {
        $webhooks = $this->webhookStore()->findWebhooks();
        $ctx->send(['data' => $webhooks]);

        return null;
    }

    public function getWebhook(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $webhook = $this->webhookStore()->findWebhook($id);

        if ($webhook === null) {
            $ctx->notFound('webhook.notFound');

            return null;
        }

        $ctx->send(['data' => $webhook]);

        return null;
    }

    public function createWebhook(Context $ctx): mixed
    {
        $body = $ctx->requestBody();

        Validators::validateYupSchema($this->webhookValidator())($body);

        // the store's rows are already in that shape (its one-line `@return` is not read by PHPStan)
        $webhook = self::toWebhook($this->webhookStore()->createWebhook(self::toWebhook(is_array($body) ? $body : [])));

        $this->webhookRunner()->add($webhook);

        AuditLogs::emitAudit($this->strapi, WebhookAudit::AUDITED_EVENTS['WEBHOOK_CREATE'], WebhookAudit::toAuditedWebhook($webhook));

        $ctx->created(['data' => $webhook]);

        return null;
    }

    public function updateWebhook(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $body = $ctx->requestBody();

        Validators::validateYupSchema($this->updateWebhookValidator())($body);

        $webhook = $this->webhookStore()->findWebhook($id);

        if ($webhook === null) {
            $ctx->notFound('webhook.notFound');

            return null;
        }

        $updatedWebhook = $this->webhookStore()->updateWebhook($id, self::toWebhook([
            ...$webhook,
            ...(is_array($body) ? $body : []),
        ]));

        if ($updatedWebhook === null) {
            $ctx->notFound('webhook.notFound');

            return null;
        }

        $updatedWebhook = self::toWebhook($updatedWebhook);
        $this->webhookRunner()->update($updatedWebhook);

        $changes = WebhookAudit::getWebhookChanges($webhook, $updatedWebhook);

        if ($changes !== []) {
            AuditLogs::emitAudit($this->strapi, WebhookAudit::AUDITED_EVENTS['WEBHOOK_UPDATE'], [
                'webhookId' => $updatedWebhook['id'] ?? null,
                'name' => $updatedWebhook['name'] ?? null,
                'changes' => $changes,
            ]);
        }

        $ctx->send(['data' => $updatedWebhook]);

        return null;
    }

    public function deleteWebhook(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');
        $webhook = $this->webhookStore()->findWebhook($id);

        if ($webhook === null) {
            $ctx->notFound('webhook.notFound');

            return null;
        }

        $this->webhookStore()->deleteWebhook($id);

        $this->webhookRunner()->remove($webhook);

        AuditLogs::emitAudit($this->strapi, WebhookAudit::AUDITED_EVENTS['WEBHOOK_DELETE'], [
            'webhookId' => $webhook['id'] ?? null,
            'name' => $webhook['name'] ?? null,
        ]);

        $ctx->setBody(['data' => $webhook]);

        return null;
    }

    public function deleteWebhooks(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        $ids = is_array($body) ? ($body['ids'] ?? null) : null;

        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            $ctx->badRequest('ids must be an array of id');

            return null;
        }

        foreach ($ids as $id) {
            $webhook = is_scalar($id) ? $this->webhookStore()->findWebhook((string) $id) : null;

            if ($webhook !== null) {
                $this->webhookStore()->deleteWebhook((string) $id);
                $this->webhookRunner()->remove($webhook);

                AuditLogs::emitAudit($this->strapi, WebhookAudit::AUDITED_EVENTS['WEBHOOK_DELETE'], [
                    'webhookId' => $webhook['id'] ?? null,
                    'name' => $webhook['name'] ?? null,
                ]);
            }
        }

        $ctx->send(['data' => $ids]);

        return null;
    }

    public function triggerWebhook(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');

        $webhook = $this->webhookStore()->findWebhook($id);

        if ($webhook === null) {
            // upstream passes the missing webhook to the runner, which throws on `webhook.url`
            throw new \TypeError("Cannot read properties of null (reading 'url')");
        }

        $response = $this->webhookRunner()->run($webhook, 'trigger-test', []);

        $ctx->setBody(['data' => $response]);

        return null;
    }
}
