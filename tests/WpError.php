<?php
/**
 * The part of WordPress's WP_Error the plug-in uses.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.Missing
class WP_Error {
	/**
	 * Holds the error.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 * @param mixed  $data    The data.
	 */
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}

	/** The code. */
	public function get_error_code(): string {
		return $this->code;
	}

	/** The message. */
	public function get_error_message(): string {
		return $this->message;
	}

	/** The data. */
	public function get_error_data(): mixed {
		return $this->data;
	}
}
