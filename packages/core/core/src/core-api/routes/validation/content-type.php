<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/core-api/routes/validation/content-type.ts: schema-aware
 * validation for the core routes of a content type (documents, bodies, query parameters).
 *
 * @phpstan-type QueryParam 'fields'|'populate'|'sort'|'status'|'publicationFilter'|'hasPublishedVersion'|'locale'|'pagination'|'filters'|'_q'
 */
final class CoreContentTypeRouteValidator extends AbstractCoreRouteValidator
{
    /** A validation schema for document IDs (UUIDs). */
    public function documentID(): ZodType
    {
        return z::uuid()->describe('The document ID, represented by a UUID');
    }

    /** A validation schema for a single document: its scalar and populatable fields (no passwords). */
    public function document(): ZodObject
    {
        $entries = [...$this->scalarFields(), ...$this->populatableFields()];

        // Remove passwords from the attribute list
        $sanitizedAttributes = array_filter($entries, static fn (array $attribute): bool => !in_array($attribute['type'] ?? null, ['password'], true));

        // Merge all attributes into a single schema
        $attributesSchema = Mappers::createAttributesSchema($this->strapi, $sanitizedAttributes);

        return z::object([
            'documentId' => $this->documentID(),
            'id' => z::union([z::string(), z::number()]),
        ])->extend($attributesSchema->shape());
    }

    /** A validation schema for an array of documents. */
    public function documents(): ZodArray
    {
        return z::array($this->document());
    }

    /** Schema-aware fields validation that restricts to actual model fields */
    protected function schemaAwareQueryFields(): ZodType
    {
        return $this->scalarFieldsArray()
            ->readonly()
            ->describe("The fields to return, this doesn't include populatable fields like relations, components, files, or dynamic zones");
    }

    /** Schema-aware populate validation that restricts to actual populatable fields */
    protected function schemaAwareQueryPopulate(): ZodType
    {
        $wildcardPopulate = z::literal('*')
            ->readonly()
            ->describe('Populate all the first level relations, components, files, and dynamic zones for the entry');

        $singleFieldPopulate = $this->populatableFieldsEnum()
            ->readonly()
            ->describe('Populate a single relation, component, file, or dynamic zone');

        $multiPopulate = $this->populatableFieldsArray()->describe('Populate a selection of multiple relations, components, files, or dynamic zones');

        return z::union([$wildcardPopulate, $singleFieldPopulate, $multiPopulate]);
    }

    /** Schema-aware sort validation that restricts to actual model fields */
    protected function schemaAwareQuerySort(): ZodType
    {
        $orderDirection = z::enum(['asc', 'desc']);

        // TODO: Handle nested sorts but very low priority, very little usage
        return z::union([
            $this->scalarFieldsEnum(), // 'name' | 'title'
            $this->scalarFieldsArray(), // ['name', 'title']
            $this->fieldRecord($orderDirection), // { name: 'desc' } | { title: 'asc' }
            z::array($this->fieldRecord($orderDirection)), // [{ name: 'desc'}, { title: 'asc' }]
        ])->describe('Sort the result');
    }

    /** Schema-aware filters validation that restricts to actual model fields */
    protected function schemaAwareFilters(): ZodType
    {
        return z::partialRecord($this->scalarFieldsEnum(), z::any())->describe('Filters to apply to the query');
    }

    public function locale(): ZodType
    {
        return z::string()->describe('Select a locale');
    }

    public function status(): ZodType
    {
        return z::enum(['draft', 'published'])
            ->describe('Fetch documents based on their status. Default to "published" if not specified.');
    }

    public function publicationFilter(): ZodType
    {
        return z::enum([
            'never-published',
            'has-published-version',
            'modified',
            'unmodified',
            'never-published-document',
            'has-published-version-document',
            'published-without-draft',
            'published-with-draft',
        ])->describe('Derived publication cohort: pair-scoped (per documentId+locale), document-scoped variants (-document), or published-slice diagnostics (published-without-draft / published-with-draft)');
    }

    /** @deprecated Use `publicationFilter` instead (`never-published`, `has-published-version`, …). */
    public function hasPublishedVersion(): ZodType
    {
        return z::union([z::boolean(), z::enum(['true', 'false'])])
            ->describe('[Deprecated: prefer publicationFilter] Filter documents by whether they have a published version. Use with status=draft to find documents that have never been published');
    }

    public function data(): ZodObject
    {
        $schema = $this->schema();

        $entries = [...$this->scalarFields(), ...$this->populatableFields()];

        // Remove non-writable attributes
        $sanitizedAttributes = array_filter(
            $entries,
            static fn (string $attributeName): bool => ContentTypes::isWritableAttribute($schema, $attributeName),
            ARRAY_FILTER_USE_KEY,
        );

        return Mappers::createAttributesInputSchema($this->strapi, $sanitizedAttributes);
    }

    public function query(): ZodType
    {
        return z::string();
    }

    public function body(): ZodObject
    {
        return z::object(['data' => $this->data()]);
    }

    public function partialBody(): ZodObject
    {
        return z::object(['data' => $this->data()->partial()]);
    }

    /**
     * Creates validation schemas for query parameters.
     *
     * @param list<string> $params query parameters to validate ('fields', 'populate', 'sort', ...)
     *
     * @return array<string, ZodType> validation schemas for the requested parameters
     */
    public function queryParams(array $params): array
    {
        $map = [
            'fields' => fn (): ZodType => $this->schemaAwareQueryFields()->optional(),
            'populate' => fn (): ZodType => $this->schemaAwareQueryPopulate()->optional(),
            'sort' => fn (): ZodType => $this->schemaAwareQuerySort()->optional(),
            'filters' => fn (): ZodType => $this->schemaAwareFilters()->optional(),
            'locale' => fn (): ZodType => $this->locale()->optional(),
            'pagination' => fn (): ZodType => $this->pagination()->optional(),
            'status' => fn (): ZodType => $this->status()->optional(),
            'publicationFilter' => fn (): ZodType => $this->publicationFilter()->optional(),
            'hasPublishedVersion' => fn (): ZodType => $this->hasPublishedVersion()->optional(),
            '_q' => fn (): ZodType => $this->query()->optional(),
        ];

        $acc = [];
        foreach ($params as $param) {
            if (!isset($map[$param])) {
                // upstream: `map[param]()` throws a TypeError for an unknown param
                throw new \TypeError("map[{$param}] is not a function");
            }
            $acc[$param] = $map[$param]();
        }

        return $acc;
    }
}
