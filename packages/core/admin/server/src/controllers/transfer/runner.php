<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Transfer;

use Strapi\Admin\Strategies\DataTransfer as DataTransferAuthStrategy;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\NotImplementedError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/controllers/transfer/runner.ts. The push and pull handlers are
 * `@strapi/data-transfer`'s WebSocket controllers (`createPushController` /
 * `createPullController`), which are not ported: both throw {@see NotImplementedError} once the
 * request is authenticated and verified for its scope.
 */
final class Runner
{
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
        self::verify($ctx, 'push');

        // createPushController({ verify }) from @strapi/data-transfer
        throw new NotImplementedError('Remote data transfer (push) requires @strapi/data-transfer, which is not ported');
    }

    public function pull(Context $ctx): mixed
    {
        self::verify($ctx, 'pull');

        // createPullController({ verify }) from @strapi/data-transfer
        throw new NotImplementedError('Remote data transfer (pull) requires @strapi/data-transfer, which is not ported');
    }
}
