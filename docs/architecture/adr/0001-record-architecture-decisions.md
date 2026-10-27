# ADR 0001: Record architecture decisions

- Status: Accepted
- Date: 2026-10-25

## Context

VeyroPHP is meant to become a long-lived open-source framework. Decisions about public APIs, dependencies and module boundaries are hard to reverse, and contributors need to know why they were made.

## Decision

Significant decisions are recorded as numbered Markdown files in `docs/architecture/adr/`, using the sections Context, Options, Decision and Consequences. A decision is significant if it affects a public API, adds a runtime dependency, changes module boundaries, changes supported environments or has security impact.

An ADR is never edited to reverse its decision. A new ADR supersedes it, and the old one's status is changed to "Superseded by ADR NNNN".

## Consequences

- Reviewers can check a change against recorded decisions.
- Writing ADRs takes time; small, local decisions do not need one.
