<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Nodemailer;

/**
 * Not an upstream file: the `Transporter` returned by nodemailer's `createTransport()`
 * (synchronous: where nodemailer returns a promise, the call returns or throws).
 *
 * @phpstan-type SentMessageInfo array{messageId: string|null, envelope: array{from: string, to: list<string>}, accepted: list<string>, rejected: list<string>, pending: list<string>, response: string}
 */
interface Transporter
{
    /**
     * @param array<string, mixed> $mail a nodemailer message object
     * @return array<string, mixed> SentMessageInfo
     */
    public function sendMail(array $mail): array;

    /** Connects (and authenticates) to check the configuration; throws when it fails. */
    public function verify(): true;

    public function isIdle(): bool;

    public function close(): void;
}
