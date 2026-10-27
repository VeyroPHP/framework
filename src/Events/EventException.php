<?php

declare(strict_types=1);

namespace VeyroPHP\Events;

use LogicException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A listener that cannot be registered or created (configuration error).
 */
final class EventException extends LogicException implements VeyroPHPException {}
