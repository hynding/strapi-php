<?php

declare(strict_types=1);

namespace Strapi\Database;

use Doctrine\DBAL\Connection;

/**
 * Port of packages/core/database/src/transaction-context.ts.
 *
 * Upstream keeps the current Knex transaction in an AsyncLocalStorage; everything is synchronous
 * here, so a plain stack of stores does the same job. A "transaction" is the DBAL Connection
 * while it has an open transaction (DBAL transactions live on the connection).
 *
 * @phpstan-type Store array{trx: Connection|null, commitCallbacks: list<callable>, rollbackCallbacks: list<callable>, completed: bool}
 */
final class TransactionContext
{
    /** @var list<array<string, mixed>> */
    private static array $stack = [];

    public static function run(Connection $trx, callable $cb): mixed
    {
        $current = self::store();
        self::$stack[] = [
            'trx' => $trx,
            // Fill with existing callbacks if nesting transactions
            'commitCallbacks' => $current['commitCallbacks'] ?? [],
            'rollbackCallbacks' => $current['rollbackCallbacks'] ?? [],
            'completed' => false,
        ];

        try {
            return $cb();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * Opens a transaction scope without a callback (`db.transaction()` returning a TransactionObject);
     * `commit()`/`rollback()` close it.
     */
    public static function push(Connection $trx): void
    {
        $current = self::store();
        self::$stack[] = [
            'trx' => $trx,
            'commitCallbacks' => $current['commitCallbacks'] ?? [],
            'rollbackCallbacks' => $current['rollbackCallbacks'] ?? [],
            'completed' => false,
            'manual' => true,
        ];
    }

    public static function get(): ?Connection
    {
        $store = self::store();

        return $store === null ? null : $store['trx'];
    }

    public static function commit(Connection $trx): void
    {
        $idx = array_key_last(self::$stack);
        if ($idx !== null && self::$stack[$idx]['completed']) {
            self::$stack[$idx]['trx'] = null;

            return;
        }

        $callbacks = [];
        if ($idx !== null) {
            self::$stack[$idx]['trx'] = null;
            self::$stack[$idx]['completed'] = true;
            $callbacks = self::$stack[$idx]['commitCallbacks'];
            self::$stack[$idx]['commitCallbacks'] = [];
            if (!empty(self::$stack[$idx]['manual'])) {
                array_pop(self::$stack);
            }
        }

        $trx->commit();

        foreach ($callbacks as $cb) {
            $cb();
        }
    }

    public static function rollback(Connection $trx): void
    {
        $idx = array_key_last(self::$stack);
        if ($idx !== null && self::$stack[$idx]['completed']) {
            self::$stack[$idx]['trx'] = null;

            return;
        }

        $callbacks = [];
        if ($idx !== null) {
            self::$stack[$idx]['trx'] = null;
            self::$stack[$idx]['completed'] = true;
            $callbacks = self::$stack[$idx]['rollbackCallbacks'];
            self::$stack[$idx]['rollbackCallbacks'] = [];
            if (!empty(self::$stack[$idx]['manual'])) {
                array_pop(self::$stack);
            }
        }

        if ($trx->isTransactionActive()) {
            $trx->rollBack();
        }

        foreach ($callbacks as $cb) {
            $cb();
        }
    }

    public static function onCommit(callable $cb): void
    {
        $idx = array_key_last(self::$stack);
        if ($idx !== null) {
            self::$stack[$idx]['commitCallbacks'][] = $cb;
        }
    }

    public static function onRollback(callable $cb): void
    {
        $idx = array_key_last(self::$stack);
        if ($idx !== null) {
            self::$stack[$idx]['rollbackCallbacks'][] = $cb;
        }
    }

    /** @return array<string, mixed>|null */
    private static function store(): ?array
    {
        $idx = array_key_last(self::$stack);

        return $idx === null ? null : self::$stack[$idx];
    }
}
