<?php

declare(strict_types=1);

namespace VeyroPHP\Package;

use RuntimeException;
use VeyroPHP\Exception\VeyroPHPException;

/**
 * A package that cannot be integrated: a missing or invalid provider, a bad
 * config file or publish path.
 */
final class PackageException extends RuntimeException implements VeyroPHPException {}
