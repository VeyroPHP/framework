# ADR 0031: Template layouts and components

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 12. Blade offers `@extends`, `@section`/`@yield`, `<x-component>` with slots and `@stack`. VeyroPHP's views are plain PHP files (ADR for views, Phase 2), and the starter app repeated its HTML frame in every page.

## Decisions

- **No template language and no compiler.** The same ideas are methods on `$view`: `layout`, `section`/`endSection`/`yield`, `component`, `startComponent`/`slot`/`endSlot`/`endComponent`, `push`/`endPush`/`stack`. This means no cache directory, no new syntax to learn or secure, and editors understand the files.
- **Render frames:** each `render()` has its own frame (layout, sections, open blocks). Layouts render after the page and inherit its sections, so nested layouts work. Stacks are shared within one top-level render and reset after it.
- **`HtmlString`** marks HTML produced by templates (`$content`, slots, sections, stacks). `$e()` passes it through and escapes everything else, as Blade does with `Htmlable`.
- **Strict about mistakes:** unclosed or unmatched blocks, invalid names and reserved names (`content`, `slot`, `e`, `view`, `this`) throw `ViewException`. Output buffers are restored to their level before the render on every error.
- Not done:
  - component classes with logic (Blade class components)
  - attribute bags
  - template inheritance with `parent()` content

## Verified

- `LayoutsAndComponentsTest` (7 tests):
  - page in a layout, with text and block sections and a stack
  - nested layouts keeping the page's sections
  - defaults
  - inline and block components with named slots and escaped data
  - stacks filled by components and reset between renders
  - `HtmlString` escaping
  - every misuse, with the output buffer level unchanged
- The existing view tests still pass.
- Starter app:
  - `layouts.app` and an `alert` component
  - login, forgot-password, reset-password and verify-email pages rewritten with them; the reset page's `no-referrer` meta now comes through a `head` section
  - all 29 starter tests pass
  - Apache serves the Hindi login page with `lang="hi"` and the translated title through the layout