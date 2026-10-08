<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Types\Core\Cookies as CookiesContract;

/**
 * Port of the `cookies` npm module (0.9) as Koa builds it for `ctx.cookies`:
 * `new Cookies(req, res, { keys: app.keys, secure: request.secure })`.
 *
 * Same rules: `get(name)` without options returns the raw value (signature not checked), `get`
 * with options checks `<name>.sig` when keys exist; `set()` signs (adds `<name>.sig`) when keys
 * exist unless `signed: false`; a `secure` cookie on an insecure request throws; an empty value
 * expires the cookie; the header is serialized like `Cookie.prototype.toHeader`.
 */
final class Cookies implements CookiesContract
{
    private const FIELD_CONTENT = '/^[\x{0009}\x{0020}-\x{007e}\x{0080}-\x{00ff}]+$/u';
    private const DOMAIN_VALUE = '/^([.]?[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)([.][a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i';
    private const PATH_VALUE = '/^[ -:=-~]*$/';
    private const SAME_SITE = '/^(?:lax|none|strict)$/i';
    private const PRIORITY = '/^(?:low|medium|high)$/i';

    /** @param list<string>|null $keys */
    public function __construct(private readonly Context $ctx, private readonly ?array $keys, private readonly bool $secure)
    {
    }

    public function get(string $name, ?array $opts = null): ?string
    {
        $signed = $opts !== null && array_key_exists('signed', $opts) ? (bool) $opts['signed'] : $this->keys !== null;

        $header = $this->ctx->request()->getHeaderLine('Cookie');
        if ($header === '') {
            return null;
        }

        if (preg_match('/(?:^|;) *' . preg_quote($name, '/') . '=([^;]*)/', $header, $match) !== 1) {
            return null;
        }

        $value = $match[1];
        if (str_starts_with($value, '"')) {
            $value = substr($value, 1, -1);
        }
        if ($opts === null || !$signed) {
            return $value;
        }

        $remote = $this->get($name . '.sig');
        if ($remote === null || $remote === '') {
            return null;
        }

        $data = $name . '=' . $value;
        $index = $this->keyIndex($data, $remote);

        if ($index < 0) {
            $this->set($name . '.sig', null, ['path' => '/', 'signed' => false]);

            return null;
        }

        if ($index > 0) {
            $this->set($name . '.sig', self::sign($data, $this->keys[0] ?? ''), ['signed' => false]);
        }

        return $value;
    }

