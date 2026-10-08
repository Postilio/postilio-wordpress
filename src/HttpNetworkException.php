<?php
/**
 * No answer to a request.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use PostilioWp\Vendor\Psr\Http\Message\RequestInterface;

/** WordPress could not reach the API, or got no answer in time. */
final class HttpNetworkException extends \RuntimeException implements NetworkExceptionInterface {
	/**
	 * Creates the exception.
	 *
	 * @param string           $message WordPress's error message.
	 * @param RequestInterface $request The request that failed.
	 */
	public function __construct( string $message, private readonly RequestInterface $request ) {
		parent::__construct( $message );
	}

	/** The request that failed. */
	public function getRequest(): RequestInterface {
		return $this->request;
	}
}
