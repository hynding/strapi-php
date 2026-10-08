<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Codemods;

use Strapi\Upgrade\Modules\CodemodRepository\Constants;
use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Modules\Json\JSONTransformAPI;
use Strapi\Upgrade\Modules\Runner\Code\TransformAPI;
use Strapi\Upgrade\Modules\Runner\Json\Transform;
use Strapi\Upgrade\Tests\TestCase;

/** PHP-only: the bundled codemods (resources/codemods), on strapi-php sources. */
final class CodemodsTest extends TestCase
{
    private static function codemod(string $file): mixed
    {
        return Transform::load(Constants::internalCodemodsDirectory() . "/5.0.0/{$file}");
    }

    /** @param array<string, mixed> $json */
    private static function json(string $codemod, array $json, string $path = '/app/package.json'): mixed
    {
        $transform = self::codemod($codemod);
        self::assertIsCallable($transform);

        return $transform(['path' => $path, 'json' => $json], ['cwd' => '/app', 'json' => JSONTransformAPI::createJSONTransformAPI(...)]);
    }

    private function code(string $codemod, string $relative, string $source): string
    {
        $cwd = $this->volume([$relative => $source]);
        $transform = self::codemod($codemod);
        self::assertIsCallable($transform);

        return $transform(['path' => "{$cwd}/{$relative}", 'source' => $source], new TransformAPI($cwd), []);
    }

    public function testDependencyRemoveStrapiPluginI18n(): void
    {
        self::assertSame(['dependencies' => ['@strapi/strapi' => '5.0.0']], self::json('dependency-remove-strapi-plugin-i18n.json.php', ['dependencies' => ['@strapi/strapi' => '5.0.0', '@strapi/plugin-i18n' => '4.25.0']]));
        // only the root package.json
        self::assertSame(['dependencies' => ['@strapi/plugin-i18n' => '4']], self::json('dependency-remove-strapi-plugin-i18n.json.php', ['dependencies' => ['@strapi/plugin-i18n' => '4']], '/app/src/x.json'));
    }

    public function testDependencyUpgradeReactAndReactDom(): void
    {
        $file = 'dependency-upgrade-react-and-react-dom.json.php';

        self::assertSame(['dependencies' => ['react' => '^18.0.0', 'react-dom' => '^18.0.0']], self::json($file, ['dependencies' => ['react' => '^17.0.2', 'react-dom' => '^17.0.2']]));
        self::assertSame(['dependencies' => ['react' => '18.3.1', 'react-dom' => '18.3.1']], self::json($file, ['dependencies' => ['react' => '18.3.1', 'react-dom' => '18.3.1']]));
        self::assertSame(['dependencies' => ['react' => '^18.0.0', 'react-dom' => '^18.0.0']], self::json($file, []));
    }

    public function testDependencyUpgradeReactRouterDomAndStyledComponents(): void
    {
        self::assertSame(['dependencies' => ['react-router-dom' => '^6.0.0']], self::json('dependency-upgrade-react-router-dom.json.php', ['dependencies' => ['react-router-dom' => '5.3.4']]));
        self::assertSame(['dependencies' => ['react-router-dom' => '6.30.6']], self::json('dependency-upgrade-react-router-dom.json.php', ['dependencies' => ['react-router-dom' => '6.30.6']]));
        self::assertSame(['dependencies' => ['styled-components' => '^6.0.0']], self::json('dependency-upgrade-styled-components.json.php', ['dependencies' => ['styled-components' => '5.3.3']]));
    }

    public function testCodemodsWithoutAPhpSubjectReturnNull(): void
    {
        foreach (['deprecate-helper-plugin', 'strapi-public-interface', 'utils-public-interface'] as $name) {
            self::assertNull(self::codemod("{$name}.code.php"));
        }
    }

    public function testCommentOutLifecycleFiles(): void
    {
        $source = "<?php\n\nreturn [\n    'beforeCreate' => static function (): void {},\n];\n";

        $out = $this->code('comment-out-lifecycle-files.code.php', 'src/api/a/content-types/a/lifecycles.php', $source);

        self::assertStringStartsWith("<?php\n\n/*\n *\n * ====", $out);
        self::assertStringContainsString("// return [\n//     'beforeCreate' => static function (): void {},\n// ];", $out);
        // the commented-out file returns nothing
        $cwd = $this->volume(['l.php' => $out]);
        self::assertSame(1, require "{$cwd}/l.php");

        self::assertSame($source, $this->code('comment-out-lifecycle-files.code.php', 'src/api/a/services/a.php', $source));
    }

