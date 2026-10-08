<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Report;

use Strapi\Upgrade\Modules\Codemod\Codemod;

/**
 * Port of packages/utils/upgrade/src/modules/report/report.ts.
 *
 * @phpstan-import-type ReportData from Types
 * @phpstan-import-type CodemodReport from Types
 */
final class Report
{
    /**
     * @param ReportData $report
     * @return CodemodReport
     */
    public static function codemodReportFactory(Codemod $codemod, array $report): array
    {
        return ['codemod' => $codemod, 'report' => $report];
    }

    /**
     * @param ReportData $report
     * @return ReportData
     */
    public static function reportFactory(array $report): array
    {
        return $report;
    }
}
