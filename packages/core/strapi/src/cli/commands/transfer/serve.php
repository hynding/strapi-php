<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Transfer;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Cli\Cli\Utils\DataTransfer;
use Strapi\DataTransfer\Utils\Websocket\Server;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream command: `$ strapi transfer:serve` runs the server side of remote transfers.
 *
 * Upstream's Koa server upgrades `/admin/transfer/runner/{push,pull}` to WebSockets in-process.
 * PHP served by FPM or FrankenPHP cannot hold an upgraded connection, so this long-running process
 * serves those routes (through the regular router, middlewares and transfer-token auth) and the
 * reverse proxy forwards `/admin/transfer/runner/*` (with the `Upgrade` header) to it, e.g. Caddy:
 * `reverse_proxy /admin/transfer/runner/* 127.0.0.1:1338`. One transfer is served at a time.
 */
final class Serve extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('transfer:serve')
            ->setDescription('Serve the remote data transfer WebSocket routes (/admin/transfer/runner/push|pull)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Interface to listen on', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '1338');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');

        $strapi = DataTransfer::createStrapiInstance(['cwd' => $this->ctx->cwd, 'logLevel' => $output->isVerbose() ? 'info' : 'warn']);
        $httpServer = $strapi->server()->mount();

        $server = new Server($host, $port);
        $boundPort = $server->listen();

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, static function () use ($server): void {
                    $server->stop();
                });
            }
        }

        $output->writeln("Data transfer server listening on ws://{$host}:{$boundPort}/admin/transfer/runner/{push,pull}");

        $server->serve(
            static fn ($request) => $httpServer->handle($request),
            static function (\Throwable $error) use ($strapi): void {
                $strapi->log()->error("[Data transfer] {$error->getMessage()}");
            }
        );

        $strapi->destroy();

        return 0;
    }
}
