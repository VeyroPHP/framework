<?php

declare(strict_types=1);

namespace VeyroPHP\Auth;

use Psr\EventDispatcher\EventDispatcherInterface;
use SensitiveParameter;
use VeyroPHP\Auth\Events\Login;
use VeyroPHP\Auth\Events\LoginFailed;
use VeyroPHP\Auth\Events\Logout;
use VeyroPHP\Auth\Events\OtherDevicesLoggedOut;
use VeyroPHP\Auth\Remember\RememberTokens;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Security\PasswordHasher;
use VeyroPHP\Session\Session;

/**
 * Session-based authentication for the current request (scoped service).
 *
 *     if ($auth->attempt($data['email'], $data['password'], remember: $wantsToBeRemembered)) { ... }
 *     $auth->user();   $auth->check();   $auth->logout();
 *
 * - attempt() takes about the same time whether or not the identifier exists,
 *   so response times do not reveal which accounts exist.
 * - login() regenerates the session id (prevents session fixation).
 * - logout() clears the session and issues a new id and CSRF token.
 * - Outdated password hashes are upgraded on successful login.
 * - Rate limiting of login attempts is separate (see the rate limiter).
 * - With an event dispatcher, Login, LoginFailed, Logout and OtherDevicesLoggedOut
 *   events are sent.
 * - "Remember me" needs RememberTokens (registered by AuthServiceProvider) and the
 *   RememberMe middleware. A session without a user is then logged in from a valid
 *   remember cookie; a password change ends remembered browsers like sessions.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user';

    /** A fingerprint of the password hash at login; a session whose fingerprint no longer matches ends. */
    private const FINGERPRINT_KEY = '_auth_password';

    private ?Model $user = null;

    private bool $resolved = false;

    /**
     * @param list<string> $fingerprintKeys keys for the password fingerprint, current first (derived
     *        from APP_KEY and its previous keys). Without keys a plain SHA-256 is used.
     */
    public function __construct(
        private readonly Orm $orm,
        private readonly Session $session,
        private readonly PasswordHasher $hasher,
        private readonly AuthConfig $config,
        private readonly ?EventDispatcherInterface $events = null,
        #[SensitiveParameter]
        private readonly array $fingerprintKeys = [],
        private readonly ?RememberTokens $remember = null,
    ) {}

    /**
     * Checks credentials and logs the user in on success.
     */
    public function attempt(string $identifier, #[SensitiveParameter] string $password, bool $remember = false): bool
    {
        $user = $this->findByIdentifier($identifier);

        if ($user === null) {
            $this->hasher->verify($password, $this->hasher->dummyHash());
            $this->events?->dispatch(new LoginFailed($identifier, null));

            return false;
        }

        $hash = $user->raw($this->config->passwordColumn);

        if (!is_string($hash) || !$this->hasher->verify($password, $hash)) {
            $this->events?->dispatch(new LoginFailed($identifier, $user));

            return false;
        }

        if ($this->hasher->needsRehash($hash)) {
            $user->set($this->config->passwordColumn, $this->hasher->hash($password));
            $this->orm->save($user);
        }

        $this->login($user, $remember);

        return true;
    }

    /**
     * Checks credentials without logging in (e.g. to confirm a password).
     */
    public function validate(string $identifier, #[SensitiveParameter] string $password): bool
    {
        $user = $this->findByIdentifier($identifier);
        $hash = $user?->raw($this->config->passwordColumn);

        return $this->hasher->verify($password, is_string($hash) ? $hash : $this->hasher->dummyHash()) && $user !== null;
    }

    /**
     * @param bool $remember also remember this browser (needs RememberTokens)
     */
    public function login(Model $user, bool $remember = false): void
    {
        $key = $user->getKey();

        if ($key === null || !$user->exists()) {
            throw new \LogicException('Only saved users can be logged in.');
        }

        $this->session->regenerate();
        $this->session->put(self::SESSION_KEY, $key);
        $this->session->put(self::FINGERPRINT_KEY, $this->fingerprint($user, $this->fingerprintKeys[0] ?? null));
        $this->user = $user;
        $this->resolved = true;

        if ($remember) {
            ($this->remember ?? throw new \LogicException('"Remember me" needs RememberTokens; register AuthServiceProvider and the RememberMe middleware.'))
                ->issue($key, $this->fingerprint($user, $this->fingerprintKeys[0] ?? null));
        }

        $this->events?->dispatch(new Login($user));
    }

    /**
     * Makes $user the current user for this request only: nothing is stored in
     * the session and no Login event is sent. Used by API token authentication.
     */
    public function loginForRequest(Model $user): void
    {
        if ($user->getKey() === null || !$user->exists()) {
            throw new \LogicException('Only saved users can be logged in.');
        }

        $this->user = $user;
        $this->resolved = true;
    }

    public function logout(): void
    {
        $user = $this->events === null ? null : $this->user();
        $this->remember?->forget();
        $this->session->invalidate();
        $this->user = null;
        $this->resolved = true;
        $this->events?->dispatch(new Logout($user));
    }

    /**
     * The logged-in user, loaded once per request; null for guests or deleted users.
     */
    public function user(): ?Model
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !is_string($id)) {
            return $this->userFromRememberCookie();
        }

        $this->user = $this->orm->find($this->config->model, $id);

        // The password changed since this session logged in (VQ-011), or the session
        // predates fingerprints: it no longer counts as logged in.
        if ($this->user !== null && !$this->fingerprintMatches($this->user)) {
            $this->user = null;
        }

        if ($this->user === null) {
            $this->session->forget(self::SESSION_KEY);
            $this->session->forget(self::FINGERPRINT_KEY);
        }

        return $this->user;
    }

    /**
     * Ends every other session and remembered browser of the logged-in user, after
     * confirming the password: the password is hashed again, so the fingerprint that
     * other sessions and remember tokens hold no longer matches. This session and this
     * browser's remember token stay valid. Returns false for a wrong password.
     *
     * @throws \LogicException when nobody is logged in
     */
    public function logoutOtherDevices(#[SensitiveParameter] string $password): bool
    {
        $user = $this->user() ?? throw new \LogicException('logoutOtherDevices() needs a logged-in user.');
        $hash = $user->raw($this->config->passwordColumn);

        if (!is_string($hash) || !$this->hasher->verify($password, $hash)) {
            return false;
        }

        $user->set($this->config->passwordColumn, $this->hasher->hash($password));
        $this->orm->save($user);
        $fingerprint = $this->fingerprint($user, $this->fingerprintKeys[0] ?? null);
        $this->session->put(self::FINGERPRINT_KEY, $fingerprint);
        $this->remember?->forgetOthers((string) $user->getKey(), $fingerprint);
        $this->events?->dispatch(new OtherDevicesLoggedOut($user));

        return true;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getKey();
    }

    private function fingerprint(Model $user, ?string $key): string
    {
        $hash = $user->raw($this->config->passwordColumn);
        $hash = is_string($hash) ? $hash : '';

        return $key === null ? hash('sha256', 'veyrophp:auth-session|' . $hash) : hash_hmac('sha256', $hash, $key);
    }

    /**
     * A session without a user, but a valid remember cookie whose password fingerprint
     * still matches: log in (new session id, Login event). Otherwise forget the cookie.
     */
    private function userFromRememberCookie(): ?Model
    {
        $token = $this->remember?->resolve();

        if ($this->remember === null || $token === null) {
            return null;
        }

        $user = $this->orm->find($this->config->model, $token['user_id']);

        if ($user === null || !$this->fingerprintMatches($user, $token['password_fingerprint'])) {
            $this->remember->forget();

            return null;
        }

        $this->login($user);

        return $user;
    }

    private function fingerprintMatches(Model $user, mixed $stored = null): bool
    {
        $stored ??= $this->session->get(self::FINGERPRINT_KEY);

        if (!is_string($stored)) {
            return false;
        }

        foreach ($this->fingerprintKeys === [] ? [null] : $this->fingerprintKeys as $key) {
            if (hash_equals($this->fingerprint($user, $key), $stored)) {
                return true;
            }
        }

        return false;
    }

    private function findByIdentifier(string $identifier): ?Model
    {
        if ($identifier === '') {
            return null;
        }

        return $this->orm->query($this->config->model)->where($this->config->identifier, $identifier)->first();
    }
}
