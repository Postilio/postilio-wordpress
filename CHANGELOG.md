# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

## [0.1.0-alpha.2] - 2026-10-09

### Changed

- The sender domain's status needs the scope `domains:read` (or `domains:manage`), which a test key may have too; the
  settings page, README and readme.txt name it instead of `domains:manage`.
- On the Postilio PHP SDK at commit `3e671fb84b7b5ebf8f91d06f445f1dcb22fd9347`: a test email's timeline knows the
  reason `async_bounce`, a bounce reported after delivery.

## [0.1.0-alpha.1]

First version, on the Postilio PHP SDK at commit `8d012a04d008d3dd94b1f2ac488e1d1139503097` (0.1.0-alpha.1, not tagged
yet).

### Added

- `wp_mail()` through the Postilio API, by the `pre_wp_mail` filter: every plug-in that sends with `wp_mail()` sends
  through Postilio. The arguments are read as WordPress reads them: recipients in every form, headers as a string or an
  array, From, Reply-To, Cc, Bcc, Content-Type with its charset, `multipart/alternative`, the custom headers Postilio
  takes, attachments and embeds (WordPress 6.9+).
- Cc and Bcc with one To address as Postilio sends them; several To addresses with Cc or Bcc are refused
  (`cc_bcc_require_single_to`).
- More than 50 recipients, or more than 25 MB as size times recipients, split into several requests, each with an
  `Idempotency-Key` from the site, the request and the hour, so a retry never sends twice.
- No fallback to PHP's mail(): a failure returns false and fires `wp_mail_failed` with the Postilio error code,
  status and trace id; `wp_mail_succeeded` on success. A line in the PHP error log, without the key, recipients or
  content.
- Settings → Postilio: the API key (the `POSTILIO_API_KEY` constant in `wp-config.php`, or a field stored without
  autoload and never shown again), the sender (forced by default), a status line (key, mode, sender domain; one cached
  call), and a test email that shows Postilio's answer.
- A PSR-18 client over the WordPress HTTP API, so the site's proxy and TLS settings apply; the SDK and its
  dependencies bundled with their namespaces prefixed.
- English, and a Dutch (nl_NL) translation.
- WordPress 6.4 or later, PHP 8.2 or later. GPL-2.0-or-later.
