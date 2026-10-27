<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VeyroPHP\Auth\Access\AccessException;
use VeyroPHP\Auth\Access\AccessRules;
use VeyroPHP\Auth\Access\AuthorizationException;
use VeyroPHP\Auth\Access\Gate;
use VeyroPHP\Container\Container;
use VeyroPHP\Orm\Model;
use VeyroPHP\Tests\Fixtures\Auth\AccessPost;
use VeyroPHP\Tests\Fixtures\Auth\AccessPostPolicy;
use VeyroPHP\Tests\Fixtures\Auth\AuthUser;
use VeyroPHP\Tests\Fixtures\Auth\FeaturedAccessPost;

/**
 * Abilities, policies and before hooks without RBAC (RBAC: AuthorizationTest).
 */
#[CoversClass(Gate::class)]
#[CoversClass(AccessRules::class)]
#[CoversClass(AuthorizationException::class)]
final class GateTest extends TestCase
{
    private AccessRules $rules;

    private Container $container;

    private AccessPostPolicy $policy;

    protected function setUp(): void
    {
        $this->rules = new AccessRules();
        $this->container = new Container();
        $this->policy = new AccessPostPolicy();
        $this->container->instance(AccessPostPolicy::class, $this->policy);
        $this->rules->policy(AccessPost::class, AccessPostPolicy::class);
    }

    private function gate(?Model $user): Gate
    {
        return new Gate($this->rules, $this->container, static fn(): ?Model => $user);
    }

    private static function user(int $id, string $email = 'asha@example.test'): AuthUser
    {
        return AuthUser::hydrate(['id' => $id, 'email' => $email]);
    }

    private static function post(int $owner, bool $published = false): AccessPost
    {
        return AccessPost::hydrate(['id' => 10, 'user_id' => $owner, 'published' => $published]);
    }

    public function testPoliciesDecideByTheMethodNamedAfterTheAbility(): void
    {
        $gate = $this->gate(self::user(1));

        self::assertTrue($gate->allows('update', self::post(1)));
        self::assertFalse($gate->allows('update', self::post(2)));
        self::assertTrue($gate->denies('update', self::post(2)));
        self::assertTrue($gate->allows('share', self::post(1), 'email'), 'extra arguments are passed on');
        self::assertFalse($gate->allows('share', self::post(1), 'sms'));
    }

    public function testAClassNameSelectsThePolicyWithoutBeingPassed(): void
    {
        self::assertTrue($this->gate(self::user(1))->allows('create', AccessPost::class));
        self::assertFalse($this->gate(self::user(2, 'readonly@example.test'))->allows('create', AccessPost::class));
    }

    public function testASubclassUsesItsParentsPolicy(): void
    {
        $featured = FeaturedAccessPost::hydrate(['id' => 3, 'user_id' => 1]);

        self::assertTrue($this->gate(self::user(1))->allows('update', $featured));
    }

    public function testGuestsAreDeniedWithoutCallingRulesThatNeedAUser(): void
    {
        $guest = $this->gate(null);

        self::assertFalse($guest->allows('update', self::post(1)));
        self::assertFalse($guest->allows('create', AccessPost::class));
        self::assertSame([], $this->policy->calls, 'a non-nullable user parameter is never called with null');

        self::assertTrue($guest->allows('view', self::post(1, published: true)), 'a nullable user parameter is called');
        self::assertFalse($guest->allows('view', self::post(1)));
        self::assertSame(['view', 'view'], $this->policy->calls);
    }

    public function testMissingOrNonPublicPolicyMethodsDeny(): void
    {
        $gate = $this->gate(self::user(1));

        self::assertFalse($gate->allows('archive', self::post(1)), 'no such method');
        self::assertFalse($gate->allows('delete', self::post(1)), 'a protected method is not a rule');
    }

