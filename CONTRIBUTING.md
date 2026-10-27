# Contributing

Thanks for your interest in VeyroPHP. The project is in an early phase, so the rules below matter more than the number of features.

## Before you start

- Read [docs/ROADMAP.md](docs/ROADMAP.md) and [docs/architecture/overview.md](docs/architecture/overview.md).
- For a change to architecture or a public API, open an issue first. Significant decisions are recorded as ADRs in [docs/architecture/adr/](docs/architecture/adr/).

## Workflow

1. `composer install`
2. Make one small, coherent change.
3. Add or update tests, including failure paths.
4. Run `composer check` and make sure it passes.
5. Update the docs and `CHANGELOG.md` when behavior changes.

## Rules

- Every PHP file starts with `declare(strict_types=1);`.
- Code style is PER-CS 2.0, enforced by PHP-CS-Fixer.
- PHPStan runs at level `max`. Do not add baseline entries or ignore rules to make it pass without discussion.
- No hidden global state and no static facades in the core.
- Exceptions thrown by the framework implement `VeyroPHP\Exception\VeyroPHPException`.
- Do not add a runtime dependency without following [ADR 0003](docs/architecture/adr/0003-dependency-policy.md).
- Never claim a test passed or a benchmark result unless it was actually run.

## Where code comes from

VeyroPHP is released under the MIT license. Submit only code that you wrote or that you have the right to contribute under that license.

- **Write your own code.** Do not copy code, comments, docblocks, tests or user-facing messages from other frameworks or libraries (Laravel, Symfony, CodeIgniter, Guzzle and others), from tutorials or from Q&A sites.
- **Learning from other projects is fine.** Read them to understand a problem, then write your own implementation, working from specifications (RFCs, the PHP manual) where they exist. Say in the pull request which projects or documents you consulted.
- **Familiar method names are fine.** Many PHP frameworks share names such as `where()` and `firstOrFail()`. The implementation, comments and messages must still be your own.
- **You are responsible for everything you submit, including code from tools.** If a code generator or AI assistant produced part of the change, review that part as you would your own code. Check that it does not reproduce existing code, and say in the pull request that a tool was used. A tool's output is not automatically free of other people's rights.

### Including third-party code

Open an issue before adding code you did not write. A maintainer reviews each case individually before it is accepted.

1. **Identify the license and its obligations.** Name the exact license (an SPDX identifier where possible). Note what it requires, such as keeping copyright notices, shipping a NOTICE file or stating changes.
   - **Common permissive licenses** (MIT, BSD-2-Clause, BSD-3-Clause, ISC, Apache-2.0) are often usable in an MIT project, but each case is still checked.
   - **Any other license is flagged for review** under the project's policy. This includes copyleft licenses such as GPL, LGPL or AGPL, licenses with field-of-use or non-commercial limits, and code with an unclear or missing license. Do not assume such code can be included, or that it cannot; wait for the maintainer's decision.
   - If the maintainer is unsure, the question is referred for legal advice before the code is merged.
2. **Keep existing notices.** Never remove or change the original copyright and license text.
3. **Record the source.** In the pull request, give the source URL, the exact version or commit, and the license. Once accepted, the same details go into a `NOTICE` file at the repository root; the file is created when the first such entry is needed.
4. **Prefer a Composer dependency to a copy,** following [ADR 0003](docs/architecture/adr/0003-dependency-policy.md). Dependencies are reviewed the same way. `composer licenses` lists the declared license of each installed package; a declared license still needs to be checked against the package's own files.

## Sign-off

Contributors are asked to end each commit message with a `Signed-off-by` line (`git commit -s` adds it):

```
Signed-off-by: Your Name <you@example.com>
```

The line means that you certify the statements in the [Developer Certificate of Origin 1.1](https://developercertificate.org/) for that commit. In short, you have the right to submit the change under the project's license.

**Sign-off is requested, but nothing enforces it.** No DCO bot, CI check or Git hook is configured in this repository, so a commit without the line is not rejected automatically.

## Reporting security issues

Do not open public issues for vulnerabilities. Follow [SECURITY.md](SECURITY.md).
