<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Codemod;

use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;

/**
 * Port of packages/utils/upgrade/src/modules/codemod/codemod.ts.
 *
 * @phpstan-import-type Kind from Types
 * @phpstan-import-type FormatOptions from Types
 */
final class Codemod
{
    public readonly string $uid;

    /** @var Kind */
    public readonly string $kind;

    public readonly SemVer $version;

    public readonly string $baseDirectory;

    public readonly string $filename;

    public readonly string $path;

    /** @param array{kind: Kind, version: SemVer, baseDirectory: string, filename: string} $options */
    public function __construct(array $options)
    {
        $this->kind = $options['kind'];
        $this->version = $options['version'];
        $this->baseDirectory = $options['baseDirectory'];
        $this->filename = $options['filename'];

        $this->path = $this->baseDirectory . DIRECTORY_SEPARATOR . $this->version->raw . DIRECTORY_SEPARATOR . $this->filename;
        $this->uid = $this->createUID();
    }

    /** @param array{kind: Kind, version: SemVer, baseDirectory: string, filename: string} $options */
    public static function codemodFactory(array $options): self
    {
        return new self($options);
    }

    private function createUID(): string
    {
        $name = $this->format(['stripExtension' => true, 'stripKind' => true, 'stripHyphens' => false]);

        return "{$this->version->raw}-{$name}-{$this->kind}";
    }

    /** @param FormatOptions|null $options */
    public function format(?array $options = null): string
    {
        $stripExtension = $options['stripExtension'] ?? true;
        $stripKind = $options['stripKind'] ?? true;
        $stripHyphens = $options['stripHyphens'] ?? true;

        $formatted = $this->filename;

        if ($stripExtension) {
            $formatted = (string) preg_replace('/\.' . preg_quote(Constants::CODEMOD_EXTENSION, '/') . '$/i', '', $formatted);
        }

        if ($stripKind) {
            $formatted = self::replaceFirst('.' . Constants::CODEMOD_CODE_SUFFIX, '', $formatted);
            $formatted = self::replaceFirst('.' . Constants::CODEMOD_JSON_SUFFIX, '', $formatted);
        }

        if ($stripHyphens) {
            $formatted = str_replace('-', ' ', $formatted);
        }

        return $formatted;
    }

    /** JS `String.prototype.replace` with a string pattern: first occurrence only */
    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr_replace($subject, $replace, $pos, strlen($search));
    }
}
