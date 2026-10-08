<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Utils\Errors\PayloadTooLargeError;
use Strapi\Utils\File as FileUtils;

/**
 * PHP-port addition: the object `register.ts`'s `createProvider` builds and assigns to
 * `strapi.plugin('upload').provider` — the provider instance's methods wrapped so that each
 * receives its `actionOptions[methodName]`, on top of `baseProvider` (`extend`, `checkFileSize`,
 * `getSignedUrl`, `isPrivate`), which the instance's own methods override.
 *
 * Files are `\ArrayObject`s, so a provider method that sets `file['url']` updates the caller's
 * file as upstream's object mutation does; plain arrays are wrapped, and every method returns the
 * file it was given (or what the provider returned).
 *
 * @method mixed upload(\ArrayObject<string, mixed>|array<string, mixed> $file, mixed $options = null)
 * @method mixed uploadStream(\ArrayObject<string, mixed>|array<string, mixed> $file, mixed $options = null)
 * @method mixed replace(\ArrayObject<string, mixed>|array<string, mixed> $newFile, mixed $oldFile = null)
 * @method mixed replaceStream(\ArrayObject<string, mixed>|array<string, mixed> $newFile, mixed $oldFile = null)
 * @method mixed delete(\ArrayObject<string, mixed>|array<string, mixed> $file, mixed $options = null)
 */
final class Provider
{
    /** @var array<string, \Closure> methods added with extend() */
    private array $extensions = [];

    /**
     * @param object $providerInstance what the provider's `init()` returned
     * @param array<string, mixed> $actionOptions
     */
    public function __construct(private readonly object $providerInstance, private readonly array $actionOptions = [])
    {
    }

    /** Whether the provider (or an extension) implements `$method` — upstream's `isFunction(provider[method])`. */
    public function has(string $method): bool
    {
        if ($this->own($method) !== null) {
            return true;
        }

        return in_array($method, ['extend', 'checkFileSize', 'getSignedUrl', 'isPrivate'], true);
    }

    /** The instance's own method, or null (baseProvider methods excluded). */
    private function own(string $method): ?\Closure
    {
        if (isset($this->extensions[$method])) {
            return $this->extensions[$method];
        }

        $callable = [$this->providerInstance, $method];
        if (method_exists($this->providerInstance, $method) && is_callable($callable)) {
            return \Closure::fromCallable($callable);
        }

        if (property_exists($this->providerInstance, $method)) {
            $value = $this->providerInstance->{$method};
            if ($value instanceof \Closure) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $method, array $args): mixed
    {
        $fn = $this->own($method);
        if ($fn === null) {
            throw new \BadMethodCallException("The upload provider doesn't implement the {$method} method.");
        }

        // `async (file, options = actionOptions[methodName]) => providerInstance[methodName](file, options)`
        $file = $args[0] ?? null;
        if (is_array($file) && !array_is_list($file)) {
            $file = new \ArrayObject($file);
        }
        $options = array_key_exists(1, $args) ? $args[1] : ($this->actionOptions[$method] ?? null);

        $result = $fn($file, $options);

        return $result ?? $file;
    }

    /** @param array<string, callable> $obj */
    public function extend(array $obj): void
    {
        foreach ($obj as $name => $fn) {
            $this->extensions[$name] = \Closure::fromCallable($fn);
        }
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @param array{sizeLimit?: int|float|null} $options
     */
    public function checkFileSize(\ArrayAccess|array $file, array $options = []): void
    {
        $own = $this->own('checkFileSize');
        if ($own !== null) {
            $own($file, $options);

            return;
        }

        $sizeLimit = $options['sizeLimit'] ?? null;
        $size = $file['size'] ?? 0;
        if ($sizeLimit && FileUtils::kbytesToBytes(is_numeric($size) ? (float) $size : 0) > $sizeLimit) {
            $name = $file['originalFilename'] ?? null;
            $name = is_string($name) ? $name : 'undefined';

            throw new PayloadTooLargeError("{$name} exceeds size limit of " . FileUtils::bytesToHumanReadable($sizeLimit) . '.');
        }
    }

    /**
     * @param \ArrayAccess<string, mixed>|array<string, mixed> $file
     * @return \ArrayAccess<string, mixed>|array<string, mixed>
     */
    public function getSignedUrl(\ArrayAccess|array $file, mixed $options = null): \ArrayAccess|array
    {
        $own = $this->own('getSignedUrl');
        if ($own !== null) {
            $result = $own($file, $options ?? ($this->actionOptions['getSignedUrl'] ?? null));

            return is_array($result) || $result instanceof \ArrayAccess ? $result : ['url' => is_string($result) ? $result : null];
        }

        return $file;
    }

    public function isPrivate(): bool
    {
        $own = $this->own('isPrivate');

        return $own !== null ? (bool) $own() : false;
    }

    /** The provider object `init()` returned. */
    public function instance(): object
    {
        return $this->providerInstance;
    }
}
