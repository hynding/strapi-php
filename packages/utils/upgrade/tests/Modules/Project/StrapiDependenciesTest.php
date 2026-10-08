<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Project;

use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Project\StrapiDependencies;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/project/__tests__/strapi-dependencies.test.ts, plus the Composer counterparts. */
final class StrapiDependenciesTest extends TestCase
{
    public function testIsPinnedSemVer(): void
    {
        self::assertTrue(StrapiDependencies::isPinnedSemVer('4.26.1'));
        self::assertTrue(StrapiDependencies::isPinnedSemVer('5.0.0'));

        self::assertFalse(StrapiDependencies::isPinnedSemVer('^4.26.1'));
        self::assertFalse(StrapiDependencies::isPinnedSemVer('~4.26.1'));
        self::assertFalse(StrapiDependencies::isPinnedSemVer('>=4.26.1 <5.0.0'));
    }

    public function testIsPinnedComposerVersion(): void
    {
        self::assertTrue(StrapiDependencies::isPinnedComposerVersion('5.56.0'));
        self::assertTrue(StrapiDependencies::isPinnedComposerVersion('5.56.0.1'));
        self::assertTrue(StrapiDependencies::isPinnedComposerVersion('5.56.0-beta.1'));

        self::assertFalse(StrapiDependencies::isPinnedComposerVersion('^5.56'));
        self::assertFalse(StrapiDependencies::isPinnedComposerVersion('v5.56.0'));
        self::assertFalse(StrapiDependencies::isPinnedComposerVersion('5.56.*'));
    }

    public function testFindUnpinnedStrapiDependencies(): void
    {
        $unpinned = StrapiDependencies::findUnpinnedStrapiDependencies(
            ['@strapi/strapi' => '^4.26.1', '@strapi/plugin-users-permissions' => '4.26.1', 'lodash' => '^4.17.21'],
            ['@strapi/types' => '~4.26.1'],
        );

        self::assertSame([
            ['name' => '@strapi/strapi', 'declaredVersion' => '^4.26.1', 'section' => 'dependencies'],
            ['name' => '@strapi/types', 'declaredVersion' => '~4.26.1', 'section' => 'devDependencies'],
        ], $unpinned);

        self::assertSame([], StrapiDependencies::findUnpinnedStrapiDependencies(['@strapi/strapi' => '4.26.1'], ['@strapi/types' => '4.26.1']));
    }

    public function testFindUnpinnedComposerStrapiDependencies(): void
    {
        $unpinned = StrapiDependencies::findUnpinnedComposerStrapiDependencies(['php' => '>=8.3', 'strapi/strapi' => '^5.56', 'strapi/plugin-graphql' => '5.56.0-beta.1'], null);

        self::assertSame([['name' => 'strapi/strapi', 'declaredVersion' => '^5.56', 'section' => 'require']], $unpinned);
    }

    public function testPinStrapiDependenciesPinsOnlyTheListedPackages(): void
    {
        $packageJSON = [
            'name' => 'test-app',
            'version' => '0.1.0',
            'dependencies' => ['@strapi/strapi' => '^4.26.1', '@strapi/plugin-users-permissions' => '4.26.1'],
            'devDependencies' => ['@strapi/types' => '~4.26.1'],
        ];

        $unpinned = StrapiDependencies::findUnpinnedStrapiDependencies($packageJSON['dependencies'], $packageJSON['devDependencies']);
        $updated = StrapiDependencies::pinStrapiDependencies($packageJSON, '4.26.1', $unpinned);

        self::assertSame(['@strapi/strapi' => '4.26.1', '@strapi/plugin-users-permissions' => '4.26.1'], $updated['dependencies']);
        self::assertSame(['@strapi/types' => '4.26.1'], $updated['devDependencies']);
    }

    public function testPinComposerStrapiDependenciesKeepsEmptySectionsObjects(): void
    {
        $updated = StrapiDependencies::pinComposerStrapiDependencies(['require' => ['strapi/strapi' => '^5.56']], '5.56.0', [['name' => 'strapi/strapi', 'declaredVersion' => '^5.56', 'section' => 'require']]);

        self::assertSame(['strapi/strapi' => '5.56.0'], $updated['require']);
        self::assertEquals(new \stdClass(), $updated['require-dev']);
    }

    public function testGetStrapiPinTargetVersionUsesTheDeclaredFloor(): void
    {
        $cwd = $this->volume(self::appTree('^4.26.1', extra: ['vendor' => ['composer' => ['installed.json' => '[{"name": "strapi/strapi", "version": "4.26.2"}]']]]));
        $project = new AppProject($cwd);

        self::assertSame('4.26.2', $project->strapiVersion->raw);
        self::assertSame('4.26.1', StrapiDependencies::getStrapiPinTargetVersion($project)->raw);
    }

    public function testComposerConstraintToRange(): void
    {
        self::assertSame('>=5.56 <6.0', StrapiDependencies::composerConstraintToRange('>=5.56, <6.0'));
        self::assertSame('^5.56', StrapiDependencies::composerConstraintToRange('^5.56@beta'));
    }
}
