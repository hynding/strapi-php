<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops;

use Strapi\Generators\Plop;
use Strapi\Generators\Plops\Utils\Files;
use Strapi\Utils\Primitives\Strings;

/**
 * Not an upstream generator: `strapi generate plugin` scaffolds a strapi-php plugin in
 * `src/plugins/<pluginId>`. Upstream scaffolds plugins with `npx @strapi/sdk-plugin init`; this
 * generator asks the same questions (minus the git/eslint/prettier/editorconfig extras) and
 * creates the same layout, with the server half in PHP:
 *
 * - `composer.json` (`extra.strapi.kind = "plugin"`, `server = "strapi-server.php"`), `strapi-server.php`,
 *   `server/src/{index,register,bootstrap,destroy}.php`, `config/`, `content-types/`,
 *   `controllers/`, `middlewares/`, `policies/`, `routes/{content-api,admin}/`, `services/` — the
 *   sdk-plugin server template, file for file;
 * - with the admin panel (the admin half stays JS, built by `@strapi/sdk-plugin`): `package.json`
 *   (an `./strapi-admin` export, `strapi.kind = "plugin"`) and sdk-plugin's `admin/` template,
 *   TypeScript or JavaScript;
 * - `README.md` and `.gitignore`.
 *
 * The layout matches examples/plugins/announcements (a dual-runtime plugin) for the PHP half.
 */
final class Plugin
{
    /** sdk-plugin's PLUGIN_ID_REGEXP */
    public const string PLUGIN_ID_REGEXP = '/^[a-z0-9][a-z0-9-_]*$/';

    /** sdk-plugin's PACKAGE_NAME_REGEXP (npm) */
    public const string PACKAGE_NAME_REGEXP = '/^(?:@(?:[a-z0-9-*~][a-z0-9-*._~]*)\/)?[a-z0-9-~][a-z0-9-._~]*$/i';

    /** Composer's package name pattern */
    public const string COMPOSER_NAME_REGEXP = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    private const array SERVER_FILES = [
        'strapi-server.php',
        'server/src/index.php',
        'server/src/register.php',
        'server/src/bootstrap.php',
        'server/src/destroy.php',
        'server/src/config/index.php',
        'server/src/content-types/index.php',
        'server/src/controllers/index.php',
        'server/src/controllers/controller.php',
        'server/src/middlewares/index.php',
        'server/src/policies/index.php',
        'server/src/routes/index.php',
        'server/src/routes/content-api/index.php',
        'server/src/routes/admin/index.php',
        'server/src/services/index.php',
        'server/src/services/service.php',
    ];

    private const array ADMIN_TS_FILES = [
        'src/index.ts',
        'src/pluginId.ts',
        'src/components/PluginIcon.tsx',
        'src/components/Initializer.tsx',
        'src/pages/App.tsx',
        'src/pages/HomePage.tsx',
        'src/utils/getTranslation.ts',
        'src/translations/en.json',
        'custom.d.ts',
        'tsconfig.json',
        'tsconfig.build.json',
    ];

    private const array ADMIN_JS_FILES = [
        'src/index.js',
        'src/pluginId.js',
        'src/components/PluginIcon.jsx',
        'src/components/Initializer.jsx',
        'src/pages/App.jsx',
        'src/pages/HomePage.jsx',
        'src/utils/getTranslation.js',
        'src/translations/en.json',
        'jsconfig.json',
    ];

