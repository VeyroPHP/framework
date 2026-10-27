<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

/**
 * A custom validation rule, usable next to the built-in string rules:
 *
 *     ['email' => ['required', 'email', new Unique($db, 'users', 'email')]]
 */
interface Rule
{
    /**
     * @param array<array-key, mixed> $data all input, for rules that compare fields
     *
     * @return string|null an error message (":field" is replaced with the field name), or null when valid
     */
    public function validate(string $field, mixed $value, array $data): ?string;
}
