<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Tests\Nodemailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A symfony/mailer transport that keeps what would go over the wire (the SMTP `MAIL FROM`,
 * `RCPT TO` and `DATA`) instead of connecting anywhere. Loaded with `require_once`.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<array{data: string, from: string, to: list<string>}> */
    public array $messages = [];

    public ?\Throwable $error = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        $envelope ??= Envelope::create($message);
        $sent = new SentMessage($message, $envelope);
        $this->messages[] = [
            'data' => $sent->toString(),
            'from' => $envelope->getSender()->getAddress(),
            'to' => array_values(array_map(static fn ($a): string => $a->getAddress(), $envelope->getRecipients())),
        ];

        return $sent;
    }

    public function last(): ?array
    {
        return $this->messages === [] ? null : $this->messages[count($this->messages) - 1];
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
