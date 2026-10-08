<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailNodemailer\Nodemailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\RawMessage;

/**
 * Not an upstream file: the part of the `nodemailer` npm package (`lib/mail-composer`,
 * `lib/addressparser`, the `envelope` / `messageId` logic of `lib/mailer/mail-message`) that turns a
 * nodemailer message object into a MIME message, built on symfony/mime.
 *
 * Supported message fields: `from`, `sender`, `to`, `cc`, `bcc`, `replyTo`, `inReplyTo`,
 * `references`, `subject`, `text`, `html`, `watchHtml`, `amp`, `icalEvent`, `alternatives`,
 * `attachments` (`content` string/stream with `encoding`, data-URI `path`, `filename`,
 * `contentType`, `contentDisposition`, `cid`, `headers`), `attachDataUrls`, `headers`,
 * `priority`, `messageId`, `date`, `xMailer`, `list`, `envelope`, `raw`, `textEncoding`,
 * `encoding`, `normalizeHeaderKey`, `disableFileAccess`, `disableUrlAccess`. Addresses are strings
 * (`'Name <a@b.c>, d@e.f'`), `{ name, address }` arrays or lists of either. `dkim`, `auth` and
 * `dsn` are handled by the transport.
 */
final class MailComposer
{
    /**
     * @param array<string, mixed> $mail
     * @return array{message: RawMessage, envelope: Envelope, messageId: string|null}
     */
    public static function compose(array $mail): array
    {
        $envelope = self::envelope($mail);

        if (isset($mail['raw']) && $mail['raw'] !== '' && $mail['raw'] !== false) {
            $raw = is_resource($mail['raw']) ? (string) stream_get_contents($mail['raw']) : self::stringValue($mail['raw']);
            $messageId = preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $raw, $m) === 1 ? $m[1] : null;

            return ['message' => new RawMessage($raw), 'envelope' => $envelope, 'messageId' => $messageId];
        }

