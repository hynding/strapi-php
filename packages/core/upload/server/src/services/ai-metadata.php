<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Shared\Constants as SharedConstants;
use Strapi\Upload\Utils\Images;
use Strapi\Upload\Utils\Utils;

/**
 * Port of server/src/services/ai-metadata.ts.
 *
 * @phpstan-type AIMetadataFileResult array{id: int, status: 'success'|'error'|'skipped', error?: string}
 * @phpstan-type Metadata array{altText: string, caption: string}
 */
final class AiMetadata
{
    /**
     * Supported image types for AI metadata generation. Lives in `shared/` so the
     * admin panel gates the bulk action on exactly what the provider accepts.
     */
    private const array SUPPORTED_IMAGE_TYPES = Constants::AI_METADATA_SUPPORTED_IMAGE_TYPES;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array<string, mixed> $file */
    private static function isSupportedImage(array $file): bool
    {
        $mime = $file['mime'] ?? null;

        return SharedConstants::isAIMetadataSupportedMime(is_string($mime) ? $mime : null);
    }

    public function isEnabled(): bool
    {
        if (!Utils::getService('aiMetadataProvider', $this->strapi)->hasProvider()) {
            return false;
        }
        $settings = Utils::getService('upload', $this->strapi)->getSettings() ?? [];

        return (bool) ($settings['aiMetadata'] ?? true);
    }

    /** @return array<string, mixed> */
    private static function withoutMetadataWhere(): array
    {
        return [
            'mime' => [
                '$in' => self::SUPPORTED_IMAGE_TYPES,
            ],
            '$or' => [
                ['alternativeText' => ['$null' => true]],
                ['alternativeText' => ''],
                ['caption' => ['$null' => true]],
                ['caption' => ''],
            ],
        ];
    }

    /** @return array{imagesWithoutMetadataCount: int, totalImages: int} */
    public function countImagesWithoutMetadata(): array
    {
        $imagesWithoutMetadataCount = (int) $this->strapi->db()->query(Constants::FILE_MODEL_UID)->count([
            'where' => self::withoutMetadataWhere(),
        ]);

        $totalImages = (int) $this->strapi->db()->query(Constants::FILE_MODEL_UID)->count([
            'where' => [
                'mime' => [
                    '$in' => self::SUPPORTED_IMAGE_TYPES,
                ],
            ],
        ]);

        return ['imagesWithoutMetadataCount' => $imagesWithoutMetadataCount, 'totalImages' => $totalImages];
    }

    /**
     * Update files with AI-generated metadata
     * Shared logic used by both upload flow and retroactive processing
     *
     * @param list<array<string, mixed>> $files updated in place (needed for upload flow response)
     * @param list<Metadata|null> $metadataResults
     */
    public function updateFilesWithAIMetadata(array &$files, array $metadataResults, mixed $user): void
    {
        $uploadService = Utils::getService('upload', $this->strapi);

        foreach ($files as $index => $file) {
            $aiMetadata = $metadataResults[$index] ?? null;
            if ($aiMetadata === null) {
                continue;
            }

            // Only update fields that are missing (null or empty string)
            $updateData = [];

            if (empty($file['alternativeText'])) {
                $updateData['alternativeText'] = $aiMetadata['altText'];
            }

            if (empty($file['caption'])) {
                $updateData['caption'] = $aiMetadata['caption'];
            }

            // Only update if there are fields to update
            if ($updateData !== []) {
                $uploadService->updateFileInfo($file['id'], $updateData, ['user' => $user]);

                // Update in-memory file object (needed for upload flow response)
                $files[$index] = [...$file, ...$updateData];
            }
        }
    }

