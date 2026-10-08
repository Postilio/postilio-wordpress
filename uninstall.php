<?php
/**
 * Removes the plug-in's options, its stored API key included, when the plug-in is deleted.
 *
 * @package PostilioWp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$postilio_remove = static function (): void {
	delete_option( 'postilio_settings' );
	delete_option( 'postilio_api_key' );
	delete_transient( 'postilio_status' );
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $postilio_site ) {
		switch_to_blog( (int) $postilio_site );
		$postilio_remove();
		restore_current_blog();
	}
} else {
	$postilio_remove();
}
