<?php
/**
 * A fake Postilio API for the integration test, loaded as a must-use plug-in: it answers /fake-postilio/v1/… before
 * WordPress routes the request, and writes each request it gets to wp-content/fake-postilio.jsonl.
 *
 * Recipients steer the answer: fail@example.test gets 503 service_degraded, suppressed@example.test is suppressed, and a
 * sender outside mail.example.test gets 422 unverified_sender_domain.
 *
 * @package PostilioWp
 */

$postilio_fake_path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
if ( ! is_string( $postilio_fake_path ) || ! str_starts_with( $postilio_fake_path, '/fake-postilio/' ) ) {
	return;
}

( static function ( string $path ): void {
	$headers = array_change_key_case( function_exists( 'getallheaders' ) ? (array) getallheaders() : array() );
	$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$raw     = (string) file_get_contents( 'php://input' );
	$body    = json_decode( $raw, true );
	file_put_contents(
		WP_CONTENT_DIR . '/fake-postilio.jsonl',
		wp_json_encode(
			array(
				'method'  => $method,
				'path'    => $path,
				'headers' => array_intersect_key( $headers, array_flip( array( 'authorization', 'idempotency-key', 'user-agent', 'content-type' ) ) ),
				'body'    => $body,
			)
		) . "\n",
		FILE_APPEND | LOCK_EX
	);

	$answer = static function ( int $status, ?array $json, array $extra = array() ): void {
		http_response_code( $status );
		header( 'Content-Type: application/json' );
		foreach ( $extra as $name => $value ) {
			header( "$name: $value" );
		}
		echo null === $json ? '' : wp_json_encode( $json );
		exit;
	};

	if ( 'Bearer pk_test_00000000000000000000000000000000' !== ( $headers['authorization'] ?? '' ) ) {
		$answer( 401, array( 'error' => 'invalid_api_key' ) );
	}
	$route = substr( $path, strlen( '/fake-postilio' ) );
	if ( 'POST' === $method && '/v1/emails' === $route && is_array( $body ) ) {
		if ( ! str_ends_with( preg_replace( '/^.*<|>$/', '', (string) $body['from'] ), '@mail.example.test' ) ) {
			$answer( 422, array( 'error' => 'unverified_sender_domain' ) );
		}
		$recipients = array_merge( $body['to'] ?? array(), $body['cc'] ?? array(), $body['bcc'] ?? array() );
		if ( in_array( 'fail@example.test', $recipients, true ) ) {
			$answer( 503, array( 'error' => 'service_degraded' ), array( 'Retry-After' => '60' ) );
		}
		$ids = array();
		foreach ( $recipients as $recipient ) {
			$ids[] = '01a1' . substr( md5( (string) $recipient . ( $headers['idempotency-key'] ?? '' ) ), 0, 28 );
		}
		$answer(
			202,
			array(
				'ids'        => $ids,
				'suppressed' => array_values( array_intersect( $recipients, array( 'suppressed@example.test' ) ) ),
			)
		);
	}
	if ( 'GET' === $method && str_starts_with( $route, '/v1/emails/' ) ) {
		$event = array(
			'occurredAt'     => '2026-10-08T12:00:00+00:00',
			'smtpCode'       => null,
			'response'       => null,
			'attempt'        => null,
			'remoteHost'     => null,
			'enhancedCode'   => null,
			'classification' => null,
			'reason'         => null,
		);
		$answer(
			200,
			array(
				'id'         => substr( $route, strlen( '/v1/emails/' ) ),
				'status'     => 'delivered',
				'from'       => 'no-reply@mail.example.test',
				'to'         => 'ada@example.test',
				'subject'    => 'Test',
				'tag'        => 'wordpress',
				'acceptedAt' => '2026-10-08T12:00:00+00:00',
				'test'       => true,
				'via'        => 'api',
				'sendAt'     => null,
				'events'     => array(
					array( 'type' => 'accepted' ) + $event,
					array(
						'type'       => 'delivered',
						'smtpCode'   => 250,
						'response'   => '250 2.0.0 Ok',
						'attempt'    => 1,
						'remoteHost' => 'mx.simulator.postilio.eu',
					) + $event,
				),
			)
		);
	}
	if ( 'GET' === $method && '/v1/domains' === $route ) {
		$answer( 403, array( 'error' => 'insufficient_scope' ) );
	}
	$answer( 404, null );
} )( $postilio_fake_path );
