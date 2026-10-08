<?php
/**
 * Sends wp_mail() through Postilio.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Postilio\Exception\PostilioException;
use PostilioWp\Vendor\Postilio\Exception\TransportException;
use PostilioWp\Vendor\Postilio\PostilioClient;

/**
 * Answers WordPress's pre_wp_mail filter: sends the call through the API and reports the outcome with WordPress's own
 * wp_mail_succeeded and wp_mail_failed actions. A failure is never handed back to PHP's mail(), which would send without
 * the domain's SPF and DKIM.
 */
final class Mailer {
	/**
	 * What the last call sent, for the test email on the settings page.
	 *
	 * @var array{ids: list<string>, suppressed: list<string>, error: ?\WP_Error}|null
	 */
	private ?array $last_result = null;

	/**
	 * Creates the mailer.
	 *
	 * @param PostilioClient|null $client The API client; null while the key is not a Postilio key, so every call fails
	 *                                    rather than going out through PHP's mail().
	 * @param Settings            $settings The plug-in's settings.
	 * @param \Closure            $log      Writes a line to the PHP error log.
	 * @param int|null            $now      The time; the current time when null.
	 */
	public function __construct(
		private readonly ?PostilioClient $client,
		private readonly Settings $settings,
		private readonly \Closure $log,
		private readonly ?int $now = null,
	) {}

	/**
	 * What the last call sent: the message ids, the suppressed recipients and the error; null when the last call was
	 * answered by another plug-in.
	 *
	 * @return array{ids: list<string>, suppressed: list<string>, error: ?\WP_Error}|null
	 */
	public function last_result(): ?array {
		return $this->last_result;
	}

	/**
	 * Sends a wp_mail() call.
	 *
	 * @param mixed                $result Null, or what another plug-in already answered.
	 * @param array<string, mixed> $atts   The wp_mail() arguments.
	 *
	 * @return mixed What another plug-in answered; otherwise whether Postilio accepted the email for every recipient.
	 */
	public function pre_wp_mail( mixed $result, array $atts ): mixed {
		$this->last_result = null;
		if ( null !== $result ) {
			return $result;
		}
		$mail_data       = $atts;
		$mail_data['to'] = is_array( $atts['to'] ?? '' ) ? $atts['to'] : explode( ',', Settings::text( $atts['to'] ?? '' ) );

		$ids        = array();
		$suppressed = array();
		$accepted   = 0;
		$total      = 0;
		if ( null === $this->client ) {
			return $this->fail( $mail_data, 'invalid_api_key', 'Postilio for WordPress did not send the email: the API key is not a Postilio API key (pk_live_… or pk_test_…).', null, null, $ids, $suppressed );
		}
		try {
			$requests = WpMail::translate( $atts, $this->settings, $this->now ?? time() );
			// Only a call without Cc or Bcc is split into several requests, so counting To is enough.
			foreach ( $requests as $item ) {
				$total += count( $item['request']->to );
			}
			foreach ( $requests as $item ) {
				$response   = $this->client->sendEmail( $item['request'], $item['key'] );
				$ids        = array_merge( $ids, $response->ids );
				$suppressed = array_merge( $suppressed, $response->suppressed );
				$accepted  += count( $item['request']->to );
			}
		} catch ( MailError $e ) {
			return $this->fail( $mail_data, $e->error, 'Postilio for WordPress did not send the email (' . $e->error . '): ' . $e->getMessage(), null, null, $ids, $suppressed );
		} catch ( PostilioException $e ) {
			$code    = $e instanceof TransportException ? 'transport_error' : ( $e->errorCode ?? 'http_' . $e->status );
			$message = $e->getMessage();
			if ( $accepted > 0 ) {
				$message = "$accepted of $total recipients were accepted before this failed: $message";
			}
			return $this->fail( $mail_data, $code, $message, 0 === $e->status ? null : $e->status, $e->traceId, $ids, $suppressed );
		}//end try

		$this->last_result = array(
			'ids'        => $ids,
			'suppressed' => $suppressed,
			'error'      => null,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
		do_action( 'wp_mail_succeeded', $mail_data );

		return true;
	}

	/**
	 * Reports a failure as wp_mail() does, and logs it without the key, the recipients or the content.
	 *
	 * @param array<string, mixed> $mail_data  The wp_mail() arguments.
	 * @param string               $code       The Postilio or plug-in error code.
	 * @param string               $message    What went wrong.
	 * @param int|null             $status     The HTTP status, when the API answered.
	 * @param string|null          $trace_id   The API's trace id, to quote to support.
	 * @param string[]             $ids        The messages accepted before the failure.
	 * @param string[]             $suppressed The suppressed recipients among them.
	 *
	 * @phpstan-param list<string> $ids
	 * @phpstan-param list<string> $suppressed
	 */
	private function fail( array $mail_data, string $code, string $message, ?int $status, ?string $trace_id, array $ids, array $suppressed ): bool {
		$error = new \WP_Error(
			'wp_mail_failed',
			$message,
			$mail_data + array(
				'postilio_error'    => $code,
				'postilio_status'   => $status,
				'postilio_trace_id' => $trace_id,
				'postilio_ids'      => $ids,
			)
		);
		( $this->log )( 'Postilio for WordPress: ' . $message . ( null === $trace_id ? '' : " (trace id $trace_id)" ) );
		$this->last_result = array(
			'ids'        => $ids,
			'suppressed' => $suppressed,
			'error'      => $error,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, applied as wp_mail() applies it.
		do_action( 'wp_mail_failed', $error );

		return false;
	}
}
