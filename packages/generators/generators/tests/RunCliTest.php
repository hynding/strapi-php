<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests;

use Strapi\Generators\ConsoleInquirer;
use Strapi\Generators\Generators;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/GeneratorsTestCase.php';

/**
 * Not an upstream test (upstream delegates to plop's CLI): the interactive runner with
 * symfony/console's question helper — typed answers, plop's bypass (positional and named
 * answers) and `--no-interaction`.
 */
final class RunCliTest extends GeneratorsTestCase
{
    private BufferedOutput $output;

    /** @var list<string> */
    private array $log = [];

    /**
     * @param list<string> $typed lines typed at the prompts (null: not interactive)
     * @param array<string, mixed> $named
     * @param list<string> $bypass
     */
    private function runCli(?string $generator, ?array $typed, array $named = [], array $bypass = []): void
    {
        $input = new ArrayInput([]);
        if ($typed === null) {
            $input->setInteractive(false);
        } else {
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, implode("\n", $typed) . "\n");
            rewind($stream);
            $input->setStream($stream);
        }
        $this->output = new BufferedOutput();

        Generators::runCLI(
            new ConsoleInquirer($input, $this->output, new QuestionHelper(), $named),
            $this->outputDirectory,
            $generator,
            $bypass,
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    public function testTypedAnswers(): void
    {
        // API name, Is this API for a plugin?
        $this->runCli('api', ['hello', 'n']);

        self::assertSame([
            'src/api/hello/controllers/hello.php',
            'src/api/hello/routes/hello.php',
            'src/api/hello/services/hello.php',
        ], self::files($this->outputDirectory));
        self::assertSame([
            '✔  ++ /src/api/hello/controllers/hello.php',
            '✔  ++ /src/api/hello/services/hello.php',
            '✔  ++ /src/api/hello/routes/hello.php',
        ], $this->log);
        self::assertStringContainsString('API name', $this->output->fetch());
    }

    public function testAnInvalidTypedAnswerIsAskedAgain(): void
    {
        $this->runCli('policy', ['not valid!', 'is-owner', 'root']);

        self::assertSame(['src/policies/is-owner.php'], self::files($this->outputDirectory));
        self::assertStringContainsString("Please use only letters, '-' and no spaces", $this->output->fetch());
    }

    public function testPicksTheGeneratorFromTheMenu(): void
    {
        $this->runCli(null, ['migration', 'add-index']);

        $files = self::files($this->outputDirectory);
        self::assertCount(1, $files);
        self::assertStringEndsWith('.add-index.php', $files[0]);
        self::assertStringContainsString('Strapi Generators', $this->output->fetch());
    }

    public function testPositionalBypass(): void
    {
        mkdir("{$this->outputDirectory}/src/api/article", 0o777, true);
        $this->runCli('controller', null, [], ['hello', 'api', 'article']);

        self::assertSame(['src/api/article/controllers/hello.php'], array_values(array_filter(self::files($this->outputDirectory))));
    }

    public function testNamedAnswersWithoutInteraction(): void
    {
        $this->runCli('service', null, ['id' => 'mailer', 'destination' => 'new']);

        self::assertSame(['src/api/mailer/services/mailer.php'], self::files($this->outputDirectory));
    }

    public function testListAnswersMayBeChoiceNames(): void
    {
        $this->runCli('policy', null, ['id' => 'p', 'destination' => 'Add policy to root of project']);

        self::assertSame(['src/policies/p.php'], self::files($this->outputDirectory));
    }

    public function testContentTypeWithoutInteraction(): void
    {
        $this->runCli('content-type', null, [
            'displayName' => 'Blog Post',
            'attributes' => 'title:string,status:enumeration:draft|published,cover:media:multiple',
            'destination' => 'new',
        ]);

        // defaults: singular/plural names from the display name, collectionType, API id, bootstrapApi
        self::assertSame([
            'src/api/blog-post/content-types/blog-post/schema.json',
            'src/api/blog-post/controllers/blog-post.php',
            'src/api/blog-post/routes/blog-post.php',
            'src/api/blog-post/services/blog-post.php',
        ], self::files($this->outputDirectory));

        $schema = self::readJSON("{$this->outputDirectory}/src/api/blog-post/content-types/blog-post/schema.json");
        self::assertSame('collectionType', $schema['kind']);
        self::assertSame(['singularName' => 'blog-post', 'pluralName' => 'blog-posts', 'displayName' => 'Blog Post'], $schema['info']);
        self::assertSame([
            'title' => ['type' => 'string'],
            'status' => ['type' => 'enumeration', 'enum' => ['draft', 'published']],
            'cover' => ['type' => 'media', 'allowedTypes' => ['images', 'files', 'videos', 'audios'], 'multiple' => true],
        ], $schema['attributes']);
    }

    public function testContentTypeTypedAnswers(): void
    {
        $this->runCli('content-type', [
            'Article',          // display name
            '',                 // singular name: article
            '',                 // plural name: articles
            '1',                // Single Type
            'y',                // add attributes?
            'title',            // attribute name
            'string',           // type
            'n',                // another?
            'Add model to new API',
            '',                 // API name: article
            'n',                // bootstrap API?
            'n',                // add to a folder?
        ]);

        self::assertSame(['src/api/article/content-types/article/schema.json'], self::files($this->outputDirectory));
        $schema = self::readJSON("{$this->outputDirectory}/src/api/article/content-types/article/schema.json");
        self::assertSame('singleType', $schema['kind']);
        self::assertSame(['title' => ['type' => 'string']], $schema['attributes']);
    }

    public function testAMissingAnswerFailsWithoutInteraction(): void
    {
        $this->expectExceptionMessage('Missing answer for "id" (API name): pass it as --id=<value>');

        $this->runCli('api', null);
    }

    public function testAnInvalidAnswerFailsWithoutInteraction(): void
    {
        $this->expectExceptionMessage("Please use only letters, '-' and no spaces");

        $this->runCli('api', null, ['id' => 'bad name']);
    }

    public function testAnInvalidChoiceFails(): void
    {
        $this->expectExceptionMessage('Invalid answer "nowhere" for "destination": expected one of root, api, plugin');

        $this->runCli('policy', null, ['id' => 'p', 'destination' => 'nowhere']);
    }

    public function testDynamicPromptsRejectPositionalAnswers(): void
    {
        $this->expectExceptionMessage('asks dynamic questions');

        $this->runCli('content-type', null, [], ['Article']);
    }

    public function testUnknownGenerator(): void
    {
        $this->expectExceptionMessage('Generator "nope" not found. Available generators: api, controller, content-type, policy, middleware, migration, service, plugin');

        $this->runCli('nope', null);
    }
}
