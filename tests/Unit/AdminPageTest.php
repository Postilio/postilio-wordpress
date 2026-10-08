<?php
/**
 * Tests for the guards of the settings page's actions.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Functions;
use PostilioWp\AdminPage;

final class AdminPageTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data, $status ) {
				throw new JsonAnswer( false, $data, $status );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_ajax_send_test_checks_the_nonce_before_anything_else(): void {
		Functions\expect( 'check_ajax_referer' )->once()->with( 'postilio_admin', 'nonce' )->andReturnUsing(
			static function () {
				throw new JsonAnswer( false, -1, 403 );
			}
		);
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'wp_mail' )->never();

		$answer = $this->answer( static fn() => ( new AdminPage( '/plugins/postilio-for-wordpress/postilio-for-wordpress.php' ) )->ajax_send_test() );

		self::assertSame( 403, $answer->status );
	}

	public function test_ajax_send_test_refuses_a_user_who_may_not_manage_options(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_mail' )->never();

		$answer = $this->answer( static fn() => ( new AdminPage( '/plugins/postilio-for-wordpress/postilio-for-wordpress.php' ) )->ajax_send_test() );

		self::assertSame( 403, $answer->status );
		self::assertSame( array( 'message' => 'You may not send a test email.' ), $answer->data );
	}

	public function test_ajax_send_test_refuses_a_recipient_that_is_not_an_address(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_mail' )->never();
		$_POST['to'] = 'nobody';

		$answer = $this->answer( static fn() => ( new AdminPage( '/plugins/postilio-for-wordpress/postilio-for-wordpress.php' ) )->ajax_send_test() );

		self::assertSame( 400, $answer->status );
		self::assertSame( array( 'message' => 'Enter a valid email address.' ), $answer->data );
	}

	public function test_refresh_status_checks_the_nonce_and_the_capability(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( 'postilio_refresh_status' )->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		Functions\expect( 'wp_die' )->once()->andReturnUsing(
			static function ( $message, $title, $args ) {
				throw new JsonAnswer( false, $message, is_array( $args ) ? $args['response'] ?? null : null );
			}
		);
		Functions\expect( 'delete_transient' )->never();

		$answer = $this->answer( static fn() => ( new AdminPage( '/plugins/postilio-for-wordpress/postilio-for-wordpress.php' ) )->refresh_status() );

		self::assertSame( 403, $answer->status );
	}

	private function answer( callable $call ): JsonAnswer {
		try {
			$call();
		} catch ( JsonAnswer $answer ) {
			return $answer;
		}
		self::fail( 'No answer.' );
	}
}

/** Stops a handler where WordPress would send JSON and exit. */
final class JsonAnswer extends \RuntimeException {
	/**
	 * Holds the answer.
	 *
	 * @param bool  $success Whether it was a success.
	 * @param mixed $data    The data.
	 * @param mixed $status  The HTTP status.
	 */
	public function __construct( public readonly bool $success, public readonly mixed $data, public readonly mixed $status ) {
		parent::__construct( 'answered' );
	}
}
