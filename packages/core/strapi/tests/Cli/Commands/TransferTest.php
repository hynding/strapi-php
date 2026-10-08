<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Commands;

use Strapi\Cli\Cli\Commands\Transfer\Action;

/**
 * Port of packages/core/strapi/src/cli/commands/transfer/__tests__/transfer.test.ts (the
 * `assetIdleTimeoutMs` case sets the fixture app's config instead of mocking `strapi.config.get`).
 */
final class TransferTest extends DataTransferCommandTestCase
{
    private const string DESTINATION_URL = 'http://one.localhost/admin';

    private const string DESTINATION_TOKEN = 'test-token';

    private const string SOURCE_URL = 'http://two.localhost/admin';

    private const string SOURCE_TOKEN = 'test-source-token';

    /** @param array<string, mixed> $opts */
    private function transfer(array $opts): int
    {
        $action = new Action([
            ...$this->commonDeps(),
            'createLocalStrapiSourceProvider' => $this->sourceFactory('localSource'),
            'createRemoteStrapiSourceProvider' => $this->sourceFactory('remoteSource'),
            'createLocalStrapiDestinationProvider' => $this->destinationFactory('localDestination'),
            'createRemoteStrapiDestinationProvider' => $this->destinationFactory('remoteDestination'),
        ]);

        return $action($opts, self::input(), $this->output);
    }

    public function testExitsWithErrorWhenNoToOrFromIsProvided(): void
    {
        self::assertSame(1, $this->transfer(['from' => null, 'to' => null]));
        self::assertMatchesRegularExpression('/one source/i', $this->output->fetch());
        self::assertArrayNotHasKey('remoteDestination', $this->created);
    }

    public function testExitsWithErrorWhenBothToAndFromAreProvided(): void
    {
        self::assertSame(1, $this->transfer(['from' => self::SOURCE_URL, 'to' => self::DESTINATION_URL]));
        self::assertMatchesRegularExpression('/one source/i', $this->output->fetch());
        self::assertArrayNotHasKey('remoteDestination', $this->created);
    }

    public function testToExitsWithErrorWhenAuthIsNotProvided(): void
    {
        self::assertSame(1, $this->transfer(['to' => self::DESTINATION_URL]));
        self::assertMatchesRegularExpression('/missing token/i', $this->output->fetch());
        self::assertArrayNotHasKey('remoteDestination', $this->created);
    }

    public function testToUsesDestinationUrlAndTokenAndALocalSource(): void
    {
        self::assertSame(0, $this->transfer(['to' => self::DESTINATION_URL, 'toToken' => self::DESTINATION_TOKEN]));

        self::assertSubset([
            'url' => self::DESTINATION_URL,
            'auth' => ['type' => 'token', 'token' => self::DESTINATION_TOKEN],
            'strategy' => 'restore',
        ], $this->created['remoteDestination'][0]);
        self::assertArrayHasKey('localSource', $this->created);
        self::assertStringContainsString('Transfer process has been completed successfully!', $this->output->fetch());
    }

    public function testToPassesVerifyChecksumsOnlyWhenChecksumsAreEnabled(): void
    {
        self::assertSame(0, $this->transfer(['to' => self::DESTINATION_URL, 'toToken' => self::DESTINATION_TOKEN, 'checksums' => true]));
        self::assertTrue($this->created['remoteDestination'][0]['verifyChecksums']);

        self::assertSame(0, $this->transfer(['to' => self::DESTINATION_URL, 'toToken' => self::DESTINATION_TOKEN, 'checksums' => false]));
        self::assertArrayNotHasKey('verifyChecksums', $this->created['remoteDestination'][1]);
    }

    public function testFromExitsWithErrorWhenAuthIsNotProvided(): void
    {
        self::assertSame(1, $this->transfer(['from' => self::SOURCE_URL]));
        self::assertMatchesRegularExpression('/missing token/i', $this->output->fetch());
        self::assertArrayNotHasKey('remoteSource', $this->created);
    }

    public function testFromUsesSourceUrlAndTokenAndALocalDestination(): void
    {
        self::assertSame(0, $this->transfer(['from' => self::SOURCE_URL, 'fromToken' => self::SOURCE_TOKEN]));

        self::assertSubset([
            'url' => self::SOURCE_URL,
            'auth' => ['type' => 'token', 'token' => self::SOURCE_TOKEN],
        ], $this->created['remoteSource'][0]);
        self::assertSame('restore', $this->created['localDestination'][0]['strategy']);
    }

    public function testFromPassesVerifyChecksumsOnlyWhenChecksumsAreEnabled(): void
    {
        self::assertSame(0, $this->transfer(['from' => self::SOURCE_URL, 'fromToken' => self::SOURCE_TOKEN, 'checksums' => true]));
        self::assertTrue($this->created['remoteSource'][0]['verifyChecksums']);

        self::assertSame(0, $this->transfer(['from' => self::SOURCE_URL, 'fromToken' => self::SOURCE_TOKEN, 'checksums' => false]));
        self::assertArrayNotHasKey('verifyChecksums', $this->created['remoteSource'][1]);
    }

    public function testFromPassesAssetIdleTimeoutMsAsStreamTimeout(): void
    {
        self::strapi()->config()->set('server.transfer.remote.assetIdleTimeoutMs', 99000);

        try {
            self::assertSame(0, $this->transfer(['from' => self::SOURCE_URL, 'fromToken' => self::SOURCE_TOKEN]));
        } finally {
            self::strapi()->config()->set('server.transfer.remote.assetIdleTimeoutMs', null);
        }

        self::assertSubset(['url' => self::SOURCE_URL, 'streamTimeout' => 99000], $this->created['remoteSource'][0]);
    }
}