    public function testUnknownAbilitiesAreDenied(): void
    {
        self::assertFalse($this->gate(self::user(1))->allows('reports.export'));
        self::assertFalse($this->gate(self::user(1))->allows('update', new \stdClass()));
    }

    public function testDefinedAbilitiesComeBeforePolicies(): void
    {
        $this->rules->define('reports.export', static fn(?AuthUser $user): bool => $user?->getKey() === 1);
        $this->rules->define('update', static fn(AuthUser $user, AccessPost $post): bool => true);

        self::assertTrue($this->gate(self::user(1))->allows('reports.export'));
        self::assertFalse($this->gate(self::user(2))->allows('reports.export'));
        self::assertFalse($this->gate(null)->allows('reports.export'));
        self::assertTrue($this->gate(self::user(1))->allows('update', self::post(2)), 'the defined ability wins');
    }

    public function testBeforeHooksCanAllowDenyOrPass(): void
    {
        $this->rules->before(static fn(?Model $user, string $ability): ?bool => $user?->get('email') === 'banned@example.test' ? false : null);
        $this->rules->before(static fn(?Model $user, string $ability, array $arguments): ?bool => $ability === 'update' && ($arguments[0] ?? null) instanceof AccessPost && $arguments[0]->get('locked') === true ? false : null);
        $this->rules->before(static fn(?Model $user): ?bool => $user?->getKey() === 99 ? true : null);

        self::assertFalse($this->gate(self::user(1, 'banned@example.test'))->allows('update', self::post(1)));
        self::assertFalse($this->gate(self::user(1))->allows('update', AccessPost::hydrate(['user_id' => 1, 'locked' => true])));
        self::assertTrue($this->gate(self::user(99))->allows('update', self::post(1)));
        self::assertTrue($this->gate(self::user(1))->allows('update', self::post(1)), 'null goes on to the policy');
    }

    public function testAuthorizeThrows401ForGuestsAnd403ForUsers(): void
    {
        $this->gate(self::user(1))->authorize('update', self::post(1));

        foreach ([[null, 401, 'Unauthenticated.'], [self::user(2), 403, 'You do not have permission to do this.']] as [$user, $status, $message]) {
            try {
                $this->gate($user)->authorize('update', self::post(1));
                self::fail('AuthorizationException expected');
            } catch (AuthorizationException $e) {
                self::assertSame($status, $e->getStatusCode());
                self::assertSame($message, $e->getMessage(), 'never names the ability or the record');
            }
        }
    }

    public function testForUserChecksAnotherUser(): void
    {
        $gate = $this->gate(self::user(1));

        self::assertFalse($gate->forUser(self::user(2))->allows('update', self::post(1)));
        self::assertFalse($gate->forUser(null)->allows('update', self::post(1)));
        self::assertSame(1, $gate->user()?->getKey(), 'the original gate keeps its user');
    }

    public function testRulesMustReturnABool(): void
    {
        $this->expectException(AccessException::class);
        $this->expectExceptionMessage('AccessPostPolicy::broken() returned string');
        $this->gate(self::user(1))->allows('broken', self::post(1));
    }

    public function testRegistrationIsChecked(): void
    {
        $errors = [
            'invalid name' => fn() => $this->rules->define('bad name', static fn(): bool => true),
            'defined twice' => function (): void {
                $this->rules->define('x', static fn(): bool => true);
                $this->rules->define('x', static fn(): bool => true);
            },
            'second policy' => fn() => $this->rules->policy(AccessPost::class, AccessPostPolicy::class),
            'not a model' => fn() => $this->rules->policy(\stdClass::class, AccessPostPolicy::class),
            'no such policy' => fn() => $this->rules->policy(FeaturedAccessPost::class, 'App\\Policies\\Missing'),
            'invalid check' => fn() => $this->gate(null)->allows(''),
        ];

        foreach ($errors as $case => $register) {
            try {
                $register();
                self::fail('AccessException expected: ' . $case);
            } catch (AccessException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
