# Security

## Reporting a vulnerability

Please do not open a public issue. Report it privately through GitHub's **Report a vulnerability** button on this
repository's **Security** tab. We answer within five working days and keep you informed until it is fixed. Include the
version, what an attacker can do, and the steps to reproduce it.

A vulnerability in the Postilio service itself (the API, the portal, SMTP) or in the PHP SDK is reported the same way;
we pass it on.

## Supported versions

Only the latest release gets security fixes while the plug-in is below 1.0.

## How the plug-in handles secrets and input

- **The API key.** Best in `wp-config.php` (`POSTILIO_API_KEY`), outside the database and its backups. A key entered on
  the settings page is stored in its own option without autoload, is never printed again (the page shows the mode and
  the first four characters only, as the portal does), and is removed when the plug-in is deleted. It is sent only in
  the `Authorization` header to the API. It is never logged and never part of an error message; the SDK keeps it out of
  exceptions and dumps, and the plug-in's PSR-18 adapter passes on only WordPress's error message, not the request.
- **TLS.** Requests go through the WordPress HTTP API with its certificate checks; the plug-in never turns
  `sslverify` off and follows no redirects. `POSTILIO_API_URL` exists for test environments; production uses the
  default `https://api.postilio.eu`.
- **The settings page.** Only users with `manage_options` see it or use its actions. Saving goes through the Settings
  API (`options.php`, its nonce and capability check); every value is sanitised (`sanitize_email`,
  `sanitize_text_field`, the key's format) and every output escaped. The test email (AJAX) and the status refresh
  (`admin-post.php`) check a nonce first and the capability second. The test email's answer is shown with
  `textContent`, never as HTML.
- **Attachments.** Only local, readable files: a URL or stream wrapper (`phar://`, `http://`), a directory or a path
  with a `..` segment is refused before anything is read. Plug-ins choose the paths they attach; the plug-in does not
  take paths from visitors.
- **Logging.** A failed email writes one line to the PHP error log: the Postilio error code and message and the trace
  id. Never the key, the recipients, the subject or the content.
- **No fallback.** A failure is reported, never handed to PHP's mail(), which would send without the domain's SPF and
  DKIM.
- The repository holds no keys. The integration test uses a made-up test key against a fake API.
