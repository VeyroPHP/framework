<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * An invalid locale or group name, or a broken language file.
 */
final class TranslationException extends RuntimeException implements VeyroPHPException {}
