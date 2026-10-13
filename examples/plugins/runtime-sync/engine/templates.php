<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/templates.js: the project files create-strapi-app writes, for both runtimes
 * (spec/templates/node = upstream's vanilla-js and vanilla templates, spec/templates/php =
 * strapi-php's). A config file a project never touched is recognised by its normalised source and
 * answered with the other runtime's template, instead of being translated.
 */
final class Templates
{
    public static function dir(): string
    {
        return dirname(__DIR__) . '/spec/templates';
    }

    /**
     * @param 'node'|'php' $runtime
     */
    public static function read(string $runtime, string $relPath): ?string
    {
        $file = self::dir() . "/{$runtime}/{$relPath}";

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * The other runtime's template for a file that is itself an unchanged template, else null.
     * `$targetExt` is `php`, `js` or `ts`.
     */
    public static function counterpart(string $relPath, string $source, string $targetExt): ?string
    {
        $stem = Files::stem($relPath);
        $ext = Files::extension($relPath);
        $sourceRuntime = Spec::runtimeOfExtension($ext);
        if ($sourceRuntime === null) {
            return null;
        }
        $lang = $ext === 'php' ? 'php' : ($ext === 'ts' ? 'ts' : 'js');

        $template = self::read($sourceRuntime, "{$stem}.{$ext}");
        if ($template === null || Source::normalize($template, $lang) !== Source::normalize($source, $lang)) {
            return null;
        }

        $targetRuntime = $targetExt === 'php' ? 'php' : 'node';

        return self::read($targetRuntime, "{$stem}.{$targetExt}");
    }
}
