<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Routes;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/routes/list.ts: `$ strapi routes:list`.
 *
 * Upstream prints `Method | Path`; the PHP edition adds the handler, the policies and the auth
 * setting of every route, which is what one needs when debugging a 401/403.
 */
final class List_ extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('routes:list')->setDescription('List all the application routes');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->load();

        $list = $app->server()->mount()->listRoutes();

        $table = new Table($output);
        $table->setHeaders(['Method', 'Path', 'Handler', 'Policies', 'Auth']);

        foreach ($list as $route) {
            $definition = $route['route'];
            $handler = $definition['handler'] ?? null;
            $config = is_array($definition['config'] ?? null) ? $definition['config'] : [];
            $policies = $config['policies'] ?? [];
            $auth = $config['auth'] ?? null;

            $table->addRow([
                strtoupper($route['method']),
                $route['path'],
                self::describeHandler($handler),
                implode(', ', array_map(self::describePolicy(...), is_array($policies) ? $policies : [])),
                self::describeAuth($auth),
            ]);
        }

        $table->render();

        $app->destroy();

        return Command::SUCCESS;
    }

    private static function describeHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }
        if (is_array($handler)) {
            return implode(' → ', array_map(self::describeHandler(...), $handler));
        }
        if ($handler instanceof \Closure) {
            return 'closure';
        }
        if (is_object($handler)) {
            return (new \ReflectionClass($handler))->getShortName();
        }

        return $handler === null ? '' : get_debug_type($handler);
    }

    private static function describePolicy(mixed $policy): string
    {
        if (is_string($policy)) {
            return $policy;
        }
        if (is_array($policy) && isset($policy['name'])) {
            return (string) $policy['name'];
        }

        return is_callable($policy) ? 'closure' : get_debug_type($policy);
    }

    private static function describeAuth(mixed $auth): string
    {
        if ($auth === false) {
            return 'public';
        }
        if ($auth === null) {
            return '';
        }
        if (is_array($auth)) {
            $scope = $auth['scope'] ?? null;
            $strategies = $auth['strategies'] ?? null;
            $parts = [];
            if (is_array($scope)) {
                $parts[] = 'scope: ' . implode(',', $scope);
            }
            if (is_array($strategies)) {
                $parts[] = 'strategies: ' . implode(',', array_map(static fn (mixed $s): string => is_string($s) ? $s : (is_array($s) ? (string) ($s['name'] ?? '?') : '?'), $strategies));
            }

            return $parts === [] ? 'required' : implode('; ', $parts);
        }

        return (string) json_encode($auth);
    }
}
