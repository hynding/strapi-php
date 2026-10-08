<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes;

/**
 * Not an upstream file: `SESServiceException` of `@aws-sdk/client-ses` — the SES error `Code` as
 * `name` (`MessageRejected`, `MailFromDomainNotVerifiedException`, …), its `Message` as message.
 */
final class SesServiceException extends \RuntimeException
{
    /** @var array{httpStatusCode: int, requestId: string|null} */
    public readonly array $metadata;

    public function __construct(string $message, public readonly string $name, int $httpStatusCode, ?string $requestId = null)
    {
        parent::__construct($message, $httpStatusCode);
        $this->metadata = ['httpStatusCode' => $httpStatusCode, 'requestId' => $requestId];
    }
}
