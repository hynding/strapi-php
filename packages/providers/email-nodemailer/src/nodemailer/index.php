<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Nodemailer;

/**
 * Not an upstream file: `nodemailer.createTransport(options)`, the entry point of the `nodemailer`
 * npm package the sendmail and nodemailer providers use, on symfony/mailer (see SmtpTransport).
 *
 * `Nodemailer::$createTransport` replaces the factory (what `vi.mock('nodemailer')` does upstream);
 * set it back to `null` to restore the real one.
 */
final class Nodemailer
{
    /** @var (\Closure(array<string, mixed>): Transporter)|null */
    public static ?\Closure $createTransport = null;

    /** @param array<string, mixed> $options */
    public static function createTransport(array $options = []): Transporter
    {
        if (self::$createTransport !== null) {
            return (self::$createTransport)($options);
        }

        return new SmtpTransport($options);
    }
}
