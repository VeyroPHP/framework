<?php

declare(strict_types=1);

namespace VeyroPHP\Orm\Factory;

use Closure;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Support\Fake;

/**
 * Builds models with realistic data, for tests and seeders.
 *
 *     final class UserFactory extends Factory
 *     {
 *         public function model(): string { return User::class; }
 *
 *         public function definition(): array
 *         {
 *             return ['name' => $this->fake->name(), 'email' => $this->fake->email(), 'password' => 'x'];
 *         }
 *     }
 *
 *     $factory = $container->make(UserFactory::class);   // Orm and Fake are injected
 *     $user = $factory->createOne();
 *     $users = $factory->count(10)->state(['active' => true])->create();
 *     $draft = $factory->makeOne(['name' => 'Asha']);   // not saved
 *
 * Factories are immutable: count(), state() and the others return a copy.
 * Attributes are set without the FILLABLE check (they come from your code).
 *
 * @template TModel of Model
 */
abstract class Factory
{
    private int $count = 1;

    /** @var list<array<string, mixed>|Closure(array<string, mixed>, Fake): array<string, mixed>> */
    private array $states = [];

    /** @var list<array<string, mixed>> */
    private array $sequence = [];

    /** @var list<Closure(TModel): void> */
    private array $afterMaking = [];

    /** @var list<Closure(TModel): void> */
    private array $afterCreating = [];

    final public function __construct(
        protected readonly Orm $orm,
        protected readonly Fake $fake,
    ) {}

    /**
     * @return class-string<TModel>
     */
    abstract public function model(): string;

    /**
     * Default attributes for one model; use $this->fake for varied data.
     *
     * @return array<string, mixed>
     */
    abstract public function definition(): array;

    public function count(int $count): static
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('count() needs 0 or more.');
        }

        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    /**
     * Attributes that replace the definition's; a closure receives the attributes so far.
     *
     * @param array<string, mixed>|Closure(array<string, mixed>, Fake): array<string, mixed> $state
     */
    public function state(array|Closure $state): static
    {
        $clone = clone $this;
        $clone->states[] = $state;

        return $clone;
    }

    /**
     * Cycles through these attribute sets, one per model: sequence(['role' => 'a'], ['role' => 'b']).
     *
     * @param array<string, mixed> ...$states
     */
    public function sequence(array ...$states): static
    {
        $clone = clone $this;
        $clone->sequence = array_values($states);

        return $clone;
    }

    /**
     * @param Closure(TModel): void $callback
     */
    public function afterMaking(Closure $callback): static
    {
        $clone = clone $this;
        $clone->afterMaking[] = $callback;

        return $clone;
    }

    /**
     * @param Closure(TModel): void $callback runs after the model is saved
     */
    public function afterCreating(Closure $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback;

        return $clone;
    }

    /**
     * Unsaved models.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<TModel>
     */
    public function make(array $attributes = []): array
    {
        $models = [];

        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->build($i, $attributes);
        }

        return $models;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function makeOne(array $attributes = []): Model
    {
        return $this->build(0, $attributes);
    }

    /**
     * Saved models, all in one transaction.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<TModel>
     */
    public function create(array $attributes = []): array
    {
        return $this->orm->transaction(function () use ($attributes): array {
            $models = $this->make($attributes);

            foreach ($models as $model) {
                $this->orm->save($model);

                foreach ($this->afterCreating as $callback) {
                    $callback($model);
                }
            }

            return $models;
        });
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    public function createOne(array $attributes = []): Model
    {
        return $this->count(1)->create($attributes)[0];
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TModel
     */
    private function build(int $index, array $attributes): Model
    {
        $values = $this->definition();

        foreach ($this->states as $state) {
            $values = array_replace($values, $state instanceof Closure ? $state($values, $this->fake) : $state);
        }

        if ($this->sequence !== []) {
            $values = array_replace($values, $this->sequence[$index % count($this->sequence)]);
        }

        $class = $this->model();
        $model = new $class();
        $model->forceFill(array_replace($values, $attributes));

        foreach ($this->afterMaking as $callback) {
            $callback($model);
        }

        return $model;
    }
}
