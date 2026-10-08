<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

use Strapi\Core\Strapi;
use Strapi\Database\TransactionObject;

/**
 * Port of src/utils/transaction.ts: `createTransaction(strapi)` opens one database transaction
 * that the whole restore runs in. Upstream keeps a `strapi.db.transaction` callback alive and
 * feeds it the attached callbacks through an event emitter; everything is synchronous here, so
 * the transaction is opened up front, `attach()` runs the callback right away inside it,
 * `end()` commits and `rollback()` rolls back.
 */
final class Transaction
{
    private bool $done = false;

    private function __construct(private readonly TransactionObject $trx)
    {
    }

    public static function createTransaction(Strapi $strapi): self
    {
        $trx = $strapi->db()->transaction();
        assert($trx instanceof TransactionObject);

        return new self($trx);
    }

    /**
     * @template T
     *
     * @param callable(\Doctrine\DBAL\Connection|null): T $callback
     *
     * @return T|null
     */
    public function attach(callable $callback): mixed
    {
        if ($this->done) {
            // upstream: the callback is queued on a transaction that no longer runs anything
            throw new \LogicException('The transaction has already ended');
        }

        return $callback($this->trx->get());
    }

    public function end(): bool
    {
        if ($this->done) {
            return false;
        }

        $this->done = true;
        $this->trx->commit();

        return true;
    }

    public function rollback(): bool
    {
        if ($this->done) {
            return false;
        }

        $this->done = true;

        try {
            $this->trx->rollback();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isDone(): bool
    {
        return $this->done;
    }
}
