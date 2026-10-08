<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests\Plops;

use Strapi\Generators\Generators;
use Strapi\Generators\Tests\GeneratorsTestCase;

require_once dirname(__DIR__) . '/GeneratorsTestCase.php';

/** Port of src/plops/__tests__/content-type.test.ts. */
final class ContentTypeTest extends GeneratorsTestCase
{
    /** @param array<string, mixed> $answers */
    private function generate(array $answers): void
    {
        Generators::generate('content-type', [
            'displayName' => 'testContentType',
            'singularName' => 'testContentType',
            'pluralName' => 'testContentTypes',
            'kind' => 'singleType',
            'id' => 'testContentType',
            'destination' => 'new',
            'bootstrapApi' => false,
            'attributes' => [],
            ...$answers,
        ], ['dir' => $this->outputDirectory]);
    }

    public function testItGeneratesTheSchema(): void
    {
        $this->generate([]);

        $generatedSchemaPath = $this->outputDirectory . '/src/api/testContentType/content-types/testContentType/schema.json';

        self::assertFileExists($generatedSchemaPath);

        $fileContent = self::read($generatedSchemaPath);

        self::assertSame([
            'kind' => 'singleType',
            'collectionName' => 'test_content_types',
            'info' => [
                'singularName' => 'testContentType',
                'pluralName' => 'testContentTypes',
                'displayName' => 'testContentType',
            ],
            'options' => [
                'comment' => '',
            ],
            'attributes' => [],
        ], json_decode($fileContent, true));

        // the template, byte for byte (upstream's starts with an empty line)
        self::assertSame(<<<'JSON'

            {
              "kind": "singleType",
              "collectionName": "test_content_types",
              "info": {
                "singularName": "testContentType",
                "pluralName": "testContentTypes",
                "displayName": "testContentType"
              },
              "options": {
                "comment": ""
              },
              "attributes": {}
            }

            JSON, $fileContent);
    }

    public function testItScaffoldsANewApi(): void
    {
        $this->generate(['bootstrapApi' => true]);

        $generatedApiPath = $this->outputDirectory . '/src/api/testContentType';

        self::assertDirectoryExists($generatedApiPath);
        self::assertFileExists("{$generatedApiPath}/controllers/testContentType.php");
        self::assertFileExists("{$generatedApiPath}/services/testContentType.php");
        self::assertFileExists("{$generatedApiPath}/routes/testContentType.php");

        $controller = self::read("{$generatedApiPath}/controllers/testContentType.php");
        $router = self::read("{$generatedApiPath}/routes/testContentType.php");
        $service = self::read("{$generatedApiPath}/services/testContentType.php");

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            /**
             * testContentType controller
             */

            use Strapi\Core\Factories;

            return Factories::createCoreController('api::testContentType.testContentType');

            PHP, $controller);
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            /**
             * testContentType router
             */

            use Strapi\Core\Factories;

            return Factories::createCoreRouter('api::testContentType.testContentType');

            PHP, $router);
        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            /**
             * testContentType service
             */

            use Strapi\Core\Factories;

            return Factories::createCoreService('api::testContentType.testContentType');

            PHP, $service);
    }

    public function testItGeneratesTheSchemaThenAddsTheAttributes(): void
    {
        $this->generate([
            'attributes' => [
                ['attributeName' => 'name', 'attributeType' => 'string'],
                ['attributeName' => 'email', 'attributeType' => 'string'],
            ],
        ]);

        $generatedSchemaPath = $this->outputDirectory . '/src/api/testContentType/content-types/testContentType/schema.json';

        self::assertFileExists($generatedSchemaPath);

        $schema = self::readJSON($generatedSchemaPath);

        self::assertEqualsCanonicalizing([
            'email' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ], $schema['attributes']);
        // JSON.stringify(parsed, null, 2): no leading empty line, no trailing newline
        self::assertStringStartsWith('{', self::read($generatedSchemaPath));
        self::assertStringEndsWith('}', self::read($generatedSchemaPath));
    }

    // Not upstream: the other attribute shapes

    public function testEnumerationAndMediaAttributes(): void
    {
        $this->generate([
            'attributes' => [
                ['attributeName' => 'status', 'attributeType' => 'enumeration', 'enum' => 'draft, published'],
                ['attributeName' => 'cover', 'attributeType' => 'media', 'multiple' => false],
            ],
        ]);

        $schema = self::readJSON($this->outputDirectory . '/src/api/testContentType/content-types/testContentType/schema.json');

        self::assertSame([
            'status' => ['type' => 'enumeration', 'enum' => ['draft', 'published']],
            'cover' => ['type' => 'media', 'allowedTypes' => ['images', 'files', 'videos', 'audios'], 'multiple' => false],
        ], $schema['attributes']);
    }

    public function testAnExistingFileIsNotOverwritten(): void
    {
        $this->generate(['bootstrapApi' => true]);

        $this->expectExceptionMessage('File already exists');

        $this->generate(['bootstrapApi' => true]);
    }

    public function testTheGeneratedFilesAreValidPhp(): void
    {
        $this->generate(['bootstrapApi' => true]);

        foreach (['controllers', 'services', 'routes'] as $dir) {
            self::assertValidPhp("{$this->outputDirectory}/src/api/testContentType/{$dir}/testContentType.php");
        }
    }
}
