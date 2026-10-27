<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Mail\Address;
use VeyroPHP\Mail\Attachment;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\MimeBuilder;

#[CoversClass(Address::class)]
#[CoversClass(Attachment::class)]
#[CoversClass(Message::class)]
#[CoversClass(MimeBuilder::class)]
#[CoversClass(MailException::class)]
final class MessageTest extends TestCase
{
    private static function build(Message $message, ?Address $from = null): string
    {
        return (new MimeBuilder())->build($message, $from ?? new Address('shop@example.com', 'Shop'), 1_793_000_000)[0];
    }

    /**
     * Splits a raw message into headers (unfolded, by lower-case name) and body.
     *
     * @return array{array<string, string>, string}
     */
    private static function parse(string $raw): array
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = [];

        foreach (explode("\r\n", (string) preg_replace("/\r\n[ \t]/", ' ', $head)) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = ltrim($value);
        }

        return [$headers, $body];
    }

    private static function capture(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[1])) {
            self::fail(sprintf('"%s" does not match %s', $subject, $pattern));
        }

        return $m[1];
    }

    private static function decodeHeader(string $value): string
    {
        // RFC 2047: whitespace between two encoded words is ignored, elsewhere it is kept.
        $value = (string) preg_replace('/\?=\s+=\?UTF-8\?B\?/', '?==?UTF-8?B?', $value);

        return (string) preg_replace_callback(
            '/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/',
            static fn(array $m): string => (string) base64_decode($m[1], true),
            $value,
        );
    }

    // Addresses

    public function testAddressParsing(): void
    {
        $a = Address::parse('Asha Verma <asha@example.com>');
        self::assertSame(['asha@example.com', 'Asha Verma'], [$a->email, $a->name]);

        $b = Address::parse('"Verma, Asha" <asha@example.com>');
        self::assertSame('Verma, Asha', $b->name);

        $c = Address::parse(' asha@example.com ');
        self::assertSame(['asha@example.com', ''], [$c->email, $c->name]);
        self::assertSame('Asha Verma <asha@example.com>', (string) $a);
        self::assertSame($a, Address::parse($a));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badAddresses(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'no at' => ['asha.example.com', ''];
        yield 'header injection in address' => ["asha@example.com\r\nBcc: victim@example.com", ''];
        yield 'angle bracket' => ['asha@example.com>', ''];
        yield 'space' => ['asha verma@example.com', ''];
        yield 'non-ascii' => ['आशा@example.com', ''];
        yield 'header injection in name' => ['asha@example.com', "Asha\r\nBcc: victim@example.com"];
    }

    #[DataProvider('badAddresses')]
    public function testBadAddressesAreRejected(string $email, string $name): void
    {
        $this->expectException(MailException::class);
        new Address($email, $name);
    }

    public function testInvalidAddressMessageDoesNotEchoControlCharacters(): void
    {
        try {
            new Address("a\r\nb");
            self::fail('expected MailException');
        } catch (MailException $e) {
            self::assertStringNotContainsString("\r", $e->getMessage());
            self::assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    // Message validation

    public function testSubjectAndHeadersRejectLineBreaks(): void
    {
        $message = new Message();

        foreach ([
            static fn() => $message->subject("Hi\r\nBcc: victim@example.com"),
            static fn() => $message->subject("Hi\nthere"),
            static fn() => $message->header('X-Campaign', "a\r\nBcc: victim@example.com"),
            static fn() => $message->header("X-Bad\r\nBcc", 'x'),
            static fn() => $message->header('X Bad', 'x'),
            static fn() => $message->header("X-Bad\n", 'x'),
            static fn() => $message->header('Bcc', 'victim@example.com'),
            static fn() => $message->header('content-type', 'text/html'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('call ' . $i . ' should be rejected');
            } catch (MailException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame("Tab\tok", $message->subject("Tab\tok")->getSubject());
    }

    public function testRecipientsIncludeCcAndBccWithoutDuplicates(): void
    {
        $message = (new Message())
            ->to('asha@example.com')
            ->to('Ravi <ravi@example.com>')
            ->cc('ASHA@example.com')
            ->bcc(new Address('audit@example.com', 'Audit'));

        self::assertSame(['asha@example.com', 'ravi@example.com', 'audit@example.com'], $message->recipients());
        self::assertSame('Ravi', $message->getTo()[1]->name);
        self::assertSame('Asha', (new Message())->to(new Address('asha@example.com'), 'Asha')->getTo()[0]->name);
    }

    // MIME output

    public function testSimpleTextMessage(): void
    {
        $raw = self::build((new Message())->to('asha@example.com', 'Asha')->subject('Order 42')->text("Line one\nLine two"));
        [$headers, $body] = self::parse($raw);

        self::assertSame('Shop <shop@example.com>', $headers['from']);
        self::assertSame('Asha <asha@example.com>', $headers['to']);
        self::assertSame('Order 42', $headers['subject']);
        self::assertSame(date(DATE_RFC2822, 1_793_000_000), $headers['date']);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $headers['message-id']);
        self::assertSame('1.0', $headers['mime-version']);
        self::assertSame('text/plain; charset=utf-8', $headers['content-type']);
        self::assertSame('quoted-printable', $headers['content-transfer-encoding']);
        self::assertSame("Line one\r\nLine two", quoted_printable_decode($body));
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $raw), 'only CRLF line endings');
    }

    public function testBccIsNeverInTheHeaders(): void
    {
        $raw = self::build((new Message())->to('asha@example.com')->bcc('secret-audit@example.com')->subject('x')->text('y'));

        self::assertStringNotContainsString('secret-audit', $raw);
        self::assertArrayNotHasKey('bcc', self::parse($raw)[0]);
    }

    public function testNonAsciiSubjectNamesAndBody(): void
    {
        $subject = 'आपका ऑर्डर तैयार है — Order №42 is ready for pickup at the Jaipur store ✓';
        $raw = self::build((new Message())->to('asha@example.com', 'आशा वर्मा')->replyTo('help@example.com', 'Help, Desk')->subject($subject)->text('नमस्ते ✓'));
        [$headers, $body] = self::parse($raw);

        self::assertSame($subject, self::decodeHeader($headers['subject']));
        self::assertSame('आशा वर्मा <asha@example.com>', self::decodeHeader($headers['to']));
        self::assertSame('"Help, Desk" <help@example.com>', $headers['reply-to']);
        self::assertSame('नमस्ते ✓', quoted_printable_decode($body));
        self::assertSame(1, preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $raw), 'the whole message is 7-bit ASCII');

        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), 'line too long: ' . $line);
        }
    }

    public function testLongAsciiSubjectIsFolded(): void
    {
        $subject = trim(str_repeat('word ', 40));
        [$headers] = self::parse($raw = self::build((new Message())->to('a@example.com')->subject($subject)->text('x')));

        self::assertSame($subject, $headers['subject']);
        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(78, strlen($line));
        }
    }

    public function testInvalidUtf8InSubjectIsRejected(): void
    {
        $this->expectException(MailException::class);
        self::build((new Message())->to('a@example.com')->subject("bad \xC3\x28")->text('x'));
    }

    public function testTextAndHtmlBecomeMultipartAlternative(): void
    {
        $raw = self::build((new Message())->to('a@example.com')->subject('x')->text('Plain')->html('<p>Rich</p>'));
        [$headers, $body] = self::parse($raw);

        $boundary = self::capture('/^multipart\/alternative; boundary="(=_[0-9a-f]{24})"$/', $headers['content-type']);
        $parts = explode('--' . $boundary, $body);
        self::assertCount(4, $parts, 'preamble, text, html, closing');
        self::assertStringContainsString('text/plain; charset=utf-8', $parts[1]);
        self::assertStringContainsString('text/html; charset=utf-8', $parts[2]);
        self::assertSame('--', $parts[3], 'closing boundary ends the body');
    }

    public function testAttachmentsBecomeMultipartMixed(): void
    {
        $pdf = "%PDF-1.4\n" . random_bytes(500);
        $raw = self::build((new Message())->to('a@example.com')->subject('x')->text('See attached')->html('<p>See attached</p>')
            ->attach(Attachment::fromData($pdf, 'Invoice 42.pdf'))
            ->attach(Attachment::fromData('a,b', 'रिपोर्ट.csv', 'text/csv')));
        [$headers, $body] = self::parse($raw);

        $boundary = self::capture('/^multipart\/mixed; boundary="([^"]+)"$/', $headers['content-type']);
        $parts = explode('--' . $boundary, $body);
        self::assertCount(5, $parts, 'preamble, alternative body, two attachments, closing');
        self::assertStringContainsString('multipart/alternative', $parts[1]);

        [$pdfHeaders, $pdfBody] = explode("\r\n\r\n", trim($parts[2], "\r\n"), 2);
        self::assertStringContainsString("Content-Type: application/pdf;\r\n name=\"Invoice 42.pdf\"", $pdfHeaders);
        self::assertStringContainsString("Content-Disposition: attachment;\r\n filename=\"Invoice 42.pdf\"", $pdfHeaders);
        self::assertSame($pdf, base64_decode(str_replace("\r\n", '', $pdfBody), true), 'attachment survives byte for byte');

        preg_match_all("/filename\*\d+\*=(?:utf-8'')?([^;\r\n]+)/", $parts[3], $pieces);
        self::assertSame('रिपोर्ट.csv', rawurldecode(implode('', $pieces[1])), 'RFC 2231 continuations');
        self::assertStringContainsString("Content-Type: text/csv;\r\n", $parts[3]);
    }

    public function testLongNonAsciiNamesStayWithinLineLimits(): void
    {
        $longName = str_repeat('वार्षिक-रिपोर्ट-', 6) . '२०२६.pdf';
        $raw = (new MimeBuilder())->build(
            (new Message())->to('asha.verma.long.address@example.com', 'आशा वर्मा')->subject('x')->text('y')
                ->attach(Attachment::fromData('%PDF-1.4', $longName, 'application/pdf')),
            new Address('shop@example.com', 'वेल्थारो दुकान'),
        )[0];

        foreach (explode("\r\n", $raw) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), 'line too long: ' . $line);
        }

        // The RFC 2231 continuations join back to the exact name.
        preg_match_all("/filename\\*(\\d+)\\*=(?:utf-8'')?([^;\\r\\n]+)/", $raw, $m, PREG_SET_ORDER);
        self::assertGreaterThan(1, count($m), 'split into continuations');
        self::assertSame($longName, rawurldecode(implode('', array_column($m, 2))));
    }

    public function testExtraHeaders(): void
    {
        [$headers] = self::parse(self::build((new Message())->to('a@example.com')->subject('x')->text('y')
            ->header('List-Unsubscribe', '<https://example.com/unsubscribe/abc>')
            ->header('X-Campaign', 'दिवाली')));

        self::assertSame('<https://example.com/unsubscribe/abc>', $headers['list-unsubscribe']);
        self::assertSame('दिवाली', self::decodeHeader($headers['x-campaign']));
    }

    public function testMessageWithoutBodyIsRejected(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('no body');
        self::build((new Message())->to('a@example.com')->subject('x'));
    }

    public function testBodyLinesStartingWithDotsAndLongLinesAreSafe(): void
    {
        $text = ".hidden?\n" . str_repeat('x', 2000) . "\nend";
        [, $body] = self::parse(self::build((new Message())->to('a@example.com')->subject('x')->text($text)));

        foreach (explode("\r\n", $body) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }

        self::assertSame(str_replace("\n", "\r\n", $text), quoted_printable_decode($body));
    }

    // Attachments

    public function testAttachmentFromPathDetectsType(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'att');
        self::assertIsString($file);
        file_put_contents($file, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        try {
            $attachment = Attachment::fromPath($file, 'logo.png');
            self::assertSame('image/png', $attachment->mimeType);
            self::assertSame('logo.png', $attachment->name);
        } finally {
            unlink($file);
        }
    }

    public function testBadAttachmentsAreRejected(): void
    {
        foreach ([
            static fn() => Attachment::fromPath(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4))),
            static fn() => Attachment::fromData('x', "a\r\nContent-Type: text/html"),
            static fn() => Attachment::fromData('x', '../etc/passwd'),
            static fn() => Attachment::fromData('x', 'a"b.txt'),
            static fn() => Attachment::fromData('x', ''),
            static fn() => Attachment::fromData('x', 'a.txt', "text/plain\r\nX: y"),
            static fn() => Attachment::fromData('x', 'a.txt', "text/plain\n"),
        ] as $i => $call) {
            try {
                $call();
                self::fail('attachment ' . $i . ' should be rejected');
            } catch (MailException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // HTML to text

    public function testHtmlToText(): void
    {
        $html = <<<'HTML'
            <html><head><title>T</title><style>p { color: red }</style></head>
            <body><h1>Hello  Asha</h1><p>Your order is <b>ready</b>.<br>Thanks &amp; regards</p>
            <ul><li>One</li><li>Two</li></ul>
            <p><a href="https://example.com/orders/42?a=1&amp;b=2">View order</a> or <a href="https://example.com">https://example.com</a></p>
            <script>alert(1)</script></body></html>
            HTML;

        self::assertSame(
            "Hello Asha\n\nYour order is ready.\nThanks & regards\n\n- One\n\n- Two\n\nView order (https://example.com/orders/42?a=1&b=2) or https://example.com",
            MimeBuilder::htmlToText($html),
        );
    }
}
