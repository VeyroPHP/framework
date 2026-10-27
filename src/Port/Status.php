<?php

declare(strict_types=1);

namespace VeyroPHP\Port;

/**
 * How a feature of the analyzed project maps to VeyroPHP.
 */
enum Status: string
{
    /** Same concept and API shape: mostly renaming. */
    case Direct = 'direct';

    /** VeyroPHP has the concept, but the code must be rewritten. */
    case Manual = 'manual';

    /** No equivalent in VeyroPHP yet: needs a different design or a package. */
    case Unsupported = 'unsupported';
}
