<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Queries\Link;
use Strapi\DataTransfer\Utils\CappedWarnings;

/**
 * Port of src/strapi/providers/local-source/links.ts.
 */
final class Links
{
    /** @param array<string, mixed> $link */
    private static function formatOrphanedExportLinkWarning(array $link): string
    {
        return "Omitting link {$link['left']['type']}:" . self::str($link['left']['ref'] ?? null) . " -> {$link['right']['type']}:" . self::str($link['right']['ref'] ?? null) . ' from export because a referenced entity no longer exists in the database.';
    }

    private static function str(mixed $value): string
    {
        return $value === null ? 'null' : (is_scalar($value) ? (string) $value : '');
    }

    public static function formatOrphanedLinksExportSummary(int $count): string
    {
        return "Links export omitted {$count} relation(s) pointing at missing entities. Verify relations after import if this is unexpected.";
    }

    /**
     * Create a readable which will stream all the links from a Strapi instance
     *
     * @param array{onWarning?: callable(string): void} $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function createLinksStream(Strapi $strapi, array $options = []): \Generator
    {
        $uids = [...array_keys($strapi->contentTypes()), ...array_keys($strapi->components())];
        $orphanedLinkCount = 0;
        $onWarning = $options['onWarning'] ?? null;
        $warnings = CappedWarnings::createCappedWarningReporter($onWarning);

        $query = Link::createLinkQuery($strapi, null, [
            'onOrphanedLink' => static function (array $link) use (&$orphanedLinkCount, $warnings): void {
                ++$orphanedLinkCount;
                $warnings->warn(self::formatOrphanedExportLinkWarning($link));
            },
        ]);

        // generator that returns every link from a Strapi instance
        foreach ($uids as $uid) {
            yield from $query()->generateAll((string) $uid);
        }

        if ($orphanedLinkCount > 0 && $onWarning !== null) {
            $onWarning(self::formatOrphanedLinksExportSummary($orphanedLinkCount));
        }
    }
}
