# Postilio for WordPress

Sends the email of a WordPress site through [Postilio](https://postilio.eu), European transactional email, instead of
the web server's own mail function. Every plug-in that sends with `wp_mail()` (WooCommerce, Contact Form 7, password
resets, comment notifications) sends through Postilio without a change, from an address on a domain you verified, with
that domain's SPF and DKIM.

WordPress 6.4 or later, PHP 8.2 or later. It uses the [Postilio PHP SDK](https://github.com/Postilio/postilio-php),
bundled with its namespace prefixed, so it cannot clash with another plug-in's copy of the SDK or of the PSR packages.

> Alpha. Not in the WordPress.org plug-in directory yet: install it from the ZIP. See [CHANGELOG.md](CHANGELOG.md).

## Install

1. Download `postilio-for-wordpress-<version>.zip` from the repository's releases (or build it, see
   [Development](#development)).
2. In WordPress: **Plugins → Add New Plugin → Upload Plugin**, choose the ZIP, **Install Now**, **Activate**.
3. In the Postilio portal, under **Keys & SMTP**, create an API key for the project whose domain you send from:

   | Scope | Needed for |
   |---|---|
   | `emails:send` | sending; the only scope the plug-in needs |
   | `emails:read` | the delivery status of a test email on the settings page |
   | `domains:read` | the sender domain's status on the settings page (`domains:manage` works too, but also allows changing domains) |

   A test key (`pk_test_…`) goes through every check but delivers nothing: good for a staging site.
4. Put the key in `wp-config.php`, above the line `/* That's all, stop editing! */`:

   ```php
   define( 'POSTILIO_API_KEY', 'pk_live_…' );
   ```

   Or enter it under **Settings → Postilio** (see [The API key](#the-api-key)).
5. Under **Settings → Postilio**, set the **From address** to an address on a domain that is verified in the key's
   project, and a **From name**. Save.
6. Send a test email from the same page.

Until a key is set, the plug-in does nothing and WordPress sends its email itself, as before; the dashboard and the
plug-ins page say so.

## Settings

**Settings → Postilio** (administrators, `manage_options`):

- **Status**: whether Postilio accepts the key, whether it is a live or a test key, and whether the sender's domain is
  verified (`verified`, `pending`, `failing`, not a domain of the project, or unknown when the key lacks
  `domains:read`). One call to the API, cached for 12 hours; **Check again** refreshes it. Saving the settings
  refreshes it too.
- **API key**: the masked key and where it comes from. See below.
- **From address** and **From name**: the sender of every email.
- **Force this sender** (on by default): every email goes out from this address and name, whatever the plug-in that
  sends it asks for. Turned off, a plug-in's `From:` header and the `wp_mail_from` and `wp_mail_from_name` filters
  choose the sender, as in WordPress itself (with this address and name as the starting point instead of
  `wordpress@yourdomain`). Postilio refuses any sender outside the verified domains of the key's project.
- **Send a test email**: sends a short email through `wp_mail()` to the address you enter, and shows Postilio's answer:
  the message id, and with `emails:read` its status and events. With a live key it is delivered and counts as usage.

## The API key

- **In `wp-config.php`** (recommended): the `POSTILIO_API_KEY` constant. It stays out of the database and its backups,
  and it wins over a key in the settings.
- **In the settings**: stored in the option `postilio_api_key`, not autoloaded, so it is read only when an email is
  sent or the settings page is open. The page never shows it again: only its mode and first characters, as the portal
  shows keys (`pk_live_abcd…`). Leave the field empty to keep it, enter a new one to replace it, or tick **Remove the
  stored key**.
- The key is never logged, and never part of an error message.
- Deleting the plug-in (not just deactivating it) removes the stored key and every other option of the plug-in
  (`uninstall.php`, on every site of a multisite network). A key in `wp-config.php` stays; remove that line yourself.

`POSTILIO_API_URL` points the plug-in at another Postilio, such as a test environment
(`define( 'POSTILIO_API_URL', 'http://localhost:26299' );`). Leave it out in production: the default is
`https://api.postilio.eu`.

## How an email is translated

The plug-in answers WordPress's `pre_wp_mail` filter (WordPress 5.7 and later), so it takes over every `wp_mail()` call
after the `wp_mail` filter has run, and reads the arguments the way `wp_mail()` itself does:

| `wp_mail()` | Postilio |
|---|---|
| `$to` as a string, comma-separated, or an array; `Name <address>` | `to`, bare addresses; invalid ones are skipped, as WordPress skips them; each address once over To, Cc and Bcc |
| `From:` header | the sender when force is off (see [Settings](#settings)) |
| `Reply-To:` | `replyTo`, the first address (the API takes one) |
| `Cc:`, `Bcc:` | `cc`, `bcc`: see [Cc and Bcc](#cc-and-bcc) |
| `Content-Type:` and the `wp_mail_content_type` filter | `text/plain` → `text`; `text/html` → `html`; `multipart/alternative` (with its boundary) → the text and the HTML part, decoded (base64, quoted-printable) |
| charset (header, `wp_mail_charset`) | converted to UTF-8 |
| `List-Unsubscribe`, `List-Unsubscribe-Post`, `In-Reply-To`, `References`, `X-…` | `headers`, up to 10, printable ASCII values; `X-Mailer` is left out, as WordPress leaves it out |
| any other header (`Importance`, `MIME-Version`, …) | left out, and the email is still sent (as Postilio's SMTP submission does) |
| `$attachments` (paths, keyed by file name or not) | `attachments`, at most 20 |
| `$embeds` (WordPress 6.9+, with `wp_mail_embed_args`) | `attachments` with a `contentId`, inline |
| — | `tag: "wordpress"` on every message |

More than 50 recipients, or more than 25 MB counted as size times recipients, are split into several requests. Every
request carries an `Idempotency-Key` made from the site, the request and the hour (UTC): when a caller or a queue sends
the same email again within the same clock hour, Postilio answers as the first time and sends nothing twice. The other
side of that: the very same email (same recipients, subject, body and attachments) sent on purpose twice within the
same clock hour also goes out once.

The plug-in fires `wp_mail_succeeded` when Postilio accepted every request, and `wp_mail_failed` otherwise (see
[When sending fails](#when-sending-fails)). `phpmailer_init` does not run, because PHPMailer is not used.

### Cc and Bcc

Postilio gives every recipient a copy of their own (its message model), and takes `cc` and `bcc` only with exactly one
address in `to` (`422 cc_bcc_require_single_to`). So:

- **One To address, with Cc and/or Bcc**: works as in a mail program. Every copy, the Cc and Bcc recipients' too, shows
  the To address and the Cc addresses; the Bcc addresses appear on no copy.
- **Several To addresses, no Cc or Bcc**: every recipient gets a copy that shows only their own address. In WordPress
  with PHP's mail(), they would see each other in the To header.
- **Only Cc or Bcc, no To**: every one of them gets a copy of their own, showing their own address.
- **Several To addresses with Cc or Bcc**: refused in this version (`wp_mail()` returns false, `wp_mail_failed` with
  `cc_bcc_require_single_to`), and nothing is sent. Postilio itself has no way to send it with the headers WordPress
  would write; how to map it is an open decision.
- **Names** in To, Cc and Bcc are dropped: the API takes bare addresses there. The From and Reply-To names stay.
- To, Cc and Bcc together hold at most 50 addresses, and the size times the recipients at most 25 MB. Over that,
  Postilio refuses the email (`400`, or `413 message_too_large_for_recipients`) and `wp_mail()` returns false; a call
  without Cc or Bcc is split into several requests instead.

### Attachments

Up to 20 files and 10 MB per email in all (text, HTML and attachments together), checked before anything is read or
sent. Only local files that PHP can read are attached. An attachment that cannot be read, a directory, a path with a
`..` segment, or a URL or stream wrapper (`phar://`, `http://`) stops the email with `attachment_not_readable`;
WordPress itself would send the email without that attachment and without a word. The type is taken from the file name
(`wp_check_filetype()`), `application/octet-stream` otherwise.

## When sending fails

The plug-in never hands an email back to the web server's mail function: that would send it without your domain's SPF
and DKIM, from a server that may not be allowed to send for it. Instead:

- `wp_mail()` returns `false`.
- The `wp_mail_failed` action gets a `WP_Error` with the code `wp_mail_failed` (as WordPress's own), a message that
  names the call, the status and the Postilio error code, and in its data the `wp_mail()` arguments plus
  `postilio_error` (such as `unverified_sender_domain`, `plan_daily_limit_reached`, `transport_error` for no answer,
  or a code of the plug-in: `no_recipients`, `invalid_from`, `empty_message`, `attachment_not_readable`,
  `too_many_attachments`, `message_too_large`, `cc_bcc_require_single_to`, `invalid_api_key`), `postilio_status`,
  `postilio_trace_id` and `postilio_ids` (the messages accepted before a later request of the same email failed).
- One line goes to the PHP error log, with the message and the trace id, never the key, the recipients or the content.

Network failures and `5xx`/`429` answers are tried twice more within a few seconds, with the same `Idempotency-Key`;
an answer that asks to wait longer fails at once.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Status says the key is not accepted | The key was revoked or mistyped. Create a new one; in `wp-config.php` check the quotes. |
| `unverified_sender_domain` | The From address is not on a verified domain of the key's project, or the domain has been failing for over 72 hours. Check the domain in the portal and the From address in the settings; with force off, check which plug-in sets another `From`. |
| `sender_domain_not_allowed_for_key` | The key is limited to other sending domains in the portal. |
| `sandbox_recipient_not_allowed` | Your organization is in the sandbox: live mail goes only to your team and your verified domains. |
| `insufficient_scope` | The key lacks `emails:send`. |
| `client_ip_not_allowed` | The key is limited to networks your web server is not in. |
| `cc_bcc_require_single_to` | A plug-in sends to several To addresses with Cc or Bcc; see [Cc and Bcc](#cc-and-bcc). |
| `transport_error`, `cURL error 28` | No answer within 15 seconds: check the server's outgoing HTTPS (firewall, proxy). The plug-in uses the WordPress HTTP API, so `WP_PROXY_HOST` and friends apply, and the `http_request_args` filter can change the timeout. |
| Mail still comes from `wordpress@…` | No key is set, so WordPress sends itself (see the dashboard notice). |
| A plug-in's email has no body | It builds the body in `phpmailer_init`, which does not run; see [How an email is translated](#how-an-email-is-translated). |

Every email is in the Postilio portal's message log, with the tag `wordpress`, its status and its events. To find
failures on the WordPress side, hook `wp_mail_failed` or read the PHP error log.

## Privacy: what goes to Postilio

Nothing, until an API key is set. Then, for every email the site sends with `wp_mail()`: the sender, the recipients,
the subject, the text and HTML body, the attachments, the Reply-To address and the custom headers listed above; the API
key; the tag `wordpress`; and a `User-Agent` with the versions of the SDK, PHP and the plug-in. For the status line: a
request for the project's domains. For the test email: the address you enter. Postilio runs in the European Union as
the processor of this mail. It holds an email's content only until the receiving mail server has it (normally under a
second, at most a day); its message log keeps, per recipient, the sender, the recipient, the tag, the size, the status
and the events, and the subject unless the project turns that off, for the project's log retention (30 days by
default). See Postilio's documentation on data retention at [docs.postilio.eu](https://docs.postilio.eu).

The plug-in sets no cookies, adds nothing to the front end, tracks nothing, and keeps no log of its own in WordPress.

## Multisite

Every site has its own settings and its own stored key; the `POSTILIO_API_KEY` constant applies to every site.
Network-wide settings are not in this version.

## Development

PHP 8.2 or later and Composer; docker for the integration test.

```sh
composer install       # also prefixes the SDK into vendor-prefixed/ (Strauss)
vendor/bin/phpunit     # unit tests
vendor/bin/phpcs       # WordPress coding standards, with the security sniffs
vendor/bin/phpstan analyse --memory-limit=1G
./build.sh             # all of the above, then dist/postilio-for-wordpress-<version>.zip
tests/integration/run.sh 8.2 6.4   # a real WordPress in wp-env, against a fake Postilio API
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md).

## License

[GPL-2.0-or-later](LICENSE). The bundled Postilio PHP SDK, nyholm/psr7 and the PSR interfaces are MIT-licensed; their
license texts are in the ZIP next to their code, under `vendor-prefixed/`.
