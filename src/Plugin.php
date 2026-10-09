<?php
/**
 * Wires the plug-in into WordPress.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PostilioWp\Vendor\Postilio\PostilioClient;

/** The plug-in. */
final class Plugin {
	/** The plug-in's version; matches the header of postilio-for-wordpress.php. */
	public const VERSION = '0.1.0-alpha.2';

	/** Seconds for a request to the API; the http_request_args filter can change it. */
	private const TIMEOUT = 15.0;

	/**
	 * The mailer, made when the first email is sent, and again when the key or the settings change (in WP-CLI or a queue
	 * worker, which send many emails in one process).
	 *
	 * @var array{key: string, settings: Settings, mailer: Mailer}|null
	 */
	private static ?array $mailer = null;

	/**
	 * Hooks the plug-in in.
	 *
	 * @param string $file The plug-in's main file.
	 */
	public static function boot( string $file ): void {
		add_filter( 'pre_wp_mail', array( self::class, 'pre_wp_mail' ), 10, 2 );
		add_action(
			'init',
			static function () use ( $file ): void {
				load_plugin_textdomain( 'postilio-for-wordpress', false, dirname( plugin_basename( $file ) ) . '/languages' );
			}
		);
		if ( is_admin() ) {
			( new AdminPage( $file ) )->register();
		}
	}

	/**
	 * The pre_wp_mail filter: sends through Postilio once there is an API key, and leaves wp_mail() alone before that.
	 *
	 * @param mixed                $result Null, or what another plug-in already answered.
	 * @param array<string, mixed> $atts   The wp_mail() arguments.
	 */
	public static function pre_wp_mail( mixed $result, array $atts ): mixed {
		$mailer = self::mailer();

		return null === $mailer ? $result : $mailer->pre_wp_mail( $result, $atts );
	}

	/** The mailer, or null while no API key is set. */
	public static function mailer(): ?Mailer {
		$key = Settings::api_key();
		if ( null === $key ) {
			return null;
		}
		$settings = Settings::load();
		if ( null === self::$mailer || self::$mailer['key'] !== $key || self::$mailer['settings'] != $settings ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- compares the values of two settings.
			self::$mailer = array(
				'key'      => $key,
				'settings' => $settings,
				'mailer'   => new Mailer(
					self::client( $key ),
					$settings,
					static function ( string $line ): void {
						error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failures belong in the PHP error log; the line holds no key, recipient or content.
					}
				),
			);
		}

		return self::$mailer['mailer'];
	}

	/**
	 * A client for the key, sending through the WordPress HTTP API; null when it is not a Postilio key.
	 *
	 * @param string $key The API key.
	 */
	public static function client( string $key ): ?PostilioClient {
		if ( ! Settings::valid_key( $key ) ) {
			return null;
		}
		$factory = new Psr17Factory();

		return new PostilioClient(
			$key,
			new WpHttpClient( self::TIMEOUT ),
			$factory,
			$factory,
			baseUrl: defined( 'POSTILIO_API_URL' ) ? Settings::text( constant( 'POSTILIO_API_URL' ) ) : 'https://api.postilio.eu',
			maxRetries: 2,
			maxRetryDelay: 5.0,
		);
	}
}
