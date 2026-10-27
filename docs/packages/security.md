# Package trust and security

A package being installable, or listed on Packagist, says nothing about whether it is safe. VeyroPHP never treats a package as trusted because it can be discovered or installed. This page explains what VeyroPHP does, what it cannot do, and what to check yourself.

## What happens when you install a package

| Step | Code from the package runs? |
| --- | --- |
| `composer require` | **Possibly:** Composer plugins in the package or its dependencies run during installation, and the root project's scripts run. Library scripts do not run. |
| `php veyro package:discover` | **No.** It only reads `vendor/composer/installed.json`. |
| Every request / command | **Yes.** Discovered service providers are registered, so their `register()` and `boot()` code runs, and everything they bind can run. |

So installing a package means trusting its code and the code of all its dependencies, as with any PHP framework.

## Before installing

1. **Who maintains it?** Check the repository: recent commits, open security issues, a security policy, how many people use it. Packagist shows download counts and links.
2. **Is it abandoned?** Packagist and `composer audit` mark abandoned packages and name replacements.
3. **Known vulnerabilities:** `php veyro security:audit` checks the lock file against Packagist's advisories and OSV.dev ([dependency audit](dependency-audit.md)).
4. **License:** check that it suits your project (`composer licenses`).
5. **Read the code you are adding**, at least the service provider and anything that touches the network, files, `exec` or `eval`.
6. **Unknown packages:** try them in a disposable copy of your application without production secrets, for example a container or VM, not on a machine with production credentials.

## Composer plugins and scripts

- Composer 2.2+ asks before running a plugin. Allow only what you need, by name, in `composer.json` `config.allow-plugins`, and keep `"*"` out of it.
- `--no-plugins` and `--no-scripts` skip them. Use these in CI steps that only inspect.
- Commit `composer.lock`, and install in production with `composer install --no-dev` from the lock file, so production gets exactly the versions you reviewed. Never run `composer update` on a server.

## Controlling discovery

- `config/packages.php` `dont_discover`: package names to skip, or `['*']` to register every package yourself.
- `php veyro package:discover` lists every package and provider that will run; review it after `composer update`.
- Packages cannot add global middleware. They can add routes, but documented packages put them behind a config switch.

## What VeyroPHP checks and what it does not

| Checked | Not checked |
| --- | --- |
| Discovery metadata is validated (package names, provider class names); invalid entries are skipped and reported | Whether the package's code is benign |
| Providers must be constructible service providers; errors name the class | Whether a maintainer is who they claim to be |
| Publish targets stay inside the application; nothing is overwritten without `--force` | Whether a release matches its source repository |
| Known advisories for locked versions, with `security:audit` ([dependency audit](dependency-audit.md)) | Vulnerabilities nobody has published yet |

There is no "safe" badge, and automated checks cannot give one.

## Reporting

- **A vulnerability in a package:** report it privately to its maintainer (see its README or `SECURITY.md`), and to the GitHub Security Advisory database or FriendsOfPHP so `composer audit` learns about it.
- **A malicious package on Packagist:** report it to Packagist (`security@packagist.org`).
- **A vulnerability in VeyroPHP itself:** see the security policy (to be published with the repository).
