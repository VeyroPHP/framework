<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Mail\Address;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\Transport\SmtpTransport;
use VeyroPHP\Mail\TransportException;

/**
 * SmtpTransport against a real socket: a scripted SMTP server in a child
 * process (tests/Fixtures/Mail/smtp-server.php), including STARTTLS with a
 * self-signed certificate created for the test.
 */
#[CoversClass(SmtpTransport::class)]
#[CoversClass(TransportException::class)]
final class SmtpTransportTest extends TestCase
{
    private static string $dir;

    private static ?string $certFile = null;

    private static ?string $caFile = null;

    /** @var resource|null */
    private $process = null;

    private string $scenarioFile = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-smtp-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);

        // A self-signed certificate for 127.0.0.1. XAMPP's PHP on Windows needs the openssl.cnf path.
        $config = [];
        foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf'] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                $config = ['config' => $candidate];
                break;
            }
        }

        $key = openssl_pkey_new($config + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = false;

        if ($key !== false) {
            $csrKey = $key; // passed by reference
            $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $csrKey, $config + ['digest_alg' => 'sha256']);
            $cert = $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 1, $config + ['digest_alg' => 'sha256']) : false;
        }

        while (openssl_error_string() !== false) {
            // Clear OpenSSL's error queue (it reports the missing default config file).
        }

        $certPem = $keyPem = null;

        if ($key !== false && $cert !== false && openssl_x509_export($cert, $certPem) && openssl_pkey_export($key, $keyPem, null, $config)
            && is_string($certPem) && is_string($keyPem)) {
            self::$caFile = self::$dir . '/ca.pem';
            self::$certFile = self::$dir . '/server.pem';
            file_put_contents(self::$caFile, $certPem);
            file_put_contents(self::$certFile, $certPem . $keyPem);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir(self::$dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function startServer(array $scenario): int
    {
        $this->scenarioFile = self::$dir . '/scenario-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->scenarioFile, json_encode($scenario + ['cert' => self::$certFile], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Mail/smtp-server.php', $this->scenarioFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->process = $process;

        $line = fgets($pipes[1]);

        // Read stderr only on failure: it blocks until the server exits.
        if ($line === false) {
            self::fail('server did not start: ' . stream_get_contents($pipes[2]));
        }

        if (preg_match('/^PORT (\d+)/', $line, $m) !== 1) {
            self::fail('unexpected server output: ' . $line);
        }

        return (int) $m[1];
    }

    /**
     * @return array{commands: list<string>, data: string|null, tls: bool, auth: array{mechanism: string, user: string, pass: string, tls: bool}|null}
     */
    private function transcript(): array
    {
        \assert(is_resource($this->process));
        $deadline = microtime(true) + 10;

        while (!is_file($this->scenarioFile . '.out') && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $transcript = json_decode((string) file_get_contents($this->scenarioFile . '.out'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($transcript);

        /** @var array{commands: list<string>, data: string|null, tls: bool, auth: array{mechanism: string, user: string, pass: string, tls: bool}|null} $transcript */
        return $transcript;
    }

    private function requireTls(): void
    {
        if (self::$certFile === null) {
            self::markTestSkipped('Could not create a test certificate with this PHP/OpenSSL build.');
        }
    }

    /**
     * @param list<string> $recipients
     */
    private static function sent(string $raw = "Subject: Hi\r\n\r\nHello\r\n", array $recipients = ['asha@example.com']): SentMessage
    {
        return new SentMessage(new Message(), $raw, 'id@example.com', 'shop@example.com', $recipients, 'smtp');
    }

    public function testPlainConnectionSendsTheMessageWithDotStuffing(): void
    {
        $port = $this->startServer([]);
        $raw = "Subject: Dots\r\n\r\n.starts with a dot\r\n..two dots\r\nmiddle . dot\r\n.\r\nend";

        (new SmtpTransport('127.0.0.1', $port, 'none', localDomain: 'app.test'))->send(self::sent($raw, ['a@example.com', 'b@example.com']));

        $t = $this->transcript();
        self::assertSame(['EHLO app.test', 'MAIL FROM:<shop@example.com>', 'RCPT TO:<a@example.com>', 'RCPT TO:<b@example.com>', 'DATA', 'QUIT'], $t['commands']);
        self::assertSame($raw . "\r\n", $t['data'], 'the server receives exactly the message, dots restored');
        self::assertFalse($t['tls']);
    }

    public function testStartTlsWithVerifiedCertificateAndAuthPlain(): void
    {
        $this->requireTls();
        $port = $this->startServer(['starttls' => true, 'auth' => ['LOGIN', 'PLAIN'], 'user' => 'shop', 'pass' => 'p@ss wörd']);

        (new SmtpTransport('127.0.0.1', $port, 'tls', 'shop', 'p@ss wörd', caFile: self::$caFile))->send(self::sent());

        $t = $this->transcript();
        self::assertTrue($t['tls']);
        self::assertSame(['mechanism' => 'PLAIN', 'user' => 'shop', 'pass' => 'p@ss wörd', 'tls' => true], $t['auth'], 'credentials only after TLS');
        self::assertSame(['EHLO', 'STARTTLS', 'EHLO', 'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT'], array_map(static fn(string $c): string => (string) strtok($c, ' '), $t['commands']));
        self::assertSame("Subject: Hi\r\n\r\nHello\r\n\r\n", $t['data']);
    }

    public function testAuthLoginWhenPlainIsNotOffered(): void
    {
        $this->requireTls();
        $port = $this->startServer(['starttls' => true, 'auth' => ['LOGIN'], 'user' => 'shop', 'pass' => 'secret']);

        (new SmtpTransport('127.0.0.1', $port, 'tls', 'shop', 'secret', caFile: self::$caFile))->send(self::sent());

        self::assertSame(['mechanism' => 'LOGIN', 'user' => 'shop', 'pass' => 'secret', 'tls' => true], $this->transcript()['auth']);
    }

    public function testUntrustedCertificateIsRefused(): void
    {
        $this->requireTls();
        $port = $this->startServer(['starttls' => true]);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'tls'))->send(self::sent());
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('TLS with the SMTP server 127.0.0.1 failed', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }

        self::assertNull($this->transcript()['data'], 'nothing was sent');
    }

    public function testVerifyPeerFalseAcceptsSelfSignedCertificate(): void
    {
        $this->requireTls();
        $port = $this->startServer(['starttls' => true]);

        (new SmtpTransport('127.0.0.1', $port, 'tls', verifyPeer: false))->send(self::sent());

        self::assertTrue($this->transcript()['tls']);
    }

    public function testImplicitTls(): void
    {
        $this->requireTls();
        $port = $this->startServer(['implicit_tls' => true, 'auth' => ['PLAIN'], 'user' => 'shop', 'pass' => 'secret']);

        (new SmtpTransport('127.0.0.1', $port, 'ssl', 'shop', 'secret', caFile: self::$caFile))->send(self::sent());

        $t = $this->transcript();
        self::assertTrue($t['tls']);
        self::assertSame(['mechanism' => 'PLAIN', 'user' => 'shop', 'pass' => 'secret', 'tls' => true], $t['auth']);
        self::assertNotContains('STARTTLS', $t['commands']);
    }

    public function testImplicitTlsAgainstAPlainServerFails(): void
    {
        $this->requireTls();
        // The server speaks plain SMTP, so the TLS handshake cannot succeed.
        $port = $this->startServer([]);

        $this->expectException(TransportException::class);
        (new SmtpTransport('127.0.0.1', $port, 'ssl', timeout: 3, caFile: self::$caFile))->send(self::sent());
    }

    public function testMissingStartTlsIsNotDowngraded(): void
    {
        $port = $this->startServer(['auth' => ['PLAIN'], 'user' => 'shop', 'pass' => 'secret']);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'tls', 'shop', 'secret'))->send(self::sent());
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }

        $t = $this->transcript();
        self::assertNull($t['auth'], 'password never sent');
        self::assertNull($t['data']);
    }

    public function testCredentialsAreNeverSentUnencryptedToARemoteHost(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 25, 'none', 'shop', 'secret');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('without encryption');
        $transport->send(self::sent());
    }

    public function testWrongPasswordGivesA535WithHintAndNoPassword(): void
    {
        $port = $this->startServer(['auth' => ['PLAIN'], 'user' => 'shop', 'pass' => 'right']);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'none', 'shop', 'wrong-secret-123'))->send(self::sent());
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertSame(535, $e->smtpCode);
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('check the SMTP username and password', $e->getMessage());
            self::assertStringNotContainsString('wrong-secret-123', $e->getMessage());
            self::assertStringNotContainsString(base64_encode("\0shop\0wrong-secret-123"), $e->getMessage());
        }
    }

    public function testRejectedRecipient(): void
    {
        $port = $this->startServer(['reject_rcpt' => ['nobody@example.com']]);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'none'))->send(self::sent(recipients: ['asha@example.com', 'nobody@example.com']));
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertSame(550, $e->smtpCode);
            self::assertStringContainsString('550 to RCPT', $e->getMessage());
        }

        self::assertNull($this->transcript()['data']);
    }

    public function testNoSupportedAuthMechanism(): void
    {
        $port = $this->startServer(['auth' => ['CRAM-MD5', 'XOAUTH2']]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('offered: CRAM-MD5, XOAUTH2');
        (new SmtpTransport('127.0.0.1', $port, 'none', 'shop', 'secret'))->send(self::sent());
    }

    public function testTimeoutWhenServerDoesNotAnswer(): void
    {
        $port = $this->startServer(['silent' => true]);
        $start = microtime(true);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'none', timeout: 1))->send(self::sent());
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('did not answer connect within 1 seconds', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }

        self::assertLessThan(3.5, microtime(true) - $start);
    }

    public function testServerClosingTheConnection(): void
    {
        $port = $this->startServer(['close_after_greeting' => true]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('closed the connection');
        (new SmtpTransport('127.0.0.1', $port, 'none'))->send(self::sent());
    }

    public function testConnectionRefused(): void
    {
        // Find a free port, then close it again so nothing listens there.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        try {
            (new SmtpTransport('127.0.0.1', $port, 'none', timeout: 2))->send(self::sent());
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('Could not connect to the SMTP server 127.0.0.1:' . $port, $e->getMessage());
            self::assertSame(0, $e->smtpCode);
        }
    }

    public function testFullMessageThroughMailer(): void
    {
        $port = $this->startServer([]);
        $transport = new SmtpTransport('127.0.0.1', $port, 'none');
        $mailer = new Mailer(static fn(): SmtpTransport => $transport, 'smtp', new Address('shop@example.com', 'Shop'));

        $sent = $mailer->send((new Message())->to('asha@example.com')->bcc('audit@example.com')->subject('Order 42')->html('<p>Thanks</p>'));

        $data = (string) $this->transcript()['data'];
        self::assertSame($sent->raw . "\r\n", $data);
        self::assertStringNotContainsString('audit@example.com', $data, 'Bcc is not in the message');
        self::assertContains('RCPT TO:<audit@example.com>', $this->transcript()['commands'], 'but it is a recipient');
    }

    /**
     * @return list<array{string, int, string, ?string, ?string, string, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            ['', 25, 'none', null, null, 'localhost', 'host'],
            ['smtp example.com', 25, 'none', null, null, 'localhost', 'host'],
            ['smtp.example.com', 0, 'none', null, null, 'localhost', 'port'],
            ['smtp.example.com', 587, 'starttls', null, null, 'localhost', 'encryption'],
            ['smtp.example.com', 587, 'tls', 'user', null, 'localhost', 'together'],
            ['smtp.example.com', 587, 'tls', null, null, "bad\r\nname", 'local_domain'],
        ];
    }

    public function testInvalidSettingsAreRejected(): void
    {
        foreach (self::invalidSettings() as [$host, $port, $encryption, $user, $pass, $local, $message]) {
            try {
                new SmtpTransport($host, $port, $encryption, $user, $pass, localDomain: $local);
                self::fail('expected MailException for ' . $message);
            } catch (MailException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
