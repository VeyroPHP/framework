<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * Turns a Message with rendered bodies into an RFC 5322 / MIME message.
 *
 * - CRLF line endings; no line longer than 998 bytes.
 * - Non-ASCII header text as RFC 2047 encoded words; non-ASCII attachment
 *   names as RFC 2231 parameters.
 * - Bodies quoted-printable (UTF-8), attachments base64.
 * - text + html become multipart/alternative; attachments multipart/mixed.
 * - Bcc is never written to the headers.
 *
 * @internal used by Mailer
 */
final class MimeBuilder
{
    /**
     * @return array{string, string} the raw message and its Message-ID (without angle brackets)
     */
    public function build(Message $message, Address $from, ?int $time = null): array
    {
        $text = $message->getText();
        $html = $message->getHtml();

        if ($text === null && $html === null) {
            throw new MailException('The message has no body: call text(), html() or view().');
        }

        $domain = substr($from->email, (int) strrpos($from->email, '@') + 1);
        $messageId = bin2hex(random_bytes(16)) . '@' . $domain;

        $headers = [
            'Date: ' . date(DATE_RFC2822, $time ?? time()),
            'Message-ID: <' . $messageId . '>',
            self::addressHeader('From', [$from]),
        ];

        foreach (['To' => $message->getTo(), 'Cc' => $message->getCc(), 'Reply-To' => $message->getReplyTo()] as $name => $addresses) {
            if ($addresses !== []) {
                $headers[] = self::addressHeader($name, $addresses);
            }
        }

        $headers[] = 'Subject: ' . self::headerText($message->getSubject(), strlen('Subject: '));

        foreach ($message->getHeaders() as $name => $value) {
            $headers[] = $name . ': ' . self::headerText($value, strlen($name) + 2);
        }

        $headers[] = 'MIME-Version: 1.0';

        $body = self::body($text, $html);

        if ($message->getAttachments() !== []) {
            $boundary = self::boundary();
            $parts = [$body];

            foreach ($message->getAttachments() as $attachment) {
                $parts[] = self::attachment($attachment);
            }

            $body = ['Content-Type: multipart/mixed; boundary="' . $boundary . '"', self::multipart($boundary, $parts)];
        }

        return [implode("\r\n", $headers) . "\r\n" . $body[0] . "\r\n\r\n" . $body[1], $messageId];
    }

