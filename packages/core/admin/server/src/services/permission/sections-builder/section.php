<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\SectionsBuilder;

use Strapi\Utils\Hooks;
use Strapi\Utils\Hooks\AsyncParallelHook;
use Strapi\Utils\Hooks\AsyncSeriesHook;

/**
 * Port of server/src/services/permission/sections-builder/section.ts (`createSection(options)`).
 *
 * Handlers are called with `['action' => $action, 'section' => &$section]`: the `section` entry is a
 * reference to the section being built, so a handler mutates it through its (copied) context
 * argument (`$ctx['section'][] = ...`), as upstream handlers mutate the section object.
 *
 * @phpstan-type SectionOptions array{initialStateFactory?: callable(): mixed, handlers?: list<callable>, matchers?: list<callable>}
 */
final class Section
{
    /** @var array{handlers: AsyncSeriesHook, matchers: AsyncParallelHook} */
    public readonly array $hooks;

    /** @var \Closure(): mixed */
    private readonly \Closure $initialStateFactory;

    /** @param SectionOptions $options */
    public function __construct(array $options = [])
    {
        $initialStateFactory = $options['initialStateFactory'] ?? null;
        $this->initialStateFactory = $initialStateFactory !== null ? $initialStateFactory(...) : static fn (): array => [];

        $this->hooks = [
            'handlers' => Hooks::createAsyncSeriesHook(),
            'matchers' => Hooks::createAsyncParallelHook(),
        ];

        // Register initial hooks
        foreach ($options['handlers'] ?? [] as $handler) {
            $this->hooks['handlers']->register($handler);
        }
        foreach ($options['matchers'] ?? [] as $matcher) {
            $this->hooks['matchers']->register($matcher);
        }
    }

    /** @param SectionOptions $options */
    public static function createSection(array $options = []): self
    {
        return new self($options);
    }

    /**
     * Verifies if an action can be applied to the section by running the matchers hook.
     * If any of the registered matcher functions returns true, then the condition applies.
     *
     * @param array<string, mixed> $action
     */
    public function appliesToAction(array $action): bool
    {
        $results = $this->hooks['matchers']->call($action);

        return in_array(true, $results, true);
    }

    /**
     * Init, build and returns a section object based on the given actions.
     *
     * @param list<array<string, mixed>> $actions
     */
    public function build(array $actions = []): mixed
    {
        $section = ($this->initialStateFactory)();

        foreach ($actions as $action) {
            if ($this->appliesToAction($action)) {
                $this->hooks['handlers']->call(['action' => $action, 'section' => &$section]);
            }
        }

        return $section;
    }
}
