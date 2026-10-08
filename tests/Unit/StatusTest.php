<?php
/**
 * Tests for the status line on the settings page.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PostilioWp\Status;
use PostilioWp\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PostilioWp\Vendor\Nyholm\Psr7\Response;
use PostilioWp\Vendor\Postilio\PostilioClient;
use PostilioWp\Vendor\Psr\Http\Client\ClientInterface;
use PostilioWp\Vendor\Psr\Http\Message\RequestInterface;
use PostilioWp\Vendor\Psr\Http\Message\ResponseInterface;

final class StatusTest extends TestCase {
	private const DOMAINS = '{"data":[%s]}';
	private const DOMAIN  = '{"id":"d1","name":"%s","status":"%s","checkedAt":null,"records":[],"createdAt":"2026-10-01T00:00:00Z","addedBy":null,"failingSince":null,"sent30d":0,"dmarc":null}';

	/**
	 * Answers of GET /v1/domains and the status they give for no-reply@mail.example.com.
	 *
	 * @return array<string, array{int, string, string, string}>
	 */
	public static function answers(): array {
		return array(
			'verified'        => array( 200, sprintf( self::DOMAINS, sprintf( self::DOMAIN, 'mail.example.com', 'verified' ) ), 'accepted', 'verified' ),
			'pending'         => array( 200, sprintf( self::DOMAINS, sprintf( self::DOMAIN, 'Mail.Example.com', 'pending' ) ), 'accepted', 'pending' ),
			'failing'         => array( 200, sprintf( self::DOMAINS, sprintf( self::DOMAIN, 'mail.example.com', 'failing' ) ), 'accepted', 'failing' ),
			'another domain'  => array( 200, sprintf( self::DOMAINS, sprintf( self::DOMAIN, 'example.com', 'verified' ) ), 'accepted', 'not_found' ),
			'no domains:read' => array( 403, '{"error":"insufficient_scope"}', 'accepted', 'unknown' ),
			'revoked key'     => array( 401, '{"error":"invalid_api_key"}', 'rejected', 'unknown' ),
			'suspended'       => array( 403, '{"error":"project_suspended"}', 'error', 'unknown' ),
			'server error'    => array( 500, '{"traceId":"00-1"}', 'error', 'unknown' ),
		);
	}

	#[DataProvider( 'answers' )]
	public function test_check_reads_the_key_and_the_sender_domain_from_one_call( int $status, string $body, string $key, string $domain ): void {
		$client = $this->client( new Response( $status, array( 'Content-Type' => 'application/json' ), $body ) );

		$result = Status::check( $client, 'pk_live_abcdEFGH0123456789abcdefghijklmn', 'no-reply@mail.example.com', 1791475200 );

		self::assertSame( $key, $result['key'] );
		self::assertSame( $domain, $result['domain'] );
		self::assertSame( 'live', $result['mode'] );
		self::assertSame( 'mail.example.com', $result['sender_domain'] );
		self::assertSame( 1791475200, $result['checked_at'] );
		self::assertSame( 'error' === $key, null !== $result['error'] );
	}

	public function test_check_reads_the_mode_from_a_test_key(): void {
		$client = $this->client( new Response( 403, array( 'Content-Type' => 'application/json' ), '{"error":"insufficient_scope"}' ) );

		self::assertSame( 'test', Status::check( $client, 'pk_test_abcdEFGH0123456789abcdefghijklmn', 'a@b.example', 0 )['mode'] );
	}

	public function test_read_takes_a_cached_check_and_nothing_else(): void {
		$check = array(
			'checked_at'    => 1791475200,
			'mode'          => 'live',
			'key'           => 'accepted',
			'domain'        => 'verified',
			'sender_domain' => 'mail.example.com',
			'error'         => null,
		);

		self::assertSame( $check, Status::read( $check ) );
		self::assertNull( Status::read( false ) );
		self::assertNull( Status::read( array( 'key' => 'accepted' ) ) );
		self::assertNull( Status::read( array( 'checked_at' => '1' ) + $check ) );
	}

	private function client( ResponseInterface $answer ): PostilioClient {
		$fake    = new class( $answer ) implements ClientInterface {
			public function __construct( private readonly ResponseInterface $answer ) {}

			public function sendRequest( RequestInterface $request ): ResponseInterface {
				return 'GET' === $request->getMethod() && str_ends_with( $request->getUri()->getPath(), '/v1/domains' ) ? $this->answer : new Response( 404 );
			}
		};
		$factory = new Psr17Factory();

		return new PostilioClient( 'pk_live_abcdEFGH0123456789abcdefghijklmn', $fake, $factory, $factory );
	}
}
