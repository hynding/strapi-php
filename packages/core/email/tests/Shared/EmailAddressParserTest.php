<?php

declare(strict_types=1);

namespace Strapi\Email\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Strapi\Email\Shared\EmailAddressParser;

/** shared/email-address-parser.ts (no upstream test): the examples of its doc comments. */
final class EmailAddressParserTest extends TestCase
{
    public function testParsesTheDocumentedFormats(): void
    {
        $cases = [
            'Strapi <no-reply@strapi.io>' => ['Strapi', 'no-reply@strapi.io'],
            '=?UTF-8?B?U3RyYXBp?= <no-reply@strapi.io>' => ['Strapi', 'no-reply@strapi.io'],
            'no-reply@strapi.io (Strapi Support)' => ['Strapi Support', 'no-reply@strapi.io'],
            'email@example.com' => [null, 'email@example.com'],
            '"Doe, John" <john@example.com>' => ['Doe, John', 'john@example.com'],
            '"Support \\"24/7\\"" <s@example.com>' => ['Support "24/7"', 's@example.com'],
            '=?UTF-8?Q?M=C3=BCller?= <m@example.com>' => ['Müller', 'm@example.com'],
            '=?ISO-8859-1?Q?=E4=F6=FC?= <a@example.com>' => ['äöü', 'a@example.com'],
            '(comment) <a@example.com>' => ['comment', 'a@example.com'],
            '"Name" <a@example.com> (comment)' => ['Name', 'a@example.com'],
            '=?UTF-8?B?!!!?= <a@example.com>' => ['=?UTF-8?B?!!!?=', 'a@example.com'],
            'not-an-email' => [null, 'not-an-email'],
        ];

        foreach ($cases as $input => [$name, $email]) {
            self::assertSame(['name' => $name, 'email' => $email, 'original' => $input], EmailAddressParser::parseEmailAddress($input), $input);
        }

        self::assertSame(['name' => null, 'email' => '', 'original' => ''], EmailAddressParser::parseEmailAddress(null));
    }

    public function testFormatsAddresses(): void
    {
        self::assertSame('Strapi <no-reply@strapi.io>', EmailAddressParser::formatEmailAddress('Strapi', 'no-reply@strapi.io'));
        self::assertSame('"Doe, John" <john@example.com>', EmailAddressParser::formatEmailAddress('Doe, John', 'john@example.com'));
        self::assertSame('"Say \\"hi\\"" <a@b.c>', EmailAddressParser::formatEmailAddress('Say "hi"', 'a@b.c'));
        self::assertSame('a@b.c', EmailAddressParser::formatEmailAddress(null, 'a@b.c'));
    }

    public function testValidatesAddresses(): void
    {
        self::assertTrue(EmailAddressParser::isValidEmail('no-reply@strapi.io'));
        self::assertTrue(EmailAddressParser::isValidEmail('müller@bücher.de'));
        self::assertFalse(EmailAddressParser::isValidEmail('no-reply'));
        self::assertFalse(EmailAddressParser::isValidEmail('a@-b.c'));
        self::assertFalse(EmailAddressParser::isValidEmail(''));
    }

    public function testParsesMultipleAddresses(): void
    {
        $parsed = EmailAddressParser::parseMultipleEmailAddresses('a@example.com, "Doe, John" <b@example.com>, (x, y) c@example.com');

        self::assertSame([
            ['name' => null, 'email' => 'a@example.com', 'original' => 'a@example.com'],
            ['name' => 'Doe, John', 'email' => 'b@example.com', 'original' => '"Doe, John" <b@example.com>'],
            ['name' => 'x, y', 'email' => 'c@example.com', 'original' => '(x, y) c@example.com'],
        ], $parsed);
        self::assertSame([], EmailAddressParser::parseMultipleEmailAddresses(''));
    }
}
