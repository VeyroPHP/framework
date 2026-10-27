<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Mail\Events\MessageSent;
use VeyroPHP\Mail\Transport\Transport;
use VeyroPHP\Queue\Queue;
use VeyroPHP\View\View;

/**
 * Sends messages through the configured mailers.
 *
 *     $mailer->send(new WelcomeMail($user));
 *     $mailer->send((new Message())->to($email)->subject('Hi')->text('...'));
 *     $mailer->send($message, 'smtp');          // a specific mailer
 *
 * - Views are rendered here; with only HTML, a plain-text part is added.
 * - The sender defaults to config mail.from.
 * - Each send is logged (mailer, Message-ID, number of recipients, time),
 *   never addresses or content; MessageSent is dispatched when events exist.
 * - Once the transport accepted a message, send() succeeds: a failing log or
 *   MessageSent listener is logged, not thrown, so retries do not resend it.
 * - send() delivers immediately; queue() hands a Mailable to a queue worker.
 */
final class Mailer
{
    /** @var array<string, Transport> */
    private array $resolved = [];

    private readonly MimeBuilder $builder;

    /**
     * @param Closure(string): Transport $transports creates the transport for a mailer name
     * @param (Closure(): Queue)|null $queue resolves the queue for queue()
     */
    public function __construct(
        private readonly Closure $transports,
        private readonly string $default,
        private readonly ?Address $from = null,
        private readonly ?View $view = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?Closure $queue = null,
    ) {
        $this->builder = new MimeBuilder();
    }

    /**
     * @throws MailException for invalid messages or configuration
     * @throws TransportException when the server rejects or cannot be reached
     */
    public function send(Message|Mailable $mail, ?string $mailer = null): SentMessage
    {
        if ($mail instanceof Mailable) {
            $mailer ??= $mail->mailer();
            $message = new Message();
            $mail->build($message);
        } else {
            $message = clone $mail;
        }

        $mailer ??= $this->default;
        $sent = $this->prepare($message, $mailer);
        $start = hrtime(true);

        try {
            $this->transport($mailer)->send($sent);
        } catch (MailException $e) {
            $this->logger->warning('Mail failed', ['mailer' => $mailer, 'message_id' => $sent->messageId, 'error' => $e->getMessage()]);

            throw $e;
        }

        // The transport accepted the message. From here on nothing may make send() fail:
        // a caller or a queue retry would deliver it again (VQ-044).
        try {
            $this->logger->info('Mail sent', [
                'mailer' => $mailer,
                'message_id' => $sent->messageId,
                'recipients' => count($sent->recipients),
                'ms' => round((hrtime(true) - $start) / 1e6, 1),
            ]);
        } catch (\Throwable) {
            // A logging failure does not undo the delivery.
        }

        try {
            $this->events?->dispatch(new MessageSent($sent));
        } catch (\Throwable $e) {
            try {
                $this->logger->error('A MessageSent listener failed after the mail was delivered', ['mailer' => $mailer, 'message_id' => $sent->messageId, 'error' => $e::class . ': ' . $e->getMessage()]);
            } catch (\Throwable) {
                // Nothing else can report it.
            }
        }

        return $sent;
    }

    /**
     * Sends the Mailable from a queue worker instead of now; returns the job id.
     *
     * The Mailable is stored through its constructor (see VeyroPHP\Queue\Job for the rules),
     * so it is checked here and fails early if it cannot be queued.
     */
    public function queue(Mailable $mail, ?string $queue = null, ?string $mailer = null): string
    {
        if ($this->queue === null) {
            throw new MailException('Queueing mail needs the QueueServiceProvider.');
        }

        $queues = ($this->queue)();

        return $queues->push(new SendQueuedMailable($queues->payload()->pack($mail), $mailer ?? $mail->mailer()), $queue);
    }

    /**
     * The transport of a mailer, for example the "array" transport in tests.
     */
    public function transport(?string $mailer = null): Transport
    {
        $mailer ??= $this->default;

        return $this->resolved[$mailer] ??= ($this->transports)($mailer);
    }

    private function prepare(Message $message, string $mailer): SentMessage
    {
        $from = $message->getFrom() ?? $this->from
            ?? throw new MailException('The message has no sender: call from() or set mail.from in config/mail.php.');

        if ($message->recipients() === []) {
            throw new MailException('The message has no recipients: call to(), cc() or bcc().');
        }

        if ($message->getView() !== null && $message->getHtml() === null) {
            $message->html($this->render($message->getView()));
        }

        if ($message->getTextView() !== null && $message->getText() === null) {
            $message->text($this->render($message->getTextView()));
        }

        $html = $message->getHtml();

        if ($html !== null && $message->getText() === null) {
            $message->text(MimeBuilder::htmlToText($html));
        }

        [$raw, $messageId] = $this->builder->build($message, $from);

        return new SentMessage($message, $raw, $messageId, $from->email, $message->recipients(), $mailer);
    }

    /**
     * @param array{string, array<string, mixed>} $view
     */
    private function render(array $view): string
    {
        if ($this->view === null) {
            throw new MailException('Mail views need the ViewServiceProvider.');
        }

        return $this->view->render($view[0], $view[1]);
    }
}
