<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Utils\Identifiers\Hash;
use Strapi\Database\Utils\Identifiers\Identifiers;

/** Port of utils/identifiers/__tests__/identifiers.test.ts. The expected strings come from Node. */
final class IdentifiersTest extends TestCase
{
    private Identifiers $identifiers;

    protected function setUp(): void
    {
        $this->identifiers = new Identifiers(['maxLength' => 55]);
    }

    public function testConstants(): void
    {
        // NOTE: if these constants ever change between versions, it will cause massive data loss
        self::assertSame(5, Identifiers::HASH_LENGTH);
        self::assertSame('', Identifiers::HASH_SEPARATOR);
        self::assertSame(55, Identifiers::IDENTIFIER_MAX_LENGTH);
    }

    public function testCreateHash(): void
    {
        self::assertSame('24', Hash::createHash('123456789', 2));
        self::assertSame('2434', Hash::createHash('123456789', 4));
        self::assertSame('243', Hash::createHash('123456789', 3));
        self::assertSame('24347b9c4b6da2fc9cde08c87f33edd2e603c8dcd6840e6b39', Hash::createHash('123456789', 50));
        self::assertSame('93da1', Hash::createHash('myData', 5));
    }

    public function testCreateHashThrows(): void
    {
        $this->expectExceptionMessage('length must be a positive integer');
        Hash::createHash('123456789', 0);
    }

    public function testGetShortenedName(): void
    {
        self::assertSame('1234567890', $this->identifiers->getShortenedName('1234567890', 10));
        self::assertSame('1234567890', $this->identifiers->getShortenedName('1234567890', 100));
        self::assertSame('1234cd65a', $this->identifiers->getShortenedName('1234567890', 9));
    }

    public function testGetShortenedNameTooShort(): void
    {
        $this->expectExceptionMessage('length for part of identifier too short, minimum is hash length (5) plus min token length (3), received 7');
        $this->identifiers->getShortenedName('1234567890', 7);
    }

    public function testGetNameFromTokens(): void
    {
        $t = static fn (string $name, bool $compressible = true, ?string $short = null): array => ['name' => $name, 'compressible' => $compressible] + ($short !== null ? ['shortName' => $short] : []);

        self::assertSame('1234567890_12345_links', (new Identifiers(['maxLength' => 22]))->getNameFromTokens([$t('1234567890'), $t('12345'), $t('links', false)]));
        self::assertSame('1234_56789_123_4_links', (new Identifiers(['maxLength' => 22]))->getNameFromTokens([$t('1234_56789'), $t('123_4'), $t('links', false)]));
        self::assertSame('1234567878db8', (new Identifiers(['maxLength' => 13]))->getNameFromTokens([$t('123456789012345')]));
        self::assertSame('1234567_47b4e', (new Identifiers(['maxLength' => 13]))->getNameFromTokens([$t('1234567_9012345')]));
        self::assertSame('12345678867f6', (new Identifiers(['maxLength' => 13]))->getNameFromTokens([$t('12345678_012345')]));
        self::assertSame('12345', (new Identifiers(['maxLength' => 5]))->getNameFromTokens([$t('12345')]));
        self::assertSame('1234cd65a_12345_links', (new Identifiers(['maxLength' => 21]))->getNameFromTokens([$t('1234567890'), $t('12345'), $t('links', false)]));
        self::assertSame('1234567890_12345_lnk', (new Identifiers(['maxLength' => 21]))->getNameFromTokens([$t('1234567890'), $t('12345'), $t('links', false, 'lnk')]));
        self::assertSame('123cd65a_123cd65a_links', (new Identifiers(['maxLength' => 23]))->getNameFromTokens([$t('1234567890'), $t('1234567890'), $t('links', false)]));
        self::assertSame('12_12_12_12', (new Identifiers(['maxLength' => 12]))->getNameFromTokens([$t('12'), $t('12'), $t('12'), $t('12')]));
        self::assertSame('1234cd65a_12345_0984addb_links', (new Identifiers(['maxLength' => 30]))->getNameFromTokens([$t('1234567890'), $t('12345'), $t('0987654321'), $t('links', false)]));
        self::assertSame('inv_order_1234cd65a_12345_0984addb', (new Identifiers(['maxLength' => 34]))->getNameFromTokens([$t('inv_order', false), $t('1234567890'), $t('12345'), $t('0987654321')]));
        self::assertSame('pre_1234cd65a_in_3456be378_post', (new Identifiers(['maxLength' => 31]))->getNameFromTokens([$t('pre', false), $t('1234567890'), $t('in', false), $t('3456789012'), $t('post', false)]));
        self::assertSame('pre_1234567890_in_3456be378_post', (new Identifiers(['maxLength' => 32]))->getNameFromTokens([$t('pre', false), $t('1234567890'), $t('in', false), $t('3456789012'), $t('post', false)]));
        self::assertSame('1234567890_2345678901_3456789012', (new Identifiers(['maxLength' => 34]))->getNameFromTokens([$t('1234567890', false), $t('2345678901', false), $t('3456789012', false)]));
    }

