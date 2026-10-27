<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Access;

use Closure;
use VeyroPHP\Orm\Model;

/**
 * The application's authorization rules, registered once at boot (singleton).
 * Checks go through Gate, which knows the current user.
 *
 *     $rules->define('reports.export', fn (?User $user) => $user?->get('is_staff') === true);
 *     $rules->policy(Post::class, PostPolicy::class);       // update(User $user, Post $post): bool
 *     $rules->before(fn (?Model $user, string $ability) => $user?->get('banned') ? false : null);
 *
 * Abilities defined here take precedence over a policy method of the same name.
 */
final class AccessRules
{
    private const ABILITY = '/^[A-Za-z][A-Za-z0-9._-]*$/D';

    /** @var array<string, Closure> */
    private array $abilities = [];

    /** @var array<class-string<Model>, class-string> */
    private array $policies = [];

    /** @var list<Closure(?Model, string, list<mixed>): ?bool> */
    private array $before = [];

    /**
     * @param Closure $check receives the user (null for guests) and the arguments of the check; returns bool
     *
     * @throws AccessException for an invalid or already defined name
     */
    public function define(string $ability, Closure $check): self
    {
        self::assertName($ability);

        if (isset($this->abilities[$ability])) {
            throw new AccessException(sprintf('The ability "%s" is already defined.', $ability));
        }

        $this->abilities[$ability] = $check;

        return $this;
    }

    /**
     * Checks on $model (an instance or the class name) call the public method of $policy
     * named after the ability, e.g. update(User $user, Post $post).
     *
     * Both names are checked here, so a typo fails at registration and not at the first check.
     *
     * @throws AccessException when the classes do not exist or the model already has a policy
     */
    public function policy(string $model, string $policy): self
    {
        if (!is_subclass_of($model, Model::class)) {
            throw new AccessException(sprintf('Policies are for models; %s is not a VeyroPHP\Orm\Model.', $model));
        }

        if (!class_exists($policy)) {
            throw new AccessException(sprintf('Policy class %s does not exist.', $policy));
        }

        if (isset($this->policies[$model])) {
            throw new AccessException(sprintf('%s already has the policy %s.', $model, $this->policies[$model]));
        }

        $this->policies[$model] = $policy;

        return $this;
    }

    /**
     * Runs before every check: true allows, false denies, null goes on to the next rule.
     *
     * @param Closure(?Model, string, list<mixed>): ?bool $hook
     */
    public function before(Closure $hook): self
    {
        $this->before[] = $hook;

        return $this;
    }

    public function ability(string $name): ?Closure
    {
        return $this->abilities[$name] ?? null;
    }

    /**
     * The policy for a model instance or class name, also found through parent classes.
     *
     * @return class-string|null
     */
    public function policyFor(mixed $subject): ?string
    {
        $class = is_object($subject) ? $subject::class : (is_string($subject) && class_exists($subject) ? $subject : null);

        while (is_string($class)) {
            if (isset($this->policies[$class])) {
                return $this->policies[$class];
            }

            $class = get_parent_class($class);
        }

        return null;
    }

    /**
     * @return list<Closure(?Model, string, list<mixed>): ?bool>
     */
    public function beforeHooks(): array
    {
        return $this->before;
    }

    /**
     * @throws AccessException
     */
    public static function assertName(string $ability): void
    {
        if (preg_match(self::ABILITY, $ability) !== 1) {
            throw new AccessException(sprintf('"%s" is not a valid ability name; use letters, digits, ".", "_" and "-".', $ability));
        }
    }
}
