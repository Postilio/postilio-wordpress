<?php
/**
 * Tests for sending a wp_mail() call through the API.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PostilioWp\Mailer;
use PostilioWp\Settings;
use PostilioWp\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PostilioWp\Vendor\Nyholm\Psr7\Response;
use PostilioWp\Vendor\Postilio\PostilioClient;
use PostilioWp\Vendor\Psr\Http\Client\ClientInterface;
use PostilioWp\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use PostilioWp\Vendor\Psr\Http\Message\RequestInterface;
use PostilioWp\Vendor\Psr\Http\Message\ResponseInterface;

final class MailerTest extends TestCase {
	private const KEY = 'pk_test_abcdefghijklmnopqrstuvwxyz012345';

	/** @var list<RequestInterface> */
	private array $requests = array();

	/** @var list<ResponseInterface|\Throwable> The answers, in turn. */
	private array $answers = array();

	/** @var list<string> */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
	}

	public function test_pre_wp_mail_sends_each_request_with_its_key_and_reports_success(): void {
		$this->answers = array( self::json( 202, '{"ids":["01a1"],"suppressed":[]}' ), self::json( 202, '{"ids":["01a2"],"suppressed":["x@example.org"]}' ) );
		$to            = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$to[] = "user$i@example.org";
		}
		Actions\expectDone( 'wp_mail_failed' )->never();
		Actions\expectDone( 'wp_mail_succeeded' )->once()->with( \Mockery::on( static fn( array $data ) => $to === $data['to'] && 'Hello' === $data['subject'] ) );

		$sent = $this->mailer()->pre_wp_mail( null, self::atts( $to ) );

		self::assertTrue( $sent );
		self::assertCount( 2, $this->requests );
		self::assertMatchesRegularExpression( '/^wp-[0-9a-f]{64}$/', $this->requests[0]->getHeaderLine( 'Idempotency-Key' ) );
		self::assertNotSame( $this->requests[0]->getHeaderLine( 'Idempotency-Key' ), $this->requests[1]->getHeaderLine( 'Idempotency-Key' ) );
		self::assertSame( 'Bearer ' . self::KEY, $this->requests[0]->getHeaderLine( 'Authorization' ) );
		self::assertSame(
			array(
				'ids'        => array( '01a1', '01a2' ),
				'suppressed' => array( 'x@example.org' ),
				'error'      => null,
			),
			$this->mailer->last_result()
		);
	}

	public function test_pre_wp_mail_reports_an_api_refusal_and_does_not_fall_back_to_php_mail(): void {
		$this->answers = array( self::json( 422, '{"error":"unverified_sender_domain"}' ) );
		$error         = null;
		Actions\expectDone( 'wp_mail_failed' )->once()->with( \Mockery::capture( $error ) );
		Actions\expectDone( 'wp_mail_succeeded' )->never();

		$sent = $this->mailer()->pre_wp_mail( null, self::atts( 'ada@example.org' ) );

		self::assertFalse( $sent );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'wp_mail_failed', self::error( $error )->get_error_code() );
		self::assertStringContainsString( 'unverified_sender_domain', self::error( $error )->get_error_message() );
		self::assertSame( 'unverified_sender_domain', self::data( $error )['postilio_error'] );
		self::assertSame( 422, self::data( $error )['postilio_status'] );
		self::assertSame( array( 'ada@example.org' ), self::data( $error )['to'] );
		self::assertCount( 1, $this->logged );
		self::assertStringContainsString( 'unverified_sender_domain', $this->logged[0] );
		self::assertStringNotContainsString( 'ada@example.org', $this->logged[0] );
		self::assertStringNotContainsString( self::KEY, $this->logged[0] );
		self::assertSame( $error, ( $this->mailer->last_result() ?? array( 'error' => null ) )['error'] );
	}

	public function test_pre_wp_mail_reports_a_call_it_cannot_translate_without_sending(): void {
		$error = null;
		Actions\expectDone( 'wp_mail_failed' )->once()->with( \Mockery::capture( $error ) );

		$sent = $this->mailer()->pre_wp_mail( null, self::atts( 'not-an-address' ) );

		self::assertFalse( $sent );
		self::assertSame( array(), $this->requests );
		self::assertSame( 'no_recipients', self::data( $error )['postilio_error'] );
		self::assertNull( self::data( $error )['postilio_status'] );
	}

	public function test_pre_wp_mail_reports_a_network_failure(): void {
		$network       = new class( 'cURL error 28' ) extends \RuntimeException implements NetworkExceptionInterface {
			public function getRequest(): RequestInterface {
				return ( new Psr17Factory() )->createRequest( 'GET', 'https://api.postilio.eu' );
			}
		};
		$this->answers = array( $network, $network, $network );
		$error         = null;
		Actions\expectDone( 'wp_mail_failed' )->once()->with( \Mockery::capture( $error ) );

		self::assertFalse( $this->mailer()->pre_wp_mail( null, self::atts( 'ada@example.org' ) ) );
		self::assertSame( 'transport_error', self::data( $error )['postilio_error'] );
		self::assertStringContainsString( 'cURL error 28', self::error( $error )->get_error_message() );
	}

	public function test_pre_wp_mail_names_what_was_sent_before_a_later_request_failed(): void {
		$this->answers = array( self::json( 202, '{"ids":["01a1"],"suppressed":[]}' ), self::json( 429, '{"error":"plan_daily_limit_reached"}', array( 'Retry-After' => '3600' ) ) );
		$to            = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$to[] = "user$i@example.org";
		}
		$error = null;
		Actions\expectDone( 'wp_mail_failed' )->once()->with( \Mockery::capture( $error ) );

		self::assertFalse( $this->mailer()->pre_wp_mail( null, self::atts( $to ) ) );
		self::assertSame( array( '01a1' ), self::data( $error )['postilio_ids'] );
		self::assertStringContainsString( '50 of 51 recipients were accepted', self::error( $error )->get_error_message() );
	}

	public function test_pre_wp_mail_refuses_every_call_while_the_key_is_not_a_postilio_key(): void {
		$error = null;
		Actions\expectDone( 'wp_mail_failed' )->once()->with( \Mockery::capture( $error ) );
		$mailer = new Mailer( null, new Settings( 'no-reply@mail.example.com', 'Acme', true ), static function (): void {} );

		self::assertFalse( $mailer->pre_wp_mail( null, self::atts( 'ada@example.org' ) ) );
		self::assertSame( 'invalid_api_key', self::data( $error )['postilio_error'] );
	}

	public function test_pre_wp_mail_leaves_a_call_another_plugin_answered_alone(): void {
		self::assertTrue( $this->mailer()->pre_wp_mail( true, self::atts( 'ada@example.org' ) ) );
		self::assertSame( array(), $this->requests );
	}

	private Mailer $mailer;

	private static function error( mixed $error ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $error );

		return $error;
	}

	/**
	 * The data of a WP_Error.
	 *
	 * @param mixed $error The error.
	 *
	 * @return array<mixed>
	 */
	private static function data( mixed $error ): array {
		$data = self::error( $error )->get_error_data();
		self::assertIsArray( $data );

		return $data;
	}

	private function mailer(): Mailer {
		$test         = $this;
		$client       = new class( $test ) implements ClientInterface {
			public function __construct( private readonly MailerTest $test ) {}

			public function sendRequest( RequestInterface $request ): ResponseInterface {
				return $this->test->answer( $request );
			}
		};
		$factory      = new Psr17Factory();
		$this->mailer = new Mailer(
			new PostilioClient( self::KEY, $client, $factory, $factory, maxRetries: 2, sleep: static function (): void {} ),
			new Settings( 'no-reply@mail.example.com', 'Acme', true ),
			function ( string $line ): void {
				$this->logged[] = $line;
			},
			1791475200
		);

		return $this->mailer;
	}

	/**
	 * The fake API: records the request and gives the next answer.
	 *
	 * @param RequestInterface $request The request.
	 */
	public function answer( RequestInterface $request ): ResponseInterface {
		$this->requests[] = $request;
		$answer           = array_shift( $this->answers ) ?? throw new \LogicException( 'No answer left.' );
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return $answer;
	}

	/**
	 * An answer of the API.
	 *
	 * @param int                   $status  The status.
	 * @param string                $body    The JSON.
	 * @param array<string, string> $headers More headers.
	 */
	private static function json( int $status, string $body, array $headers = array() ): ResponseInterface {
		return new Response( $status, $headers + array( 'Content-Type' => 'application/json' ), $body );
	}

	/**
	 * The wp_mail() arguments.
	 *
	 * @param string|list<string> $to The recipients.
	 *
	 * @return array<string, mixed>
	 */
	private static function atts( string|array $to ): array {
		return array(
			'to'          => $to,
			'subject'     => 'Hello',
			'message'     => 'Hi',
			'headers'     => '',
			'attachments' => array(),
		);
	}
}
