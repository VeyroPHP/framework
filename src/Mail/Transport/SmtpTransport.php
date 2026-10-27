<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Transport;

use SensitiveParameter;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\TransportException;

/**
 * Sends mail to an SMTP server (your provider, Mailpit locally, ...).
 *
 * - encryption "tls": plain connection upgraded with STARTTLS (port 587); the
 *   message is not sent if the server does not offer STARTTLS.
 *   "ssl": TLS from the start (port 465). "none": no encryption, only for
 *   local test servers.
 * - Certificates are verified unless verify_peer is false (local testing only).
 * - Credentials are never sent over an unencrypted connection, except to
 *   localhost. Error messages never contain the password.
 * - AUTH PLAIN or LOGIN, whichever the server offers (PLAIN preferred).
 * - One connection per message.
 */
final class SmtpTransport implements Transport
{
    private const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',
        private readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        private readonly float $timeout = 10.0,
        private readonly string $localDomain = 'localhost',
        private readonly bool $verifyPeer = true,
        private readonly ?string $caFile = null,
    ) {
        if ($host === '' || preg_match('/[\s\/]/', $host) === 1) {
            throw new MailException('SMTP host must be a host name or IP address.');
        }

        if ($port < 1 || $port > 65535) {
            throw new MailException('SMTP port must be between 1 and 65535.');
        }

        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new MailException(sprintf('SMTP encryption must be one of %s, "%s" given.', implode(', ', self::ENCRYPTIONS), $encryption));
        }

        if (preg_match('/^[A-Za-z0-9.-]+$|^\[[0-9a-fA-F.:]+\]$/D', $localDomain) !== 1) {
            throw new MailException('SMTP local_domain must be a host name.');
        }

        if (($username === null) !== ($password === null)) {
            throw new MailException('SMTP username and password must be set together.');
        }
    }

    public function send(SentMessage $message): void
    {
        if ($this->username !== null && $this->encryption === 'none' && !$this->isLocalHost()) {
            throw new MailException('Refusing to send SMTP credentials without encryption; set encryption to "tls" or "ssl".');
        }

        try {
            $this->connect();
            $this->expect(null, [220], 'connect');
            $extensions = $this->ehlo();

            if ($this->encryption === 'tls') {
                if (!isset($extensions['STARTTLS'])) {
                    throw new TransportException('The SMTP server does not offer STARTTLS; the message was not sent unencrypted.');
                }

                $this->expect('STARTTLS', [220]);
                $this->startTls();
                $extensions = $this->ehlo();
            }

            if ($this->username !== null) {
                $this->authenticate($extensions['AUTH'] ?? []);
            }

            $this->expect('MAIL FROM:<' . $message->sender . '>', [250]);

            foreach ($message->recipients as $recipient) {
                $this->expect('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->expect('DATA', [354]);
            $this->write((string) preg_replace('/^\./m', '..', $message->raw) . "\r\n.\r\n");
            $this->expect(null, [250], 'message data');
            $this->quit();
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }

            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $context = stream_context_create(['ssl' => array_filter([
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'cafile' => $this->caFile,
        ], static fn(mixed $v): bool => $v !== null)]);

        $errno = 0;
        $errstr = '';
        $scheme = $this->encryption === 'ssl' ? 'tls' : 'tcp';
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new TransportException(sprintf(
                'Could not connect to the SMTP server %s:%d: %s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'connection failed' . ($this->encryption === 'ssl' ? ' (TLS handshake or certificate problem?)' : ''),
            ));
        }

        stream_set_timeout($socket, (int) ceil($this->timeout));
        $this->socket = $socket;
    }

    private function startTls(): void
    {
        \assert(is_resource($this->socket));
        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);

        if ($ok !== true) {
            throw new TransportException(sprintf('TLS with the SMTP server %s failed; check that its certificate is valid for this host name.', $this->host));
        }
    }

    /**
     * @return array<string, list<string>> extension keyword => parameters
     */
    private function ehlo(): array
    {
        $lines = $this->expect('EHLO ' . $this->localDomain, [250]);
        $extensions = [];

        foreach (array_slice($lines, 1) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $keyword = strtoupper((string) array_shift($parts));

            if ($keyword !== '') {
                $extensions[$keyword] = array_map('strtoupper', $parts);
            }
        }

        return $extensions;
    }

    /**
     * @param list<string> $mechanisms
     */
    private function authenticate(array $mechanisms): void
    {
        $user = (string) $this->username;
        $pass = (string) $this->password;

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->expect('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], 'AUTH PLAIN');

            return;
        }

        if (in_array('LOGIN', $mechanisms, true)) {
            $this->expect('AUTH LOGIN', [334]);
            $this->expect(base64_encode($user), [334], 'AUTH LOGIN username');
            $this->expect(base64_encode($pass), [235], 'AUTH LOGIN password');

            return;
        }

        throw new TransportException(sprintf(
            'The SMTP server offers no supported login method (offered: %s; supported: PLAIN, LOGIN).',
            $mechanisms === [] ? 'none' : implode(', ', $mechanisms),
        ));
    }

    private function quit(): void
    {
        try {
            $this->expect('QUIT', [221]);
        } catch (TransportException) {
            // The message was accepted; a failed goodbye does not matter.
        }
    }

    /**
     * Sends $command (if any) and reads the reply.
     *
     * @param list<int> $codes accepted reply codes
     *
     * @return list<string> reply texts, one per line, without code
     */
    private function expect(?string $command, array $codes, ?string $label = null): array
    {
        $label ??= $command === null ? 'reply' : strtok($command, ' :');

        if ($command !== null) {
            $this->write($command . "\r\n");
        }

        [$code, $lines] = $this->readReply((string) $label);

        if (!in_array($code, $codes, true)) {
            $text = substr(implode(' ', $lines), 0, 300);
            $hint = $code === 535 ? ' (check the SMTP username and password)' : '';

            throw new TransportException(sprintf('The SMTP server answered %d to %s: %s%s', $code, $label, $text, $hint), $code);
        }

        return $lines;
    }

    /**
     * @return array{int, list<string>}
     */
    private function readReply(string $label): array
    {
        \assert(is_resource($this->socket));
        $lines = [];

        while (true) {
            $line = fgets($this->socket, 8192);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                throw new TransportException(sprintf(
                    $meta['timed_out'] ? 'The SMTP server did not answer %s within %s seconds.' : 'The SMTP server closed the connection during %s.',
                    $label,
                    $this->timeout,
                ));
            }

            if (preg_match('/^(\d{3})([ -])(.*)$/sD', rtrim($line, "\r\n"), $m) !== 1) {
                throw new TransportException(sprintf('Unexpected SMTP reply to %s.', $label));
            }

            $lines[] = $m[3];

            if ($m[2] === ' ') {
                return [(int) $m[1], $lines];
            }
        }
    }

    private function write(string $data): void
    {
        \assert(is_resource($this->socket));

        for ($written = 0, $length = strlen($data); $written < $length; $written += $bytes) {
            $bytes = @fwrite($this->socket, substr($data, $written, 65536));

            if ($bytes === false || $bytes === 0) {
                throw new TransportException('Could not write to the SMTP server; the connection was lost.');
            }
        }
    }

    private function isLocalHost(): bool
    {
        return in_array(strtolower($this->host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
