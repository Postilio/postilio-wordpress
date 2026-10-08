<?php
/**
 * Tests for the settings and the API key.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PostilioWp\Settings;

final class SettingsTest extends TestCase {
	private const KEY = 'pk_live_abcdEFGH0123456789abcdefghijklmn';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	public function test_load_forces_the_sender_unless_it_was_turned_off(): void {
		Functions\when( 'get_option' )->justReturn( false );
		self::assertTrue( Settings::load()->force_from );

		Functions\when( 'get_option' )->justReturn(
			array(
				'from_email' => 'no-reply@mail.example.com',
				'from_name'  => 'Acme',
				'force_from' => false,
			)
		);
		$settings = Settings::load();
		self::assertSame( array( 'no-reply@mail.example.com', 'Acme', false ), array( $settings->from_email, $settings->from_name, $settings->force_from ) );
	}

	public function test_api_key_comes_from_the_option_without_the_constant(): void {
		Functions\expect( 'get_option' )->with( 'postilio_api_key', '' )->andReturn( ' ' . self::KEY . ' ' );

		self::assertSame( self::KEY, Settings::api_key() );
		self::assertSame( 'option', Settings::api_key_source() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_api_key_comes_from_the_constant_before_the_option(): void {
		define( 'POSTILIO_API_KEY', self::KEY );
		Functions\expect( 'get_option' )->never();

		self::assertSame( self::KEY, Settings::api_key() );
		self::assertSame( 'constant', Settings::api_key_source() );
	}

	public function test_api_key_is_null_when_none_is_set(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		self::assertNull( Settings::api_key() );
		self::assertNull( Settings::api_key_source() );
	}

	public function test_mask_shows_the_mode_and_the_first_characters_only(): void {
		self::assertSame( 'pk_live_abcd…', Settings::mask( self::KEY ) );
	}

	public function test_sanitize_stores_a_valid_key_apart_without_autoload_and_never_in_the_settings(): void {
		Functions\expect( 'update_option' )->once()->with( 'postilio_api_key', self::KEY, false );

		$saved = Settings::sanitize(
			array(
				'api_key'    => ' ' . self::KEY,
				'from_email' => 'no-reply@mail.example.com',
				'from_name'  => 'Acme',
				'force_from' => '1',
			)
		);

		self::assertSame(
			array(
				'from_email' => 'no-reply@mail.example.com',
				'from_name'  => 'Acme',
				'force_from' => true,
			),
			$saved
		);
	}

	public function test_sanitize_refuses_a_key_that_is_not_a_postilio_key_and_keeps_the_old_one(): void {
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'add_settings_error' )->once()->with( 'postilio_settings', 'invalid_api_key', \Mockery::type( 'string' ) );

		$saved = Settings::sanitize( array( 'api_key' => 'sk_live_whatever' ) );

		self::assertArrayNotHasKey( 'api_key', $saved );
		self::assertFalse( $saved['force_from'] );
	}

	public function test_sanitize_removes_the_stored_key_on_request(): void {
		Functions\expect( 'delete_option' )->once()->with( 'postilio_api_key' );

		Settings::sanitize( array( 'remove_api_key' => '1' ) );
	}

	public function test_sanitize_refuses_a_sender_that_is_not_an_address_and_keeps_the_old_one(): void {
		Functions\when( 'get_option' )->justReturn( array( 'from_email' => 'old@mail.example.com' ) );
		Functions\expect( 'add_settings_error' )->once()->with( 'postilio_settings', 'invalid_from_email', \Mockery::type( 'string' ) );

		self::assertSame( 'old@mail.example.com', Settings::sanitize( array( 'from_email' => 'nobody' ) )['from_email'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sanitize_ignores_a_key_while_the_constant_is_set(): void {
		define( 'POSTILIO_API_KEY', self::KEY );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		Settings::sanitize(
			array(
				'api_key'        => 'pk_test_abcdEFGH0123456789abcdefghijklmn',
				'remove_api_key' => '1',
			)
		);
	}
}
