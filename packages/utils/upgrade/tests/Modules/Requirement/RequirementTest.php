<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Requirement;

use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Requirement\Requirement;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/requirement/__tests__/requirement.test.ts. */
final class RequirementTest extends TestCase
{
    private \Closure $testCallback;

    private Requirement $requirement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testCallback = static function (array $context): void {
        };
        $this->requirement = new Requirement('testRequirement', $this->testCallback);
    }

    /** @return array{target: SemVer, npmVersionsMatches: list<array<string, mixed>>, project: AppProject} */
    private function context(): array
    {
        $cwd = $this->volume(self::appTree('5.56.0'));

        return ['target' => new SemVer('5.57.0'), 'npmVersionsMatches' => [], 'project' => new AppProject($cwd)];
    }

    public function testConstructorSetsProperties(): void
    {
        self::assertSame('testRequirement', $this->requirement->name);
        self::assertSame($this->testCallback, $this->requirement->testCallback);
        self::assertTrue($this->requirement->isRequired);
        self::assertFalse((new Requirement('x', null, false))->isRequired);
    }

    public function testSetChildrenAndAddChild(): void
    {
        $child = new Requirement('child', null);

        self::assertSame([$child], $this->requirement->setChildren([$child])->children);
        self::assertSame([$child, $child], $this->requirement->addChild($child)->children);
    }

    public function testAsOptionalAndAsRequiredCreateNewInstances(): void
    {
        $optional = $this->requirement->asOptional();
        self::assertFalse($optional->isRequired);
        self::assertNotSame($this->requirement, $optional);

        $required = $optional->asRequired();
        self::assertTrue($required->isRequired);
        self::assertNotSame($optional, $required);
    }

    public function testTestPassesWhenTheCallbackPasses(): void
    {
        self::assertSame(['pass' => true, 'error' => null], $this->requirement->test($this->context()));
    }

    public function testTestReturnsTheErrorWhenTheCallbackThrows(): void
    {
        $error = new \RuntimeException('Test error');
        $requirement = new Requirement('x', static function () use ($error): void {
            throw $error;
        });

        self::assertSame(['pass' => false, 'error' => $error], $requirement->test($this->context()));
    }

    public function testRequirementFactory(): void
    {
        $requirement = Requirement::requirementFactory('testRequirement', $this->testCallback, false);

        self::assertInstanceOf(Requirement::class, $requirement);
        self::assertSame('testRequirement', $requirement->name);
        self::assertFalse($requirement->isRequired);
    }
}
