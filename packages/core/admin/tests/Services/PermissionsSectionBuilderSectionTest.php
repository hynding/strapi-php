<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Permission\SectionsBuilder\Section;

/** Port of server/src/services/__tests__/permissions.section-builder.section.test.ts. */
final class PermissionsSectionBuilderSectionTest extends TestCase
{
    public function testCreatesASectionWithTheCorrectProperties(): void
    {
        $section = Section::createSection();

        self::assertArrayHasKey('handlers', $section->hooks);
        self::assertArrayHasKey('matchers', $section->hooks);
        self::assertTrue(method_exists($section, 'appliesToAction'));
        self::assertTrue(method_exists($section, 'build'));
    }

    public function testHandlersCanBeRegisteredOnInit(): void
    {
        $called = [];
        $section = Section::createSection([
            'handlers' => [static function () use (&$called): void {
                $called[] = 'handler';
            }],
            'matchers' => [static function () use (&$called): void {
                $called[] = 'matcher';
            }],
        ]);

        $section->hooks['matchers']->call([]);
        $section->hooks['handlers']->call([]);

        self::assertSame(['matcher', 'handler'], $called);
    }

    public function testHandlersCanBeRegisteredAfterInit(): void
    {
        $called = [];
        $section = Section::createSection();
        $section->hooks['handlers']->register(static function () use (&$called): void {
            $called[] = 'handler';
        });
        $section->hooks['matchers']->register(static function () use (&$called): void {
            $called[] = 'matcher';
        });

        $section->hooks['matchers']->call([]);
        $section->hooks['handlers']->call([]);

        self::assertSame(['matcher', 'handler'], $called);
    }

    public function testAppliesToActionIsFalseWithoutMatchers(): void
    {
        self::assertFalse(Section::createSection()->appliesToAction([]));
    }

    public function testAppliesToActionIsTrueIfOneMatcherReturnsTrue(): void
    {
        $action = ['foo' => 'bar'];
        $received = [];
        $section = Section::createSection(['matchers' => [
            static function (array $a) use (&$received): bool {
                $received[] = $a;

                return ($a['foo'] ?? null) === 'bar';
            },
            static function (array $a) use (&$received): bool {
                $received[] = $a;

                return ($a['bar'] ?? null) === 'foo';
            },
        ]]);

        self::assertTrue($section->appliesToAction($action));
        self::assertSame([$action, $action], $received);
    }

    public function testAppliesToActionIsFalseIfNoMatcherReturnsTrue(): void
    {
        $action = ['foo' => 'bar'];
        $section = Section::createSection(['matchers' => [
            static fn (array $a): bool => ($a['foo'] ?? null) === 'foo',
            static fn (array $a): bool => ($a['bar'] ?? null) === 'foo',
        ]]);

        self::assertFalse($section->appliesToAction($action));
    }

    public function testBuildWithoutActionsReturnsInitialState(): void
    {
        $calls = 0;
        $section = Section::createSection(['initialStateFactory' => static function () use (&$calls): array {
            $calls++;

            return ['foo' => 'bar'];
        }]);

        self::assertSame(['foo' => 'bar'], $section->build());
        self::assertSame(1, $calls);
    }

    public function testBuildWithoutMatchersReturnsInitialState(): void
    {
        $section = Section::createSection(['initialStateFactory' => static fn (): array => ['foo' => 'bar']]);

        self::assertSame(['foo' => 'bar'], $section->build([['foo' => 'bar'], ['bar' => 'foo']]));
    }

    public function testBuildWithoutHandlersReturnsInitialState(): void
    {
        $matcherCalls = 0;
        $section = Section::createSection([
            'initialStateFactory' => static fn (): array => ['foo' => 'bar'],
            'matchers' => [static function () use (&$matcherCalls): bool {
                $matcherCalls++;

                return true;
            }],
        ]);

        self::assertSame(['foo' => 'bar'], $section->build([['foo' => 'bar'], ['bar' => 'foo']]));
        self::assertSame(2, $matcherCalls);
    }

    public function testBuildWithHandlers(): void
    {
        $matcherCalls = 0;
        $section = Section::createSection([
            'initialStateFactory' => static fn (): array => ['foo' => 'bar'],
            'matchers' => [static function () use (&$matcherCalls): bool {
                $matcherCalls++;

                return true;
            }],
            'handlers' => [
                static function (array $ctx): void {
                    $ctx['section']['foobar'] = 1;
                },
                static function (array $ctx): void {
                    $ctx['section']['barfoo'] = 2;
                },
            ],
        ]);

        self::assertSame(['foo' => 'bar', 'foobar' => 1, 'barfoo' => 2], $section->build([['foo' => 'bar'], ['bar' => 'foo']]));
        self::assertSame(2, $matcherCalls);
    }
}
