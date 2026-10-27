<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Access;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use VeyroPHP\Auth\Rbac\Rbac;
use VeyroPHP\Orm\Model;

/**
 * Authorization checks for the current user (scoped per request).
 *
 *     $gate->authorize('update', $post);           // 401 for guests, 403 otherwise
 *     if ($gate->allows('reports.export')) { ... }
 *     $gate->forUser($other)->allows('delete', $post);
 *
 * A check is decided by the first of these that answers:
 *
 * 1. before() hooks, in the order registered (true allows, false denies, null goes on);
 * 2. with RBAC registered, the super-admin role allows everything (as in Rbac::can());
 * 3. an ability from AccessRules::define();
 * 4. the policy of the first argument (a model or a model class name), method named after
 *    the ability; a policy without that method denies;
 * 5. with RBAC registered, the RBAC permission of that name;
 * 6. otherwise the check is denied.
 *
 * Guests are passed as null. A rule whose user parameter is not nullable is not called
 * for guests: they are denied. Every rule must return a bool.
 */
final class Gate
{
    /**
     * @param Closure(): ?Model $currentUser
     */
    public function __construct(
        private readonly AccessRules $rules,
        private readonly ContainerInterface $container,
        private readonly Closure $currentUser,
        private readonly ?Rbac $rbac = null,
    ) {}

    /**
     * The same rules for another user (null for a guest).
     */
    public function forUser(?Model $user): self
    {
        return new self($this->rules, $this->container, static fn(): ?Model => $user, $this->rbac);
    }

    public function user(): ?Model
    {
        return ($this->currentUser)();
    }

    /**
     * @throws AccessException when a rule is set up wrongly
     */
    public function allows(string $ability, mixed ...$arguments): bool
    {
        AccessRules::assertName($ability);
        $user = $this->user();
        $arguments = array_values($arguments);

        foreach ($this->rules->beforeHooks() as $hook) {
            $decision = $hook($user, $ability, $arguments);

            if ($decision !== null) {
                return $decision;
            }
        }

        if ($user !== null && $this->rbac !== null && $this->rbac->hasRole($user, $this->rbac->superAdminRole())) {
            return true;
        }

        $check = $this->rules->ability($ability);

        if ($check !== null) {
            return self::call($check, $user, $arguments, sprintf('ability "%s"', $ability));
        }

        $subject = $arguments[0] ?? null;
        $policy = $this->rules->policyFor($subject);

        if ($policy !== null) {
            return $this->callPolicy($policy, $ability, $user, $subject, $arguments);
        }

        return $user !== null && $this->rbac !== null && $this->rbac->can($user, $ability);
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /**
     * @throws AuthorizationException 401 for guests, 403 for logged-in users
     */
    public function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->allows($ability, ...$arguments)) {
            throw $this->user() === null ? AuthorizationException::forGuest() : AuthorizationException::forUser();
        }
    }

    /**
     * @param class-string $policy
     * @param list<mixed> $arguments
     */
    private function callPolicy(string $policy, string $ability, ?Model $user, mixed $subject, array $arguments): bool
    {
        $instance = $this->container->get($policy);
        $method = is_object($instance) && method_exists($instance, $ability) ? [$instance, $ability] : null;

        // Public methods only: is_callable() from here is false for private and protected ones.
        if ($method === null || !is_callable($method)) {
            return false;
        }

        // A class name only selects the policy ("create", Post::class); an instance is passed on.
        $passed = is_object($subject) ? $arguments : array_slice($arguments, 1);

        return self::call(Closure::fromCallable($method), $user, $passed, sprintf('%s::%s()', $policy, $ability));
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function call(Closure $rule, ?Model $user, array $arguments, string $name): bool
    {
        if ($user === null) {
            $first = (new ReflectionFunction($rule))->getParameters()[0] ?? null;

            if ($first !== null && !$first->allowsNull()) {
                return false;
            }
        }

        $result = $rule($user, ...$arguments);

        if (!is_bool($result)) {
            throw new AccessException(sprintf('The %s returned %s; authorization rules must return true or false.', $name, get_debug_type($result)));
        }

        return $result;
    }
}