    public function testUseUidForConfigNamespace(): void
    {
        $source = <<<'PHP'
            <?php

            return function ($strapi) {
                $a = $strapi->config()->get('plugin.upload.sizeLimit');
                $b = $this->strapi->config()->set("api.foo", 1);
                $c = strapi()->config()->has('api.rest.prefix');
                $d = $strapi->config()->get('server.host');
                $e = $other->config()->get('plugin.x');
            };
            PHP;

        $out = $this->code('use-uid-for-config-namespace.code.php', 'src/index.php', $source);

        self::assertStringContainsString("get('plugin::upload.sizeLimit')", $out);
        self::assertStringContainsString('set("api::foo", 1)', $out);
        self::assertStringContainsString("has('api.rest.prefix')", $out);
        self::assertStringContainsString("get('server.host')", $out);
        self::assertStringContainsString("\$other->config()->get('plugin.x')", $out);
    }

    public function testS3KeysWrappedInCredentials(): void
    {
        $source = <<<'PHP'
            <?php

            return static fn (Env $env): array => [
                'upload' => [
                    'config' => [
                        'provider' => 'aws-s3',
                        'providerOptions' => [
                            'accessKeyId' => $env('AWS_ACCESS_KEY_ID'),
                            'secretAccessKey' => $env('AWS_ACCESS_SECRET'),
                            'region' => $env('AWS_REGION'),
                        ],
                    ],
                ],
            ];
            PHP;

        $out = $this->code('s3-keys-wrapped-in-credentials.code.php', 'config/plugins.php', $source);

        self::assertStringContainsString("'s3Options' => ['credentials' => ['accessKeyId' => \$env('AWS_ACCESS_KEY_ID'), 'secretAccessKey' => \$env('AWS_ACCESS_SECRET')]]", $out);
        self::assertStringNotContainsString("'accessKeyId' => \$env('AWS_ACCESS_KEY_ID'),\n", $out);

        // other providers and other files are left alone
        self::assertSame(str_replace('aws-s3', 'local', $source), $this->code('s3-keys-wrapped-in-credentials.code.php', 'config/plugins.php', str_replace('aws-s3', 'local', $source)));
        self::assertSame($source, $this->code('s3-keys-wrapped-in-credentials.code.php', 'config/server.php', $source));
    }

    public function testEntityServiceDocumentService(): void
    {
        $source = <<<'PHP'
            <?php

            return function ($strapi, $uid, $entityId) {
                $params = ['fields' => ['id', 'name'], 'publicationState' => 'live'];
                $state = 'preview';
                $strapi->entityService()->findOne($uid, $entityId, $params);
                $this->strapi->entityService()->findMany($uid, ['populate' => ['author'], 'publicationState' => $state]);
                $args = [$uid, $entityId, ['publicationState' => 'preview']];
                $strapi->entityService()->update(...$args);
                $strapi->entityService()->delete($uid, $entityId);
                $strapi->entityService()->create($uid, ['data' => ['name' => 'John Doe']]);
                $strapi->documents($uid)->findMany();
            };
            PHP;

        $out = $this->code('entity-service-document-service.code.php', 'src/api/a/services/a.php', $source);

        self::assertStringContainsString("\$params = ['documentId' => '__TODO__', 'fields' => ['id', 'name'], 'status' => 'published'];", $out);
        self::assertStringContainsString("\$state = 'draft';", $out);
        self::assertStringContainsString('$strapi->documents($uid)->findOne($params);', $out);
        self::assertStringContainsString("\$this->strapi->documents(\$uid)->findMany(['populate' => ['author'], 'status' => \$state]);", $out);
        self::assertStringContainsString("\$strapi->documents(\$uid)->update(['documentId' => '__TODO__', 'status' => 'draft']);", $out);
        self::assertStringContainsString("\$strapi->documents(\$uid)->delete(['documentId' => '__TODO__']);", $out);
        self::assertStringContainsString("\$strapi->documents(\$uid)->create(['data' => ['name' => 'John Doe']]);", $out);
        self::assertStringNotContainsString('entityService', $out);
    }

    public function testJsonCodemodsOnARealProjectKeepFormatting(): void
    {
        $cwd = $this->volume(['package.json' => "{\n  \"name\": \"x\",\n  \"dependencies\": {\n    \"react\": \"18.3.1\",\n    \"react-dom\": \"18.3.1\",\n    \"@strapi/plugin-i18n\": \"5.0.0\"\n  },\n  \"strapi\": {}\n}\n"]);

        Transform::transformJSON(Constants::internalCodemodsDirectory() . '/5.0.0/dependency-remove-strapi-plugin-i18n.json.php', ["{$cwd}/package.json"], ['cwd' => $cwd]);

        self::assertSame("{\n  \"name\": \"x\",\n  \"dependencies\": {\n    \"react\": \"18.3.1\",\n    \"react-dom\": \"18.3.1\"\n  },\n  \"strapi\": {}\n}\n", file_get_contents("{$cwd}/package.json"));
        self::assertIsArray(File::readJSON("{$cwd}/package.json"));
    }
}
