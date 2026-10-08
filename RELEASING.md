# Releasing

The plug-in is distributed as a ZIP, `postilio-for-wordpress-<version>.zip`, attached to a GitHub release of this
repository. It is not in the WordPress.org plug-in directory yet; `readme.txt` is written in its format so that it can
be, see [WordPress.org](#wordpressorg-later).

## Every release

1. **Version**, in three places that `build.sh` checks against each other: the `Version:` header of
   `postilio-for-wordpress.php`, `Plugin::VERSION` in `src/Plugin.php`, and `Stable tag:` in `readme.txt`. Semantic
   versioning; pre-releases as `0.2.0-alpha.1`. Move the *Unreleased* entries of `CHANGELOG.md` to a heading with the
   version and the date, and add the version to `== Changelog ==` in `readme.txt`.
2. **Translations**, when a text changed:

   ```sh
   npx @wordpress/env@11.16.0 run cli --env-cwd=wp-content/plugins/postilio-for-wordpress \
     wp i18n make-pot . languages/postilio-for-wordpress.pot --exclude=vendor-prefixed
   msgmerge --update --backup=none languages/postilio-for-wordpress-nl_NL.po languages/postilio-for-wordpress.pot
   # translate the new and the fuzzy entries in the .po, then:
   msgfmt --check -o languages/postilio-for-wordpress-nl_NL.mo languages/postilio-for-wordpress-nl_NL.po
   ```

   (`make-pot` runs inside wp-env on the unpacked build in `dist/`; copy the `.pot` back, or run WP-CLI locally.)
3. **The SDK.** `composer.json` pins `postilio/postilio-php` to a commit through a VCS repository, because the SDK has
   no release tag and is not on Packagist yet. Once it has a tag, require that tag (`"0.1.0-alpha.1"`, or a range once
   it is on Packagist, and drop the `repositories` entry), run `composer update postilio/postilio-php`, and note the
   SDK version in `CHANGELOG.md`.
4. **Build and test**:

   ```sh
   ./build.sh                          # checks, then dist/postilio-for-wordpress-<version>.zip and its sha256
   tests/integration/run.sh 8.2 6.4    # the oldest WordPress and PHP the plug-in supports
   tests/integration/run.sh 8.3 latest
   tests/integration/run.sh 8.4 latest
   ```

   `build.sh` packs the last commit only, and refuses a ZIP with development files, an unprefixed dependency, a missing
   license or anything that looks like a key. The same commit gives the same ZIP, byte for byte, so anyone can check the
   released ZIP against the tag by building it again.
5. **The manual test** below, on a site with a real Postilio project.
6. **Commit** (`chore: release 0.2.0`) through a pull request, then tag the merged commit signed and push the tag:

   ```sh
   git tag -s v0.2.0 -m "v0.2.0"
   git push origin v0.2.0
   ```

7. **The GitHub release** for the tag: the changelog's section as the text, `build.sh`'s ZIP and its sha256 as assets,
   marked as a pre-release for a version with a suffix. Build the ZIP from the tagged commit.

A tag is never moved or reused: a broken release gets a new patch version.

## Supported versions

- **WordPress 6.4 and later.** The plug-in needs `pre_wp_mail` (5.7) and `wp_mail_succeeded` (5.9); 6.4 was chosen as
  the oldest version still worth testing: 89 % of WordPress sites ran 6.4 or later on 2026-10-08
  (api.wordpress.org/stats), and WordPress supports PHP 8.2 from 6.4 on. Embedded images need 6.9. Raise the minimum
  when a version drops below a few percent, in `Requires at least` (header and `readme.txt`), the `minimum_wp_version`
  of `phpcs.xml.dist`, and the integration test's oldest pair. Set `Tested up to` in `readme.txt` to the newest
  WordPress the integration test passed on.
- **PHP 8.2 and later**, because the SDK needs it. Follow the SDK when it drops a PHP version past its end of life (8.2:
  31 December 2026): `Requires PHP`, `config.platform.php` in `composer.json` and `phpVersion.min` in
  `phpstan.neon.dist`.

## Manual test

On a WordPress site (a staging site is fine) and a Postilio project with a verified domain, before a release:

1. Install the ZIP under **Plugins → Add New Plugin → Upload Plugin** and activate it. The dashboard says no key is
   set, and a password reset email still arrives the old way.
2. Create a key in the portal (**Keys & SMTP**) with `emails:send` and `emails:read`. Add it to `wp-config.php`:
   `define( 'POSTILIO_API_KEY', 'pk_live_…' );`. The notice is gone.
3. **Settings → Postilio**: the key shows as `pk_live_…` with its first four characters, *from wp-config.php*, and the
   status says *Accepted, live key*. Set the From address on the verified domain and a From name; save.
4. **Send a test email** to your own address. The page shows *Postilio accepted the email*, the message id and its
   status. The email arrives from the configured sender; in the mailbox's original or headers (Gmail: **Show
   original**), SPF, DKIM and DMARC say `pass`.
