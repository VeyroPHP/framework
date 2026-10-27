<?php

declare(strict_types=1);

namespace VeyroPHP\Logging;

use VeyroPHP\Support\SensitiveKey;

/**
 * Replaces values of sensitive-looking keys in log context with "[REDACTED]".
 *
 * Keys are matched by SensitiveKey in its broad form, plus DEFAULT_WORDS: "api_key",
 * "X-Api-Key", "userPassword" and "APP_KEY" are all redacted. Matching is deliberately
 * broad: a false positive hides a harmless value, while a false negative leaks a secret.
 *
 * In string values under other keys, "Bearer <token>" and the value of an
 * "Authorization:" header line are replaced too. Secrets written into the log message
 * itself cannot be detected.
 *
 * @internal Framework implementation detail, not covered by the backward-compatibility promise.
 */
final class Redactor
{
    public const REPLACEMENT = '[REDACTED]';

    /**
     * Log-only words, in addition to SensitiveKey's: request and query details that are
     * secret in logs but ordinary as form field names.
     */
    public const DEFAULT_WORDS = ['cookie', 'session', 'bindings'];

    private const MAX_DEPTH = 20;

    // "Bearer <token>" anywhere, and the rest of an "Authorization:" header line.
    private const CREDENTIALS = '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]{8,}=*|\b(Authorization:)[ \t]*[^\r\n]+/i';

    /** @var list<string> */
    private readonly array $words;

    /**
     * @param list<string> $additionalWords extra sensitive words, matched like the defaults
     */
    public function __construct(array $additionalWords = [])
    {
        $words = self::DEFAULT_WORDS;

        foreach ($additionalWords as $word) {
            $normalized = SensitiveKey::normalize($word);

            if ($normalized !== '') {
                $words[] = $normalized;
            }
        }

        $this->words = array_values(array_unique($words));
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    public function isSensitive(string $key): bool
    {
        return SensitiveKey::matches($key, true, $this->words);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $values[$key] = self::REPLACEMENT;
            } elseif (is_array($value)) {
                $values[$key] = $depth < self::MAX_DEPTH ? $this->redactArray($value, $depth + 1) : self::REPLACEMENT;
            } elseif (is_string($value) && preg_match(self::CREDENTIALS, $value) === 1) {
                $values[$key] = preg_replace(self::CREDENTIALS, '$1$2 ' . self::REPLACEMENT, $value) ?? self::REPLACEMENT;
            }
        }

        return $values;
    }
}
