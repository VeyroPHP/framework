<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Mail\Transport\BrevoTransport;
use VeyroPHP\Mail\Transport\LogTransport;
use VeyroPHP\Mail\Transport\MailgunTransport;
use VeyroPHP\Mail\Transport\SesTransport;
use VeyroPHP\Mail\Transport\SmtpTransport;
use VeyroPHP\Mail\Transport\Transport;
use VeyroPHP\Queue\Queue;
use VeyroPHP\Support\HttpClient;
use VeyroPHP\View\View;

/**
 * Registers Mailer (singleton). Transports are created on first use.
 *
 * Config (config/mail.php):
 *
 *     'default' => 'smtp',
 *     'from' => ['address' => 'hello@example.com', 'name' => 'Example'],
 *     'mailers' => [
 *         'smtp' => [
 *             'transport' => 'smtp',
 *             'host' => 'smtp.example.com',
 *             'port' => 587,
 *             'encryption' => 'tls',       // tls (STARTTLS), ssl, none
 *             'username' => '...',
 *             'password' => '...',
 *             'timeout' => 10,
 *             'local_domain' => null,      // EHLO name; default: this host's name
 *             'verify_peer' => true,       // false only for local test servers
 *         ],
 *         // HTTP APIs (need guzzlehttp/guzzle or a bound PSR-18 ClientInterface):
 *         'mailgun' => ['transport' => 'mailgun', 'domain' => 'mg.example.com', 'secret' => '...',
 *                       'endpoint' => 'https://api.mailgun.net'],          // EU: https://api.eu.mailgun.net
 *         'ses' => ['transport' => 'ses', 'key' => '...', 'secret' => '...', 'region' => 'ap-south-1',
 *                   'configuration_set' => ''],
 *         'brevo' => ['transport' => 'brevo', 'key' => '...'],
 *         'log' => ['transport' => 'log'],
 *         'array' => ['transport' => 'array'],
 *         'api' => ['transport' => App\Mail\MyApiTransport::class],   // implements Transport
 *     ],
 */
final class MailServiceProvider implements ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->singleton(Mailer::class, static function (Container $c): Mailer {
            $config = $c->make(Config::class);
            $logger = $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger();
            $default = $config->get('mail.default', 'log');
            $fromAddress = $config->get('mail.from.address');
            $fromName = $config->get('mail.from.name', '');

            return new Mailer(
                static fn(string $name): Transport => self::transport($name, $config, $c, $logger),
                is_string($default) && $default !== '' ? $default : 'log',
                is_string($fromAddress) && $fromAddress !== '' ? new Address($fromAddress, is_string($fromName) ? $fromName : '') : null,
                $c->bound(View::class) ? $c->make(View::class) : null,
                $logger,
                $c->bound(EventDispatcherInterface::class) ? $c->make(EventDispatcherInterface::class) : null,
                $c->bound(Queue::class) ? static fn(): Queue => $c->make(Queue::class) : null,
            );
        });
    }

    private static function transport(string $name, Config $config, Container $c, LoggerInterface $logger): Transport
    {
        $settings = $config->get('mail.mailers.' . $name);

        if (!is_array($settings)) {
            throw new MailException(sprintf('No mailer "%s" is configured in config/mail.php.', $name));
        }

        $transport = $settings['transport'] ?? null;

        return match (true) {
            $transport === 'smtp' => self::smtp($settings),
            $transport === 'log' => new LogTransport($logger),
            $transport === 'array' => new ArrayTransport(),
            $transport === 'mailgun' => new MailgunTransport(
                self::httpClient($c, $name),
                self::setting($settings, 'domain', $name),
                self::setting($settings, 'secret', $name),
                self::setting($settings, 'endpoint', $name, 'https://api.mailgun.net'),
            ),
            $transport === 'ses' => new SesTransport(
                self::httpClient($c, $name),
                self::setting($settings, 'key', $name),
                self::setting($settings, 'secret', $name),
                self::setting($settings, 'region', $name),
                self::setting($settings, 'configuration_set', $name, ''),
            ),
            $transport === 'brevo' => new BrevoTransport(
                self::httpClient($c, $name),
                self::setting($settings, 'key', $name),
                self::setting($settings, 'endpoint', $name, 'https://api.brevo.com'),
            ),
            is_string($transport) && is_subclass_of($transport, Transport::class) => $c->make($transport),
            default => throw new MailException(sprintf('Mailer "%s" has an unknown transport; use smtp, mailgun, ses, brevo, log, array or a class implementing %s.', $name, Transport::class)),
        };
    }

    /**
     * The HTTP client for API transports: a PSR-18 client bound as ClientInterface, or
     * Guzzle when it is installed.
     */
    private static function httpClient(Container $c, string $mailer): ClientInterface
    {
        return HttpClient::resolve($c, sprintf('Mailer "%s"', $mailer), static fn(string $message): MailException => new MailException($message));
    }

    /**
     * A string setting of an API mailer; required unless a default is given.
     *
     * @param array<mixed> $settings
     */
    private static function setting(array $settings, string $key, string $mailer, ?string $default = null): string
    {
        $value = $settings[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($value !== null && $value !== '' || $default === null) {
            throw new MailException(sprintf('Mailer "%s" needs "%s" (a string) in config/mail.php.', $mailer, $key));
        }

        return $default;
    }

    /**
     * @param array<mixed> $s
     */
    private static function smtp(array $s): SmtpTransport
    {
        $string = static fn(string $key, ?string $default = null): ?string => isset($s[$key]) && $s[$key] !== '' ? (is_scalar($s[$key]) ? (string) $s[$key] : throw new MailException("mail config {$key} must be a string.")) : $default;
        $username = $string('username');
        $hostname = gethostname();
        $localDomain = $string('local_domain') ?? (is_string($hostname) && preg_match('/^[A-Za-z0-9.-]+$/D', $hostname) === 1 ? $hostname : 'localhost');

        return new SmtpTransport(
            host: (string) $string('host', 'localhost'),
            port: (int) $string('port', '587'),
            encryption: (string) $string('encryption', 'tls'),
            username: $username,
            password: $username === null ? null : (string) $string('password', ''),
            timeout: (float) $string('timeout', '10'),
            localDomain: $localDomain,
            verifyPeer: ($s['verify_peer'] ?? true) !== false,
        );
    }
}
