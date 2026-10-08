<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Prompts;
use Strapi\CreateStrapiApp\Tests\ScopeFactory;
use Strapi\CreateStrapiApp\Utils\Database;
use Strapi\CreateStrapiApp\Utils\FatalError;
use Strapi\CreateStrapiApp\Utils\Logger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class DatabaseTest extends TestCase
{
    private BufferedOutput $output;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->logger = new Logger($this->output);
    }

    public function testDefaultsToSqliteWithoutFlagsWhenNotPrompting(): void
    {
        self::assertSame(Database::DEFAULT_CONFIG, Database::getDatabaseInfos(ScopeFactory::options(['quickstart' => true]), $this->logger));
        self::assertSame(Database::DEFAULT_CONFIG, Database::getDatabaseInfos(ScopeFactory::options(['nonInteractive' => true]), $this->logger));
        self::assertSame(Database::DEFAULT_CONFIG, Database::getDatabaseInfos(ScopeFactory::options(['skipDb' => true, 'dbclient' => 'oracle']), $this->logger));
    }

    public function testBuildsTheConnectionFromTheFlags(): void
    {
        $info = Database::getDatabaseInfos(ScopeFactory::options([
            'dbclient' => 'postgres', 'dbhost' => 'localhost', 'dbport' => '5432', 'dbname' => 'strapi',
            'dbusername' => 'u', 'dbpassword' => 'p', 'dbssl' => 'true',
        ]), $this->logger);

        self::assertSame([
            'client' => 'postgres',
            'connection' => ['host' => 'localhost', 'port' => '5432', 'database' => 'strapi', 'username' => 'u', 'password' => 'p', 'filename' => null, 'ssl' => true],
        ], $info);
    }

    public function testSqliteOnlyNeedsTheClientAndFile(): void
    {
        $info = Database::getDatabaseInfos(ScopeFactory::options(['dbclient' => 'sqlite', 'dbfile' => 'db/app.db']), $this->logger);

        self::assertSame('sqlite', $info['client']);
        self::assertSame('db/app.db', $info['connection']['filename']);
    }

    public function testRejectsAnInvalidClient(): void
    {
        try {
            Database::getDatabaseInfos(ScopeFactory::options(['dbclient' => 'oracle']), $this->logger);
            self::fail('expected a fatal error');
        } catch (FatalError) {
            self::assertStringContainsString('Invalid --dbclient: oracle, expected one of sqlite, mysql, postgres', $this->output->fetch());
        }
    }

    public function testRejectsMissingArguments(): void
    {
        try {
            Database::getDatabaseInfos(ScopeFactory::options(['dbclient' => 'mysql', 'dbhost' => 'localhost']), $this->logger);
            self::fail('expected a fatal error');
        } catch (FatalError) {
            self::assertStringContainsString('Required database arguments are missing: dbport, dbname, dbusername, dbpassword.', $this->output->fetch());
        }
    }

    public function testPromptsForACustomDatabase(): void
    {
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        // not the default database; mysql; name, host, port (default), username, password, ssl
        fwrite($stream, "n\nmysql\nmydb\n\n\nroot\nsecret\ny\n");
        rewind($stream);
        $input->setStream($stream);

        $info = Database::getDatabaseInfos(ScopeFactory::options(), $this->logger, new Prompts($input, $this->output));

        self::assertSame([
            'client' => 'mysql',
            'connection' => ['database' => 'mydb', 'host' => '127.0.0.1', 'port' => '3306', 'username' => 'root', 'password' => 'secret', 'ssl' => true],
        ], $info);
    }

    public function testRequiresThePdoExtensionOfTheClient(): void
    {
        $scope = Database::addDatabaseDependencies(ScopeFactory::scope(['database' => ['client' => 'postgres', 'connection' => []]]));

        self::assertSame('*', $scope['composerDependencies']['ext-pdo_pgsql']);
        self::assertSame('^5.56', $scope['composerDependencies']['strapi/strapi']);
    }
}
