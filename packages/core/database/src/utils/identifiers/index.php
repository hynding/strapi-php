<?php

declare(strict_types=1);

namespace Strapi\Database\Utils\Identifiers;

use Strapi\Database\Utils\LodashWords as Strings;

/**
 * Port of packages/core/database/src/utils/identifiers/index.ts.
 *
 * Generates every table, column, join table and index name used in the database, shortening
 * them to `maxLength` with a hash suffix exactly the way Node Strapi does so both backends can
 * share one database.
 *
 * @phpstan-type NameToken array{name: string, compressible: bool, shortName?: string, allocatedLength?: int}
 * @phpstan-type NameOptions array{suffix?: string, prefix?: string}
 */
class Identifiers
{
    public const IDENTIFIER_MAX_LENGTH = 55;

    public const ID_COLUMN = 'id';
    public const ORDER_COLUMN = 'order';
    public const FIELD_COLUMN = 'field';
    public const HASH_LENGTH = 5;
    public const HASH_SEPARATOR = ''; // no separator is needed, we will just attach hash directly to shortened name
    public const IDENTIFIER_SEPARATOR = '_';
    public const MIN_TOKEN_LENGTH = 3; // the min characters required at the beginning of a name part

    /** Fixed compression map for suffixes and prefixes. */
    public const REPLACEMENT_MAP = [
        'links' => 'lnk',
        'order_inv_fk' => 'oifk',
        'order' => 'ord',
        'morphs' => 'mph',
        'index' => 'idx',
        'inv_fk' => 'ifk',
        'order_fk' => 'ofk',
        'id_column_index' => 'idix',
        'order_index' => 'oidx',
        'unique' => 'uq',
        'primary' => 'pk',
    ];

    /** @var array<string, string> shortened name -> full-length name */
    public array $nameMap = [];

    private static ?Identifiers $global = null;

    /** @param array{maxLength: int} $options */
    public function __construct(private array $options)
    {
    }

    /**
     * The global instance used by metadata (upstream `export const identifiers`).
     * Tests may swap it with setGlobal() to use a different maxLength.
     */
    public static function global(): Identifiers
    {
        return self::$global ??= new Identifiers(['maxLength' => self::IDENTIFIER_MAX_LENGTH]);
    }

    public static function setGlobal(?Identifiers $identifiers): void
    {
        self::$global = $identifiers;
    }

    /** @return array{maxLength: int} */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getMaxLength(): int
    {
        return $this->options['maxLength'];
    }

    public function mapShortNames(string $name): ?string
    {
        return self::REPLACEMENT_MAP[$name] ?? null;
    }

    /**
     * Generic name handler that must be used by all helper functions.
     *
     * @param string|list<string> $names
     * @param NameOptions $options
     */
    public function getName(string|array $names, array $options = []): string
    {
        $tokens = array_map(static fn (string $name): array => ['name' => $name, 'compressible' => true], is_array($names) ? $names : [$names]);

        if (isset($options['suffix'])) {
            $tokens[] = ['name' => $options['suffix'], 'compressible' => false, 'shortName' => $this->mapShortNames($options['suffix'])];
        }

        if (isset($options['prefix'])) {
            array_unshift($tokens, ['name' => $options['prefix'], 'compressible' => false, 'shortName' => $this->mapShortNames($options['prefix'])]);
        }

        return $this->getNameFromTokens($tokens);
    }

    /*
     * TABLES
     */

    /** @param NameOptions $options */
    public function getTableName(string $name, array $options = []): string
    {
        return $this->getName($name, $options);
    }

    /** @param NameOptions $options */
    public function getJoinTableName(string $collectionName, string $attributeName, array $options = []): string
    {
        return $this->getName([$collectionName, $attributeName], ['suffix' => 'links', ...$options]);
    }

    /** @param NameOptions $options */
    public function getMorphTableName(string $collectionName, string $attributeName, array $options = []): string
    {
        return $this->getName([Strings::snakeCase($collectionName), Strings::snakeCase($attributeName)], ['suffix' => 'morphs', ...$options]);
    }

    /*
     * COLUMNS
     */

    /** @param NameOptions $options */
    public function getColumnName(string $attributeName, array $options = []): string
    {
        return $this->getName($attributeName, $options);
    }

    /** @param NameOptions $options */
    public function getJoinColumnAttributeIdName(string $attributeName, array $options = []): string
    {
        return $this->getName($attributeName, ['suffix' => 'id', ...$options]);
    }

