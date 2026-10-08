<?php

declare(strict_types=1);

namespace Strapi\Admin\Shared\Utils;

/**
 * Port of shared/utils/audit-log-export.ts: the audit-log export constants. Their consumers (the
 * audit-logs controller, service and validation) are Enterprise code under ee/ and are not ported.
 */
final class AuditLogExport
{
    public const AUDIT_LOG_EXPORT_EVENT = 'audit-log.export';

    public const AUDIT_LOGS_EXPORT_UNTIL_HEADER = 'X-Audit-Logs-Export-Until';
    public const AUDIT_LOGS_EXPORT_NEXT_CURSOR_HEADER = 'X-Audit-Logs-Export-Next-Cursor';
    public const AUDIT_LOGS_EXPORT_PART_SIZE_HEADER = 'X-Audit-Logs-Export-Part-Size';
    public const AUDIT_LOGS_EXPORT_TOKEN_HEADER = 'X-Audit-Logs-Export-Token';
    public const AUDIT_LOGS_EXPORT_CURSOR_DONE = 'none';
    public const AUDIT_LOGS_EXPORT_PART_ROWS = 50000;
    public const AUDIT_LOGS_EXPORT_PART_MAX_ROWS = 100000;
    public const AUDIT_LOGS_EXPORT_DEFAULT_MAX_ROWS = 1_000_000;
}
