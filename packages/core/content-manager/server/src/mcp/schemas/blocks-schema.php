<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Mcp\Schemas;

use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/schemas/blocks-schema.ts: the Zod schema of a Blocks (rich-text) field
 * input. The node schemas are built once (module constants upstream), so the recursive list schema
 * is one instance the JSON Schema conversion can reference.
 */
final class BlocksSchema
{
    private static ?ZodType $blocks = null;

    private static ?ZodType $listSchema = null;

    private static function textNodeSchema(): ZodType
    {
        return z::object([
            'type' => z::literal('text'),
            'text' => z::string()->describe('The text content.'),
            'bold' => z::boolean()->optional(),
            'italic' => z::boolean()->optional(),
            'underline' => z::boolean()->optional(),
            'strikethrough' => z::boolean()->optional(),
            'code' => z::boolean()->optional(),
        ])->describe('A text node with optional formatting marks.');
    }

    private static function inlineNodeSchema(ZodType $text): ZodType
    {
        $link = z::object([
            'type' => z::literal('link'),
            'url' => z::string()->describe('The URL the link points to.'),
            'children' => z::array($text)->min(1),
        ])->describe('An inline link node. Children must be text nodes.');

        return z::discriminatedUnion('type', [$text, $link]);
    }

    /** Returns the Zod schema for a Strapi Blocks field input: an array of block nodes. */
    public static function buildBlocksInputSchema(): ZodType
    {
        if (self::$blocks !== null) {
            return self::$blocks;
        }

        $text = self::textNodeSchema();
        $inline = self::inlineNodeSchema($text);

        $paragraph = z::object(['type' => z::literal('paragraph'), 'children' => z::array($inline)->min(1)]);
        $heading = z::object([
            'type' => z::literal('heading'),
            'level' => z::union([z::literal(1), z::literal(2), z::literal(3), z::literal(4), z::literal(5), z::literal(6)]),
            'children' => z::array($inline)->min(1),
        ]);
        $quote = z::object(['type' => z::literal('quote'), 'children' => z::array($inline)->min(1)]);
        $code = z::object([
            'type' => z::literal('code'),
            'language' => z::string()->nullable()->optional()->describe('Programming language identifier (e.g. "javascript", "python").'),
            'children' => z::array($text)->min(1),
        ]);

        // List nodes (recursive via z.lazy — mirrors yup.lazy in blocks-validator.ts)
        $listItem = z::object(['type' => z::literal('list-item'), 'children' => z::array($inline)->min(1)]);
        self::$listSchema = z::object([
            'type' => z::literal('list'),
            'format' => z::enum(['ordered', 'unordered']),
            'indentLevel' => z::number()->optional(),
            'children' => z::array(z::lazy(static fn (): ZodType => z::union([$listItem, self::$listSchema ?? z::never()])))
                ->min(1)
                ->describe('Children must be list-item or nested list nodes.'),
        ]);

        $image = z::object([
            'type' => z::literal('image'),
            'image' => z::object([
                'name' => z::string(),
                'alternativeText' => z::string()->nullable()->optional(),
                'url' => z::string(),
                'caption' => z::string()->nullable()->optional(),
                'width' => z::number(),
                'height' => z::number(),
                'formats' => z::record(z::string(), z::unknown())->nullable()->optional(),
                'hash' => z::string(),
                'ext' => z::string(),
                'mime' => z::string(),
                'size' => z::number(),
                'previewUrl' => z::string()->nullable()->optional(),
                'provider' => z::string(),
                'provider_metadata' => z::unknown()->nullable()->optional(),
                'createdAt' => z::string(),
                'updatedAt' => z::string(),
            ])->describe('An existing media asset. Use media tools to retrieve this data — MCP cannot upload files.'),
            'children' => z::array(z::object(['type' => z::literal('text'), 'text' => z::literal('')]))->length(1),
        ]);

        $blockNode = z::discriminatedUnion('type', [$paragraph, $heading, $quote, $code, $image]);

        // listSchema uses z.lazy so it cannot participate in discriminatedUnion; use a regular union
        return self::$blocks = z::array(z::union([$blockNode, self::$listSchema]))
            ->describe('An array of block nodes representing structured rich text content.');
    }
}
