<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Utils\Cron;
use Strapi\Upload\Utils\Utils;

/**
 * Port of server/src/services/weekly-metrics.ts.
 *
 * @phpstan-type MetricStoreValue array{lastWeeklyUpdate?: int, weeklySchedule?: string}
 */
final class WeeklyMetrics
{
    private const int ONE_WEEK = 7 * 24 * 60 * 60 * 1000;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    private function getMetricsStoreValue(): array
    {
        $value = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'upload', 'key' => 'metrics']);

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $value */
    private function setMetricsStoreValue(array $value): void
    {
        $this->strapi->store()->set(['type' => 'plugin', 'name' => 'upload', 'key' => 'metrics', 'value' => $value]);
    }

    /** @return array{assetNumber: int, folderNumber: int, averageDepth: float|int, maxDepth: int, averageDeviationDepth: float|int} */
    public function computeMetrics(): array
    {
        $db = $this->strapi->db();

        // Folder metrics
        $pathColName = $db->metadata(Constants::FOLDER_MODEL_UID)['attributes']['path']['columnName'];
        $folderTable = $db->metadata(Constants::FOLDER_MODEL_UID)['tableName'];

        $sql = $db->sql();
        $keepOnlySlashesSQLString = $sql->quoteIdentifier($pathColName);
        $queryParams = [];
        for ($i = 0; $i < 10; $i += 1) {
            $keepOnlySlashesSQLString = "REPLACE({$keepOnlySlashesSQLString}, ?, ?)";
            $queryParams[] = (string) $i;
            $queryParams[] = '';
        }

        /*
          The following query goal is to count the number of folders with depth 1, depth 2 etc.
          The query returns :
          [
            { depth: 1, occurence: 4 },
            { depth: 2, occurence: 2 },
            { depth: 3, occurence: 5 },
          ]

          The query is built as follow:
          1. In order to get the depth level of a folder:
            - we take their path
            - remove all numbers (by replacing 0123456789 by '', thus the 10 REPLACE in the query)
            - count the remaining `/`, which correspond to their depth (by using LENGTH)
            We now have, for each folder, its depth.
          2. In order to get the number of folders for each depth:
            - we group them by their depth and use COUNT(*)
        */

        $res = $sql->from($folderTable)
            ->select($sql->raw("LENGTH({$keepOnlySlashesSQLString}) AS depth, COUNT(*) AS occurence", $queryParams))
            ->groupBy('depth')
            ->run();

        // values can be strings depending on the database
        $folderLevelsArray = array_map(
            static fn (array $map): array => ['depth' => (int) $map['depth'], 'occurence' => (int) $map['occurence']],
            is_array($res) ? $res : [],
        );

        $product = 0;
        $folderNumber = 0;
        $maxDepth = 0;
        foreach ($folderLevelsArray as $folderLevel) {
            $product += $folderLevel['depth'] * $folderLevel['occurence'];
            $folderNumber += $folderLevel['occurence'];
            if ($folderLevel['depth'] > $maxDepth) {
                $maxDepth = $folderLevel['depth'];
            }
        }
        $averageDepth = $folderNumber !== 0 ? $product / $folderNumber : 0;

        $sumOfDeviation = 0;
        foreach ($folderLevelsArray as $folderLevel) {
            $sumOfDeviation += abs($folderLevel['depth'] - $averageDepth) * $folderLevel['occurence'];
        }

        $averageDeviationDepth = $folderNumber !== 0 ? $sumOfDeviation / $folderNumber : 0;

        // File metrics
        $assetNumber = (int) $db->query(Constants::FILE_MODEL_UID)->count();

        return [
            'assetNumber' => $assetNumber,
            'folderNumber' => $folderNumber,
            'averageDepth' => $averageDepth,
            'maxDepth' => $maxDepth,
            'averageDeviationDepth' => $averageDeviationDepth,
        ];
    }

    public function sendMetrics(): void
    {
        $metrics = $this->computeMetrics();
        Utils::getService('metrics', $this->strapi)->trackUsage('didSendUploadPropertiesOnceAWeek', [
            'groupProperties' => ['metrics' => $metrics],
        ]);

        $metricsInfoStored = $this->getMetricsStoreValue();
        $this->setMetricsStoreValue([...$metricsInfoStored, 'lastWeeklyUpdate' => (int) floor(microtime(true) * 1000)]);
    }

    public function ensureWeeklyStoredCronSchedule(): string
    {
        $metricsInfoStored = $this->getMetricsStoreValue();
        $currentSchedule = $metricsInfoStored['weeklySchedule'] ?? null;
        $lastWeeklyUpdate = $metricsInfoStored['lastWeeklyUpdate'] ?? null;

        $now = new \DateTimeImmutable();
        $nowMs = (int) floor(microtime(true) * 1000);
        $weeklySchedule = $currentSchedule;

        if (!$weeklySchedule || !$lastWeeklyUpdate || (int) $lastWeeklyUpdate + self::ONE_WEEK < $nowMs) {
            $weeklySchedule = Cron::getWeeklyCronScheduleAt($now->modify('+15 seconds'));
            $this->setMetricsStoreValue([...$metricsInfoStored, 'weeklySchedule' => $weeklySchedule]);

            return $weeklySchedule;
        }

        return (string) $weeklySchedule;
    }

    public function registerCron(): void
    {
        $weeklySchedule = $this->ensureWeeklyStoredCronSchedule();

        $this->strapi->cron()->add([
            'uploadWeekly' => [
                'task' => fn () => $this->sendMetrics(),
                'options' => $weeklySchedule,
            ],
        ]);
    }
}
