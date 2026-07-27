<?php
/**
 * HTTP transport, including response-envelope resolution.
 *
 * @package Codexpert\PluginTracker
 */

namespace Codexpert\PluginTracker\Http;

use Codexpert\PluginTracker\Config;
use Codexpert\PluginTracker\Tracker;

/**
 * Sends registration and event batches, and decides what a response means.
 *
 * The interesting part of this class is not the sending, it is result(). The backend emits TWO
 * different error envelopes and neither `success` nor the HTTP status is sufficient on its own.
 * See docs/WIRE.md for the full reasoning; the short version:
 *
 *   - Errors decided inside a route callback use  {"success":false,"data":{"message":...}}
 *   - Errors from a permission_callback never reach the callback. WP_REST_Server converts them
 *     to a WP_Error and serialises  {"code":"rest_forbidden","message":...,"data":{"status":401}}
 *     -- with NO `success` key at all.
 *
 * A client that keys only off `success` therefore cannot tell "my token was revoked" from "the
 * server is broken", and will retry a dead token forever. Status decides auth and rate limiting;
 * `success` decides application outcome.
 */
class Transport {

	const RESULT_OK        = 'ok';
	const RESULT_AUTH      = 'auth';
	const RESULT_RATE      = 'rate';
	const RESULT_RETRY     = 'retry';
	const RESULT_PERMANENT = 'permanent';

	/**
	 * Request timeout, seconds.
	 *
	 * Short deliberately. This only ever runs on a scheduled job, but a cron request that hangs
	 * for 30s still occupies a PHP worker on the consumer's host, and telemetry is not worth
	 * that. The existing plugin's timeouts range from unset to 3000s with no consistency, so
	 * there is no house value to inherit -- this is chosen, not copied.
	 */
	const TIMEOUT = 8;

