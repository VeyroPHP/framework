<?php

declare(strict_types=1);

namespace VeyroPHP\Mail;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A message that cannot be built or sent: invalid address or header, missing
 * recipient, sender or body, unknown mailer, or a transport failure.
 *
 * Messages never contain passwords or the mail body.
 */
class MailException extends RuntimeException implements VeyroPHPException {}
