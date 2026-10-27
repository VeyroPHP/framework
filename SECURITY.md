# Security Policy

## Supported versions

VeyroPHP has no released version yet. Until `0.1.0`, only the `main` branch receives fixes, and the code is not intended for production use.

## Reporting a vulnerability

Please do not report security problems in public issues.

Use GitHub's private vulnerability reporting ("Report a vulnerability" under the repository's Security tab) once the repository is public. Include:

- the affected version or commit,
- steps to reproduce,
- the impact you believe it has.

We aim to acknowledge reports within 7 days. As a pre-alpha project we cannot promise fix timelines.

## Scope

Security behavior the core provides, and what applications must configure themselves, will be documented in `docs/security/` as each module is built. Phase 0 includes one security-relevant behavior: `Application::basePath()` rejects absolute paths, null bytes and `..` segments.
