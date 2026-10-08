<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailNodemailer\Utils\EmailAddress as E;

/** Port of src/__tests__/email-address.vitest.test.ts. */
final class EmailAddressTest extends TestCase
{
    // parseEmailAddress — basic formats

    public function testParsesASimpleEmailAddress(): void
    {
        $result = E::parseEmailAddress('test@example.com');
        self::assertNull($result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    public function testParsesNameWithAngleBrackets(): void
    {
        $result = E::parseEmailAddress('John Doe <john@example.com>');
        self::assertSame('John Doe', $result['name']);
        self::assertSame('john@example.com', $result['email']);
    }

    public function testParsesQuotedName(): void
    {
        $result = E::parseEmailAddress('"Doe, John" <john@example.com>');
        self::assertSame('Doe, John', $result['name']);
        self::assertSame('john@example.com', $result['email']);
    }

    public function testHandlesEmptyInput(): void
    {
        self::assertSame(['name' => null, 'email' => '', 'original' => ''], E::parseEmailAddress(''));
        self::assertSame(['name' => null, 'email' => '', 'original' => ''], E::parseEmailAddress(null));
    }

    // RFC 2047 encoded-words

    public function testDecodesBase64EncodedName(): void
    {
        $result = E::parseEmailAddress('=?UTF-8?B?U3RyYXBp?= <no-reply@strapi.io>');
        self::assertSame('Strapi', $result['name']);
        self::assertSame('no-reply@strapi.io', $result['email']);
    }

    public function testDecodesQuotedPrintableEncodedName(): void
    {
        $result = E::parseEmailAddress('=?UTF-8?Q?M=C3=BCller?= <mueller@example.com>');
        self::assertSame('Müller', $result['name']);
        self::assertSame('mueller@example.com', $result['email']);
    }

    public function testDecodesBase64WithSpecialCharacters(): void
    {
        $encoded = base64_encode('日本語');
        $result = E::parseEmailAddress("=?UTF-8?B?{$encoded}?= <japanese@example.com>");
        self::assertSame('日本語', $result['name']);
        self::assertSame('japanese@example.com', $result['email']);
    }

    public function testHandlesLowercaseEncodingMarkers(): void
    {
        self::assertSame('Strapi', E::parseEmailAddress('=?utf-8?b?U3RyYXBp?= <no-reply@strapi.io>')['name']);
    }

    public function testPreservesOriginalOnDecodeFailure(): void
    {
        self::assertSame('test@example.com', E::parseEmailAddress('=?INVALID?X?garbage?= <test@example.com>')['email']);
    }

    // RFC 5322 comments

    public function testExtractsCommentAfterEmail(): void
    {
        $result = E::parseEmailAddress('support@example.com (Support Team)');
        self::assertSame('Support Team', $result['name']);
        self::assertSame('support@example.com', $result['email']);
    }

    public function testExtractsCommentBeforeEmail(): void
    {
        $result = E::parseEmailAddress('(Admin) admin@example.com');
        self::assertSame('Admin', $result['name']);
        self::assertSame('admin@example.com', $result['email']);
    }

    public function testHandlesNestedComments(): void
    {
        $result = E::parseEmailAddress('test@example.com (Outer (Inner) Comment)');
        self::assertSame('Outer (Inner) Comment', $result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    public function testPrefersNameOverCommentWhenBothPresent(): void
    {
        $result = E::parseEmailAddress('Name <test@example.com> (Comment)');
        self::assertSame('Name', $result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    public function testUsesCommentAsNameWhenNoExplicitName(): void
    {
        $result = E::parseEmailAddress('<test@example.com> (Display Name)');
        self::assertSame('Display Name', $result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    // RFC 5322 quoted strings

    public function testHandlesNameWithComma(): void
    {
        self::assertSame('Last, First', E::parseEmailAddress('"Last, First" <test@example.com>')['name']);
    }

    public function testHandlesNameWithSpecialCharacters(): void
    {
        self::assertSame('Support (24/7)', E::parseEmailAddress('"Support (24/7)" <support@example.com>')['name']);
    }

    public function testHandlesEscapedQuotesInName(): void
    {
        self::assertSame('Say "Hello"', E::parseEmailAddress('"Say \\"Hello\\"" <test@example.com>')['name']);
    }

    public function testHandlesEscapedBackslash(): void
    {
        self::assertSame('Back\\slash', E::parseEmailAddress('"Back\\\\slash" <test@example.com>')['name']);
    }

    // edge cases

    public function testHandlesWhitespace(): void
    {
        $result = E::parseEmailAddress('  Name   <  test@example.com  >  ');
        self::assertSame('Name', $result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    public function testPreservesOriginalString(): void
    {
        self::assertSame('Test <test@example.com>', E::parseEmailAddress('Test <test@example.com>')['original']);
    }

    public function testHandlesEmailWithoutNameButWithAngleBrackets(): void
    {
        $result = E::parseEmailAddress('<test@example.com>');
        self::assertNull($result['name']);
        self::assertSame('test@example.com', $result['email']);
    }

    // email normalization (RFC 5321)

    public function testLowercasesTheEntireEmailAddress(): void
    {
        self::assertSame('user@example.com', E::parseEmailAddress('User@Example.COM')['email']);
    }

    public function testLowercasesEmailInAngleBracketFormat(): void
    {
        $result = E::parseEmailAddress('John Doe <John.Doe@Example.COM>');
        self::assertSame('john.doe@example.com', $result['email']);
        self::assertSame('John Doe', $result['name']);
    }

    public function testLowercasesEmailWithCommentFormat(): void
    {
        $result = E::parseEmailAddress('Admin@EXAMPLE.ORG (Administrator)');
        self::assertSame('admin@example.org', $result['email']);
        self::assertSame('Administrator', $result['name']);
    }

    public function testPreservesOriginalCasingInOriginalField(): void
    {
        $result = E::parseEmailAddress('User@Example.COM');
        self::assertSame('user@example.com', $result['email']);
        self::assertSame('User@Example.COM', $result['original']);
    }

    public function testHandlesMixedCaseWithRfc2047Encoding(): void
    {
        $result = E::parseEmailAddress('=?UTF-8?B?U3RyYXBp?= <No-Reply@Strapi.IO>');
        self::assertSame('no-reply@strapi.io', $result['email']);
        self::assertSame('Strapi', $result['name']);
    }

    // normalizeEmail

    public function testNormalizeEmail(): void
    {
        self::assertSame('user@example.com', E::normalizeEmail('User@Example.COM'));
        self::assertSame('user@example.com', E::normalizeEmail('user@example.com'));
        self::assertSame('', E::normalizeEmail(''));
    }

    // parseMultipleEmailAddresses

    public function testParsesMultipleSimpleAddresses(): void
    {
        $result = E::parseMultipleEmailAddresses('a@example.com, b@example.com');
        self::assertCount(2, $result);
        self::assertSame('a@example.com', $result[0]['email']);
        self::assertSame('b@example.com', $result[1]['email']);
    }

    public function testParsesMultipleAddressesWithNames(): void
    {
        $result = E::parseMultipleEmailAddresses('Name A <a@example.com>, Name B <b@example.com>');
        self::assertCount(2, $result);
        self::assertSame('Name A', $result[0]['name']);
        self::assertSame('Name B', $result[1]['name']);
    }

    public function testParsesQuotedNamesWithCommas(): void
    {
        $result = E::parseMultipleEmailAddresses('"Doe, John" <a@example.com>, b@example.com');
        self::assertCount(2, $result);
        self::assertSame('Doe, John', $result[0]['name']);
        self::assertSame('b@example.com', $result[1]['email']);
    }

    public function testParseMultipleHandlesEmptyInput(): void
    {
        self::assertSame([], E::parseMultipleEmailAddresses(''));
        self::assertSame([], E::parseMultipleEmailAddresses(null));
    }

    public function testHandlesEscapedBackslashBeforeQuoteCorrectly(): void
    {
        $result = E::parseMultipleEmailAddresses('"Back\\\\" <a@example.com>, b@example.com');
        self::assertCount(2, $result);
        self::assertSame('a@example.com', $result[0]['email']);
        self::assertSame('b@example.com', $result[1]['email']);
    }

    // formatEmailAddress

    public function testFormatsSimpleAddress(): void
    {
        self::assertSame('John Doe <john@example.com>', E::formatEmailAddress('John Doe', 'john@example.com'));
    }

    public function testReturnsJustEmailWhenNoName(): void
    {
        self::assertSame('test@example.com', E::formatEmailAddress(null, 'test@example.com'));
    }

    public function testQuotesNameWithSpecialCharacters(): void
    {
        self::assertSame('"Doe, John" <john@example.com>', E::formatEmailAddress('Doe, John', 'john@example.com'));
    }

    public function testEncodesNonAsciiCharacters(): void
    {
        $result = E::formatEmailAddress('Müller', 'mueller@example.com');
        self::assertMatchesRegularExpression('/^=\?UTF-8\?B\?.*\?= <mueller@example\.com>$/', $result);
        self::assertSame('Müller', E::parseEmailAddress($result)['name']);
    }

    public function testSkipsEncodingWhenDisabled(): void
    {
        self::assertSame('Müller <mueller@example.com>', E::formatEmailAddress('Müller', 'mueller@example.com', ['encodeNonAscii' => false]));
    }

    public function testNormalizesEmailToLowercase(): void
    {
        self::assertSame('John Doe <john.doe@example.com>', E::formatEmailAddress('John Doe', 'John.Doe@Example.COM'));
        self::assertSame('admin@example.org', E::formatEmailAddress(null, 'Admin@EXAMPLE.ORG'));
    }

    // decodeRfc2047

    public function testDecodeRfc2047(): void
    {
        self::assertSame('Hello World', E::decodeRfc2047('=?UTF-8?B?SGVsbG8gV29ybGQ=?='));
        self::assertSame('Hello World', E::decodeRfc2047('=?UTF-8?Q?Hello_World?='));
        self::assertSame('Hello World', E::decodeRfc2047('=?UTF-8?B?SGVsbG8=?= =?UTF-8?B?V29ybGQ=?='));
        self::assertSame('Normal text without encoding', E::decodeRfc2047('Normal text without encoding'));
    }

    // encodeRfc2047Base64

    public function testEncodeRfc2047Base64(): void
    {
        self::assertSame('=?UTF-8?B?TcO8bGxlcg==?=', E::encodeRfc2047Base64('Müller'));
        self::assertSame('Hello', E::encodeRfc2047Base64('Hello'));
    }

    public function testSplitsLongNonAsciiNamesIntoMultipleEncodedWords(): void
    {
        $longName = str_repeat("\u{00fc}", 100);
        $parts = explode(' ', E::encodeRfc2047Base64($longName));

        self::assertGreaterThan(1, count($parts));
        $decoded = '';
        foreach ($parts as $part) {
            self::assertMatchesRegularExpression('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $part);
            self::assertLessThanOrEqual(75, strlen($part));
            $decoded .= base64_decode((string) preg_replace(['/^=\?UTF-8\?B\?/', '/\?=$/'], '', $part));
        }
        self::assertSame($longName, $decoded);
    }

    public function testDoesNotSplitMidCharacterForMultiByteUtf8(): void
    {
        $emojiName = str_repeat("\u{1F600}", 20);
        $decoded = '';
        foreach (explode(' ', E::encodeRfc2047Base64($emojiName)) as $part) {
            $chunk = base64_decode((string) preg_replace(['/^=\?UTF-8\?B\?/', '/\?=$/'], '', $part));
            self::assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            $decoded .= $chunk;
        }
        self::assertSame($emojiName, $decoded);
    }

    // encodeRfc2047QuotedPrintable

    public function testEncodeRfc2047QuotedPrintable(): void
    {
        $result = E::encodeRfc2047QuotedPrintable('Müller');
        self::assertMatchesRegularExpression('/^=\?UTF-8\?Q\?.*\?=$/', $result);
        self::assertSame('Müller', E::decodeRfc2047($result));
        self::assertSame('Hello', E::encodeRfc2047QuotedPrintable('Hello'));
    }

    // extractComments

    public function testExtractComments(): void
    {
        self::assertSame(['text' => 'text', 'comments' => ['comment']], E::extractComments('text (comment)'));
        self::assertSame(['text' => 'text', 'comments' => ['first', 'second']], E::extractComments('(first) text (second)'));
        self::assertSame(['outer (inner) more'], E::extractComments('text (outer (inner) more)')['comments']);
        self::assertSame(['text' => '"(not a comment)" text', 'comments' => []], E::extractComments('"(not a comment)" text'));
    }

    public function testHandlesUnmatchedClosingParenthesis(): void
    {
        self::assertSame(['text' => 'text ) more text', 'comments' => []], E::extractComments('text ) more text'));
        self::assertSame(['text' => 'a ) b', 'comments' => ['valid']], E::extractComments('a ) b (valid)'));
    }

    // unquoteString

    public function testUnquoteString(): void
    {
        self::assertSame('Hello', E::unquoteString('"Hello"'));
        self::assertSame('Say "Hi"', E::unquoteString('"Say \\"Hi\\""'));
        self::assertSame('Hello', E::unquoteString('Hello'));
    }

    // isValidEmail

    public function testValidatesCorrectEmails(): void
    {
        self::assertTrue(E::isValidEmail('test@example.com'));
        self::assertTrue(E::isValidEmail('user.name@example.com'));
        self::assertTrue(E::isValidEmail('user+tag@example.com'));
    }

    public function testRejectsInvalidEmails(): void
    {
        self::assertFalse(E::isValidEmail(''));
        self::assertFalse(E::isValidEmail('invalid'));
        self::assertFalse(E::isValidEmail('@example.com'));
        self::assertFalse(E::isValidEmail('test@'));
    }

    public function testRejectsDotsInTheWrongPlaces(): void
    {
        self::assertFalse(E::isValidEmail('user..name@example.com'));
        self::assertFalse(E::isValidEmail('.user@example.com'));
        self::assertFalse(E::isValidEmail('user.@example.com'));
    }

    public function testEnforcesLengthLimits(): void
    {
        self::assertFalse(E::isValidEmail(str_repeat('a', 65) . '@example.com'));
        self::assertTrue(E::isValidEmail(str_repeat('a', 64) . '@example.com'));

        $longDomain = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.com';
        self::assertFalse(E::isValidEmail("user@{$longDomain}"));

        $email = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.' . str_repeat('e', 62) . '.com';
        self::assertGreaterThan(320, strlen($email));
        self::assertFalse(E::isValidEmail($email));
    }
}
