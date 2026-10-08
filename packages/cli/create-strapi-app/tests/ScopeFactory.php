<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests;

use Strapi\CreateStrapiApp\Types;
use Strapi\CreateStrapiApp\Utils\ComposerJson;

/**
 * @phpstan-import-type Scope from Types
 * @phpstan-import-type Options from Types
 */
final class ScopeFactory
{
    /**
     * @param array<string, mixed> $overrides
     * @return Scope
     */
    public static function scope(array $overrides = []): array
    {
        /** @var Scope */
        return [
            'name' => 'my-project',
            'rootPath' => sys_get_temp_dir() . '/my-project',
            'template' => null,
            'templateBranch' => null,
            'templatePath' => null,
            'strapiVersion' => '5.56.0',
            'installDependencies' => false,
            'devDependencies' => [],
            'dependencies' => [],
            'composerDependencies' => ComposerJson::strapiDependencies('5.56.0'),
            'docker' => false,
            'packageManager' => 'npm',
            'runApp' => false,
            'isQuickstart' => false,
            'uuid' => 'uuid',
            'installId' => 'install-id',
            'database' => ['client' => 'sqlite', 'connection' => ['filename' => '.tmp/data.db']],
            'tmpPath' => sys_get_temp_dir() . '/strapi-tmp',
            'packageJsonStrapi' => [],
            'useExample' => false,
            'gitInit' => false,
            'pnpmVersion' => null,
            'inPlace' => false,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return Options
     */
    public static function options(array $overrides = []): array
    {
        /** @var Options */
        return [
            'useNpm' => false, 'usePnpm' => false, 'useYarn' => false, 'quickstart' => false, 'run' => true,
            'dbclient' => null, 'skipCloud' => false, 'skipDb' => false,
            'dbhost' => null, 'dbport' => null, 'dbname' => null, 'dbusername' => null, 'dbpassword' => null,
            'dbssl' => null, 'dbfile' => null,
            'template' => null, 'typescript' => null, 'javascript' => null, 'install' => null, 'example' => null,
            'gitInit' => null, 'nonInteractive' => false, 'templateBranch' => null, 'templatePath' => null,
            ...$overrides,
        ];
    }
}
