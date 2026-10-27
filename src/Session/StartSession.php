<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use VeyroPHP\Http\Cookie;
use VeyroPHP\Http\HttpErrorResponder;

/**
 * Loads the session from the cache store, makes it available, and saves it.
 *
 * - The session id comes from a cookie. An id that is malformed or unknown to
 *   the store is never adopted: a new id is generated (prevents session fixation).
 * - The session is available as the request attribute Session::class and
 *   through the container (SessionHolder / Session).
 * - Data is saved with a TTL equal to the lifetime, so inactive sessions expire.
 *   A session that was never written to is not stored and gets no cookie.
 * - After regenerate()/invalidate(), the old id is marked destroyed for the
 *   lifetime: its data is gone, and a request that was still running with it
 *   cannot write it back.
 * - The cookie is HttpOnly and SameSite=Lax by default, and Secure on HTTPS.
 * - A response that gets the cookie is never cacheable by shared caches: "public"
 *   and "s-maxage" in Cache-Control become "private, no-cache".
 * - With an HttpErrorResponder, an exception thrown after the session was stored is
 *   rendered here, so the error response still carries the (possibly new) cookie.
 */
final class StartSession implements MiddlewareInterface
{
    private const KEY_PREFIX = 'session.';

    /**
     * Stored under an id after regenerate()/invalidate(). It is not an array, so load()
     * treats the id as unknown, and save() will not write session data back under it.
     */
    private const DESTROYED = 'veyrophp:session-destroyed';

    /**
     * @param HttpErrorResponder|null $errors renders exceptions thrown further down when the
     *                                        session was stored, so the error response carries
     *                                        the cookie (VQ-046); without it they propagate
     */
    public function __construct(
        private readonly CacheInterface $store,
        private readonly SessionHolder $holder,
        private readonly SessionConfig $config = new SessionConfig(),
        private readonly ?HttpErrorResponder $errors = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->load($request);
        $session->ageFlashData();
        $this->holder->set($session);
        $request = $request->withAttribute(Session::class, $session);
        $failure = null;

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $failure = $e;
        } finally {
            $session->removeExpiredFlashData();
            $stored = $this->save($session);
        }

        if ($failure !== null) {
            // Without a cookie, a session saved under a new id (after regenerate()) is lost and the
            // browser keeps the destroyed old id. Render the error here so the cookie can be attached.
            if (!$stored || $this->errors === null) {
                throw $failure;
            }

            $response = $this->errors->respond($failure, $request);
        }

        if (!$stored) {
            return $response;
        }

        $cookie = new Cookie(
            $this->config->cookieName,
            $session->id(),
            $this->config->lifetimeMinutes * 60,
            $this->config->path,
            $this->config->domain,
            $this->config->secure ?? ($request->getUri()->getScheme() === 'https'),
            true,
            $this->config->sameSite,
        );

        return self::withoutSharedCaching($response)->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
    }

    /**
     * A response that carries the session cookie belongs to one visitor, so shared caches
     * (CDNs, proxies) must not keep it. Route middleware such as ConditionalGet runs
     * before the cookie is added and cannot see it (HTTP-1). "public" and "s-maxage"
     * therefore become "private, no-cache" ("private, no-store" when no-store was set).
     * A private max-age stays: only the visitor's own browser keeps that copy.
     */
    private static function withoutSharedCaching(ResponseInterface $response): ResponseInterface
    {
        $directives = array_map(static fn(string $directive): string => strtolower(trim($directive)), explode(',', $response->getHeaderLine('Cache-Control')));
        $shared = array_filter($directives, static fn(string $directive): bool => $directive === 'public' || str_starts_with($directive, 's-maxage'));

        if ($shared === []) {
            return $response;
        }

        return $response->withHeader('Cache-Control', in_array('no-store', $directives, true) ? 'private, no-store' : 'private, no-cache');
    }

    private function load(ServerRequestInterface $request): Session
    {
        $id = $request->getCookieParams()[$this->config->cookieName] ?? null;

        if (is_string($id) && Session::isValidId($id)) {
            $stored = $this->store->get(self::KEY_PREFIX . $id);

            if (is_array($stored)) {
                $data = [];
                foreach ($stored as $key => $value) {
                    if (is_string($key)) {
                        $data[$key] = $value;
                    }
                }

                return new Session($id, $data, false);
            }
        }

        return new Session(Session::generateId());
    }

    /**
     * @return bool whether the session is stored (and needs a cookie)
     */
    private function save(Session $session): bool
    {
        $previous = $session->previousId();
        $ttl = $this->config->lifetimeMinutes * 60;

        if ($previous !== null) {
            // A tombstone, not a delete: a request still running with the old id must
            // not write the session back when it finishes (VQ-014).
            $this->store->set(self::KEY_PREFIX . $previous, self::DESTROYED, $ttl);
        }

        $data = $session->toStorage();
        $meaningful = array_diff_key($data, ['_flash' => true]);

        if ($meaningful === [] && $session->isNew()) {
            return false;
        }

        // Another request destroyed this session while this one ran: keep it destroyed.
        // (Without an atomic compare-and-set in the store, a write landing between this
        // check and set() can still win; the window is this one round trip.)
        if (!$session->isNew() && $this->store->get(self::KEY_PREFIX . $session->id()) === self::DESTROYED) {
            return false;
        }

        return $this->store->set(self::KEY_PREFIX . $session->id(), $data, $ttl);
    }
}
