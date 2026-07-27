<?php
/**
 * Tests for HTTP transport and, most importantly, response-envelope resolution.
 *
 * docs/WIRE.md is explicit that the backend emits TWO different error envelopes and that keying
 * off `success` alone is a silent bug -- specifically for authentication failures, where a
 * permission_callback rejection never reaches the route and carries no `success` key at all. This
 * file is the highest-value part of the suite: see Transport::result().
 *
 * @package Codexpert\PluginTracker\Test
 */

namespace Codexpert\PluginTracker\Test\Http;

use Brain\Monkey\Functions;
use Codexpert\PluginTracker\Http\Transport;
use Codexpert\PluginTracker\Test\PluginTrackerTestCase;

/**
 * @covers \Codexpert\PluginTracker\Http\Transport
 */
class TransportTest extends PluginTrackerTestCase {

	/**
	 * @return Transport
	 */
	private function transport() {
		return new Transport( $this->make_config() );
	}

	/**
	 * 1. A transport-level failure (DNS, TLS, timeout) delivered nothing, so it must be retried.
	 */
	public function test_transport_error_is_retryable() {
		$raw = array(
			'status' => 0,
			'body'   => null,
			'error'  => 'cURL error 28: Operation timed out',
		);

		$this->assertSame( Transport::RESULT_RETRY, $this->transport()->result( $raw ) );
	}

