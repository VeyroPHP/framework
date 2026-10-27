<?php

declare(strict_types=1);

namespace VeyroPHP\Validation;

use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;
use VeyroPHP\Security\MimeType;
use VeyroPHP\Support\Ulid;

/**
 * Validates input arrays against rules and returns only the validated fields.
 *
 *     $data = $validator->validate($request->getParsedBody() ?? [], [
 *         'name' => 'required|string|max:100',
 *         'email' => ['required', 'email', new Unique($db, 'users', 'email')],
 *         'age' => 'nullable|integer|between:18,120',
 *     ]);
 *
 * Semantics:
 * - Fields without rules are never returned, so extra input cannot slip through.
 * - "required": the field must be present and not null, "", whitespace only, or [].
 * - "nullable": an empty value is accepted and returned as null; other rules are skipped.
 * - A present but empty field without "nullable" fails, so "" never reaches
 *   code that expects a number or an email.
 * - A missing optional field is skipped and not returned.
 * - Each field reports its first failing rule only.
 * - Values are returned as given (form values stay strings); cast where you use them.
 * - Dot notation reads nested arrays: "address.city". Validated nested fields are
 *   returned under their keys ("address.city"); an array field with rules below it
 *   ("address") is returned with only those sub-keys (ADR 0037).
 * - "*" matches every key at its level: "tags.*", "items.*.id". Errors and values
 *   use the expanded keys ("items.1.id"). At most MAX_WILDCARD_PATHS paths per rule.
 * - required_if, required_unless, required_with and required_without make a field
 *   required depending on other fields (named by their full path).
 * - after, after_or_equal, before and before_or_equal compare dates (Y-m-d, HTML
 *   date-time input or ISO 8601) with "today", "tomorrow", "yesterday", "now", a
 *   date, or another field.
 * - Rules: docs/security/authentication.md#validation lists them all.
 */
final class Validator
{
    /** Upper bound on the paths one "*" rule may expand to, against oversized input. */
    public const MAX_WILDCARD_PATHS = 10_000;

    /** Rules that decide whether a value must be there, handled before the others. */
    private const PRESENCE_RULES = ['required', 'nullable', 'required_if', 'required_unless', 'required_with', 'required_without'];

    /** Accepted date input: dates, HTML date-time inputs and ISO 8601 with an offset. */
    private const DATE_FORMATS = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', DATE_ATOM];

    private const DATE_RULES = ['after' => '>', 'after_or_equal' => '>=', 'before' => '<', 'before_or_equal' => '<='];

    private const MESSAGES = [
        'too_many' => 'The :field field has too many items.',
        'required' => 'The :field field is required.',
        'empty' => 'The :field field must not be empty.',
        'string' => 'The :field field must be text.',
        'integer' => 'The :field field must be a whole number.',
        'numeric' => 'The :field field must be a number.',
        'boolean' => 'The :field field must be true or false.',
        'array' => 'The :field field must be a list.',
        'email' => 'The :field field must be a valid email address.',
        'url' => 'The :field field must be a valid http or https URL.',
        'min.numeric' => 'The :field field must be at least :min.',
        'min.string' => 'The :field field must be at least :min characters.',
        'min.array' => 'The :field field must have at least :min items.',
        'max.numeric' => 'The :field field must not be greater than :max.',
        'max.string' => 'The :field field must not be longer than :max characters.',
        'max.array' => 'The :field field must not have more than :max items.',
        'between.numeric' => 'The :field field must be between :min and :max.',
        'between.string' => 'The :field field must be between :min and :max characters.',
        'between.array' => 'The :field field must have between :min and :max items.',
        'in' => 'The selected :field is invalid.',
        'not_in' => 'The selected :field is invalid.',
        'regex' => 'The :field field format is invalid.',
        'confirmed' => 'The :field confirmation does not match.',
        'same' => 'The :field field must match :other.',
        'date' => 'The :field field must be a date in the format YYYY-MM-DD.',
        'date_format' => 'The :field field must match the format :format.',
        'ulid' => 'The :field field must be a valid identifier.',
        'alpha_dash' => 'The :field field may only contain letters, numbers, dashes and underscores.',
        'file' => 'The :field must be a successfully uploaded file.',
        'max_size' => 'The :field must not be larger than :max kilobytes.',
        'max_bytes' => 'The :field field is too long.',
        'mimes' => 'The :field must be a file of type: :values.',
        'image' => 'The :field must be an image (JPEG, PNG, GIF or WebP).',
        'accepted' => 'The :field must be accepted.',
        'alpha' => 'The :field field may only contain letters.',
        'alpha_num' => 'The :field field may only contain letters and numbers.',
        'digits' => 'The :field field must be :digits digits.',
        'digits_between' => 'The :field field must be between :min and :max digits.',
        'uuid' => 'The :field field must be a valid UUID.',
        'json' => 'The :field field must be valid JSON.',
        'ip' => 'The :field field must be a valid IP address.',
        'ipv4' => 'The :field field must be a valid IPv4 address.',
        'ipv6' => 'The :field field must be a valid IPv6 address.',
        'lowercase' => 'The :field field must be lowercase.',
        'uppercase' => 'The :field field must be uppercase.',
        'different' => 'The :field field and :other must be different.',
        'starts_with' => 'The :field field must start with one of: :values.',
        'ends_with' => 'The :field field must end with one of: :values.',
        'size.numeric' => 'The :field field must be :size.',
        'size.string' => 'The :field field must be :size characters.',
        'size.array' => 'The :field field must have :size items.',
        'after' => 'The :field field must be a date after :date.',
        'after_or_equal' => 'The :field field must be a date on or after :date.',
        'before' => 'The :field field must be a date before :date.',
        'before_or_equal' => 'The :field field must be a date on or before :date.',
    ];

