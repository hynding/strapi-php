<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Cli\Cli\CliContext;
use Symfony\Component\Console\Command\Command;

/**
 * Port of packages/core/strapi/src/cli/commands/index.ts: the list of command factories
 * (`callable(CliContext): ?Command`), in upstream's order.
 *
 * Not ported (see the package README): admin:delete-user / active-user / block-user / list-users,
 * content-types:rename-field, ts:generate-types (TypeScript typings), enterprise, cloud.
 * `openapi generate` is `openapi:generate`. Added: cron:run, migrations:run, transfer:serve (the
 * remote transfer WebSocket server, see commands/transfer/serve.php).
 */
final class Commands
{
    /** @return list<callable(CliContext): ?Command> */
    public static function all(): array
    {
        return [
            static fn (CliContext $ctx): Command => new Admin\CreateUser($ctx),
            static fn (CliContext $ctx): Command => new Admin\ResetUserPassword($ctx),
            static fn (CliContext $ctx): Command => new Components\List_($ctx),
            static fn (CliContext $ctx): Command => new Configuration\Dump($ctx),
            static fn (CliContext $ctx): Command => new Configuration\Restore($ctx),
            static fn (CliContext $ctx): Command => new Console($ctx),
            static fn (CliContext $ctx): Command => new ContentTypes\List_($ctx),
            static fn (CliContext $ctx): Command => new Controllers\List_($ctx),
            static fn (CliContext $ctx): Command => new Generate($ctx),
            static fn (CliContext $ctx): Command => new Hooks\List_($ctx),
            static fn (CliContext $ctx): Command => new Middlewares\List_($ctx),
            static fn (CliContext $ctx): Command => new Policies\List_($ctx),
            static fn (CliContext $ctx): Command => new Report($ctx),
            static fn (CliContext $ctx): Command => new Routes\List_($ctx),
            static fn (CliContext $ctx): Command => new Services\List_($ctx),
            static fn (CliContext $ctx): Command => new Start($ctx),
            static fn (CliContext $ctx): Command => new Telemetry\Disable($ctx),
            static fn (CliContext $ctx): Command => new Telemetry\Enable($ctx),
            static fn (CliContext $ctx): Command => new Templates\Generate($ctx),
            static fn (CliContext $ctx): Command => new Version($ctx),
            static fn (CliContext $ctx): Command => new Build($ctx),
            static fn (CliContext $ctx): Command => new Develop($ctx),
            static fn (CliContext $ctx): Command => new Export\Command($ctx),
            static fn (CliContext $ctx): Command => new Import\Command($ctx),
            static fn (CliContext $ctx): Command => new Transfer\Command($ctx),
            static fn (CliContext $ctx): Command => new Openapi\Openapi($ctx),
            static fn (CliContext $ctx): Command => new Cron\Run($ctx),
            static fn (CliContext $ctx): Command => new Migrations\Run($ctx),
            static fn (CliContext $ctx): Command => new Transfer\Serve($ctx),
        ];
    }
}
