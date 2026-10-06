<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Cli\CliContext;
use Symfony\Component\Console\Command\Command;

/**
 * Port of packages/core/strapi/src/cli/commands/index.ts: the list of command factories
 * (`callable(CliContext): ?Command`).
 *
 * Not ported (see the package README): admin:delete-user / active-user / block-user / list-users,
 * components:list, controllers:list, hooks:list, middlewares:list, policies:list, services:list,
 * content-types:rename-field, generate, templates:generate, ts:generate-types, report, export,
 * import, transfer, openapi, enterprise, cloud. Added: cron:run, migrations:run.
 */
final class Commands
{
    /** @return list<callable(CliContext): ?Command> */
    public static function all(): array
    {
        return [
            static fn (CliContext $ctx): Command => new Admin\CreateUser($ctx),
            static fn (CliContext $ctx): Command => new Admin\ResetUserPassword($ctx),
            static fn (CliContext $ctx): Command => new Configuration\Dump($ctx),
            static fn (CliContext $ctx): Command => new Configuration\Restore($ctx),
            static fn (CliContext $ctx): Command => new Console($ctx),
            static fn (CliContext $ctx): Command => new ContentTypes\List_($ctx),
            static fn (CliContext $ctx): Command => new Routes\List_($ctx),
            static fn (CliContext $ctx): Command => new Start($ctx),
            static fn (CliContext $ctx): Command => new Telemetry\Disable($ctx),
            static fn (CliContext $ctx): Command => new Telemetry\Enable($ctx),
            static fn (CliContext $ctx): Command => new Version($ctx),
            static fn (CliContext $ctx): Command => new Build($ctx),
            static fn (CliContext $ctx): Command => new Develop($ctx),
            static fn (CliContext $ctx): Command => new Cron\Run($ctx),
            static fn (CliContext $ctx): Command => new Migrations\Run($ctx),
        ];
    }
}
