<?php

declare(strict_types=1);

use Strapi\Upgrade\Modules\Runner\Code\TransformAPI;

/**
 * Port of resources/codemods/5.0.0/comment-out-lifecycle-files.code.ts: comments out
 * `content-types/<name>/lifecycles.php` files and adds a description for the reason at the top.
 * The `<?php` opening tag stays; a commented-out file returns nothing, which the loader treats
 * as no lifecycles.
 *
 * @param array{path: string, source: string} $file
 */
return static function (array $file, TransformAPI $api): string {
    $source = $file['source'];

    // check if file path follows this pattern `content-types/[content-type-name]/lifecycles`
    if (preg_match('~content-types[/\\\\][^/\\\\]+[/\\\\]lifecycles\.php$~', $file['path']) !== 1) {
        return $source;
    }

    if (preg_match('/^<\?php[ \t]*\r?\n/', $source, $m) !== 1) {
        return $source;
    }

    // Split the source code into lines and prepend // to each line
    // we are using line comments instead of block comments so we don't face issues with existing block comments
    $commentedCode = implode("\n", array_map(static fn (string $line): string => "// {$line}", explode("\n", substr($source, strlen($m[0])))));

    // Add a header comment at the top to explain why the file is commented out
    $headerComment = <<<'TXT'

        /*
         *
         * ============================================================
         * WARNING: THIS FILE HAS BEEN COMMENTED OUT
         * ============================================================
         *
         * CONTEXT:
         *
         * The lifecycles.php file has been commented out to prevent unintended side effects when starting Strapi 5 for the first time after migrating to the document service.
         *
         * STRAPI 5 introduces a new document service that handles lifecycles differently compared to previous versions. Without migrating your lifecycles to document service middlewares, you may experience issues such as:
         *
         * - `unpublish` actions triggering `delete` lifecycles for every locale with a published entity, which differs from the expected behavior in v4.
         * - `discardDraft` actions triggering both `create` and `delete` lifecycles, leading to potential confusion.
         *
         * MIGRATION GUIDE:
         *
         * For a thorough guide on migrating your lifecycles to document service middlewares, please refer to the following link:
         * [Document Services Middlewares Migration Guide](https://docs.strapi.io/dev-docs/migration/v4-to-v5/breaking-changes/lifecycle-hooks-document-service)
         *
         * IMPORTANT:
         *
         * Simply uncommenting this file without following the migration guide may result in unexpected behavior and inconsistencies. Ensure that you have completed the migration process before re-enabling this file.
         *
         * ============================================================
         */

        TXT;

    // Combine the header comment with the commented-out code
    return $m[0] . $headerComment . "\n" . $commentedCode;
};
