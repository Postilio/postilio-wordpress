<?php
/**
 * Tests for the PSR-18 client over the WordPress HTTP API.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Functions;
use PostilioWp\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use PostilioWp\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use PostilioWp\Plugin;
use PostilioWp\WpHttpClient;

final class WpHttpClientTest extends TestCase {
	public function test_sendRequest_passes_the_request_to_wp_remote_request_and_reads_the_answer(): void {
		$factory = new Psr17Factory();
		$request = $factory->createRequest( 'POST', 'https://api.postilio.eu/v1/emails' )
			->withHeader( 'Authorization', 'Bearer pk_test_x' )
			->withHeader( 'User-Agent', 'postilio-php/0.1.0' )
			->withBody( $factory->createStream( '{"to":[]}' ) );
		$seen    = null;
		Functions\expect( 'wp_remote_request' )->once()->andReturnUsing(
			function ( string $url, array $args ) use ( &$seen ) {
				$seen = array( $url, $args );
				return array(
					'headers'  => array(
						'content-type' => 'application/json',
						'set-cookie'   => array( 'a=1', 'b=2' ),
					),
					'body'     => '{"ids":["1"]}',
					'response' => array(
						'code'    => 202,
						'message' => 'Accepted',
					),
				);
			}
		);

		$response = ( new WpHttpClient( 15.0 ) )->sendRequest( $request );
		self::assertIsArray( $seen );
		$args = $seen[1];
		self::assertIsArray( $args['headers'] );
		self::assertIsString( $args['user-agent'] );

		self::assertSame( 'https://api.postilio.eu/v1/emails', $seen[0] );
		self::assertSame( 'POST', $args['method'] );
		self::assertSame( '{"to":[]}', $args['body'] );
		self::assertSame( 15.0, $args['timeout'] );
		self::assertSame( 0, $args['redirection'] );
		self::assertSame( 'Bearer pk_test_x', $args['headers']['Authorization'] );
		self::assertSame( 'postilio-php/0.1.0 postilio-for-wordpress/' . Plugin::VERSION, $args['user-agent'] );
		self::assertArrayNotHasKey( 'User-Agent', $args['headers'] );
		self::assertArrayNotHasKey( 'sslverify', $args );
		self::assertSame( 202, $response->getStatusCode() );
		self::assertSame( '{"ids":["1"]}', (string) $response->getBody() );
		self::assertSame( 'application/json', $response->getHeaderLine( 'Content-Type' ) );
		self::assertSame( array( 'a=1', 'b=2' ), $response->getHeader( 'Set-Cookie' ) );
	}

	public function test_sendRequest_throws_a_network_exception_with_the_WordPress_error_message(): void {
		$factory = new Psr17Factory();
		$request = $factory->createRequest( 'GET', 'https://api.postilio.eu/v1/domains' )
			->withHeader( 'Authorization', 'Bearer pk_test_secret' );
		Functions\when( 'wp_remote_request' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		try {
			( new WpHttpClient( 15.0 ) )->sendRequest( $request );
			self::fail( 'No exception.' );
		} catch ( NetworkExceptionInterface $e ) {
			self::assertSame( 'cURL error 28: Operation timed out', $e->getMessage() );
			self::assertSame( $request, $e->getRequest() );
		}
	}
}
