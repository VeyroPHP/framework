<?php

declare(strict_types=1);

namespace VeyroPHP\Session;

use InvalidArgumentException;

/**
 * Data of one user session. Loaded and saved by StartSession; never touches
 * $_SESSION or PHP's native session functions.
 *
 * - flash() values survive exactly one following request.
 * - regenerate() issues a new id (call it on login to prevent session fixation).
 * - invalidate() clears all data and issues a new id and CSRF token (logout).
 */
final class Session
{
    private const TOKEN_KEY = '_token';

    private const FLASH_KEY = '_flash';

    private ?string $previousId = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $id,
        private array $data = [],
        private readonly bool $isNew = true,
    ) {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException('Invalid session id.');
        }
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $id) === 1;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * The id this session had before regenerate()/invalidate(), whose stored data must be deleted.
     */
    public function previousId(): ?string
    {
        return $this->previousId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * @return array<string, mixed> user data, without internal keys
     */
    public function all(): array
    {
        return array_diff_key($this->data, [self::TOKEN_KEY => true, self::FLASH_KEY => true]);
    }

    /**
     * Stores a value for the next request only (e.g. a "Saved" message after a redirect).
     */
    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $flash = $this->flashState();
        $flash['new'] = array_values(array_unique([...$flash['new'], $key]));
        $flash['old'] = array_values(array_diff($flash['old'], [$key]));
        $this->data[self::FLASH_KEY] = $flash;
    }

    /**
     * The CSRF token, created on first use.
     */
    public function token(): string
    {
        $token = $this->data[self::TOKEN_KEY] ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->data[self::TOKEN_KEY] = $token;
        }

        return $token;
    }

    /**
     * The CSRF token if one was already created, without creating one.
     */
    public function existingToken(): ?string
    {
        $token = $this->data[self::TOKEN_KEY] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function regenerateToken(): void
    {
        $this->data[self::TOKEN_KEY] = bin2hex(random_bytes(32));
    }

    public function regenerate(): void
    {
        $this->previousId ??= $this->id;
        $this->id = self::generateId();
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
        $this->regenerateToken();
    }

    /**
     * @internal called by StartSession at the start of a request: values flashed
     * during the previous request become readable now and are removed at the end.
     */
    public function ageFlashData(): void
    {
        $flash = $this->flashState();

        foreach ($flash['old'] as $key) {
            unset($this->data[$key]);
        }

        $this->data[self::FLASH_KEY] = ['old' => $flash['new'], 'new' => []];
    }

    /**
     * @internal called by StartSession at the end of a request
     */
    public function removeExpiredFlashData(): void
    {
        $flash = $this->flashState();

        foreach ($flash['old'] as $key) {
            unset($this->data[$key]);
        }

        $flash['old'] = [];
        $this->data[self::FLASH_KEY] = $flash;
    }

    /**
     * @internal raw data for storage
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return $this->data;
    }

    /**
     * @return array{old: list<string>, new: list<string>}
     */
    private function flashState(): array
    {
        $flash = $this->data[self::FLASH_KEY] ?? null;
        $old = is_array($flash) && is_array($flash['old'] ?? null) ? array_values(array_filter($flash['old'], 'is_string')) : [];
        $new = is_array($flash) && is_array($flash['new'] ?? null) ? array_values(array_filter($flash['new'], 'is_string')) : [];

        return ['old' => $old, 'new' => $new];
    }
}
