<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Handlers;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Utils\Websocket\Connection;
use Strapi\DataTransfer\Utils\Websocket\Upgrade;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * Port of src/strapi/remote/handlers/utils.ts: the upgrade checks and `handlerControllerFactory`,
 * which turns a handler into a route controller that upgrades the request to a WebSocket and
 * feeds the frames to the handler.
 *
 * Upgrading needs the raw connection, which only the transfer server (`strapi transfer:serve`,
 * see {@see \Strapi\DataTransfer\Utils\Websocket\Server}) provides: a request served by
 * FPM/FrankenPHP has no {@see Upgrade} attribute and gets `501 Not Implemented`. The server
 * handles one connection at a time, so the frames are processed in order (upstream queues them).
 *
 * @phpstan-type HandlerOptions array{verify: callable(Context, string|null=): void, strapi?: Strapi|null}
 */
final class Utils
{
    public const string NOT_SERVED_MESSAGE = 'Remote data transfer needs the transfer server: run `strapi transfer:serve` and route /admin/transfer/runner/* to it (PHP served by FPM or FrankenPHP cannot keep a WebSocket connection).';

    /** @return list<string> */
    public static function transformUpgradeHeader(?string $header = ''): array
    {
        return array_map(static fn (string $s): string => strtolower(trim($s)), explode(',', $header ?? ''));
    }

    /**
     * Make sure that the upgrade header is a valid websocket one
     */
    public static function assertValidHeader(Context $ctx, Strapi $strapi): void
    {
        $upgrade = $ctx->header('upgrade');

        // if it's exactly what we expect, it's fine
        if ($upgrade === 'websocket') {
            return;
        }

        // check if it could be an array that still includes websocket
        $upgradeHeader = self::transformUpgradeHeader($upgrade);

        // Sanitize user input before writing it to our logs
        $logSafeUpgradeHeader = $upgrade === null ? 'undefined' : substr((string) preg_replace('/[^a-z0-9\s.,|]/i', '', (string) json_encode($upgrade)), 0, 50);

        if (!in_array('websocket', $upgradeHeader, true)) {
            throw new \RuntimeException("Transfer Upgrade header expected 'websocket', found '{$logSafeUpgradeHeader}'. Please ensure that your server or proxy is not modifying the Upgrade header.");
        }

        /*
         * If there's more than expected but it still includes websocket, in theory it could still work
         * and could be necessary for their certain configurations, so we'll allow it to proceed but
         * log the unexpected behaviour in case it helps debug an issue
         */
        $strapi->log()->info("Transfer Upgrade header expected only 'websocket', found unexpected values: {$logSafeUpgradeHeader}");
    }

    public static function isDataTransferMessage(mixed $message): bool
    {
        if (!is_array($message) || $message === []) {
            return false;
        }

        $uuid = $message['uuid'] ?? null;
        $type = $message['type'] ?? null;

        if (!is_string($uuid) || !is_string($type)) {
            return false;
        }

        if (!in_array($type, ['command', 'transfer'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Handle the upgrade to ws connection
     *
     * @param \Closure(Connection): void $callback
     */
    public static function handleWSUpgrade(Context $ctx, Strapi $strapi, \Closure $callback): void
    {
        $upgrade = $ctx->request()->getAttribute(Upgrade::ATTRIBUTE);
        if (!$upgrade instanceof Upgrade) {
            throw new NotImplementedError(self::NOT_SERVED_MESSAGE);
        }

        self::assertValidHeader($ctx, $strapi);

        try {
            $client = $upgrade->accept();
        } catch (\Throwable $error) {
            // If the WebSocket upgrade failed, destroy the socket to avoid hanging
            $upgrade->destroy();
            throw $error;
        }

        $strapi->db()->lifecycles->disable();
        $strapi->log()->info('[Data transfer] Disabling lifecycle hooks');

        // Invoke the ws callback
        $callback($client);
    }

    /**
     * Protocol related functions: `handlerControllerFactory(implementation)(options)` returns the
     * route controller.
     *
     * @param \Closure(Strapi, Context, Connection, \Closure(Context, string|null): void): Handler $implementation
     *
     * @return \Closure(HandlerOptions): (\Closure(Context): void)
     */
    public static function handlerControllerFactory(\Closure $implementation): \Closure
    {
        return static function (array $options) use ($implementation): \Closure {
            $verify = \Closure::fromCallable($options['verify']);
            $explicitStrapi = $options['strapi'] ?? null;

            return static function (Context $ctx) use ($implementation, $verify, $explicitStrapi): void {
                $strapi = $explicitStrapi ?? Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

                $cb = static function (Connection $ws) use ($implementation, $verify, $strapi, $ctx): void {
                    $handler = $implementation($strapi, $ctx, $ws, static function (Context $c, ?string $scope = null) use ($verify): void {
                        $verify($c, $scope);
                    });

                    try {
                        // Bind ws events to handler methods
                        while (!$ws->isClosed()) {
                            try {
                                $raw = $ws->receive();
                            } catch (\Throwable $err) {
                                try {
                                    $handler->onError($err);
                                } catch (\Throwable $e) {
                                    $strapi->log()->error('[Data transfer] Uncaught error in error handling');
                                    $strapi->log()->error($e->getMessage());
                                    $handler->cannotRespondHandler($e);
                                }
                                break;
                            }

                            if ($raw === null) {
                                break;
                            }

                            try {
                                $handler->onMessage($raw);
                                $handler->runDeferred();
                            } catch (\Throwable $err) {
                                $strapi->log()->error('[Data transfer] Uncaught error in message handling');
                                $strapi->log()->error($err->getMessage());
                                $handler->cannotRespondHandler($err);
                            }
                        }

                        try {
                            $handler->onClose($ws->closeCode ?? 1005, $ws->closeReason);
                        } catch (\Throwable $err) {
                            $strapi->log()->error('[Data transfer] Uncaught error closing connection');
                            $strapi->log()->error($err->getMessage());
                            $handler->cannotRespondHandler($err);
                        }
                    } finally {
                        if (!$ws->isClosed()) {
                            $ws->terminate();
                        }
                        $strapi->db()->lifecycles->enable();
                        $strapi->log()->info('[Data transfer] Restoring lifecycle hooks');
                    }
                };

                try {
                    self::handleWSUpgrade($ctx, $strapi, $cb);
                } catch (NotImplementedError $err) {
                    throw $err;
                } catch (\Throwable $err) {
                    $strapi->log()->error('[Data transfer] Error in websocket upgrade request');
                    $strapi->log()->error($err->getMessage());
                }
            };
        };
    }
}