    /**
     * Plain text from HTML, for the text part when only HTML was given.
     */
    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $m): string {
                $label = trim(strip_tags($m[3]));
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
            },
            $html,
        );
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|div|h[1-6]|li|tr|table|ul|ol|blockquote)\s*>#i', "\n\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * @return array{string, string} content headers and content
     */
    private static function body(?string $text, ?string $html): array
    {
        if ($text !== null && $html !== null) {
            $boundary = self::boundary();

            return [
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                self::multipart($boundary, [self::textPart($text, 'plain'), self::textPart($html, 'html')]),
            ];
        }

        return $html !== null ? self::textPart($html, 'html') : self::textPart((string) $text, 'plain');
    }

    /**
     * @return array{string, string}
     */
    private static function textPart(string $content, string $subtype): array
    {
        $content = (string) preg_replace('/\r\n?|\n/', "\r\n", $content);

        return [
            "Content-Type: text/{$subtype}; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable",
            quoted_printable_encode($content),
        ];
    }

    /**
     * @return array{string, string}
     */
    private static function attachment(Attachment $attachment): array
    {
        $name = self::parameter('name', $attachment->name);
        $filename = self::parameter('filename', $attachment->name);

        return [
            "Content-Type: {$attachment->mimeType};\r\n {$name}\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment;\r\n {$filename}",
            rtrim(chunk_split(base64_encode($attachment->data), 76, "\r\n"), "\r\n"),
        ];
    }

    /**
     * A header parameter on its own folded line: quoted when short ASCII,
     * otherwise RFC 2231 percent-encoding, split into numbered continuations
     * (name*0*, name*1*, ...) so no line passes 78 characters.
     */
    private static function parameter(string $param, string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $value) === 1 && strlen($param) + strlen($value) + 4 <= 76) {
            return $param . '="' . $value . '"';
        }

        preg_match_all('/%[0-9A-F]{2}|[^%]/', rawurlencode($value), $tokens);
        $chunks = [];
        $chunk = '';

        foreach ($tokens[0] as $token) {
            if (strlen($chunk) + strlen($token) > 54) {
                $chunks[] = $chunk;
                $chunk = '';
            }

            $chunk .= $token;
        }

        $chunks[] = $chunk;

        if (count($chunks) === 1) {
            return $param . "*=utf-8''" . $chunks[0];
        }

        $parts = [];

        foreach ($chunks as $i => $part) {
            $parts[] = $param . '*' . $i . '*=' . ($i === 0 ? "utf-8''" : '') . $part;
        }

        return implode(";\r\n ", $parts);
    }

    /**
     * @param list<array{string, string}> $parts
     */
    private static function multipart(string $boundary, array $parts): string
    {
        $out = '';

        foreach ($parts as [$headers, $content]) {
            $out .= '--' . $boundary . "\r\n" . $headers . "\r\n\r\n" . $content . "\r\n";
        }

        return $out . '--' . $boundary . '--';
    }

    /**
     * "=_" never occurs in quoted-printable or base64 output, so the boundary cannot collide with content.
     */
    private static function boundary(): string
    {
        return '=_' . bin2hex(random_bytes(12));
    }

    /**
     * @param list<Address> $addresses
     */
    private static function addressHeader(string $name, array $addresses): string
    {
        $formatted = [];

        foreach ($addresses as $i => $address) {
            if ($address->name === '') {
                $formatted[] = $address->email;

                continue;
            }

            $used = $i === 0 ? strlen($name) + 2 : 1;
            $display = self::displayName($address->name, $used);
            $lastBreak = strrpos($display, "\r\n");
            $lastLine = $lastBreak === false ? $used + strlen($display) : strlen($display) - $lastBreak - 2;
            // Folding is allowed between the name and the address.
            $separator = $lastLine + 2 + strlen($address->email) + 2 > 78 ? "\r\n " : ' ';
            $formatted[] = $display . $separator . '<' . $address->email . '>';
        }

        return $name . ': ' . implode(",\r\n ", $formatted);
    }

    private static function displayName(string $name, int $used): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $name) !== 1) {
            return self::encodedWords($name, $used);
        }

        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/D', $name) === 1) {
            return $name;
        }

        return '"' . addcslashes($name, '"\\') . '"';
    }

    /**
     * Unstructured header text: as-is when short ASCII, otherwise encoded words.
     */
    private static function headerText(string $value, int $used): string
    {
        $value = str_replace("\t", ' ', $value);

        if (preg_match('/^[\x20-\x7E]*$/D', $value) === 1 && $used + strlen($value) <= 78) {
            return $value;
        }

        if (preg_match('/^[\x20-\x7E]*$/D', $value) === 1) {
            return self::fold($value, $used);
        }

        return self::encodedWords($value, $used);
    }

    /**
     * Folds long ASCII text at spaces so lines stay short; words longer than a line are kept whole
     * (still far below the 998-byte limit for any sensible header).
     */
    private static function fold(string $value, int $used): string
    {
        $lines = [];
        $line = '';

        foreach (explode(' ', $value) as $word) {
            if ($line !== '' && $used + strlen($line) + 1 + strlen($word) > 78) {
                $lines[] = $line;
                $line = $word;
                $used = 1;

                continue;
            }

            $line = $line === '' && $lines === [] ? $word : $line . ' ' . $word;
        }

        $lines[] = $line;

        return implode("\r\n ", $lines);
    }

    /**
     * RFC 2047 "B" encoded words, split between characters (never inside one), each
     * on its own line of at most 78 characters. $used is what precedes the first word
     * on its line (for example "Subject: ").
     */
    private static function encodedWords(string $value, int $used): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            throw new MailException('Header text must be valid UTF-8.');
        }

        // 12 characters of "=?UTF-8?B?" and "?="; every 3 bytes become 4 characters; words stay <= 75.
        $limit = static fn(int $used): int => max(3, intdiv(min(78 - $used, 75) - 12, 4) * 3);
        $max = $limit($used);
        $words = [];
        $chunk = '';

        foreach ($chars as $char) {
            if ($chunk !== '' && strlen($chunk) + strlen($char) > $max) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
                $max = $limit(1);
            }

            $chunk .= $char;
        }

        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }
}
