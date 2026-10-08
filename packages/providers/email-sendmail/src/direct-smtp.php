<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailSendmail;

use Strapi\Provider\EmailNodemailer\Nodemailer\Nodemailer;

/**
 * Port of src/direct-smtp.ts.
 *
 * Direct SMTP delivery (per recipient domain, per MX with fallback), replacing the unmaintained
 * `sendmail` npm package while preserving the same routing semantics. The per-host SMTP client is
 * nodemailer's (Strapi\Provider\EmailNodemailer\Nodemailer, on symfony/mailer), as upstream.
 *
 * `DirectSmtp::$resolveMx` and `DirectSmtp::$hostname` replace `dns.resolveMx` and `os.hostname`
 * (what upstream's tests `vi.mock`); `null` restores `dns_get_record(DNS_MX)` / `gethostname()`.

 */
final class DirectSmtp
{
    /** @var (\Closure(string): list<array{exchange: string, priority: int}>)|null */
    public static ?\Closure $resolveMx = null;

    /** @var (\Closure(): string)|null */
    public static ?\Closure $hostname = null;

    /**
     * Mirrors legacy `const devPort = options.devPort || -1` (guileen/node-sendmail).
     * `0` is falsy → `-1` (MX mode). `true` is truthy and was passed to `createConnection` (Node
     * coerces port `true` to `1`).
     *
     * @param array<string, mixed> $options
     */
    private static function getEffectiveDevPort(array $options): int
    {
        $v = $options['devPort'] ?? null;
        if ($v === null || $v === false) {
            return -1;
        }
        if ($v === true) {
            return 1;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v ?: -1;
        }

        return -1;
    }

    /**
     * Legacy `sendmail` connects with `createConnection(devPort, devHost)` in dev mode — the
     * dev port is the SMTP port. Otherwise use `smtpPort` (default 25).
     *
     * @param array<string, mixed> $options
     */
    private static function getOutboundSmtpPort(array $options): int
    {
        $dev = self::getEffectiveDevPort($options);
        if ($dev !== -1) {
            return $dev;
        }

        return is_numeric($options['smtpPort'] ?? null) && (int) $options['smtpPort'] !== 0 ? (int) $options['smtpPort'] : 25;
    }

