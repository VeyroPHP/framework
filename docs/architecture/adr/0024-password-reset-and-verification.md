# ADR 0024: Password reset and email verification

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 5. Login existed, but users who forgot their password needed an administrator, and email addresses were never confirmed. Both need mail (ADR 0022), and verification links need signing (Laravel: `URL::signedRoute`).

## Decisions

- **Reset tokens:**
  - 32 random bytes; only the SHA-256 is stored. Laravel stores a bcrypt hash, which is slower and gains nothing for random tokens.
  - Compared with `hash_equals`.
  - One row per account, keyed by the user's primary key, not by the typed email. So "Asha@Example.com" and "asha@example.com" share the throttle and the token.
  - Single use; valid for 60 minutes; one link per account per 60 seconds.
  - Every failure gives `InvalidToken`, so the reset form does not reveal accounts either.
- **Signed URLs** (`UrlSigner`):
  - HMAC-SHA256 over the application path and the sorted query.
  - Not over scheme and host, so links work behind proxies and in subdirectory installs, which the owner's XAMPP setup uses.
  - The key is derived with HKDF from `APP_KEY` (separate from the encryption key); previous keys are accepted.
  - The expiry is signed.
- **Verification links** contain the user id and a SHA-256 of the lowercased address (Laravel uses SHA-1). They require the logged-in user to match, so a forwarded link cannot verify someone else's account, and a changed address invalidates old links.
- **Mails:**
  - Plain Mailables with escaped HTML, replaceable through config.
  - Absolute links need `APP_URL`. A missing URL is an error with a clear message, not a broken link.
  - Queued when a queue is registered, so a slow SMTP server does not show in the response time (and cannot hint which accounts exist).
- **Starter app:** routes in `routes/auth.php`, per-IP throttling on all forms, `no-referrer` on the reset page (the token is in its URL), and a dashboard banner instead of blocking unverified users. `user:create` marks addresses as verified.
- Not done:
  - Ending other sessions after a password change (sessions are not indexed by user).
  - Registration pages.
  - "Confirm password" before sensitive actions.

## Verified

- `UrlSignerTest` (8 tests) covers:
  - signing and verifying, independent of parameter order
  - every kind of tampering (path, changed, added or array parameters, removed or extended expiry, other signature, other key)
  - expiry
  - previous keys
  - the derived key
  - the middleware with a subdirectory request
- `PasswordResetAndVerificationTest` (8 tests) runs through real HTTP routes and the array mailer:
  - the mail and its absolute link, and that only the hash is stored
  - a single use, after which the old password fails and the new one works
  - an event
  - another user's token, guessed, empty, unknown-user and expired tokens all rejected
  - throttling with no mail for unknown users
  - the missing-`APP_URL` error
  - the verification flow with the middleware redirect and JSON 403
  - another user's link, a changed id, an extended expiry and a changed email address all rejected
  - an expired verification link
- A mutation check (removing the email-hash comparison) makes the changed-address test fail.
- The starter app has 4 new feature tests (the full forgot-password flow, the same answer for unknown emails, invalid tokens and mismatched passwords, and the verification flow).
- The flow was also run by hand through Apache in the `/veyrophp-app` subdirectory: form, generic message, logged mail, and the link opening the reset page.

Found while building: `TestClient` did not fill the query parameters from the URI, so no route test could read `?email=` or check a signature. It now behaves like a web server, and has a test for it.