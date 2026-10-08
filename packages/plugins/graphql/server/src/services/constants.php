<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services;

/**
 * Port of server/src/services/constants.ts: the `constants` service. The values are class
 * constants and, for `getService('constants').KINDS`-style access, public properties.
 */
final class Constants
{
    public const string PAGINATION_TYPE_NAME = 'Pagination';

    public const string DELETE_MUTATION_RESPONSE_TYPE_NAME = 'DeleteMutationResponse';

    public const string PUBLICATION_STATUS_TYPE_NAME = 'PublicationStatus';

    public const string PUBLICATION_FILTER_TYPE_NAME = 'PublicationFilter';

    public const string ERROR_TYPE_NAME = 'Error';

    public const string RESPONSE_COLLECTION_META_TYPE_NAME = 'ResponseCollectionMeta';

    public const array GRAPHQL_SCALARS = [
        'ID',
        'Boolean',
        'Int',
        'String',
        'Long',
        'Float',
        'JSON',
        'Date',
        'Time',
        'DateTime',
    ];

    public const array STRAPI_SCALARS = [
        'boolean',
        'integer',
        'string',
        'richtext',
        'blocks',
        'enumeration',
        'biginteger',
        'float',
        'decimal',
        'json',
        'date',
        'time',
        'datetime',
        'timestamp',
        'uid',
        'email',
        'password',
        'text',
    ];

    public const array SCALARS_ASSOCIATIONS = [
        'uid' => 'String',
        'email' => 'String',
        'password' => 'String',
        'text' => 'String',
        'boolean' => 'Boolean',
        'integer' => 'Int',
        'string' => 'String',
        'enumeration' => 'String',
        'richtext' => 'String',
        'blocks' => 'JSON',
        'biginteger' => 'Long',
        'float' => 'Float',
        'decimal' => 'Float',
        'json' => 'JSON',
        'date' => 'Date',
        'time' => 'Time',
        'datetime' => 'DateTime',
        'timestamp' => 'DateTime',
    ];

    public const string GENERIC_MORPH_TYPENAME = 'GenericMorph';

    public const array KINDS = [
        'type' => 'type',
        'component' => 'component',
        'dynamicZone' => 'dynamic-zone',
        'enum' => 'enum',
        'entity' => 'entity',
        'entityResponse' => 'entity-response',
        'entityResponseCollection' => 'entity-response-collection',
        'relationResponseCollection' => 'relation-response-collection',
        'query' => 'query',
        'mutation' => 'mutation',
        'input' => 'input',
        'filtersInput' => 'filters-input',
        'scalar' => 'scalar',
        'morph' => 'polymorphic',
        'internal' => 'internal',
    ];

    private const array ALL_OPERATORS = [
        'and',
        'or',
        'not',

        'eq',
        'eqi',
        'ne',
        'nei',

        'startsWith',
        'endsWith',

        'contains',
        'notContains',

        'containsi',
        'notContainsi',

        'gt',
        'gte',

        'lt',
        'lte',

        'null',
        'notNull',

        'in',
        'notIn',

        'between',
    ];

    public const array GRAPHQL_SCALAR_OPERATORS = [
        // ID
        'ID' => self::ALL_OPERATORS,
        // Booleans
        'Boolean' => self::ALL_OPERATORS,
        // Strings
        'String' => self::ALL_OPERATORS,
        // Numbers
        'Int' => self::ALL_OPERATORS,
        'Long' => self::ALL_OPERATORS,
        'Float' => self::ALL_OPERATORS,
        // Dates
        'Date' => self::ALL_OPERATORS,
        'Time' => self::ALL_OPERATORS,
        'DateTime' => self::ALL_OPERATORS,
        // Others
        'JSON' => self::ALL_OPERATORS,
    ];

    public const array ERROR_CODES = [
        'emptyDynamicZone' => 'dynamiczone.empty',
    ];

    public readonly string $PAGINATION_TYPE_NAME;

    public readonly string $RESPONSE_COLLECTION_META_TYPE_NAME;

    public readonly string $DELETE_MUTATION_RESPONSE_TYPE_NAME;

    public readonly string $PUBLICATION_STATUS_TYPE_NAME;

    public readonly string $PUBLICATION_FILTER_TYPE_NAME;

    /** @var list<string> */
    public readonly array $GRAPHQL_SCALARS;

    /** @var list<string> */
    public readonly array $STRAPI_SCALARS;

    public readonly string $GENERIC_MORPH_TYPENAME;

    /** @var array<string, string> */
    public readonly array $KINDS;

    /** @var array<string, list<string>> */
    public readonly array $GRAPHQL_SCALAR_OPERATORS;

    /** @var array<string, string> */
    public readonly array $SCALARS_ASSOCIATIONS;

    /** @var array<string, string> */
    public readonly array $ERROR_CODES;

    public readonly string $ERROR_TYPE_NAME;

    public function __construct()
    {
        $this->PAGINATION_TYPE_NAME = self::PAGINATION_TYPE_NAME;
        $this->RESPONSE_COLLECTION_META_TYPE_NAME = self::RESPONSE_COLLECTION_META_TYPE_NAME;
        $this->DELETE_MUTATION_RESPONSE_TYPE_NAME = self::DELETE_MUTATION_RESPONSE_TYPE_NAME;
        $this->PUBLICATION_STATUS_TYPE_NAME = self::PUBLICATION_STATUS_TYPE_NAME;
        $this->PUBLICATION_FILTER_TYPE_NAME = self::PUBLICATION_FILTER_TYPE_NAME;
        $this->GRAPHQL_SCALARS = self::GRAPHQL_SCALARS;
        $this->STRAPI_SCALARS = self::STRAPI_SCALARS;
        $this->GENERIC_MORPH_TYPENAME = self::GENERIC_MORPH_TYPENAME;
        $this->KINDS = self::KINDS;
        $this->GRAPHQL_SCALAR_OPERATORS = self::GRAPHQL_SCALAR_OPERATORS;
        $this->SCALARS_ASSOCIATIONS = self::SCALARS_ASSOCIATIONS;
        $this->ERROR_CODES = self::ERROR_CODES;
        $this->ERROR_TYPE_NAME = self::ERROR_TYPE_NAME;
    }
}
