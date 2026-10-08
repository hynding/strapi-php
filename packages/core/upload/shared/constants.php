<?php

declare(strict_types=1);

namespace Strapi\Upload\Shared;

/**
 * Port of shared/constants.ts: constants shared by the upload server and the admin panel.
 *
 * The server stays authoritative — it validates and rejects — but the admin
 * needs the same values to disable actions up front rather than letting the
 * user discover a limit through a failed request.
 */
final class Constants
{
    /**
     * Image formats the AI metadata provider can read.
     *
     * Anything outside this list (non-images, but also exotic image formats like
     * SVG or TIFF) is reported as `skipped` instead of being sent.
     *
     * @see https://ai.google.dev/gemini-api/docs/image-understanding
     */
    public const array AI_METADATA_SUPPORTED_IMAGE_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    /**
     * Number of images sent to the AI server per request when generating metadata
     * for an explicit selection. Matches the URL cap of the bulk URL upload flow.
     */
    public const int AI_METADATA_CHUNK_SIZE = 20;

    /**
     * Upper bound on a single synchronous AI metadata request.
     *
     * The selection is processed in sequential chunks inside one HTTP request, so
     * an unbounded selection means an unbounded request — long past most proxy and
     * load balancer timeouts, with no way for the client to learn what was written.
     * Rejecting with a 400 lets the UI ask for a smaller selection instead.
     */
    public const int AI_METADATA_MAX_FILES = self::AI_METADATA_CHUNK_SIZE * 2;

    /** Whether the AI metadata provider can generate metadata for this mime type. */
    public static function isAIMetadataSupportedMime(?string $mime): bool
    {
        return in_array($mime, self::AI_METADATA_SUPPORTED_IMAGE_TYPES, true);
    }
}
