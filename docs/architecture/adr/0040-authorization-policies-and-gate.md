# ADR 0040: Authorization with policies and a gate

- Status: Accepted
- Date: 2026-10-27

## Context

RBAC ([ADR 0015](0015-validation-sessions-auth-rbac.md), `Rbac::can()`) answers "may this user edit posts?". It cannot answer "may this user edit *this* post?", which almost every application needs: owners edit their own records, editors edit published ones, guests view public ones. ROADMAP's public-identifier section requires an authorization check on every record a URL names. Without a framework place for that check, applications write it inline in each handler and forget it in some.

## Decisions

### Two objects: rules once, checks per request

- `AccessRules` (singleton) holds the application's rules: `define()` for abilities that need no record, `policy()` to map a model class to a policy class, and `before()` hooks. Providers register rules in `boot()`.
- `Gate` (scoped) checks them for the current user: `allows()`, `denies()`, `authorize()`, `forUser()`. It reads the user through a closure (`Auth::user()`), so a login during the request is seen at once.
- Not chosen:
  - One global gate holding both rules and the user. It would keep the user in a singleton, which goes stale in long-running processes.
  - Static facades. The rest of the framework is explicit dependencies.

### Order of a check, and deny by default

1. `before()` hooks, in registration order: `true` allows, `false` denies, `null` continues.
2. With RBAC registered, the super-admin role allows everything. This matches `Rbac::can()`, so the two never disagree about super-admins.
3. A defined ability.
4. The policy of the first argument (a model, or a model class name for checks such as "create"). The method named after the ability decides. A policy without that public method **denies**: it does not fall through to RBAC, so adding a policy never widens access through a permission of the same name.
5. With RBAC registered, the RBAC permission of that name. `$gate->allows('posts.edit')` and `RequirePermission` then agree.
6. Otherwise **denied**. A typo in an ability name denies; it never allows.

Hooks run before the super-admin rule, so an application can still block a destructive ability for everyone.

### Guests

- The user is `null` for guests.
- A rule whose first parameter does not accept null is **not called** for guests: they are denied. Policies say whether guests may act by the type they write (`?User $user`), not by a check each method has to remember.
- `authorize()` throws `AuthorizationException`, a `HttpException`: 401 for guests, 403 for users. The message is fixed and never names the ability or the record, so a 403 does not reveal that a record exists.

### Rules must return bool

A rule that returns anything else throws `AccessException` (a programming error). Treating `null`, `1` or a string as an answer would make a missing `return` an allow or a deny by accident.

### Middleware only for checks without a record

`Authorize::for('reports.export')` covers abilities that need no record. Route model binding happens when the handler is called, after middleware, so record checks belong in the handler: `$gate->authorize('update', $post)`. A middleware that loaded records itself would load them twice and bypass the ORM's scopes.

### Not in this version

- Policy auto-discovery by naming convention: explicit `policy()` keeps the mapping visible.
- Response objects with custom denial messages: the fixed messages are deliberate (see Guests).
- Template helpers: templates receive what the handler passes; a `$can` helper can follow if the starter needs it.

## Consequences

- Applications get one place for record-level checks, testable without HTTP (`forUser()`).
- RBAC permissions keep working through the gate, and RBAC stays optional.
- `make:policy` creates a policy whose methods show the guest rule in their types.
