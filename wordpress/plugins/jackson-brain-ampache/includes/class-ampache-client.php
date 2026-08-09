<?php
/**
 * Allowlisted, context-guarded HTTP client for the Ampache JSON API. Every request goes
 * through Method_Policy first, and this class refuses to run at all outside WP-Cron or an
 * explicitly authorized manual admin action - see 02-architecture-api.md "Guarding the
 * Ampache server from request volume".
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ampache_Client {

	private const ENDPOINT_PATH             = '/server/json.server.php';
	private const API_VERSION               = '6.9.0';
	private const USER_AGENT_PREFIX         = 'JacksonBrain-WP-Ampache/';
	// Confirmed live 2026-08-08: the `artists` list action alone takes ~3.5s on this
	// Ampache install (likely an expensive per-artist aggregation query), consistently
	// exceeding a 3s budget and failing as a transport-level timeout even though the
	// request eventually succeeds server-side. Only cron/manual refreshes use this
	// timeout (never a public page render - see class docblock), so a more generous
	// budget here is safe.
	private const JSON_TIMEOUT              = 8;
	// Confirmed live 2026-08-08: the `artists` list action also ignores the requested
	// `limit` param entirely (same "ignores requested size" bug pattern already confirmed
	// for get_art's `size`) and returns the FULL ~4060-row list (3.27MB) regardless -
	// only `total_count` is ever read from this response, but the transport layer must
	// not truncate the body before json_decode() gets a chance to parse it. Raised from
	// 256KB accordingly; still only used for background cron/manual refreshes, never a
	// public page render.
	private const JSON_SIZE_LIMIT           = 4194304;
	// Ampache has been observed ignoring the requested artwork `size` and returning a
	// full-resolution original (confirmed live: a 512x512 request came back 3.66MB); this
	// must be large enough for the transport layer to not truncate that before
	// Artwork_Cache gets a chance to resize it down.
	private const ART_SIZE_LIMIT            = 8388608;
	private const MANUAL_ACTION_COOLDOWN    = 30;
	private const OPTION_LAST_MANUAL_ACTION = 'jba_last_manual_action';

	private static bool $manual_context_authorized = false;

	/**
	 * The configured Ampache origin is normally a LAN-private address (this plugin's whole
	 * point is talking to a same-network Ampache instance), which WordPress's own SSRF guard
	 * in wp_http_validate_url() rejects by default for anything other than the site's own
	 * home URL - confirmed live 2026-08-08: a raw curl to the exact same URL/credentials
	 * succeeded instantly, while wp_safe_remote_post() failed with "A valid URL was not
	 * provided." only because DNS for the origin resolves to a 192.168.0.0/16 address.
	 * WP_ACCESSIBLE_HOSTS does NOT fix this (that constant only affects the separate,
	 * opt-in WP_HTTP_BLOCK_EXTERNAL allow-all-blocking feature) - the actual gate is the
	 * 'http_request_host_is_external' filter, which this allowlists narrowly (by exact host,
	 * not a blanket bypass) for only the currently-configured origin.
	 */
	public static function register_hooks(): void {
		add_filter( 'http_request_host_is_external', array( self::class, 'allow_configured_origin' ), 10, 2 );
	}

	public static function allow_configured_origin( bool $is_external, string $host ): bool {
		if ( $is_external ) {
			return true;
		}

		$origin_host = wp_parse_url( Settings::get_origin(), PHP_URL_HOST );

		return is_string( $origin_host ) && '' !== $origin_host && strtolower( $origin_host ) === strtolower( $host );
	}

	/**
	 * Runs $callback (e.g. a full manual Refresh_Service call, which may issue several
	 * requests) with manual-context requests permitted. Must only be invoked by an admin
	 * action handler after its own manage_options + nonce check has already passed. The
	 * try/finally guarantees the flag is cleared even if $callback throws, so a reused
	 * PHP-FPM worker can never carry authorization into an unrelated later request.
	 *
	 * @return mixed
	 */
	public static function run_in_manual_context( callable $callback ) {
		self::$manual_context_authorized = true;

		try {
			return $callback();
		} finally {
			self::$manual_context_authorized = false;
		}
	}

	public static function requested_api_version(): string {
		return self::API_VERSION;
	}

	public static function manual_action_cooldown_remaining(): int {
		$elapsed = time() - (int) get_option( self::OPTION_LAST_MANUAL_ACTION, 0 );

		return max( 0, self::MANUAL_ACTION_COOLDOWN - $elapsed );
	}

	public static function record_manual_action(): void {
		update_option( self::OPTION_LAST_MANUAL_ACTION, time(), false );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function request( string $kind, array $params = array() ) {
		if ( ! self::context_permitted() ) {
			return self::forbidden_context_error();
		}

		$definition = Method_Policy::get( $kind );

		if ( null === $definition ) {
			return new \WP_Error( 'jba_unknown_request', 'Unknown or disallowed Ampache request.' );
		}

		$validated = Method_Policy::validate_params( $kind, $params );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$request_args = self::build_request_args( $definition, $validated, self::JSON_SIZE_LIMIT );

		if ( is_wp_error( $request_args ) ) {
			return $request_args;
		}

		$response = self::send_with_retry( $request_args, wp_doing_cron() );

		return self::parse_json_response( $response );
	}

	/**
	 * Fetches raw artwork bytes. MIME/magic-byte and dimension validation happen in the
	 * artwork fetcher/cache that consumes this, not here - this method is transport only.
	 *
	 * @return array{body:string,content_type:string}|\WP_Error
	 */
	public static function request_art( array $params ) {
		if ( ! self::context_permitted() ) {
			return self::forbidden_context_error();
		}

		$validated = Method_Policy::validate_params( 'get_art', $params );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$definition   = Method_Policy::get( 'get_art' );
		$request_args = self::build_request_args( $definition, $validated, self::ART_SIZE_LIMIT );

		if ( is_wp_error( $request_args ) ) {
			return $request_args;
		}

		// Artwork fetches are never retried; a later scheduled/admin refresh tries again.
		$response = wp_safe_remote_post( $request_args['url'], $request_args['args'] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'jba_transport_error', 'Ampache artwork request failed.' );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return new \WP_Error(
				'jba_http_error',
				'Ampache artwork request returned an unexpected status.',
				array( 'http_status' => $status )
			);
		}

		return array(
			'body'         => wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	private static function context_permitted(): bool {
		return wp_doing_cron() || self::$manual_context_authorized;
	}

	private static function forbidden_context_error(): \WP_Error {
		return new \WP_Error(
			'jba_forbidden_context',
			'Ampache requests are only permitted from WP-Cron or an authorized manual action.'
		);
	}

	/**
	 * @return array{url:string,args:array<string,mixed>}|\WP_Error
	 */
	private static function build_request_args( array $definition, array $validated, int $size_limit ) {
		$origin = Settings::get_origin();

		if ( '' === $origin ) {
			return new \WP_Error( 'jba_unconfigured', 'Ampache origin is not configured.' );
		}

		$headers = array(
			'User-Agent' => self::USER_AGENT_PREFIX . JBA_PLUGIN_VERSION,
		);

		if ( ! empty( $definition['require_auth'] ) ) {
			$api_key = Settings::get_api_key();

			if ( '' === $api_key ) {
				return new \WP_Error( 'jba_unconfigured', 'Ampache API key is not configured.' );
			}

			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$body = array_merge(
			array(
				'action'  => $definition['action'],
				'version' => self::API_VERSION,
			),
			$validated
		);

		return array(
			'url'  => $origin . self::ENDPOINT_PATH,
			'args' => array(
				'method'              => 'POST',
				'body'                => $body,
				'headers'             => $headers,
				'timeout'             => self::JSON_TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => $size_limit,
			),
		);
	}

	/**
	 * One retry after 250-750ms jitter for transport failures/5xx, and only during a
	 * scheduled (cron) refresh; manual actions never retry.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function send_with_retry( array $request_args, bool $retry_allowed ) {
		$response = wp_safe_remote_post( $request_args['url'], $request_args['args'] );

		if ( $retry_allowed && self::is_retryable( $response ) ) {
			usleep( wp_rand( 250000, 750000 ) );
			$response = wp_safe_remote_post( $request_args['url'], $request_args['args'] );
		}

		return $response;
	}

	private static function is_retryable( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		return wp_remote_retrieve_response_code( $response ) >= 500;
	}

	/**
	 * @param array<string,mixed>|\WP_Error $response
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function parse_json_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'jba_transport_error', 'Ampache request failed.' );
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( false === stripos( $content_type, 'json' ) ) {
			return new \WP_Error( 'jba_invalid_response', 'Ampache did not return JSON.' );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'jba_invalid_response', 'Ampache response was not valid JSON.' );
		}

		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			// Only the numeric errorCode is treated as stable; see 02-architecture-api.md
			// for why errorType/errorMessage are never used for control flow.
			return new \WP_Error(
				'jba_ampache_error',
				'Ampache returned an error.',
				array(
					'error_code'  => isset( $decoded['error']['errorCode'] ) ? (int) $decoded['error']['errorCode'] : 0,
					'http_status' => wp_remote_retrieve_response_code( $response ),
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'jba_http_error',
				'Ampache returned an unexpected HTTP status.',
				array( 'http_status' => $status )
			);
		}

		return $decoded;
	}
}
