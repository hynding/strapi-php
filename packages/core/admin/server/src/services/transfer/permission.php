<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Permissions\Engine\Engine;
use Strapi\Utils\ProviderFactory;

/**
 * Port of server/src/services/transfer/permission.ts: the transfer token permission engine, whose
 * actions are `push` and `pull`.
 */
final class Permission
{
    private const DEFAULT_TRANSFER_ACTIONS = ['push', 'pull'];

    /** @var array{action: ProviderFactory<mixed>, condition: ProviderFactory<mixed>} */
    public readonly array $providers;

    public readonly Engine $engine;

    public function __construct()
    {
        $providers = [
            'action' => ProviderFactory::create(),
            'condition' => ProviderFactory::create(),
        ];

        foreach (self::DEFAULT_TRANSFER_ACTIONS as $action) {
            $providers['action']->register($action, ['action' => $action]);
        }

        $this->providers = $providers;
        $this->engine = Engine::new(['providers' => $providers]);
    }
}
