<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Services;

use Strapi\Core\Services\ContentSourceMaps;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Core\Tests\Services\Mcp\McpTestCase;

/** Port of services/__tests__/content-source-maps.test.ts, plus @vercel/stega's encoding. */
final class ContentSourceMapsTest extends McpTestCase
{
    private const FIELD_PATH = 'body';

    private const BASE_METADATA = [
        'fieldPath' => self::FIELD_PATH,
        'documentId' => 'doc-1',
        'locale' => 'en',
        'kind' => 'collectionType',
        'model' => 'api::article.article',
    ];

    /** `vercelStegaDecode()`: the JSON hidden in a string, or null. */
    private static function stegaDecode(string $value): mixed
    {
        if (preg_match('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]{4,}/u', $value, $m) !== 1) {
            return null;
        }
        $digits = [8203 => '0', 8204 => '1', 8205 => '2', 65279 => '3'];
        $chars = array_slice(mb_str_split($m[0]), 4); // the prefix
        $json = '';
        foreach (array_chunk($chars, 4) as $group) {
            $json .= chr((int) base_convert(implode('', array_map(static fn (string $c): string => $digits[mb_ord($c)], $group)), 4, 10));
        }

        return json_decode($json, true);
    }

    /** @return array<string, string>|null */
    private static function sourceParams(string $value): ?array
    {
        $decoded = self::stegaDecode($value);
        if (!is_array($decoded) || !isset($decoded['strapiSource'])) {
            return null;
        }
        parse_str((string) $decoded['strapiSource'], $params);

        return array_map('strval', $params);
    }

    private static function mockEncodeField(string $text, array $metadata): string
    {
        $params = ['documentId' => $metadata['documentId'], 'type' => $metadata['type'], 'path' => $metadata['path']];
        foreach (['model', 'kind', 'locale', 'fieldPath'] as $key) {
            if (!empty($metadata[$key])) {
                $params[$key] = $metadata[$key];
            }
        }

        return $text . '[' . http_build_query($params) . ']';
    }

    /** @return list<string> */
    private static function collectEncodedStrings(mixed $value, array &$out = []): array
    {
        if (is_string($value)) {
            if (self::sourceParams($value) !== null || str_contains($value, '[documentId=')) {
                $out[] = $value;
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                self::collectEncodedStrings($item, $out);
            }
        }

        return $out;
    }

    private static function encode(mixed $blocks): mixed
    {
        return ContentSourceMaps::encodeBlocksWith($blocks, self::BASE_METADATA, self::mockEncodeField(...));
    }

    public function testVercelStegaCombine(): void
    {
        $encoded = ContentSourceMaps::vercelStegaCombine('Hello', ['strapiSource' => 'a=b'], false);
        self::assertStringStartsWith('Hello', $encoded);
        self::assertSame(['strapiSource' => 'a=b'], self::stegaDecode($encoded));
        // the exact bytes of @vercel/stega 0.1.2 for `{"a":1}`
        self::assertSame("\u{200B}\u{200B}\u{200B}\u{200B}\u{200C}\u{FEFF}\u{200D}\u{FEFF}\u{200B}\u{200D}\u{200B}\u{200D}\u{200C}\u{200D}\u{200B}\u{200C}\u{200B}\u{200D}\u{200B}\u{200D}\u{200B}\u{FEFF}\u{200D}\u{200D}\u{200B}\u{FEFF}\u{200B}\u{200C}\u{200C}\u{FEFF}\u{FEFF}\u{200C}", ContentSourceMaps::vercelStegaEncode(['a' => 1]));
        self::assertSame('2024-01-01', ContentSourceMaps::vercelStegaCombine('2024-01-01', ['x' => 1]), 'auto skips dates');
        self::assertSame('https://strapi.io', ContentSourceMaps::vercelStegaCombine('https://strapi.io', ['x' => 1]), 'auto skips URLs');
    }