    public function __invoke(Plop $plop): void
    {
        $plop->setGenerator('plugin', [
            'description' => 'Generate a plugin (PHP server, JS admin)',
            'prompts' => [
                [
                    'type' => 'input',
                    'name' => 'pluginId',
                    'message' => 'plugin id (used by Strapi)',
                    'validate' => static function (mixed $val) use ($plop): bool|string {
                        if (!is_string($val) || $val === '') {
                            return 'plugin id is required';
                        }
                        if (preg_match(self::PLUGIN_ID_REGEXP, $val) !== 1) {
                            return 'plugin id must match /^[a-z0-9][a-z0-9-_]*$/';
                        }
                        if (file_exists($plop->getDestBasePath() . "/plugins/{$val}")) {
                            return "src/plugins/{$val} already exists";
                        }

                        return true;
                    },
                ],
                [
                    'type' => 'input',
                    'name' => 'composerName',
                    'message' => 'composer package name',
                    'default' => static fn (array $answers): string => 'strapi-plugin/' . strtolower((string) ($answers['pluginId'] ?? '')),
                    'validate' => static fn (mixed $val): bool|string => is_string($val) && preg_match(self::COMPOSER_NAME_REGEXP, $val) === 1 ? true : 'invalid composer package name (vendor/name)',
                ],
                [
                    'type' => 'input',
                    'name' => 'displayName',
                    'message' => 'plugin display name',
                    'default' => '',
                ],
                [
                    'type' => 'input',
                    'name' => 'description',
                    'message' => 'plugin description',
                    'default' => '',
                ],
                [
                    'type' => 'input',
                    'name' => 'authorName',
                    'message' => 'plugin author name',
                    'default' => '',
                ],
                [
                    'type' => 'input',
                    'name' => 'authorEmail',
                    'message' => 'plugin author email',
                    'default' => '',
                ],
                [
                    'type' => 'input',
                    'name' => 'license',
                    'message' => 'plugin license',
                    'default' => 'MIT',
                    'validate' => static fn (mixed $val): bool|string => is_string($val) && $val !== '' ? true : 'license is required',
                ],
                [
                    'type' => 'confirm',
                    'name' => 'client-code',
                    'message' => 'register with the admin panel?',
                    'default' => true,
                ],
                [
                    'when' => static fn (array $answers): bool => (bool) ($answers['client-code'] ?? false),
                    'type' => 'input',
                    'name' => 'pkgName',
                    'message' => 'npm package name (admin)',
                    'default' => static fn (array $answers): string => (string) ($answers['pluginId'] ?? ''),
                    'validate' => static fn (mixed $val): bool|string => is_string($val) && preg_match(self::PACKAGE_NAME_REGEXP, $val) === 1 ? true : 'invalid package name',
                ],
                [
                    'when' => static fn (array $answers): bool => (bool) ($answers['client-code'] ?? false),
                    'type' => 'confirm',
                    'name' => 'typescript',
                    'message' => 'Use TypeScript?',
                    'default' => true,
                ],
            ],
            'actions' => static function (array $answers): array {
                if ($answers === []) {
                    return [];
                }

                $pluginId = (string) $answers['pluginId'];
                $base = "plugins/{$pluginId}";
                $data = [
                    'pluginId' => $pluginId,
                    'title' => ($answers['displayName'] ?? '') !== '' ? $answers['displayName'] : $pluginId,
                    'description' => (string) ($answers['description'] ?? ''),
                    'composerJson' => Files::stringify(self::composerJson($answers)),
                ];

                $actions = [];
                $add = static function (string $template, string $path, array $extra = []) use (&$actions, $base, $data): void {
                    $actions[] = [
                        'type' => 'add',
                        'path' => "{$base}/{$path}",
                        'templateFile' => "templates/php/plugin-skeleton/{$template}.tpl",
                        'data' => [...$data, ...$extra],
                    ];
                };

                $add('composer.json', 'composer.json');
                foreach (self::SERVER_FILES as $file) {
                    $add($file, $file);
                }

                if (($answers['client-code'] ?? false) === true) {
                    $typescript = ($answers['typescript'] ?? true) === true;
                    $add('package.json', 'package.json', ['packageJson' => Files::stringify(self::packageJson($answers, $typescript))]);
                    foreach ($typescript ? self::ADMIN_TS_FILES : self::ADMIN_JS_FILES as $file) {
                        $add(($typescript ? 'admin-ts/' : 'admin-js/') . $file, "admin/{$file}");
                    }
                }

                $add('README.md', 'README.md');
                $add('gitignore', '.gitignore');

                $actions[] = static fn (): string => implode("\n", [
                    "Plugin \"{$pluginId}\" created in src/plugins/{$pluginId}. Enable it in config/plugins.php:",
                    '',
                    "  '{$pluginId}' => [",
                    "      'enabled' => true,",
                    "      'resolve' => './src/plugins/{$pluginId}',",
                    '  ],',
                    ...(($answers['client-code'] ?? false) === true ? ['', "Then build its admin: cd src/plugins/{$pluginId} && npm install && npm run build"] : []),
                ]);

                return $actions;
            },
        ]);
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    public static function composerJson(array $answers): array
    {
        $pluginId = (string) $answers['pluginId'];
        $strapi = ['kind' => 'plugin', 'name' => $pluginId];
        if (($answers['displayName'] ?? '') !== '') {
            $strapi['displayName'] = $answers['displayName'];
        }
        if (($answers['description'] ?? '') !== '') {
            $strapi['description'] = $answers['description'];
        }
        $strapi['server'] = 'strapi-server.php';
        $strapi['namespace'] = 'StrapiPlugin\\' . Strings::upperFirst(Strings::camelCase($pluginId));

        $json = [
            'name' => $answers['composerName'] ?? "strapi-plugin/{$pluginId}",
            'description' => (string) ($answers['description'] ?? ''),
            'type' => 'library',
            'license' => (string) ($answers['license'] ?? 'MIT'),
        ];

        $author = array_filter(['name' => $answers['authorName'] ?? '', 'email' => $answers['authorEmail'] ?? ''], static fn (mixed $v): bool => $v !== '');
        if ($author !== []) {
            $json['authors'] = [$author];
        }

        return [
            ...$json,
            'require' => [
                'php' => '>=8.3',
                'strapi/core' => '^5.0',
            ],
            'autoload' => ['classmap' => ['server/src/']],
            'extra' => ['strapi' => $strapi],
        ];
    }

    /**
     * sdk-plugin's package.json for a plugin with an admin part only (the server is PHP).
     *
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    public static function packageJson(array $answers, bool $typescript): array
    {
        $pluginId = (string) $answers['pluginId'];
        $adminExport = [
            'source' => $typescript ? './admin/src/index.ts' : './admin/src/index.js',
            'import' => './dist/admin/index.mjs',
            'require' => './dist/admin/index.js',
            'default' => './dist/admin/index.js',
        ];
        if ($typescript) {
            $adminExport = ['types' => './dist/admin/src/index.d.ts', ...$adminExport];
        }

        $scripts = [
            'build' => 'strapi-plugin build',
            'watch' => 'strapi-plugin watch',
            'watch:link' => 'strapi-plugin watch:link',
            'verify' => 'strapi-plugin verify',
        ];
        if ($typescript) {
            $scripts['test:ts:front'] = 'tsc -p admin/tsconfig.json';
        }

        $devDependencies = [
            '@strapi/strapi' => '^5.0.0',
            '@strapi/sdk-plugin' => '^6.0.0',
            'prettier' => '*',
            '@strapi/design-system' => '^2.0.0',
            '@strapi/icons' => '^2.0.0',
            'react-intl' => '^6.0.0',
            'react' => '^18.0.0',
            'react-dom' => '^18.0.0',
            'react-router-dom' => '^6.0.0',
            'styled-components' => '^6.0.0',
        ];
        if ($typescript) {
            $devDependencies = [
                ...$devDependencies,
                '@types/react' => '^18.0.0',
                '@types/react-dom' => '^18.0.0',
                '@strapi/typescript-utils' => '^5',
                'typescript' => '^5',
            ];
        }

        $strapi = ['kind' => 'plugin', 'name' => $pluginId];
        if (($answers['displayName'] ?? '') !== '') {
            $strapi['displayName'] = $answers['displayName'];
        }
        if (($answers['description'] ?? '') !== '') {
            $strapi['description'] = $answers['description'];
        }

        $author = trim(implode(' ', array_filter([
            (string) ($answers['authorName'] ?? ''),
            ($answers['authorEmail'] ?? '') !== '' ? "<{$answers['authorEmail']}>" : '',
        ])));

        return [
            'name' => (string) ($answers['pkgName'] ?? $pluginId),
            'version' => '0.0.0',
            'description' => (string) ($answers['description'] ?? ''),
            'keywords' => [],
            'license' => (string) ($answers['license'] ?? 'MIT'),
            'author' => $author,
            'type' => 'commonjs',
            'exports' => [
                './package.json' => './package.json',
                './strapi-admin' => $adminExport,
            ],
            'files' => ['dist'],
            'scripts' => $scripts,
            'dependencies' => new \stdClass(),
            'devDependencies' => $devDependencies,
            'peerDependencies' => [
                '@strapi/strapi' => '^5.0.0',
                '@strapi/sdk-plugin' => '^6.0.0',
                '@strapi/design-system' => '^2.0.0',
                '@strapi/icons' => '^2.0.0',
                'react-intl' => '^6.0.0',
                'react' => '^18.0.0',
                'react-dom' => '^18.0.0',
                'react-router-dom' => '^6.0.0',
                'styled-components' => '^6.0.0',
            ],
            'strapi' => $strapi,
        ];
    }
}
