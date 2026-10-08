<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers;

use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Providers\Utils\Dispatcher;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Websocket\Client;
use Strapi\DataTransfer\Utils\Websocket\Connection;
use Strapi\DataTransfer\Utils\Websocket\UnexpectedResponseError;

/**
 * Port of src/strapi/providers/utils.ts: the remote providers' WebSocket helpers.
 *
 * @phpstan-import-type RetryMessageOptions from Dispatcher
 */
final class Utils
{
    /**
     * @param RetryMessageOptions|null      $retryMessageOptions
     * @param (callable(string): void)|null $reportInfo
     */
    public static function createDispatcher(Connection $ws, ?array $retryMessageOptions = null, ?callable $reportInfo = null): Dispatcher
    {
        return new Dispatcher(
            $ws,
            $retryMessageOptions ?? ['retryMessageMaxRetries' => 5, 'retryMessageTimeout' => 30000],
            $reportInfo !== null ? \Closure::fromCallable($reportInfo) : null
        );
    }

    /**
     * @param array{headers?: array<string, string>}|null $options
     */
    public static function connectToWebsocket(string $address, ?array $options = null, ?Diagnostic $diagnostics = null): Connection
    {
        try {
            $server = Client::connect($address, $options['headers'] ?? []);
        } catch (UnexpectedResponseError $res) {
            if ($res->statusCode === 401) {
                throw new ProviderInitializationError('Failed to initialize the connection: Authentication Error');
            }

            if ($res->statusCode === 403) {
                throw new ProviderInitializationError('Failed to initialize the connection: Authorization Error');
            }

            if ($res->statusCode === 404) {
                throw new ProviderInitializationError('Failed to initialize the connection: Data transfer is not enabled on the remote host');
            }

            throw new ProviderInitializationError("Failed to initialize the connection: Unexpected server response {$res->statusCode}");
        } catch (\Throwable $err) {
            throw new ProviderTransferError($err->getMessage(), [
                'details' => [
                    'error' => $err->getMessage(),
                ],
            ]);
        }

        // report the remote diagnostics; they are not responses anybody waits for
        $server->onMessage = static function (string $raw) use ($diagnostics): bool {
            $response = json_decode($raw, true);
            if (is_array($response) && is_array($response['diagnostic'] ?? null)) {
                $diagnostics?->report($response['diagnostic']);

                return true;
            }

            return false;
        };

        return $server;
    }

    public static function trimTrailingSlash(string $input): string
    {
        return (string) preg_replace('#/$#', '', $input);
    }

    public static function wait(int $ms): void
    {
        usleep($ms * 1000);
    }

    /** @param callable(): bool $test */
    public static function waitUntil(callable $test, int $interval): void
    {
        while (!$test()) {
            self::wait($interval);
        }
    }
}
