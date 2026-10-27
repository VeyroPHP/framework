# Validation, Sessions, Login and Roles

Status: **implemented** (Phase 6, part 1). Design decisions are in [ADR 0015](../architecture/adr/0015-validation-sessions-auth-rbac.md).

## Validation

```php
$data = $validator->validate($request->getParsedBody() ?? [], [
    'name' => 'required|string|max:100',
    'email' => ['required', 'email', new Unique($db, 'users', 'email')],
    'age' => 'nullable|integer|between:18,120',
    'password' => 'required|string|between:8,72|confirmed',
]);
```

Rules:

| Group | Rules |
| --- | --- |
| Presence | `required`, `nullable`, `required_if:field,a,b`, `required_unless:field,a,b`, `required_with:a,b` (when any is filled in), `required_without:a,b` (when any is missing) |
| Types | `string`, `integer`, `numeric`, `boolean`, `array`, `accepted` (yes, on, 1, true: terms checkboxes) |
| Text | `email`, `url` (http/https only), `alpha` (letters in any script), `alpha_num`, `alpha_dash`, `lowercase`, `uppercase`, `starts_with:a,b`, `ends_with:a,b`, `regex`, `uuid`, `ulid`, `json`, `ip`, `ipv4`, `ipv6` |
| Numbers and size | `digits:6` (exactly 6 digits, leading zeros kept: PIN codes, OTPs), `digits_between:10,12` (phone numbers), `min`, `max`, `between`, `size` (characters for text, value for numbers, items for lists), `max_bytes` (UTF-8 bytes, for limits such as bcrypt's 72) |
| Choices and comparisons | `in`, `not_in`, `confirmed`, `same:field`, `different:field` |
| Dates | `date`, `date_format`, `after:today`, `after_or_equal`, `before`, `before_or_equal`: compare with `today`, `tomorrow`, `yesterday`, `now`, a date (`2027-01-31`) or another field (`after:check_in`). Accepted values: `Y-m-d`, HTML date-time input (`2027-01-31T10:30`) and ISO 8601 with an offset. |
| Files | `file`, `image`, `mimes`, `max_size` |
| Objects | `Rule` objects: `Unique`, `Exists`, your own |

Other fields in `required_if`, `same`, `after` and similar rules are named by their full path (`address.country`). Custom messages can target one rule: `'gst_number.required_if' => '...'`.

- Only fields listed in the rules are returned.
- **Nested fields** use dots and come back under the same key: `'address.city' => 'X'`.
  - An array field with rules below it (`address` with `address.city`) is returned with only those sub-keys, so an extra `address.is_admin` in the request is dropped.
  - An `array` field without rules below it is returned as given.
- **`*` matches every item:** `'tags.*' => 'string|max:20'`, `'items.*.id' => 'required|integer'`.
  - Errors name the item (`items.1.id`).
  - `*` over a missing or non-list field matches nothing, so require the list itself (`'items' => 'required|array'`).
  - One `*` rule expands to at most 10,000 paths; bigger input fails with "has too many items". Design: [ADR 0037](../architecture/adr/0037-nested-and-wildcard-validation.md).
- An empty value without `nullable` is an error.
- Failures throw `ValidationException`: 422 with `{"error": {..., "errors": {"field": ["message"]}}}` for JSON clients.
- `Unique` is a convenience check; keep a unique index in the database as well.
- Format rules such as `integer`, `numeric`, `ulid` and `alpha_dash` match the whole value: a trailing newline (`"12\n"`) fails. `string` accepts any text, newlines included.
- `regex` uses your pattern as written. In PHP, `$` also matches before a final newline, so write `/^[A-Z]{3}$/D` (or end with `\z`) when the value must end exactly there.

### Form requests

Put a route's rules in a class (`php veyro make:request StoreEnquiry`) and take it as a handler parameter. The handler runs only when the request is authorized and valid. Design: [ADR 0042](../architecture/adr/0042-validation-rules-and-form-requests.md).

```php
final class StoreEnquiry extends FormRequest
{
    public function __construct(private readonly Gate $gate) {}   // services are injected

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'phone' => 'required|digits:10'];
    }

    public function messages(): array
    {
        return ['phone.digits' => 'Enter a 10-digit mobile number.'];
    }

    public function authorize(): bool                              // false: 403, before validation
    {
        return $this->gate->allows('enquiries.create');
    }

    protected function prepare(array $input): array                // runs before validation
    {
        $input['name'] = trim((string) ($input['name'] ?? ''));

        return $input;
    }
}

$router->post('/enquiries', function (StoreEnquiry $form) {
    $form->validated();           // only the fields with rules
    $form->get('phone');
    $form->request();             // the PSR-7 request
});
```

- Input is the query string for GET and HEAD, and the parsed body plus uploaded files otherwise.
- Invalid input throws `ValidationException`, as `$validator->validate()` does: 422 for JSON clients, or back to the form with `RedirectBackOnValidationError`.
- The handler invoker builds any parameter class that implements `VeyroPHP\Http\FromRequest`; `FormRequest` is one.

### Showing errors on HTML forms (opt-in)

Without help, a failed validation in an HTML form answers with a 422 error page. Add `VeyroPHP\Validation\RedirectBackOnValidationError` after `StartSession` in the middleware list to send the form back instead:

```php
<input name="email" value="<?= $e($old('email')) ?>">
<?php if ($errors->has('email')): ?><p class="error"><?= $e($errors->first('email')) ?></p><?php endif ?>
```

- **Which requests:** only form submissions (POST, PUT, PATCH, DELETE) from clients that do not prefer JSON. JSON clients and GET requests keep the 422.
- **Where it redirects:** with 303 to the `Referer`, only when the Referer has the same scheme, host and port as the request. Otherwise it goes to the fallback path (constructor argument, default `/`). It never redirects to another site.
- **What is not kept for the next request:**
  - uploaded files and objects;
  - every field with a secret-looking name, in any spelling (`api_key`, `api-key`, `apiKey`): names containing `password`, `passwd`, `passphrase`, `secret`, `token`, `apikey`, `appkey`, `privatekey`, `accesskey`, `authorization`, `creditcard` or `cardnumber`, and names with a part `pwd`, `pass`, `otp`, `ssn`, `cvv`, `cvc`, `dsn` or `jwt` (so `user_otp` is kept out but `footprint` is not);
  - the names you pass in `$dontFlash`.
- **What templates get:** `$old('field', $default)` (dot notation for nested fields) and `$errors`, a `VeyroPHP\Validation\ErrorBag` with `any()`, `has()`, `first()`, `get()`, `all()` and `count()`. With the middleware on, `old` and `errors` are reserved view names. Output from `$old()` must still go through `$e()`.

## Sessions and CSRF

Register `CacheServiceProvider` and `SessionServiceProvider`, then add `StartSession` and `VerifyCsrfToken` to the global middleware. Session data is stored in the cache (file or Redis), never in PHP's native session files.

```php
$session->put('cart', $items);
$session->flash('status', 'Saved!');     // next request only
```

Every form that changes something needs the token:

```php
<form method="post" action="profile">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
```

Pass `$request->getAttribute('csrf_token')` to the view as `csrfToken`. AJAX requests can send the `X-CSRF-Token` header instead. A missing or wrong token gives **403**. Exclude machine-to-machine paths in `session.csrf_except`.

## Login

```php
if ($auth->attempt($email, $password)) {
    return $responses->redirect('/dashboard');
}
// Same message for unknown email and wrong password.

$auth->user();      // current user model or null
$auth->check();
$auth->logout();
```

Protect routes with `->middleware(Authenticate::class)`: browsers are redirected to `auth.login_path`, JSON clients get 401.

Passwords: hash with `PasswordHasher::hash()` (bcrypt by default, 72-byte limit enforced; `argon2id` optional). Old hashes are upgraded automatically on login.

### Sessions end when the password changes

At login, the session stores a fingerprint of the user's password hash: an HMAC with a key derived from `APP_KEY`, so the session store never holds the hash itself. On every request `$auth->user()` compares it with the current hash. When the password has changed (a reset, a profile form, `user:create --force`...), every session that logged in with the old password counts as logged out.

- **Keeping the current session after a password change:** call `$auth->login($user)` again after saving the new password. That session gets a new fingerprint; all others end.
- **Hash upgrades:** an automatic upgrade on login (higher cost or a new algorithm) changes the hash too. It ends the user's other sessions once.
- **Rotating `APP_KEY`:** keep the old key in `APP_PREVIOUS_KEYS` and sessions stay valid. Remove it and they end.
- **Deploying this version:** sessions created before it have no fingerprint, so **every user is logged out once** after the upgrade.

### Throttling login attempts

`attempt()` itself does not count failures. Use `LoginThrottle` before it ([ADR 0041](../architecture/adr/0041-remember-me-other-devices-login-throttle.md)):

```php
$ip = $clientIp->of($request);

if (($wait = $throttle->attempt($email, $ip)) !== null) {
    // "Too many attempts, wait $wait seconds" (same answer for unknown emails)
}

if ($auth->attempt($email, $password)) {
    $throttle->clear($email, $ip);
}
```

It counts two limits from `config/security.php` `login`:

- per email + client address (IPv6 by its /64): `max_attempts` 5 per `decay_seconds` 60;
- per email across all addresses: `account_max_attempts` 20 per `account_decay_seconds` 900. Guessing spread over many IPs is slow too.

Add a per-IP ceiling with `ThrottleRequests` on the route. See [hardening.md](hardening.md).

### Remember me

1. Run the migration (`RememberSchema::up()`; the starter has it).
2. Add `RememberMe::class` to the global middleware after `StartSession`.
3. Pass the checkbox to `attempt()`:

```php
$auth->attempt($email, $password, remember: ($data['remember'] ?? null) === '1');
```

After the browser restarts, the first `$auth->user()` logs the user in from the cookie (a new session, a `Login` event).

| Case | Result |
| --- | --- |
| `logout()` | this browser's token and cookie are deleted |
| Password changed or reset | every remembered browser is refused (the token holds the password fingerprint, like sessions) |
| Expired (config `auth.remember.lifetime_days`, 30) | refused; `php veyro auth:prune-remember` deletes old rows (daily in the starter) |
| Tampered or unknown cookie | refused; the row and the cookie are deleted |

The cookie is HttpOnly and `SameSite=Lax`, and it follows the session cookie's `Secure`, path and domain. The table holds only a SHA-256 of the cookie's secret.

### Log out other devices

```php
if ($auth->logoutOtherDevices($request->password)) { ... }   // false for a wrong password
```

It confirms the password, then ends every other session and remembered browser of the user. This session and this browser stay logged in. The `OtherDevicesLoggedOut` event is sent. The starter's dashboard has the form (`POST /logout-other-devices`, limited to 5 per minute).

## Forgot password

`VeyroPHP\Auth\Passwords\PasswordBroker` sends a one-time link and sets the new password. Design: [ADR 0024](../architecture/adr/0024-password-reset-and-verification.md). It needs:

- the `password_reset_tokens` table (`PasswordResetSchema::up()` in a migration),
- `APP_URL` (links in mails are absolute),
- a route named `password.reset` with a `{token}` parameter,
- mail.

The starter app has all of it (`routes/auth.php`).

```php
$status = $broker->sendResetLink($email);              // LinkSent, UserNotFound or Throttled
$status = $broker->reset($email, $token, $newPassword); // PasswordReset or InvalidToken
```

- **Do not reveal accounts:** show the same message for `LinkSent`, `UserNotFound` and `Throttled`. The starter app also rate-limits the form per IP.
- **Tokens:**
  - 32 random bytes.
  - Only their SHA-256 is stored, so a copy of the database cannot reset passwords.
  - A token works once, for `auth.passwords.expire` minutes (60), and only for its own account.
  - A new link replaces the old one.
  - At most one link per account per `auth.passwords.throttle` seconds (60).
- **After a reset:**
  - The old password stops working.
  - Every session that logged in with it ends (see [above](#sessions-end-when-the-password-changes)).
  - The account's API tokens are revoked. Set `auth.passwords.revoke_tokens` to `false` to keep them; nothing is revoked when the tokens table does not exist.
  - `VeyroPHP\Auth\Events\PasswordReset` is dispatched.
- **Mail:** the default mail is `VeyroPHP\Auth\Mail\ResetPasswordMail`. Set your own Mailable with the same constructor in `auth.passwords.mail`. Mails are queued when a queue is registered (`auth.queue_mail`).
- **Cleanup:** expired tokens are deleted by `php veyro auth:clear-resets` (run it from cron, for example daily).
- **Password rules:** validate the new password yourself; the starter app uses `min:8|max:72|max_bytes:72|confirmed`. With bcrypt, `max_bytes:72` is needed: bcrypt reads 72 bytes, and a Hindi or emoji character takes 3-4, so 72 characters can be 288 bytes. Without it, the hasher throws and the request ends in a 500.

## Email verification

`VeyroPHP\Auth\Verification\EmailVerifier` mails a signed link that confirms the address and sets `email_verified_at`:

```php
$verifier->send($user);                     // after registration, or from a "send again" button
$verifier->verify($user, $id, $hash);       // in the link's route (logged in, ValidateSignature)
$verifier->isVerified($user);
```

- **The link:**
  - It contains the user's id and a SHA-256 of the email address.
  - It is signed and expires after `auth.verification.expire` minutes.
  - It only verifies the logged-in user it was made for, and it stops working if the address changes.
- **Protecting pages:** add `EnsureEmailIsVerified` after `Authenticate`. Unverified users are redirected to `auth.verification.notice_path` (`/email/verify`), or get 403 for JSON requests.
- **Event:** `VeyroPHP\Auth\Events\Verified` is dispatched.
- **Existing users:** in the starter app, users created with `user:create` count as verified, because an administrator typed the address.

### Signed links for your own features

`VeyroPHP\Security\UrlSigner` signs any application path. The `ValidateSignature` middleware returns 403 for changed or expired links:

```php
$path = $signer->sign('/invoices/' . $id . '/download', [], time() + 3600);   // add APP_URL for mails
$router->get('/invoices/{id}/download', ...)->middleware(ValidateSignature::class);
```

The key is derived from `APP_KEY`, so rotating the key with `key:generate --force` keeps links valid (old keys stay in `APP_PREVIOUS_KEYS`). A signed link works for whoever has it: keep expiries short and still check permissions where they matter.

## Roles and permissions (RBAC)

Run the migration (`RbacSchema::up()`), then define roles in `config/rbac.php` and run `php veyro rbac:seed`.

```php
$rbac->assignRole($user, 'admin');
$rbac->givePermissionToRole('admin', 'users.view', 'users.manage');
$rbac->grant($user, 'reports.export');   // this user only
$rbac->deny($user, 'users.manage');      // beats every role
$rbac->clearOverride($user, 'users.manage');

$rbac->can($user, 'users.view');         // true / false
$rbac->hasRole($user, 'admin');
$rbac->permissions($user);               // effective list
```

Routes:

```php
$router->get('/admin/users', ...)->middleware(Authenticate::class, RequirePermission::for('users.view'));
$router->get('/admin/settings', ...)->middleware(RequireRole::for('admin'));
```

| Who | Result |
| --- | --- |
| Guest | 401 (or redirect with `Authenticate` first) |
| Logged in, no permission | 403 |
| Role permission, user grant, or super-admin | allowed |
| User denial | 403, even if a role grants the permission |

Prefer permission checks over role checks: permissions can be regrouped into roles without changing code.

### Admin screens (starter app)

The starter app includes screens for managing access, built on `Rbac` (`App\Http\Controllers\Admin\AccessController`):

| Page | Needs | What it does |
| --- | --- | --- |
| `/admin/access` | `users.view` | Users with their roles; prefix search by email or name; cursor pagination |
| `/admin/access/users/{user}` | `users.manage` | A user's roles, and per permission "from roles", "always" (grant) or "never" (deny) |
| `/admin/access/roles` | `roles.manage` | Each role's permissions; create roles and permissions; delete roles |

Rules that stop the screens from being used to gain access:

- Nobody changes their own access.
- Only super-admins give or remove the super-admin role or edit a super-admin.
- Everyone else can only hand out permissions they have themselves (directly or through a role), and cannot edit users or roles that have permissions they lack.
- The last super-admin keeps the role; the super-admin role cannot be deleted.
- Every change is logged with the acting user, the target and the values before and after.

Service methods used by the screens, also available to applications: `allRoles()`, `allPermissions()`, `permissionsByRole()`, `syncRolePermissions()`, `syncRoles()`, `overrides()`, `deleteRole()`, `countUsersWithRole()`, `preload()`.

## Policies and the gate (record-level checks)

RBAC answers "may this user edit posts?". A **policy** answers "may this user edit *this* post?". Check every record a URL names, even when the URL uses a `public_id`. Design: [ADR 0040](../architecture/adr/0040-authorization-policies-and-gate.md).

Create a policy with `php veyro make:policy PostPolicy` (model `App\Models\Post`; another one with `--model=`):

```php
final class PostPolicy
{
    public function view(?User $user, Post $post): bool      // ?User: guests are asked too
    {
        return $post->get('published') === true || $post->get('user_id') === $user?->getKey();
    }

    public function update(User $user, Post $post): bool     // User: guests are denied, never asked
    {
        return $post->get('user_id') === $user->getKey();
    }
}
```

Register rules in a service provider's `boot()`:

```php
$rules = $app->container()->make(AccessRules::class);
$rules->policy(Post::class, PostPolicy::class);
$rules->define('reports.export', fn (User $user): bool => $user->get('is_staff') === true);
$rules->before(fn (?User $user, string $ability): ?bool => $user?->get('banned') === true ? false : null);
```

Check in the handler, after the record is loaded:

```php
$router->post('/posts/{post}', function (Post $post, Gate $gate) {
    $gate->authorize('update', $post);          // guest: 401, not allowed: 403
    // ...
});

$gate->allows('create', Post::class);           // a class name selects the policy
$gate->allows('share', $post, 'email');         // more arguments are passed on
$gate->forUser($other)->denies('update', $post);
```

For abilities without a record, use the middleware: `->middleware(Authorize::for('reports.export'))`.

How a check is decided (the first answer wins):

| Step | Rule |
| --- | --- |
| 1 | `before()` hooks: `true` allows, `false` denies, `null` goes on |
| 2 | RBAC super-admin: allowed |
| 3 | an ability from `define()` |
| 4 | the model's policy method named after the ability; a policy without that public method denies |
| 5 | the RBAC permission of that name (`$gate->allows('posts.edit')`) |
| 6 | denied |

- Rules must return `true` or `false`; anything else throws `AccessException`.
- The 401/403 messages never name the ability or the record.
- In tests and CLI code, use `$gate->forUser($user)`: there is no logged-in user there.

## Starter app commands

```bash
php veyro migrate
php veyro rbac:seed
php veyro user:create "Name" admin@example.com --role=super-admin              # prints a generated password once
echo "chosen-password" | php veyro user:create "Name" u@example.com --password-stdin
```

Passwords are never accepted as command-line arguments, because those end up in shell history.
