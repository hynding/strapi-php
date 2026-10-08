<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Nodemailer;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\AuthenticatorInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\CramMd5Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport as SymfonySmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Message;

/**
 * Not an upstream file: nodemailer's SMTP (and `sendmail: true`) transport on symfony/mailer.
 *
 * nodemailer transport options and what they map to:
 *
 * | nodemailer | symfony/mailer |
 * | --- | --- |
 * | `host` (default `localhost`), `port` (default 465 when `secure`, else 587) | `EsmtpTransport` host / port |
 * | `secure: true` | implicit TLS (`smtps`); otherwise STARTTLS when the server offers it |
 * | `ignoreTLS`, `requireTLS` | `setAutoTls(false)`, `setRequireTls(true)` |
 * | `auth.user` / `auth.pass` | `setUsername` / `setPassword` (CRAM-MD5, LOGIN, PLAIN, XOAUTH2 tried in order) |
 * | `auth.type: 'OAuth2'` + `auth.user` / `auth.accessToken` | XOAUTH2 with the access token (tokens are not refreshed: `refreshToken`/`clientId`/`clientSecret`/`serviceClient` are not used) |
 * | `authMethod` (`PLAIN`, `LOGIN`, `CRAM-MD5`, `XOAUTH2`) | that authenticator only |
 * | `name` (default: the host name when it is a FQDN, else `[127.0.0.1]`) | `setLocalDomain` (EHLO name) |
 * | `localAddress` | `SocketStream::setSourceIp` |
 * | `tls.rejectUnauthorized: false`, `tls.servername`, `tls.ciphers`, `tls.ca` (PEM file path), `tls.minVersion` | `ssl` stream context options `verify_peer`/`verify_peer_name`, `peer_name`, `ciphers`, `cafile`, `crypto_method` |
 * | `connectionTimeout` / `greetingTimeout` / `socketTimeout` (ms) | one socket timeout (`socketTimeout`, else the largest given) |
 * | `pool: true` | the connection stays open between messages (closed by `close()`); without it, one connection per message as nodemailer does |
 * | `maxMessages` | `setRestartThreshold` (reconnect after that many messages) |
 * | `rateLimit` / `rateDelta` (ms, default 1000) | `setMaxPerSecond(rateLimit / rateDelta s)` |
 * | `dkim` (`domainName`, `keySelector`, `privateKey`, `skipFields`, or `keys: [...]`) | `DkimSigner` (a message's own `dkim` wins) |
 * | `service` (well-known name) | host / port / secure of that service (a subset of nodemailer's list) |
 * | `sendmail: true`, `path`, `args` | `SendmailTransport` (`<path> -i -t` by default) |
 * | `streamTransport: true` / `jsonTransport: true` | nothing is sent; the info's `message` is the MIME message / the message as JSON |
 *
 * Not supported: `proxy`, `maxConnections` (one synchronous connection), connection URLs, `logger` /
 * `debug`, the `SES` transport, and the per-message `dsn`
 * (accepted and ignored). A message's `auth` (OAuth2 `user` + `accessToken`) re-authenticates for
 * that message.
 */
final class SmtpTransport implements Transporter
{
    /** nodemailer `well-known/services.json` (subset): name (lowercase, no spaces) => [host, port, secure] */
    private const array SERVICES = [
        'gmail' => ['smtp.gmail.com', 465, true],
        'googlemail' => ['smtp.gmail.com', 465, true],
        'outlook365' => ['smtp.office365.com', 587, false],
        'hotmail' => ['smtp-mail.outlook.com', 587, false],
        'outlook' => ['smtp-mail.outlook.com', 587, false],
        'yahoo' => ['smtp.mail.yahoo.com', 465, true],
        'icloud' => ['smtp.mail.me.com', 587, false],
        'zoho' => ['smtp.zoho.com', 465, true],
        'sendgrid' => ['smtp.sendgrid.net', 587, false],
        'mailgun' => ['smtp.mailgun.org', 465, true],
        'postmark' => ['smtp.postmarkapp.com', 2525, false],
        'mailjet' => ['in.mailjet.com', 587, false],
        'sendinblue' => ['smtp-relay.brevo.com', 587, false],
        'brevo' => ['smtp-relay.brevo.com', 587, false],
        'ses' => ['email-smtp.us-east-1.amazonaws.com', 465, true],
        'fastmail' => ['smtp.fastmail.com', 465, true],
    ];

    private ?TransportInterface $transport;