    public function set(string $name, ?string $value, ?array $opts = null): static
    {
        $signed = $opts !== null && array_key_exists('signed', $opts) ? (bool) $opts['signed'] : $this->keys !== null;

        if (!$this->secure && $opts !== null && !empty($opts['secure'])) {
            throw new \RuntimeException('Cannot send secure cookie over unencrypted connection');
        }

        $cookie = self::cookie($name, $value, $opts ?? []);
        $cookie['secure'] = $opts !== null && array_key_exists('secure', $opts) && $opts['secure'] !== null ? (bool) $opts['secure'] : $this->secure;

        $this->pushCookie($cookie);

        if ($opts !== null && $signed) {
            if ($this->keys === null || $this->keys === []) {
                throw new \RuntimeException('.keys required for signed cookies');
            }
            $cookie['value'] = self::sign($cookie['name'] . '=' . $cookie['value'], $this->keys[0]);
            $cookie['name'] .= '.sig';
            $this->pushCookie($cookie);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private static function cookie(string $name, ?string $value, array $attrs): array
    {
        if (preg_match(self::FIELD_CONTENT, $name) !== 1 || preg_match('/[;=]/', $name) === 1) {
            throw new \TypeError('argument name is invalid');
        }

        if ($value !== null && $value !== '' && (preg_match(self::FIELD_CONTENT, $value) !== 1 || str_contains($value, ';'))) {
            throw new \TypeError('argument value is invalid');
        }

        $cookie = [
            'path' => '/',
            'expires' => null,
            'domain' => null,
            'httpOnly' => true,
            'partitioned' => false,
            'priority' => null,
            'sameSite' => false,
            'secure' => false,
            'overwrite' => false,
            'maxAge' => null,
            ...$attrs,
            'name' => $name,
            'value' => $value ?? '',
        ];

        if ($cookie['value'] === '') {
            $cookie['expires'] = new \DateTimeImmutable('@0');
            $cookie['maxAge'] = null;
        }

        if (is_string($cookie['path']) && $cookie['path'] !== '' && preg_match(self::PATH_VALUE, $cookie['path']) !== 1) {
            throw new \TypeError('option path is invalid');
        }

        if (is_string($cookie['domain']) && $cookie['domain'] !== '' && preg_match(self::DOMAIN_VALUE, $cookie['domain']) !== 1) {
            throw new \TypeError('option domain is invalid');
        }

        if (is_float($cookie['maxAge']) && !is_finite($cookie['maxAge'])) {
            throw new \TypeError('option maxAge is invalid');
        }

        if (is_string($cookie['priority']) && $cookie['priority'] !== '' && preg_match(self::PRIORITY, $cookie['priority']) !== 1) {
            throw new \TypeError('option priority is invalid');
        }

        $sameSite = $cookie['sameSite'];
        if ($sameSite !== null && $sameSite !== false && $sameSite !== true && (!is_string($sameSite) || preg_match(self::SAME_SITE, $sameSite) !== 1)) {
            throw new \TypeError('option sameSite is invalid');
        }

        return $cookie;
    }

    /** @param array<string, mixed> $cookie */
    private static function toHeader(array $cookie): string
    {
        $header = $cookie['name'] . '=' . $cookie['value'];
        $expires = $cookie['expires'];

        if (!empty($cookie['maxAge'])) {
            $expires = (new \DateTimeImmutable())->setTimestamp((int) floor((microtime(true) * 1000 + (float) $cookie['maxAge']) / 1000));
        }

        if (!empty($cookie['path'])) {
            $header .= '; path=' . $cookie['path'];
        }
        if ($expires instanceof \DateTimeInterface) {
            $header .= '; expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expires->getTimestamp());
        }
        if (!empty($cookie['domain'])) {
            $header .= '; domain=' . $cookie['domain'];
        }
        if (!empty($cookie['priority'])) {
            $header .= '; priority=' . strtolower((string) $cookie['priority']);
        }
        if (!empty($cookie['sameSite'])) {
            $header .= '; samesite=' . ($cookie['sameSite'] === true ? 'strict' : strtolower((string) $cookie['sameSite']));
        }
        if (!empty($cookie['secure'])) {
            $header .= '; secure';
        }
        if (!empty($cookie['httpOnly'])) {
            $header .= '; httponly';
        }
        if (!empty($cookie['partitioned'])) {
            $header .= '; partitioned';
        }

        return $header;
    }

    /** @param array<string, mixed> $cookie */
    private function pushCookie(array $cookie): void
    {
        $headers = $this->ctx->responseHeaders()['set-cookie'] ?? [];

        if (!empty($cookie['overwrite'])) {
            $headers = array_values(array_filter($headers, static fn (string $h): bool => !str_starts_with($h, $cookie['name'] . '=')));
        }

        $headers[] = self::toHeader($cookie);

        $this->ctx->removeHeader('Set-Cookie');
        foreach ($headers as $header) {
            $this->ctx->appendHeader('Set-Cookie', $header);
        }
    }

    /** keygrip `index()`: the position of the key that produced `$digest`, -1 when none did. */
    private function keyIndex(string $data, string $digest): int
    {
        foreach ($this->keys ?? [] as $i => $key) {
            if (hash_equals(self::sign($data, $key), $digest)) {
                return $i;
            }
        }

        return -1;
    }

    /** keygrip `sign()` with its defaults: HMAC-SHA1, base64url without padding. */
    public static function sign(string $data, string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha1', $data, $key, true)), '+/', '-_'), '=');
    }
}