    /** @param NameOptions $options */
    public function getInverseJoinColumnAttributeIdName(string $attributeName, array $options = []): string
    {
        return $this->getName(Strings::snakeCase($attributeName), ['suffix' => 'id', 'prefix' => 'inv', ...$options]);
    }

    /** @param NameOptions $options */
    public function getOrderColumnName(string $singularName, array $options = []): string
    {
        return $this->getName($singularName, ['suffix' => 'order', ...$options]);
    }

    /** @param NameOptions $options */
    public function getInverseOrderColumnName(string $singularName, array $options = []): string
    {
        return $this->getName($singularName, ['suffix' => 'order', 'prefix' => 'inv', ...$options]);
    }

    /*
     * Morph join tables
     */

    /** @param NameOptions $options */
    public function getMorphColumnJoinTableIdName(string $singularName, array $options = []): string
    {
        return $this->getName(Strings::snakeCase($singularName), ['suffix' => 'id', ...$options]);
    }

    /** @param NameOptions $options */
    public function getMorphColumnAttributeIdName(string $attributeName, array $options = []): string
    {
        return $this->getName(Strings::snakeCase($attributeName), ['suffix' => 'id', ...$options]);
    }

    /** @param NameOptions $options */
    public function getMorphColumnTypeName(string $attributeName, array $options = []): string
    {
        return $this->getName(Strings::snakeCase($attributeName), ['suffix' => 'type', ...$options]);
    }

