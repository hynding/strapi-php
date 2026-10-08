<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Validation\Zod;
use Strapi\Utils\FormatYupError;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Zod as z;

/**
 * Port of server/src/validation/__tests__/zod-yup-compat.test.ts. (Upstream's formatted errors
 * also carry `value: undefined`, which neither PHP formatter emits: JSON drops it.)
 */
final class ZodYupCompatTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     * @return array{errors: list<array<string, mixed>>, message: string}
     */
    private static function yupFormatted(Yup\YupObject $schema, array $options = []): array
    {
        try {
            $schema->validate([], $options);
        } catch (YupError $e) {
            return FormatYupError::formatYupErrors($e);
        }
        self::fail('yup should have thrown');
    }

    /** @return array{errors: list<array<string, mixed>>, message: string} */
    private static function zodFormatted(\Strapi\Utils\Zod\ZodType $schema): array
    {
        $result = $schema->safeParse([]);
        self::assertFalse($result['success']);
        self::assertInstanceOf(\Strapi\Utils\Zod\ZodError::class, $result['error']);

        return Zod::formatZodErrors($result['error']);
    }

    public function testSingleRequiredFieldProducesSameErrorShape(): void
    {
        $yupFormatted = self::yupFormatted(Yup::object(['name' => Yup::string()->required('name is required')]));
        $zodFormatted = self::zodFormatted(z::object(['name' => z::string()]));

        self::assertCount(count($yupFormatted['errors']), $zodFormatted['errors']);
        self::assertSame($yupFormatted['errors'][0]['path'], $zodFormatted['errors'][0]['path']);
        self::assertSame($yupFormatted['errors'][0]['name'], $zodFormatted['errors'][0]['name']);
        self::assertIsString($zodFormatted['errors'][0]['message']);
    }

    public function testMultipleFieldErrorsProduceSameNumberOfErrors(): void
    {
        $yupFormatted = self::yupFormatted(
            Yup::object(['name' => Yup::string()->required('name is required'), 'age' => Yup::number()->required('age is required')]),
            ['abortEarly' => false],
        );
        $zodFormatted = self::zodFormatted(z::object(['name' => z::string(), 'age' => z::number()]));

        self::assertCount(count($yupFormatted['errors']), $zodFormatted['errors']);
    }

    public function testErrorObjectsHaveIdenticalKeys(): void
    {
        $yupError = self::yupFormatted(Yup::object(['name' => Yup::string()->required()]))['errors'][0];
        $zodError = self::zodFormatted(z::object(['name' => z::string()]))['errors'][0];

        // Both have identical keys: message, name, path (+ value, which upstream's zod formatter sets
        // to `undefined` — absent from JSON — and which this port leaves out)
        $yupKeys = array_values(array_diff(array_keys($yupError), ['value']));
        $zodKeys = array_keys($zodError);
        sort($yupKeys);
        sort($zodKeys);
        self::assertSame($yupKeys, $zodKeys);
    }
}
