<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\HttpError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\NotImplementedError;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Errors\PayloadTooLargeError;
use Strapi\Utils\Errors\PolicyError;
use Strapi\Utils\Errors\RateLimitError;
use Strapi\Utils\Errors\UnauthorizedError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Errors\YupValidationError;

final class ErrorsTest extends TestCase
{
    /** @return iterable<string, array{0: ApplicationError, 1: string, 2: int, 3: string}> */
    public static function errors(): iterable
    {
        yield 'application' => [new ApplicationError(), 'ApplicationError', 400, 'An application error occurred'];
        yield 'validation' => [new ValidationError('bad'), 'ValidationError', 400, 'bad'];
        yield 'pagination' => [new PaginationError(), 'PaginationError', 400, 'Invalid pagination'];
        yield 'not found' => [new NotFoundError(), 'NotFoundError', 404, 'Entity not found'];
        yield 'forbidden' => [new ForbiddenError(), 'ForbiddenError', 403, 'Forbidden access'];
        yield 'unauthorized' => [new UnauthorizedError(), 'UnauthorizedError', 401, 'Unauthorized'];
        yield 'rate limit' => [new RateLimitError(), 'RateLimitError', 429, 'Too many requests, please try again later.'];
        yield 'payload too large' => [new PayloadTooLargeError(), 'PayloadTooLargeError', 413, 'Entity too large'];
        yield 'policy' => [new PolicyError(), 'PolicyError', 403, 'Policy Failed'];
        yield 'not implemented' => [new NotImplementedError(), 'NotImplementedError', 500, 'This feature is not implemented yet'];
    }

    #[DataProvider('errors')]
    public function testNameStatusAndDefaultMessage(ApplicationError $error, string $name, int $status, string $message): void
    {
        self::assertSame($name, $error->name);
        self::assertSame($status, $error->status);
        self::assertSame($message, $error->getMessage());
        self::assertSame([], $error->details);
        self::assertInstanceOf(ApplicationError::class, $error);
        self::assertInstanceOf(\RuntimeException::class, $error);
    }

    public function testDetailsAndToArray(): void
    {
        $error = new PolicyError('Nope', ['policy' => 'global::is-authenticated']);

        self::assertInstanceOf(ForbiddenError::class, $error);
        self::assertSame([
            'status' => 403,
            'name' => 'PolicyError',
            'message' => 'Nope',
            'details' => ['policy' => 'global::is-authenticated'],
        ], $error->toArray());
    }

    public function testYupValidationErrorCarriesFormattedErrors(): void
    {
        $errors = [['path' => ['title'], 'message' => 'title is required', 'name' => 'ValidationError', 'value' => null]];
        $error = new YupValidationError($errors);

        self::assertInstanceOf(ValidationError::class, $error);
        self::assertSame('ValidationError', $error->name);
        self::assertSame('title is required', $error->getMessage());
        self::assertSame(['errors' => $errors], $error->details);
        self::assertSame($errors, $error->errors());
        self::assertSame('custom', (new YupValidationError($errors, 'custom'))->getMessage());
    }

    public function testHttpErrorDerivesNameFromStatus(): void
    {
        $error = new HttpError(404);
        self::assertSame(404, $error->status);
        self::assertSame('NotFoundError', $error->name);
        self::assertSame('Not Found', $error->getMessage());

        self::assertSame('TooManyRequestsError', (new HttpError(429, 'slow down'))->name);
        self::assertSame('Custom', (new HttpError(418, 'teapot', [], 'Custom'))->name);
    }

    public function testFromThrowableWrapsUnknownErrors(): void
    {
        $app = new ValidationError('x');
        self::assertSame($app, Errors::fromThrowable($app));

        $wrapped = Errors::fromThrowable(new \RuntimeException('boom'));
        self::assertInstanceOf(HttpError::class, $wrapped);
        self::assertSame(500, $wrapped->status);
        self::assertSame('InternalServerError', $wrapped->name);
        self::assertSame('boom', $wrapped->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $wrapped->getPrevious());

        self::assertSame(
            ['status' => 400, 'body' => ['data' => null, 'error' => ['status' => 400, 'name' => 'ValidationError', 'message' => 'x', 'details' => []]]],
            Errors::format($app),
        );
    }
}
