<?php

declare(strict_types=1);

namespace Strapi\Generators;

/**
 * Not an upstream file: the minimal Plop API `generate()` hands to the plopfile upstream (the
 * `plopApi` object of src/index.ts) — `setGenerator`, `setHelper`, `getDestBasePath`,
 * `setWelcomeMessage` — plus the getters the runner needs.
 *
 * A generator is `['description' => string, 'prompts' => list<Question>|callable(Inquirer): array,
 * 'actions' => callable(array $answers): list<Action>]` where an action is
 * `['type' => 'add', 'path' => string, 'templateFile' => string, 'data'?: array, 'skipIfExists'?: bool]`,
 * `['type' => 'modify', 'path' => string, 'transform' => callable(string): string]` or a
 * `callable(array $answers): mixed` (paths and template files are relative to `src/` and to this
 * package's `src/` respectively, and may contain `{{ answers }}`).
 *
 * @phpstan-type Question array{type: 'input'|'confirm'|'list', name: string, message: string, default?: mixed, choices?: mixed, when?: callable(array<string, mixed>): bool, validate?: callable(mixed, array<string, mixed>): (bool|string)}
 * @phpstan-type Generator array{description: string, prompts: list<Question>|callable(Inquirer): array<string, mixed>, actions: callable(array<string, mixed>): list<mixed>}
 */
final class Plop
{
    /** @var array<string, Generator> */
    private array $generators = [];

    /** @var array<string, callable(mixed): mixed> */
    private array $helpers = [];

    private string $welcomeMessage = '';

    public function __construct(private readonly string $destBasePath)
    {
    }

    /** @param Generator $config */
    public function setGenerator(string $name, array $config): void
    {
        $this->generators[$name] = $config;
    }

    /** @param callable(mixed): mixed $fn */
    public function setHelper(string $name, callable $fn): void
    {
        $this->helpers[$name] = $fn;
    }

    public function getDestBasePath(): string
    {
        return $this->destBasePath;
    }

    public function setWelcomeMessage(string $message): void
    {
        $this->welcomeMessage = $message;
    }

    public function getWelcomeMessage(): string
    {
        return $this->welcomeMessage;
    }

    /** @return Generator|null */
    public function getGenerator(string $name): ?array
    {
        return $this->generators[$name] ?? null;
    }

    /** @return array<string, Generator> */
    public function getGenerators(): array
    {
        return $this->generators;
    }

    /** @return array<string, callable(mixed): mixed> */
    public function getHelpers(): array
    {
        return $this->helpers;
    }
}
