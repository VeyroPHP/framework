<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

/**
 * One kind of email as a class (create with "php veyro make:mail").
 *
 *     final class WelcomeMail extends Mailable
 *     {
 *         public function __construct(private readonly string $email, private readonly string $name) {}
 *
 *         public function build(Message $message): void
 *         {
 *             $message->to($this->email, $this->name)
 *                 ->subject('Welcome')
 *                 ->view('mail.welcome', ['name' => $this->name]);
 *         }
 *     }
 *
 *     $mailer->send(new WelcomeMail($user->email, $user->name));
 */
abstract class Mailable
{
    /**
     * The mailer (config mail.mailers) to send with; null for the default.
     */
    public function mailer(): ?string
    {
        return null;
    }

    abstract public function build(Message $message): void;
}
