<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/console.ts: `$ strapi console`.
 *
 * Node's REPL becomes a readline/eval loop with the loaded application available as `$strapi`
 * (and `$app`). Upstream also starts the HTTP server before opening the REPL; the PHP server
 * runs in a child process, so the console only loads the application (database included).
 */
final class Console extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('console')->setDescription('Open the Strapi framework console');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->createStrapi()->load();

        $name = $app->config()->get('info.name');
        $prompt = (is_string($name) && $name !== '' ? $name : 'strapi') . ' > ';

        $output->writeln('<info>Strapi console. `$strapi` is the application; `exit` or Ctrl-D leaves.</info>');

        $code = self::repl($prompt, $app, $output);

        $app->destroy();

        return $code;
    }

    private static function repl(string $prompt, \Strapi\Core\Strapi $strapi, OutputInterface $output): int
    {
        $app = $strapi; // alias available in the eval scope
        $useReadline = function_exists('readline');

        while (true) {
            if ($useReadline) {
                $line = readline($prompt);
                if ($line === false) {
                    $output->writeln('');

                    return Command::SUCCESS;
                }
                if (trim($line) !== '') {
                    readline_add_history($line);
                }
            } else {
                $output->write($prompt);
                $line = fgets(STDIN);
                if ($line === false) {
                    return Command::SUCCESS;
                }
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (in_array($line, ['exit', 'exit;', 'quit', '.exit'], true)) {
                return Command::SUCCESS;
            }

            $code = rtrim($line, ';');
            // expressions are echoed like a REPL; statements run as they are
            $isStatement = preg_match('/^(\$[a-zA-Z_][\w]*\s*=[^=]|if\b|for\b|foreach\b|while\b|function\b|echo\b|print\b|unset\b|return\b|use\b|try\b|throw\b|class\b)/', $code) === 1;

            try {
                $result = eval($isStatement ? $code . ';' : 'return ' . $code . ';');
                if (!$isStatement) {
                    $output->writeln(self::export($result));
                }
            } catch (\Throwable $e) {
                $output->writeln('<error>' . get_class($e) . ': ' . $e->getMessage() . '</error>');
            }
        }
    }

    private static function export(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_object($value) && !$value instanceof \JsonSerializable && !$value instanceof \Stringable) {
            return get_class($value) . ' {#' . spl_object_id($value) . '}';
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : var_export($value, true);
    }
}
