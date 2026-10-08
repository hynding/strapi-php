<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\Sentry\Sdk\Client;
use Strapi\Plugin\Sentry\Sdk\Scope;

/**
 * Port of server/src/services/sentry.ts.
 *
 * `@sentry/node` is replaced by {@see Client}, a small client posting events to the envelope
 * endpoint of the DSN through `strapi.fetch`. `getInstance()` returns that client (upstream: the
 * `Sentry` namespace), or null while Sentry is not initialized (no DSN, invalid DSN, `init()` not
 * called yet). Without a DSN nothing is created and no network I/O happens.
 */
final class Sentry
{
    private bool $isReady = false;

    private ?Client $instance = null;

    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(private readonly Strapi $strapi)
    {
        // Retrieve user config and merge it with the default one
        $config = $strapi->config()->get('plugin::sentry');
        $this->config = is_array($config) ? $config : [];
    }

    public static function createSentryService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /**
     * Initialize Sentry service
     */
    public function init(): static
    {
        // Make sure there isn't a Sentry instance already running
        if ($this->instance !== null) {
            return $this;
        }

        // Don't init Sentry if no DSN was provided
        $dsn = $this->config['dsn'] ?? null;
        if ($dsn === null || $dsn === '' || $dsn === false) {
            $this->strapi->log()->info('@strapi/plugin-sentry is disabled because no Sentry DSN was provided');

            return $this;
        }

        try {
            $init = is_array($this->config['init'] ?? null) ? $this->config['init'] : [];
            $strapi = $this->strapi;

            $client = Client::init(
                [
                    'dsn' => $dsn,
                    'environment' => $strapi->config()->get('environment'),
                    ...$init,
                ],
                // the transport: strapi.fetch, resolved on the first event only
                static fn (string $url, array $options): mixed => ($strapi->fetch())($url, $options),
                $strapi->log(),
            );

            // Store the successfully initialized Sentry instance
            $this->instance = $client;
            $this->isReady = true;
        } catch (\Throwable) {
            $this->strapi->log()->warning('Could not set up Sentry, make sure you entered a valid DSN');
        }

        return $this;
    }

    /**
     * Expose Sentry instance through a getter
     */
    public function getInstance(): ?Client
    {
        return $this->instance;
    }

    /**
     * Higher level method to send exception events to Sentry
     *
     * @param (callable(Scope): mixed)|null $configureScope
     */
    public function sendError(\Throwable $error, ?callable $configureScope = null): void
    {
        // Make sure Sentry is ready
        $instance = $this->instance;
        if (!$this->isReady || $instance === null) {
            $this->strapi->log()->warning("Sentry wasn't properly initialized, cannot send event");

            return;
        }

        $sendMetadata = (bool) ($this->config['sendMetadata'] ?? false);

        $instance->withScope(static function (Scope $scope) use ($instance, $error, $configureScope, $sendMetadata): void {
            // Configure the Sentry scope using the provided callback
            if ($configureScope !== null && $sendMetadata) {
                $configureScope($scope);
            }

            // Actually send the Error to Sentry
            $instance->captureException($error);
        });
    }
}
