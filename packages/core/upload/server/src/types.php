<?php

declare(strict_types=1);

namespace Strapi\Upload;

/**
 * Port of server/src/types.ts (type-only): the shapes the upload services exchange.
 *
 * `InputFile` and `UploadableFile` are `\ArrayObject`s at runtime (see `Utils::toInputFile()`).
 *
 * @phpstan-type FocalPoint array{x: int|float, y: int|float}
 * @phpstan-type File array{id: int, name: string, alternativeText?: string|null, caption?: string|null, focalPoint?: FocalPoint|null, width?: int|null, height?: int|null, formats?: array<string, mixed>|null, hash: string, ext?: string|null, mime?: string|null, size?: int|float, sizeInBytes?: int, url?: string, previewUrl?: string|null, path?: string|null, provider?: string, provider_metadata?: array<string, mixed>|null, isUrlSigned?: bool, folder?: int|array<string, mixed>|null, folderPath?: string, createdAt?: string, updatedAt?: string}
 * @phpstan-type Folder array{id: int, name: string, pathId: int, parent?: int|null, children?: list<int>, path: string, files?: list<File>}
 * @phpstan-type Config array{provider: string, sizeLimit?: int|float, providerOptions: array<string, mixed>, actionOptions: array<string, mixed>, sharp?: array{cache?: bool, concurrency?: int}, concurrentUploadSize?: int, concurrentUploadRequests?: int, security?: array{allowedTypes?: list<string>, deniedTypes?: list<string>}}
 * @phpstan-type FileInfo array{name?: string|null, alternativeText?: string|null, caption?: string|null, focalPoint?: FocalPoint|null, folder?: int|null}
 */
final class Types
{
}
