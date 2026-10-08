<?php
/**
 * The status line on the settings page.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Postilio\Enum\DomainStatus;
use PostilioWp\Vendor\Postilio\Exception\AuthenticationException;
use PostilioWp\Vendor\Postilio\Exception\PermissionException;
use PostilioWp\Vendor\Postilio\Exception\PostilioException;
use PostilioWp\Vendor\Postilio\PostilioClient;

/** Whether the key is accepted and the sender's domain verified, from a single GET /v1/domains. */
final class Status {
	/** The transient that caches the last check. */
	public const TRANSIENT = 'postilio_status';

	/**
	 * A check from the cache, or null when the cache holds something else.
	 *
	 * @param mixed $cached What get_transient() returned.
	 *
	 * @return array{checked_at: int, mode: string, key: string, domain: string, sender_domain: string, error: ?string}|null
	 */
	public static function read( mixed $cached ): ?array {
		if ( ! is_array( $cached ) || ! is_int( $cached['checked_at'] ?? null ) || ! is_string( $cached['mode'] ?? null ) || ! is_string( $cached['key'] ?? null )
			|| ! is_string( $cached['domain'] ?? null ) || ! is_string( $cached['sender_domain'] ?? null ) || ! ( is_string( $cached['error'] ?? null ) || null === ( $cached['error'] ?? null ) ) ) {
			return null;
		}

		return array(
			'checked_at'    => $cached['checked_at'],
			'mode'          => $cached['mode'],
			'key'           => $cached['key'],
			'domain'        => $cached['domain'],
			'sender_domain' => $cached['sender_domain'],
			'error'         => is_string( $cached['error'] ) ? $cached['error'] : null,
		);
	}

	/**
	 * Checks the key and the sender's domain.
	 *
	 * @param PostilioClient $client     The client with the key.
	 * @param string         $key        The key, for its mode.
	 * @param string         $from_email The sender.
	 * @param int            $now        The time of the check.
	 *
	 * @return array{checked_at: int, mode: string, key: string, domain: string, sender_domain: string, error: ?string}
	 *         `key` is accepted, rejected or error; `domain` is verified, pending, failing, not_found or unknown (the key
	 *         lacks domains:manage, which a test key never has).
	 */
	public static function check( PostilioClient $client, string $key, string $from_email, int $now ): array {
		$at     = strrpos( $from_email, '@' );
		$result = array(
			'checked_at'    => $now,
			'mode'          => str_starts_with( $key, 'pk_test_' ) ? 'test' : 'live',
			'key'           => 'accepted',
			'domain'        => 'unknown',
			'sender_domain' => false === $at ? '' : strtolower( substr( $from_email, $at + 1 ) ),
			'error'         => null,
		);
		try {
			$result['domain'] = 'not_found';
			foreach ( $client->listDomains()->data as $domain ) {
				if ( strtolower( $domain->name ) === $result['sender_domain'] ) {
					$result['domain'] = $domain->status instanceof DomainStatus ? $domain->status->value : $domain->status;
				}
			}
		} catch ( AuthenticationException ) {
			$result['key']    = 'rejected';
			$result['domain'] = 'unknown';
		} catch ( PostilioException $e ) {
			$result['domain'] = 'unknown';
			if ( ! ( $e instanceof PermissionException && 'insufficient_scope' === $e->errorCode ) ) {
				$result['key']   = 'error';
				$result['error'] = $e->getMessage();
			}
		}

		return $result;
	}
}
