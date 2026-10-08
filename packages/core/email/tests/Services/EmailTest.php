<?php

declare(strict_types=1);

namespace Strapi\Email\Tests\Services;

require_once dirname(__DIR__) . '/EmailTestCase.php';

use Strapi\Email\Tests\EmailTestCase;
use Strapi\Email\Tests\RecordingEmailProvider;

/** services/email.ts (no upstream unit test): send, sendTemplatedEmail, getProviderSettings. */
final class EmailTest extends EmailTestCase
{
    public function testSendDelegatesToTheProvider(): void
    {
        $provider = new RecordingEmailProvider();
        self::useProvider($provider);

        self::strapi()->plugin('email')->service('email')->send(['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T']);

        self::assertSame([['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T']], $provider->sent);
    }

    public function testGetProviderSettingsReturnsThePluginConfig(): void
    {
        $settings = self::strapi()->plugin('email')->service('email')->getProviderSettings();

        self::assertSame('sendmail', $settings['provider']);
        self::assertSame(['defaultFrom' => 'Strapi <no-reply@strapi.io>'], $settings['settings']);
    }

    public function testSendTemplatedEmailFillsSubjectTextAndHtml(): void
    {
        $provider = new RecordingEmailProvider();
        self::useProvider($provider);

        self::strapi()->plugin('email')->service('email')->sendTemplatedEmail(
            ['to' => 'jane@example.com', 'from' => 'admin@example.com'],
            [
                'subject' => 'Hello <%= user.firstname %>',
                'text' => 'Reset: <%=url%> for <%= user.email %> (<%= user.lastname %>)',
                'html' => '<p><%= url %></p><p><%= count %> <%= flag %></p>',
            ],
            [
                'url' => 'http://localhost/reset?code=abc',
                'user' => ['email' => 'jane@example.com', 'firstname' => 'Jane', 'lastname' => null],
                'count' => 3,
                'flag' => true,
            ],
        );

        self::assertSame([[
            'to' => 'jane@example.com',
            'from' => 'admin@example.com',
            'subject' => 'Hello Jane',
            'text' => 'Reset: http://localhost/reset?code=abc for jane@example.com ()',
            'html' => '<p>http://localhost/reset?code=abc</p><p>3 true</p>',
        ]], $provider->sent);
    }

    public function testFalsyTemplateAttributesAreLeftOut(): void
    {
        $provider = new RecordingEmailProvider();
        self::useProvider($provider);

        self::strapi()->plugin('email')->service('email')->sendTemplatedEmail(
            ['to' => 'a@b.c'],
            ['subject' => 'S', 'text' => 'T <%= url %>', 'html' => ''],
            ['url' => 'u'],
        );

        self::assertSame([['to' => 'a@b.c', 'subject' => 'S', 'text' => 'T u']], $provider->sent);
    }

    public function testMissingTemplateAttributesThrow(): void
    {
        $this->expectExceptionMessage('Following attributes are missing from your email template : text, html');

        self::strapi()->plugin('email')->service('email')->sendTemplatedEmail(['to' => 'a@b.c'], ['subject' => 'S'], []);
    }

    public function testExpressionsOtherThanAllowedDataPathsAreRejected(): void
    {
        self::useProvider(new RecordingEmailProvider());
        $this->expectExceptionMessage('unsupported template expression');

        self::strapi()->plugin('email')->service('email')->sendTemplatedEmail(
            ['to' => 'a@b.c'],
            ['subject' => 'S', 'text' => '<%= process.env.SECRET %>', 'html' => 'h'],
            ['url' => 'u'],
        );
    }
}
