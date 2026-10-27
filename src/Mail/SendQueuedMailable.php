<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

use VeyroPHP\Queue\Job;
use VeyroPHP\Queue\Payload;

/**
 * Sends a Mailable from a queue worker; created by Mailer::queue(). The
 * Mailable is stored with the same rules as a job (see VeyroPHP\Queue\Job).
 *
 * @internal
 */
final class SendQueuedMailable implements Job
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * @param array<mixed> $mailable packed Mailable (Payload::pack)
     */
    public function __construct(
        public readonly array $mailable,
        public readonly ?string $mailer = null,
    ) {}

    public function handle(Mailer $mailer, Payload $payload): void
    {
        $mailable = $payload->unpack($this->mailable, Mailable::class);
        \assert($mailable instanceof Mailable);

        $mailer->send($mailable, $this->mailer);
    }
}
