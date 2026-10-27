<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Restores a job from its queued payload and calls its handle() method,
 * injecting handle()'s parameters from the container.
 *
 * @internal used by Worker and the sync driver
 */
final class JobRunner
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Payload $payload,
    ) {}

    /**
     * @return array{uuid: string, job: array<mixed>, tries: int, backoff: list<int>, timeout: int}
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data) || !is_string($data['uuid'] ?? null) || !is_array($data['job'] ?? null)
            || !is_int($data['tries'] ?? null) || !is_array($data['backoff'] ?? null) || !is_int($data['timeout'] ?? null)) {
            throw new UnrunnableJobException('Invalid queued job payload.');
        }

        $backoff = array_values(array_filter($data['backoff'], 'is_int'));

        return ['uuid' => $data['uuid'], 'job' => $data['job'], 'tries' => max(1, $data['tries']), 'backoff' => $backoff, 'timeout' => max(0, $data['timeout'])];
    }

    /**
     * @param array<mixed> $job the "job" part of a decoded payload
     */
    public function instantiate(array $job): Job
    {
        $instance = $this->payload->unpack($job, Job::class);
        \assert($instance instanceof Job);

        return $instance;
    }

    public function handle(Job $job): void
    {
        if (!method_exists($job, 'handle')) {
            throw new UnrunnableJobException(sprintf('%s needs a handle() method.', $job::class));
        }

        $method = new ReflectionMethod($job, 'handle');

        if (!$method->isPublic()) {
            throw new UnrunnableJobException(sprintf('%s::handle() must be public.', $job::class));
        }

        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->container->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new UnrunnableJobException(sprintf('%s::handle() parameter $%s cannot be injected: only class and interface types can.', $job::class, $parameter->getName()));
            }
        }

        $method->invokeArgs($job, $arguments);
    }
}
