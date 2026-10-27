# ADR 0035: The VeyroPHP name

- Status: Accepted
- Date: 2026-10-26

## Context

The owner chose **VeyroPHP** as the framework's final name (intended domain `veyrophp.com`) and asked that no identifier from the earlier working name remain: not in code, data formats, local infrastructure, folder names or documentation. Nothing had been released, so every identifier could change without a compatibility layer.

## Decisions

| Identifier | Value |
| --- | --- |
| Display name | **VeyroPHP** |
| PHP namespaces | `VeyroPHP\`, `VeyroPHP\Tests\`, `VeyroPHP\Bench\`; base exception `VeyroPHP\Exception\VeyroPHPException` |
| Composer packages | `veyrophp/core` (framework; proposed name, not registered on Packagist), `veyrophp/ai`, `veyrophp/app` (starter) |
| Command line | `php veyro` (script `veyro`) |
| Package discovery key | `extra.veyrophp.providers` |
| Environment variables | `VEYROPHP_CONFIG_CACHE`, `VEYROPHP_TEST_*` |
| Session cookie, log file | `veyrophp_session`, `storage/logs/veyrophp.log` |
| Encryption purpose label | `veyrophp:v1:<purpose>` |
| Signed-URL key label (HKDF) | `veyrophp:url-signing` |
| API token prefix | `vyr_` (four characters like before, so the token format and its parser are otherwise unchanged) |
| Local folders | `D:\xampp\htdocs\veyrophp` (framework), `veyrophp-app` (starter, `http://localhost/veyrophp-app/`), `veyrophp-packages`, `veyrophp-clean-app` |
| Test databases | `veyrophp_test` (MariaDB and PostgreSQL); local PostgreSQL user `veyrophp` |

## Consequences

Changing the security labels was a breaking change, made on purpose because there are no real users yet:

- **Encrypted values:** values encrypted before the change can no longer be decrypted.
- **Signed links:** links signed before the change (password reset, email verification) are invalid.
- **API tokens:** tokens issued before the change are rejected.
- **Sessions:** current sessions end once.
- **Queue workers:** a worker started before the change must be restarted by hand.

From the first release on, these labels are part of the backward-compatibility promise and must not change again.

## Verified

See the PROGRESS entries of 2026-10-26 about the name.