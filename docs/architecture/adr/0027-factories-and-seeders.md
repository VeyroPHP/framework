# ADR 0027: Model factories and seeders

- Status: Accepted
- Date: 2026-10-26

## Context

Laravel parity item 8. Laravel ships `HasFactory` (`User::factory()`), Faker and `db:seed`. VeyroPHP's ORM avoids static entry points (ADR 0014).

## Decisions

- **Factories are services:**
  - `$container->make(UserFactory::class)` instead of `User::factory()`, so no static state and no hidden container.
  - Immutable, with `count`, `state` (array or closure), `sequence`, `afterMaking`, `afterCreating`, `make`/`makeOne`, `create`/`createOne`.
  - `create()` runs in one transaction.
  - Attributes go through `forceFill()`.
- **Own `Fake` generator instead of Faker:**
  - Faker is large and was abandoned once (`fzaninotto/faker`).
  - Factories need a few dozen kinds of values, and `Fake` provides them on PHP 8.2's `Random\Randomizer`, seedable for reproducible tests.
  - Names include Indian and international names; emails use only the reserved example domains (RFC 2606), so seeded data cannot mail real people. Phone numbers are Indian mobiles.
  - Applications may still use Faker in `definition()`.
- **Seeders:**
  - Created by the container (constructor injection); `call()` runs others, each once per run.
  - `db:seed` uses the same production guard as `migrate`.
  - `make:seeder` and `make:factory` generators.
- **Starter app:** `UserFactory` (password "password", hashed once per process) and `DatabaseSeeder` (roles everywhere, demo users only locally).

## Verified

- `FakeTest` (4 tests, 5,000+ assertions): reproducibility with a seed, 2,000 unique valid emails on reserved domains, phone/UUID/number/date formats, boolean extremes.
- `FactoryAndSeederTest` (8 tests):
  - make vs. create, and the FILLABLE bypass
  - states, closures, sequences and callbacks together
  - immutability
  - a duplicate email rolling back the whole `create()`
  - each seeder running once with injection
  - `call()` outside the runner
  - `db:seed` errors and the production `--force` guard
- `DeveloperCommandsTest`: `make:model`, `make:factory` and `make:seeder` output loaded and used, so the generated factory creates rows and `db:seed` runs the generated seeder.
- Two starter feature tests: a factory user logs in with "password"; `DatabaseSeeder` adds roles but no demo users outside `local`.

`db:seed` was not run on the owner's local starter database, to avoid adding demo users there.
