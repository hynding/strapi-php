<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * yup's `ValidationError`: one error (`errors = [message]`, `inner = []`) or an aggregate whose
 * `inner` holds the leaf errors (`message` = "N errors occurred" when there are several).
 */
final class YupError extends \RuntimeException
{
    public string $name = 'ValidationError';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<YupError> */
    public array $inner = [];

    /** @var array<string, mixed> */
    public array $params = [];

    /**
     * @param string|YupError|list<string|YupError> $errorOrErrors
     */
    public function __construct(string|YupError|array $errorOrErrors, public mixed $value = null, public ?string $path = null, public ?string $type = null)
    {
        foreach (is_array($errorOrErrors) ? $errorOrErrors : [$errorOrErrors] as $err) {
            if ($err instanceof self) {
                array_push($this->errors, ...$err->errors);
                array_push($this->inner, ...($err->inner !== [] ? $err->inner : [$err]));
            } else {
                $this->errors[] = $err;
            }
        }

        parent::__construct(count($this->errors) > 1 ? count($this->errors) . ' errors occurred' : ($this->errors[0] ?? ''));
    }

    public static function isError(mixed $err): bool
    {
        return $err instanceof self;
    }

    /**
     * `ValidationError.formatError`: `${key}` placeholders are replaced with `printValue(params[key])`;
     * `${path}` is the label, else the path, else "this". A closure message receives the params.
     *
     * @param array<string, mixed> $params
     */
    public static function formatError(string|\Closure $message, array $params): string
    {
        $path = Yup::truthy($params['label'] ?? null) ? $params['label'] : (Yup::truthy($params['path'] ?? null) ? $params['path'] : 'this');
        $params['path'] = $path;

        if ($message instanceof \Closure) {
            return Yup::jsString($message($params));
        }

        return (string) preg_replace_callback(
            '/\$\{\s*(\w+)\s*\}/',
            static fn (array $m): string => Yup::print(array_key_exists($m[1], $params) ? $params[$m[1]] : Undefined::value()),
            $message,
        );
    }
}
