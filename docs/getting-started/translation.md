# Translation

Show the same application in several languages. Design: [ADR 0029](../architecture/adr/0029-translation.md).

## Setup

Register `VeyroPHP\Translation\TranslationServiceProvider`, and add `SetLocale` to the global middleware after `StartSession`. The starter app does both. In `config/app.php`:

```php
'locale' => 'en',               // default language
'fallback_locale' => 'en',      // used when a translation is missing
'locales' => ['en', 'hi'],      // languages visitors may get
```

## Language files

```
lang/
  en/auth.php        return ['failed' => 'The email address or password is incorrect.', 'login' => ['title' => 'Log in']];
  hi/auth.php        return ['failed' => 'ये विवरण हमारे रिकॉर्ड से मेल नहीं खाते।', 'login' => ['title' => 'लॉग इन करें']];
  hi.json            {"Welcome back, :name": "वापसी पर स्वागत है, :name"}
```

Keys like `auth.login.title` come from the PHP files: the file name, then the keys inside, with dots for nested arrays (a flat key containing dots works too). Any other text is looked up in `<locale>.json`, and the text itself is shown when there is no translation.

## Using translations

In templates, `$t()`, `$tc()` and `$locale()` are always available:

```php
<html lang="<?= $e($locale()) ?>">
<h1><?= $e($t('auth.login.title')) ?></h1>
<p><?= $e($t('Welcome back, :name', ['name' => $user->name])) ?></p>
<p><?= $e($tc('cart.items', $count)) ?></p>
```

Always escape with `$e()`: translations and replacements are not escaped.

In PHP code, inject `VeyroPHP\Translation\Translator`:

```php
$translator->get('auth.throttle', ['seconds' => 60]);
$translator->choice('cart.items', $count);
$translator->locale();             // the current request's language
$translator->setLocale('hi');      // for example for a user's saved preference
```

- **Placeholders:** `:name` is replaced as given, `:Name` with a capital first letter, `:NAME` in capitals.
- **Missing translations:** first the fallback locale is used, then the key itself is shown.

## Plurals

```php
'items' => '{0} Your cart is empty|{1} One item|[2,*] :count items',
'apples' => 'apple|apples',
```

- **Explicit forms** match exact numbers (`{0}`, `{1,2,3}`) or ranges (`[2,*]`, `[*,0]`).
- **Plain forms** follow the language's rule:
  - English, German, Spanish, Italian and similar: singular for 1 only.
  - Hindi, Bengali, Gujarati, Marathi, French: singular for 0 and 1.
  - Japanese, Chinese, Korean and similar: one form.
- `:count` is filled in automatically.

## Choosing the language

`SetLocale` picks, in this order:

1. `?lang=hi` in the URL, remembered in the session. The starter's login page has "English · हिन्दी" links.
2. The session key `locale`, for example from a language menu or the user's profile: `$session->put(SetLocale::SESSION_KEY, 'hi')`.
3. The browser's `Accept-Language` header, with priorities respected.
4. `app.locale`.

Only languages in `app.locales` are ever used. The response carries `Content-Language`.

## Validation messages

With the provider registered, validation messages come from `lang/<locale>/validation.php` when present, and field names from its `attributes` array:

```php
'required' => ':field भरना ज़रूरी है।',
'attributes' => ['email' => 'ईमेल'],
```

Messages passed to `validate()` still win. The starter app ships complete English and Hindi validation files.

## Finding missing translations

```bash
php veyro lang:missing      # exit code 1 when keys are missing
```

This compares every language with the fallback language and lists missing keys. Run it in CI.
