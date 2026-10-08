<?php

declare(strict_types=1);

namespace Strapi\Email\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/controllers/email.ts.
 *
 * Email.js controller
 *
 * @description: A set of functions called "actions" of the `email` plugin.
 *
 * Upstream turns a provider error carrying `statusCode: 400` into an ApplicationError; here an
 * error with a public `statusCode` (or `status`) property equal to 400 does the same.
 */
final class Email
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function send(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $options = is_array($body) ? $body : [];

        try {
            $this->service('send')($options);
        } catch (\Throwable $error) {
            if (self::statusCode($error) === 400) {
                throw new ApplicationError($error->getMessage());
            }

            throw new \RuntimeException("Couldn't send email: {$error->getMessage()}.", 0, $error);
        }

        // Send 200 `ok`
        $ctx->send(new \stdClass());
    }

    public function test(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $to = is_array($body) ? ($body['to'] ?? null) : null;

        if (!$to) {
            throw new ApplicationError('No recipient(s) are given');
        }

        $provider = $this->strapi->config()->get('plugin::email.provider');
        $email = [
            'to' => $to,
            'subject' => 'Strapi test mail to: ' . self::toJsString($to),
            'text' => 'Great! You have correctly configured the Strapi email plugin with the ' . self::toJsString($provider) . " provider. \r\nFor documentation on how to use the email plugin checkout: https://docs.strapi.io/developer-docs/latest/plugins/email.html",
        ];

        try {
            $this->service('send')($email);
        } catch (\Throwable $error) {
            if (self::statusCode($error) === 400) {
                throw new ApplicationError($error->getMessage());
            }

            throw new \RuntimeException("Couldn't send test email: {$error->getMessage()}.", 0, $error);
        }

        // Send 200 `ok`
        $ctx->send(new \stdClass());
    }

    public function getSettings(Context $ctx): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->service('getProviderSettings')();
        $provider = $this->strapi->plugin('email')->provider;

        // Check if provider supports verify method
        $supportsVerify = is_object($provider) && method_exists($provider, 'verify');

        // Get capabilities from provider (e.g. SMTP host, auth type, features)
        $capabilities = is_object($provider) && method_exists($provider, 'getCapabilities') ? $provider->getCapabilities() : null;

        // Get pool idle status if provider supports it
        $isIdle = is_object($provider) && method_exists($provider, 'isIdle') ? $provider->isIdle() : null;

        $ctx->send([
            'config' => self::pick(
                ['provider', 'settings.defaultFrom', 'settings.defaultReplyTo', 'settings.testAddress'],
                $config
            ),
            'supportsVerify' => $supportsVerify,
            ...($capabilities ? ['capabilities' => $capabilities] : []),
            ...($isIdle !== null ? ['isIdle' => $isIdle] : []),
        ]);
    }

    public function verify(Context $ctx): void
    {
        $provider = $this->strapi->plugin('email')->provider;

        if (!is_object($provider) || !method_exists($provider, 'verify')) {
            throw new ApplicationError('This email provider does not support connection verification');
        }

        try {
            $provider->verify();
        } catch (\Throwable $error) {
            throw new ApplicationError("Connection verification failed: {$error->getMessage()}");
        }

        $ctx->send(['success' => true, 'message' => 'SMTP connection verified successfully']);
    }

    /** `strapi.plugin('email').service('email')[method]` */
    private function service(string $method): callable
    {
        $callable = [$this->strapi->plugin('email')->service('email'), $method];
        if (!is_callable($callable)) {
            throw new \RuntimeException("The email service has no {$method}()");
        }

        return $callable;
    }

    private static function statusCode(\Throwable $error): mixed
    {
        if (property_exists($error, 'statusCode')) {
            return $error->statusCode;
        }

        return null;
    }

    /**
     * lodash/fp `pick(paths, object)`: keeps the given (deep) paths that exist, in order.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $object
     * @return array<string, mixed>|\stdClass
     */
    private static function pick(array $paths, array $object): array|\stdClass
    {
        $result = [];
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = $object;
            $found = true;
            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $found = false;
                    break;
                }
            }
            if (!$found) {
                continue;
            }
            $target = &$result;
            foreach (array_slice($segments, 0, -1) as $segment) {
                if (!isset($target[$segment]) || !is_array($target[$segment])) {
                    $target[$segment] = [];
                }
                $target = &$target[$segment];
            }
            $target[$segments[count($segments) - 1]] = $value;
            unset($target);
        }

        return $result === [] ? new \stdClass() : $result;
    }

    /** Template-literal interpolation of a value. */
    private static function toJsString(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)),
            default => '[object Object]',
        };
    }
}
