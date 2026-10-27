<?php

declare(strict_types=1);

namespace VeyroPHP\Auth\Verification;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use VeyroPHP\Auth\Events\Verified;
use VeyroPHP\Orm\Model;
use VeyroPHP\Orm\Orm;
use VeyroPHP\Security\UrlSigner;

/**
 * Confirms that users own their email address.
 *
 *     $verifier->send($user);                         // mails a signed link
 *     $verifier->verify($user, $id, $hash);           // in the link's route, for the logged-in user
 *     $verifier->isVerified($user);
 *
 * - The link is signed (UrlSigner) and expires after "expire" minutes (default 60).
 * - It contains the user's id and a SHA-256 of the email address, so a link
 *   stops working when the address changes, and only works for that user.
 * - Verifying sets the "email_verified_at" column and dispatches Verified.
 */
final class EmailVerifier
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(string, string): string $path builds the application path of the verify route from (id, hash)
     * @param Closure(Model, string, string): void $sendLink sends the link: (user, email address, signed application path)
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly UrlSigner $signer,
        private readonly Orm $orm,
        private readonly Closure $path,
        private readonly Closure $sendLink,
        private readonly string $emailColumn = 'email',
        private readonly string $verifiedColumn = 'email_verified_at',
        private readonly int $expireMinutes = 60,
        private readonly ?EventDispatcherInterface $events = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function isVerified(Model $user): bool
    {
        $value = $user->raw($this->verifiedColumn);

        return $value !== null && $value !== '';
    }

    /**
     * Sends the verification link. Does nothing for verified users or users without an email address.
     *
     * @return bool whether a link was sent
     */
    public function send(Model $user): bool
    {
        $email = $user->raw($this->emailColumn);

        if ($this->isVerified($user) || !is_string($email) || $email === '') {
            return false;
        }

        ($this->sendLink)($user, $email, $this->signedPath($user));

        return true;
    }

    /**
     * The signed application path of the verification link (without the base URL).
     */
    public function signedPath(Model $user): string
    {
        $email = $user->raw($this->emailColumn);

        return $this->signer->sign(
            ($this->path)((string) $user->getKey(), self::hash(is_string($email) ? $email : '')),
            [],
            ($this->clock)() + $this->expireMinutes * 60,
        );
    }

    /**
     * Marks the user verified when $id and $hash belong to them. The route must
     * also check the signature (ValidateSignature middleware).
     */
    public function verify(Model $user, string $id, string $hash): bool
    {
        $email = $user->raw($this->emailColumn);

        if (!hash_equals((string) $user->getKey(), $id) || !is_string($email) || !hash_equals(self::hash($email), $hash)) {
            return false;
        }

        if (!$this->isVerified($user)) {
            $user->set($this->verifiedColumn, gmdate('Y-m-d H:i:s', ($this->clock)()));
            $this->orm->save($user);
            $this->events?->dispatch(new Verified($user));
        }

        return true;
    }

    public function expireMinutes(): int
    {
        return $this->expireMinutes;
    }

    private static function hash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
