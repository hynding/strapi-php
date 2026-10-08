<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\Controllers\Validation\Schema;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\Sessions;

/**
 * Port of server/src/services/homepage.ts (`homepageService({ strapi })`).
 *
 * @phpstan-import-type HomepageLayout from Schema
 */
final class Homepage
{
    private const DEFAULT_WIDTH = 6;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private static function keyFor(mixed $userId): string
    {
        return 'homepage-layout:' . (is_scalar($userId) ? (string) $userId : 'undefined');
    }

    private static function isContentTypeVisible(mixed $model): bool
    {
        $model = is_object($model) && method_exists($model, 'toArray') ? $model->toArray() : $model;

        return !is_array($model) || ($model['pluginOptions']['content-type-builder']['visible'] ?? null) !== false;
    }

    private function adminStore(): \Strapi\Core\Services\ScopedCoreStore
    {
        return $this->strapi->store()->scoped(['type' => 'core', 'name' => 'admin']);
    }

    /** @return array{assets: int, contentTypes: int, components: int, locales: int|null, admins: int, webhooks: int, apiTokens: int} */
    public function getKeyStatistics(): array
    {
        $contentTypes = array_filter($this->strapi->contentTypes(), self::isContentTypeVisible(...));

        $countApiTokens = Utils::getService($this->strapi, 'api-token-admin')->countAll();
        $countAdmins = Utils::getService($this->strapi, 'user')->count();
        $countLocales = null;
        if ($this->strapi->hasPlugin('i18n')) {
            $locales = $this->strapi->plugin('i18n')->service('locales');
            $count = [$locales, 'count'];
            $countLocales = is_callable($count) ? $count() : null;
        }
        $countsAssets = $this->strapi->db()->query('plugin::upload.file')->count();
        $countWebhooks = $this->strapi->db()->query('strapi::webhook')->count();

        $componentCategories = [];
        foreach ($this->strapi->components() as $component) {
            $component = is_object($component) && method_exists($component, 'toArray') ? $component->toArray() : $component;
            $category = is_array($component) ? ($component['category'] ?? null) : null;
            if (!in_array($category, $componentCategories, true)) {
                $componentCategories[] = $category;
            }
        }

        return [
            'assets' => $countsAssets,
            'contentTypes' => count($contentTypes),
            'components' => count($componentCategories),
            'locales' => is_int($countLocales) ? $countLocales : null,
            'admins' => $countAdmins,
            'webhooks' => $countWebhooks,
            'apiTokens' => $countApiTokens,
        ];
    }

    /** @return HomepageLayout|null */
    public function getHomepageLayout(mixed $userId): ?array
    {
        $key = self::keyFor($userId);
        $value = $this->adminStore()->get(['key' => $key]);

        if ($value === null || $value === false || $value === '' || $value === 0) {
            // nothing saved yet
            return null;
        }

        /** @var HomepageLayout */
        return Schema::homepageLayoutSchema()->parse($value);
    }

    /** @return HomepageLayout */
    public function updateHomepageLayout(mixed $userId, mixed $input): array
    {
        /** @var array{version?: int, widgets: list<array{uid: string, width: int}>, updatedAt?: string} $write */
        $write = Schema::homepageLayoutWriteSchema()->parse($input);

        $key = self::keyFor($userId);
        $currentRaw = $this->adminStore()->get(['key' => $key]);
        /** @var HomepageLayout|null $current */
        $current = $currentRaw ? Schema::homepageLayoutSchema()->parse($currentRaw) : null;

        $widgetsNext = $write['widgets'] ?? $current['widgets'] ?? [];

        // Normalize widths (fill defaults where missing)
        $normalizedWidgets = array_map(static function (array $w) use ($current): array {
            $prev = null;
            foreach ($current['widgets'] ?? [] as $cw) {
                if ($cw['uid'] === $w['uid']) {
                    $prev = $cw;
                    break;
                }
            }

            return [
                'uid' => $w['uid'],
                'width' => $w['width'] ?? $prev['width'] ?? self::DEFAULT_WIDTH,
            ];
        }, $widgetsNext);

        $next = [
            'version' => $write['version'] ?? 1,
            'widgets' => $normalizedWidgets,
            'updatedAt' => $write['updatedAt'] ?? Sessions::toISOString(new \DateTimeImmutable()),
        ];

        $this->adminStore()->set(['key' => $key, 'value' => $next]);

        return $next;
    }
}
