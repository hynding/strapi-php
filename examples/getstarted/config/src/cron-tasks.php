<?php

declare(strict_types=1);

/**
 * Cron config that gives you an opportunity to run scheduled jobs.
 *
 * The cron format consists of:
 * [MINUTE] [HOUR] [DAY OF MONTH] [MONTH OF YEAR] [DAY OF WEEK]
 * or six fields with seconds first (seconds are ignored: minute granularity under PHP).
 *
 * Under PHP-FPM/FrankenPHP jobs are executed by `bin/strapi cron:run` (schedule it with system cron);
 * `bin/strapi start` in CLI mode runs the scheduler loop.
 */
return [
    /**
     * Simple example. Every monday at 1am.
     */
    // '0 0 1 * * 1' => static function (\Strapi\Core\Strapi $strapi, \DateTimeInterface $fireDate): void {
    //     // Add your own logic here (e.g. send a queue of email, create a database backup, etc.).
    // },
    // 'myJob' => [
    //     'task' => static function (\Strapi\Core\Strapi $strapi): void { /* Add your own logic here */ },
    //     'options' => [
    //         'rule' => '* * * * * *',
    //         'end' => (new \DateTimeImmutable())->modify('+6 seconds'),
    //     ],
    // ],
];
