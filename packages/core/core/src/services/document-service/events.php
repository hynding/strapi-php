<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Services\DocumentService\Utils\Populate;
use Strapi\Core\Strapi;
use Strapi\Utils\Sanitize\Sanitizers;

/**
 * Port of services/document-service/events.ts: emits `entry.*` events with the full populated and
 * sanitized entry (`{ model, uid, entry }`) once the surrounding transaction commits.
 */
final class Events
{
    public const ENTRY_CREATE = 'entry.create';
    public const ENTRY_UPDATE = 'entry.update';
    public const ENTRY_DELETE = 'entry.delete';
    public const ENTRY_PUBLISH = 'entry.publish';
    public const ENTRY_UNPUBLISH = 'entry.unpublish';
    public const ENTRY_DRAFT_DISCARD = 'entry.draft-discard';

    /** @var array<string, mixed> */
    private readonly array $populate;

    public function __construct(private readonly Strapi $strapi, private readonly string $uid)
    {
        $this->populate = Populate::getDeepPopulate($strapi, $uid, []);
    }

    public static function createEventManager(Strapi $strapi, string $uid): self
    {
        return new self($strapi, $uid);
    }

    /** @param array<string, mixed> $entry */
    private function doEmit(string $eventName, array $entry): void
    {
        $model = $this->strapi->getModel($this->uid);
        if ($model === null) {
            return;
        }

        // There is no need to populate the entry if it has been deleted
        $populatedEntry = $entry;
        if (!in_array($eventName, [self::ENTRY_DELETE, self::ENTRY_UNPUBLISH], true)) {
            $populatedEntry = $this->strapi->db()->query($this->uid)->findOne(['where' => ['id' => $entry['id']], 'populate' => $this->populate]) ?? $entry;
        }

        $sanitizedEntry = Sanitizers::defaultSanitizeOutput(
            ['schema' => $model, 'getModel' => fn (string $uid) => $this->strapi->getModel($uid)],
            $populatedEntry,
        );

        $this->strapi->eventHub()->emit($eventName, ['model' => $model->modelName, 'uid' => $model->uid, 'entry' => $sanitizedEntry]);
    }

    /**
     * strapi.db.query might reuse the transaction used in the doc service request,
     * so this is executed after that transaction is committed.
     *
     * @param array<string, mixed> $entry
     */
    public function emitEvent(string $eventName, array $entry): void
    {
        $this->strapi->db()->transaction(function (array $trx) use ($eventName, $entry): void {
            $trx['onCommit'](fn () => $this->doEmit($eventName, $entry));
        });
    }
}
