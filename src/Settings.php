<?php
/**
 * The plug-in's settings and its API key.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

/** The sender the plug-in sends as, and where the API key comes from. */
final class Settings {
	/** The option with the sender settings. */
	public const OPTION = 'postilio_settings';

	/** The option with the API key when it is not in wp-config.php; never autoloaded. */
	public const KEY_OPTION = 'postilio_api_key';

	/**
	 * Creates the settings.
	 *
	 * @param string $from_email The sender's address, on a verified domain; empty for WordPress's default.
	 * @param string $from_name  The sender's name.
	 * @param bool   $force_from Whether this sender wins over a From header and the wp_mail_from filters.
	 */
	public function __construct(
		public readonly string $from_email,
		public readonly string $from_name,
		public readonly bool $force_from,
	) {}

	/**
	 * A value from WordPress (an option, a constant, a request field) as a string; anything but a scalar is empty.
	 *
	 * @param mixed $value The value.
	 */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** The saved settings; the sender is forced until that is turned off. */
	public static function load(): self {
		$saved = get_option( self::OPTION, false );
		if ( ! is_array( $saved ) ) {
			return new self( '', '', true );
		}

		return new self( self::text( $saved['from_email'] ?? '' ), self::text( $saved['from_name'] ?? '' ), (bool) ( $saved['force_from'] ?? true ) );
	}

	/** The API key: the POSTILIO_API_KEY constant, or else the one saved on the settings page; null for none. */
	public static function api_key(): ?string {
		$key = self::constant_key() ?? trim( self::text( get_option( self::KEY_OPTION, '' ) ) );

		return '' === $key ? null : $key;
	}

	/** Where the key comes from: 'constant', 'option', or null for no key. */
	public static function api_key_source(): ?string {
		if ( null !== self::constant_key() ) {
			return 'constant';
		}

		return '' === trim( self::text( get_option( self::KEY_OPTION, '' ) ) ) ? null : 'option';
	}

	/** The POSTILIO_API_KEY constant from wp-config.php; null when it is not set. */
	private static function constant_key(): ?string {
		$key = defined( 'POSTILIO_API_KEY' ) ? trim( self::text( constant( 'POSTILIO_API_KEY' ) ) ) : '';

		return '' === $key ? null : $key;
	}

	/**
	 * Whether a key has the form of a Postilio API key.
	 *
	 * @param string $key The key.
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^pk_(live|test)_[A-Za-z0-9]{32}$/D', $key );
	}

	/**
	 * The key as the settings page shows it: the mode and the first characters, as the portal shows them.
	 *
	 * @param string $key The key.
	 */
	public static function mask( string $key ): string {
		return substr( $key, 0, 12 ) . '…';
	}

	/**
	 * The register_setting() callback: stores a new API key in an option of its own, without autoload, and returns the
	 * sender settings without it.
	 *
	 * @param mixed $input The submitted fields.
	 *
	 * @return array{from_email: string, from_name: string, force_from: bool}
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();

		if ( null === self::constant_key() ) {
			$key = trim( self::text( $input['api_key'] ?? '' ) );
			if ( ! empty( $input['remove_api_key'] ) ) {
				delete_option( self::KEY_OPTION );
			} elseif ( '' !== $key && self::valid_key( $key ) ) {
				update_option( self::KEY_OPTION, $key, false );
			} elseif ( '' !== $key ) {
				add_settings_error( self::OPTION, 'invalid_api_key', __( 'That is not a Postilio API key: it starts with pk_live_ or pk_test_ and has 32 letters and digits after that. The key you had is kept.', 'postilio-for-wordpress' ) );
			}
		}

		$from_email = sanitize_email( self::text( $input['from_email'] ?? '' ) );
		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			add_settings_error( self::OPTION, 'invalid_from_email', __( 'The sender address is not a valid email address. The address you had is kept.', 'postilio-for-wordpress' ) );
			$saved      = get_option( self::OPTION, array() );
			$from_email = is_array( $saved ) ? self::text( $saved['from_email'] ?? '' ) : '';
		}
		delete_transient( Status::TRANSIENT );

		return array(
			'from_email' => $from_email,
			'from_name'  => sanitize_text_field( self::text( $input['from_name'] ?? '' ) ),
			'force_from' => ! empty( $input['force_from'] ),
		);
	}
}