    /**
     * Process existing files with job tracking for progress updates
     */
    public function processExistingFiles(int $jobId, mixed $user): void
    {
        $jobService = Utils::getService('aiMetadataJobs', $this->strapi);

        try {
            // Mark as processing
            $jobService->updateJob($jobId, ['status' => 'processing']);

            // Query all images without metadata
            $files = array_values($this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany([
                'where' => self::withoutMetadataWhere(),
            ]));

            if ($files === []) {
                $jobService->updateJob($jobId, [
                    'status' => 'completed',
                    'completedAt' => new \DateTimeImmutable(),
                ]);

                return;
            }

            // Process all files at once
            $metadataResults = $this->processFiles($files);
            $this->updateFilesWithAIMetadata($files, $metadataResults, $user);

            // Mark as completed
            $jobService->updateJob($jobId, [
                'status' => 'completed',
                'completedAt' => new \DateTimeImmutable(),
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('AI metadata job failed', [
                'jobId' => $jobId,
                'error' => $error->getMessage(),
            ]);

            $jobService->updateJob($jobId, [
                'status' => 'failed',
                'completedAt' => new \DateTimeImmutable(),
            ]);
        }
    }

    /**
     * Generate AI metadata for an explicit selection of files, synchronously.
     *
     * Unlike `processExistingFiles`, this does not create a job and does not
     * filter on missing metadata — the selection is what the user asked for.
     * Existing alt text / captions are still preserved by
     * `updateFilesWithAIMetadata`, so a file that already has both is reported
     * as `success` without being modified.
     *
     * Images are processed in sequential chunks so one failing chunk only
     * affects its own files; every other chunk still runs.
     *
     * @experimental
     *
     * @param list<int|string> $fileIds
     * @return list<AIMetadataFileResult>
     */
    public function generateForFiles(array $fileIds, mixed $user): array
    {
        // `yup.strapiID()` accepts both numbers and numeric strings without
        // coercing, so normalise here — otherwise a request sending `["1"]`
        // would never match the numeric ids coming back from the database.
        $normalisedIds = array_map(static fn (int|string $id): int => (int) $id, $fileIds);
        $uniqueIds = array_values(array_unique($normalisedIds));

        $files = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany([
            'where' => ['id' => ['$in' => $uniqueIds]],
        ]);

        $filesById = [];
        foreach ($files as $file) {
            $filesById[(int) $file['id']] = $file;
        }

        // Keyed by input id so the response order matches the request order.
        $resultsById = [];
        $images = [];

        foreach ($uniqueIds as $id) {
            $file = $filesById[$id] ?? null;

            if ($file === null) {
                $resultsById[$id] = ['id' => $id, 'status' => 'error', 'error' => 'File not found'];
                continue;
            }

            // Only the formats the AI provider understands are sent; anything else
            // (non-images, but also exotic image formats like SVG or TIFF) is
            // reported as skipped rather than failed.
            if (!self::isSupportedImage($file)) {
                $resultsById[$id] = ['id' => $id, 'status' => 'skipped'];
                continue;
            }

            $images[] = $file;
        }

        foreach (array_chunk($images, Constants::AI_METADATA_CHUNK_SIZE) as $imageChunk) {
            try {
                $metadataResults = $this->processFiles($imageChunk);
                $this->updateFilesWithAIMetadata($imageChunk, $metadataResults, $user);

                foreach ($imageChunk as $index => $file) {
                    $fileId = (int) $file['id'];
                    $resultsById[$fileId] = ($metadataResults[$index] ?? null) !== null
                        ? ['id' => $fileId, 'status' => 'success']
                        : ['id' => $fileId, 'status' => 'error', 'error' => 'AI metadata generation returned no result'];
                }
            } catch (\Throwable $error) {
                $message = $error->getMessage() !== '' ? $error->getMessage() : 'AI metadata generation failed';

                $this->strapi->log()->error('AI metadata generation failed for a chunk of files', [
                    'fileIds' => array_map(static fn (array $file): mixed => $file['id'], $imageChunk),
                    'message' => $message,
                ]);

                foreach ($imageChunk as $file) {
                    $resultsById[(int) $file['id']] = ['id' => (int) $file['id'], 'status' => 'error', 'error' => $message];
                }
            }
        }

        return array_map(
            static fn (int $id): array => $resultsById[$id] ?? ['id' => $id, 'status' => 'error', 'error' => 'File not processed'],
            $normalisedIds,
        );
    }

    /**
     * Processes provided files for AI metadata generation
     *
     * @param list<array<string, mixed>> $files
     * @return list<Metadata|null>
     */
    public function processFiles(array $files): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('AI Metadata service is not enabled');
        }

        // Filter for image files only and track their original positions
        // We need to maintain the original indices so we can map AI results back correctly
        $imageFiles = [];
        foreach ($files as $index => $file) {
            $mime = $file['mime'] ?? null;
            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                $imageFiles[] = ['file' => $file, 'originalIndex' => $index];
            }
        }

        // Convert filtered image files to InputFile format (uses thumbnails when available)
        $imageInputFiles = array_map(static function (array $imageFile): array {
            $file = $imageFile['file'];
            $formats = $file['formats'] ?? null;
            $thumbnail = is_array($formats) ? ($formats['thumbnail'] ?? null) : null;

            return [
                'filepath' => (is_array($thumbnail) ? ($thumbnail['url'] ?? null) : null) ?: (($file['url'] ?? null) ?: ''),
                'mimetype' => $file['mime'] ?? null,
                'originalFilename' => $file['name'] ?? null,
                'size' => (is_array($thumbnail) ? ($thumbnail['size'] ?? null) : null) ?: ($file['size'] ?? null),
                'provider' => $file['provider'] ?? null,
            ];
        }, $imageFiles);

        $emptyResults = array_fill(0, count($files), null);

        // If no image files, return sparse array with all nulls to avoid calling the AI server
        // This maintains the same array length as input files for proper index alignment
        if ($imageFiles === []) {
            return $emptyResults;
        }

        $absoluteUrl = $this->strapi->config()->get('server.absoluteUrl');
        $images = Images::fetchImagesAsBlobs($this->strapi, $imageInputFiles, is_string($absoluteUrl) ? $absoluteUrl : '', $this->strapi->log());

        $results = Utils::getService('aiMetadataProvider', $this->strapi)->generateMetadata(['images' => $images])['results'];

        // Create sparse array with results at original indices
        // Example: files=[img1, pdf, img2] -> imageFiles=[{img1, index:0}, {img2, index:2}]
        // AI results=[meta1, meta2] -> sparse=[meta1, null, meta2]
        // This ensures metadata[i] corresponds to files[i], with null for non-images
        foreach ($imageFiles as $resultIndex => $imageFile) {
            $emptyResults[$imageFile['originalIndex']] = $results[$resultIndex] ?? null;
        }

        return array_values($emptyResults);
    }
}
