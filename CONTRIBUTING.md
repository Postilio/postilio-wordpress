# Contributing

Thank you for helping. Open an issue before a large change, so we can agree on the approach first.

## Build and test

You need PHP 8.2 or later and Composer; docker (or podman with docker compatibility) for the integration test.

```sh
composer install
```

It installs the tools and runs [Strauss](https://github.com/BrianHenryIE/strauss), which copies the SDK and its
dependencies into `vendor-prefixed/` with their namespaces prefixed by `PostilioWp\Vendor\`. The plug-in's code uses
those prefixed names, in development as in the ZIP. Run `composer prefix` again after changing a dependency.

| Command | What |
|---|---|
| `vendor/bin/phpunit` | unit tests, with [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey) for WordPress's functions and a fake PSR-18 client for the API |
| `vendor/bin/phpcs` | the WordPress coding standards, the security sniffs included (escaping, nonces, sanitising); `vendor/bin/phpcbf` fixes what it can |
| `vendor/bin/phpstan analyse --memory-limit=1G` | PHPStan at the highest level with the WordPress stubs, for PHP 8.2 to 8.5 |
| `./build.sh` | all three on the last commit, then the ZIP; see [RELEASING.md](RELEASING.md) |
| `tests/integration/run.sh <php> <wordpress>` | the integration test, see below |

### The integration test

`tests/integration/run.sh 8.2 6.4` unpacks the built ZIP (it builds one when there is none) and starts a real
WordPress with [wp-env](https://www.npmjs.com/package/@wordpress/env) on port 28888, with the plug-in, a
made-up test key in `wp-config.php`, and a fake Postilio API as a must-use plug-in (`tests/integration/mu-plugins/fake-postilio.php`).
`tests/integration/scenarios.php` then sends through `wp_mail()` over real HTTP, and checks what the fake API received
and what WordPress reported: the sender, the tag, the key and the Idempotency-Key; HTML, Cc, Bcc, headers and an
attachment; a refusal without a fallback to PHPMailer; force off; several To addresses with a Bcc; the status line; the
settings fields; the test email's nonce and capability checks; uninstalling; and no PHP notices.

The versions the plug-in supports: WordPress 6.4 with PHP 8.2 (the oldest pair), and the latest WordPress with PHP
8.3 and 8.4:

```sh
tests/integration/run.sh 8.2 6.4
tests/integration/run.sh 8.3 latest
tests/integration/run.sh 8.4 latest
npx @wordpress/env@11.16.0 cleanup   # removes the containers and volumes, keeps the images
```

Rebuild the ZIP (`./build.sh`) after a change, or the test runs the old one.

### Mutation testing

`composer mutation` runs [Infection](https://infection.github.io/) over `src` (it needs pcov or Xdebug). Brain Monkey's
Patchwork loads PHP files through a stream wrapper of its own, which would hide Infection's mutants; `patchwork.json`
leaves `src/` and `vendor-prefixed/` to PHP, so the mutants are really run.

Look at every mutant that escapes: either a test is missing, or the code it changed is not needed. On 0.1.0-alpha.1:
604 mutants, 85 % killed (covered-code MSI). Most of the rest change the wording of an error message, the separators
of the Idempotency-Key's input, or the trimming of messy header lines, where a test would only repeat the code; the
settings page's rendering and its AJAX happy path are covered by the integration test, not by unit tests.

## Conventions

- Code, comments, docs, commits and pull requests in English. Text in the user interface goes through `__()` and
  friends with the text domain `postilio-for-wordpress`; update `languages/` (see [RELEASING.md](RELEASING.md)).
- The WordPress coding standards; classes in `src/`, one per file, named as their file (PSR-4, namespace `PostilioWp`).
- Commits: `<type>(<scope>): <subject>`, imperative and lowercase (`feat`, `fix`, `docs`, `test`, `refactor`, `build`,
  `chore`). Signed commits are welcome.
- Read the arguments of `wp_mail()` as WordPress does (`wp-includes/pluggable.php`), and say so where the plug-in
  differs on purpose.
- Every behaviour has one test; vary the input with a data provider rather than writing another test.
- No new runtime dependency without a good reason: each one is bundled, prefixed, in every site's ZIP.
- Add a line to `CHANGELOG.md` under *Unreleased*.
