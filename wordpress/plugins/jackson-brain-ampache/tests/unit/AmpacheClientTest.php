<?php
/**
 * Ampache_Client is the enforced "no public request can reach Ampache" boundary and the
 * request-budget/error-handling contract from 02-architecture-api.md. These tests focus on
 * what must never happen (an HTTP call outside cron/manual context, or before validation)
 * and the exact retry/error-code behavior once a call is actually permitted.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Ampache_Client;
use JacksonBrain\Ampache\Tests\TestCase;

final class AmpacheClientTest extends TestCase {

	private function stub_untrailingslashit(): void {
		Functions\when( 'untrailingslashit' )->alias( static fn( $value ) => rtrim( (string) $value, '/' ) );
	}

	public function test_request_is_forbidden_outside_cron_or_manual_context(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\expect( 'wp_safe_remote_post' )->never();

		$result = Ampache_Client::request( 'now_playing' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'jba_forbidden_context', $result->get_error_code() );
	}

	public function test_run_in_manual_context_permits_then_resets_afterward(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		$inside = Ampache_Client::run_in_manual_context(
			static fn() => Ampache_Client::request( 'a_kind_that_does_not_exist' )
		);

		// Reaching Method_Policy (jba_unknown_request) rather than jba_forbidden_context
		// proves the manual context was actually active for the call made inside the callback.
		$this->assertSame( 'jba_unknown_request', $inside->get_error_code() );

		$after = Ampache_Client::request( 'now_playing' );

		$this->assertSame( 'jba_forbidden_context', $after->get_error_code() );
	}

	public function test_unknown_request_kind_is_rejected_before_any_http_call(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\expect( 'wp_safe_remote_post' )->never();

		$result = Ampache_Client::request( 'not_a_real_kind' );

		$this->assertSame( 'jba_unknown_request', $result->get_error_code() );
	}

	public function test_invalid_params_are_rejected_before_any_http_call(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\expect( 'wp_safe_remote_post' )->never();

		$result = Ampache_Client::request( 'song', array() ); // missing required filter id.

		$this->assertSame( 'jba_invalid_param', $result->get_error_code() );
	}

	public function test_unconfigured_origin_is_rejected_before_any_http_call(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\expect( 'wp_safe_remote_post' )->never();

		// Neither JBA_AMPACHE_ORIGIN nor JBA_AMPACHE_DEV_MODE is defined in this process,
		// so Settings::get_origin() resolves to '' without needing any further stubs.
		$result = Ampache_Client::request( 'now_playing' );

		$this->assertSame( 'jba_unconfigured', $result->get_error_code() );
	}

	public function test_manual_action_cooldown_counts_down_from_thirty_seconds(): void {
		Functions\when( 'get_option' )->justReturn( time() - 10 );

		$this->assertSame( 20, Ampache_Client::manual_action_cooldown_remaining() );
	}

	public function test_manual_action_cooldown_never_goes_negative(): void {
		Functions\when( 'get_option' )->justReturn( time() - 999 );

		$this->assertSame( 0, Ampache_Client::manual_action_cooldown_remaining() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_successful_request_returns_decoded_json(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_untrailingslashit();
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_safe_remote_post' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'application/json; charset=utf-8' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'server' => 'ampache', 'version' => '6.9.0' ) ) );

		$result = Ampache_Client::request( 'ping_probe' );

		$this->assertSame( array( 'server' => 'ampache', 'version' => '6.9.0' ), $result );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_request_sends_bearer_auth_and_the_exact_documented_budgets(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_untrailingslashit();
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'application/json' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'songs' => 1 ) ) );

		Functions\expect( 'wp_safe_remote_post' )
			->once()
			->with(
				'https://music.jackson-brain.com/server/json.server.php',
				\Mockery::on(
					static function ( $args ) {
						return 'Bearer test-key' === ( $args['headers']['Authorization'] ?? null )
							&& 8 === $args['timeout']
							&& 0 === $args['redirection']
							&& true === $args['sslverify']
							&& true === $args['reject_unsafe_urls']
							&& 4194304 === $args['limit_response_size'];
					}
				)
			)
			->andReturn( array() );

		$result = Ampache_Client::request( 'ping_stats' );

		$this->assertSame( array( 'songs' => 1 ), $result );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ampache_error_envelope_surfaces_only_the_numeric_code(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_untrailingslashit();
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_safe_remote_post' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'application/json' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			json_encode(
				array(
					'error' => array(
						'errorCode'    => '4701',
						'errorAction'  => 'handshake',
						'errorType'    => 'account',
						'errorMessage' => 'Session Expired',
					),
				)
			)
		);

		$result = Ampache_Client::request( 'ping_stats' );

		$this->assertSame( 'jba_ampache_error', $result->get_error_code() );
		$this->assertSame( 4701, $result->get_error_data()['error_code'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_retries_once_on_5xx_during_a_cron_refresh(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_untrailingslashit();
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'usleep' )->justReturn( null );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'application/json' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'songs' => 1 ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => 1 === $response['attempt'] ? 503 : 200
		);

		Functions\expect( 'wp_safe_remote_post' )
			->twice()
			->andReturn( array( 'attempt' => 1 ), array( 'attempt' => 2 ) );

		$result = Ampache_Client::request( 'ping_stats' );

		$this->assertSame( array( 'songs' => 1 ), $result );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_manual_action_never_retries_even_on_5xx(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_untrailingslashit();
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( 'application/json' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( array( 'songs' => 1 ) ) );

		Functions\expect( 'wp_safe_remote_post' )
			->once() // not twice: a manual action never retries.
			->andReturn( array() );

		$result = Ampache_Client::run_in_manual_context(
			static fn() => Ampache_Client::request( 'ping_stats' )
		);

		$this->assertSame( 'jba_http_error', $result->get_error_code() );
	}
}
