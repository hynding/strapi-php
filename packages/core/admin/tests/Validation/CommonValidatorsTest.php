<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Validation\CommonValidators;
use Strapi\Utils\Yup\YupError;

/**
 * Port of server/src/validation/__tests__/common-validators.test.ts: the `permission` schema's
 * `action-validity` test, with the action provider stubbed through {@see CommonValidators::$serviceResolver}.
 */
final class CommonValidatorsTest extends TestCase
{
    protected function tearDown(): void
    {
        CommonValidators::$serviceResolver = null;
    }

    /** @param \Closure(string): mixed $get */
    private static function stubActionProvider(\Closure $get): void
    {
        $actionProvider = new class ($get) {
            public function __construct(private readonly \Closure $get)
            {
            }

            public function get(string $actionId): mixed
            {
                return ($this->get)($actionId);
            }
        };
        $permission = new class ($actionProvider) {
            public function __construct(public readonly object $actionProvider)
            {
            }
        };
        CommonValidators::$serviceResolver = static fn (string $name): object => $permission;
    }

    /** @return list<string> */
    private static function errorsOf(mixed $value): array
    {
        try {
            CommonValidators::permission()->validate($value, ['strict' => true, 'abortEarly' => false]);

            return [];
        } catch (YupError $e) {
            return $e->errors;
        }
    }

    public function testAcceptsAKnownAction(): void
    {
        self::stubActionProvider(static fn (string $actionId): array => ['actionId' => $actionId, 'subjects' => null]);

        self::assertSame([], self::errorsOf(['action' => 'admin::marketplace.read']));
    }

    public function testRejectsAnUnknownAction(): void
    {
        self::stubActionProvider(static fn (): mixed => null);

        self::assertContains('action is not an existing permission action', self::errorsOf(['action' => 'admin::does.not.exist']));
    }

    public function testDefersNilActionToRequired(): void
    {
        self::stubActionProvider(static fn (): mixed => null);

        $errors = self::errorsOf([]);

        self::assertContains('action is a required field', $errors);
        self::assertNotContains('action is not an existing permission action', $errors);
    }
}
