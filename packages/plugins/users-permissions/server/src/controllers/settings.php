<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\EmailTemplate;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/controllers/settings.js. */
final class Settings
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** `strapi.store({ type: 'plugin', name: 'users-permissions', key }).get()` */
    private function getStore(string $key): mixed
    {
        return $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => $key]);
    }

    /** `strapi.store({ type: 'plugin', name: 'users-permissions', key }).set({ value })` */
    private function setStore(string $key, mixed $value): void
    {
        $this->strapi->store()->set(['type' => 'plugin', 'name' => 'users-permissions', 'key' => $key, 'value' => $value]);
    }

    public function getEmailTemplate(Context $ctx): mixed
    {
        $ctx->send($this->getStore('email'));

        return null;
    }

    public function updateEmailTemplate(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        if (Objects::isEmpty($body)) {
            throw new ValidationError('Request body cannot be empty');
        }

        $emailTemplates = is_array($body) ? ($body['email-templates'] ?? null) : null;
        if (!is_array($emailTemplates)) {
            throw new \TypeError('Cannot convert undefined or null to object');
        }

        foreach (array_keys($emailTemplates) as $key) {
            $template = $emailTemplates[$key]['options']['message'] ?? null;

            if (!EmailTemplate::isValidEmailTemplate($template)) {
                throw new ValidationError('Invalid template');
            }
        }

        $this->setStore('email', $emailTemplates);

        $ctx->send(['ok' => true]);

        return null;
    }

    public function getAdvancedSettings(Context $ctx): mixed
    {
        $settings = $this->getStore('advanced');

        $roles = Utils::getService($this->strapi, 'role')->find();

        $ctx->send(['settings' => $settings, 'roles' => $roles]);

        return null;
    }

    public function updateAdvancedSettings(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        if (Objects::isEmpty($body)) {
            throw new ValidationError('Request body cannot be empty');
        }

        $this->setStore('advanced', $body);

        $ctx->send(['ok' => true]);

        return null;
    }

    public function getProviders(Context $ctx): mixed
    {
        $providers = $this->getStore('grant');

        if (is_array($providers)) {
            foreach (array_keys($providers) as $provider) {
                if ($provider !== 'email' && is_array($providers[$provider])) {
                    $providers[$provider]['redirectUri'] = Utils::getService($this->strapi, 'providers')->buildRedirectUri((string) $provider);
                }
            }
        }

        $ctx->send($providers);

        return null;
    }

    public function updateProviders(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        if (Objects::isEmpty($body)) {
            throw new ValidationError('Request body cannot be empty');
        }

        $this->setStore('grant', is_array($body) ? ($body['providers'] ?? null) : null);

        $ctx->send(['ok' => true]);

        return null;
    }
}
