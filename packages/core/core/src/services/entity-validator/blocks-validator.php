<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** Port of packages/core/core/src/services/entity-validator/blocks-validator.ts. */
final class BlocksValidator
{
    private const ALLOWED_LINK_PROTOCOLS = ['http:', 'https:', 'ftp:', 'mailto:', 'tel:'];

    private static ?YupArray $schema = null;

    public static function blocksValidator(): YupArray
    {
        return self::$schema ??= Yup::array()->of(self::blockNodeValidator());
    }

    private static function textNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['text'])->required(),
            'text' => Yup::string()->test('is-valid-text', 'Text must be defined with at least an empty string', static fn (mixed $text): bool => is_string($text)),
            'bold' => Yup::boolean(),
            'italic' => Yup::boolean(),
            'underline' => Yup::boolean(),
            'strikethrough' => Yup::boolean(),
            'code' => Yup::boolean(),
        ]);
    }

    public static function checkValidLink(string $link): bool
    {
        $url = str_starts_with($link, '/') ? "https://strapi.io{$link}" : $link;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }

        return in_array(strtolower($scheme) . ':', self::ALLOWED_LINK_PROTOCOLS, true);
    }

    private static function linkNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['link'])->required(),
            'url' => Yup::string()->test('invalid-url', 'Please specify a valid link.', static fn (mixed $value): bool => self::checkValidLink(is_string($value) ? $value : '')),
            'children' => Yup::array()->of(self::textNodeValidator())->required(),
        ]);
    }

    private static function inlineNodeValidator(): YupLazy
    {
        return Yup::lazy(static fn (mixed $value): Yup => match (is_array($value) ? ($value['type'] ?? null) : null) {
            'text' => self::textNodeValidator(),
            'link' => self::linkNodeValidator(),
            default => Yup::mixed()->test('invalid-type', 'Inline node must be Text or Link', static fn (): bool => false),
        });
    }

    private static function paragraphNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['paragraph'])->required(),
            'children' => Yup::array()->of(self::inlineNodeValidator())->min(1, 'Paragraph node children must have at least one Text or Link node')->required(),
        ]);
    }

    private static function headingNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['heading'])->required(),
            'level' => Yup::number()->oneOf([1, 2, 3, 4, 5, 6])->required(),
            'children' => Yup::array()->of(self::inlineNodeValidator())->min(1, 'Heading node children must have at least one Text or Link node')->required(),
        ]);
    }

    private static function quoteNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['quote'])->required(),
            'children' => Yup::array()->of(self::inlineNodeValidator())->min(1, 'Quote node children must have at least one Text or Link node')->required(),
        ]);
    }

    private static function codeBlockValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['code'])->required(),
            'language' => Yup::string()->nullable(),
            'children' => Yup::array()->of(self::textNodeValidator())->min(1, 'Quote node children must have at least one Text or Link node')->required(),
        ]);
    }

    private static function listItemNode(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['list-item'])->required(),
            'children' => Yup::array()->of(self::inlineNodeValidator())->required(),
        ]);
    }

    private static function listChildrenValidator(): YupLazy
    {
        return Yup::lazy(static fn (mixed $value): Yup => match (is_array($value) ? ($value['type'] ?? null) : null) {
            'list' => self::listNodeValidator(),
            'list-item' => self::listItemNode(),
            default => Yup::mixed()->test('invalid-type', 'Inline node must be list-item or list', static fn (): bool => false),
        });
    }

    private static function listNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['list'])->required(),
            'format' => Yup::string()->equals(['ordered', 'unordered'])->required(),
            'children' => Yup::array()->of(self::listChildrenValidator())->min(1, 'List node children must have at least one ListItem or ListNode')->required(),
        ]);
    }

    private static function imageNodeValidator(): YupObject
    {
        return Yup::object([
            'type' => Yup::string()->equals(['image'])->required(),
            'image' => Yup::object([
                'name' => Yup::string()->required(),
                'alternativeText' => Yup::string()->nullable(),
                'url' => Yup::string()->required(),
                'caption' => Yup::string()->nullable(),
                'width' => Yup::number()->required(),
                'height' => Yup::number()->required(),
                'formats' => Yup::object()->nullable(),
                'hash' => Yup::string()->required(),
                'ext' => Yup::string()->required(),
                'mime' => Yup::string()->required(),
                'size' => Yup::number()->required(),
                'previewUrl' => Yup::string()->nullable(),
                'provider' => Yup::string()->required(),
                'provider_metadata' => Yup::mixed()->nullable(),
                'createdAt' => Yup::string()->required(),
                'updatedAt' => Yup::string()->required(),
            ]),
            'children' => Yup::array()->of(self::inlineNodeValidator())->required(),
        ]);
    }

    private static function blockNodeValidator(): YupLazy
    {
        return Yup::lazy(static fn (mixed $value): Yup => match (is_array($value) ? ($value['type'] ?? null) : null) {
            'paragraph' => self::paragraphNodeValidator(),
            'heading' => self::headingNodeValidator(),
            'quote' => self::quoteNodeValidator(),
            'list' => self::listNodeValidator(),
            'image' => self::imageNodeValidator(),
            'code' => self::codeBlockValidator(),
            default => Yup::mixed()->test('invalid-type', 'Block node is of invalid type', static fn (): bool => false),
        });
    }
}
