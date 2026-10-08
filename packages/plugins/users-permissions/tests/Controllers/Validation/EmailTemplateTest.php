<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Controllers\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\EmailTemplate;

/** Port of server/src/controllers/validation/__tests__/email-template.test.js. */
final class EmailTemplateTest extends TestCase
{
    public function testAcceptsOneValidPattern(): void
    {
        self::assertTrue(EmailTemplate::isValidEmailTemplate('<%= CODE %>'));
        self::assertTrue(EmailTemplate::isValidEmailTemplate('<%=CODE%>'));
    }

    public function testRefusesInvalidPatterns(): void
    {
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<%- CODE %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<% CODE %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<%= <% CODE %> %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<%- <% CODE %> %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('${ <% CODE %> }'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<%CODE%>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('${CODE}'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('${ CODE }'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate(
            '<%=`${ console.log({ "remote-execution": { "foo": "bar" }/*<>%=*/ }) }`%>'
        ));
    }

    public function testFailsOnNonAuthorizedKeys(): void
    {
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<% random expression %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<% random expression }%>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<% some.var.azdazd %>'));
        self::assertFalse(EmailTemplate::isValidEmailTemplate('<% function() %>'));
    }
}
