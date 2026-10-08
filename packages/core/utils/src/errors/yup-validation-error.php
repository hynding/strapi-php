<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

use Strapi\Utils\FormatYupError;
use Strapi\Utils\Yup\YupError;

/**
 * A ValidationError whose details carry a list of formatted field errors, in the shape
 * upstream produces from a yup ValidationError: `{ errors: [{ path, message, name, value }] }`.
 *
 * Upstream builds it from a yup error (`new YupValidationError(yupError, message?)`, details from
 * `formatYupErrors`); passing a {@see YupError} does the same. A list of already formatted
 * errors is accepted too.
 *
 * @phpstan-type FormattedError array{path: list<string>, message: string, name: string, value?: mixed}
 */
class YupValidationError extends ValidationError
{
    /**
     * @param YupError|list<FormattedError> $errors
     */
    public function __construct(YupError|array $errors, ?string $message = null, ?\Throwable $previous = null)
    {
        if ($errors instanceof YupError) {
            $formatted = FormatYupError::formatYupErrors($errors);
            parent::__construct($message !== null && $message !== '' ? $message : $formatted['message'], ['errors' => $formatted['errors']], $previous ?? $errors);

            return;
        }

        $derived = count($errors) === 1 ? $errors[0]['message'] : implode('; ', array_map(static fn (array $e): string => $e['message'], $errors));

        parent::__construct($message ?? ($derived !== '' ? $derived : 'Validation'), ['errors' => $errors], $previous);
    }

    /** @return list<FormattedError> */
    public function errors(): array
    {
        /** @var list<FormattedError> $errors */
        $errors = $this->details['errors'] ?? [];

        return $errors;
    }
}
