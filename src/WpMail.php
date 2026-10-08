<?php
/**
 * Translates a wp_mail() call into Postilio requests.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Postilio\Model\EmailAttachment;
use PostilioWp\Vendor\Postilio\Model\SendEmailRequest;

/**
 * Reads the arguments of wp_mail() the way WordPress itself does (wp-includes/pluggable.php), and writes them as the
 * requests POST /v1/emails takes: within its limits, and with an Idempotency-Key per request.
 */
final class WpMail {
	/** The tag on every message, to find WordPress's mail in the portal. */
	public const TAG = 'wordpress';

	private const MAX_RECIPIENTS            = 50;
	private const MAX_ATTACHMENTS           = 20;
	private const MAX_MESSAGE_BYTES         = 10 * 1024 * 1024;
	private const MAX_SIZE_TIMES_RECIPIENTS = 25 * 1024 * 1024;
	private const MAX_CUSTOM_HEADERS        = 10;

	/** Custom headers the API takes besides X- headers, by their lower-case name. */
	private const ALLOWED_HEADERS = array(
		'list-unsubscribe'      => 'List-Unsubscribe',
		'list-unsubscribe-post' => 'List-Unsubscribe-Post',
		'in-reply-to'           => 'In-Reply-To',
		'references'            => 'References',
	);

