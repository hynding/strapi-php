<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

/**
 * A ValidationError whose details carry a list of formatted field errors, in the shape
 * upstream produces from a yup ValidationError: `{ errors: [{ path, message, name, value }] }`.
 *
 * @phpstan-type FormattedError array{path: list<string>, message: string, name: string, value: mixed}
 */
class YupValidationError extends ValidationError
{
    /**
     * @param list<FormattedError> $errors
     */
    public function __construct(array $errors, ?string $message = null, ?\Throwable $previous = null)
    {
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
