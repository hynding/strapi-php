<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Utils\UploadProvider;
use Strapi\DataTransfer\Utils\Stream\PassThrough;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Transaction;

/**
 * Port of src/strapi/providers/local-destination/assets-destination-writable.ts: the writable
 * that restores upload assets.
 *
 * Upstream buffers each asset's chunks and calls `uploadStream` once the asset stream has ended
 * (so a remote push can keep feeding chunks in later WebSocket frames). Here an asset whose
 * `stream` is a {@see PassThrough} still being filled (remote push) is uploaded when it ends; any
 * other iterable is read right away.
 *
 * @phpstan-type AssetsWritableOptions array{strapi: Strapi, transaction: Transaction, resolveUploadFileId: callable(array<string, mixed>): (int|null), restoreMediaEntitiesContent: bool, removeAssetsBackup: callable(): void}
 */
final class AssetsDestinationWritable
{
    /** @param AssetsWritableOptions $options */
    public static function createAssetsDestinationWritable(array $options): Writable
    {
        [
            'strapi' => $strapi,
            'transaction' => $transaction,
            'resolveUploadFileId' => $resolveUploadFileId,
            'restoreMediaEntitiesContent' => $restoreMediaEntitiesContent,
            'removeAssetsBackup' => $removeAssetsBackup,
        ] = $options;

        $pendingUploads = 0;
        /** @var Writable|null $writable */
        $writable = null;

        $upload = static function (array $chunk, int $fileId, string $contents) use ($strapi, $transaction, $restoreMediaEntitiesContent): void {
            $config = $strapi->config()->get('plugin::upload');
            $provider = is_array($config) ? ($config['provider'] ?? null) : null;

            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Could not create a temporary stream');
            }
            fwrite($stream, $contents);
            rewind($stream);

            // Build uploadData here so the stream is fully populated before the provider reads it.
            $uploadData = new \ArrayObject([
                ...(is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : []),
                'stream' => $stream,
                ...(isset($chunk['buffer']) ? ['buffer' => $chunk['buffer']] : []),
            ]);

            try {
                $transaction->attach(static function () use ($strapi, $uploadData, $restoreMediaEntitiesContent, $fileId, $provider, $chunk): void {
                    try {
                        UploadProvider::call($strapi, 'uploadStream', $uploadData);

                        if (!$restoreMediaEntitiesContent) {
                            return;
                        }

                        if (!empty($uploadData['type'])) {
                            $entry = $strapi->db()->query('plugin::upload.file')->findOne([
                                'where' => ['id' => $fileId],
                            ]);
                            if ($entry === null) {
                                throw new \RuntimeException('file not found');
                            }
                            $formats = is_array($entry['formats'] ?? null) ? $entry['formats'] : null;
                            if ($formats !== null && isset($formats[$uploadData['type']]) && is_array($formats[$uploadData['type']])) {
                                $formats[$uploadData['type']]['url'] = $uploadData['url'] ?? null;
                            }
                            $strapi->db()->query('plugin::upload.file')->update([
                                'where' => ['id' => $entry['id']],
                                'data' => [
                                    'formats' => $formats,
                                    'provider' => $provider,
                                ],
                            ]);

                            return;
                        }

                        $entry = $strapi->db()->query('plugin::upload.file')->findOne([
                            'where' => ['id' => $fileId],
                        ]);
                        if ($entry === null) {
                            throw new \RuntimeException('file not found');
                        }
                        $strapi->db()->query('plugin::upload.file')->update([
                            'where' => ['id' => $entry['id']],
                            'data' => [
                                'url' => $uploadData['url'] ?? null,
                                'provider' => $provider,
                            ],
                        ]);
                    } catch (\Throwable $error) {
                        throw new \RuntimeException("Error while uploading asset {$chunk['filename']} Error: {$error->getMessage()}", 0, $error);
                    }
                });
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        };

        $writable = new Writable(
            write: static function (mixed $chunk) use ($resolveUploadFileId, &$pendingUploads, $upload, &$writable): void {
                $chunk = is_array($chunk) ? $chunk : [];
                $metadata = is_array($chunk['metadata'] ?? null) ? $chunk['metadata'] : [];

                $fileId = $resolveUploadFileId($metadata);
                if (!$fileId) {
                    throw new \RuntimeException('File ID not found for ID: ' . (is_scalar($metadata['id'] ?? null) ? (string) $metadata['id'] : 'undefined'));
                }

                $stream = $chunk['stream'] ?? [];

                if ($stream instanceof PassThrough && !$stream->finished) {
                    // filled chunk by chunk by the remote push handler: upload once it ends
                    ++$pendingUploads;
                    $stream->onEnd(static function () use ($stream, $chunk, $fileId, $upload, &$pendingUploads, &$writable): void {
                        try {
                            $upload($chunk, $fileId, $stream->contents());
                        } catch (\Throwable $error) {
                            $writable?->destroy($error);
                            throw $error;
                        } finally {
                            --$pendingUploads;
                        }
                    });
                    $stream->onError(static function (\Throwable $err) use (&$pendingUploads, &$writable): void {
                        --$pendingUploads;
                        $writable?->destroy($err);
                    });

                    return;
                }

                // Accumulate all binary chunks, then upload
                $contents = '';
                foreach (is_iterable($stream) ? $stream : [] as $data) {
                    $contents .= (string) $data;
                }

                $upload($chunk, $fileId, $contents);
            },
            final: static function () use ($removeAssetsBackup): void {
                // uploads run synchronously when their stream ends
                $removeAssetsBackup();
            },
        );

        return $writable;
    }
}
