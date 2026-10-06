<?php

declare(strict_types=1);

namespace Strapi\Logger\Tests;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Strapi\Logger\Configs\DefaultConfiguration;
use Strapi\Logger\Configs\OutputFileConfiguration;
use Strapi\Logger\Constants;
use Strapi\Logger\Formats\DetailedLog;
use Strapi\Logger\Formats\ExcludeColors;
use Strapi\Logger\Formats\LevelFilter;
use Strapi\Logger\Formats\LogErrors;
use Strapi\Logger\Formats\PrettyPrint;
use Strapi\Logger\Logger;

final class LoggerTest extends TestCase
{
    private static function record(Level $level = Level::Info, string $message = 'hello', array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable('2024-01-02 03:04:05.678'), 'strapi', $level, $message, $context);
    }

    public function testConstants(): void
    {
        self::assertSame(6, Constants::LEVEL);
        self::assertSame('silly', Constants::LEVEL_LABEL);
        self::assertSame(['error' => 0, 'warn' => 1, 'info' => 2, 'http' => 3, 'verbose' => 4, 'debug' => 5, 'silly' => 6], Constants::LEVELS);
        self::assertSame(Level::Debug, Constants::toMonologLevel('silly'));
        self::assertSame(Level::Warning, Constants::toMonologLevel('warn'));
        self::assertSame(Level::Error, Constants::toMonologLevel('ERROR'));
        self::assertSame(Level::Critical, Constants::toMonologLevel('critical'));
        self::assertSame(Level::Info, Constants::toMonologLevel(200));
        self::assertSame('warn', Constants::toWinstonLabel(Level::Warning));
        self::assertSame('error', Constants::toWinstonLabel(Level::Critical));
    }

    public function testCreateLoggerReturnsAMonologLoggerWithTheDefaultConfiguration(): void
    {
        $logger = Logger::createLogger();

        self::assertInstanceOf(MonologLogger::class, $logger);
        self::assertSame('strapi', $logger->getName());
        self::assertCount(1, $logger->getHandlers());
        self::assertInstanceOf(StreamHandler::class, $logger->getHandlers()[0]);
        self::assertInstanceOf(PrettyPrint::class, $logger->getHandlers()[0]->getFormatter());
        self::assertTrue($logger->isHandling(Level::Debug));
    }

    public function testUserConfigurationOverridesTheDefaults(): void
    {
        $handler = new TestHandler();
        $logger = Logger::createLogger(['level' => 'warn', 'transports' => [$handler], 'name' => 'custom']);

        $logger->info('ignored');
        $logger->warning('kept');
        $logger->error('kept too');

        self::assertSame('custom', $logger->getName());
        self::assertCount(2, $handler->getRecords());
        self::assertFalse($handler->hasInfoRecords());
        self::assertInstanceOf(PrettyPrint::class, $handler->getFormatter());
    }

    public function testFormatIsNotForcedOnTransportsWithTheirOwnFormatter(): void
    {
        $handler = new TestHandler();
        $handler->setFormatter(new ExcludeColors());
        Logger::createLogger(['transports' => [$handler]]);

        self::assertInstanceOf(ExcludeColors::class, $handler->getFormatter());
    }

    public function testInvalidTransportsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Logger::createLogger(['transports' => ['nope']]);
    }

    public function testPrettyPrint(): void
    {
        $format = new PrettyPrint();
        self::assertSame("[2024-01-02 03:04:05.678] \033[32minfo\033[39m: hello\n", $format->format(self::record()));

        $plain = new PrettyPrint(['colors' => false]);
        self::assertSame("[2024-01-02 03:04:05.678] warn: hello\n", $plain->format(self::record(Level::Warning)));

        $noTimestamp = new PrettyPrint(['timestamps' => false, 'colors' => false]);
        self::assertSame("error: hello\n", $noTimestamp->format(self::record(Level::Error)));

        $custom = new PrettyPrint(['timestamps' => 'H:i', 'colors' => false]);
        self::assertSame("[03:04] debug: hello\n", $custom->format(self::record(Level::Debug)));
    }

    public function testPrettyPrintAppendsErrorStacks(): void
    {
        $error = new \RuntimeException('boom');
        $line = (new PrettyPrint(['colors' => false]))->format(self::record(Level::Error, 'boom', ['exception' => $error]));

        self::assertStringStartsWith("[2024-01-02 03:04:05.678] error: boom\n#0 ", $line);
    }

    public function testLogErrors(): void
    {
        $error = new \RuntimeException('boom');
        $record = (new LogErrors())(self::record(Level::Error, '', ['exception' => $error]));
        self::assertStringStartsWith("boom\n#0", $record->message);

        $untouched = (new LogErrors())(self::record());
        self::assertSame('hello', $untouched->message);
    }

    public function testExcludeColorsAndDetailedLog(): void
    {
        $colored = "\033[32minfo\033[39m: \033[1mhello\033[22m";
        self::assertSame('info: hello', ExcludeColors::strip($colored));
        self::assertSame("info: hello\n", (new ExcludeColors())->format(self::record(Level::Info, $colored)));
        self::assertSame("[2024-01-02 03:04:05.678] info: hello\n", (new DetailedLog())->format(self::record(Level::Info, "\033[1mhello\033[22m")));
    }

    public function testLevelFilter(): void
    {
        $filter = new LevelFilter('error', 'warn');
        self::assertTrue($filter->accepts(Level::Error));
        self::assertTrue($filter->accepts(Level::Warning));
        self::assertFalse($filter->accepts(Level::Info));

        $inner = new TestHandler();
        $logger = new MonologLogger('t', [$filter->wrap($inner)]);
        $logger->info('no');
        $logger->warning('yes');
        $logger->error('yes');

        self::assertCount(2, $inner->getRecords());
    }

    public function testDefaultConfigurationShape(): void
    {
        $config = DefaultConfiguration::create('php://memory');
        self::assertSame('silly', $config['level']);
        self::assertSame(Constants::LEVELS, $config['levels']);
        self::assertInstanceOf(PrettyPrint::class, $config['format']);
        self::assertCount(1, $config['transports']);
        self::assertSame(Level::Debug, $config['transports'][0]->getLevel());
    }

    public function testOutputFileConfigurationWritesErrorsAsPlainText(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'strapi-log');
        self::assertIsString($file);
        $console = fopen('php://memory', 'w+');
        self::assertIsResource($console);

        $config = OutputFileConfiguration::create($file, [], ['consoleLevel' => 'warn'], $console);
        $logger = Logger::createLogger($config);

        $logger->info('info line');
        $logger->warning('warn line');
        $logger->error("\033[31merror line\033[39m");
        $logger->close();

        rewind($console);
        $consoleOutput = (string) stream_get_contents($console);
        self::assertStringNotContainsString('info line', $consoleOutput);
        self::assertStringContainsString('warn line', $consoleOutput);
        self::assertStringContainsString('error line', $consoleOutput);

        $fileOutput = (string) file_get_contents($file);
        self::assertSame("error line\n", $fileOutput);
        self::assertSame(Level::Warning, $config['transports'][0]->getLevel());
        self::assertSame(Level::Error, $config['transports'][1]->getLevel());
        unlink($file);
    }
}