	/**
	 * Translates a call.
	 *
	 * @param array<string, mixed> $atts     The wp_mail() arguments, after the wp_mail filter.
	 * @param Settings             $settings The plug-in's settings.
	 * @param int                  $now      The time, for the Idempotency-Key.
	 *
	 * @return list<array{request: SendEmailRequest, key: string}> One request per group of recipients.
	 *
	 * @throws MailError The call cannot be sent.
	 */
	public static function translate( array $atts, Settings $settings, int $now ): array {
		$headers = self::parse_headers( $atts['headers'] ?? '' );

		$seen = array();
		$to   = self::addresses( is_array( $atts['to'] ?? '' ) ? $atts['to'] : explode( ',', Settings::text( $atts['to'] ?? '' ) ), $seen );
		$cc   = self::addresses( $headers['cc'], $seen );
		$bcc  = self::addresses( $headers['bcc'], $seen );
		if ( array() === $to ) {
			// Only Cc or Bcc, as WordPress allows: each of them gets a copy of their own.
			$to  = array_merge( $cc, $bcc );
			$cc  = array();
			$bcc = array();
		}
		if ( array() === $to ) {
			throw new MailError( 'no_recipients', 'The email has no valid recipient.' );
		}
		if ( ( array() !== $cc || array() !== $bcc ) && count( $to ) > 1 ) {
			throw new MailError( 'cc_bcc_require_single_to', 'Postilio sends Cc and Bcc only with exactly one To address; this email has ' . count( $to ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, never printed as HTML.
		}

		$from     = self::from( $headers['from'], $settings );
		$reply_to = self::reply_to( $headers['reply_to'] );
		$custom   = self::custom_headers( $headers['custom'] );

		$content_type = $headers['content_type'] ?? 'text/plain';
		if ( null !== $headers['boundary'] && str_starts_with( strtolower( $content_type ), 'multipart/' ) && ! str_contains( $content_type, ';' ) ) {
			$content_type .= '; boundary="' . $headers['boundary'] . '"';
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
		$content_type = Settings::text( apply_filters( 'wp_mail_content_type', $content_type ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
		$charset = Settings::text( apply_filters( 'wp_mail_charset', $headers['charset'] ?? get_bloginfo( 'charset' ) ) );

		$subject         = self::to_utf8( trim( str_replace( array( "\r", "\n" ), '', Settings::text( $atts['subject'] ?? '' ) ) ), $charset );
		[ $text, $html ] = self::bodies( Settings::text( $atts['message'] ?? '' ), $content_type, $headers['boundary'], $charset );
		if ( '' === ( $text ?? '' ) && '' === ( $html ?? '' ) ) {
			throw new MailError( 'empty_message', 'The email has no message.' );
		}

		$files = self::files( $atts['attachments'] ?? array(), $atts['embeds'] ?? array() );
		$size  = strlen( $from ) + strlen( $reply_to ?? '' ) + strlen( $subject ) + strlen( self::TAG ) + strlen( $text ?? '' ) + strlen( $html ?? '' );
		foreach ( $custom as $name => $value ) {
			$size += strlen( $name ) + strlen( $value );
		}
		foreach ( $files as $file ) {
			$size += (int) filesize( $file['path'] ) + strlen( $file['name'] ) + strlen( $file['type'] ) + strlen( $file['cid'] ?? '' );
		}
		if ( $size > self::MAX_MESSAGE_BYTES ) {
			throw new MailError( 'message_too_large', 'The email is ' . $size . ' bytes; Postilio takes at most ' . self::MAX_MESSAGE_BYTES . ', attachments included.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, never printed as HTML.
		}
		$attachments = array();
		foreach ( $files as $file ) {
			$attachments[] = new EmailAttachment( $file['name'], $file['type'], Settings::text( file_get_contents( $file['path'] ) ), $file['cid'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		}

		// With Cc or Bcc there is one To address, so one request. $size is 1 byte to 10 MB, so at least 2 per request.
		$groups = array_chunk( $to, max( 1, min( self::MAX_RECIPIENTS, intdiv( self::MAX_SIZE_TIMES_RECIPIENTS, $size ) ) ) );

		$requests = array();
		foreach ( $groups as $group ) {
			$request    = new SendEmailRequest(
				from: $from,
				to: $group,
				subject: $subject,
				text: $text,
				html: $html,
				tag: self::TAG,
				replyTo: $reply_to,
				attachments: array() === $attachments ? null : $attachments,
				cc: array() === $cc ? null : $cc,
				bcc: array() === $bcc ? null : $bcc,
				headers: array() === $custom ? null : $custom,
			);
			$requests[] = array(
				'request' => $request,
				'key'     => self::idempotency_key( $request, $now ),
			);
		}

		return $requests;
	}

	/**
	 * Reads the headers as wp_mail() does.
	 *
	 * @param mixed $headers A string of lines, or an array of lines.
	 *
	 * @return array{from: ?string, content_type: ?string, charset: ?string, boundary: ?string, cc: list<string>, bcc: list<string>, reply_to: list<string>, custom: array<string, string>}
	 */
	private static function parse_headers( mixed $headers ): array {
		$parsed = array(
			'from'         => null,
			'content_type' => null,
			'charset'      => null,
			'boundary'     => null,
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'custom'       => array(),
		);
		$lines  = is_array( $headers ) ? $headers : explode( "\n", Settings::text( $headers ) );

		foreach ( $lines as $line ) {
			$line = Settings::text( $line );
			if ( ! str_contains( $line, ':' ) ) {
				if ( false !== stripos( $line, 'boundary=' ) ) {
					$parts              = (array) preg_split( '/boundary=/i', trim( $line ) );
					$parsed['boundary'] = trim( str_replace( array( "'", '"' ), '', Settings::text( $parts[1] ?? '' ) ) );
				}
				continue;
			}
			[ $name, $content ] = explode( ':', trim( $line ), 2 );
			$name               = trim( $name );
			$content            = trim( $content );

			switch ( strtolower( $name ) ) {
				case 'from':
					$parsed['from'] = $content;
					break;
				case 'content-type':
					if ( str_contains( $content, ';' ) ) {
						[ $type, $parameter ]   = explode( ';', $content, 2 );
						$parsed['content_type'] = trim( $type );
						if ( 1 === preg_match( '/charset\s*=\s*"?([^";]+)"?/i', $parameter, $match ) ) {
							$parsed['charset'] = trim( $match[1] );
						} elseif ( 1 === preg_match( '/boundary\s*=\s*"?([^";]+)"?/i', $parameter, $match ) ) {
							$parsed['boundary'] = trim( $match[1] );
						}
					} elseif ( '' !== $content ) {
						$parsed['content_type'] = $content;
					}
					break;
				case 'cc':
					$parsed['cc'] = array_merge( $parsed['cc'], explode( ',', $content ) );
					break;
				case 'bcc':
					$parsed['bcc'] = array_merge( $parsed['bcc'], explode( ',', $content ) );
					break;
				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], explode( ',', $content ) );
					break;
				default:
					$parsed['custom'][ $name ] = $content;
			}//end switch
		}//end foreach

		return $parsed;
	}

	/**
	 * Splits "Name <address>" as wp_mail() does.
	 *
	 * @param string $address The address, with or without a name.
	 *
	 * @return array{string, string} The name (empty without one) and the address.
	 */
	private static function split_address( string $address ): array {
		if ( 1 === preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
			return array( trim( str_replace( '"', '', $matches[1] ) ), trim( $matches[2] ) );
		}

		return array( '', trim( $address ) );
	}

	/**
	 * The valid addresses, without names, each once over To, Cc and Bcc together; invalid ones are skipped, as
	 * WordPress skips them.
	 *
	 * @param array<mixed>        $addresses The addresses.
	 * @param array<string, true> $seen      The addresses taken so far, in lower case.
	 *
	 * @return list<string>
	 */
	private static function addresses( array $addresses, array &$seen ): array {
		$valid = array();
		foreach ( $addresses as $address ) {
			$email = self::split_address( Settings::text( $address ) )[1];
			if ( '' === $email || ! is_email( $email ) || isset( $seen[ strtolower( $email ) ] ) ) {
				continue;
			}
			$seen[ strtolower( $email ) ] = true;
			$valid[]                      = $email;
		}

		return $valid;
	}

	/**
	 * The sender: the configured one when forced, otherwise WordPress's choice through its filters.
	 *
	 * @param string|null $header   The From header, if the call had one.
	 * @param Settings    $settings The plug-in's settings.
	 *
	 * @throws MailError The sender is not an address.
	 */
	private static function from( ?string $header, Settings $settings ): string {
		if ( $settings->force_from && '' !== $settings->from_email ) {
			$email = $settings->from_email;
			$name  = $settings->from_name;
		} else {
			[ $header_name, $header_email ] = null === $header ? array( '', '' ) : self::split_address( $header );
			$email                          = '' !== $header_email ? $header_email : ( '' !== $settings->from_email ? $settings->from_email : self::default_from() );
			$name                           = '' !== $header_name ? $header_name : ( '' !== $settings->from_name ? $settings->from_name : 'WordPress' );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
			$email = Settings::text( apply_filters( 'wp_mail_from', $email ) );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
			$name = Settings::text( apply_filters( 'wp_mail_from_name', $name ) );
		}
		if ( ! is_email( $email ) ) {
			throw new MailError( 'invalid_from', 'The sender is not a valid email address.' );
		}
		// The API takes no @ or comma in the name; the rest would break the "Name <address>" form.
		$name = trim( str_replace( array( '@', ',', '<', '>', '"', "\r", "\n" ), '', $name ) );

		return '' === $name ? $email : "$name <$email>";
	}

	/** The address WordPress sends from by default: wordpress@ and the site's host, without www. */
	public static function default_from(): string {
		$host = Settings::text( wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		return 'wordpress@' . ( str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host );
	}

	/**
	 * The first valid Reply-To address, with its name: the API takes one.
	 *
	 * @param string[] $addresses The Reply-To addresses.
	 */
	private static function reply_to( array $addresses ): ?string {
		foreach ( $addresses as $address ) {
			[ $name, $email ] = self::split_address( $address );
			if ( is_email( $email ) ) {
				$name = trim( str_replace( array( '<', '>', '"', "\r", "\n" ), '', $name ) );
				return '' === $name ? $email : "$name <$email>";
			}
		}

		return null;
	}

	/**
	 * The custom headers the API takes, as Postilio's SMTP submission keeps them: the others are left out, so a
	 * plug-in's own header never stops a message.
	 *
	 * @param array<string, string> $headers The headers that are not From, Content-Type, Cc, Bcc or Reply-To.
	 *
	 * @return array<string, string>
	 */
	private static function custom_headers( array $headers ): array {
		$kept = array();
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( Settings::text( $name ) );
			if ( 'x-mailer' === $lower ) {
				// WordPress leaves it out too: the mailer is not the plug-in that called wp_mail().
				continue;
			}
			if ( isset( self::ALLOWED_HEADERS[ $lower ] ) ) {
				$name = self::ALLOWED_HEADERS[ $lower ];
			} elseif ( 1 !== preg_match( '/^X-[A-Za-z0-9-]{1,62}$/i', Settings::text( $name ) ) || 1 === preg_match( '/^x-(postilio-|tenant$|kumo)/i', Settings::text( $name ) ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[\x20-\x7E]{1,900}$/D', $value ) || isset( $kept[ $lower ] ) ) {
				continue;
			}
			$kept[ $lower ] = array( Settings::text( $name ), $value );
			if ( count( $kept ) === self::MAX_CUSTOM_HEADERS ) {
				break;
			}
		}

		return array_column( $kept, 1, 0 );
	}

	/**
	 * The text and HTML bodies, in UTF-8.
	 *
	 * @param string      $message      The message.
	 * @param string      $content_type The content type, after the wp_mail_content_type filter.
	 * @param string|null $boundary     A boundary from a header line of its own.
	 * @param string      $charset      The charset, after the wp_mail_charset filter.
	 *
	 * @return array{?string, ?string}
	 */
	private static function bodies( string $message, string $content_type, ?string $boundary, string $charset ): array {
		$type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		if ( str_starts_with( $type, 'multipart/' ) && null !== $boundary && '' !== $boundary ) {
			[ $text, $html ] = self::multipart( $message, $boundary );
			if ( null !== $text || null !== $html ) {
				return array( $text, $html );
			}
		}
		$body = self::to_utf8( $message, $charset );

		return 'text/html' === $type ? array( null, $body ) : array( $body, null );
	}

	/**
	 * The first text/plain and the first text/html part of a multipart body, decoded.
	 *
	 * @param string $message  The body.
	 * @param string $boundary The boundary.
	 *
	 * @return array{?string, ?string}
	 */
	private static function multipart( string $message, string $boundary ): array {
		$text = null;
		$html = null;
		foreach ( (array) preg_split( '/^--' . preg_quote( $boundary, '/' ) . '(?:--)?[ \t]*\r?$/m', $message ) as $part ) {
			$sections = (array) preg_split( '/\r?\n\r?\n/', ltrim( Settings::text( $part ), "\r\n" ), 2 );
			if ( count( $sections ) !== 2 ) {
				continue;
			}
			$part_headers = strtolower( Settings::text( $sections[0] ) );
			$body         = Settings::text( preg_replace( '/\r?\n$/', '', (string) $sections[1] ) );
			if ( str_contains( $part_headers, 'content-transfer-encoding: base64' ) ) {
				$body = Settings::text( base64_decode( $body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MIME transfer encoding.
			} elseif ( str_contains( $part_headers, 'content-transfer-encoding: quoted-printable' ) ) {
				$body = quoted_printable_decode( $body );
			}
			$charset = 1 === preg_match( '/charset\s*=\s*"?([^";\s]+)"?/i', $part_headers, $match ) ? $match[1] : 'utf-8';
			if ( null === $text && 1 === preg_match( '/^content-type:\s*text\/plain/m', $part_headers ) ) {
				$text = self::to_utf8( $body, $charset );
			} elseif ( null === $html && 1 === preg_match( '/^content-type:\s*text\/html/m', $part_headers ) ) {
				$html = self::to_utf8( $body, $charset );
			}
		}

		return array( $text, $html );
	}

	/**
	 * Converts text in a charset to UTF-8, as the API takes JSON.
	 *
	 * @param string $text    The text.
	 * @param string $charset Its charset; empty for UTF-8.
	 */
	private static function to_utf8( string $text, string $charset ): string {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return $text;
		}
		foreach ( mb_list_encodings() as $known ) {
			if ( 0 === strcasecmp( $known, $charset ) ) {
				return Settings::text( mb_convert_encoding( $text, 'UTF-8', $known ) );
			}
		}

		return $text;
	}

	/**
	 * The attachments and embeds, checked: only local files that can be read, no more than the API takes.
	 *
	 * @param mixed $attachments Paths, keyed by file name or not, or a string of lines.
	 * @param mixed $embeds      Paths keyed by Content-ID (WordPress 6.9 and later), or a string of lines.
	 *
	 * @return list<array{path: string, name: string, type: string, cid: ?string}>
	 *
	 * @throws MailError An attachment cannot be read safely, or there are too many.
	 */
	private static function files( mixed $attachments, mixed $embeds ): array {
		$files = array();
		foreach ( self::lines( $attachments ) as $name => $path ) {
			$files[] = array(
				'path' => $path,
				'name' => is_string( $name ) && '' !== $name ? $name : basename( $path ),
				'type' => '',
				'cid'  => null,
			);
		}
		foreach ( self::lines( $embeds ) as $cid => $path ) {
			$args = (array) apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
				'wp_mail_embed_args',
				array(
					'path'        => $path,
					'cid'         => Settings::text( $cid ),
					'name'        => basename( $path ),
					'encoding'    => 'base64',
					'type'        => '',
					'disposition' => 'inline',
				)
			);
			$files[] = array(
				'path' => Settings::text( $args['path'] ),
				'name' => Settings::text( $args['name'] ),
				'type' => Settings::text( $args['type'] ),
				'cid'  => Settings::text( $args['cid'] ),
			);
		}
		if ( count( $files ) > self::MAX_ATTACHMENTS ) {
			throw new MailError( 'too_many_attachments', 'The email has ' . count( $files ) . ' attachments; Postilio takes at most ' . self::MAX_ATTACHMENTS . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, never printed as HTML.
		}

		foreach ( $files as &$file ) {
			$path = $file['path'];
			if ( 1 === preg_match( '#^[a-z][a-z\d+.-]*:#i', $path ) || in_array( '..', (array) preg_split( '#[/\\\\]#', $path ), true ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new MailError( 'attachment_not_readable', 'The attachment ' . basename( $path ) . ' is not a file WordPress can read.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, never printed as HTML.
			}
			if ( '' === $file['type'] ) {
				$type         = wp_check_filetype( $file['name'] )['type'];
				$file['type'] = is_string( $type ) && '' !== $type ? $type : 'application/octet-stream';
			}
		}

		return $files;
	}

	/**
	 * Attachments or embeds as wp_mail() takes them: an array, or a string of lines.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return array<array-key, string> Without empty entries.
	 */
	private static function lines( mixed $value ): array {
		$entries = is_array( $value ) ? $value : explode( "\n", str_replace( "\r\n", "\n", Settings::text( $value ) ) );

		return array_filter( array_map( array( Settings::class, 'text' ), $entries ), static fn( string $entry ) => '' !== trim( $entry ) );
	}

	/**
	 * A key that is the same when the same call is sent again within the same clock hour (UTC), so a retry, by the
	 * caller or a queue, never sends twice; and differs per site, per request and per hour.
	 *
	 * @param SendEmailRequest $request The request.
	 * @param int              $now     The time.
	 */
	private static function idempotency_key( SendEmailRequest $request, int $now ): string {
		return 'wp-' . hash( 'sha256', home_url() . "\n" . gmdate( 'Y-m-d H', $now ) . "\n" . serialize( $request->toArray() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- only hashed.
	}
}