    public function testEncodeBlocksPureEncoder(): void
    {
        $encoded = self::encode([['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'First line'], ['type' => 'text', 'text' => 'Second line', 'bold' => true]]]]);
        self::assertStringContainsString('First line', $encoded[0]['children'][0]['text']);
        self::assertStringContainsString('path=' . self::FIELD_PATH, $encoded[0]['children'][0]['text']);
        self::assertSame('Second line', $encoded[0]['children'][1]['text']);

        $encoded = self::encode([['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'one']]], ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'two']]]]);
        $strings = self::collectEncodedStrings($encoded);
        self::assertCount(2, $strings);
        foreach ($strings as $value) {
            self::assertStringContainsString('fieldPath=' . self::FIELD_PATH, $value);
        }

        self::assertCount(6, self::collectEncodedStrings(self::encode(array_map(static fn (int $level): array => ['type' => 'heading', 'level' => $level, 'children' => [['type' => 'text', 'text' => "heading {$level}"]]], [1, 2, 3, 4, 5, 6]))));

        $lists = self::encode([
            ['type' => 'list', 'format' => 'unordered', 'children' => [
                ['type' => 'list-item', 'children' => [['type' => 'text', 'text' => 'Outer item']]],
                ['type' => 'list', 'format' => 'unordered', 'children' => [['type' => 'list-item', 'children' => [['type' => 'text', 'text' => 'Nested item']]]]],
            ]],
        ]);
        self::assertCount(2, self::collectEncodedStrings($lists));
        self::assertStringContainsString('Nested item', $lists[0]['children'][1]['children'][0]['children'][0]['text']);

        $image = self::encode([['type' => 'image', 'image' => ['url' => '/uploads/photo.png', 'alternativeText' => 'Hero image'], 'children' => [['type' => 'text', 'text' => '']]]]);
        self::assertSame('/uploads/photo.png', $image[0]['image']['url']);
        self::assertStringContainsString('fieldPath=' . self::FIELD_PATH, $image[0]['image']['alternativeText']);

        $code = [['type' => 'code', 'children' => [['type' => 'text', 'text' => 'console.log("hi")']]]];
        self::assertSame($code, self::encode($code));
        $noAlt = [['type' => 'image', 'image' => ['url' => '/x.png'], 'children' => []]];
        self::assertSame($noAlt, self::encode($noAlt));
        self::assertSame([], self::encode([]));
        self::assertNull(self::encode(null));

        $nested = self::encode([['type' => 'paragraph', 'children' => [['type' => 'link', 'url' => 'https://strapi.io', 'children' => [['type' => 'text', 'text' => 'Read more']]], ['type' => 'text', 'text' => ' trailing text']]]]);
        self::assertStringContainsString('Read more', $nested[0]['children'][0]['children'][0]['text']);
        self::assertSame(' trailing text', $nested[0]['children'][1]['text']);
        self::assertCount(1, self::collectEncodedStrings($nested));
    }

    private function schema(Strapi $strapi): Schema
    {
        $schema = new Schema(
            uid: 'api::article.article',
            modelType: 'contentType',
            kind: 'collectionType',
            modelName: 'article',
            globalId: 'Article',
            collectionName: 'articles',
            plugin: null,
            apiName: 'article',
            category: null,
            info: [],
            options: [],
            pluginOptions: [],
            attributes: ['title' => ['type' => 'string'], 'views' => ['type' => 'integer'], 'body' => ['type' => 'blocks'], 'content' => ['type' => 'json']],
        );

        return $schema;
    }

    public function testEncodeSourceMapsService(): void
    {
        $strapi = $this->createStrapi();
        $service = ContentSourceMaps::createContentSourceMapsService($strapi);
        $schema = $this->schema($strapi);

        $encoded = $service->encodeSourceMaps([
            'id' => 1,
            'documentId' => 'doc-1',
            'locale' => 'en',
            'title' => 'Hello',
            'views' => 3,
            'body' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Intro', 'bold' => true], ['type' => 'link', 'url' => 'https://strapi.io', 'children' => [['type' => 'text', 'text' => 'Read more']]]]],
                ['type' => 'heading', 'level' => 2, 'children' => [['type' => 'text', 'text' => 'Title']]],
                ['type' => 'code', 'children' => [['type' => 'text', 'text' => 'const x = 1;']]],
            ],
            'content' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'Should not be encoded']]]],
        ], $schema);

        self::assertSame(3, $encoded['views']);
        self::assertSame('doc-1', $encoded['documentId']);
        self::assertSame('en', $encoded['locale']);
        self::assertSame(['documentId' => 'doc-1', 'type' => 'string', 'path' => 'title', 'model' => 'api::article.article', 'kind' => 'collectionType', 'locale' => 'en'], self::sourceParams($encoded['title']));
        self::assertSame('https://strapi.io', $encoded['body'][0]['children'][1]['url']);
        self::assertSame('Read more', $encoded['body'][0]['children'][1]['children'][0]['text']);
        self::assertTrue($encoded['body'][0]['children'][0]['bold']);
        self::assertSame('const x = 1;', $encoded['body'][2]['children'][0]['text']);
        self::assertSame('Should not be encoded', $encoded['content'][0]['children'][0]['text']);

        $blockStrings = self::collectEncodedStrings($encoded['body']);
        self::assertCount(2, $blockStrings);
        foreach ($blockStrings as $value) {
            self::assertSame(['documentId' => 'doc-1', 'type' => 'blocks', 'path' => 'body', 'model' => 'api::article.article', 'kind' => 'collectionType', 'locale' => 'en', 'fieldPath' => 'body'], self::sourceParams($value));
        }

        $list = $service->encodeSourceMaps([['documentId' => 'a', 'title' => 'x', 'body' => null], 'scalar'], $schema);
        self::assertNotNull(self::sourceParams($list[0]['title']));
        self::assertNull($list[0]['body']);
        self::assertSame('scalar', $list[1]);
    }
}
