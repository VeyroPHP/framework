<?php

declare(strict_types=1);

namespace VeyroPHP\Queue;

use BackedEnum;
use Closure;
use JsonException;
use ReflectionClass;
use ReflectionException;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;

/**
 * Turns a job (or a queued mail or event) into JSON-safe data and back,
 * through its constructor. Never uses serialize(): restoring a payload can
 * only call the constructor of the class it names, with JSON values, enums
 * or models loaded through the ORM.
 *
 * Each constructor parameter needs a property of the same name holding the
 * value (promoted parameters do). Models are stored as class and key and
 * loaded again when unpacked.
 */
final class Payload
{
    /**
     * @param Closure(): Orm|null $orm resolves the ORM when a payload contains models
     */
    public function __construct(private readonly ?Closure $orm = null) {}

    /**
     * @return array{class: class-string, data: array<string, array<string, mixed>>}
     */
    public function pack(object $object): array
    {
        $class = new ReflectionClass($object);
        $data = [];

        if ($class->isAnonymous()) {
            throw new QueueException('Anonymous classes cannot be queued: the worker could not create them again. Use a named class.');
        }

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();

            if ($parameter->isVariadic()) {
                throw new QueueException(sprintf('%s cannot be queued: variadic constructor parameter $%s.', $class->getName(), $name));
            }

            if (!$class->hasProperty($name)) {
                throw new QueueException(sprintf('%s cannot be queued: constructor parameter $%s needs a property of the same name (use a promoted parameter).', $class->getName(), $name));
            }

            $property = $class->getProperty($name);

            if (!$property->isInitialized($object)) {
                throw new QueueException(sprintf('%s cannot be queued: property $%s is not set.', $class->getName(), $name));
            }

            $data[$name] = $this->encode($property->getValue($object), $class->getName() . '::$' . $name);
        }

        try {
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new QueueException(sprintf('%s cannot be queued: %s.', $class->getName(), $e->getMessage()), 0, $e);
        }

        return ['class' => $class->getName(), 'data' => $data];
    }

    /**
     * @param array<mixed> $packed from pack(), usually after a JSON round trip
     * @param class-string|null $expected the class or interface the result must have
     */
    public function unpack(array $packed, ?string $expected = null): object
    {
        $class = $packed['class'] ?? null;
        $data = $packed['data'] ?? null;

        if (!is_string($class) || !is_array($data)) {
            throw new UnrunnableJobException('Invalid queue payload.');
        }

        if (!class_exists($class)) {
            throw new UnrunnableJobException(sprintf('The queued class %s does not exist (renamed or deleted since it was queued?).', $class));
        }

        if ($expected !== null && !is_a($class, $expected, true)) {
            throw new UnrunnableJobException(sprintf('The queued class %s is not a %s.', $class, $expected));
        }

        $arguments = [];

        foreach ($data as $name => $value) {
            if (!is_string($name) || !is_array($value)) {
                throw new UnrunnableJobException(sprintf('Invalid queue payload for %s.', $class));
            }

            $arguments[$name] = $this->decode($value, $class . '::$' . $name);
        }

        try {
            return (new ReflectionClass($class))->newInstanceArgs($arguments);
        } catch (ReflectionException | \Error $e) {
            throw new UnrunnableJobException(sprintf('%s could not be created from its queued data (constructor changed since it was queued?): %s', $class, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(mixed $value, string $where): array
    {
        if ($value instanceof Model) {
            $key = $value->getKey();

            if ($key === null || !$value->exists()) {
                throw new QueueException(sprintf('%s: only saved models can be queued.', $where));
            }

            return ['t' => 'model', 'c' => $value::class, 'k' => $key];
        }

        if ($value instanceof BackedEnum) {
            return ['t' => 'enum', 'c' => $value::class, 'v' => $value->value];
        }

        self::assertJson($value, $where);

        return ['t' => 'json', 'v' => $value];
    }

    /**
     * @param array<mixed> $value
     */
    private function decode(array $value, string $where): mixed
    {
        $type = $value['t'] ?? null;
        $class = $value['c'] ?? null;

        if ($type === 'json') {
            return $value['v'] ?? null;
        }

        if ($type === 'enum' && is_string($class) && is_subclass_of($class, BackedEnum::class)) {
            $raw = $value['v'] ?? null;
            $case = is_int($raw) || is_string($raw) ? $class::tryFrom($raw) : null;

            return $case ?? throw new UnrunnableJobException(sprintf('%s: enum case %s no longer exists.', $where, var_export($raw, true)));
        }

        if ($type === 'model' && is_string($class) && is_subclass_of($class, Model::class)) {
            $key = $value['k'] ?? null;

            if ((!is_int($key) && !is_string($key)) || $this->orm === null) {
                throw new UnrunnableJobException(sprintf('%s: a model cannot be loaded without the ORM.', $where));
            }

            return ($this->orm)()->find($class, $key)
                ?? throw new ModelMissingException(sprintf('%s: %s with key %s no longer exists.', $where, $class, (string) $key));
        }

        throw new UnrunnableJobException(sprintf('Invalid queued value for %s.', $where));
    }

    private static function assertJson(mixed $value, string $where): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return;
            }

            throw new QueueException(sprintf('%s: INF and NAN cannot be queued.', $where));
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJson($item, $where);
            }

            return;
        }

        $type = is_object($value) ? $value::class : get_debug_type($value);

        throw new QueueException(sprintf('%s: a %s cannot be queued. Pass JSON values, enums or saved models (for example an id instead of an object).', $where, $type));
    }
}