    /**
     * @param array<string, string> $messages custom messages: "field.rule" or "rule" => text
     * @param (\Closure(string): ?string)|null $translate looks up "validation.<rule>" and
     *        "validation.attributes.<field>" translations; null when there is none
     * @param (\Closure(): int)|null $clock current Unix time, for "today" and "now" in date rules
     */
    public function __construct(
        private readonly array $messages = [],
        private readonly ?\Closure $translate = null,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string|list<string|Rule>> $rules
     * @param array<string, string> $messages overrides for this call
     *
     * @return array<string, mixed> validated fields only
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        [$validated, $errors] = $this->run($data, $rules, $messages);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    /**
     * Like validate(), but returns the errors instead of throwing.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string|list<string|Rule>> $rules
     * @param array<string, string> $messages
     *
     * @return array<string, list<string>>
     */
    public function errors(array $data, array $rules, array $messages = []): array
    {
        return $this->run($data, $rules, $messages)[1];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string|list<string|Rule>> $rules
     * @param array<string, string> $messages
     *
     * @return array{array<string, mixed>, array<string, list<string>>}
     */
    private function run(array $data, array $rules, array $messages): array
    {
        $messages = $messages + $this->messages;
        $validated = [];
        $errors = [];
        $expanded = [];

        foreach ($rules as $field => $fieldRules) {
            if (!str_contains($field, '*')) {
                $expanded[$field] = $fieldRules;
                continue;
            }

            $matches = [];
            $paths = 0;

            if (!self::expand($data, explode('.', $field), '', $matches, $paths)) {
                $errors[$field][] = $this->message($messages, $field, 'too_many', 'too_many', []);
                continue;
            }

            foreach ($matches as $path) {
                $expanded[$path] ??= $fieldRules;
            }
        }

        if ($errors !== []) {
            return [[], $errors];
        }

        foreach ($expanded as $field => $fieldRules) {
            $parsed = self::parse($fieldRules);
            [$present, $value] = self::read($data, $field);
            $names = [];
            foreach ($parsed as $rule) {
                if (is_array($rule)) {
                    $names[] = $rule[0];
                }
            }
            $empty = self::isEmpty($value);
            $requiredBy = in_array('required', $names, true) ? 'required' : self::requiredBy($parsed, $data);

            if ($requiredBy !== null && (!$present || $empty)) {
                $errors[$field][] = $this->message($messages, $field, $requiredBy, 'required', []);
                continue;
            }

            if (!$present) {
                continue;
            }

            if ($empty) {
                if (in_array('nullable', $names, true)) {
                    $validated[$field] = null;
                } else {
                    $errors[$field][] = $this->message($messages, $field, 'empty', 'empty', []);
                }
                continue;
            }

            $error = $this->firstError($field, $value, $parsed, $names, $data, $messages);

            if ($error !== null) {
                $errors[$field][] = $error;
                continue;
            }

            $validated[$field] = $value;
        }

        return [self::onlyValidatedSubKeys($validated, array_keys($expanded)), $errors];
    }

    /**
     * Expands a rule key with "*" against the input into concrete paths ("items.*.id" to
     * "items.0.id", ...). Segments after the last "*" are kept even when missing, so
     * "required" can report them. False when more than MAX_WILDCARD_PATHS are needed.
     *
     * @param list<string> $segments
     * @param list<string> $out
     */
    private static function expand(mixed $value, array $segments, string $prefix, array &$out, int &$count): bool
    {
        if (!in_array('*', $segments, true)) {
            $out[] = $segments === [] ? substr($prefix, 0, -1) : $prefix . implode('.', $segments);

            return ++$count <= self::MAX_WILDCARD_PATHS;
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            return !is_array($value) || !array_key_exists($segment, $value)
                || self::expand($value[$segment], $segments, $prefix . $segment . '.', $out, $count);
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $key => $item) {
            if (!self::expand($item, $segments, $prefix . $key . '.', $out, $count)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuilds every validated array field that has rules below it from its validated
     * sub-keys only, deepest first, so input without rules cannot pass through it.
     *
     * @param array<string, mixed> $validated
     * @param list<string> $ruleKeys
     *
     * @return array<string, mixed>
     */
    private static function onlyValidatedSubKeys(array $validated, array $ruleKeys): array
    {
        // Indexes built once, so the work grows with keys × depth, not keys² (VQ-017).
        $hasRulesBelow = [];
        foreach ($ruleKeys as $ruleKey) {
            $prefix = '';
            foreach (array_slice(explode('.', $ruleKey), 0, -1) as $segment) {
                $prefix .= ($prefix === '' ? '' : '.') . $segment;
                $hasRulesBelow[$prefix] = true;
            }
        }

        $children = [];
        foreach (array_keys($validated) as $key) {
            $prefix = '';
            foreach (array_slice(explode('.', $key), 0, -1) as $segment) {
                $prefix .= ($prefix === '' ? '' : '.') . $segment;
                if (isset($hasRulesBelow[$prefix]) && array_key_exists($prefix, $validated) && is_array($validated[$prefix])) {
                    $children[$prefix][] = $key;
                }
            }
        }

        $parents = array_keys(array_filter($hasRulesBelow, static fn(bool $_, string $key): bool => array_key_exists($key, $validated) && is_array($validated[$key]), ARRAY_FILTER_USE_BOTH));
        usort($parents, static fn(string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.'));

        foreach ($parents as $parent) {
            $rebuilt = [];
            $offset = strlen($parent) + 1;
            $list = $children[$parent] ?? [];
            usort($list, static fn(string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));

            foreach ($list as $child) {
                self::setPath($rebuilt, explode('.', substr($child, $offset)), $validated[$child]);
            }

            $validated[$parent] = $rebuilt;
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<string> $segments
     */
    private static function setPath(array &$target, array $segments, mixed $value): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segments === []) {
            $target[$segment] = $value;

            return;
        }

        if (!isset($target[$segment]) || !is_array($target[$segment])) {
            $target[$segment] = [];
        }

        self::setPath($target[$segment], $segments, $value);
    }

    /**
     * @param list<array{string, list<string>}|Rule> $rules
     * @param list<string> $names
     * @param array<array-key, mixed> $data
     * @param array<string, string> $messages
     */
    private function firstError(string $field, mixed $value, array $rules, array $names, array $data, array $messages): ?string
    {
        $kind = match (true) {
            in_array('integer', $names, true), in_array('numeric', $names, true) => 'numeric',
            is_array($value) => 'array',
            default => 'string',
        };

        foreach ($rules as $rule) {
            if ($rule instanceof Rule) {
                $message = $rule->validate($field, $value, $data);
                if ($message !== null) {
                    return str_replace(':field', self::label($field), $message);
                }
                continue;
            }

            [$name, $parameters] = $rule;

            if (in_array($name, self::PRESENCE_RULES, true)) {
                continue;
            }

            $valid = $this->check($name, $parameters, $value, $data, $field, $kind);

            if (!$valid) {
                $key = in_array($name, ['min', 'max', 'between', 'size'], true) ? $name . '.' . $kind : $name;

                return $this->message($messages, $field, $name, $key, $this->replacements($name, $parameters));
            }
        }

        return null;
    }

    /**
     * @param list<string> $parameters
     * @param array<array-key, mixed> $data
     */
    private function check(string $name, array $parameters, mixed $value, array $data, string $field, string $kind): bool
    {
        return match ($name) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && self::isIntegerString($value)),
            'numeric' => is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && preg_match('/^-?(\d+(\.\d+)?|\.\d+)([eE][-+]?\d+)?$/D', $value) === 1),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($value),
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
            'min' => self::size($value, $kind) >= self::number($name, $parameters, 0),
            'max' => self::size($value, $kind) <= self::number($name, $parameters, 0),
            'between' => self::size($value, $kind) >= self::number($name, $parameters, 0) && self::size($value, $kind) <= self::number($name, $parameters, 1),
            'in' => is_scalar($value) && in_array((string) $value, self::needParameters($name, $parameters), true),
            'not_in' => is_scalar($value) && !in_array((string) $value, self::needParameters($name, $parameters), true),
            'regex' => is_string($value) && self::regex($parameters, $value),
            // Next to the field: "account.password" is confirmed by "account.password_confirmation" (HTTP-4).
            'confirmed' => self::read($data, $field . '_confirmation')[1] === $value,
            'same' => self::read($data, self::needParameters($name, $parameters)[0])[1] === $value,
            'date' => is_string($value) && self::matchesDateFormat('Y-m-d', $value),
            'date_format' => is_string($value) && self::matchesDateFormat(self::needParameters($name, $parameters)[0], $value),
            'ulid' => is_string($value) && Ulid::isValid($value),
            'alpha_dash' => is_string($value) && preg_match('/^[\pL\pM\pN_-]+$/uD', $value) === 1,
            'file' => $value instanceof UploadedFileInterface && $value->getError() === UPLOAD_ERR_OK,
            'max_size' => $value instanceof UploadedFileInterface && ($value->getSize() ?? PHP_INT_MAX) <= self::number($name, $parameters, 0) * 1024,
            'max_bytes' => is_string($value) && strlen($value) <= self::number($name, $parameters, 0),
            'mimes' => $value instanceof UploadedFileInterface && self::matchesExtensions($value, self::needParameters($name, $parameters)),
            'image' => $value instanceof UploadedFileInterface && in_array(MimeType::detect($value), MimeType::IMAGE_TYPES, true),
            'accepted' => in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true),
            'alpha' => is_string($value) && preg_match('/^[\pL\pM]+$/uD', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\pL\pM\pN]+$/uD', $value) === 1,
            'digits' => self::digitCount($value) === (int) self::number($name, $parameters, 0),
            'digits_between' => self::digitCount($value) !== null
                && self::digitCount($value) >= self::number($name, $parameters, 0) && self::digitCount($value) <= self::number($name, $parameters, 1),
            'uuid' => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1,
            'json' => is_string($value) && self::isJson($value),
            'ip' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'lowercase' => is_string($value) && mb_strtolower($value, 'UTF-8') === $value,
            'uppercase' => is_string($value) && mb_strtoupper($value, 'UTF-8') === $value,
            'different' => self::read($data, self::needParameters($name, $parameters)[0])[1] !== $value,
            'starts_with' => is_string($value) && array_filter(self::needParameters($name, $parameters), static fn(string $p): bool => str_starts_with($value, $p)) !== [],
            'ends_with' => is_string($value) && array_filter(self::needParameters($name, $parameters), static fn(string $p): bool => str_ends_with($value, $p)) !== [],
            'size' => self::size($value, $kind) === self::number($name, $parameters, 0),
            'after', 'after_or_equal', 'before', 'before_or_equal' => $this->compareDate($name, $parameters, $value, $data),
            default => throw InvalidRuleException::unknown($name),
        };
    }

    /**
     * The rule that makes an empty or missing field required, or null.
     *
     * - required_if:other,a,b       when "other" is one of the values
     * - required_unless:other,a,b   unless "other" is one of the values
     * - required_with:a,b           when any of the fields is filled in
     * - required_without:a,b        when any of the fields is missing or empty
     *
     * Other fields are named by their full path ("address.country").
     *
     * @param list<array{string, list<string>}|Rule> $parsed
     * @param array<array-key, mixed> $data
     */
    private static function requiredBy(array $parsed, array $data): ?string
    {
        foreach ($parsed as $rule) {
            if (!is_array($rule) || !str_starts_with($rule[0], 'required_')) {
                continue;
            }

            [$name, $parameters] = $rule;
            $filled = static fn(string $other): bool => !self::isEmpty(self::read($data, $other)[1]);

            $required = match ($name) {
                'required_if', 'required_unless' => (function () use ($name, $parameters, $data): bool {
                    $other = self::needParameters($name, $parameters)[0];
                    $values = array_slice($parameters, 1);

                    if ($values === []) {
                        throw InvalidRuleException::badParameters($name, 'a field and at least one value, e.g. ' . $name . ':type,company');
                    }

                    $actual = self::read($data, $other)[1];
                    $matches = is_scalar($actual) && in_array(is_bool($actual) ? ($actual ? '1' : '0') : (string) $actual, $values, true);

                    return $name === 'required_if' ? $matches : !$matches;
                })(),
                'required_with' => array_filter(self::needParameters($name, $parameters), $filled) !== [],
                'required_without' => count(array_filter(self::needParameters($name, $parameters), $filled)) < count($parameters),
                default => throw InvalidRuleException::unknown($name),
            };

            if ($required) {
                return $name;
            }
        }

        return null;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '')
            || ($value instanceof UploadedFileInterface && $value->getError() === UPLOAD_ERR_NO_FILE);
    }

    /**
     * The number of digits when the value is only digits (as text or a non-negative int), else null.
     */
    private static function digitCount(mixed $value): ?int
    {
        $text = is_int($value) && $value >= 0 ? (string) $value : $value;

        return is_string($text) && $text !== '' && ctype_digit($text) ? strlen($text) : null;
    }

    private static function isJson(string $value): bool
    {
        try {
            json_decode($value, false, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * after/before rules. The parameter is "today", "tomorrow", "yesterday", "now", a
     * date in an accepted format, or the name of another field holding one.
     *
     * @param list<string> $parameters
     * @param array<array-key, mixed> $data
     */
    private function compareDate(string $name, array $parameters, mixed $value, array $data): bool
    {
        $date = self::parseDate($value);
        $reference = $this->referenceDate(self::needParameters($name, $parameters)[0], $data);

        if ($date === null || $reference === null) {
            return false;
        }

        return match (self::DATE_RULES[$name] ?? '') {
            '>' => $date > $reference,
            '>=' => $date >= $reference,
            '<' => $date < $reference,
            default => $date <= $reference,
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function referenceDate(string $reference, array $data): ?DateTimeImmutable
    {
        $now = (new DateTimeImmutable('@' . ($this->clock === null ? time() : ($this->clock)())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return match ($reference) {
            'now' => $now,
            'today' => $now->setTime(0, 0),
            'tomorrow' => $now->setTime(0, 0)->modify('+1 day'),
            'yesterday' => $now->setTime(0, 0)->modify('-1 day'),
            default => self::parseDate($reference) ?? self::parseDate(self::read($data, $reference)[1]),
        };
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            if (self::matchesDateFormat($format, $value)) {
                $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

                return $date === false ? null : $date;
            }
        }

        return null;
    }

    /**
     * @param string|list<string|Rule> $rules
     *
     * @return list<array{string, list<string>}|Rule>
     */
    private static function parse(string|array $rules): array
    {
        $items = is_string($rules) ? explode('|', $rules) : $rules;
        $parsed = [];

        foreach ($items as $item) {
            if ($item instanceof Rule) {
                $parsed[] = $item;
                continue;
            }

            $pieces = explode(':', $item, 2);
            $name = trim($pieces[0]);
            $parameterString = $pieces[1] ?? null;
            // Regex patterns may contain commas, so they are not split.
            $parameters = $parameterString === null ? [] : ($name === 'regex' ? [$parameterString] : explode(',', $parameterString));
            $parsed[] = [$name, $parameters];
        }

        return $parsed;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{bool, mixed}
     */
    private static function read(array $data, string $field): array
    {
        $current = $data;

        foreach (explode('.', $field) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * True when the upload's detected content type belongs to one of the extensions.
     *
     * @param list<string> $extensions
     */
    private static function matchesExtensions(UploadedFileInterface $file, array $extensions): bool
    {
        $detected = MimeType::detect($file);

        if ($detected === null) {
            return false;
        }

        foreach ($extensions as $extension) {
            $types = MimeType::EXTENSIONS[strtolower(trim($extension))] ?? throw InvalidRuleException::badParameters('mimes', 'known extensions: ' . implode(', ', array_keys(MimeType::EXTENSIONS)));

            if (in_array($detected, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Digits with an optional minus sign, within the int range. Leading zeros are
     * allowed; filter_var() alone rejects them.
     */
    private static function isIntegerString(string $value): bool
    {
        return preg_match('/^(-?)0*(\d+)$/D', $value, $match) === 1
            && filter_var($match[1] . $match[2], FILTER_VALIDATE_INT) !== false;
    }

    private static function size(mixed $value, string $kind): float
    {
        return match (true) {
            $kind === 'numeric' && is_numeric($value) => (float) $value,
            is_array($value) => count($value),
            is_string($value) => mb_strlen($value, 'UTF-8'),
            default => NAN, // comparisons with NAN are false, so the rule fails
        };
    }

    /**
     * @param list<string> $parameters
     */
    private static function number(string $rule, array $parameters, int $index): float
    {
        $value = $parameters[$index] ?? null;

        if ($value === null || !is_numeric($value)) {
            throw InvalidRuleException::badParameters($rule, 'numeric parameters, e.g. ' . $rule . ':3' . ($rule === 'between' ? ',10' : ''));
        }

        return (float) $value;
    }

    /**
     * @param list<string> $parameters
     *
     * @return non-empty-list<string>
     */
    private static function needParameters(string $rule, array $parameters): array
    {
        if ($parameters === []) {
            throw InvalidRuleException::badParameters($rule, 'at least one parameter');
        }

        return $parameters;
    }

    /**
     * @param list<string> $parameters
     */
    private static function regex(array $parameters, string $value): bool
    {
        $pattern = self::needParameters('regex', $parameters)[0];
        $result = @preg_match($pattern, $value);

        if ($result === false) {
            throw InvalidRuleException::badParameters('regex', 'a valid regular expression with delimiters');
        }

        return $result === 1;
    }

    private static function matchesDateFormat(string $format, string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    /**
     * @param list<string> $parameters
     *
     * @return array<string, string>
     */
    private function replacements(string $rule, array $parameters): array
    {
        $first = $parameters[0] ?? '';

        return match ($rule) {
            'min', 'max', 'max_size', 'max_bytes' => [':' . ($rule === 'min' ? 'min' : 'max') => $first],
            'between', 'digits_between' => [':min' => $first, ':max' => $parameters[1] ?? ''],
            'same', 'different' => [':other' => $this->fieldName($first)],
            'date_format' => [':format' => $first],
            'mimes', 'starts_with', 'ends_with' => [':values' => implode(', ', $parameters)],
            'digits' => [':digits' => $first],
            'size' => [':size' => $first],
            // A date or a word such as "today" is shown as written; a field by its name.
            'after', 'after_or_equal', 'before', 'before_or_equal' => [':date' => in_array($first, ['now', 'today', 'tomorrow', 'yesterday'], true) || self::parseDate($first) !== null ? $first : $this->fieldName($first)],
            default => [],
        };
    }

    private function fieldName(string $field): string
    {
        return $this->translated('validation.attributes.' . $field) ?? self::label($field);
    }

    /**
     * @param array<string, string> $messages
     * @param array<string, string> $replacements
     */
    private function message(array $messages, string $field, string $rule, string $defaultKey, array $replacements): string
    {
        $template = $messages[$field . '.' . $rule] ?? $messages[$rule]
            ?? $this->translated('validation.' . $defaultKey)
            ?? self::MESSAGES[$defaultKey] ?? 'The :field field is invalid.';

        return strtr($template, [':field' => $this->translated('validation.attributes.' . $field) ?? self::label($field)] + $replacements);
    }

    private function translated(string $key): ?string
    {
        return $this->translate === null ? null : ($this->translate)($key);
    }

    private static function label(string $field): string
    {
        return str_replace(['_', '.'], ' ', $field);
    }
}
