<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

/**
 * A unit of work that runs later in a worker ("php veyro queue:work").
 *
 *     final class SendInvoice implements Job
 *     {
 *         public int $tries = 5;                 // optional, default 3
 *         public array $backoff = [10, 60, 300]; // seconds before retry 1, 2, 3...; optional
 *         public int $timeout = 60;              // seconds, enforced where pcntl exists; optional;
 *                                                // must be below the connection's retry_after (90)
 *
 *         public function __construct(public readonly Order $order, public readonly bool $copyToAdmin = false) {}
 *
 *         public function handle(Mailer $mailer): void   // parameters are injected
 *         {
 *             // ...
 *         }
 *
 *         public function failed(\Throwable $e): void    // optional, after the last attempt
 *         {
 *         }
 *     }
 *
 *     $queue->push(new SendInvoice($order));
 *
 * Rules for the constructor (checked when the job is queued):
 * - every parameter is stored in a property of the same name (promoted parameters do this);
 * - values are JSON values (null, bool, int, float, string, arrays of them), backed
 *   enums, or ORM models. Models are stored as class and key and loaded again,
 *   fresh, when the job runs.
 *
 * Jobs must be safe to run more than once: a worker that crashes after the work
 * but before the job is deleted makes the job run again.
 */
interface Job {}
