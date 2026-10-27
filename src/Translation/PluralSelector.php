<?php

declare(strict_types=1);

namespace VeyroPHP\Translation;

/**
 * Picks the form of a pluralized message for a count.
 *
 *     "apple|apples"                                  one form for 1, another for the rest (by language)
 *     "{0} No messages|{1} One message|[2,*] :count messages"
 *     "{0} कोई संदेश नहीं|[1,*] :count संदेश"
 *
 * Explicit forms ({n}, {a,b}, [a,b], [a,*], [*,b]) win; otherwise the
 * language's rule chooses between the remaining forms:
 * - "one" for 1 only: English, German, Dutch, Swedish, Italian, Spanish, ...
 * - "one" for 0 and 1: Hindi, Bengali, Gujarati, Marathi, French, Brazilian Portuguese
 * - a single form: Japanese, Chinese, Korean, Thai, Indonesian, Vietnamese
 *
 * @internal used by Translator
 */
final class PluralSelector
{
    private const ZERO_IS_ONE = ['hi', 'bn', 'gu', 'mr', 'pa', 'fr', 'pt_BR', 'pt-BR', 'am', 'kn'];

    private const NO_PLURAL = ['ja', 'zh', 'ko', 'th', 'id', 'ms', 'vi', 'tr', 'fa'];

    public static function choose(string $message, int $count, string $locale): string
    {
        $forms = explode('|', $message);
        $plain = [];

        foreach ($forms as $form) {
            $form = trim($form);

            if (preg_match('/^\{\s*(-?\d+(?:\s*,\s*-?\d+)*)\s*\}\s?(.*)$/sD', $form, $m) === 1) {
                if (in_array($count, array_map('intval', explode(',', $m[1])), true)) {
                    return $m[2];
                }

                continue;
            }

            if (preg_match('/^\[\s*(\*|-?\d+)\s*,\s*(\*|-?\d+)\s*\]\s?(.*)$/sD', $form, $m) === 1) {
                $from = $m[1] === '*' ? PHP_INT_MIN : (int) $m[1];
                $to = $m[2] === '*' ? PHP_INT_MAX : (int) $m[2];

                if ($count >= $from && $count <= $to) {
                    return $m[3];
                }

                continue;
            }

            $plain[] = $form;
        }

        if ($plain === []) {
            return $forms[count($forms) - 1];
        }

        return $plain[min(self::index($count, $locale), count($plain) - 1)];
    }

    private static function index(int $count, string $locale): int
    {
        $language = strtolower(substr($locale, 0, 2));

        return match (true) {
            in_array($locale, self::ZERO_IS_ONE, true), in_array($language, self::ZERO_IS_ONE, true) => $count === 0 || $count === 1 ? 0 : 1,
            in_array($language, self::NO_PLURAL, true) => 0,
            default => $count === 1 ? 0 : 1,
        };
    }
}
