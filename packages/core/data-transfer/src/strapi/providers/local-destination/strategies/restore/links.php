<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalDestination\Strategies\Restore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Strapi\Core\Strapi;
use Strapi\DataTransfer\Errors\Providers\ProviderTransferError;
use Strapi\DataTransfer\Strapi\Queries\Link;
use Strapi\DataTransfer\Utils\CappedWarnings;
use Strapi\DataTransfer\Utils\Stream\Writable;
use Strapi\DataTransfer\Utils\Transaction;

/**
 * Port of src/strapi/providers/local-destination/strategies/restore/links.ts.
 */
final class Links
{
    private static function errorCode(\Throwable $e): ?string
    {
        if ($e instanceof DriverException) {
            $state = $e->getSQLState();
            if ($state !== null && $state !== '') {
                return $state;
            }

            return (string) $e->getCode();
        }

        if (property_exists($e, 'code') && is_string($e->code)) {
            return $e->code;
        }

        return null;
    }

    public static function isForeignKeyConstraintError(\Throwable $e): bool
    {
        if ($e instanceof ForeignKeyConstraintViolationException || $e->getPrevious() instanceof ForeignKeyConstraintViolationException) {
            return true;
        }

        $mysqlFkErrorCodes = ['1452', '1557', '1216', '1217', '1451'];
        $postgresFkErrorCode = '23503';
        $sqliteFkErrorCode = 'SQLITE_CONSTRAINT_FOREIGNKEY';

        $code = self::errorCode($e);
        $driverCode = $e instanceof DriverException ? (string) $e->getCode() : null;
        if (in_array($code, [$sqliteFkErrorCode, $postgresFkErrorCode, ...$mysqlFkErrorCodes], true) || in_array($driverCode, $mysqlFkErrorCodes, true)) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'foreign key constraint');
    }

    public static function isAbortedTransactionError(\Throwable $e): bool
    {
        return self::errorCode($e) === '25P02';
    }

    private static function isPostgresDialect(Strapi $strapi): bool
    {
        return $strapi->db()->dialect->client === 'postgres';
    }

    private static function withSavepoint(Connection $trx, callable $fn): mixed
    {
        $savepoint = 'sp_' . bin2hex(random_bytes(16));

        $trx->executeStatement("SAVEPOINT {$savepoint}");

        try {
            $result = $fn();
            $trx->executeStatement("RELEASE SAVEPOINT {$savepoint}");

            return $result;
        } catch (\Throwable $error) {
            $trx->executeStatement("ROLLBACK TO SAVEPOINT {$savepoint}");
            throw $error;
        }
    }

    public static function formatSkippedLinksRestoreSummary(int $unmapped, int $foreignKey): ?string
    {
        $total = $unmapped + $foreignKey;

        if ($total === 0) {
            return null;
        }

        $parts = [];

        if ($unmapped > 0) {
            $parts[] = "{$unmapped} unmapped";
        }

        if ($foreignKey > 0) {
            $parts[] = "{$foreignKey} foreign key";
        }

        return "Links restore skipped {$total} relation(s) (" . implode(', ', $parts) . '). Verify relations in the admin after transfer.';
    }

    private static function str(mixed $value): string
    {
        return $value === null ? 'null' : (is_scalar($value) ? (string) $value : 'undefined');
    }

    /**
     * @param callable(string, int): (int|null) $mapID
     * @param (callable(string): void)|null     $onWarning
     */
    public static function createLinksWriteStream(callable $mapID, Strapi $strapi, ?Transaction $transaction = null, ?callable $onWarning = null): Writable
    {
        $skippedUnmapped = 0;
        $skippedForeignKey = 0;
        $warnings = CappedWarnings::createCappedWarningReporter($onWarning);
        // Only PostgreSQL aborts the surrounding transaction on a statement error
        // (25P02). MySQL and SQLite keep the transaction usable after an FK failure,
        // so per-link savepoints are unnecessary there.
        $useSavepoints = self::isPostgresDialect($strapi);

        return new Writable(
            write: static function (mixed $link) use ($mapID, $strapi, $transaction, $warnings, $useSavepoints, &$skippedUnmapped, &$skippedForeignKey): void {
                $link = is_array($link) ? $link : [];

                $transaction?->attach(static function (?Connection $trx) use ($link, $mapID, $strapi, $warnings, $useSavepoints, &$skippedUnmapped, &$skippedForeignKey): void {
                    $left = $link['left'] ?? [];
                    $right = $link['right'] ?? [];
                    $query = Link::createLinkQuery($strapi, $trx);

                    $originalLeftRef = $left['ref'] ?? null;
                    $originalRightRef = $right['ref'] ?? null;

                    $mappedLeftRef = ResolveLinkRef::resolveLinkRef($strapi, $link, 'left', $mapID);
                    $mappedRightRef = ResolveLinkRef::resolveLinkRef($strapi, $link, 'right', $mapID);

                    $leftLabel = ($left['type'] ?? 'undefined') . ':' . self::str($originalLeftRef);
                    $rightLabel = ($right['type'] ?? 'undefined') . ':' . self::str($originalRightRef);

                    // A missing mapping means the referenced row was never transferred
                    // during the entities stage (e.g. an orphaned component or a dangling
                    // reference in the source database). Falling back to the original ID
                    // would either violate a foreign key constraint — which aborts the
                    // whole transaction on PostgreSQL — or silently attach the link to an
                    // unrelated row, so the link is skipped instead.
                    if ($mappedLeftRef === null || $mappedRightRef === null) {
                        $missingRefs = implode(' and ', [
                            ...($mappedLeftRef === null ? [$leftLabel] : []),
                            ...($mappedRightRef === null ? [$rightLabel] : []),
                        ]);

                        $warnings->warn("Skipping link {$leftLabel} -> {$rightLabel} because {$missingRefs} was not transferred during the entities stage.");

                        ++$skippedUnmapped;

                        return;
                    }

                    $link['left']['ref'] = $mappedLeftRef;
                    $link['right']['ref'] = $mappedRightRef;

                    try {
                        if ($trx !== null && $useSavepoints) {
                            self::withSavepoint($trx, static fn () => $query()->insert($link));
                        } else {
                            $query()->insert($link);
                        }
                    } catch (\Throwable $e) {
                        if (self::isForeignKeyConstraintError($e) || self::isAbortedTransactionError($e)) {
                            $warnings->warn("Skipping link {$leftLabel} -> {$rightLabel} due to a foreign key constraint.");
                            ++$skippedForeignKey;

                            return;
                        }

                        if ($e instanceof \Exception) {
                            throw $e;
                        }

                        throw new ProviderTransferError("An error happened while trying to import a {$left['type']} link.");
                    }
                });
            },
            final: static function () use ($onWarning, &$skippedUnmapped, &$skippedForeignKey): void {
                $summary = self::formatSkippedLinksRestoreSummary($skippedUnmapped, $skippedForeignKey);

                if ($summary !== null && $onWarning !== null) {
                    $onWarning($summary);
                }
            },
        );
    }
}
