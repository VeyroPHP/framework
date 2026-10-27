# ADR 0029: Translation

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 10. The owner's market is India, where Hindi (and other Indian languages) matter. Laravel offers `__()`, `trans_choice()`, PHP and JSON language files, and validation messages per language.

## Decisions

- **Files like Laravel's:**
  - PHP groups (`lang/hi/auth.php`) for keys, and JSON (`lang/hi.json`) for sentence keys. Flat keys containing dots are found as well as nested ones.
  - Locale and group names are validated, so a key can never load a file outside `lang/`.
- **Per-request translator.** `Translator` is scoped (it holds the request's locale), and the loader is a singleton cache, so files are read once per process.
- **Templates get helpers through `View::share()`** (new). `$t`, `$tc` and `$locale` resolve the current request's translator on each call. Shared names cannot be shadowed by template data.
- **Plural rules** cover the languages relevant here: Hindi and its neighbours (and French) treat 0 as singular; English-like languages don't; CJK has one form. Explicit `{n}` and `[a,b]` forms work for every language. Full CLDR rules (Arabic, Russian) are left to explicit forms.
- **`SetLocale` middleware:**
  - The order is query, session, `Accept-Language` (with q-values; `q=0` excludes), then the default.
  - Only supported locales are ever set.
  - `Content-Language` is added to the response.
- **Validation** got an optional translation hook (`validation.<rule>`, `validation.attributes.<field>`). The provider binds `Validator` with it, and existing custom messages still take precedence.
- **`lang:missing`** compares every locale with the fallback and fails when keys are missing, for CI.
- Not done:
  - locale-aware number, date and currency formatting (needs ext-intl)
  - right-to-left layout helpers
  - translation of framework error pages

## Verified

- `TranslationTest` (14 tests):
  - group, nested, flat and JSON keys; fallback; unknown keys and text
  - `:name`/`:Name`/`:NAME`, and `:name` vs `:name_full`
  - English and Hindi plurals, and seven selector cases
  - path-traversal names rejected
  - every `SetLocale` source over HTTP, including q-values, `q=0`, unsupported values and session memory
  - view helpers with escaping, and protection of shared names
  - translated validation messages and attributes (also flat keys with dots), with custom messages still winning
  - `lang:missing`
- Starter app:
  - complete English and Hindi files for the login page and all validation messages (`lang:missing`: complete, 41 keys)
  - two feature tests: Hindi login page, remembered language, Hindi validation and credential errors, browser language
  - Apache shows the Hindi login page with `?lang=hi` and `Content-Language: hi` for `Accept-Language: hi`
