<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/populate-builder.ts.
 *
 * The service is the `populateBuilder(uid)` factory: `getService('populate-builder')($uid)` returns
 * a builder (an instance of this class bound to `$uid`).
 *
 * @example
 * $populate = $strapi->plugin('content-manager')->service('populate-builder')('api::article.article')->countRelations()->build();
 */
final class PopulateBuilder
{
    /** @var \Closure(): mixed */
    private \Closure $getInitialPopulate;

    /** @var array{countMany: bool, countOne: bool, maxLevel: int|float} */
    private array $deepPopulateOptions = [
        'countMany' => false,
        'countOne' => false,
        'maxLevel' => -1,
    ];

    public function __construct(private readonly Strapi $strapi, private readonly ?string $uid = null)
    {
        // undefined
        $this->getInitialPopulate = static fn (): mixed => null;
    }

    public function __invoke(string $uid): self
    {
        return new self($this->strapi, $uid);
    }

    private function uid(): string
    {
        if ($this->uid === null) {
            throw new \LogicException('populate-builder: call the service with a uid first');
        }

        return $this->uid;
    }

    /**
     * Populates all attribute fields present in a query.
     *
     * @param array<string, mixed> $query Strapi query object
     */
    public function populateFromQuery(array $query): self
    {
        $uid = $this->uid();
        $strapi = $this->strapi;
        $this->getInitialPopulate = static fn (): mixed => Populate::getQueryPopulate($strapi, $uid, $query);

        return $this;
    }

    /**
     * Populate relations as count.
     *
     * @param array{toMany?: bool|null, toOne?: bool|null} $options toMany: populate XtoMany relations as count if true; toOne: populate XtoOne relations as count if true
     */
    public function countRelations(array $options = ['toMany' => true, 'toOne' => true]): self
    {
        if (isset($options['toMany'])) {
            $this->deepPopulateOptions['countMany'] = $options['toMany'];
        }
        if (isset($options['toOne'])) {
            $this->deepPopulateOptions['countOne'] = $options['toOne'];
        }

        return $this;
    }

    /**
     * Populate relations deeply, up to a certain level.
     *
     * @param int|float $level Max level of nested populate (default INF)
     */
    public function populateDeep(int|float $level = INF): self
    {
        $this->deepPopulateOptions['maxLevel'] = $level;

        return $this;
    }

    /**
     * Override the populate for specific attributes, taking precedence over
     * query-derived or deep populate defaults.
     *
     * @param array<string, mixed> $overrides Populate overrides to merge (e.g. ['localizations' => ['fields' => ['locale']]])
     */
    public function withPopulateOverride(array $overrides): self
    {
        $prev = $this->getInitialPopulate;
        // merge(base, overrides): overrides win for overlapping keys, so e.g. localizations
        $this->getInitialPopulate = static function () use ($prev, $overrides): mixed {
            $base = $prev();

            return self::merge(is_array($base) ? $base : [], $overrides);
        };

        return $this;
    }

    /**
     * Construct the populate object based on the builder options.
     *
     * @return array<string, mixed>|null Populate object (null: upstream `undefined`)
     */
    public function build(): ?array
    {
        $initialPopulate = ($this->getInitialPopulate)();

        if ($this->deepPopulateOptions['maxLevel'] === -1) {
            return is_array($initialPopulate) ? $initialPopulate : null;
        }

        $options = $this->deepPopulateOptions;
        if ($initialPopulate !== null) {
            $options['initialPopulate'] = $initialPopulate;
        }

        return Populate::getDeepPopulate($this->strapi, $this->uid(), $options);
    }

    /**
     * lodash `merge`: recursive, the source wins.
     *
     * @param array<string|int, mixed> $a
     * @param array<string|int, mixed> $b
     * @return array<string|int, mixed>
     */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = is_array($value) && isset($a[$key]) && is_array($a[$key]) ? self::merge($a[$key], $value) : $value;
        }

        return $a;
    }
}
