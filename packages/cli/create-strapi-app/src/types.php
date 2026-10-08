<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp;

/**
 * Port of packages/cli/create-strapi-app/src/types.ts: the shapes passed around while a project is
 * generated. `Scope` is an array (passed by value; the functions that changed it upstream return
 * the new scope).
 *
 * Differences from upstream: no `useTypescript` (the server is PHP; the admin customisation files
 * are TypeScript like upstream's default), no `shouldCreateGrowthSsoTrial` (Strapi Cloud is out of
 * scope), plus `composerDependencies` (the `require` of the generated `composer.json`) and
 * `inPlace` (the project directory is the one `composer create-project` made for this package).
 *
 * @phpstan-type DBClient 'mysql'|'postgres'|'sqlite'
 * @phpstan-type PackageManager 'npm'|'yarn'|'pnpm'
 * @phpstan-type DatabaseInfo array{client: DBClient, connection: array<string, string|bool|null>}
 * @phpstan-type Options array{
 *     useNpm: bool, usePnpm: bool, useYarn: bool, quickstart: bool, run: bool,
 *     dbclient: ?string, skipCloud: bool, skipDb: bool,
 *     dbhost: ?string, dbport: ?string, dbname: ?string, dbusername: ?string, dbpassword: ?string,
 *     dbssl: ?string, dbfile: ?string,
 *     template: ?string, typescript: ?bool, javascript: ?bool, install: ?bool, example: ?bool,
 *     gitInit: ?bool, nonInteractive: bool, templateBranch: ?string, templatePath: ?string
 * }
 * @phpstan-type Scope array{
 *     name: string,
 *     rootPath: string,
 *     template: ?string,
 *     templateBranch: ?string,
 *     templatePath: ?string,
 *     strapiVersion: string,
 *     installDependencies: bool,
 *     devDependencies: array<string, string>,
 *     dependencies: array<string, string>,
 *     composerDependencies: array<string, string>,
 *     docker: bool,
 *     packageManager: PackageManager,
 *     runApp: bool,
 *     isQuickstart: bool,
 *     uuid: string,
 *     installId: string,
 *     database: DatabaseInfo,
 *     tmpPath: string,
 *     packageJsonStrapi: array<string, mixed>,
 *     useExample: bool,
 *     gitInit: bool,
 *     pnpmVersion: ?string,
 *     inPlace: bool
 * }
 */
final class Types
{
    public const DB_CLIENTS = ['mysql', 'postgres', 'sqlite'];

    public const PACKAGE_MANAGERS = ['npm', 'yarn', 'pnpm'];
}
