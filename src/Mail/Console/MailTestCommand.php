<?php

declare(strict_types=1);

namespace VeyroPHP\Mail\Console;

use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Mail\Mailer;
use VeyroPHP\Mail\MailException;
use VeyroPHP\Mail\Message;
use VeyroPHP\Mail\TransportException;

#[AsCommand('mail:test', 'Send a test email to check the mail settings')]
final class MailTestCommand extends Command
{
    public function __construct(private readonly Mailer $mailer) {}

    public function definition(): array
    {
        return [
            Argument::required('to', 'Recipient address'),
            Option::value('mailer', 'Mailer from config/mail.php (default: mail.default)'),
        ];
    }

    public function execute(Input $input, Output $output): int
    {
        $mailer = $input->option('mailer');

        try {
            $sent = $this->mailer->send(
                (new Message())
                    ->to((string) $input->argument('to'))
                    ->subject('VeyroPHP test mail')
                    ->text("This is a test message from \"php veyro mail:test\".\n\nIf you can read it, sending mail works."),
                $mailer === '' ? null : $mailer,
            );
        } catch (TransportException $e) {
            $output->error($e->getMessage());

            return self::FAILURE;
        } catch (MailException $e) {
            $output->error($e->getMessage());

            return self::USAGE;
        }

        $output->success(sprintf('Sent with mailer "%s" (Message-ID %s).', $sent->mailer, $sent->messageId));

        if ($sent->mailer === 'log' || $sent->mailer === 'array') {
            $output->line('Note: this mailer does not deliver mail; set MAIL_MAILER=smtp to send for real.');
        }

        return self::SUCCESS;
    }
}
