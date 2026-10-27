# ADR 0042: More validation rules and form requests

- Status: Accepted
- Date: 2026-10-27

## Context

The validator had 30 rules. Everyday forms needed more:

- conditional fields ("GST number if the customer is a company");
- dates in the future, or after another date;
- exact digit counts: PIN codes, OTPs, phone numbers;
- terms checkboxes;
- UUIDs and JSON.

Applications worked around the gaps with `regex` or hand-written checks. Rules also lived inline in handlers, repeated between a form route and its API twin.

## Decisions

### Conditional "required" is a presence rule

`required_if`, `required_unless`, `required_with` and `required_without` are decided where `required` is, before the other rules.

- When they apply, an empty or missing field fails with the "required" message. Custom messages can target the rule (`field.required_if`).
- When they do not apply, the field behaves like any optional field: skipped when missing, validated when present.
- Other fields are named by their full path. Resolving relative paths inside `*` rules would be guesswork; full paths are explicit.
- `required_if` compares the other field's value as text. Booleans compare as `1` and `0`, so `required_if:agree,1` works for JSON `true`.

### Dates: a short list of exact formats, and a clock

Date rules accept:

- `Y-m-d`;
- the two HTML date-time input forms;
- `Y-m-d H:i:s`;
- ISO 8601 with an offset.

Each format must round-trip exactly, as `date_format` already requires. So `2027-02-30` and free text such as "next week" fail. `strtotime()` was not used: it accepts almost anything and its result depends on the current time.

The reference is `today`, `tomorrow`, `yesterday` or `now` in the application's time zone, a date, or another field. `Validator` takes an optional clock (`Closure(): int`), so tests can pin "today".

### Formats added

`accepted`, `alpha`, `alpha_num`, `digits`, `digits_between`, `uuid`, `json`, `ip`, `ipv4`, `ipv6`, `lowercase`, `uppercase`, `different`, `starts_with`, `ends_with`, `size`.

- `alpha` and `alpha_num` are Unicode-aware (Hindi names pass).
- `digits` works on the text, so leading zeros count (`0042` is 4 digits) and signs or decimals fail.
- Every rule has a default English message, and the starter has the Hindi messages.

### Form requests are built by the handler invoker

`FormRequest` is an abstract class:

- `rules()`;
- optional `messages()`;
- `authorize()` (false gives 403, checked before validation, so an unauthorized client learns nothing about the rules);
- `prepare()` (normalises input first).

The handler invoker builds any parameter type implementing `VeyroPHP\Http\FromRequest` through its static `fromRequest()`. The interface lives in `Http`, which both Routing and Validation already depend on, so neither gains a dependency on the other.

The container creates the form, so it can take services (a `Gate`, a `Connection` for `Unique`). Validation uses the container's `Validator`, so translations apply.

Not chosen:

- Validating in a middleware: middleware runs before the handler is known.
- Making the form request a PSR-7 request decorator: its job is the validated data, not the transport.

## Consequences

- Existing rule strings behave as before. The only change to old behaviour: `same` now shows the translated name of the other field.
- `make:request` creates a form request in `app/Http/Requests`.