    /*
     * INDEXES
     */

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'index', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getFkIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'fk', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getUniqueIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'unique', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getPrimaryIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'primary', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getInverseFkIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'inv_fk', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getOrderFkIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'order_fk', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getOrderInverseFkIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'order_inv_fk', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getIdColumnIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'id_column_index', ...$options]);
    }

    /** @param string|list<string> $names  @param NameOptions $options */
    public function getOrderIndexName(string|array $names, array $options = []): string
    {
        return $this->getName($names, ['suffix' => 'order_index', ...$options]);
    }

    /**
     * Generates a string with a max length, appending a hash at the end if necessary to keep it unique.
     *
     * @internal
     */
    public function getShortenedName(string $name, int $len): string
    {
        if ($len <= 0) {
            throw new \InvalidArgumentException("tokenWithHash length must be a positive integer, received {$len}");
        }
        if (strlen($name) <= $len) {
            return $name;
        }
        if ($len < self::MIN_TOKEN_LENGTH + self::HASH_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'length for part of identifier too short, minimum is hash length (%d) plus min token length (%d), received %d for token %s',
                self::HASH_LENGTH,
                self::MIN_TOKEN_LENGTH,
                $len,
                $name,
            ));
        }

        $availableLength = $len - self::HASH_LENGTH - strlen(self::HASH_SEPARATOR);
        if ($availableLength < self::MIN_TOKEN_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'length for part of identifier minimum is less than min token length (%d), received %d for token %s',
                self::MIN_TOKEN_LENGTH,
                $len,
                $name,
            ));
        }

        return substr($name, 0, $availableLength) . self::HASH_SEPARATOR . Hash::createHash($name, self::HASH_LENGTH);
    }

    /**
     * Constructs a name from an array of name tokens within the configured maximum length, selectively
     * compressing the tokens marked as compressible.
     *
     * @param list<NameToken> $nameTokens
     *
     * @internal
     */
    public function getNameFromTokens(array $nameTokens): string
    {
        $maxLength = $this->options['maxLength'];

        if ($maxLength < 0) {
            throw new \InvalidArgumentException('maxLength must be a positive integer or 0 (for unlimited length)');
        }

        $unshortenedName = implode(self::IDENTIFIER_SEPARATOR, array_map(static fn (array $t): string => $t['name'], $nameTokens));

        // if maxLength == 0 we want the legacy v4 name without any shortening
        if ($maxLength === 0) {
            $this->setUnshortenedName($unshortenedName, $unshortenedName);

            return $unshortenedName;
        }

        // check the full length name (but with incompressible tokens using shortNames if available)
        $fullLengthName = implode(self::IDENTIFIER_SEPARATOR, array_map(
            static fn (array $t): string => $t['compressible'] ? $t['name'] : ($t['shortName'] ?? $t['name']),
            $nameTokens,
        ));

        if (strlen($fullLengthName) <= $maxLength) {
            $this->setUnshortenedName($fullLengthName, $unshortenedName);

            return $fullLengthName;
        }

        $compressibleIdx = [];
        $totalIncompressibleLength = 0;
        foreach ($nameTokens as $i => $token) {
            if ($token['compressible']) {
                $compressibleIdx[] = $i;
            } else {
                $totalIncompressibleLength += strlen($token['shortName'] ?? $token['name']);
            }
        }

        $totalSeparatorsLength = count($nameTokens) * strlen(self::IDENTIFIER_SEPARATOR) - 1;
        $available = $maxLength - $totalIncompressibleLength - $totalSeparatorsLength;
        $compressibleCount = count($compressibleIdx);
        $availablePerToken = $compressibleCount > 0 ? intdiv($available, $compressibleCount) : 0;

        if ($totalIncompressibleLength + $totalSeparatorsLength > $maxLength || $availablePerToken < self::MIN_TOKEN_LENGTH) {
            throw new \InvalidArgumentException('Maximum length is too small to accommodate all tokens');
        }

        // Calculate the remainder from the division and add it to the surplus
        $surplus = $compressibleCount > 0 ? $available % $compressibleCount : 0;

        // Check that it's even possible to proceed
        $minHashedLength = self::HASH_LENGTH + strlen(self::HASH_SEPARATOR) + self::MIN_TOKEN_LENGTH;
        $totalLength = $totalSeparatorsLength;
        foreach ($nameTokens as $token) {
            if ($token['compressible']) {
                $totalLength += strlen($token['name']) < $availablePerToken ? strlen($token['name']) : $minHashedLength;
            } else {
                $totalLength += strlen($token['shortName'] ?? $token['name']);
            }
        }

        if ($maxLength < $totalLength) {
            throw new \InvalidArgumentException('Maximum length is too small to accommodate all tokens');
        }

        // Calculate total surplus length from shorter strings and total deficit length from longer strings
        $deficits = [];
        foreach ($compressibleIdx as $i) {
            $actualLength = strlen($nameTokens[$i]['name']);
            if ($actualLength < $availablePerToken) {
                $surplus += $availablePerToken - $actualLength;
                $nameTokens[$i]['allocatedLength'] = $actualLength;
            } else {
                $nameTokens[$i]['allocatedLength'] = $availablePerToken;
                $deficits[] = $i;
            }
        }

        // Redistribute surplus length to longer strings, one character at a time
        $previousSurplus = $surplus + 1; // infinite loop protection
        while ($surplus > 0 && count($deficits) > 0) {
            $remaining = [];
            foreach ($deficits as $i) {
                if ($nameTokens[$i]['allocatedLength'] < strlen($nameTokens[$i]['name']) && $surplus > 0) {
                    $nameTokens[$i]['allocatedLength']++;
                    $surplus--;
                    if ($nameTokens[$i]['allocatedLength'] < strlen($nameTokens[$i]['name'])) {
                        $remaining[] = $i;
                    }
                }
            }
            $deficits = $remaining;

            if ($surplus === $previousSurplus) {
                break;
            }
            $previousSurplus = $surplus;
        }

        // Build final string
        $parts = [];
        foreach ($nameTokens as $token) {
            if ($token['compressible'] && isset($token['allocatedLength'])) {
                $parts[] = $this->getShortenedName($token['name'], $token['allocatedLength']);
            } elseif (!$token['compressible'] && !empty($token['shortName'])) {
                $parts[] = $token['shortName'];
            } else {
                $parts[] = $token['name'];
            }
        }
        $shortenedName = implode(self::IDENTIFIER_SEPARATOR, $parts);

        if (strlen($shortenedName) > $maxLength) {
            throw new \RuntimeException("name shortening failed to generate a name of the correct maxLength; name {$shortenedName}");
        }

        $this->setUnshortenedName($shortenedName, $unshortenedName);

        return $shortenedName;
    }

    public function getUnshortenedName(string $shortName): string
    {
        return $this->nameMap[$this->serializeKey($shortName)] ?? $shortName;
    }

    public function setUnshortenedName(string $shortName, string $fullName): void
    {
        // Protection against names shortened twice: a second pass through the shortener must not
        // replace the original full name in the mapping.
        if (isset($this->nameMap[$this->serializeKey($shortName)]) && $shortName === $fullName) {
            return;
        }

        $this->nameMap[$this->serializeKey($shortName)] = $fullName;
    }

    public function serializeKey(string $shortName): string
    {
        return "{$shortName}.{$this->options['maxLength']}";
    }
}
