<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/services/token.ts (`admin::token`). */
final class Token
{
    private const DEFAULT_JWT_OPTIONS = ['expiresIn' => '30d'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array{secret: mixed, options: array<string, mixed>} */
    public function getTokenOptions(): array
    {
        $auth = $this->strapi->config()->get('admin.auth', []);
        $auth = is_array($auth) ? $auth : [];
        $options = is_array($auth['options'] ?? null) ? $auth['options'] : [];

        // Check for new sessions.options configuration
        $sessionsOptions = $this->strapi->config()->get('admin.auth.sessions.options', []);

        // Merge with legacy options for backward compatibility
        /** @var array<string, mixed> $mergedOptions */
        $mergedOptions = Objects::merge([], self::DEFAULT_JWT_OPTIONS, $options, is_array($sessionsOptions) ? $sessionsOptions : []);

        return [
            'secret' => $auth['secret'] ?? null,
            'options' => $mergedOptions,
        ];
    }

    /**
     * True when the project set `admin.auth.options.expiresIn`.
     * Do not use merged options from {@see getTokenOptions()}: defaults always inject `expiresIn: '30d'`,
     * which would make every install look like a legacy config (see GitHub #25989).
     */
    public static function hasUserConfiguredAuthOptionsExpiresIn(mixed $adminAuthOptions): bool
    {
        if (!is_array($adminAuthOptions) && !is_object($adminAuthOptions)) {
            return false;
        }

        $expiresIn = is_array($adminAuthOptions) ? ($adminAuthOptions['expiresIn'] ?? null) : ($adminAuthOptions->expiresIn ?? null);

        return $expiresIn !== null;
    }

    /** Create a random token */
    public function createToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    public function checkSecretIsDefined(): void
    {
        if ($this->strapi->config()->get('admin.serveAdminPanel') && !$this->strapi->config()->get('admin.auth.secret')) {
            throw new \RuntimeException(
                "Missing auth.secret. Please set auth.secret in config/admin.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`).\n"
                . 'For security reasons, prefer storing the secret in an environment variable and read it in config/admin.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.'
            );
        }
    }

    /**
     * Convert an expiresIn value (string or number) into seconds.
     * Supported formats:
     * - number: treated as seconds
     * - numeric string (e.g. "180"): treated as seconds
     * - shorthand string: "Xs", "Xm", "Xh", "Xd", "Xw" (case-insensitive)
     * Returns null (upstream: undefined) when value is not set or invalid.
     */
    public static function expiresInToSeconds(mixed $expiresIn): ?int
    {
        if ($expiresIn === null) {
            return null;
        }

        // Numeric input => seconds
        if (is_int($expiresIn) || (is_float($expiresIn) && is_finite($expiresIn))) {
            return max(0, (int) floor($expiresIn));
        }

        if (!is_string($expiresIn)) {
            return null;
        }

        $value = strtolower(trim($expiresIn));

        // Pure numeric string => seconds
        if (preg_match('/^\d+$/', $value) === 1) {
            return max(0, (int) $value);
        }

        // Shorthand formats (s, m, h, d, w)
        if (preg_match('/^(\d+)\s*(ms|s|m|h|d|w)$/i', $value, $match) !== 1) {
            return null;
        }

        $amount = (int) $match[1];

        return match ($match[2]) {
            'ms' => max(0, intdiv($amount, 1000)),
            's' => max(0, $amount),
            'm' => max(0, $amount * 60),
            'h' => max(0, $amount * 60 * 60),
            'd' => max(0, $amount * 24 * 60 * 60),
            'w' => max(0, $amount * 7 * 24 * 60 * 60),
            default => null,
        };
    }
}
