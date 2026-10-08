<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Token;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of server/src/services/__tests__/token.test.ts. */
final class TokenTest extends TestCase
{
    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
        $this->strapi->config()->set('admin.auth', []);
    }

    public function testExpiresInToSecondsReturnsNullForNull(): void
    {
        self::assertNull(Token::expiresInToSeconds(null));
    }

    public function testExpiresInToSecondsAcceptsNumericSeconds(): void
    {
        self::assertSame(3600, Token::expiresInToSeconds(3600));
    }

    public function testExpiresInToSecondsAcceptsNumericStringSeconds(): void
    {
        self::assertSame(180, Token::expiresInToSeconds('180'));
    }

    public function testParses30d(): void
    {
        self::assertSame(30 * 24 * 60 * 60, Token::expiresInToSeconds('30d'));
    }

    public function testParsesShorthands(): void
    {
        self::assertSame(12 * 60 * 60, Token::expiresInToSeconds('12h'));
        self::assertSame(15 * 60, Token::expiresInToSeconds('15m'));
        self::assertSame(45, Token::expiresInToSeconds('45s'));
    }

    public function testParses1wAs7Days(): void
    {
        self::assertSame(7 * 24 * 60 * 60, Token::expiresInToSeconds('1w'));
    }

    public function testReturnsNullForInvalidStrings(): void
    {
        self::assertNull(Token::expiresInToSeconds('abc'));
        self::assertNull(Token::expiresInToSeconds('10y'));
        self::assertNull(Token::expiresInToSeconds(''));
    }

    public function testHasDefaultsWhenNoConfigurationIsProvided(): void
    {
        self::assertSame(['secret' => null, 'options' => ['expiresIn' => '30d']], (new Token($this->strapi))->getTokenOptions());
    }

    public function testMergesDefaultsWithLegacyAuthOptions(): void
    {
        $this->strapi->config()->set('admin.auth', ['options' => ['algorithm' => 'HS256', 'expiresIn' => '1d'], 'secret' => '123']);

        self::assertEquals(['secret' => '123', 'options' => ['algorithm' => 'HS256', 'expiresIn' => '1d']], (new Token($this->strapi))->getTokenOptions());
    }

    public function testUsesNewSessionsOptions(): void
    {
        $this->strapi->config()->set('admin.auth', ['options' => [], 'secret' => '123', 'sessions' => ['options' => ['algorithm' => 'HS384', 'issuer' => 'sessions-issuer']]]);

        self::assertEquals(['secret' => '123', 'options' => ['expiresIn' => '30d', 'algorithm' => 'HS384', 'issuer' => 'sessions-issuer']], (new Token($this->strapi))->getTokenOptions());
    }

    public function testSessionsOptionsTakePriorityOverLegacyAuthOptions(): void
    {
        $this->strapi->config()->set('admin.auth', [
            'secret' => '123',
            'options' => ['algorithm' => 'HS256', 'issuer' => 'legacy-issuer', 'audience' => 'legacy-audience'],
            'sessions' => ['options' => ['algorithm' => 'HS512', 'issuer' => 'sessions-issuer']],
        ]);

        $options = (new Token($this->strapi))->getTokenOptions()['options'];
        self::assertSame('HS512', $options['algorithm']);
        self::assertSame('sessions-issuer', $options['issuer']);
        self::assertSame('legacy-audience', $options['audience']);
    }

    public function testSupportsAsymmetricAlgorithmConfiguration(): void
    {
        $this->strapi->config()->set('admin.auth', ['secret' => '123', 'options' => ['algorithm' => 'RS256', 'privateKey' => 'PRIVATE', 'publicKey' => 'PUBLIC']]);

        $options = (new Token($this->strapi))->getTokenOptions()['options'];
        self::assertSame(['expiresIn' => '30d', 'algorithm' => 'RS256', 'privateKey' => 'PRIVATE', 'publicKey' => 'PUBLIC'], $options);
    }

    public function testHasUserConfiguredAuthOptionsExpiresIn(): void
    {
        self::assertFalse(Token::hasUserConfiguredAuthOptionsExpiresIn(null));
        self::assertFalse(Token::hasUserConfiguredAuthOptionsExpiresIn([]));
        self::assertFalse(Token::hasUserConfiguredAuthOptionsExpiresIn('7d'));
        self::assertTrue(Token::hasUserConfiguredAuthOptionsExpiresIn(['expiresIn' => '7d']));
        self::assertFalse(Token::hasUserConfiguredAuthOptionsExpiresIn(['expiresIn' => null]));
    }

    public function testCreateTokenIsARandomTokenOfLength40(): void
    {
        $token = (new Token($this->strapi))->createToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        self::assertNotSame($token, (new Token($this->strapi))->createToken());
    }

    public function testCheckSecretIsDefined(): void
    {
        $this->strapi->config()->set('admin.serveAdminPanel', true);
        $this->strapi->config()->set('admin.auth.secret', 'abc');
        (new Token($this->strapi))->checkSecretIsDefined();

        $this->strapi->config()->set('admin.auth.secret', null);
        $this->expectExceptionMessage('Missing auth.secret');
        (new Token($this->strapi))->checkSecretIsDefined();
    }
}