    /**
     * Build list of SMTP peers to try for a recipient domain (MX resolution or dev server),
     * matching guileen/node-sendmail `connectMx` + `smtpHost` append behavior.
     *
     * @param array<string, mixed> $options
     * @return list<array{exchange: string}>
     */
    public static function resolveMxHosts(string $domain, array $options): array
    {
        $devPort = self::getEffectiveDevPort($options);
        $devHost = is_string($options['devHost'] ?? null) && $options['devHost'] !== '' ? $options['devHost'] : 'localhost';

        if ($devPort !== -1) {
            return [['exchange' => $devHost]];
        }

        try {
            $records = (self::$resolveMx ?? self::dnsResolveMx(...))($domain);
        } catch (\Throwable $err) {
            throw new \RuntimeException("can not resolve Mx of <{$domain}>: {$err->getMessage()}", 0, $err);
        }

        if (count($records) === 0) {
            throw new \RuntimeException("can not resolve Mx of <{$domain}>");
        }

        usort($records, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        // Mirror legacy `const smtpHost = options.smtpHost || -1` behavior: falsy (eg 0) disables.
        $smtpHost = $options['smtpHost'] ?? null;
        if ($smtpHost !== null && $smtpHost !== '' && $smtpHost !== 0 && $smtpHost !== false && is_scalar($smtpHost)) {
            $records[] = ['exchange' => (string) $smtpHost, 'priority' => 9999];
        }

        return array_map(
            static fn (array $r): array => ['exchange' => (string) preg_replace('/\.$/', '', $r['exchange'])],
            $records,
        );
    }

    /**
     * @param array<string, mixed> $mail
     * @param array<string, mixed>|null $dkim
     * @param list<string> $recipients
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function trySendViaHost(
        string $exchange,
        int $smtpPort,
        string $srcHost,
        string $fromEnvelope,
        array $recipients,
        array $mail,
        ?array $dkim,
        array $options,
    ): array {
        $transporter = Nodemailer::createTransport([
            'host' => $exchange,
            'port' => $smtpPort,
            'secure' => false,
            // Legacy sendmail@1.6.1 uses plain SMTP sockets and does not attempt STARTTLS.
            'ignoreTLS' => true,
            'requireTLS' => false,
            'name' => $srcHost,
            'tls' => [
                'rejectUnauthorized' => $options['rejectUnauthorized'] ?? null,
            ],
            'connectionTimeout' => 60_000,
            'greetingTimeout' => 30_000,
            'socketTimeout' => 60_000,
        ]);

        try {
            return $transporter->sendMail([
                ...$mail,
                'envelope' => [
                    'from' => $fromEnvelope,
                    'to' => $recipients,
                ],
                'dkim' => $dkim,
                // Prevent nodemailer from reading local files or fetching URLs referenced by
                // `attachments[].path` / `attachments[].href`. Set after the spread so a caller
                // cannot re-enable file/URL access through the passed mail options.
                'disableFileAccess' => true,
                'disableUrlAccess' => true,
            ]);
        } finally {
            $transporter->close();
        }
    }

    /**
     * Direct SMTP delivery (per recipient domain, per MX with fallback), replacing the
     * unmaintained `sendmail` npm package while preserving the same routing semantics.
     *
     * @param array<string, mixed> $mail
     * @param array<string, mixed> $providerOptions
     */
    public static function sendDirectSmtp(array $mail, array $providerOptions): void
    {
        $logger = Logger::createLogger($providerOptions);
        $smtpPort = self::getOutboundSmtpPort($providerOptions);

        $fromHeader = is_string($mail['from'] ?? null) ? $mail['from'] : '';
        $fromAddr = Addressing::extractEmail($fromHeader);
        $fromHost = Addressing::getHostFromAddress($fromAddr);
        $hostname = (self::$hostname ?? static fn (): string => (string) gethostname())();
        $srcHost = $fromHost !== null && $fromHost !== '' ? $fromHost : ($hostname !== '' ? $hostname : 'localhost');

        $dkimOpt = $providerOptions['dkim'] ?? null;
        $dkim = is_array($dkimOpt) && array_key_exists('privateKey', $dkimOpt)
            ? [
                'domainName' => $srcHost,
                'keySelector' => is_string($dkimOpt['keySelector'] ?? null) && $dkimOpt['keySelector'] !== '' ? $dkimOpt['keySelector'] : 'dkim',
                'privateKey' => $dkimOpt['privateKey'],
            ]
            : null;

        $recipients = Addressing::collectRecipients([
            'to' => self::addressField($mail['to'] ?? null),
            'cc' => self::addressField($mail['cc'] ?? null),
            'bcc' => self::addressField($mail['bcc'] ?? null),
        ]);

        if (count($recipients) === 0) {
            throw new \RuntimeException('No recipients defined');
        }

        $groups = Addressing::groupRecipientsByDomain($recipients);
        $fromEnvelope = $fromAddr;

        $anyDomainDelivered = false;

        foreach ($groups as $domain => $domainRecipients) {
            $domain = (string) $domain;
            try {
                $hosts = self::resolveMxHosts($domain, $providerOptions);
            } catch (\Throwable $err) {
                $logger->error('Sendmail provider: MX resolution failed', $err);

                throw $err;
            }

            $lastError = null;
            $sent = false;

            foreach ($hosts as ['exchange' => $exchange]) {
                try {
                    self::trySendViaHost(
                        $exchange,
                        $smtpPort,
                        $srcHost,
                        $fromEnvelope,
                        $domainRecipients,
                        $mail,
                        $dkim,
                        $providerOptions,
                    );
                    $sent = true;
                    break;
                } catch (\Throwable $err) {
                    $lastError = $err;
                    $logger->error("Sendmail provider: failed to send via {$exchange}:{$smtpPort}", $lastError);
                }
            }

            if ($sent) {
                $anyDomainDelivered = true;
            } else {
                $logger->error("Sendmail provider: failed to deliver for domain {$domain}", $lastError);
            }
        }

        if (!$anyDomainDelivered) {
            throw new \RuntimeException('Failed to deliver mail for all recipient domains');
        }
    }

    /** @return string|list<mixed>|null */
    private static function addressField(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_values($value);
        }

        return null;
    }

    /** @return list<array{exchange: string, priority: int}> */
    private static function dnsResolveMx(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);
        if ($records === false) {
            throw new \RuntimeException(error_get_last()['message'] ?? 'queryMx ENOTFOUND ' . $domain);
        }

        return array_values(array_map(
            static fn (array $r): array => ['exchange' => (string) ($r['target'] ?? ''), 'priority' => (int) ($r['pri'] ?? 0)],
            $records,
        ));
    }
}
