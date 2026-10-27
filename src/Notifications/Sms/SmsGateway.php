<?php

declare(strict_types=1);

namespace VeyroPHP\Notifications\Sms;

use VeyroPHP\Notifications\NotificationException;

/**
 * Delivers one SMS (MSG91, Twilio, log, array, or your own provider).
 */
interface SmsGateway
{
    /**
     * @param string $to the number in E.164 form, e.g. +919876543210
     *
     * @throws NotificationException when the message could not be sent
     */
    public function send(SmsMessage $message, string $to): void;
}
