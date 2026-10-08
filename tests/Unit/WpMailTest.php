<?php
/**
 * Tests for the translation of a wp_mail() call into Postilio requests.
 *
 * @package PostilioWp
 */

declare(strict_types=1);

namespace PostilioWp\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PostilioWp\MailError;
use PostilioWp\Settings;
use PostilioWp\WpMail;
use PostilioWp\Vendor\Postilio\Model\SendEmailRequest;

final class WpMailTest extends TestCase {
	private const NOW = 1791475200; // 2026-10-08 16:00:00 UTC.

	/** @var list<string> Files a test made, removed after it. */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
		Functions\when( 'network_home_url' )->justReturn( 'https://www.example.com' );
		Functions\when( 'home_url' )->justReturn( 'https://www.example.com' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_check_filetype' )->alias(
			static fn( string $name ) => array(
				'ext'  => pathinfo( $name, PATHINFO_EXTENSION ),
				'type' => array(
					'pdf' => 'application/pdf',
					'png' => 'image/png',
				)[ pathinfo( $name, PATHINFO_EXTENSION ) ] ?? false,
			)
		);
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			unlink( $file );
		}
		if ( is_dir( self::dir() ) ) {
			rmdir( self::dir() );
		}
		parent::tearDown();
	}

	public function test_translate_sends_one_request_with_the_configured_sender_tag_and_bodies(): void {
		$requests = $this->translate( array( 'to' => 'ada@example.org' ) );

		self::assertCount( 1, $requests );
		$request = $requests[0]['request'];
		self::assertSame( 'Acme <no-reply@mail.example.com>', $request->from );
		self::assertSame( array( 'ada@example.org' ), $request->to );
		self::assertSame( 'Hello', $request->subject );
		self::assertSame( 'Hi Ada', $request->text );
		self::assertNull( $request->html );
		self::assertSame( 'wordpress', $request->tag );
		self::assertNull( $request->cc );
		self::assertNull( $request->replyTo );
		self::assertNull( $request->attachments );
	}

	/**
	 * Recipients in each form wp_mail() takes.
	 *
	 * @return array<string, array{mixed, list<string>}>
	 */
	public static function recipients(): array {
		return array(
			'comma-separated string'   => array( 'ada@example.org, grace@example.org', array( 'ada@example.org', 'grace@example.org' ) ),
			'array'                    => array( array( 'ada@example.org', 'grace@example.org' ), array( 'ada@example.org', 'grace@example.org' ) ),
			'names are dropped'        => array( array( 'Ada Lovelace <ada@example.org>', '"Grace" <grace@example.org>' ), array( 'ada@example.org', 'grace@example.org' ) ),
			'duplicates once, in case' => array( 'ada@example.org,ADA@example.org', array( 'ada@example.org' ) ),
			'invalid ones skipped'     => array( 'not-an-address, ada@example.org', array( 'ada@example.org' ) ),
		);
	}

	/**
	 * Test.
	 *
	 * @param mixed        $to       The wp_mail() recipients.
	 * @param list<string> $expected The API's.
	 */
	#[DataProvider( 'recipients' )]
	public function test_translate_reads_recipients_as_wp_mail_does( mixed $to, array $expected ): void {
		self::assertSame( $expected, $this->translate( array( 'to' => $to ) )[0]['request']->to );
	}

	public function test_translate_refuses_a_call_without_a_valid_recipient(): void {
		self::assertRefused( 'no_recipients', fn() => $this->translate( array( 'to' => 'nobody' ) ) );
	}

	public function test_translate_reads_headers_given_as_a_string_as_given_as_an_array(): void {
		$lines = array(
			'Cc: grace@example.org',
			'Bcc: Archive <archive@example.org>',
			'Reply-To: Support <support@example.org>, other@example.org',
			'Content-Type: text/html; charset=UTF-8',
			'X-Order-Id: 1042',
		);

		$from_array  = $this->translate( array( 'headers' => $lines ) )[0]['request'];
		$from_string = $this->translate( array( 'headers' => implode( "\r\n", $lines ) ) )[0]['request'];

		self::assertEquals( $from_array, $from_string );
		self::assertSame( array( 'grace@example.org' ), $from_array->cc );
		self::assertSame( array( 'archive@example.org' ), $from_array->bcc );
		self::assertSame( 'Support <support@example.org>', $from_array->replyTo );
		self::assertSame( 'Hi Ada', $from_array->html );
		self::assertNull( $from_array->text );
		self::assertSame( array( 'X-Order-Id' => '1042' ), $from_array->headers );
	}

	public function test_translate_forces_the_configured_sender_over_the_header_and_the_filters(): void {
		Filters\expectApplied( 'wp_mail_from' )->never();
		Filters\expectApplied( 'wp_mail_from_name' )->never();

		$request = $this->translate( array( 'headers' => 'From: Shop <shop@other.example>' ) )[0]['request'];

		self::assertSame( 'Acme <no-reply@mail.example.com>', $request->from );
	}

	public function test_translate_without_force_takes_the_header_sender_through_the_wordpress_filters(): void {
		Filters\expectApplied( 'wp_mail_from' )->once()->with( 'shop@mail.example.com' )->andReturn( 'orders@mail.example.com' );
		Filters\expectApplied( 'wp_mail_from_name' )->once()->with( 'Shop, Inc. @ Home' )->andReturnFirstArg();

		$request = $this->translate(
			array( 'headers' => 'From: "Shop, Inc. @ Home" <shop@mail.example.com>' ),
			new Settings( 'no-reply@mail.example.com', 'Acme', false )
		)[0]['request'];

		self::assertSame( 'Shop Inc.  Home <orders@mail.example.com>', $request->from );
	}

	public function test_translate_without_force_or_header_starts_from_the_configured_sender(): void {
		Filters\expectApplied( 'wp_mail_from' )->once()->with( 'no-reply@mail.example.com' )->andReturnFirstArg();
		Filters\expectApplied( 'wp_mail_from_name' )->once()->with( 'Acme' )->andReturnFirstArg();

		$this->translate( array(), new Settings( 'no-reply@mail.example.com', 'Acme', false ) );
	}

	public function test_translate_without_a_configured_sender_falls_back_to_the_wordpress_default(): void {
		$request = $this->translate( array(), new Settings( '', '', true ) )[0]['request'];

		self::assertSame( 'WordPress <wordpress@example.com>', $request->from );
	}

	public function test_translate_refuses_a_sender_that_is_not_an_address(): void {
		Filters\expectApplied( 'wp_mail_from' )->andReturn( 'nobody' );

		self::assertRefused( 'invalid_from', fn() => $this->translate( array(), new Settings( 'no-reply@mail.example.com', 'Acme', false ) ) );
	}

	public function test_translate_applies_the_content_type_filter(): void {
		Filters\expectApplied( 'wp_mail_content_type' )->once()->with( 'text/plain' )->andReturn( 'text/html' );

		$request = $this->translate( array() )[0]['request'];

		self::assertSame( 'Hi Ada', $request->html );
		self::assertNull( $request->text );
	}

	/**
	 * The two ways WordPress passes a multipart boundary: in the Content-Type header (6.9 and later), or on a line of its
	 * own, as older callers wrote it.
	 *
	 * @return array<string, array{list<string>}>
	 */
	public static function multipart_headers(): array {
		return array(
			'boundary in the content type' => array( array( 'Content-Type: multipart/alternative; boundary="b1"' ) ),
			'boundary on its own line'     => array( array( 'Content-Type: multipart/alternative', 'boundary="b1"' ) ),
		);
	}

	/**
	 * Test.
	 *
	 * @param list<string> $headers The headers.
	 */
	#[DataProvider( 'multipart_headers' )]
	public function test_translate_takes_text_and_html_from_a_multipart_alternative_body( array $headers ): void {
		$body = "--b1\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 open\r\n"
			. "--b1\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode( '<p>Café open</p>' ) . "\r\n"
			. "--b1--\r\n";

		$request = $this->translate(
			array(
				'headers' => $headers,
				'message' => $body,
			)
		)[0]['request'];

		self::assertSame( 'Café open', $request->text );
		self::assertSame( '<p>Café open</p>', $request->html );
	}

	public function test_translate_converts_the_charset_to_utf8(): void {
		$request = $this->translate(
			array(
				'subject' => "Caf\xE9",
				'message' => "Caf\xE9 open",
				'headers' => 'Content-Type: text/plain; charset=ISO-8859-1',
			)
		)[0]['request'];

		self::assertSame( 'Café', $request->subject );
		self::assertSame( 'Café open', $request->text );
	}

	public function test_translate_keeps_only_the_custom_headers_postilio_takes(): void {
		$request = $this->translate(
			array(
				'headers' => array(
					'MIME-Version: 1.0',
					'X-Mailer: Some Plugin',
					'Importance: high',
					'X-Postilio-Tenant: 1',
					'X-Accented: Café',
					'List-Unsubscribe: <https://example.com/u/1>',
					'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
					'In-Reply-To: <1@example.com>',
					'References: <1@example.com>',
					'X-A: 1',
					'X-B: 2',
					'X-C: 3',
					'X-D: 4',
					'X-E: 5',
					'X-F: 6',
					'X-G: 7',
				),
			)
		)[0]['request'];

		self::assertSame(
			array(
				'List-Unsubscribe'      => '<https://example.com/u/1>',
				'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
				'In-Reply-To'           => '<1@example.com>',
				'References'            => '<1@example.com>',
				'X-A'                   => '1',
				'X-B'                   => '2',
				'X-C'                   => '3',
				'X-D'                   => '4',
				'X-E'                   => '5',
				'X-F'                   => '6',
			),
			$request->headers
		);
	}

	public function test_translate_strips_line_breaks_from_the_subject(): void {
		self::assertSame( 'Hello there', $this->translate( array( 'subject' => "Hello\r\n there" ) )[0]['request']->subject );
	}

	public function test_translate_refuses_an_empty_message(): void {
		self::assertRefused( 'empty_message', fn() => $this->translate( array( 'message' => '' ) ) );
	}

	public function test_translate_attaches_files_and_embeds_by_path(): void {
		$pdf = $this->file( 'invoice.pdf', '%PDF' );
		$png = $this->file( 'logo.png', 'PNG' );
		Filters\expectApplied( 'wp_mail_embed_args' )->once()->andReturnUsing(
			static function ( array $args ) {
				$args['name'] = 'brand.png';
				return $args;
			}
		);

		$attachments = $this->translate(
			array(
				'attachments' => array( 'Invoice 1042.pdf' => $pdf ),
				'embeds'      => array( 'logo' => $png ),
			)
		)[0]['request']->attachments;

		self::assertNotNull( $attachments );
		self::assertCount( 2, $attachments );
		self::assertSame( array( 'Invoice 1042.pdf', 'application/pdf', '%PDF', null ), array( $attachments[0]->fileName, $attachments[0]->contentType, $attachments[0]->content, $attachments[0]->contentId ) );
		self::assertSame( array( 'brand.png', 'image/png', 'PNG', 'logo' ), array( $attachments[1]->fileName, $attachments[1]->contentType, $attachments[1]->content, $attachments[1]->contentId ) );
	}

	public function test_translate_reads_attachments_given_as_lines_and_names_them_by_file(): void {
		$first  = $this->file( 'a.txt', 'A' );
		$second = $this->file( 'b.bin', 'B' );

		$attachments = $this->translate( array( 'attachments' => "$first\r\n$second" ) )[0]['request']->attachments;

		self::assertNotNull( $attachments );
		self::assertSame( array( basename( $first ), basename( $second ) ), array( $attachments[0]->fileName, $attachments[1]->fileName ) );
		self::assertSame( 'application/octet-stream', $attachments[1]->contentType );
	}

	/**
	 * Attachments the plug-in refuses, where WordPress would skip them without a word.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused_attachments(): array {
		return array(
			'missing file'    => array( '/nonexistent/file.pdf' ),
			'directory'       => array( '/tmp' ),
			'stream wrapper'  => array( 'phar:///tmp/x.phar/file.pdf' ),
			'url'             => array( 'https://example.com/file.pdf' ),
			'parent segments' => array( '/tmp/../etc/hostname' ),
		);
	}

	/**
	 * Test.
	 *
	 * @param string $path The attachment.
	 */
	#[DataProvider( 'refused_attachments' )]
	public function test_translate_refuses_an_attachment_it_cannot_read_safely( string $path ): void {
		self::assertRefused( 'attachment_not_readable', fn() => $this->translate( array( 'attachments' => array( $path ) ) ) );
	}

	public function test_translate_refuses_more_than_twenty_attachments(): void {
		$file = $this->file( 'a.txt', 'A' );

		self::assertRefused( 'too_many_attachments', fn() => $this->translate( array( 'attachments' => array_fill( 0, 21, $file ) ) ) );
	}

	public function test_translate_refuses_a_message_over_ten_megabytes_before_reading_it(): void {
		$file   = $this->file( 'big.bin', '' );
		$handle = fopen( $file, 'r+' );
		self::assertNotFalse( $handle );
		ftruncate( $handle, 10 * 1024 * 1024 + 1 ); // Sparse: takes no disk space.
		fclose( $handle );

		self::assertRefused( 'message_too_large', fn() => $this->translate( array( 'attachments' => array( $file ) ) ) );
	}

	public function test_translate_sends_cc_and_bcc_with_a_single_recipient_as_they_are(): void {
		$requests = $this->translate(
			array(
				'to'      => 'ada@example.org',
				'headers' => array( 'Cc: grace@example.org, ada@example.org', 'Bcc: archive@example.org' ),
			)
		);

		self::assertCount( 1, $requests );
		self::assertSame( array( 'ada@example.org' ), $requests[0]['request']->to );
		self::assertSame( array( 'grace@example.org' ), $requests[0]['request']->cc );
		self::assertSame( array( 'archive@example.org' ), $requests[0]['request']->bcc );
	}

	public function test_translate_refuses_cc_or_bcc_with_several_recipients(): void {
		self::assertRefused(
			'cc_bcc_require_single_to',
			fn() => $this->translate(
				array(
					'to'      => 'ada@example.org, grace@example.org',
					'headers' => 'Bcc: archive@example.org',
				)
			)
		);
	}

	public function test_translate_splits_more_than_fifty_recipients_into_requests_of_fifty(): void {
		$to = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$to[] = "user$i@example.org";
		}

		$requests = $this->translate( array( 'to' => $to ) );

		self::assertSame( array( 50, 50, 20 ), array_map( static fn( array $r ) => count( $r['request']->to ), $requests ) );
		self::assertSame( 'user119@example.org', $requests[2]['request']->to[19] );
	}

	public function test_translate_splits_a_large_message_so_its_size_times_its_recipients_stays_within_25_megabytes(): void {
		$file = $this->file( 'report.pdf', str_repeat( 'x', 3 * 1024 * 1024 ) );

		$requests = $this->translate(
			array(
				'to'          => array( 'a@example.org', 'b@example.org', 'c@example.org', 'd@example.org', 'e@example.org', 'f@example.org', 'g@example.org', 'h@example.org', 'i@example.org' ),
				'attachments' => array( $file ),
			)
		);

		self::assertSame( array( 8, 1 ), array_map( static fn( array $r ) => count( $r['request']->to ), $requests ) );
	}

	public function test_translate_derives_the_same_idempotency_key_for_the_same_call_within_the_hour(): void {
		$first  = $this->translate( array(), null, self::NOW );
		$second = $this->translate( array(), null, self::NOW + 1799 );
		$other  = $this->translate( array( 'subject' => 'Hello again' ), null, self::NOW );
		$later  = $this->translate( array(), null, self::NOW + 3600 );

		self::assertSame( $first[0]['key'], $second[0]['key'] );
		self::assertNotSame( $first[0]['key'], $other[0]['key'] );
		self::assertNotSame( $first[0]['key'], $later[0]['key'] );
		self::assertMatchesRegularExpression( '/^wp-[0-9a-f]{64}$/', $first[0]['key'] );
	}

	public function test_translate_gives_each_request_of_a_split_call_its_own_key(): void {
		$to = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$to[] = "user$i@example.org";
		}

		$requests = $this->translate( array( 'to' => $to ) );

		self::assertNotSame( $requests[0]['key'], $requests[1]['key'] );
	}

	/**
	 * Translates a wp_mail() call with defaults for what the test does not set.
	 *
	 * @param array<string, mixed> $atts     The wp_mail() arguments that differ from the defaults.
	 * @param Settings|null        $settings The plug-in's settings; a forced sender by default.
	 * @param int                  $now      The time.
	 *
	 * @return list<array{request: SendEmailRequest, key: string}>
	 */
	private function translate( array $atts, ?Settings $settings = null, int $now = self::NOW ): array {
		return WpMail::translate(
			$atts + array(
				'to'          => 'ada@example.org',
				'subject'     => 'Hello',
				'message'     => 'Hi Ada',
				'headers'     => '',
				'attachments' => array(),
			),
			$settings ?? new Settings( 'no-reply@mail.example.com', 'Acme', true ),
			$now
		);
	}

	/**
	 * Asserts that the call is refused with the code.
	 *
	 * @param string   $code The MailError code.
	 * @param callable $call The call.
	 */
	private static function assertRefused( string $code, callable $call ): void {
		try {
			$call();
			self::fail( "Not refused; expected $code." );
		} catch ( MailError $e ) {
			self::assertSame( $code, $e->error );
			self::assertNotSame( '', $e->getMessage() );
		}
	}

	private static function dir(): string {
		return sys_get_temp_dir() . '/postilio-wp-tests-' . getmypid();
	}

	private function file( string $name, string $content ): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}
		$path = $dir . '/' . $name;
		file_put_contents( $path, $content );
		$this->files[] = $path;
		return $path;
	}
}
