=== Postilio for WordPress ===
Tags: email, wp_mail, transactional email, smtp, deliverability
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0-alpha.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends your site's email through Postilio, European transactional email, with your own domain's SPF and DKIM.

== Description ==

WordPress sends its email with the web server's own mail function, as wordpress@ and your domain, often without SPF or DKIM, so it lands in spam or nowhere. This plug-in sends every email that goes through `wp_mail()` through the [Postilio](https://postilio.eu) API instead: password resets, WooCommerce orders, Contact Form 7 messages and the rest, from an address on a domain you verified in Postilio.

* Works with every plug-in that sends with `wp_mail()`; nothing to change in them.
* The API key goes in `wp-config.php`, outside the database; a settings field is the fallback.
* One sender for all email, on your verified domain, or the sender each plug-in chooses.
* A status line (key accepted, test or live key, sender domain verified) and a test email that shows Postilio's answer.
* When Postilio refuses an email, `wp_mail()` returns false and WordPress's `wp_mail_failed` action tells you why. It never falls back to the web server's mail function, which would send without your domain's SPF and DKIM.
* Every message carries the tag `wordpress`, to find it in the Postilio portal's message log.
* No tracking, no log of its own: the message log is in the Postilio portal.

This is an alpha version.

== Installation ==

1. Upload the ZIP under Plugins → Add New Plugin → Upload Plugin, and activate it.
2. Create an API key in the Postilio portal under Keys & SMTP, with the scope `emails:send` (add `emails:read` to see a test email's delivery in WordPress, and `domains:read` for the domain status).
3. Add it to `wp-config.php`, above the line that says to stop editing:
   `define( 'POSTILIO_API_KEY', 'pk_live_…' );`
   Or enter it under Settings → Postilio.
4. Under Settings → Postilio, set the sender: an address on a domain you verified in the key's project.
5. Send a test email from that page.

Until a key is set, WordPress sends its email as before.

== Frequently Asked Questions ==

= What happens to Cc and Bcc? =

Postilio gives every recipient a copy of their own. With one To address, Cc and Bcc work as in any mail program: every copy shows the To and the Cc addresses, and Bcc addresses stay hidden. Postilio takes Cc and Bcc only with exactly one To address, so an email with several To addresses and a Cc or Bcc is refused (wp_mail() returns false) in this version. Several To addresses without Cc or Bcc each get their own copy, which shows only their own address. Names in To, Cc and Bcc are dropped: Postilio takes bare addresses there.

= Which headers are passed on? =

From, Reply-To (the first address), Cc, Bcc and Content-Type are read as WordPress reads them. Of the other headers, Postilio takes `List-Unsubscribe`, `List-Unsubscribe-Post`, `In-Reply-To`, `References` and `X-` headers (up to 10, ASCII values); others, such as `X-Mailer` or `Importance`, are left out and the email is still sent.

= Are attachments supported? =

Yes, up to 20 files and 10 MB per email in all, from files on the server. An attachment that cannot be read, or a path with `..` or a stream wrapper such as `phar://`, stops the email with an error, where WordPress itself would leave the attachment out without a word. Embedded images (WordPress 6.9 and later) are sent inline.

= Does it work with plug-ins that change PHPMailer? =

The `phpmailer_init` action does not run, because PHPMailer is not used. Plug-ins that only set PHPMailer properties there (an SMTP server, DKIM) are not needed with Postilio; a plug-in that builds its email body in `phpmailer_init` will not have that body sent.

= Is it multisite-ready? =

Each site has its own settings; the `POSTILIO_API_KEY` constant applies to all of them. Network-wide settings are not in this version.

== External services ==

This plug-in sends email through the Postilio API (`https://api.postilio.eu`), run by Postilio in the European Union. It does so only once you set an API key, and then for every email your site sends with `wp_mail()`, and for the status line and the test email on its settings page.

What is sent, for each email: the sender, the recipients, the subject, the text and HTML body, the attachments, the Reply-To address and the custom headers listed above, plus your API key, a tag (`wordpress`), and the plug-in's and PHP's version in the User-Agent. For the status line: a request for your project's domains. Postilio holds an email's content only until the receiving mail server has it; its message log keeps, per recipient, the sender, the recipient, the status and its events, and the subject unless your project turns that off, for 30 days by default. Postilio's documentation: https://docs.postilio.eu. Postilio's terms of service and privacy policy for the service: to be published before this plug-in is listed.

== Changelog ==

= 0.1.0-alpha.1 =
* First version: wp_mail() through the Postilio API, a settings page with a status line and a test email.
