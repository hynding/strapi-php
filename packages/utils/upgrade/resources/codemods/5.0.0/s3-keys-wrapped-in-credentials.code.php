<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use Strapi\Upgrade\Modules\Runner\Code\TransformAPI;

/**
 * Port of resources/codemods/5.0.0/s3-keys-wrapped-in-credentials.code.ts, on config/plugins.php.
 *
 * This codemod only affects users that are using the `aws-s3` provider.
 * It will wrap the `accessKeyId` and `secretAccessKey` properties inside a `credentials` object.
 *
 * Upstream visits every arrow function returning an object; a PHP config file returns its array
 * directly, from an arrow function, or from a closure — all three are visited. Upstream looks
 * for config/plugins.{js,ts} under `process.cwd()`; this uses the project root.
 *
 * @param array{path: string, source: string} $file
 */
return static function (array $file, TransformAPI $api): string {
    // Check if the current file is 'config/plugins.php'
    if ($file['path'] !== $api->cwd . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugins.php') {
        return $file['source'];
    }

    $key = static fn (ArrayItem $item): ?string => $item->key instanceof String_ ? $item->key->value : null;

    $getPropertyByKeyName = static function (Array_ $object, string $keyName) use ($key): ?ArrayItem {
        foreach ($object->items as $item) {
            if ($key($item) === $keyName) {
                return $item;
            }
        }

        return null;
    };

    $parsed = $api->parse($file['source']);

    // the arrays a config file returns: `return [...]`, `return fn () => [...]`, `return function () { return [...]; }`
    $bodies = [];
    foreach ($api->finder->findInstanceOf($parsed[0], Node\Stmt\Return_::class) as $return) {
        if ($return->expr instanceof Array_) {
            $bodies[] = $return->expr;
        }
    }
    foreach ($api->finder->findInstanceOf($parsed[0], Expr\ArrowFunction::class) as $arrow) {
        if ($arrow->expr instanceof Array_) {
            $bodies[] = $arrow->expr;
        }
    }

    $changed = false;

    foreach ($bodies as $body) {
        $uploadProperty = $getPropertyByKeyName($body, 'upload');

        // Check that we found an upload property and that it is an array
        if ($uploadProperty === null || !$uploadProperty->value instanceof Array_) {
            continue;
        }

        $configProperty = $getPropertyByKeyName($uploadProperty->value, 'config');
        if ($configProperty === null || !$configProperty->value instanceof Array_) {
            continue;
        }

        // If there is not a provider property or it is not 'aws-s3', skip
        $providerProperty = $getPropertyByKeyName($configProperty->value, 'provider');
        if ($providerProperty === null || !$providerProperty->value instanceof String_ || $providerProperty->value->value !== 'aws-s3') {
            continue;
        }

        $providerOptions = $getPropertyByKeyName($configProperty->value, 'providerOptions');
        if ($providerOptions === null || !$providerOptions->value instanceof Array_) {
            continue;
        }
        $providerOptionsValue = $providerOptions->value;

        // Check for accessKeyId and secretAccessKey directly under providerOptions
        $directAccessKeyId = $getPropertyByKeyName($providerOptionsValue, 'accessKeyId');
        $directSecretAccessKey = $getPropertyByKeyName($providerOptionsValue, 'secretAccessKey');

        $s3Options = $getPropertyByKeyName($providerOptionsValue, 's3Options');

        if ($s3Options === null) {
            // Create s3Options if it doesn't exist
            $s3Options = new ArrayItem(new Array_([], ['kind' => Array_::KIND_SHORT]), new String_('s3Options'));
            $providerOptionsValue->items[] = $s3Options;
            $changed = true;
        }

        $accessKeyId = null;
        $secretAccessKey = null;
        $withoutKeys = static fn (Array_ $array): array => array_values(array_filter(
            $array->items,
            static fn (ArrayItem $item): bool => !in_array($key($item), ['accessKeyId', 'secretAccessKey'], true)
        ));

        if ($directAccessKeyId !== null && $directSecretAccessKey !== null) {
            $accessKeyId = $directAccessKeyId;
            $secretAccessKey = $directSecretAccessKey;

            // Remove these properties from providerOptions
            $providerOptionsValue->items = $withoutKeys($providerOptionsValue);
            $changed = true;
        } elseif ($s3Options->value instanceof Array_) {
            // Look inside s3Options
            $accessKeyId = $getPropertyByKeyName($s3Options->value, 'accessKeyId');
            $secretAccessKey = $getPropertyByKeyName($s3Options->value, 'secretAccessKey');
        }

        if ($accessKeyId !== null && $secretAccessKey !== null && $s3Options->value instanceof Array_) {
            // Create the credentials object
            $credentials = new Array_([
                new ArrayItem($accessKeyId->value, new String_('accessKeyId')),
                new ArrayItem($secretAccessKey->value, new String_('secretAccessKey')),
            ], ['kind' => Array_::KIND_SHORT]);

            // Remove the old properties from s3Options, add the new credentials object
            $s3Options->value->items = [...$withoutKeys($s3Options->value), new ArrayItem($credentials, new String_('credentials'))];
            $changed = true;
        }
    }

    return $changed ? $api->print($parsed) : $file['source'];
};