	/**
	 * 2a. HTTP 401 is an auth failure decided by STATUS, regardless of body shape.
	 */
	public function test_http_401_is_auth_failure() {
		$raw = array(
			'status' => 401,
			'body'   => array(
				'success' => false,
				'data'    => array( 'message' => 'invalid token' ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_AUTH, $this->transport()->result( $raw ) );
	}

	/**
	 * 2b. HTTP 401 with the WP core permission_callback shape -- {"code":"rest_forbidden",
	 * "message":...,"data":{"status":401}} -- carries NO `success` key at all. This is exactly the
	 * case spec 10.6 got wrong: a client keying off `success` reads this as indeterminate and
	 * retries a revoked token forever.
	 */
	public function test_http_401_core_shape_with_no_success_key_is_auth_failure() {
		$raw = array(
			'status' => 401,
			'body'   => array(
				'code'    => 'rest_forbidden',
				'message' => 'Sorry, you are not allowed to do that.',
				'data'    => array( 'status' => 401 ),
			),
			'error'  => null,
		);

		$this->assertArrayNotHasKey( 'success', $raw['body'] );
		$this->assertSame( Transport::RESULT_AUTH, $this->transport()->result( $raw ) );
	}

	/**
	 * 2c. Same core shape, no body parsed at all (e.g. an intermediary swallowed it) -- status
	 * alone must still decide auth.
	 */
	public function test_http_401_with_unparseable_body_is_still_auth_failure() {
		$raw = array(
			'status' => 401,
			'body'   => null,
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_AUTH, $this->transport()->result( $raw ) );
	}

	/**
	 * 2d. HTTP 403, core shape, no `success` key -- same reasoning as 401.
	 */
	public function test_http_403_core_shape_is_auth_failure() {
		$raw = array(
			'status' => 403,
			'body'   => array(
				'code'    => 'rest_forbidden',
				'message' => 'Forbidden',
				'data'    => array( 'status' => 403 ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_AUTH, $this->transport()->result( $raw ) );
	}

	/**
	 * 3. HTTP 429 is rate limiting, decided before the application envelope is even inspected.
	 */
	public function test_http_429_is_rate_limited() {
		$raw = array(
			'status' => 429,
			'body'   => array(
				'code'    => 'rest_rate_limited',
				'message' => 'Too many requests.',
				'data'    => array( 'status' => 429 ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_RATE, $this->transport()->result( $raw ) );
	}

	/**
	 * 4. {"success":true} with 200 is accepted.
	 */
	public function test_success_true_with_200_is_ok() {
		$raw = array(
			'status' => 200,
			'body'   => array(
				'success' => true,
				'data'    => array( 'accepted' => 12 ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_OK, $this->transport()->result( $raw ) );
	}

	/**
	 * 5. {"success":false} with 200 is a permanent, application-level rejection -- not retryable.
	 */
	public function test_success_false_with_200_is_permanent() {
		$raw = array(
			'status' => 200,
			'body'   => array(
				'success' => false,
				'data'    => array( 'message' => 'malformed batch' ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_PERMANENT, $this->transport()->result( $raw ) );
	}

	/**
	 * 6a. Core-shape body (no `success` key) whose nested data.status is a 5xx must be retried.
	 */
	public function test_core_shape_with_nested_5xx_status_is_retryable() {
		$raw = array(
			'status' => 500,
			'body'   => array(
				'code'    => 'internal_error',
				'message' => 'Something went wrong.',
				'data'    => array( 'status' => 500 ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_RETRY, $this->transport()->result( $raw ) );
	}

	/**
	 * 6b. Core-shape body whose nested data.status is a 4xx (other than 401/403/429) is permanent.
	 */
	public function test_core_shape_with_nested_400_status_is_permanent() {
		$raw = array(
			'status' => 400,
			'body'   => array(
				'code'    => 'rest_invalid_param',
				'message' => 'Invalid parameter.',
				'data'    => array( 'status' => 400 ),
			),
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_PERMANENT, $this->transport()->result( $raw ) );
	}

	/**
	 * 7. HTTP 5xx with an unparseable body (couldn't be json_decode'd into an array) is a
	 * retryable server fault.
	 */
	public function test_http_500_with_unparseable_body_is_retryable() {
		$raw = array(
			'status' => 500,
			'body'   => null,
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_RETRY, $this->transport()->result( $raw ) );
	}

	/**
	 * A 200 carrying a body we cannot parse is RETRYABLE, not permanent.
	 *
	 * This is almost always a WAF, a proxy, or a host's maintenance mode substituting an HTML page
	 * for the real response. There is no evidence the batch itself is bad, so destroying it would
	 * be silent data loss -- and docs/WIRE.md says so explicitly ("Anything left is a body we could
	 * not interpret ... Retryable"). The retry budget bounds it, so an endpoint serving junk
	 * forever costs six attempts rather than an infinite loop.
	 *
	 * Previously classified permanent, which dropped and counted the batch on the first failure.
	 */
	public function test_http_200_with_an_unparseable_body_is_retryable() {
		$raw = array(
			'status' => 200,
			'body'   => null,
			'error'  => null,
		);

		$this->assertSame( Transport::RESULT_RETRY, $this->transport()->result( $raw ) );
	}

	/**
	 * 9. The most consequential registration case: a 200 with `success:true` but no token in the
	 * response data is a server bug. register() must treat it as RESULT_PERMANENT, never RESULT_OK
	 * -- otherwise the SDK stores an empty token and sends unauthenticated batches forever.
	 */
	public function test_register_treats_a_200_success_response_with_no_token_as_permanent() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array( 'flush_interval' => 86400 ),
				)
			)
		);

		$registered = $this->transport()->register(
			array(
				'schema'  => 1,
				'sdk'     => '1.0.0',
				'project' => 'pt_proj_abc123',
				'install' => 'ins_deadbeef',
				'plugin'  => 'my-plugin',
				'consent' => array(
					'policy' => 1,
					'at'     => 1700000000,
				),
			)
		);

		$this->assertSame( Transport::RESULT_PERMANENT, $registered['result'] );
		$this->assertSame( '', $registered['token'] );
	}

	/**
	 * Control case for the above: when a token IS present, register() reports RESULT_OK and
	 * returns it. Without this, test_register_treats_a_200_success_response_with_no_token_as_permanent()
	 * could pass for the wrong reason (e.g. register() always returning PERMANENT).
	 */
	public function test_register_returns_ok_and_token_when_present() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array(
						'token'          => 'ins_tok_abc',
						'flush_interval' => 86400,
					),
				)
			)
		);

		$registered = $this->transport()->register(
			array(
				'schema'  => 1,
				'sdk'     => '1.0.0',
				'project' => 'pt_proj_abc123',
				'install' => 'ins_deadbeef',
				'plugin'  => 'my-plugin',
				'consent' => array(
					'policy' => 1,
					'at'     => 1700000000,
				),
			)
		);

		$this->assertSame( Transport::RESULT_OK, $registered['result'] );
		$this->assertSame( 'ins_tok_abc', $registered['token'] );
		$this->assertSame( 86400, $registered['flush_interval'] );
	}

	/**
	 * send() must surface a server-supplied notice and, on a 429, the retry_after hint, so
	 * Tracker can act on both.
	 */
	public function test_send_extracts_notice_and_retry_after_on_rate_limit() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'code'    => 'rest_rate_limited',
					'message' => 'Too many requests.',
					'data'    => array(
						'status'      => 429,
						'retry_after' => 120,
						'notice'      => array(
							'level'   => 'warning',
							'message' => 'slow down',
						),
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_RATE, $sent['result'] );
		$this->assertSame( 120, $sent['retry_after'] );
		$this->assertSame(
			array(
				'level'   => 'warning',
				'message' => 'slow down',
			),
			$sent['notice']
		);
	}

	/**
	 * retry_after is only meaningful for RESULT_RATE; it must not leak through on an ordinary
	 * success even if some unrelated field happened to be present.
	 */
	public function test_send_does_not_report_retry_after_on_success() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array(
						'accepted' => 5,
						'rejected' => 0,
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_OK, $sent['result'] );
		$this->assertSame( 0, $sent['retry_after'] );
		$this->assertNull( $sent['notice'] );
	}

	/**
	 * retry_after stays 0 on a 200 even when a (meaningless, here) Retry-After header is present --
	 * the header is only consulted for RESULT_RATE.
	 */
	public function test_send_retry_after_is_zero_on_success_even_when_header_present() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '120' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'success' => true,
					'data'    => array(
						'accepted' => 5,
						'rejected' => 0,
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_OK, $sent['result'] );
		$this->assertSame( 0, $sent['retry_after'] );
	}

	/**
	 * On a 429, the Retry-After HTTP header wins over the body's data.retry_after when both are
	 * present -- see Transport::send(): the header is the RFC 9110 convention and is checked
	 * first.
	 */
	public function test_send_prefers_the_retry_after_header_over_the_body_value() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '120' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'code'    => 'rest_rate_limited',
					'message' => 'Too many requests.',
					'data'    => array(
						'status'      => 429,
						'retry_after' => 999,
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_RATE, $sent['result'] );
		$this->assertSame( 120, $sent['retry_after'] );
	}

