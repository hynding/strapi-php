<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailMailgun\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Provider\EmailMailgun\MailgunClient;

/** Port of src/__tests__/convert-provider-options.vitest.test.ts (`mailgun.client(...)` → MailgunClient). */
final class ConvertProviderOptionsTest extends TestCase
{
    public function testSuccessfullyCreatesANewMailgunClient(): void
    {
        $defaults = ['username' => 'api'];
        $providerOptions = ['key' => 'foo', 'username' => 'bar', 'domain' => 'baz.example.com'];

        $mg = new MailgunClient([...$defaults, ...$providerOptions], static fn (): array => []);

        self::assertSame([], $mg->headers);
        self::assertSame($providerOptions['key'], $mg->key);
        self::assertSame('https://api.mailgun.net', $mg->url);
        self::assertSame($providerOptions['username'], $mg->username);
    }

    public function testFailsToCreateANewMailgunClientDueToMissingKey(): void
    {
        $this->expectExceptionMessage('Parameter "key" is required');

        new MailgunClient(['username' => 'api', ...['username' => 'bar', 'domain' => 'baz.example.com']], static fn (): array => []);
    }
}
