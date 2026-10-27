<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Events\EventDispatcher;
use VeyroPHP\Events\EventServiceProvider;
use VeyroPHP\Mail\Address;
use VeyroPHP\Mail\Events\MessageSent;
use VeyroPHP\Mail\Mailable;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\MailServiceProvider;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\SentMessage;
use VeyroPHP\Mail\Transport\ArrayTransport;
use VeyroPHP\Mail\Transport\LogTransport;
use VeyroPHP\Mail\Transport\SmtpTransport;
use VeyroPHP\Mail\Transport\Transport;
use VeyroPHP\Mail\TransportException;
use VeyroPHP\View\View;
use VeyroPHP\View\ViewServiceProvider;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}

final class WelcomeMail extends Mailable
{
    public function __construct(private readonly string $email, private readonly string $name) {}

    public function build(Message $message): void
    {
        $message->to($this->email, $this->name)->subject('Welcome')->view('mail.welcome', ['name' => $this->name]);
    }
}

final class AuditMail extends Mailable
{
    public function mailer(): string
    {
        return 'audit';
    }

    public function build(Message $message): void
    {
        $message->to('audit@example.com')->subject('Audit')->text('x');
    }
}

final class FailingTransport implements Transport
{
    public function send(SentMessage $message): void
    {
        throw new TransportException('The SMTP server answered 451 to RCPT: try later', 451);
    }
}

final class CustomTransport implements Transport
{
    /** @var list<SentMessage> */
    public static array $sent = [];

    public function __construct(public readonly LoggerInterface $logger) {}

    public function send(SentMessage $message): void
    {
        self::$sent[] = $message;
    }
}

