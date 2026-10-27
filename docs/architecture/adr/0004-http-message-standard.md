# ADR 0004: HTTP message standard

- Status: Accepted
- Date: 2026-10-25 (accepted by the project owner at the start of Phase 2)

## Context

The HTTP kernel needs request and response objects. The plan asks for PSR-7, PSR-15 and PSR-17 compatibility "where it adds value", and for clear adapter boundaries if native abstractions are used.

## Options

1. **PSR-7 messages and PSR-15 handlers throughout, with an existing PSR-7 implementation** (for example `nyholm/psr7`, which is small, has no runtime dependencies besides PSR interfaces, and is MIT-licensed). Users can swap the implementation through PSR-17 factories.
   - Pros: interoperability with the existing PSR-15 middleware ecosystem; no need to write and maintain a message implementation.
   - Cons: PSR-7 immutability creates copies per `with*()` call; the PSR-7 API is verbose for common tasks such as reading JSON input.
2. **Native request/response classes, with PSR-7 adapters at the edge.**
   - Pros: full control over API ergonomics and performance.
   - Cons: more code to maintain and secure; PSR-15 middleware needs conversion.
3. **Own PSR-7 implementation.**
   - Pros: no third-party runtime package.
   - Cons: reinvents a mature standard, against the plan's principles.

## Recommendation

Option 1. Kernel and middleware use PSR-7, PSR-15 and PSR-17 interfaces. Developer-friendly helpers (JSON body parsing, typed query access, JSON responses) are provided as small functions or decorators rather than a parallel message hierarchy. The cost of immutability is measured in the Phase 2 routing and middleware benchmarks; if it turns out significant, a native fast path can be proposed in a new ADR.

## Decision

Option 1. Runtime dependencies added: `nyholm/psr7` ^1.8, `nyholm/psr7-server` ^1.1 (request creation from globals), `psr/http-message` ^2.0, `psr/http-factory` ^1.0, `psr/http-server-handler` ^1.0 and `psr/http-server-middleware` ^1.0. All are MIT-licensed and depend only on PSR interface packages.

The cost of immutability has not been measured yet. Benchmarks are part of the Phase 4 performance baseline; if the cost turns out significant, a native fast path will be proposed in a new ADR.
