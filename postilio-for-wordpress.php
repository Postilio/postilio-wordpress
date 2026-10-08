<?php
/**
 * Plugin Name:       Postilio for WordPress
 * Plugin URI:        https://github.com/Postilio/postilio-wordpress
 * Description:       Sends your site's email through Postilio, European transactional email, instead of the server's own mail function.
 * Version:           0.1.0-alpha.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Postilio
 * Author URI:        https://postilio.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       postilio-for-wordpress
 * Domain Path:       /languages
 *
 * @package PostilioWp
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor-prefixed/autoload.php';

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'PostilioWp\\' ) && ! str_starts_with( $class_name, 'PostilioWp\\Vendor\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PostilioWp\\' ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

PostilioWp\Plugin::boot( __FILE__ );
