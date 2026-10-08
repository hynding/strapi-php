<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadAwsS3;

use Strapi\Utils\Errors\ApplicationError;

/**
 * PHP-port addition: `S3ServiceException` from `@aws-sdk/client-s3`. `name` is the S3 error
 * `Code` (`NoSuchKey`, `AccessDenied`, `PreconditionFailed`…, or `NotFound` for a body-less 404
 * HEAD), `status` and `metadata['httpStatusCode']` the HTTP status (`$metadata` upstream).
 */
class S3ServiceException extends ApplicationError
{
    /** @var array{httpStatusCode: int, requestId?: string} */
    public array $metadata;

    /** @param array<string, mixed> $details */
    public function __construct(string $name, string $message, int $httpStatusCode, array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
        $this->name = $name;
        $this->status = $httpStatusCode;
        $this->metadata = ['httpStatusCode' => $httpStatusCode];
        if (is_string($details['RequestId'] ?? null)) {
            $this->metadata['requestId'] = $details['RequestId'];
        }
    }
}
