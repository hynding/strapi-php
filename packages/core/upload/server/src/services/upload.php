<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Provider as UploadProvider;
use Strapi\Upload\Utils\MimeTypes;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\File as FileUtils;
use Strapi\Utils\Pagination;
use Strapi\Utils\Sanitize\Sanitizers;

/**
 * Port of server/src/services/upload.ts.
 *
 * Input files (formidable-like `\ArrayObject`s, see {@see Utils::toInputFile()}) become uploadable
 * files: `\ArrayObject`s carrying `getStream` (a closure opening a stream resource) and `filepath`
 * while they are processed. Database rows are arrays. Everything runs in order: upstream's
 * `Promise.all` batches and `queueConcurrentOperation` become sequential calls.
 *
 * @phpstan-type User array{id: int|string}|object
 * @phpstan-type CommonOptions array{user?: mixed}
 */
final class Upload
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array<string, mixed>|\ArrayObject<string, mixed> $data */
    private function sendMediaMetrics(array|\ArrayObject $data): void
    {
        if (array_key_exists('caption', (array) $data) && !empty($data['caption'])) {
            Utils::getService('metrics', $this->strapi)->trackUsage('didSaveMediaWithCaption');
        }

        if (array_key_exists('alternativeText', (array) $data) && !empty($data['alternativeText'])) {
            Utils::getService('metrics', $this->strapi)->trackUsage('didSaveMediaWithAlternativeText');
        }
    }

    /**
     * @param \ArrayObject<string, mixed>|list<\ArrayObject<string, mixed>> $files
     */
    private function createAndAssignTmpWorkingDirectoryToFiles(\ArrayObject|array $files): string
    {
        $tmpWorkingDirectory = self::mkdtemp(sys_get_temp_dir() . '/strapi-upload-');

        foreach (is_array($files) ? $files : [$files] as $file) {
            $file['tmpWorkingDirectory'] = $tmpWorkingDirectory;
        }

        return $tmpWorkingDirectory;
    }

    /** `fse.mkdtemp(prefix)` */
    public static function mkdtemp(string $prefix): string
    {
        for ($i = 0; $i < 100; $i++) {
            $dir = $prefix . substr(str_replace(['+', '/', '='], ['a', 'b', ''], base64_encode(random_bytes(6))), 0, 6);
            if (@mkdir($dir, 0o700)) {
                return $dir;
            }
        }

        throw new \RuntimeException("Cannot create a temporary directory with prefix {$prefix}");
    }

    /** `fse.remove(path)` */
    public static function removeDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeDirectory($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Copied from https://github.com/sindresorhus/valid-filename package
     */
    private static function isValidFilename(string $string): bool
    {
        if ($string === '' || mb_strlen($string) > 255) {
            return false;
        }
        if (preg_match('/[<>:"\/\\\\|?*\x{0000}-\x{001F}]/u', $string) === 1 || preg_match('/^(con|prn|aux|nul|com\d|lpt\d)$/i', $string) === 1) {
            return false;
        }
        if ($string === '.' || $string === '..') {
            return false;
        }

        return true;
    }

    private function emitEvent(string $event, mixed $data): void
    {
        $modelDef = $this->strapi->getModel(Constants::FILE_MODEL_UID);
        $sanitizedData = Sanitizers::defaultSanitizeOutput(
            [
                'schema' => $modelDef,
                'getModel' => fn (string $uid) => $this->strapi->getModel($uid),
            ],
            $data,
        );

        $this->strapi->eventHub()->emit($event, ['media' => $sanitizedData]);
    }

    /** Node's `path.basename(p, ext)` */
    private static function basename(string $path, string $ext): string
    {
        $base = basename($path);
        if ($ext !== '' && $base !== $ext && str_ends_with($base, $ext)) {
            return substr($base, 0, -strlen($ext));
        }

        return $base;
    }

    /**
     * @param array{filename?: mixed, type?: mixed, size?: mixed} $fileMeta
     * @param array<string, mixed> $fileInfo
     * @param array{refId?: mixed, ref?: mixed, field?: mixed, path?: mixed, tmpWorkingDirectory?: mixed} $metas
     * @return array<string, mixed>
     */
    public function formatFileInfo(array $fileMeta, array $fileInfo = [], array $metas = []): array
    {
        $fileService = Utils::getService('file', $this->strapi);
        $imageManipulationService = Utils::getService('image-manipulation', $this->strapi);

        $filename = is_scalar($fileMeta['filename'] ?? null) ? (string) $fileMeta['filename'] : '';
        $type = is_scalar($fileMeta['type'] ?? null) ? (string) $fileMeta['type'] : '';
        $size = is_numeric($fileMeta['size'] ?? null) ? $fileMeta['size'] + 0 : 0;

        if (!self::isValidFilename($filename)) {
            throw new ApplicationError('File name contains invalid characters');
        }

        $ext = MimeTypes::extname($filename);
        if ($ext === '') {
            $extension = MimeTypes::extension($type);
            $ext = '.' . ($extension === false ? 'false' : $extension);
        }
        $name = $fileInfo['name'] ?? null;
        $usedName = is_string($name) && $name !== '' ? $name : $filename;
        if (class_exists(\Normalizer::class)) {
            $usedName = \Normalizer::normalize($usedName) ?: $usedName;
        }
        $basename = self::basename($usedName, $ext);

        $folder = $fileInfo['folder'] ?? null;

        $entity = ['name' => $usedName];
        foreach (['alternativeText', 'caption', 'focalPoint', 'folder'] as $key) {
            if (array_key_exists($key, $fileInfo)) {
                $entity[$key] = $fileInfo[$key];
            }
        }
        $entity['folderPath'] = $fileService->getFolderPath(is_numeric($folder) ? (int) $folder : null);
        $entity['hash'] = $imageManipulationService->generateFileName($basename);
        $entity['ext'] = $ext;
        $entity['mime'] = $type;
        $entity['size'] = FileUtils::bytesToKbytes($size);
        $entity['sizeInBytes'] = $size;

        $refId = $metas['refId'] ?? null;
        $ref = $metas['ref'] ?? null;
        $field = $metas['field'] ?? null;

        if ($refId && $ref && $field) {
            $entity['related'] = [
                [
                    'id' => $refId,
                    '__type' => $ref,
                    '__pivot' => ['field' => $field],
                ],
            ];
        }

        if (!empty($metas['path'])) {
            $entity['path'] = $metas['path'];
        }

        if (!empty($metas['tmpWorkingDirectory'])) {
            $entity['tmpWorkingDirectory'] = $metas['tmpWorkingDirectory'];
        }

        return $entity;
    }

    /**
     * @param \ArrayObject<string, mixed> $file
     * @param array<string, mixed>|null $fileInfo
     * @param array<string, mixed> $metas
     * @return \ArrayObject<string, mixed>
     */
    private function enhanceAndValidateFile(\ArrayObject $file, ?array $fileInfo, array $metas = []): \ArrayObject
    {
        // Prefer detected MIME type from security validation. Treat application/octet-stream as
        // undeclared so we use detected type when the client sends no real Content-Type.
        $detected = $file['detectedMimeType'] ?? null;
        $declared = is_string($file['mimetype'] ?? null) ? $file['mimetype'] : '';
        $mimeType = (is_string($detected) && $detected !== '' ? $detected : null)
            ?? ($declared !== '' && $declared !== 'application/octet-stream' ? $declared : null)
            ?? 'application/octet-stream';

        $originalFilename = $file['originalFilename'] ?? null;

        $currentFile = new \ArrayObject($this->formatFileInfo(
            [
                'filename' => is_string($originalFilename) ? $originalFilename : 'unamed',
                'type' => $mimeType,
                'size' => $file['size'] ?? 0,
            ],
            $fileInfo ?? [],
            [...$metas, 'tmpWorkingDirectory' => $file['tmpWorkingDirectory'] ?? null],
        ));

        $filepath = (string) $file['filepath'];
        $currentFile['filepath'] = $filepath;
        $currentFile['getStream'] = static function () use ($filepath) {
            $stream = @fopen($filepath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("ENOENT: no such file or directory, open '{$filepath}'");
            }

            return $stream;
        };

        $imageManipulation = Utils::getService('image-manipulation', $this->strapi);

        if ($imageManipulation->isImage($currentFile)) {
            if ($imageManipulation->isFaultyImage($currentFile)) {
                throw new ApplicationError('File is not a valid image');
            }
            if ($imageManipulation->isOptimizableImage($currentFile)) {
                return $imageManipulation->optimize($currentFile);
            }
        }

        return $currentFile;
    }

    /**
     * @param array{data: array<string, mixed>, files: \ArrayObject<string, mixed>|list<\ArrayObject<string, mixed>>} $params
     * @param CommonOptions $opts
     * @return list<array<string, mixed>>
     */
    public function upload(array $params, array $opts = []): array
    {
        $data = $params['data'];
        $files = $params['files'];
        $user = $opts['user'] ?? null;
        // create temporary folder to store files for stream manipulation
        $tmpWorkingDirectory = $this->createAndAssignTmpWorkingDirectoryToFiles($files);

        $uploadedFiles = [];
        try {
            $fileInfo = $data['fileInfo'] ?? null;
            $metas = $data;
            unset($metas['fileInfo']);

            $fileArray = is_array($files) ? $files : [$files];
            $fileInfoArray = is_array($fileInfo) && array_is_list($fileInfo) ? $fileInfo : [$fileInfo];

            // concurrentUploadSize batches run one file after the other here (no concurrency in PHP)
            foreach ($fileArray as $idx => $file) {
                $info = $fileInfoArray[$idx] ?? null;
                $fileData = $this->enhanceAndValidateFile($file, is_array($info) && $info !== [] ? $info : [], $metas);
                $uploadedFiles[] = $this->uploadFileAndPersist($fileData, ['user' => $user]);
            }
        } finally {
            // delete temporary folder
            self::removeDirectory($tmpWorkingDirectory);
        }

        return $uploadedFiles;
    }

    /**
     * When uploading an image, an additional thumbnail is generated.
     * Also, if there are responsive formats defined, another set of images will be generated too.
     *
     * @param \ArrayObject<string, mixed> $fileData
     */
    public function uploadImage(\ArrayObject $fileData): void
    {
        $imageManipulation = Utils::getService('image-manipulation', $this->strapi);
        $provider = Utils::getService('provider', $this->strapi);

        // Store width and height of the original image
        ['width' => $width, 'height' => $height] = $imageManipulation->getDimensions($fileData);

        // Make sure this is assigned before calling any upload
        // That way it can mutate the width and height
        $fileData['width'] = $width;
        $fileData['height'] = $height;

        // Generate the formats before the original's upload consumes its temporary file
        $formats = [];
        if ($imageManipulation->isResizableImage($fileData)) {
            $thumbnailFile = $imageManipulation->generateThumbnail($fileData);
            if ($thumbnailFile !== null) {
                $formats['thumbnail'] = $thumbnailFile;
            }

            foreach ($imageManipulation->generateResponsiveFormats($fileData) as $format) {
                $formats[$format['key']] = $format['file'];
            }
        }

        // Upload image
        $provider->upload($fileData);

        // Upload thumbnail and responsive formats
        foreach ($formats as $key => $file) {
            $provider->upload($file);
            self::setFormat($fileData, (string) $key, $file);
        }
    }

    /**
     * `_.set(fileData, ['formats', key], file)`
     *
     * @param \ArrayObject<string, mixed> $fileData
     * @param \ArrayObject<string, mixed> $file
     */
    private static function setFormat(\ArrayObject $fileData, string $key, \ArrayObject $file): void
    {
        $formats = $fileData['formats'] ?? [];
        $formats = is_array($formats) ? $formats : [];
        $formats[$key] = $file;
        $fileData['formats'] = $formats;
    }

    /**
     * Like uploadImage, but pairs the main file and each format with its old
     * counterpart and routes through provider.replace so providers that implement
     * an atomic replace can use it. Formats that no longer exist on the new image
     * are deleted; formats that didn't exist on the old image are uploaded fresh.
     *
     * @param \ArrayObject<string, mixed> $fileData
     * @param array<string, mixed> $oldFile
     */
    public function replaceImage(\ArrayObject $fileData, array $oldFile): void
    {
        $imageManipulation = Utils::getService('image-manipulation', $this->strapi);
        $provider = Utils::getService('provider', $this->strapi);

        ['width' => $width, 'height' => $height] = $imageManipulation->getDimensions($fileData);

        $fileData['width'] = $width;
        $fileData['height'] = $height;

        $oldFormats = is_array($oldFile['formats'] ?? null) ? $oldFile['formats'] : [];

        $newFormats = [];
        if ($imageManipulation->isResizableImage($fileData)) {
            $thumbnailFile = $imageManipulation->generateThumbnail($fileData);
            if ($thumbnailFile !== null) {
                $newFormats['thumbnail'] = $thumbnailFile;
            }

            foreach ($imageManipulation->generateResponsiveFormats($fileData) as $format) {
                $newFormats[$format['key']] = $format['file'];
            }
        }

        // Replace the main file
        $provider->replace($fileData, $oldFile);

        foreach ($newFormats as $key => $newFormat) {
            $oldFormat = $oldFormats[$key] ?? null;
            if (is_array($oldFormat)) {
                $provider->replace($newFormat, $oldFormat);
            } else {
                $provider->upload($newFormat);
            }
            self::setFormat($fileData, (string) $key, $newFormat);
        }

        // Delete any formats that existed on the old file but are not present on the new one
        if ($oldFormats !== [] && ($oldFile['provider'] ?? null) === $this->strapi->config()->get('plugin::upload.provider')) {
            foreach ($oldFormats as $oldKey => $oldFormat) {
                if (!array_key_exists($oldKey, $newFormats) && is_array($oldFormat)) {
                    $this->uploadProvider()->delete(new \ArrayObject($oldFormat));
                }
            }
        }
    }

    private function uploadProvider(): UploadProvider
    {
        $provider = $this->strapi->plugin('upload')->provider;
        if (!$provider instanceof UploadProvider) {
            throw new \RuntimeException('The upload provider is not initialized');
        }

        return $provider;
    }

    /**
     * Upload a file. If it is an image it will generate a thumbnail
     * and responsive formats (if enabled).
     *
     * @param \ArrayObject<string, mixed> $fileData
     * @param CommonOptions $opts
     * @return array<string, mixed>
     */
    public function uploadFileAndPersist(\ArrayObject $fileData, array $opts = []): array
    {
        $imageManipulation = Utils::getService('image-manipulation', $this->strapi);

        Utils::getService('provider', $this->strapi)->checkFileSize($fileData);

        if ($imageManipulation->isImage($fileData)) {
            $this->uploadImage($fileData);
        } else {
            Utils::getService('provider', $this->strapi)->upload($fileData);
        }

        $fileData['provider'] = $this->strapi->config()->get('plugin::upload.provider');

        // Persist file(s)
        return $this->add($fileData, $opts);
    }

    /**
     * @param array<string, mixed> $fileInfo
     * @param CommonOptions $opts
     * @return array<string, mixed>
     */
    public function updateFileInfo(int|string $id, array $fileInfo, array $opts = []): array
    {
        $dbFile = $this->findOne($id);

        if ($dbFile === null) {
            throw new NotFoundError();
        }

        $fileService = Utils::getService('file', $this->strapi);

        $name = $fileInfo['name'] ?? null;
        $newInfos = [
            'name' => $name ?? $dbFile['name'] ?? null,
            'alternativeText' => $fileInfo['alternativeText'] ?? $dbFile['alternativeText'] ?? null,
            'caption' => $fileInfo['caption'] ?? $dbFile['caption'] ?? null,
            'focalPoint' => $fileInfo['focalPoint'] ?? $dbFile['focalPoint'] ?? null,
            'folder' => array_key_exists('folder', $fileInfo) ? $fileInfo['folder'] : ($dbFile['folder'] ?? null),
            'folderPath' => array_key_exists('folder', $fileInfo)
                ? $fileService->getFolderPath(is_numeric($fileInfo['folder']) ? (int) $fileInfo['folder'] : null)
                : ($dbFile['folderPath'] ?? null),
        ];
        if (!array_key_exists('folder', $fileInfo) && !array_key_exists('folder', $dbFile)) {
            // `folder: dbFile.folder` is undefined when not populated: the relation is left as is
            unset($newInfos['folder']);
        }

        return $this->update($id, $newInfos, $opts);
    }

    /**
     * @param array{data: array<string, mixed>, file: \ArrayObject<string, mixed>} $params
     * @param CommonOptions $opts
     * @return array<string, mixed>
     */
    public function replace(int|string $id, array $params, array $opts = []): array
    {
        $data = $params['data'];
        $file = $params['file'];

        $configProvider = $this->strapi->config()->get('plugin::upload.provider');

        $imageManipulation = Utils::getService('image-manipulation', $this->strapi);

        $dbFile = $this->findOne($id);
        if ($dbFile === null) {
            throw new NotFoundError();
        }

        // create temporary folder to store files for stream manipulation
        $tmpWorkingDirectory = $this->createAndAssignTmpWorkingDirectoryToFiles($file);

        try {
            // `refId` / `ref` / `field` are dropped rather than forwarded: `formatFileInfo` turns
            // them into a one-element `related` array, and a bare array reaches the morph join as
            // `set` — which deletes every row for this file, detaching it from every other entry
            // that uses it. Attaching an existing file to an entry is the content API's job.
            $fileInfo = is_array($data['fileInfo'] ?? null) ? $data['fileInfo'] : null;
            $metas = $data;
            unset($metas['fileInfo'], $metas['refId'], $metas['ref'], $metas['field']);
            $fileData = $this->enhanceAndValidateFile($file, $fileInfo, $metas);

            // Replacing a file writes new bytes just like creating one, so it has to
            // respect sizeLimit too. Checked before any provider write, and measured on
            // the same post-optimization file as the create path (uploadFileAndPersist).
            Utils::getService('provider', $this->strapi)->checkFileSize($fileData);

            // keep a constant hash and extension so the file url doesn't change when the file is replaced
            $fileData['hash'] = $dbFile['hash'] ?? null;
            $fileData['ext'] = $dbFile['ext'] ?? null;

            // A plain replace sends no folder, so `formatFileInfo` resolved folderPath to
            // '/' while the relation survived — and folder deletion selects by folderPath,
            // which orphaned the file. An explicitly sent folder still moves it.
            if ($fileInfo === null || !array_key_exists('folder', $fileInfo)) {
                $fileData['folderPath'] = $dbFile['folderPath'] ?? null;
            }

            // clear old formats — replaceImage / replace will set new ones
            $fileData['formats'] = [];

            if (($dbFile['provider'] ?? null) === $configProvider) {
                if ($imageManipulation->isImage($fileData)) {
                    $this->replaceImage($fileData, $dbFile);
                } else {
                    // The new file is not an image, so it has no formats. Replace the main
                    // file, then delete any formats the old image left behind — otherwise
                    // they're orphaned in storage since the DB record no longer tracks them.
                    Utils::getService('provider', $this->strapi)->replace($fileData, $dbFile);
                    if (is_array($dbFile['formats'] ?? null)) {
                        foreach ($dbFile['formats'] as $format) {
                            if (is_array($format)) {
                                $this->uploadProvider()->delete(new \ArrayObject($format));
                            }
                        }
                    }
                }
            } elseif ($imageManipulation->isImage($fileData)) {
                // Cross-provider replacement: no delete on the old provider, upload to the new one.
                $this->uploadImage($fileData);
            } else {
                Utils::getService('provider', $this->strapi)->upload($fileData);
            }

            $fileData['provider'] = $configProvider;
        } finally {
            // delete temporary folder
            self::removeDirectory($tmpWorkingDirectory);
        }

        return $this->update($id, $fileData, $opts);
    }

    /**
     * @param array<string, mixed>|\ArrayObject<string, mixed> $values
     * @param CommonOptions $opts
     * @return array<string, mixed>
     */
    public function update(int|string $id, array|\ArrayObject $values, array $opts = []): array
    {
        $user = $opts['user'] ?? null;

        $fileValues = (array) Utils::toPlain($values);
        if ($user) {
            $fileValues[ContentTypes::UPDATED_BY_ATTRIBUTE] = self::userId($user);
        }

        $this->sendMediaMetrics($fileValues);

        $res = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->update(['where' => ['id' => $id], 'data' => $fileValues]);

        $this->emitEvent(Constants::ALLOWED_WEBHOOK_EVENTS['MEDIA_UPDATE'], $res);

        return is_array($res) ? $res : [];
    }

    private static function userId(mixed $user): mixed
    {
        if (is_array($user)) {
            return $user['id'] ?? null;
        }

        return is_object($user) && property_exists($user, 'id') ? $user->id : null;
    }

    /**
     * @param array<string, mixed>|\ArrayObject<string, mixed> $values
     * @param CommonOptions $opts
     * @return array<string, mixed>
     */
    public function add(array|\ArrayObject $values, array $opts = []): array
    {
        $user = $opts['user'] ?? null;

        $fileValues = (array) Utils::toPlain($values);
        if ($user) {
            $fileValues[ContentTypes::UPDATED_BY_ATTRIBUTE] = self::userId($user);
            $fileValues[ContentTypes::CREATED_BY_ATTRIBUTE] = self::userId($user);
        }

        $this->sendMediaMetrics($fileValues);

        $res = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->create(['data' => $fileValues]);

        $this->emitEvent(Constants::ALLOWED_WEBHOOK_EVENTS['MEDIA_CREATE'], $res);

        return $res;
    }

    /**
     * @param array<int|string, mixed> $populate
     * @return array<string, mixed>|null
     */
    public function findOne(int|string $id, mixed $populate = []): ?array
    {
        $query = $this->strapi->get('query-params')->transform(Constants::FILE_MODEL_UID, [
            'populate' => $populate,
        ]);

        $file = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findOne([
            'where' => ['id' => $id],
            ...$query,
        ]);

        if (!is_array($file)) {
            return null;
        }

        // Sign file URLs if using private provider
        return Utils::getService('file', $this->strapi)->signFileUrls($file);
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function findMany(array $query = []): array
    {
        $files = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany(
            $this->strapi->get('query-params')->transform(Constants::FILE_MODEL_UID, $query),
        );

        // Sign file URLs if using private provider
        $fileService = Utils::getService('file', $this->strapi);

        return array_values(array_map(static fn (array $file): array => $fileService->signFileUrls($file), $files));
    }

    /**
     * @param array<string, mixed> $query
     * @return array{results: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function findPage(array $query = []): array
    {
        $result = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findPage(
            $this->strapi->get('query-params')->transform(Constants::FILE_MODEL_UID, $query),
        );

        // Sign file URLs if using private provider
        $fileService = Utils::getService('file', $this->strapi);
        $signedResults = array_values(array_map(static fn (array $file): array => $fileService->signFileUrls($file), $result['results']));

        return ['results' => $signedResults, 'pagination' => $result['pagination']];
    }

    /**
     * Resolve whether the count query should run, mirroring core-api's `shouldCount`.
     * Defaults to the `api.rest.withCount` config (true) when not specified on the request.
     *
     * @param array<string, mixed> $pagination
     */
    private function resolveWithCount(array $pagination): bool
    {
        if (array_key_exists('withCount', $pagination)) {
            $withCount = $pagination['withCount'];

            if (is_bool($withCount)) {
                return $withCount;
            }

            if ($withCount === null) {
                return false;
            }

            if (in_array($withCount, ['true', 't', '1', 1], true)) {
                return true;
            }

            if (in_array($withCount, ['false', 'f', '0', 0], true)) {
                return false;
            }

            throw new ValidationError('Invalid withCount parameter. Expected "t","1","true","false","0","f"');
        }

        return (bool) $this->strapi->config()->get('api.rest.withCount', true);
    }

    /**
     * REST-aware paginated find for the content API.
     *
     * Unlike `findPage` (used by the admin API), this honors the standard nested
     * `pagination` query object (`pagination[page]`, `pagination[pageSize]`,
     * `pagination[start]`, `pagination[limit]`, `pagination[withCount]`) and the
     * `api.rest.defaultLimit` / `api.rest.maxLimit` / `api.rest.withCount` config,
     * exactly like every other Strapi REST collection-type endpoint.
     *
     * @param array<string, mixed> $query
     * @return array{results: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function findAndCountPage(array $query = []): array
    {
        $pagination = is_array($query['pagination'] ?? null) ? $query['pagination'] : [];

        $defaultLimit = (int) $this->strapi->config()->get('api.rest.defaultLimit', 25);
        $maxLimit = (int) $this->strapi->config()->get('api.rest.maxLimit');
        $maxLimit = $maxLimit !== 0 ? $maxLimit : null;

        // Whether the consumer used page-based pagination (default) vs offset-based.
        $isOffset = array_key_exists('start', $pagination) || array_key_exists('limit', $pagination);
        $isPaged = !$isOffset;

        // Resolve start/limit applying defaults and the maxLimit cap.
        $resolved = Pagination::withDefaultPagination(
            $pagination,
            ['offset' => ['limit' => $defaultLimit], 'page' => ['pageSize' => $defaultLimit]],
            $maxLimit ?? -1,
        );
        $start = (int) $resolved['start'];
        $limit = (int) $resolved['limit'];

        // Feed the transform the resolved offset/limit so it ignores the nested
        // `pagination` object (which it cannot read) and applies real bounds.
        $transformQuery = [...$query, 'start' => $start, 'limit' => $limit];
        unset($transformQuery['pagination']);
        $transformed = $this->strapi->get('query-params')->transform(Constants::FILE_MODEL_UID, $transformQuery);

        $shouldCount = $this->resolveWithCount($pagination);

        $results = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany($transformed);
        $total = $shouldCount ? (int) $this->strapi->db()->query(Constants::FILE_MODEL_UID)->count($transformed) : null;

        $fileService = Utils::getService('file', $this->strapi);
        $signedResults = array_values(array_map(static fn (array $file): array => $fileService->signFileUrls($file), $results));

        $paginationInfo = $isPaged
            ? Pagination::transformPagedPaginationInfo(['start' => $start, 'limit' => $limit], $total ?? 0)
            : Pagination::transformOffsetPaginationInfo(['start' => $start, 'limit' => $limit], $total ?? 0);

        if ($total === null) {
            // Omit total & pageCount when counting is disabled (withCount=false).
            unset($paginationInfo['total'], $paginationInfo['pageCount']);
        }

        return [
            'results' => $signedResults,
            'pagination' => $paginationInfo,
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    public function remove(array $file): mixed
    {
        $configProvider = $this->strapi->config()->get('plugin::upload.provider');

        // execute delete function of the provider
        if (($file['provider'] ?? null) === $configProvider) {
            $this->uploadProvider()->delete(new \ArrayObject($file));

            if (!empty($file['formats']) && is_array($file['formats'])) {
                foreach ($file['formats'] as $format) {
                    if (is_array($format)) {
                        $this->uploadProvider()->delete(new \ArrayObject($format));
                    }
                }
            }
        }

        $media = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findOne([
            'where' => ['id' => $file['id'] ?? null],
        ]);

        $this->emitEvent(Constants::ALLOWED_WEBHOOK_EVENTS['MEDIA_DELETE'], $media);

        return $this->strapi->db()->query(Constants::FILE_MODEL_UID)->delete(['where' => ['id' => $file['id'] ?? null]]);
    }

    /** @return array<string, mixed>|null */
    public function getSettings(): ?array
    {
        $res = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'upload', 'key' => 'settings']);

        return is_array($res) ? $res : null;
    }

    /** @param array<string, mixed> $value */
    public function setSettings(array $value): void
    {
        if (($value['responsiveDimensions'] ?? null) === true) {
            Utils::getService('metrics', $this->strapi)->trackUsage('didEnableResponsiveDimensions');
        } else {
            Utils::getService('metrics', $this->strapi)->trackUsage('didDisableResponsiveDimensions');
        }

        $this->strapi->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'settings', 'value' => $value]);
    }

    /** @return array<string, mixed>|null */
    public function getConfiguration(): ?array
    {
        $res = $this->strapi->store()->get([
            'type' => 'plugin',
            'name' => 'upload',
            'key' => 'view_configuration',
        ]);

        return is_array($res) ? $res : null;
    }

    /** @param array<string, mixed> $value */
    public function setConfiguration(array $value): void
    {
        $this->strapi->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'view_configuration', 'value' => $value]);
    }
}
