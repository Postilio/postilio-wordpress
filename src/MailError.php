<?php
/**
 * A wp_mail() call the plug-in cannot send.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

/** Raised before anything is sent, with a code in the style of the API's own. */
final class MailError extends \RuntimeException {
	/**
	 * Creates the error.
	 *
	 * @param string $error   A stable code, such as `attachment_not_readable`.
	 * @param string $message What is wrong, for the site's administrator.
	 */
	public function __construct( public readonly string $error, string $message ) {
		parent::__construct( $message );
	}
}
