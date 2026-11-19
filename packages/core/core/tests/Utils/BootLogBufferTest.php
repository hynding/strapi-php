<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Utils\BootLogBuffer;

/** What a boot logs where every request boots: held until bootstrap ends, dropped when settled. */
final class BootLogBufferTest extends TestCase
{
    private TestHandler $inner;

    private BootLogBuffer $buffer;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->inner = new TestHandler();
        $this->buffer = new BootLogBuffer([$this->inner]);
        $this->logger = new Logger('strapi', [$this->buffer]);
    }

    /** @return list<string> */
    private function logged(): array
    {
        return array_values(array_map(static fn (\Monolog\LogRecord $record): string => "{$record->level->getName()}: {$record->message}", $this->inner->getRecords()));
    }

    public function testAnUnsettledBootLogsWhatItHeld(): void
    {
        $this->logger->info('starting');
        $this->logger->warning('a config warning');
        self::assertSame([], $this->logged());

        $this->buffer->release(false);
        $this->logger->info('a request');

        self::assertSame(['INFO: starting', 'WARNING: a config warning', 'INFO: a request'], $this->logged());
    }

    public function testASettledBootDropsWhatItHeld(): void
    {
        $this->logger->warning('a config warning');
        $this->buffer->release(true);
        $this->logger->info('a request');

        self::assertSame(['INFO: a request'], $this->logged());
    }

    public function testAnErrorLogsAtOnceAfterWhatWasHeld(): void
    {
        $this->logger->warning('a config warning');
        $this->logger->error('the database is gone');
        $this->buffer->release(true);

        self::assertSame(['WARNING: a config warning', 'ERROR: the database is gone'], $this->logged());
    }

    public function testASettledShutdownIsHeldAndDropped(): void
    {
        $this->buffer->release(true);
        $this->logger->info('a request');
        $this->buffer->hold();
        $this->logger->info('Shutting down Strapi');
        $this->buffer->release(true);

        self::assertSame(['INFO: a request'], $this->logged());
    }

    public function testClosingLogsWhatIsStillHeld(): void
    {
        // a request that ends before bootstrap released the buffer (an exit() in a boot step):
        // closing the logger, as Monolog does when the handler is destroyed, keeps what was held
        // (a fatal error runs no destructor: testAFatalErrorLogsWhatWasHeld)
        $this->logger->warning('logged before the request ended');
        $this->logger->close();

        self::assertSame(['WARNING: logged before the request ended'], $this->logged());
    }

    public function testAFatalErrorLogsWhatWasHeld(): void
    {
        // a boot that dies of a fatal error (here memory_limit) runs no destructor
        $autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
        $script = <<<'PHP'
            require $argv[1];
            $logger = new Monolog\Logger('strapi', [new Strapi\Core\Utils\BootLogBuffer([new Monolog\Handler\StreamHandler('php://stdout')])]);
            $logger->warning('logged before the fatal error');
            ini_set('memory_limit', '16M');
            $grow = [];
            while (true) {
                $grow[] = str_repeat('x', 1024 * 1024);
            }
            PHP;
        $output = (string) shell_exec(implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'display_errors=0', '-r', $script, $autoload])));

        self::assertStringContainsString('strapi.WARNING: logged before the fatal error', $output);
    }

    public function testRecordsTheInnerHandlersIgnoreAreNotHeld(): void
    {
        $this->inner->setLevel(Level::Warning);
        $this->logger->debug('below every handler');
        $this->logger->warning('kept');
        $this->buffer->release(false);

        self::assertSame(['WARNING: kept'], $this->logged());
    }
}