#[CoversClass(Mailer::class)]
#[CoversClass(Mailable::class)]
#[CoversClass(MailServiceProvider::class)]
#[CoversClass(ArrayTransport::class)]
#[CoversClass(LogTransport::class)]
#[CoversClass(SentMessage::class)]
#[CoversClass(MessageSent::class)]
#[CoversClass(TransportException::class)]
final class MailerTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-mail-views-' . bin2hex(random_bytes(4));
        mkdir($this->views . '/mail', 0777, true);
        file_put_contents($this->views . '/mail/welcome.php', '<h1>Welcome, <?= $e($name) ?></h1><p><a href="https://example.com/start">Get started</a></p>');
        file_put_contents($this->views . '/mail/welcome-text.php', 'Welcome, <?= $name ?> (text view)');
        CustomTransport::$sent = [];
    }

    protected function tearDown(): void
    {
        foreach (['mail/welcome.php', 'mail/welcome-text.php'] as $file) {
            unlink($this->views . '/' . $file);
        }

        rmdir($this->views . '/mail');
        rmdir($this->views);
    }

    /**
     * @param array<string, Transport> $transports
     */
    private function mailer(array $transports = [], ?MemoryLogger $logger = null, ?EventDispatcher $events = null, bool $withView = true): Mailer
    {
        $transports += ['array' => new ArrayTransport()];

        return new Mailer(
            static fn(string $name): Transport => $transports[$name] ?? throw new MailException('No mailer ' . $name),
            'array',
            new Address('shop@example.com', 'Shop'),
            $withView ? new View($this->views) : null,
            $logger ?? new MemoryLogger(),
            $events,
        );
    }

    private static function arrayTransport(Mailer $mailer, ?string $name = null): ArrayTransport
    {
        $transport = $mailer->transport($name);
        self::assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function testSendsMessageWithDefaultSender(): void
    {
        $mailer = $this->mailer();

        $sent = $mailer->send((new Message())->to('asha@example.com')->subject('Hi')->text('Hello'));

        self::assertSame('shop@example.com', $sent->sender);
        self::assertSame(['asha@example.com'], $sent->recipients);
        self::assertSame('array', $sent->mailer);
        self::assertStringContainsString('From: Shop <shop@example.com>', $sent->raw);
        self::assertSame([$sent], self::arrayTransport($mailer)->messages());
    }

    public function testExplicitSenderWins(): void
    {
        $sent = $this->mailer()->send((new Message())->from('billing@example.com')->to('asha@example.com')->subject('x')->text('y'));

        self::assertSame('billing@example.com', $sent->sender);
    }

    public function testMailableWithViewGetsHtmlAndGeneratedText(): void
    {
        $mailer = $this->mailer();

        $sent = $mailer->send(new WelcomeMail('asha@example.com', 'Asha <script>'));

        self::assertSame('<h1>Welcome, Asha &lt;script&gt;</h1><p><a href="https://example.com/start">Get started</a></p>', $sent->message->getHtml(), 'view output, escaped');
        self::assertSame("Welcome, Asha <script>\n\nGet started (https://example.com/start)", $sent->message->getText(), 'plain-text part generated from HTML');
        self::assertStringContainsString('multipart/alternative', $sent->raw);
        self::assertCount(1, self::arrayTransport($mailer)->sentTo('ASHA@example.com'));
        self::assertSame([], self::arrayTransport($mailer)->sentTo('other@example.com'));
    }

    public function testTextViewIsUsedInsteadOfGeneratedText(): void
    {
        $sent = $this->mailer()->send((new Message())->to('a@example.com')->subject('x')
            ->view('mail.welcome', ['name' => 'Asha'])
            ->textView('mail.welcome-text', ['name' => 'Asha']));

        self::assertSame('Welcome, Asha (text view)', $sent->message->getText());
    }

    public function testTheCallersMessageIsNotChanged(): void
    {
        $message = (new Message())->to('a@example.com')->subject('x')->view('mail.welcome', ['name' => 'Asha']);

        $this->mailer()->send($message);

        self::assertNull($message->getHtml());
        self::assertNull($message->getText());
    }

    public function testViewsNeedTheViewService(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('ViewServiceProvider');
        $this->mailer(withView: false)->send(new WelcomeMail('a@example.com', 'A'));
    }

    public function testMailableChoosesItsMailer(): void
    {
        $audit = new ArrayTransport();
        $mailer = $this->mailer(['audit' => $audit]);

        $mailer->send(new AuditMail());

        self::assertCount(1, $audit->messages());
        self::assertSame([], self::arrayTransport($mailer)->messages());

        $mailer->send(new AuditMail(), 'array');
        self::assertCount(1, self::arrayTransport($mailer)->messages(), 'an explicit mailer wins');
    }

    public function testMissingSenderRecipientsOrBody(): void
    {
        $noFrom = new Mailer(static fn(): ArrayTransport => new ArrayTransport(), 'array');

        foreach ([
            [$noFrom, (new Message())->to('a@example.com')->text('x'), 'no sender'],
            [$this->mailer(), (new Message())->subject('x')->text('x'), 'no recipients'],
            [$this->mailer(), (new Message())->to('a@example.com'), 'no body'],
        ] as [$mailer, $message, $error]) {
            try {
                $mailer->send($message);
                self::fail('expected: ' . $error);
            } catch (MailException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
    }

    public function testLogsSendsWithoutAddressesOrContent(): void
    {
        $logger = new MemoryLogger();
        $mailer = $this->mailer(['broken' => new FailingTransport()], $logger);

        $mailer->send((new Message())->to('asha@example.com')->bcc('b@example.com')->subject('Secret subject')->text('Secret body'));

        try {
            $mailer->send((new Message())->to('asha@example.com')->subject('Secret subject')->text('Secret body'), 'broken');
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertTrue($e->isRetryable());
        }

        self::assertSame(['info', 'Mail sent'], [$logger->records[0][0], $logger->records[0][1]]);
        self::assertSame(2, $logger->records[0][2]['recipients']);
        self::assertSame(['warning', 'Mail failed'], [$logger->records[1][0], $logger->records[1][1]]);

        $logged = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('asha@example.com', $logged);
        self::assertStringNotContainsString('Secret', $logged);
    }

    /**
     * VQ-044: once the transport accepted the message, send() reports success. A failing
     * MessageSent listener or log is reported, not thrown, so callers and queue retries
     * do not send the message again.
     */
    public function testFailuresAfterDeliveryAreReportedNotThrown(): void
    {
        $events = new EventDispatcher();
        $events->listen(MessageSent::class, static function (): void {
            throw new \RuntimeException('listener down');
        });
        $logger = new MemoryLogger();
        $mailer = $this->mailer(logger: $logger, events: $events);

        $sent = $mailer->send((new Message())->to('a@example.com')->subject('x')->text('y'));

        self::assertCount(1, self::arrayTransport($mailer)->messages());
        self::assertSame($sent, self::arrayTransport($mailer)->last());
        $errors = array_values(array_filter($logger->records, static fn(array $r): bool => $r[0] === 'error'));
        self::assertCount(1, $errors);
        self::assertStringContainsString('after the mail was delivered', $errors[0][1]);

        $brokenLog = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('disk full');
            }
        };
        $quiet = new Mailer(static fn(): ArrayTransport => new ArrayTransport(), 'array', new Address('shop@example.com'), null, $brokenLog);
        self::assertSame('x', $quiet->send((new Message())->to('a@example.com')->subject('x')->text('y'))->message->getSubject());
    }

    public function testMessageSentEvent(): void
    {
        $events = new EventDispatcher();
        $seen = [];
        $events->listen(MessageSent::class, static function (MessageSent $e) use (&$seen): void {
            $seen[] = $e->sent->messageId;
        });
        $mailer = $this->mailer(['broken' => new FailingTransport()], events: $events);

        $sent = $mailer->send((new Message())->to('a@example.com')->subject('x')->text('y'));

        try {
            $mailer->send((new Message())->to('a@example.com')->subject('x')->text('y'), 'broken');
        } catch (TransportException) {
        }

        self::assertSame([$sent->messageId], $seen, 'only successful sends');
    }

    public function testLogTransportWritesReadableTextBody(): void
    {
        $logger = new MemoryLogger();
        $mailer = $this->mailer(['log' => new LogTransport($logger)], $logger);

        $mailer->send((new Message())->to('asha@example.com')->subject('Reset your password')
            ->html('<p><a href="https://example.com/reset?token=abc&amp;email=a">Reset</a></p>'), 'log');

        self::assertSame('debug', $logger->records[0][0]);
        self::assertSame('asha@example.com', $logger->records[0][2]['to']);
        self::assertSame('Reset your password', $logger->records[0][2]['subject']);
        self::assertSame('Reset (https://example.com/reset?token=abc&email=a)', $logger->records[0][2]['body'], 'the link can be copied as is');
    }

    // Service provider

    /**
     * @param array<string, mixed> $mail
     */
    private function app(array $mail): Application
    {
        $app = new Application($this->views);
        $app->container()->instance(Config::class, new Config(['mail' => $mail, 'view' => ['path' => '.']]));
        $app->container()->instance(LoggerInterface::class, new MemoryLogger());
        $app->register(new ViewServiceProvider());
        $app->register(new EventServiceProvider());
        $app->register(new MailServiceProvider());
        $app->boot();

        return $app;
    }

    public function testServiceProviderBuildsConfiguredMailers(): void
    {
        $app = $this->app([
            'default' => 'array',
            'from' => ['address' => 'shop@example.com', 'name' => 'Shop'],
            'mailers' => [
                'array' => ['transport' => 'array'],
                'log' => ['transport' => 'log'],
                'smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p'],
                'custom' => ['transport' => CustomTransport::class],
            ],
        ]);
        $mailer = $app->container()->make(Mailer::class);

        self::assertSame($mailer, $app->container()->make(Mailer::class));
        self::assertInstanceOf(ArrayTransport::class, $mailer->transport());
        self::assertSame($mailer->transport(), $mailer->transport('array'), 'one transport instance per mailer');
        self::assertInstanceOf(LogTransport::class, $mailer->transport('log'));
        self::assertInstanceOf(SmtpTransport::class, $mailer->transport('smtp'));

        $sent = $mailer->send(new WelcomeMail('a@example.com', 'Asha'));
        self::assertStringContainsString('Welcome, Asha', (string) $sent->message->getHtml(), 'views come from the ViewServiceProvider');
        self::assertSame('shop@example.com', $sent->sender);

        $mailer->send((new Message())->to('a@example.com')->text('x'), 'custom');
        self::assertCount(1, CustomTransport::$sent, 'custom transports are built by the container');
    }

    public function testServiceProviderErrors(): void
    {
        $mailer = $this->app(['mailers' => ['odd' => ['transport' => 'carrier-pigeon'], 'smtp' => ['transport' => 'smtp', 'encryption' => 'starttls']]])->container()->make(Mailer::class);

        foreach ([['missing', 'No mailer "missing"'], ['odd', 'unknown transport'], ['smtp', 'encryption must be one of']] as [$name, $error]) {
            try {
                $mailer->transport($name);
                self::fail('expected MailException for ' . $name);
            } catch (MailException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
    }

    public function testDefaultsWithoutConfigUseTheLogMailer(): void
    {
        $app = new Application($this->views);
        $app->container()->instance(Config::class, new Config([]));
        $app->register(new MailServiceProvider());
        $app->boot();

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('No mailer "log"');
        $app->container()->make(Mailer::class)->transport();
    }
}
