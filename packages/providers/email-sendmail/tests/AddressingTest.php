<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailSendmail\Addressing;

/** Port of __tests__/addressing.vitest.test.ts. */
final class AddressingTest extends TestCase
{
    public function testExtractEmail(): void
    {
        self::assertSame('user@example.com', Addressing::extractEmail('Name <user@example.com>'));
        self::assertSame('user@example.com', Addressing::extractEmail('user@example.com'));
        self::assertSame('user@example.com', Addressing::extractEmail('  user@example.com  '));
    }

    public function testParseAddressList(): void
    {
        self::assertSame(['a@x.com', 'b@y.com'], Addressing::parseAddressList('a@x.com, b@y.com'));
        self::assertSame([], Addressing::parseAddressList(null));
        self::assertSame(['a@x.com', 'b@y.com'], Addressing::parseAddressList('A <a@x.com>, B <b@y.com>'));
        // accepts array input (legacy sendmail behavior)
        self::assertSame(['a@x.com', 'b@y.com'], Addressing::parseAddressList(['A <a@x.com>', 'b@y.com']));
    }

    public function testGetHostFromAddress(): void
    {
        self::assertSame('mail.example.org', Addressing::getHostFromAddress('user@mail.example.org'));
        // legacy first-match host extraction on multiple @
        self::assertSame('local', Addressing::getHostFromAddress('odd@local@mail.example.org'));
        self::assertNull(Addressing::getHostFromAddress('not-an-email'));
        // legacy regex behavior for non-ascii domains
        self::assertSame('m', Addressing::getHostFromAddress('user@münchen.de'));
        // input exceeding max length before regex
        self::assertNull(Addressing::getHostFromAddress(str_repeat('a', 315) . '@x.com'));
    }

    public function testGroupRecipientsByDomain(): void
    {
        self::assertSame(
            ['foo.com' => ['a@foo.com', 'b@foo.com'], 'bar.org' => ['c@bar.org']],
            Addressing::groupRecipientsByDomain(['a@foo.com', 'b@foo.com', 'c@bar.org']),
        );
        // malformed addresses under "undefined" (legacy object-key coercion)
        self::assertSame(['undefined' => ['not-an-email']], Addressing::groupRecipientsByDomain(['not-an-email']));
    }

    public function testCollectRecipients(): void
    {
        self::assertSame(['a@x.com', 'b@y.com', 'c@z.com'], Addressing::collectRecipients(['to' => 'a@x.com', 'cc' => 'b@y.com', 'bcc' => 'c@z.com']));
        self::assertSame(['a@x.com', 'b@y.com', 'c@z.com'], Addressing::collectRecipients(['to' => ['a@x.com', 'b@y.com'], 'cc' => ['c@z.com'], 'bcc' => []]));
    }
}