5. In the Postilio portal, **Messages**: the email is there with the tag `wordpress` and the same id.
6. Trigger a real email: **Users → your user → Send Reset Link**, or a WooCommerce test order, or a Contact Form 7
   form. It arrives, and is in the portal.
7. Set the From address to one on a domain that is not verified, and send a test email: the page shows
   `unverified_sender_domain`, nothing arrives, the PHP error log has one line without the key or recipient. Set it
   back.
8. Remove the constant, enter the key on the settings page, save: the field stays empty and shows only the masked hint;
   sending still works. Delete the plug-in (**Deactivate**, **Delete**): the option `postilio_api_key` is gone
   (`wp option get postilio_api_key` fails).
9. With a test key (`pk_test_…`): the status says *test key: nothing is delivered*, a test email is accepted and shows
   as delivered at once, and nothing arrives.

## Continuous integration

There is no workflow in this repository; whether to add one is the owner's decision, since GitHub Actions minutes cost
money. A workflow would, on every pull request and push to `main`:

| Job | Steps |
|---|---|
| `checks` (PHP 8.4, ubuntu-latest) | checkout, `shivammathur/setup-php`, `./build.sh` (composer, phpcs, phpstan, phpunit, the ZIP and its checks), upload the ZIP as an artifact |
| `unit` (matrix PHP 8.2, 8.3, 8.4, 8.5) | checkout, setup-php, `composer install`, `vendor/bin/phpunit` |
| `integration` (matrix: PHP 8.2 + WordPress 6.4, PHP 8.3 + latest, PHP 8.4 + latest) | checkout, setup-php, setup-node, the ZIP from `checks`, `tests/integration/run.sh <php> <wordpress>` (wp-env runs on the runner's docker) |

Actions pinned to a commit SHA, with the version as a comment. A release workflow could build the ZIP from the tag and
attach it to the GitHub release; it needs no secret beyond the default `GITHUB_TOKEN`. Never attach a self-hosted
runner to a public repository: a pull request from a fork would run its code on that machine.

## WordPress.org (later)

The directory is not used yet. `readme.txt` follows its format and passes as far as this repository can check it;
before submitting:

1. Add a `Contributors:` line to `readme.txt` with the WordPress.org user name(s) that will own the listing.
2. Publish Postilio's terms of service and privacy policy for the service, and link them under `== External services ==`
   (the directory requires that for a plug-in that sends data to a service); the sentence there that says they are to
   be published goes.
3. Run the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plug-in on the built ZIP and the
   [readme validator](https://wordpress.org/plugins/developers/readme-validator/), and fix what they find.
4. Submit the ZIP at wordpress.org/plugins/developers/add/ and wait for the review. The slug asked for is
   `postilio-for-wordpress`. The review checks names and slugs against trademarks, WordPress's own included, and may
   ask for another; not checked here.
5. After approval, releases go to the directory's SVN (`trunk` and `tags/<version>`) from the same ZIP, with banner and
   icon images in its `assets` folder.