	/**
	 * When the header is absent, the body's data.retry_after is used as a fallback -- this backend
	 * puts everything in the JSON envelope by convention, and a proxy may strip headers.
	 */
	public function test_send_falls_back_to_the_body_value_when_the_header_is_absent() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		// wp_remote_retrieve_header() defaults to '' -- see PluginTrackerTestCase::stub_options().
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'code'    => 'rest_rate_limited',
					'message' => 'Too many requests.',
					'data'    => array(
						'status'      => 429,
						'retry_after' => 77,
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_RATE, $sent['result'] );
		$this->assertSame( 77, $sent['retry_after'] );
	}

	/**
	 * Only the delta-seconds form of Retry-After is honoured. An HTTP-date header (legal per
	 * RFC 9110, but not parsed by design -- see Transport::send()) is ignored rather than parsed,
	 * and resolution falls back to the body value.
	 */
	public function test_send_ignores_an_http_date_retry_after_header_and_falls_back_to_the_body_value() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'Wed, 21 Oct 2026 07:28:00 GMT' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'code'    => 'rest_rate_limited',
					'message' => 'Too many requests.',
					'data'    => array(
						'status'      => 429,
						'retry_after' => 45,
					),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_RATE, $sent['result'] );
		$this->assertSame( 45, $sent['retry_after'] );
	}

	/**
	 * Same non-numeric header, but with no body fallback available either: resolution must default
	 * to 0, never to some parsed fragment of the date string.
	 */
	public function test_send_ignores_an_http_date_retry_after_header_and_defaults_to_zero_without_a_body_fallback() {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'fake' => 'response' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'Wed, 21 Oct 2026 07:28:00 GMT' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'code'    => 'rest_rate_limited',
					'message' => 'Too many requests.',
					'data'    => array( 'status' => 429 ),
				)
			)
		);

		$sent = $this->transport()->send( array( 'events' => array() ), 'ins_tok_abc' );

		$this->assertSame( Transport::RESULT_RATE, $sent['result'] );
		$this->assertSame( 0, $sent['retry_after'] );
	}
}
