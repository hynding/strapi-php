<?php

declare(strict_types=1);

/**
 * Seeds the example app (port of create-strapi-app templates/example/scripts/seed.js):
 * public read access to the example content types, then categories, authors, articles, the
 * global settings and the about page from data/data.json, with the images of data/uploads.
 *
 *   php scripts/seed.php      # or: composer seed:example
 */

use Strapi\Cli\Strapi as StrapiFactory;
use Strapi\Core\Strapi;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

chdir($root);

/** @var array{categories: list<array<string, mixed>>, authors: list<array<string, mixed>>, articles: list<array<string, mixed>>, global: array<string, mixed>, about: array<string, mixed>} $data */
$data = json_decode((string) file_get_contents($root . '/data/data.json'), true, 512, JSON_THROW_ON_ERROR);

$seedExampleApp = static function (Strapi $strapi) use ($data, $root): void {
    $isFirstRun = static function () use ($strapi): bool {
        $pluginStore = $strapi->store()([
            'environment' => $strapi->config()->get('environment'),
            'type' => 'type',
            'name' => 'setup',
        ]);
        $initHasRun = $pluginStore->get(['key' => 'initHasRun']);
        $pluginStore->set(['key' => 'initHasRun', 'value' => true]);

        return !$initHasRun;
    };

    /** @param array<string, list<string>> $newPermissions */
    $setPublicPermissions = static function (array $newPermissions) use ($strapi): void {
        // Find the ID of the public role
        $publicRole = $strapi->query('plugin::users-permissions.role')->findOne([
            'where' => ['type' => 'public'],
        ]);

        // Create the new permissions and link them to the public role
        foreach ($newPermissions as $controller => $actions) {
            foreach ($actions as $action) {
                $strapi->query('plugin::users-permissions.permission')->create([
                    'data' => [
                        'action' => "api::{$controller}.{$controller}.{$action}",
                        'role' => $publicRole['id'] ?? null,
                    ],
                ]);
            }
        }
    };

    $getFileData = static function (string $fileName) use ($root): array {
        $filePath = $root . '/data/uploads/' . $fileName;
        // Parse the file metadata
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimeType = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'][$ext]
            ?? (mime_content_type($filePath) ?: '');

        return [
            'filepath' => $filePath,
            'originalFilename' => $fileName,
            'size' => filesize($filePath),
            'mimetype' => $mimeType,
        ];
    };

    $uploadFile = static function (array $file, string $name) use ($strapi): array {
        $upload = $strapi->plugin('upload')->service('upload');
        if (!$upload instanceof \Strapi\Upload\Services\Upload) {
            throw new \RuntimeException('The upload plugin is not installed');
        }

        return $upload->upload([
            'files' => new \ArrayObject($file),
            'data' => [
                'fileInfo' => [
                    'alternativeText' => "An image uploaded to Strapi called {$name}",
                    'caption' => $name,
                    'name' => $name,
                ],
            ],
        ]);
    };

    // Create an entry and attach files if there are any
    $createEntry = static function (string $model, array $entry, bool $published = false) use ($strapi): void {
        try {
            // Actually create the entry in Strapi (upstream passes `publishedAt: Date.now()`,
            // which the document service ignores; `status: 'published'` publishes it)
            $strapi->documents("api::{$model}.{$model}")->create([
                'data' => $entry,
                ...($published ? ['status' => 'published'] : []),
            ]);
        } catch (\Throwable $error) {
            fwrite(STDERR, json_encode(['model' => $model, 'entry' => $entry, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n");
        }
    };

    /** @param list<string> $files */
    $checkFileExistsBeforeUpload = static function (array $files) use ($strapi, $getFileData, $uploadFile): mixed {
        $existingFiles = [];
        $uploadedFiles = [];

        foreach ($files as $fileName) {
            // Check if the file already exists in Strapi
            $fileWhereName = $strapi->query('plugin::upload.file')->findOne([
                'where' => ['name' => preg_replace('/\..*$/', '', $fileName)],
            ]);

            if ($fileWhereName !== null) {
                // File exists, don't upload it
                $existingFiles[] = $fileWhereName;
            } else {
                // File doesn't exist, upload it
                $fileData = $getFileData($fileName);
                $fileNameNoExtension = explode('.', $fileName)[0];
                [$file] = $uploadFile($fileData, $fileNameNoExtension);
                $uploadedFiles[] = $file;
            }
        }
        $allFiles = [...$existingFiles, ...$uploadedFiles];

        // If only one file then return only that file
        return count($allFiles) === 1 ? $allFiles[0] : $allFiles;
    };

    /** @param list<array<string, mixed>> $blocks */
    $updateBlocks = static function (array $blocks) use ($checkFileExistsBeforeUpload): array {
        $updatedBlocks = [];
        foreach ($blocks as $block) {
            if ($block['__component'] === 'shared.media') {
                // Replace the file name on the block with the actual file
                $block['file'] = $checkFileExistsBeforeUpload([$block['file']]);
            } elseif ($block['__component'] === 'shared.slider') {
                // Replace the file names on the block with the actual files
                $block['files'] = $checkFileExistsBeforeUpload($block['files']);
            }
            $updatedBlocks[] = $block;
        }

        return $updatedBlocks;
    };

    $importArticles = static function () use ($data, $checkFileExistsBeforeUpload, $updateBlocks, $createEntry): void {
        foreach ($data['articles'] as $article) {
            $cover = $checkFileExistsBeforeUpload(["{$article['slug']}.jpg"]);
            $updatedBlocks = $updateBlocks($article['blocks']);

            $createEntry('article', [...$article, 'cover' => $cover, 'blocks' => $updatedBlocks], true);
        }
    };

    $importGlobal = static function () use ($data, $checkFileExistsBeforeUpload, $createEntry): void {
        $favicon = $checkFileExistsBeforeUpload(['favicon.png']);
        $shareImage = $checkFileExistsBeforeUpload(['default-image.png']);

        $createEntry('global', [
            ...$data['global'],
            'favicon' => $favicon,
            'defaultSeo' => [...$data['global']['defaultSeo'], 'shareImage' => $shareImage],
        ], true);
    };

    $importAbout = static function () use ($data, $updateBlocks, $createEntry): void {
        $updatedBlocks = $updateBlocks($data['about']['blocks']);

        $createEntry('about', [...$data['about'], 'blocks' => $updatedBlocks], true);
    };

    $importCategories = static function () use ($data, $createEntry): void {
        foreach ($data['categories'] as $category) {
            $createEntry('category', $category);
        }
    };

    $importAuthors = static function () use ($data, $checkFileExistsBeforeUpload, $createEntry): void {
        foreach ($data['authors'] as $author) {
            $avatar = $checkFileExistsBeforeUpload([$author['avatar']]);

            $createEntry('author', [...$author, 'avatar' => $avatar]);
        }
    };

    if (!$isFirstRun()) {
        echo "Seed data has already been imported. We cannot reimport unless you clear your database first.\n";

        return;
    }

    try {
        echo "Setting up the template...\n";

        // Allow read of application content types
        $setPublicPermissions([
            'article' => ['find', 'findOne'],
            'category' => ['find', 'findOne'],
            'author' => ['find', 'findOne'],
            'global' => ['find', 'findOne'],
            'about' => ['find', 'findOne'],
        ]);

        // Create all entries
        $importCategories();
        $importAuthors();
        $importArticles();
        $importGlobal();
        $importAbout();

        echo "Ready to go\n";
    } catch (\Throwable $error) {
        echo "Could not import seed data\n";
        fwrite(STDERR, $error . "\n");
    }
};

try {
    $app = StrapiFactory::createStrapi(['appDir' => $root, 'distDir' => $root])->load();

    $seedExampleApp($app);
    $app->destroy();

    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
