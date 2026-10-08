<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Strapi\Generators\ConsoleInquirer;
use Strapi\Generators\Generators;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/generate.ts: `$ strapi generate`.
 *
 * Upstream hands the remaining argv to plop's CLI (`@strapi/generators` `runCLI()`); here the
 * same runner is {@see Generators::runCLI()} with symfony/console's question helper. Plop's
 * bypass syntax is kept:
 *
 * ```
 * strapi generate                                # pick a generator, answer its questions
 * strapi generate api                            # run the api generator
 * strapi generate api my-api false               # positional answers, in prompt order (`_` = ask)
 * strapi generate controller -- --id=hello --destination=api --api=article
 * strapi generate content-type -n -- --displayName=Article --singularName=article \
 *     --pluralName=articles --kind=collectionType --attributes=title:string --destination=new \
 *     --id=article --bootstrapApi=true
 * ```
 *
 * With `--no-interaction` a question that has no answer takes its default, or fails.
 */
final class Generate extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('generate')
            ->setDescription('Launch the interactive API generator')
            ->addArgument('generator', InputArgument::OPTIONAL, 'api, controller, content-type, policy, middleware, migration, service or plugin')
            ->addArgument('answers', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Answers to the prompts: positional (in prompt order), or --<name>=<value> after --');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $generator = $input->getArgument('generator');
        $answers = $input->getArgument('answers');
        [$positional, $named] = self::parseAnswers(is_array($answers) ? array_values(array_map('strval', $answers)) : []);

        $helper = $this->getHelperSet()?->has('question') ? $this->getHelper('question') : new QuestionHelper();
        if (!$helper instanceof QuestionHelper) {
            $helper = new QuestionHelper();
        }

        $inquirer = new ConsoleInquirer($input, $output, $helper, $named);

        Generators::runCLI(
            $inquirer,
            $this->ctx->cwd,
            is_string($generator) ? $generator : null,
            $positional,
            static function (string $line) use ($output): void {
                $output->writeln($line);
            },
        );

        return Command::SUCCESS;
    }

    /**
     * Splits plop-style answers into positional ones and `--name=value` / `--name value` ones.
     *
     * @param list<string> $tokens
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function parseAnswers(array $tokens): array
    {
        $positional = [];
        $named = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '--') {
                continue;
            }
            if (!str_starts_with($token, '--')) {
                $positional[] = $token;
                continue;
            }

            $option = substr($token, 2);
            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                $named[$name] = $value;
            } elseif ($i + 1 < $count && !str_starts_with($tokens[$i + 1], '--')) {
                $named[$option] = $tokens[++$i];
            } else {
                $named[$option] = 'true';
            }
        }

        return [$positional, $named];
    }
}
