<?php
/**
 * The integration scenarios. Run in the wp-env CLI container with `wp eval-file`: WordPress, the plug-in as built into the
 * ZIP and the fake API (fake-postilio.php) are real, and every email goes over HTTP through wp_remote_request().
 *
 * @package PostilioWp
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security, WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.GlobalVariablesOverride -- wp eval-file runs this in a function scope.

$postilio_log = WP_CONTENT_DIR . '/fake-postilio.jsonl';
$checks       = 0;
$failed       = 0;
$check        = static function ( string $name, bool $ok, mixed $detail = null ) use ( &$checks, &$failed ): void {
	++$checks;
	if ( $ok ) {
		WP_CLI::log( "ok $checks - $name" );
		return;
	}
	++$failed;
	WP_CLI::warning( "not ok $checks - $name" . ( null === $detail ? '' : ': ' . wp_json_encode( $detail ) ) );
};
$requests     = static function () use ( $postilio_log ): array {
	return is_file( $postilio_log ) ? array_map( static fn( $line ) => json_decode( $line, true ), array_values( array_filter( explode( "\n", (string) file_get_contents( $postilio_log ) ) ) ) ) : array();
};
$reset        = static function () use ( $postilio_log ): void {
	if ( is_file( $postilio_log ) ) {
		unlink( $postilio_log );
	}
};
$failures     = array();
$successes    = array();
$phpmailer    = false;
add_action(
	'wp_mail_failed',
	static function ( $error ) use ( &$failures ) {
		$failures[] = $error;
	}
);
add_action(
	'wp_mail_succeeded',
	static function ( $data ) use ( &$successes ) {
		$successes[] = $data;
	}
);
add_action(
	'phpmailer_init',
	static function () use ( &$phpmailer ) {
		$phpmailer = true;
	}
);
$code     = static fn( array $errors ) => isset( $errors[0] ) ? ( $errors[0]->get_error_data()['postilio_error'] ?? null ) : null;
$settings = array(
	'from_email' => 'no-reply@mail.example.test',
	'from_name'  => 'Acme',
	'force_from' => true,
);
update_option( 'postilio_settings', $settings );

file_put_contents( WP_CONTENT_DIR . '/debug.log', '' ); // This run's lines only.
WP_CLI::log( 'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION );
$check( 'the plug-in is active', is_plugin_active( 'postilio-for-wordpress/postilio-for-wordpress.php' ) );

// A plain email.
$reset();
$sent    = wp_mail( 'Ada Lovelace <ada@example.test>', 'Hello', 'Hi Ada' );
$request = $requests()[0] ?? array();
$check( 'wp_mail() sends one request to the API and returns true', true === $sent && 1 === count( $requests() ) && 'POST' === ( $request['method'] ?? null ), $requests() );
$check(
	'from the configured sender, to the bare address, with the tag and the text body',
	'Acme <no-reply@mail.example.test>' === ( $request['body']['from'] ?? null ) && array( 'ada@example.test' ) === ( $request['body']['to'] ?? null )
		&& 'wordpress' === ( $request['body']['tag'] ?? null ) && 'Hi Ada' === ( $request['body']['text'] ?? null ) && ! isset( $request['body']['html'] ),
	$request['body'] ?? null
);
$check(
	'with the key, an Idempotency-Key and the plug-in in the User-Agent',
	'Bearer pk_test_00000000000000000000000000000000' === ( $request['headers']['authorization'] ?? null )
		&& 1 === preg_match( '/^wp-[0-9a-f]{64}$/', (string) ( $request['headers']['idempotency-key'] ?? '' ) )
		&& str_contains( (string) ( $request['headers']['user-agent'] ?? '' ), 'postilio-for-wordpress/0.1.0-alpha.2' ),
	$request['headers'] ?? null
);
$check( 'fires wp_mail_succeeded and not PHPMailer', 1 === count( $successes ) && array() === $failures && false === $phpmailer );

// HTML, Cc, Bcc, custom headers and an attachment.
$file = wp_upload_dir()['basedir'] . '/postilio-integration.pdf';
file_put_contents( $file, '%PDF-1.4 test' );
$reset();
$sent = wp_mail(
	'ada@example.test',
	'Invoice 1042',
	'<p>Your invoice</p>',
	array( 'Content-Type: text/html; charset=UTF-8', 'Cc: Grace <grace@example.test>', 'Bcc: archive@example.test', 'Reply-To: Support <support@example.test>', 'X-Order-Id: 1042', 'X-Mailer: Other', 'Importance: high' ),
	array( 'Invoice 1042.pdf' => $file )
);
unlink( $file );
$body = $requests()[0]['body'] ?? array();
$check(
	'HTML, Cc, Bcc, Reply-To and the allowed headers go through',
	true === $sent && '<p>Your invoice</p>' === ( $body['html'] ?? null ) && array( 'grace@example.test' ) === ( $body['cc'] ?? null ) && array( 'archive@example.test' ) === ( $body['bcc'] ?? null )
		&& 'Support <support@example.test>' === ( $body['replyTo'] ?? null ) && array( 'X-Order-Id' => '1042' ) === ( $body['headers'] ?? null ),
	$body
);
$check(
	'the attachment goes with its name, type and content',
	'Invoice 1042.pdf' === ( $body['attachments'][0]['fileName'] ?? null ) && 'application/pdf' === ( $body['attachments'][0]['contentType'] ?? null )
		&& base64_encode( '%PDF-1.4 test' ) === ( $body['attachments'][0]['content'] ?? null ),
	$body['attachments'] ?? null
);

// A refusal: no fallback to PHP's mail().
$reset();
$failures  = array();
$phpmailer = false;
$sent      = wp_mail( 'fail@example.test', 'Hello', 'Hi' );
$check( 'an API refusal makes wp_mail() return false', false === $sent );
$check( 'and fires wp_mail_failed with the Postilio code', 1 === count( $failures ) && 'service_degraded' === $code( $failures ), array_map( static fn( $e ) => $e->get_error_message(), $failures ) );
$check( 'and does not fall back to PHPMailer or mail()', false === $phpmailer );
$debug_log = is_file( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
$check(
	'and writes one line to the PHP error log, without the recipient or the key',
	str_contains( $debug_log, 'Postilio for WordPress: POST /v1/emails answered 503 (service_degraded).' ) && ! str_contains( $debug_log, 'fail@example.test' ) && ! str_contains( $debug_log, POSTILIO_API_KEY )
);

// Without force, the plug-in's From header is used, and Postilio refuses a domain that is not verified.
update_option( 'postilio_settings', array( 'force_from' => false ) + $settings );
$reset();
$failures = array();
$sent     = wp_mail( 'ada@example.test', 'Hello', 'Hi', 'From: Shop <shop@other.test>' );
$check(
	'without force the From header is sent, and an unverified domain is refused',
	false === $sent && 'Shop <shop@other.test>' === ( $requests()[0]['body']['from'] ?? null ) && 'unverified_sender_domain' === $code( $failures ),
	$requests()
);
update_option( 'postilio_settings', $settings );

// Several To addresses with a Bcc: refused before sending.
$reset();
$failures = array();
$sent     = wp_mail( array( 'a@example.test', 'b@example.test' ), 'Hello', 'Hi', 'Bcc: c@example.test' );
$check( 'several To addresses with a Bcc are refused and nothing is sent', false === $sent && array() === $requests() && 'cc_bcc_require_single_to' === $code( $failures ) );

// The status line, over HTTP.
$status = PostilioWp\Status::check( PostilioWp\Plugin::client( POSTILIO_API_KEY ), POSTILIO_API_KEY, 'no-reply@mail.example.test', time() );
$check( 'the status line reads the key as accepted, test mode, domain verified: a test key may read domains', 'accepted' === $status['key'] && 'test' === $status['mode'] && 'verified' === $status['domain'], $status );

// The settings page: labels, and the key never in the page.
require_once ABSPATH . 'wp-admin/includes/template.php';
$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
)[0];
wp_set_current_user( $admin->ID );
$page = new PostilioWp\AdminPage( WP_PLUGIN_DIR . '/postilio-for-wordpress/postilio-for-wordpress.php' );
$page->settings();
ob_start();
do_settings_sections( PostilioWp\AdminPage::SLUG );
$html = (string) ob_get_clean();
$check(
	'the settings fields have labels and show the key masked only',
	str_contains( $html, '<label for="postilio_from_email">' ) && str_contains( $html, '<label for="postilio_from_name">' )
		&& str_contains( $html, 'pk_test_0000…' ) && ! str_contains( $html, POSTILIO_API_KEY )
		&& ! str_contains( $html, '<label for="postilio_api_key">' ), // With the key in wp-config.php there is no field to label.
	$html
);

// The test email, through admin-ajax's handler.
$page->register();
define( 'DOING_AJAX', true );
add_filter(
	'wp_die_ajax_handler',
	static fn() => static function ( $message, $title = '', $args = array() ) {
		throw new RuntimeException( 'die:' . ( is_scalar( $message ) ? $message : '' ) . ':' . ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : '' ) );
	}
);
$ajax = static function ( array $post ): array {
	$_POST    = $post;
	$_REQUEST = $post;
	$died     = '';
	ob_start();
	try {
		do_action( 'wp_ajax_postilio_send_test' );
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	}
	return array( (string) ob_get_clean(), $died );
};
$reset();
[ , $died ] = $ajax(
	array(
		'nonce' => 'wrong',
		'to'    => 'ada@example.test',
	)
);
$check( 'the test email refuses a wrong nonce and sends nothing', str_starts_with( $died, 'die:-1:403' ) && array() === $requests(), $died );
[ $output ] = $ajax(
	array(
		'nonce' => wp_create_nonce( PostilioWp\AdminPage::NONCE ),
		'to'    => 'ada@example.test',
	)
);
$answer     = json_decode( $output, true );
$check(
	'the test email goes through wp_mail() and shows the delivery',
	true === ( $answer['success'] ?? null ) && 1 === count( $answer['data']['ids'] ?? array() ) && 'delivered' === ( $answer['data']['status'] ?? null )
		&& 2 === count( $answer['data']['events'] ?? array() ) && 'wordpress' === ( $requests()[0]['body']['tag'] ?? null ),
	$answer
);
$subscriber = wp_insert_user(
	array(
		'user_login' => 'postilio-subscriber-' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
wp_set_current_user( $subscriber );
$reset();
[ $output ] = $ajax(
	array(
		'nonce' => wp_create_nonce( PostilioWp\AdminPage::NONCE ),
		'to'    => 'ada@example.test',
	)
);
$check( 'the test email refuses a user who may not manage options', false === ( json_decode( $output, true )['success'] ?? null ) && array() === $requests(), $output );
wp_delete_user( $subscriber );
wp_set_current_user( $admin->ID );

// Uninstalling removes the options.
update_option( 'postilio_api_key', 'pk_test_11111111111111111111111111111111', false );
set_transient( 'postilio_status', array( 'key' => 'accepted' ) );
define( 'WP_UNINSTALL_PLUGIN', 'postilio-for-wordpress/postilio-for-wordpress.php' );
require WP_PLUGIN_DIR . '/postilio-for-wordpress/uninstall.php';
global $wpdb;
$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%postilio%'" ); // phpcs:ignore WordPress.DB
$check( 'uninstall removes the settings, the stored key and the status from the database', array() === $left, $left );

// No PHP notices, warnings or deprecations from the plug-in.
$debug = is_file( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
$check( 'no PHP notice, warning or deprecation from the plug-in in debug.log', ! preg_match( '/(Notice|Warning|Deprecated|Fatal)[^\n]*postilio-for-wordpress/i', $debug ), $debug );
$reset();

if ( $failed > 0 ) {
	WP_CLI::error( "$failed of $checks checks failed." );
}
WP_CLI::success( "All $checks checks passed." );
