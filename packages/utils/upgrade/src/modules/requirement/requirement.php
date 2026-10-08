<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Requirement;

/**
 * Port of packages/utils/upgrade/src/modules/requirement/requirement.ts.
 *
 * @phpstan-import-type TestResult from Types
 * @phpstan-import-type TestContext from Types
 * @phpstan-import-type RequirementTestCallback from Types
 */
final class Requirement
{
    public readonly bool $isRequired;

    /** @var list<Requirement> */
    public array $children = [];

    /** @param RequirementTestCallback|null $testCallback */
    public function __construct(public readonly string $name, public readonly ?\Closure $testCallback, ?bool $isRequired = null)
    {
        $this->isRequired = $isRequired ?? true;
    }

    /** @param RequirementTestCallback|null $testCallback */
    public static function requirementFactory(string $name, ?\Closure $testCallback, ?bool $isRequired = null): self
    {
        return new self($name, $testCallback, $isRequired);
    }

    /** @param list<Requirement> $children */
    public function setChildren(array $children): self
    {
        $this->children = $children;

        return $this;
    }

    public function addChild(Requirement $child): self
    {
        $this->children[] = $child;

        return $this;
    }

    public function asOptional(): self
    {
        return self::requirementFactory($this->name, $this->testCallback, false)->setChildren($this->children);
    }

    public function asRequired(): self
    {
        return self::requirementFactory($this->name, $this->testCallback, true)->setChildren($this->children);
    }

    /**
     * @param TestContext $context
     * @return TestResult
     */
    public function test(array $context): array
    {
        try {
            if ($this->testCallback !== null) {
                ($this->testCallback)($context);
            }

            return ['pass' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['pass' => false, 'error' => $e];
        }
    }
}
