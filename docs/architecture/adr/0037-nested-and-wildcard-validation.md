# ADR 0037: Nested and wildcard validation

- Status: Accepted. The owner approved option (a) on 2026-10-26.
- Date: 2026-10-26

## Context

The framework audit found two problems (VQ-017):

- **Unvalidated sub-keys:** a field with the `array` rule was returned whole. With `['address' => 'array', 'address.city' => 'required']`, the result contained `address.is_admin` from the request although no rule allowed it.
- **`*` was silently ignored:** a rule key with `*` (`tags.*`, `items.*.id`) was read as a literal key named `*`. An optional rule never ran, and a required one always failed.

The Laravel migration guide tells porting developers that the rule syntax is the same, so code using `*` lost its checks without any error.

## Decision

1. **Output keeps its shape.**
   - Validated fields are still returned under their rule keys, nested ones flat: `'address.city' => 'X'`.
   - Code written for 0.1.0-dev keeps working.
2. **A parent array holds only validated sub-keys.**
   - A field that is an array and also has rules below it (`address` and `address.city`, or `items` and `items.*.id`) is returned rebuilt from those sub-keys only.
   - An `array` field without rules below it is still returned as given. Add rules below it to restrict it.
3. **`*` matches every key at its level.**
   - The rule is expanded against the input before validation: `items.*.id` becomes `items.0.id`, `items.1.id`, ...
   - Errors and validated values use the expanded keys, so a client can tell which item failed (`items.1.id`).
   - Several `*` in one key expand in turn.
   - A `*` over something that is not an array matches nothing. Require the list itself with its own rule (`'items' => 'required|array'`).
4. **Limits against oversized input.**
   - Each rule key with `*` expands to at most `Validator::MAX_WILDCARD_PATHS` (10,000) paths.
   - Above that the field fails with "The :field field has too many items." The request is rejected before the expanded rules run.
   - The limit is per rule, not per call. A first draft limited the whole call, but that rejected a valid list of 10,000 items with two rules each. The number of rules is fixed by the developer, so the total work stays bounded.
   - Nesting depth is bounded by the input, which the JSON parser already limits (depth 64).
5. **No new rule syntax.** Only `*` is added, with the meaning described above.

## Consequences

- **Behaviour change:**
  - An `array` field that has rules below it no longer returns sub-keys without rules.
  - Rule keys with `*` now validate instead of being ignored. Input that passed unnoticed may now fail.
  - Both are listed in the CHANGELOG.
- **Cost:** expansion is linear in the number of matched items. The performance test measures 10,000 items.
- **Not done:**
  - a `*` inside the parameter of `same` (`same:items.*.x` is read as a literal path). Update 2026-10-27 (HTTP-4): `confirmed` now reads the confirmation next to the field, so `account.password` and `users.*.pin` are confirmed by `account.password_confirmation` and `users.0.pin_confirmation`;
  - a nested output format.
