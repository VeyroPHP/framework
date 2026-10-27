<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use VeyroPHP\Support\Extensions;

/**
 * Finds output in PHP templates that is not escaped. Templates are only
 * tokenized, never run.
 *
 * Output (<?=, echo, print) is accepted when it is:
 *
 * - $e(...), or $view->render/component/endComponent/yield/stack/url/json/attr/escape(...)
 *   as the whole expression;
 * - $content or $slot (HTML the renderer itself produced);
 * - literals only, a cast to int/float/bool, or a ternary whose branches are accepted.
 *
 * Anything else is an error. $e(...) inside href, src and other URL attributes is
 * a warning: $view->url() also blocks javascript: and similar schemes there.
 * Output containing the comment "view:lint-ignore" is skipped.
 */
final class TemplateLinter
{
    private const VIEW_METHODS = ['render', 'component', 'endcomponent', 'yield', 'stack', 'url', 'json', 'attr', 'escape'];

    private const RENDERED_VARIABLES = ['$content', '$slot'];

    private const LITERALS = [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER];

    private ?string $urlAttribute = null;

    /**
     * @return list<array{line: int, expression: string}>
     */
    public function errors(string $template): array
    {
        return $this->analyse($template)[0];
    }

    /**
     * @return list<array{line: int, expression: string}>
     */
    public function warnings(string $template): array
    {
        return $this->analyse($template)[1];
    }

    /**
     * Matches HTML ending inside a URL attribute (`href="`), built from the list that
     * View::attr() escapes as URLs, so the two cannot drift apart.
     */
    private function urlAttributePattern(): string
    {
        return $this->urlAttribute ??= '/\b(' . implode('|', array_map(static fn(string $a): string => preg_quote($a, '/'), View::URL_ATTRIBUTES)) . ')\s*=\s*["\']?$/iD';
    }

    /**
     * @return array{list<array{line: int, expression: string}>, list<array{line: int, expression: string}>}
     */
    private function analyse(string $template): array
    {
        Extensions::require('tokenizer', 'the template linter (view:lint)');
        $tokens = token_get_all($template);
        $errors = [];
        $warnings = [];
        $html = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_INLINE_HTML) {
                $html = $token[1];

                continue;
            }

            if (!in_array($token[0], [T_OPEN_TAG_WITH_ECHO, T_ECHO, T_PRINT], true)) {
                continue;
            }

            $line = $token[2];
            $context = $token[0] === T_OPEN_TAG_WITH_ECHO ? $html : '';
            [$expressions, $i] = $this->expressions($tokens, $i + 1, $token[0] !== T_PRINT);

            foreach ($expressions as $expression) {
                if ($this->ignored($expression)) {
                    continue;
                }

                $code = self::code($expression);
                $finding = ['line' => $line, 'expression' => self::text($expression)];

                if (!$this->safe($code)) {
                    $errors[] = $finding;
                } elseif (preg_match($this->urlAttributePattern(), $context) === 1 && self::isCallOf($code, '$e')) {
                    $warnings[] = $finding;
                }
            }
        }

        return [$errors, $warnings];
    }

    /**
     * The expressions of one output statement, up to ";" or "?>", split at top-level commas.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{list<list<array{0: int, 1: string, 2: int}|string>>, int} expressions and the index of the last token used
     */
    private function expressions(array $tokens, int $start, bool $commas): array
    {
        $expressions = [];
        $current = [];
        $depth = 0;
        $i = $start;

        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && ($text === ';' || (is_array($token) && $token[0] === T_CLOSE_TAG))) {
                break;
            }

            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($commas && $depth === 0 && $text === ',') {
                $expressions[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        $expressions[] = $current;

        return [$expressions, $i];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens tokens without whitespace and comments
     */
    private function safe(array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        $first = $tokens[0];

        if (count($tokens) === 1 && is_array($first) && $first[0] === T_VARIABLE && in_array($first[1], self::RENDERED_VARIABLES, true)) {
            return true;
        }

        if (array_filter($tokens, static fn($t): bool => !(is_array($t) && in_array($t[0], self::LITERALS, true)) && $t !== '.') === []) {
            return true;
        }

        if (is_array($first) && in_array($first[0], [T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST], true)) {
            return true;
        }

        if (self::isCallOf($tokens, '$e')) {
            return true;
        }

        if (is_array($first) && $first[0] === T_VARIABLE && $first[1] === '$view'
            && ($tokens[1] ?? null) !== null && is_array($tokens[1]) && $tokens[1][0] === T_OBJECT_OPERATOR
            && is_array($tokens[2] ?? null) && in_array(strtolower($tokens[2][1]), self::VIEW_METHODS, true)
            && ($tokens[3] ?? null) === '(' && self::closing($tokens, 3) === count($tokens) - 1) {
            return true;
        }

        $ternary = self::ternary($tokens);

        return $ternary !== null && $this->safe($ternary[0]) && $this->safe($ternary[1]);
    }

    /**
     * Whether the tokens are exactly one call of the variable $name, such as $e(...).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isCallOf(array $tokens, string $name): bool
    {
        return is_array($tokens[0] ?? null) && $tokens[0][0] === T_VARIABLE && $tokens[0][1] === $name
            && ($tokens[1] ?? null) === '(' && self::closing($tokens, 1) === count($tokens) - 1;
    }

    /**
     * Index of the bracket closing the one at $open, or -1.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($index < $open) {
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true) && --$depth === 0) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * The two value branches of a top-level "condition ? a : b", or null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{list<array{0: int, 1: string, 2: int}|string>, list<array{0: int, 1: string, 2: int}|string>}|null
     */
    private static function ternary(array $tokens): ?array
    {
        $depth = 0;
        $question = null;
        $nested = 0;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $text === '?') {
                if ($question === null) {
                    $question = $index;
                } else {
                    $nested++;
                }
            } elseif ($depth === 0 && $text === ':' && $question !== null) {
                if ($nested > 0) {
                    $nested--;

                    continue;
                }

                $then = array_slice($tokens, $question + 1, $index - $question - 1);
                $else = array_slice($tokens, $index + 1);

                return $then === [] ? null : [$then, $else];
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function ignored(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && str_contains($token[1], 'view:lint-ignore')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function code(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn($t): bool => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function text(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            if (!(is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))) {
                $text .= is_array($token) ? $token[1] : $token;
            }
        }

        return trim($text);
    }
}