        $message = new Message(self::headers($mail), self::body($mail));
        // fixed now (not on each getPreparedHeaders()) so DKIM signs, and info reports, the sent one
        $headers = $message->getHeaders();
        if (!$headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $message->generateMessageId());
        }
        $messageId = $headers->get('Message-ID')?->getBodyAsString();

        return ['message' => $message, 'envelope' => $envelope, 'messageId' => $messageId];
    }

    /**
     * `addressparser` + normalization: every address of a field, in order.
     *
     * @return list<Address>
     */
    public static function addresses(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if ($value instanceof Address) {
            return [$value];
        }
        if (is_array($value) && (array_key_exists('address', $value) || array_key_exists('name', $value)) && !array_is_list($value)) {
            $address = self::stringValue($value['address'] ?? '');
            $name = self::stringValue($value['name'] ?? '');

            return $address === '' ? [] : [new Address(trim($address), $name)];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out = [...$out, ...self::addresses($item)];
            }

            return $out;
        }

        $out = [];
        foreach (self::splitAddressList(self::stringValue($value)) as $item) {
            $out[] = self::address($item);
        }

        return $out;
    }

    /** `Name <a@b.c>`, `"Doe, J" <a@b.c>`, `a@b.c (Name)` or `a@b.c`. */
    private static function address(string $item): Address
    {
        $item = trim($item);
        if (preg_match('/^(.*?)\s*<([^>]*)>\s*(?:\((.*)\))?$/s', $item, $m) === 1) {
            $name = trim($m[1]);
            if (str_starts_with($name, '"') && str_ends_with($name, '"') && strlen($name) >= 2) {
                $name = stripcslashes(substr($name, 1, -1));
            }
            if ($name === '' && isset($m[3])) {
                $name = trim($m[3]);
            }

            return new Address(trim($m[2]), $name);
        }
        if (preg_match('/^(\S+@\S+)\s*\((.*)\)$/s', $item, $m) === 1) {
            return new Address($m[1], trim($m[2]));
        }

        return new Address($item);
    }

    /**
     * Splits on commas (and semicolons) outside quotes, angle brackets and comments.
     *
     * @return list<string>
     */
    private static function splitAddressList(string $value): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $depth = 0;
        $escaped = false;
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value);
        foreach ($chars as $char) {
            if ($escaped) {
                $current .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
                $current .= $char;
            } elseif ($char === '"') {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '<' || $char === '(')) {
                $depth++;
                $current .= $char;
            } elseif (!$inQuotes && ($char === '>' || $char === ')')) {
                $depth--;
                $current .= $char;
            } elseif (!$inQuotes && $depth <= 0 && ($char === ',' || $char === ';')) {
                if (trim($current) !== '') {
                    $parts[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * `message.envelope` (`from`, `to`, `cc`, `bcc`), else the sender (or first From) and every
     * To/Cc/Bcc recipient, deduplicated.
     *
     * @param array<string, mixed> $mail
     */
    public static function envelope(array $mail): Envelope
    {
        $custom = is_array($mail['envelope'] ?? null) ? $mail['envelope'] : null;

        $fromSource = $custom !== null && array_key_exists('from', $custom) ? $custom['from'] : ($mail['sender'] ?? $mail['from'] ?? null);
        $from = self::addresses($fromSource)[0] ?? null;

        $recipients = [];
        $sources = $custom !== null
            ? [$custom['to'] ?? null, $custom['cc'] ?? null, $custom['bcc'] ?? null]
            : [$mail['to'] ?? null, $mail['cc'] ?? null, $mail['bcc'] ?? null];
        foreach ($sources as $source) {
            foreach (self::addresses($source) as $address) {
                $recipients[strtolower($address->getAddress())] ??= new Address($address->getAddress());
            }
        }

        if ($from === null) {
            throw new \RuntimeException('Missing envelope sender (no "from" address).');
        }
        if ($recipients === []) {
            throw new \RuntimeException('No recipients defined');
        }

        return new Envelope(new Address($from->getAddress()), array_values($recipients));
    }

    /** @param array<string, mixed> $mail */
    private static function headers(array $mail): Headers
    {
        $headers = new Headers();
        $normalize = is_callable($mail['normalizeHeaderKey'] ?? null) ? $mail['normalizeHeaderKey'] : null;

        foreach (['from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc', 'replyTo' => 'Reply-To'] as $key => $name) {
            $list = self::addresses($mail[$key] ?? null);
            if ($list !== []) {
                $headers->addMailboxListHeader($name, $list);
            }
        }
        $sender = self::addresses($mail['sender'] ?? null)[0] ?? null;
        if ($sender !== null) {
            $headers->addMailboxHeader('Sender', $sender);
        }

        self::addIdHeader($headers, 'In-Reply-To', $mail['inReplyTo'] ?? null);
        self::addIdHeader($headers, 'References', $mail['references'] ?? null);

        if (isset($mail['subject'])) {
            $headers->addTextHeader('Subject', self::stringValue($mail['subject']));
        }

        $date = $mail['date'] ?? null;
        if ($date instanceof \DateTimeInterface) {
            $headers->addDateHeader('Date', $date);
        } elseif (is_int($date) || is_float($date)) {
            $headers->addDateHeader('Date', (new \DateTimeImmutable())->setTimestamp((int) floor($date / 1000)));
        } elseif (is_string($date) && $date !== '') {
            $headers->addDateHeader('Date', new \DateTimeImmutable($date));
        }

        self::addIdHeader($headers, 'Message-ID', $mail['messageId'] ?? null);

        $xMailer = $mail['xMailer'] ?? null;
        if (is_string($xMailer) && $xMailer !== '') {
            $headers->addTextHeader('X-Mailer', $xMailer);
        }

        $priority = is_string($mail['priority'] ?? null) ? strtolower($mail['priority']) : 'normal';
        if ($priority === 'high') {
            $headers->addTextHeader('X-Priority', '1 (Highest)');
            $headers->addTextHeader('X-MSMail-Priority', 'High');
            $headers->addTextHeader('Importance', 'High');
        } elseif ($priority === 'low') {
            $headers->addTextHeader('X-Priority', '5 (Lowest)');
            $headers->addTextHeader('X-MSMail-Priority', 'Low');
            $headers->addTextHeader('Importance', 'Low');
        }

        if (is_array($mail['list'] ?? null)) {
            foreach (self::listHeaders($mail['list']) as [$name, $value]) {
                $headers->addTextHeader($name, $value);
            }
        }

        foreach (self::customHeaders($mail['headers'] ?? null) as [$name, $value]) {
            if ($normalize !== null) {
                $name = self::stringValue($normalize($name));
            }
            $lower = strtolower($name);
            if (in_array($lower, ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'message-id', 'date', 'subject', 'in-reply-to', 'references'], true) && $headers->has($name)) {
                $headers->remove($name);
            }
            $headers->addTextHeader($name, $value);
        }

        return $headers;
    }

    private static function addIdHeader(Headers $headers, string $name, mixed $value): void
    {
        if ($value === null || $value === '' || $value === false || $value === []) {
            return;
        }
        $ids = [];
        foreach (is_array($value) ? $value : (preg_split('/\s+/', trim(self::stringValue($value))) ?: []) as $id) {
            $id = trim(self::stringValue($id));
            if ($id !== '') {
                $ids[] = trim($id, '<>');
            }
        }
        if ($ids === []) {
            return;
        }
        try {
            $headers->addIdHeader($name, $ids);
        } catch (\Throwable) {
            $headers->addTextHeader($name, implode(' ', array_map(static fn (string $id): string => "<{$id}>", $ids)));
        }
    }

    /**
     * `headers` as `{ key: value | value[] | { prepared, value } }` or `[{ key, value }]`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function customHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $out = [];
        if (array_is_list($headers)) {
            foreach ($headers as $header) {
                if (is_array($header) && isset($header['key'])) {
                    $out[] = [self::stringValue($header['key']), self::headerValue($header['value'] ?? '')];
                }
            }

            return $out;
        }
        foreach ($headers as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $out[] = [(string) $key, self::headerValue($item)];
                }
                continue;
            }
            $out[] = [(string) $key, self::headerValue($value)];
        }

        return $out;
    }

    private static function headerValue(mixed $value): string
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return self::stringValue($value['value']);
        }

        return self::stringValue($value);
    }

    /**
     * RFC 2369 `List-*` headers (nodemailer `_getListHeaders`).
     *
     * @param array<string, mixed> $list
     * @return list<array{0: string, 1: string}>
     */
    private static function listHeaders(array $list): array
    {
        $out = [];
        foreach ($list as $key => $data) {
            $key = strtolower(trim((string) $key));
            $name = $key === 'id' ? 'List-ID' : 'List-' . implode('-', array_map('ucfirst', explode('-', $key)));
            $entries = is_array($data) && array_is_list($data) ? $data : [$data];
            foreach ($entries as $entry) {
                $values = is_array($entry) && array_is_list($entry) ? $entry : [$entry];
                $formatted = [];
                foreach ($values as $value) {
                    if (is_string($value)) {
                        $value = ['url' => $value];
                    }
                    if (!is_array($value) || !is_string($value['url'] ?? null) || $value['url'] === '') {
                        continue;
                    }
                    $comment = is_string($value['comment'] ?? null) ? $value['comment'] : '';
                    if ($key === 'id') {
                        // RFC 2919 `List-Id: "comment" <list-id>`
                        $id = (string) preg_replace('/^[a-z]+:\/{0,2}/i', '', (string) preg_replace('/[\s<>]+/', '', $value['url']));
                        $formatted[] = ($comment !== '' ? '"' . $comment . '" ' : '') . "<{$id}>";
                    } else {
                        $formatted[] = self::formatListUrl($value['url']) . ($comment !== '' ? " ({$comment})" : '');
                    }
                }
                if ($formatted !== []) {
                    $out[] = [$name, implode(', ', $formatted)];
                }
            }
        }

        return $out;
    }

    private static function formatListUrl(string $url): string
    {
        $url = (string) preg_replace('/[\s<]+|[\s>]+/', '', $url);
        if (preg_match('/^(https?|mailto|ftp):/', $url) === 1) {
            return "<{$url}>";
        }
        if (preg_match('/^[^@]+@[^@]+$/', $url) === 1) {
            return "<mailto:{$url}>";
        }

        return "<http://{$url}>";
    }

    /** @param array<string, mixed> $mail */
    private static function body(array $mail): AbstractPart
    {
        $textEncoding = in_array($mail['textEncoding'] ?? null, ['quoted-printable', 'base64'], true) ? $mail['textEncoding'] : null;
        $contentEncoding = is_string($mail['encoding'] ?? null) ? strtolower($mail['encoding']) : null;
        $html = self::content($mail['html'] ?? null, $contentEncoding);
        $attachments = is_array($mail['attachments'] ?? null) ? array_values($mail['attachments']) : [];

        if ($html !== null && ($mail['attachDataUrls'] ?? false)) {
            [$html, $dataAttachments] = self::extractDataUrls($html);
            $attachments = [...$attachments, ...$dataAttachments];
        }

        $alternatives = [];
        $text = self::content($mail['text'] ?? null, $contentEncoding);
        if ($text !== null) {
            $alternatives[] = new TextPart($text, 'utf-8', 'plain', $textEncoding);
        }
        $watchHtml = self::content($mail['watchHtml'] ?? null, $contentEncoding);
        if ($watchHtml !== null) {
            $alternatives[] = new TextPart($watchHtml, 'utf-8', 'watch-html', $textEncoding);
        }
        $amp = self::content($mail['amp'] ?? null, $contentEncoding);
        if ($amp !== null) {
            $alternatives[] = new TextPart($amp, 'utf-8', 'x-amp-html', $textEncoding);
        }
        if (isset($mail['icalEvent']) && $mail['icalEvent'] !== false) {
            $ical = is_array($mail['icalEvent']) ? $mail['icalEvent'] : ['content' => $mail['icalEvent']];
            $icalContent = self::attachmentContent($ical, $mail);
            $method = strtoupper(is_string($ical['method'] ?? null) ? $ical['method'] : 'PUBLISH');
            $alternatives[] = self::calendarPart($icalContent, $method);
            $attachments[] = [
                'filename' => is_string($ical['filename'] ?? null) ? $ical['filename'] : 'invite.ics',
                'content' => $icalContent,
                'contentType' => 'application/ics',
            ];
        }
        foreach (is_array($mail['alternatives'] ?? null) ? $mail['alternatives'] : [] as $alternative) {
            if (!is_array($alternative)) {
                continue;
            }
            $content = self::attachmentContent($alternative, $mail);
            $contentType = is_string($alternative['contentType'] ?? null) ? strtolower($alternative['contentType']) : 'text/plain';
            [$type, $subtype] = array_pad(explode('/', explode(';', $contentType)[0], 2), 2, 'plain');
            $alternatives[] = $type === 'text'
                ? new TextPart($content, 'utf-8', trim($subtype), $textEncoding)
                : new DataPart($content, null, trim($type) . '/' . trim($subtype));
        }

        $related = [];
        $mixed = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $part = self::attachmentPart($attachment, $mail);
            if ($html !== null && is_string($attachment['cid'] ?? null) && $attachment['cid'] !== '') {
                $related[] = $part;
            } else {
                $mixed[] = $part;
            }
        }

        if ($html !== null) {
            $htmlPart = new TextPart($html, 'utf-8', 'html', $textEncoding);
            $alternatives[] = $related === [] ? $htmlPart : new RelatedPart($htmlPart, ...$related);
        }

        $body = match (count($alternatives)) {
            0 => null,
            1 => $alternatives[0],
            default => new AlternativePart(...$alternatives),
        };

        if ($mixed !== []) {
            return $body === null && count($mixed) === 1 ? new MixedPart($mixed[0]) : new MixedPart(...($body === null ? $mixed : [$body, ...$mixed]));
        }

        return $body ?? new TextPart('');
    }

    private static function calendarPart(string $content, string $method): TextPart
    {
        return new class ($content, $method) extends TextPart {
            public function __construct(string $content, private readonly string $method)
            {
                parent::__construct($content, 'utf-8', 'calendar');
            }

            public function getPreparedHeaders(): Headers
            {
                $headers = parent::getPreparedHeaders();
                $contentType = $headers->get('Content-Type');
                if ($contentType instanceof ParameterizedHeader) {
                    $contentType->setParameter('method', $this->method);
                }

                return $headers;
            }
        };
    }

    /**
     * @param array<string, mixed> $attachment
     * @param array<string, mixed> $mail
     */
    private static function attachmentPart(array $attachment, array $mail): DataPart
    {
        if (isset($attachment['raw'])) {
            throw new \RuntimeException('attachments[].raw is not supported');
        }

        $content = self::attachmentContent($attachment, $mail);
        $filename = $attachment['filename'] ?? null;
        if ($filename === null && is_string($attachment['path'] ?? null) && !str_starts_with($attachment['path'], 'data:')) {
            $filename = basename((string) parse_url($attachment['path'], PHP_URL_PATH));
        }
        $filename = is_string($filename) && $filename !== '' ? $filename : null;

        $contentType = is_string($attachment['contentType'] ?? null) ? $attachment['contentType'] : null;
        if ($contentType === null && is_string($attachment['path'] ?? null) && preg_match('/^data:([^;,]+)/', $attachment['path'], $m) === 1) {
            $contentType = $m[1];
        }
        if ($contentType === null && $filename !== null) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $contentType = $ext !== '' ? (MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? null) : null;
        }
        $contentType ??= 'application/octet-stream';

        $part = new DataPart($content, $filename, $contentType);
        $cid = is_string($attachment['cid'] ?? null) && $attachment['cid'] !== '' ? $attachment['cid'] : null;
        $disposition = is_string($attachment['contentDisposition'] ?? null) ? strtolower($attachment['contentDisposition']) : null;
        if ($cid !== null) {
            $cid = trim($cid, '<>');
            // symfony/mime only accepts `local@domain` content-ids; nodemailer takes any
            str_contains($cid, '@') ? $part->setContentId($cid) : $part->getHeaders()->addTextHeader('Content-ID', "<{$cid}>");
        }
        if ($disposition === 'inline' || ($cid !== null && $disposition === null)) {
            $part->asInline();
        }
        foreach (self::customHeaders($attachment['headers'] ?? null) as [$name, $value]) {
            $part->getHeaders()->addTextHeader($name, $value);
        }

        return $part;
    }

    /**
     * An attachment's (or alternative's) bytes: `content` (decoded per `encoding`) or `path` /
     * `href`. Local files and URLs are refused when `disableFileAccess` / `disableUrlAccess` is set,
     * with nodemailer's messages; a `data:` URI is always accepted.
     *
     * @param array<string, mixed> $attachment
     * @param array<string, mixed> $mail
     */
    private static function attachmentContent(array $attachment, array $mail): string
    {
        if (array_key_exists('content', $attachment) && $attachment['content'] !== null) {
            $content = $attachment['content'];
            $content = is_resource($content) ? (string) stream_get_contents($content) : self::stringValue($content);
            $encoding = is_string($attachment['encoding'] ?? null) ? strtolower($attachment['encoding']) : null;

            return self::decode($content, $encoding);
        }

        $path = $attachment['path'] ?? $attachment['href'] ?? null;
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (preg_match('/^data:([^,]*?)(;base64)?,(.*)$/s', $path, $m) === 1) {
            return $m[2] !== '' ? (string) base64_decode($m[3]) : rawurldecode($m[3]);
        }

        $isUrl = isset($attachment['href']) || preg_match('/^https?:\/\//i', $path) === 1;
        if ($isUrl) {
            if ($mail['disableUrlAccess'] ?? false) {
                throw new \RuntimeException("Url access rejected for {$path}");
            }
            $body = @file_get_contents($path);
            if ($body === false) {
                throw new \RuntimeException("Could not fetch {$path}");
            }

            return $body;
        }

        if ($mail['disableFileAccess'] ?? false) {
            throw new \RuntimeException("File access rejected for {$path}");
        }
        $body = @file_get_contents($path);
        if ($body === false) {
            throw new \RuntimeException("ENOENT: no such file or directory, open '{$path}'");
        }

        return $body;
    }

    private static function content(mixed $value, ?string $encoding): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (is_array($value)) {
            // `{ content, encoding }` / `{ path }` content objects
            return self::attachmentContent($value, []);
        }
        $value = is_resource($value) ? (string) stream_get_contents($value) : self::stringValue($value);

        return self::decode($value, $encoding);
    }

    private static function decode(string $content, ?string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode($content),
            'hex' => (string) hex2bin($content),
            default => $content,
        };
    }

    /**
     * `attachDataUrls`: `<img src="data:…">` becomes a `cid:` reference to an inline attachment.
     *
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private static function extractDataUrls(string $html): array
    {
        $attachments = [];
        $html = (string) preg_replace_callback(
            '/(<img\b[^<>]{0,1024} src\s{0,20}=[\s"\']{0,20})(data:([^;]+);[^"\'>\s]+)/i',
            static function (array $m) use (&$attachments): string {
                $cid = bin2hex(random_bytes(10)) . '@nodemailer';
                $attachments[] = ['path' => $m[2], 'cid' => $cid];

                return $m[1] . 'cid:' . $cid;
            },
            $html,
        );

        return [$html, $attachments];
    }

    private static function stringValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }
}