    /** @return iterable<array{int, list<array{name: string, compressible: bool}>}> */
    public static function tooSmall(): iterable
    {
        yield [21, [['name' => '1234567890', 'compressible' => true], ['name' => '1234567890', 'compressible' => true], ['name' => 'links', 'compressible' => false]]];
        yield [12, [['name' => '12', 'compressible' => true], ['name' => '12', 'compressible' => true], ['name' => '12', 'compressible' => true], ['name' => '1', 'compressible' => true], ['name' => '12', 'compressible' => true]]];
        yield [5, [['name' => '123456', 'compressible' => false]]];
        yield [12, [['name' => '123456', 'compressible' => false], ['name' => '123456', 'compressible' => false]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tooSmall')]
    public function testGetNameFromTokensThrows(int $maxLength, array $tokens): void
    {
        $this->expectExceptionMessage('Maximum length is too small to accommodate all tokens');
        (new Identifiers(['maxLength' => $maxLength]))->getNameFromTokens($tokens);
    }

    public function testFullNameMapping(): void
    {
        $id = new Identifiers(['maxLength' => 21]);
        $name = $id->getNameFromTokens([
            ['name' => '1234567890', 'compressible' => true],
            ['name' => '12345', 'compressible' => true],
            ['name' => 'links', 'compressible' => false, 'shortName' => 'lnk'],
        ]);
        self::assertSame('1234567890_12345_lnk', $name);
        self::assertSame('1234567890_12345_links', $id->getUnshortenedName($name));
    }

    public function testNamingHelpers(): void
    {
        $id = Identifiers::global();
        self::assertSame('articles_categories_lnk', $id->getJoinTableName('articles', 'categories'));
        self::assertSame('files_related_mph', $id->getMorphTableName('files', 'related'));
        self::assertSame('article_id', $id->getJoinColumnAttributeIdName('article'));
        self::assertSame('inv_article_id', $id->getInverseJoinColumnAttributeIdName('article'));
        self::assertSame('category_ord', $id->getOrderColumnName('category'));
        self::assertSame('inv_article_ord', $id->getInverseOrderColumnName('article'));
        self::assertSame('articles_categories_lnk_fk', $id->getFkIndexName('articles_categories_lnk'));
        self::assertSame('articles_categories_lnk_ifk', $id->getInverseFkIndexName('articles_categories_lnk'));
        self::assertSame('articles_categories_lnk_uq', $id->getUniqueIndexName('articles_categories_lnk'));
        self::assertSame('articles_categories_lnk_ofk', $id->getOrderFkIndexName('articles_categories_lnk'));
        self::assertSame('articles_categories_lnk_oifk', $id->getOrderInverseFkIndexName('articles_categories_lnk'));
        self::assertSame('files_related_mph_oidx', $id->getOrderIndexName('files_related_mph'));
        self::assertSame('files_related_mph_idix', $id->getIdColumnIndexName('files_related_mph'));
        self::assertSame('articles_documents_idx', $id->getIndexName(['articles', 'documents']));
        self::assertSame('articles_title_pk', $id->getPrimaryIndexName(['articles', 'title']));
    }
}
