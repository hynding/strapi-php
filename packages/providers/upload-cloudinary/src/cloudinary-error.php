<?php

declare(strict_types=1);

namespace Strapi\Provider\UploadCloudinary;

use Strapi\Utils\Errors\ApplicationError;

/**
 * PHP-port addition: the error object the Cloudinary SDK rejects with — `{ message, http_code,
 * name }` from the API's `{ error: { message } }` body, or a transport error. `status` and
 * `httpCode` carry `http_code`.
 */
class CloudinaryError extends ApplicationError
{
    public function __construct(string $message, public readonly int $httpCode = 0, string $name = 'Error', ?\Throwable $previous = null)
    {
        parent::__construct($message, ['http_code' => $httpCode], $previous);
        $this->name = $name;
        $this->status = $httpCode > 0 ? $httpCode : 500;
    }
}
