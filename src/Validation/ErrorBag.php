<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

/**
 * Validation errors by field, as shown to templates by RedirectBackOnValidationError:
 *
 *     <?php if ($errors->has('email')): ?><p class="error"><?= $e($errors->first('email')) ?></p><?php endif ?>
 */
final class ErrorBag implements \Countable
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(private readonly array $errors = []) {}

    public function any(): bool
    {
        return $this->errors !== [];
    }

    public function has(string $field): bool
    {
        return ($this->errors[$field] ?? []) !== [];
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->errors;
    }

    /**
     * The number of fields with errors.
     */
    public function count(): int
    {
        return count($this->errors);
    }
}