	/**
	 * Config.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * User agent, carrying the SDK version so ingestion can attribute traffic.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'PluginTrackerSDK/' . Tracker::VERSION;
	}

	/**
	 * POST JSON to a telemetry path.
	 *
	 * @param string $path  Path under the namespace, e.g. 'telemetry/events'.
	 * @param array  $body  Body to encode.
	 * @param string $token Optional bearer token.
	 * @return array {status:int, body:array|null, error:string|null}
	 */
	private function post( $path, array $body, $token = '' ) {

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			$this->config->endpoint() . '/' . ltrim( $path, '/' ),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => $headers,
				'user-agent'  => $this->user_agent(),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'      => 0,
				'body'        => null,
				'error'       => $response->get_error_message(),
				'retry_after' => '',
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => is_array( $decoded ) ? $decoded : null,
			'error'       => null,
			'retry_after' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
		);
	}

	/**
	 * Classify a raw response.
	 *
	 * Order matters here and is the whole point -- see the class docblock.
	 *
	 * @param array $raw Result of post().
	 * @return string One of the RESULT_* constants.
	 */
	public function result( array $raw ) {

		// 1. Transport failure: DNS, TLS, timeout. Nothing was delivered, so retry.
		if ( ! empty( $raw['error'] ) ) {
			return self::RESULT_RETRY;
		}

		$status = isset( $raw['status'] ) ? (int) $raw['status'] : 0;

		// 2. Auth, decided by STATUS not body. This is the case the `success`-only rule gets
		// wrong: a permission_callback failure has no `success` key at all.
		if ( 401 === $status || 403 === $status ) {
			return self::RESULT_AUTH;
		}

		// 3. Rate limited.
		if ( 429 === $status ) {
			return self::RESULT_RATE;
		}

		// 4. Server fault, decided by STATUS before the body is consulted.
		//
		// This ordering matters. A maintenance page or an overloaded backend can legitimately
		// return 503 WITH a {"success":false} body -- this backend's own error helper sets a status
		// code and emits that envelope together. Checking `success` first would classify it as a
		// permanent application rejection and destroy the batch on the first failure, when the
		// correct reading is "come back later".
		if ( $status >= 500 ) {
			return self::RESULT_RETRY;
		}

		$body = isset( $raw['body'] ) && is_array( $raw['body'] ) ? $raw['body'] : null;

		// 5/6. Application envelope. Only reached for a non-5xx status, so `success:false` here
		// really does mean the application rejected the batch on its merits.
		if ( null !== $body && array_key_exists( 'success', $body ) ) {
			return ! empty( $body['success'] ) ? self::RESULT_OK : self::RESULT_PERMANENT;
		}

		// 7. Core WP_Error envelope without a `success` key. Map by its nested status, since the
		// HTTP status may have been rewritten by an intermediary.
		if ( null !== $body && isset( $body['code'] ) && isset( $body['message'] ) ) {
			$nested = isset( $body['data']['status'] ) ? (int) $body['data']['status'] : $status;

			if ( 401 === $nested || 403 === $nested ) {
				return self::RESULT_AUTH;
			}
			if ( 429 === $nested ) {
				return self::RESULT_RATE;
			}
			if ( $nested >= 500 ) {
				return self::RESULT_RETRY;
			}

			return self::RESULT_PERMANENT;
		}

		// 8. Anything left is a body we could not interpret -- most often an HTML page injected by a
		// WAF, a proxy, or a host's maintenance mode, served with a 200. Retryable: we have no
		// evidence the batch itself is bad, and the retry budget bounds it, so an endpoint serving
		// junk forever costs 6 attempts rather than an infinite loop.
		return self::RESULT_RETRY;
	}

	/**
	 * Register this install and obtain a token.
	 *
	 * @param array $payload Registration payload.
	 * @return array {result:string, token:string, flush_interval:int, raw:array}
	 */
	public function register( array $payload ) {
		$raw    = $this->post( 'telemetry/register', $payload );
		$result = $this->result( $raw );
		$token  = '';
		$every  = 0;

		if ( self::RESULT_OK === $result ) {
			$data  = isset( $raw['body']['data'] ) && is_array( $raw['body']['data'] ) ? $raw['body']['data'] : array();
			$token = isset( $data['token'] ) && is_string( $data['token'] ) ? $data['token'] : '';
			$every = isset( $data['flush_interval'] ) ? (int) $data['flush_interval'] : 0;

			// A 200 with success:true but no token is a server bug. Treat it as a permanent
			// failure rather than storing an empty token and then sending unauthenticated
			// batches forever.
			if ( '' === $token ) {
				$result = self::RESULT_PERMANENT;
			}
		}

		return array(
			'result'         => $result,
			'token'          => $token,
			'flush_interval' => $every,
			'raw'            => $raw,
		);
	}

	/**
	 * Send a batch of events.
	 *
	 * @param array  $envelope Full envelope including events.
	 * @param string $token    Install token.
	 * @return array {result:string, notice:array|null, retry_after:int, raw:array}
	 */
	public function send( array $envelope, $token ) {
		$raw    = $this->post( 'telemetry/events', $envelope, $token );
		$result = $this->result( $raw );

		$notice = null;
		if ( isset( $raw['body']['data']['notice'] ) && is_array( $raw['body']['data']['notice'] ) ) {
			$notice = $raw['body']['data']['notice'];
		}

		// Retry-After is an HTTP header by convention (RFC 9110), so the header wins. The body
		// field is accepted as a fallback because this backend's own convention is to put
		// everything in the JSON envelope, and a proxy may strip headers.
		//
		// Only the delta-seconds form is honoured. The HTTP-date form is legal but is not parsed
		// here: a mis-parsed date could yield a delay of years, and silently parking a queue
		// forever is worse than ignoring the hint and using normal backoff.
		$retry_after = 0;
		if ( self::RESULT_RATE === $result ) {
			$header = isset( $raw['retry_after'] ) ? trim( (string) $raw['retry_after'] ) : '';

			if ( '' !== $header && ctype_digit( $header ) ) {
				$retry_after = (int) $header;
			} elseif ( isset( $raw['body']['data']['retry_after'] ) ) {
				$retry_after = (int) $raw['body']['data']['retry_after'];
			}
		}

		return array(
			'result'      => $result,
			'notice'      => $notice,
			'retry_after' => $retry_after,
			'raw'         => $raw,
		);
	}
}
