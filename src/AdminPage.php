<?php
/**
 * Settings → Postilio.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp;

use PostilioWp\Vendor\Postilio\Enum\EmailEventReason;
use PostilioWp\Vendor\Postilio\Enum\EmailStatus;
use PostilioWp\Vendor\Postilio\Exception\PermissionException;
use PostilioWp\Vendor\Postilio\Exception\PostilioException;

/** The settings page, its status line and its test email. */
final class AdminPage {
	/** The page's slug and settings group. */
	public const SLUG = 'postilio';

	/** The nonce action of the test email. */
	public const NONCE = 'postilio_admin';

	/** The nonce action of the status refresh. */
	public const REFRESH = 'postilio_refresh_status';

	/**
	 * Creates the page.
	 *
	 * @param string $plugin_file The plug-in's main file.
	 */
	public function __construct( private readonly string $plugin_file ) {}

	/** Hooks the page in. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'wp_ajax_postilio_send_test', array( $this, 'ajax_send_test' ) );
		add_action( 'admin_post_' . self::REFRESH, array( $this, 'refresh_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'links' ) );
	}

	/** Adds Settings → Postilio. */
	public function menu(): void {
		add_options_page( __( 'Postilio', 'postilio-for-wordpress' ), __( 'Postilio', 'postilio-for-wordpress' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/** Registers the setting and its fields. */
	public function settings(): void {
		register_setting(
			self::SLUG,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => array(
					'from_email' => '',
					'from_name'  => '',
					'force_from' => true,
				),
			)
		);
		add_settings_section( 'postilio_key', __( 'API key', 'postilio-for-wordpress' ), array( $this, 'key_section' ), self::SLUG );
		add_settings_field( 'postilio_api_key', __( 'API key', 'postilio-for-wordpress' ), array( $this, 'key_field' ), self::SLUG, 'postilio_key', array( 'label_for' => 'postilio_api_key' ) );
		add_settings_section( 'postilio_sender', __( 'Sender', 'postilio-for-wordpress' ), array( $this, 'sender_section' ), self::SLUG );
		add_settings_field( 'postilio_from_email', __( 'From address', 'postilio-for-wordpress' ), array( $this, 'from_email_field' ), self::SLUG, 'postilio_sender', array( 'label_for' => 'postilio_from_email' ) );
		add_settings_field( 'postilio_from_name', __( 'From name', 'postilio-for-wordpress' ), array( $this, 'from_name_field' ), self::SLUG, 'postilio_sender', array( 'label_for' => 'postilio_from_name' ) );
		add_settings_field( 'postilio_force_from', __( 'Force this sender', 'postilio-for-wordpress' ), array( $this, 'force_from_field' ), self::SLUG, 'postilio_sender' );
	}

	/**
	 * Loads the script of the test email on this page only.
	 *
	 * @param string $hook The page's hook suffix.
	 */
	public function scripts( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'postilio-admin', plugins_url( 'assets/admin.js', $this->plugin_file ), array(), Plugin::VERSION, true );
		wp_localize_script(
			'postilio-admin',
			'postilioAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'sending'    => __( 'Sending…', 'postilio-for-wordpress' ),
					'failed'     => __( 'The test email could not be sent: no answer from WordPress.', 'postilio-for-wordpress' ),
					'messageId'  => __( 'Message id', 'postilio-for-wordpress' ),
					'status'     => __( 'Status', 'postilio-for-wordpress' ),
					'events'     => __( 'Events', 'postilio-for-wordpress' ),
					'suppressed' => __( 'Suppressed, not sent', 'postilio-for-wordpress' ),
					'code'       => __( 'Error code', 'postilio-for-wordpress' ),
				),
			)
		);
	}

	/** Tells an administrator on the dashboard and the plug-ins page that no key is set yet. */
	public function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! current_user_can( 'manage_options' ) || null !== Settings::api_key() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Postilio for WordPress has no API key yet, so WordPress still sends its email itself.', 'postilio-for-wordpress' ),
			esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ),
			esc_html__( 'Set it up', 'postilio-for-wordpress' )
		);
	}

	/**
	 * Adds Settings to the plug-in's row.
	 *
	 * @param array<string> $links The row's links.
	 *
	 * @return array<string>
	 */
	public function links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'postilio-for-wordpress' ) ) );

		return $links;
	}

	/** Renders the page. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		$this->status();
		echo '<form action="options.php" method="post">';
		settings_fields( self::SLUG );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';

		if ( null !== Settings::api_key() ) {
			echo '<h2>' . esc_html__( 'Send a test email', 'postilio-for-wordpress' ) . '</h2>';
			echo '<p>' . esc_html__( 'Sends a short email through wp_mail(), the way your site sends all its email, and shows what Postilio answered. With a live key it is delivered and counts as usage; with a test key nothing is delivered.', 'postilio-for-wordpress' ) . '</p>';
			echo '<form id="postilio-test-form" novalidate><p><label for="postilio-test-to">' . esc_html__( 'Send to', 'postilio-for-wordpress' ) . '</label> ';
			echo '<input type="email" id="postilio-test-to" name="to" class="regular-text" autocomplete="email" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
			submit_button( __( 'Send test email', 'postilio-for-wordpress' ), 'secondary', 'postilio-test-send', false );
			echo '</p></form><div id="postilio-test-result" role="status" aria-live="polite"></div>';
		}
		echo '</div>';
	}

	/** The status line: the key, its mode and the sender's domain, from one cached call. */
	private function status(): void {
		$key = Settings::api_key();
		if ( null === $key ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No API key yet: WordPress sends its email itself, as before. Set a key below, or better, in wp-config.php.', 'postilio-for-wordpress' ) . '</p></div>';
			return;
		}
		$client = Plugin::client( $key );
		if ( null === $client ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The API key is not a Postilio API key (pk_live_… or pk_test_…). No email is sent until it is.', 'postilio-for-wordpress' ) . '</p></div>';
			return;
		}
		$settings = Settings::load();
		$status   = Status::read( get_transient( Status::TRANSIENT ) );
		if ( null === $status ) {
			$status = Status::check( $client, $key, '' !== $settings->from_email ? $settings->from_email : WpMail::default_from(), time() );
			set_transient( Status::TRANSIENT, $status, 12 * HOUR_IN_SECONDS );
		}

		$source = 'constant' === Settings::api_key_source() ? __( 'from wp-config.php', 'postilio-for-wordpress' ) : __( 'from these settings', 'postilio-for-wordpress' );
		$mode   = 'test' === $status['mode'] ? __( 'test key: nothing is delivered', 'postilio-for-wordpress' ) : __( 'live key', 'postilio-for-wordpress' );
		$keys   = array(
			/* translators: 1: the masked key, 2: live or test key, 3: where the key comes from. */
			'accepted' => array( true, __( 'Accepted: %1$s, %2$s, %3$s.', 'postilio-for-wordpress' ) ),
			/* translators: 1: the masked key. */
			'rejected' => array( false, __( 'Not accepted: Postilio does not know %1$s, or it was revoked.', 'postilio-for-wordpress' ) ),
			/* translators: 1: the masked key. */
			'error'    => array( false, __( 'Could not be checked: %1$s.', 'postilio-for-wordpress' ) ),
		);
		[ $key_ok, $key_text ] = $keys[ $status['key'] ] ?? $keys['error'];
		$key_line              = sprintf( $key_text, Settings::mask( $key ), $mode, $source );
		if ( null !== $status['error'] ) {
			$key_line .= ' ' . $status['error'];
		}
		$domains                     = array(
			'verified'  => array( true, __( 'verified.', 'postilio-for-wordpress' ) ),
			'pending'   => array( false, __( 'not verified yet: not all of its DNS records were found. Postilio refuses email from it until they are.', 'postilio-for-wordpress' ) ),
			'failing'   => array( false, __( 'failing: a DNS record went missing. Postilio keeps sending for 72 hours, then refuses email from it until the record is back.', 'postilio-for-wordpress' ) ),
			'not_found' => array( false, __( 'not a domain of this key\'s project. Postilio refuses email from it.', 'postilio-for-wordpress' ) ),
			'unknown'   => array( null, __( 'unknown: the key may not read domains (scope domains:manage, never on a test key). Send a test email to see whether Postilio takes it.', 'postilio-for-wordpress' ) ),
		);
		[ $domain_ok, $domain_text ] = $domains[ $status['domain'] ] ?? $domains['unknown'];

		echo '<div class="card" style="max-width:none"><h2>' . esc_html__( 'Status', 'postilio-for-wordpress' ) . '</h2><ul>';
		$this->status_item( $key_ok, __( 'API key', 'postilio-for-wordpress' ), $key_line );
		$this->status_item( $domain_ok, __( 'Sender domain', 'postilio-for-wordpress' ), $status['sender_domain'] . ': ' . $domain_text );
		echo '</ul><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><p>';
		/* translators: %s: how long ago, such as "5 mins". */
		echo esc_html( sprintf( __( 'Checked %s ago.', 'postilio-for-wordpress' ), human_time_diff( $status['checked_at'] ) ) ) . ' ';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REFRESH ) . '">';
		wp_nonce_field( self::REFRESH );
		submit_button( __( 'Check again', 'postilio-for-wordpress' ), 'secondary small', 'submit', false );
		echo '</p></form></div>';
	}

	/**
	 * A line of the status, with an icon and a word, so it does not rest on colour alone.
	 *
	 * @param bool|null $ok    Good, bad, or unknown.
	 * @param string    $label What the line is about.
	 * @param string    $text  The line.
	 */
	private function status_item( ?bool $ok, string $label, string $text ): void {
		$icon = null === $ok ? 'dashicons-info-outline' : ( $ok ? 'dashicons-yes-alt' : 'dashicons-warning' );
		$word = null === $ok ? __( 'Unknown', 'postilio-for-wordpress' ) : ( $ok ? __( 'OK', 'postilio-for-wordpress' ) : __( 'Problem', 'postilio-for-wordpress' ) );
		echo '<li><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> <strong>' . esc_html( $label ) . '</strong> <span class="screen-reader-text">(' . esc_html( $word ) . ')</span>: ' . esc_html( $text ) . '</li>';
	}

	/** Explains the key. */
	public function key_section(): void {
		echo '<p>' . esc_html__( 'Create a key in the Postilio portal under Keys & SMTP, with the scope emails:send. Add emails:read to see a test email\'s delivery here, and domains:manage to see the sender domain\'s status. Safest is the constant in wp-config.php, outside the database:', 'postilio-for-wordpress' ) . '</p>';
		echo "<p><code>define( 'POSTILIO_API_KEY', 'pk_live_…' );</code></p>";
	}

	/** The key field: never shows the key, only a hint of which one it is. */
	public function key_field(): void {
		$key = Settings::api_key();
		if ( 'constant' === Settings::api_key_source() && null !== $key ) {
			/* translators: %s: the masked key. */
			echo '<p id="postilio_api_key">' . esc_html( sprintf( __( 'Set in wp-config.php (POSTILIO_API_KEY): %s', 'postilio-for-wordpress' ), Settings::mask( $key ) ) ) . '</p>';
			return;
		}
		echo '<input type="password" id="postilio_api_key" name="' . esc_attr( Settings::OPTION ) . '[api_key]" class="regular-text" autocomplete="off" spellcheck="false" value="" aria-describedby="postilio_api_key_hint"';
		echo null === $key ? '>' : ' placeholder="' . esc_attr( Settings::mask( $key ) ) . '">';
		echo '<p class="description" id="postilio_api_key_hint">';
		echo null === $key
			? esc_html__( 'Stored in the database, not autoloaded, and never shown again.', 'postilio-for-wordpress' )
			/* translators: %s: the masked key. */
			: esc_html( sprintf( __( 'A key is stored: %s. Enter a new one to replace it, or leave this empty to keep it.', 'postilio-for-wordpress' ), Settings::mask( $key ) ) );
		echo '</p>';
		if ( null !== $key ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( Settings::OPTION ) . '[remove_api_key]" value="1"> ' . esc_html__( 'Remove the stored key (WordPress then sends its email itself again)', 'postilio-for-wordpress' ) . '</label></p>';
		}
	}

	/** Explains the sender. */
	public function sender_section(): void {
		echo '<p>' . esc_html__( 'Postilio sends only from an address on a domain you verified in the key\'s project. WordPress sends as wordpress@ and your site\'s domain by default, which Postilio would refuse.', 'postilio-for-wordpress' ) . '</p>';
	}

	/** The sender's address. */
	public function from_email_field(): void {
		$settings = Settings::load();
		echo '<input type="email" id="postilio_from_email" name="' . esc_attr( Settings::OPTION ) . '[from_email]" class="regular-text" autocomplete="off" value="' . esc_attr( $settings->from_email ) . '" placeholder="' . esc_attr( WpMail::default_from() ) . '" aria-describedby="postilio_from_email_hint">';
		echo '<p class="description" id="postilio_from_email_hint">' . esc_html__( 'Such as no-reply@mail.example.com.', 'postilio-for-wordpress' ) . '</p>';
	}

	/** The sender's name. */
	public function from_name_field(): void {
		$settings = Settings::load();
		echo '<input type="text" id="postilio_from_name" name="' . esc_attr( Settings::OPTION ) . '[from_name]" class="regular-text" value="' . esc_attr( $settings->from_name ) . '" aria-describedby="postilio_from_name_hint">';
		echo '<p class="description" id="postilio_from_name_hint">' . esc_html__( 'Without @ or a comma; Postilio does not take them in a name.', 'postilio-for-wordpress' ) . '</p>';
	}

	/** Whether the sender wins over other plug-ins. */
	public function force_from_field(): void {
		$settings = Settings::load();
		echo '<label><input type="checkbox" name="' . esc_attr( Settings::OPTION ) . '[force_from]" value="1"' . checked( $settings->force_from, true, false ) . ' aria-describedby="postilio_force_from_hint"> ';
		echo esc_html__( 'Send every email from this address and name', 'postilio-for-wordpress' ) . '</label>';
		echo '<p class="description" id="postilio_force_from_hint">' . esc_html__( 'On by default. Off: a From header of the plug-in that sends, and the wp_mail_from and wp_mail_from_name filters, choose the sender, as in WordPress itself; Postilio still refuses a sender outside your verified domains.', 'postilio-for-wordpress' ) . '</p>';
	}

	/** Forgets the cached status, then shows the page again. */
	public function refresh_status(): void {
		check_admin_referer( self::REFRESH );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not do this.', 'postilio-for-wordpress' ), '', array( 'response' => 403 ) );
		}
		delete_transient( Status::TRANSIENT );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * The Postilio or plug-in error code a failed wp_mail() call carries.
	 *
	 * @param \WP_Error $error The error.
	 */
	private static function error_code( \WP_Error $error ): ?string {
		$data = $error->get_error_data();

		return is_array( $data ) && is_string( $data['postilio_error'] ?? null ) ? $data['postilio_error'] : null;
	}

	/** Sends a test email through wp_mail() and answers what Postilio did with it. */
	public function ajax_send_test(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You may not send a test email.', 'postilio-for-wordpress' ) ), 403 );
		}
		$to = isset( $_POST['to'] ) && is_string( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'postilio-for-wordpress' ) ), 400 );
		}
		$mailer = Plugin::mailer();
		$key    = Settings::api_key();
		if ( null === $mailer || null === $key ) {
			wp_send_json_error( array( 'message' => __( 'Set an API key first.', 'postilio-for-wordpress' ) ), 400 );
		}

		/* translators: %s: the site's name. */
		$subject = sprintf( __( 'Test email from %s', 'postilio-for-wordpress' ), wp_specialchars_decode( Settings::text( get_bloginfo( 'name' ) ), ENT_QUOTES ) );
		/* translators: %s: the site's address. */
		$body   = sprintf( __( "This is a test email from Postilio for WordPress on %s.\n\nIf you can read this, your site sends its email through Postilio.", 'postilio-for-wordpress' ), home_url() );
		$sent   = wp_mail( $to, $subject, $body );
		$result = $mailer->last_result();
		if ( ! $sent || null === $result || null !== $result['error'] ) {
			$error = $result['error'] ?? null;
			wp_send_json_error(
				array(
					'message' => null === $error ? __( 'Another plug-in stopped the email before Postilio could send it.', 'postilio-for-wordpress' ) : $error->get_error_message(),
					'code'    => null === $error ? null : self::error_code( $error ),
				)
			);
		}

		$answer = array(
			/* translators: %s: the recipient. */
			'message'    => sprintf( __( 'Postilio accepted the email for %s.', 'postilio-for-wordpress' ), $to ),
			'ids'        => $result['ids'],
			'suppressed' => $result['suppressed'],
			'status'     => null,
			'events'     => array(),
			'note'       => null,
		);
		$client = Plugin::client( $key );
		if ( null !== $client && array() !== $result['ids'] ) {
			try {
				$email            = $client->getEmail( $result['ids'][0] );
				$answer['status'] = $email->status instanceof EmailStatus ? $email->status->value : $email->status;
				foreach ( $email->events as $event ) {
					$answer['events'][] = array(
						'type'       => $event->type instanceof EmailStatus ? $event->type->value : $event->type,
						'occurredAt' => $event->occurredAt->format( DATE_ATOM ),
						'smtpCode'   => $event->smtpCode,
						'reason'     => $event->reason instanceof EmailEventReason ? $event->reason->value : $event->reason,
						'response'   => $event->response,
					);
				}
				$answer['note'] = __( 'This is the status right after sending; later events show in the Postilio portal.', 'postilio-for-wordpress' );
			} catch ( PermissionException $e ) {
				$answer['note'] = __( 'Give the key the scope emails:read to see the delivery here, or look it up in the Postilio portal.', 'postilio-for-wordpress' );
			} catch ( PostilioException $e ) {
				$answer['note'] = $e->getMessage();
			}
		}
		wp_send_json_success( $answer );
	}
}
