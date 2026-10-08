<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Services\DocumentService\Attributes\Attributes;
use Strapi\Core\Services\DocumentService\Transform\Data;
use Strapi\Core\Services\DocumentService\Transform\IdTransform;
use Strapi\Core\Services\DocumentService\Transform\Query;
use Strapi\Core\Services\EntityValidator\EntityValidator;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of services/document-service/entries.ts: create / update / delete / publish / discardDraft of
 * a single entry (one row), including validation, component handling and attribute transforms.
 */
final class Entries
{
    private readonly Schema $contentType;

    private readonly Components $components;

    public function __construct(private readonly Strapi $strapi, private readonly string $uid, private readonly EntityValidator $entityValidator)
    {
        $this->contentType = $strapi->contentType($uid);
        $this->components = new Components($strapi);
    }

    public static function createEntriesService(Strapi $strapi, string $uid, EntityValidator $entityValidator): self
    {
        return new self($strapi, $uid, $entityValidator);
    }

    /**
     * `api.documents.strictRelations`: false/undefined → legacy behaviour, true → enforce required
     * media and relations on non-draft writes.
     */
    private function isStrictRelationsEnabled(): bool
    {
        $raw = $this->strapi->config()->get('api.documents.strictRelations');

        if ($raw !== null && $raw !== false && $raw !== true) {
            $printed = is_scalar($raw) ? (string) $raw : json_encode($raw);

            throw new ValidationError("Invalid config.api.documents.strictRelations value: \"{$printed}\". Expected boolean (true or false).");
        }

        return $raw === true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $params = []): array
    {
        $transformed = IdTransform::transformParamsDocumentId($this->strapi, $this->uid, $params);
        $inputData = $transformed['data'] ?? null;
        unset($transformed['data']);
        $query = Query::transformParamsToQuery($this->strapi, $this->uid, Params::pickSelectionParams($transformed)); // select / populate

        // Validation
        if (!is_array($inputData)) {
            throw new \RuntimeException('Create requires data attribute');
        }

        $data = $inputData;
        if (Params::isParamEmpty($data['documentId'] ?? null)) {
            unset($data['documentId']);
        }

        // Check for uniqueness based on documentId and locale (if localized)
        if (!empty($data['documentId'])) {
            $isLocalized = $this->strapi->localization()->isLocalizedContentType($this->contentType);
            $hasDraftAndPublish = ($this->contentType->options['draftAndPublish'] ?? null) === true;

            $whereClause = ['documentId' => $data['documentId']];
            if ($isLocalized) {
                $whereClause['locale'] = $data['locale'] ?? null;
            }

            $publishedStateDescription = '';
            if ($hasDraftAndPublish) {
                if (!empty($data['publishedAt'])) {
                    $whereClause['publishedAt'] = ['$notNull' => true];
                    $publishedStateDescription = 'published';
                } else {
                    $whereClause['publishedAt'] = ['$null' => true];
                    $publishedStateDescription = 'draft';
                }
            }

            $existingEntry = $this->strapi->db()->query($this->uid)->findOne(['select' => ['id'], 'where' => $whereClause]);

            if ($existingEntry !== null) {
                $errorMsg = "A {$publishedStateDescription} entry with documentId \"{$data['documentId']}\"";
                if ($isLocalized && !empty($data['locale'])) {
                    $errorMsg .= " and locale \"{$data['locale']}\"";
                }
                $errorMsg .= " already exists for UID \"{$this->uid}\". This combination must be unique.";

                throw new ApplicationError($errorMsg);
            }
        }

        $validData = $this->entityValidator->validateEntityCreation($this->contentType, $data, [
            // Note: publishedAt value will always be set when DP is disabled
            'isDraft' => empty($params['data']['publishedAt']),
            'locale' => $params['locale'] ?? null,
            'strictRelations' => $this->isStrictRelationsEnabled(),
        ]);

        // Component handling
        $componentData = $this->components->createComponents($this->uid, $validData);
        $dataWithComponents = Components::assignComponentData($this->contentType, $componentData, $validData);

        $entryData = Attributes::applyTransforms($this->contentType, $dataWithComponents);

        $query['data'] = $entryData;

        return $this->strapi->db()->query($this->uid)->create($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public function delete(int|string $id, array $query = []): ?array
    {
        $componentsToDelete = $this->components->getComponents($this->uid, ['id' => $id]);

        $query['where'] = ['id' => $id];
        $deletedEntry = $this->strapi->db()->query($this->uid)->delete($query);

        $this->components->deleteComponents($this->uid, [...$componentsToDelete, 'id' => $id], ['loadComponents' => false]);

        return $deletedEntry;
    }

    /**
     * @param array<string, mixed> $entryToUpdate
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function update(array $entryToUpdate, array $params = []): ?array
    {
        $transformed = IdTransform::transformParamsDocumentId($this->strapi, $this->uid, $params);
        $inputData = $transformed['data'] ?? null;
        unset($transformed['data']);
        $query = Query::transformParamsToQuery($this->strapi, $this->uid, Params::pickSelectionParams($transformed)); // select / populate

        $data = $inputData;
        if (is_array($data)) {
            unset($data['documentId']);
        }

        $validData = $this->entityValidator->validateEntityUpdate($this->contentType, $data, [
            'isDraft' => empty($params['data']['publishedAt']), // Always update the draft version
            'locale' => $params['locale'] ?? null,
            'strictRelations' => $this->isStrictRelationsEnabled(),
        ], $entryToUpdate);

        // Component handling
        $componentData = $this->components->updateComponents($this->uid, ['id' => $entryToUpdate['id']], $validData);
        $dataWithComponents = Components::assignComponentData($this->contentType, $componentData, $validData);

        $entryData = Attributes::applyTransforms($this->contentType, $dataWithComponents);

        $query['where'] = ['id' => $entryToUpdate['id']];
        $query['data'] = $entryData;

        return $this->strapi->db()->query($this->uid)->update($query);
    }

    /**
     * Clone a (populated) draft entry as the published version.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function publish(array $entry, array $params = []): array
    {
        Data::clearTransformDataRequestCache($this->strapi);
        $publishedAt = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');

        $draft = $entry;
        unset($draft['id']);
        $draft['publishedAt'] = $publishedAt;

        $draft = Data::transformData($this->strapi, $draft, [
            'uid' => $this->uid,
            'locale' => $draft['locale'] ?? null,
            'status' => 'published',
            'allowMissingId' => true,
            'useRequestCache' => false,
        ]);

        // Create the published entry
        return $this->create([...$params, 'data' => $draft, 'locale' => $draft['locale'] ?? null, 'status' => 'published']);
    }

    /**
     * Clone a (populated) published entry as the new draft.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function discardDraft(array $entry, array $params = []): array
    {
        Data::clearTransformDataRequestCache($this->strapi);

        $data = $entry;
        unset($data['id']);
        $data['publishedAt'] = null;

        $data = Data::transformData($this->strapi, $data, [
            'uid' => $this->uid,
            'locale' => $data['locale'] ?? null,
            'status' => 'draft',
            'allowMissingId' => true,
            'useRequestCache' => false,
        ]);

        // Create the draft entry
        return $this->create([...$params, 'locale' => $data['locale'] ?? null, 'data' => $data, 'status' => 'draft']);
    }
}