    private readonly bool $pool;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = [], ?TransportInterface $transport = null)
    {
        $this->transport = $transport;
        $this->pool = (bool) ($options['pool'] ?? false);
    }

    public function sendMail(array $mail): array
    {
        ['message' => $message, 'envelope' => $envelope, 'messageId' => $messageId] = MailComposer::compose($mail);

        $dkim = $mail['dkim'] ?? $this->options['dkim'] ?? null;
        if (is_array($dkim) && $message instanceof Message) {
            foreach (self::dkimSigners($dkim) as $signer) {
                $message = $signer->sign($message);
            }
        }

        $recipients = array_map(static fn ($a): string => $a->getAddress(), $envelope->getRecipients());
        $info = [
            'messageId' => $messageId,
            'envelope' => ['from' => $envelope->getSender()->getAddress(), 'to' => $recipients],
            'accepted' => $recipients,
            'rejected' => [],
            'pending' => [],
        ];

        // nodemailer's `streamTransport` / `jsonTransport`: build the message, send nothing
        if (($this->options['streamTransport'] ?? false) === true) {
            return [...$info, 'response' => '', 'message' => $message->toString()];
        }
        if (($this->options['jsonTransport'] ?? false) === true) {
            $data = array_filter($mail, static fn (mixed $v): bool => $v !== null && !is_resource($v) && !$v instanceof \Closure);

            return [...$info, 'response' => '', 'message' => (string) json_encode([...$data, 'messageId' => $messageId], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }

        $transport = $this->transport();
        $restoreAuth = $this->applyMessageAuth($transport, $mail['auth'] ?? null);

        try {
            $sent = $transport->send($message, $envelope);
        } finally {
            if ($restoreAuth !== null) {
                $restoreAuth();
            } elseif (!$this->pool) {
                $this->stop();
            }
        }

        return [...$info, 'response' => $sent instanceof SentMessage ? self::lastResponse($sent->getDebug()) : ''];
    }

    public function verify(): true
    {
        $transport = $this->transport();
        if ($transport instanceof SymfonySmtpTransport) {
            $transport->start();
            if (!$this->pool) {
                $transport->stop();
            }
        } elseif ($transport instanceof SendmailTransport) {
            $path = (string) ($this->options['path'] ?? '/usr/sbin/sendmail');
            if (!is_executable($path)) {
                throw new \RuntimeException("spawn {$path} ENOENT");
            }
        }

        return true;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->stop();
    }

    private function stop(): void
    {
        if ($this->transport instanceof SymfonySmtpTransport) {
            $this->transport->stop();
        }
    }

    private function transport(): TransportInterface
    {
        return $this->transport ??= $this->createSymfonyTransport();
    }

    private function createSymfonyTransport(): TransportInterface
    {
        $options = $this->options;

        if (($options['sendmail'] ?? false) === true) {
            $path = is_string($options['path'] ?? null) ? $options['path'] : '/usr/sbin/sendmail';
            $args = is_array($options['args'] ?? null) ? array_map('strval', $options['args']) : ['-i'];
            $command = trim($path . ' ' . implode(' ', $args));
            if (!str_contains($command, ' -t') && !str_contains($command, ' -bs')) {
                $command .= ' -t';
            }

            return new SendmailTransport($command);
        }

        $service = is_string($options['service'] ?? null) ? self::SERVICES[strtolower(str_replace([' ', '-', '_'], '', $options['service']))] ?? null : null;
        $host = is_string($options['host'] ?? null) && $options['host'] !== '' ? $options['host'] : ($service[0] ?? 'localhost');
        $secure = isset($options['secure']) ? (bool) $options['secure'] : ($service[2] ?? false);
        $port = is_numeric($options['port'] ?? null) ? (int) $options['port'] : ($service[1] ?? ($secure ? 465 : 587));

        $transport = new EsmtpTransport($host, $port, $secure);

        if ($options['ignoreTLS'] ?? false) {
            $transport->setAutoTls(false);
        }
        if ($options['requireTLS'] ?? false) {
            $transport->setRequireTls(true);
        }

        $auth = is_array($options['auth'] ?? null) ? $options['auth'] : [];
        $isOAuth2 = is_string($auth['type'] ?? null) && strtolower($auth['type']) === 'oauth2';
        if (isset($auth['user'])) {
            $transport->setUsername((string) $auth['user']);
        }
        if ($isOAuth2) {
            $transport->setPassword((string) ($auth['accessToken'] ?? ''));
            $transport->setAuthenticators([new XOAuth2Authenticator()]);
        } elseif (isset($auth['pass'])) {
            $transport->setPassword((string) $auth['pass']);
        }
        if (is_string($options['authMethod'] ?? null) && !$isOAuth2) {
            $authenticator = self::authenticator($options['authMethod']);
            if ($authenticator !== null) {
                $transport->setAuthenticators([$authenticator]);
            }
        }

        $transport->setLocalDomain(is_string($options['name'] ?? null) && $options['name'] !== '' ? $options['name'] : self::defaultName());

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $timeouts = array_filter(
                [$options['connectionTimeout'] ?? null, $options['greetingTimeout'] ?? null],
                static fn (mixed $v): bool => is_numeric($v),
            );
            $timeout = is_numeric($options['socketTimeout'] ?? null) ? $options['socketTimeout'] : ($timeouts === [] ? null : max($timeouts));
            if ($timeout !== null) {
                $stream->setTimeout(((float) $timeout) / 1000);
            }
            if (is_string($options['localAddress'] ?? null) && $options['localAddress'] !== '') {
                $stream->setSourceIp($options['localAddress']);
            }
            $ssl = self::sslOptions(is_array($options['tls'] ?? null) ? $options['tls'] : []);
            if ($ssl !== []) {
                $stream->setStreamOptions(['ssl' => [...($stream->getStreamOptions()['ssl'] ?? []), ...$ssl]]);
            }
        }

        if (is_numeric($options['maxMessages'] ?? null) && (int) $options['maxMessages'] > 0) {
            $transport->setRestartThreshold((int) $options['maxMessages']);
        }
        if (is_numeric($options['rateLimit'] ?? null) && (float) $options['rateLimit'] > 0) {
            $rateDelta = is_numeric($options['rateDelta'] ?? null) && (float) $options['rateDelta'] > 0 ? (float) $options['rateDelta'] : 1000.0;
            $transport->setMaxPerSecond((float) $options['rateLimit'] / ($rateDelta / 1000));
        }

        return $transport;
    }

    /**
     * Per-message OAuth2 credentials: re-authenticate for this message, then restore.
     *
     * @return (\Closure(): void)|null
     */
    private function applyMessageAuth(TransportInterface $transport, mixed $auth): ?\Closure
    {
        if (!$transport instanceof EsmtpTransport || !is_array($auth) || !isset($auth['accessToken'])) {
            return null;
        }
        $user = $transport->getUsername();
        $password = $transport->getPassword();
        $transport->stop();
        if (isset($auth['user'])) {
            $transport->setUsername((string) $auth['user']);
        }
        $transport->setPassword((string) $auth['accessToken']);

        return static function () use ($transport, $user, $password): void {
            $transport->stop();
            $transport->setUsername($user);
            $transport->setPassword($password);
        };
    }

    /**
     * @param array<string, mixed> $dkim
     * @return list<DkimSigner>
     */
    private static function dkimSigners(array $dkim): array
    {
        $keys = is_array($dkim['keys'] ?? null) ? $dkim['keys'] : [$dkim];
        $signers = [];
        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }
            $key = [...$dkim, ...$key];
            if (!is_string($key['privateKey'] ?? null) || !is_string($key['domainName'] ?? null)) {
                continue;
            }
            $skip = is_string($key['skipFields'] ?? null) ? array_values(array_filter(array_map('trim', explode(':', $key['skipFields'])))) : [];
            $signers[] = new DkimSigner(
                $key['privateKey'],
                $key['domainName'],
                is_string($key['keySelector'] ?? null) ? $key['keySelector'] : 'default',
                ['headers_to_ignore' => $skip],
            );
        }

        return $signers;
    }

    private static function authenticator(string $method): ?AuthenticatorInterface
    {
        return match (strtoupper($method)) {
            'PLAIN' => new PlainAuthenticator(),
            'LOGIN' => new LoginAuthenticator(),
            'CRAM-MD5' => new CramMd5Authenticator(),
            'XOAUTH2' => new XOAuth2Authenticator(),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $tls
     * @return array<string, mixed>
     */
    private static function sslOptions(array $tls): array
    {
        $ssl = [];
        if (($tls['rejectUnauthorized'] ?? true) === false) {
            $ssl['verify_peer'] = false;
            $ssl['verify_peer_name'] = false;
            $ssl['allow_self_signed'] = true;
        }
        if (is_string($tls['servername'] ?? null)) {
            $ssl['peer_name'] = $tls['servername'];
        }
        if (is_string($tls['ciphers'] ?? null)) {
            $ssl['ciphers'] = $tls['ciphers'];
        }
        if (is_string($tls['ca'] ?? null) && is_file($tls['ca'])) {
            $ssl['cafile'] = $tls['ca'];
        }
        $methods = [
            'TLSv1' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'TLSv1.1' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'TLSv1.2' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'TLSv1.3' => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if (is_string($tls['minVersion'] ?? null) && isset($methods[$tls['minVersion']])) {
            $ssl['crypto_method'] = $methods[$tls['minVersion']];
        }

        return $ssl;
    }

    /** nodemailer's default EHLO name: the host name when it is a FQDN, else `[127.0.0.1]`. */
    private static function defaultName(): string
    {
        $hostname = (string) gethostname();

        return str_contains($hostname, '.') ? $hostname : '127.0.0.1';
    }

    private static function lastResponse(string $debug): string
    {
        $response = '';
        foreach (preg_split('/\r?\n/', $debug) ?: [] as $line) {
            if (preg_match('/^< (2\d\d .*)$/', $line, $m) === 1) {
                $response = $m[1];
            }
        }

        return $response;
    }
}
