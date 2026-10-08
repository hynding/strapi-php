<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use Strapi\Upgrade\Modules\Runner\Code\TransformAPI;

/**
 * Port of resources/codemods/5.0.0/use-uid-for-config-namespace.code.ts.
 *
 * Replaces string dot format for config get/set/has with uid format for 'plugin' and 'api' namespace where possible
 * For example, `$strapi->config()->get('plugin.anyString')` will become `$strapi->config()->get('plugin::anyString')`
 * Ignores api followed by 'rest' or 'responses' because those are the valid Strapi api config values in v4
 *
 * @param array{path: string, source: string} $file
 */
return static function (array $file, TransformAPI $api): string {
    $parsed = $api->parse($file['source']);

    $ignoreList = ['api.rest', 'api.responses'];
    $changed = false;

    /** @var list<MethodCall> $calls */
    $calls = $api->finder->find($parsed[0], static fn (Node $node): bool => $node instanceof MethodCall
        && $node->name instanceof Node\Identifier
        && in_array($node->name->name, ['get', 'has', 'set'], true)
        && TransformAPI::isStrapiMethodCall($node->var, 'config'));

    foreach ($calls as $call) {
        $argumentNode = $call->args[0] ?? null;
        if (!$argumentNode instanceof Node\Arg || !$argumentNode->value instanceof String_) {
            continue;
        }

        $value = $argumentNode->value->value;
        $isTargeted = str_starts_with($value, 'plugin.') || str_starts_with($value, 'api.');
        $isIgnored = array_filter($ignoreList, static fn (string $ignoreItem): bool => str_starts_with($value, $ignoreItem)) !== [];
        if (!$isTargeted || $isIgnored) {
            continue;
        }

        $argumentNode->value = new String_(preg_replace('/\./', '::', $value, 1) ?? $value, ['kind' => $argumentNode->value->getAttribute('kind', String_::KIND_SINGLE_QUOTED)]);
        $changed = true;
    }

    return $changed ? $api->print($parsed) : $file['source'];
};
