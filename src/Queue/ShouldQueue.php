<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

/**
 * Marks an event listener that runs in a queue worker instead of during the
 * request. The event's constructor must follow the same rules as a Job's.
 *
 *     final class SendWelcomeMail implements ShouldQueue
 *     {
 *         public function handle(UserRegistered $event): void { ... }
 *     }
 */
interface ShouldQueue {}
