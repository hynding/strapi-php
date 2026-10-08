<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Report;

/**
 * Port of packages/utils/upgrade/src/modules/report/types.ts.
 *
 * @phpstan-type ReportData array{error: int, ok: int, nochange: int, skip: int, timeElapsed: string, stats: array<string, int>}
 * @phpstan-type CodemodReport array{codemod: \Strapi\Upgrade\Modules\Codemod\Codemod, report: ReportData}
 * @phpstan-type Collection list<ReportData>
 */
final class Types
{
    /** @return ReportData */
    public static function empty(): array
    {
        return ['ok' => 0, 'nochange' => 0, 'skip' => 0, 'error' => 0, 'timeElapsed' => '', 'stats' => []];
    }
}
