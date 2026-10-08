<?php
/**
 * A PSR-18 client over the WordPress HTTP API.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Nyholm\Psr7\Response;
use PostilioWp\Vendor\Psr\Http\Client\ClientInterface;
use PostilioWp\Vendor\Psr\Http\Message\RequestInterface;
use PostilioWp\Vendor\Psr\Http\Message\ResponseInterface;

/**
 * Sends the SDK's requests with wp_remote_request(), so the site's proxy (WP_PROXY_*), its CA bundle and the
 * http_request_args filter apply as for any other request WordPress makes. TLS verification stays on.
 */
final class WpHttpClient implements ClientInterface {
	/**
	 * Creates the client.
	 *
	 * @param float $timeout Seconds for a whole request.
	 */
	public function __construct( private readonly float $timeout ) {}

	/**
	 * Sends a request and returns the answer, whatever its status.
	 *
	 * @param RequestInterface $request The request.
	 *
	 * @throws HttpNetworkException No answer came.
	 */
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( array_keys( $request->getHeaders() ) as $name ) {
			$headers[ (string) $name ] = $request->getHeaderLine( (string) $name );
		}
		$user_agent = ( $headers['User-Agent'] ?? '' ) . ' postilio-for-wordpress/' . Plugin::VERSION;
		unset( $headers['User-Agent'] );

		$answer = wp_remote_request(
			(string) $request->getUri(),
			array(
				'method'      => $request->getMethod(),
				'headers'     => $headers,
				'body'        => (string) $request->getBody(),
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'user-agent'  => ltrim( $user_agent ),
			)
		);
		if ( is_wp_error( $answer ) ) {
			throw new HttpNetworkException( $answer->get_error_message(), $request ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, never printed as HTML.
		}

		$response_headers = array();
		foreach ( $answer['headers'] as $name => $value ) {
			$response_headers[ (string) $name ] = $value;
		}

		return new Response( (int) $answer['response']['code'], $response_headers, (string) $answer['body'] );
	}
}
