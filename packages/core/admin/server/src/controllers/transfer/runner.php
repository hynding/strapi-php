<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Transfer;

use Strapi\Admin\Strategies\DataTransfer as DataTransferAuthStrategy;
use Strapi\DataTransfer\Strapi\Remote\Handlers\Pull;
use Strapi\DataTransfer\Strapi\Remote\Handlers\Push;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/controllers/transfer/runner.ts: the push and pull routes are
 * `@strapi/data-transfer`'s WebSocket controllers (`createPushController({ verify })` /
 * `createPullController({ verify })`).
 *
 * The WebSocket upgrade needs the raw connection: the transfer server (`strapi transfer:serve`,
 * which the reverse proxy routes `/admin/transfer/runner/*` to) provides it. A request served by
 * FPM/FrankenPHP cannot be upgraded and answers `501 Not Implemented` (once it has passed the
 * route's `data-transfer` authentication, as upstream).
 */
final class Runner
{
    /** @var (\Closure(Context): void)|null */
    private static ?\Closure $pushController = null;

    /** @var (\Closure(Context): void)|null */
    private static ?\Closure $pullController = null;

    /**
     * @param string|null $scope the scope to verify
     */
    public static function verify(Context $ctx, ?string $scope = null): void
    {
        $auth = $ctx->state()->get('auth');

        if (!is_array($auth) || $auth === []) {
            throw new UnauthorizedError();
        }

        DataTransferAuthStrategy::verify($auth, ['scope' => $scope]);
    }

    public function push(Context $ctx): mixed
    {
        self::$pushController ??= Push::createPushController(['verify' => self::verify(...)]);
        (self::$pushController)($ctx);

        return null;
    }

    public function pull(Context $ctx): mixed
    {
        self::$pullController ??= Pull::createPullController(['verify' => self::verify(...)]);
        (self::$pullController)($ctx);

        return null;
    }
}
