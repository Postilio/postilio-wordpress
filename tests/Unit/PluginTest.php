<?php
/**
 * Tests for the plug-in's wiring.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PostilioWp\Plugin;

final class PluginTest extends TestCase {
	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ) => $this->options[ $name ] ?? $fallback );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_mailer_is_kept_for_a_request_and_made_again_when_the_settings_change(): void {
		$this->options = array(
			'postilio_api_key'  => 'pk_test_abcdEFGH0123456789abcdefghijklmn',
			'postilio_settings' => array(
				'from_email' => 'a@mail.example.com',
				'force_from' => true,
			),
		);
		$first         = Plugin::mailer();

		self::assertNotNull( $first );
		self::assertSame( $first, Plugin::mailer() );

		$this->options['postilio_settings']['force_from'] = false;
		self::assertNotSame( $first, Plugin::mailer() );

		$this->options['postilio_api_key'] = '';
		self::assertNull( Plugin::mailer() );
	}

	public function test_client_is_made_for_a_postilio_key_only(): void {
		self::assertNotNull( Plugin::client( 'pk_live_abcdEFGH0123456789abcdefghijklmn' ) );
		self::assertNull( Plugin::client( 'xx_live_abcdEFGH0123456789abcdefghijklmn' ) );
	}
}
