<?php

declare(strict_types=1);

namespace Strapi\Database;

use Doctrine\DBAL\Connection;

/**
 * The object `Database::transaction()` returns when called without a callback
 * (upstream `TransactionObject`: `{ commit, rollback, get }`).
 */
final class TransactionObject
{
    private bool $finished = false;

    public function __construct(
        private readonly Connection $trx,
        private readonly bool $notNested,
    ) {
    }

    public function commit(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if ($this->notNested) {
            TransactionContext::commit($this->trx);
        }
    }

    public function rollback(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if ($this->notNested) {
            TransactionContext::rollback($this->trx);
        }
    }

    public function get(): Connection
    {
        return $this->trx;
    }

    public function onCommit(callable $cb): void
    {
        TransactionContext::onCommit($cb);
    }

    public function onRollback(callable $cb): void
    {
        TransactionContext::onRollback($cb);
    }
}
