<?php
/**
 * Refresh_Service is the orchestration layer: lock + per-section fetch/normalize/write, where
 * a failed section must never erase a healthy prior one. Driven end-to-end through WP function
 * stubs (a stateful fake options table plus an action-dispatching HTTP stub) rather than mocking
 * Ampache_Client/Snapshot_Repository's static methods directly, since PHP static calls aren't
 * reliably mockable without a DI refactor this codebase doesn't use.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Refresh_Service;
use JacksonBrain\Ampache\Tests\TestCase;

final class RefreshServiceTest extends TestCase {

	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array(
			'jba_settings' => array(
				'recent_items'             => 5,
				'configuration_generation' => 1,
			),
		);

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->alias( static fn( $v ) => rtrim( (string) $v, '/' ) );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => serialize( $value ) );
		Functions\when( 'maybe_unserialize' )->alias(
			static fn( $value ) => is_string( $value ) ? @unserialize( $value ) : $value
		);
		Functions\when( 'wp_rand' )->justReturn( 1 );
		Functions\when( 'usleep' )->justReturn( null );

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * Uncontested acquire: add_option succeeds like a real never-locked option row would.
	 * release_lock() has no such fast path - it always queries $wpdb to verify ownership -
	 * so a FakeWpdb reflecting our own token as the current owner is needed for the release
	 * at the end of Refresh_Service::run() to succeed too.
	 */
	private function stub_uncontested_lock(): void {
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'test-token',
				'expires' => time() + 120,
			)
		);
		$wpdb->query_return = 1;
		$GLOBALS['wpdb']     = $wpdb;
	}

	private function fake_response( int $status, array $body ): array {
		return array(
			'__status'       => $status,
			'__content_type' => 'application/json',
			'__body'         => json_encode( $body ),
		);
	}

	private function stub_http_dispatch( callable $router ): void {
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( $url, $args ) use ( $router ) {
				return $router( $args['body']['action'] ?? '', $args['body'] );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $r ) => is_array( $r ) ? ( $r['__status'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $r ) => is_array( $r ) ? ( $r['__content_type'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $r ) => is_array( $r ) ? ( $r['__body'] ?? '' ) : ''
		);
	}

	public function test_refresh_returns_false_when_the_lock_is_already_held(): void {
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-token' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'someone-else',
				'expires' => time() + 100,
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertFalse( Refresh_Service::refresh_now_playing() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_now_playing_with_no_active_listeners_writes_an_empty_section(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		$this->stub_uncontested_lock();
		$this->stub_http_dispatch(
			fn( $action ) => 'now_playing' === $action
				? $this->fake_response( 200, array( 'now_playing' => array() ) )
				: $this->fake_response( 404, array( 'error' => array( 'errorCode' => '4704' ) ) )
		);

		$this->assertTrue( Refresh_Service::refresh_now_playing() );

		$section = $this->options['jba_snapshot']['sections']['now_playing'];
		$this->assertSame( array(), $section['data'] );

		$status = $this->options['jba_status']['now_playing'];
		$this->assertSame( 0, $status['consecutive_failures'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_section_does_not_erase_a_healthy_prior_section(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );

		// Pre-existing healthy 'stats' section that a failed refresh must not touch.
		$this->options['jba_snapshot'] = array(
			'schema_version'           => 1,
			'configuration_generation' => 1,
			'written_at'               => 100,
			'sections'                 => array(
				'stats'       => array(
					'refreshed_at'       => 100,
					'source_api_version' => '6.9.0',
					'data'               => array(
						'songs'      => 1,
						'albums'     => 1,
						'artists'    => 1,
						'genres'     => 1,
						'playlists'  => 1,
						'updated_at' => 100,
					),
				),
				'now_playing' => null,
				'recent'      => null,
			),
		);

		$this->stub_uncontested_lock();
		$this->stub_http_dispatch(
			function ( $action ) {
				if ( 'ping' === $action ) {
					return new \WP_Error( 'jba_transport_error', 'boom' ); // stats fetch fails.
				}

				if ( 'stats' === $action ) {
					return $this->fake_response( 200, array( 'song' => array() ) ); // recent succeeds.
				}

				return $this->fake_response( 404, array( 'error' => array( 'errorCode' => '4704' ) ) );
			}
		);

		$this->assertTrue( Refresh_Service::refresh_stats_and_recent() );

		$sections = $this->options['jba_snapshot']['sections'];
		// The pre-existing healthy 'stats' section survives the failed refresh untouched.
		$this->assertSame( 100, $sections['stats']['refreshed_at'] );
		$this->assertSame( 1, $sections['stats']['data']['songs'] );
		// 'recent' succeeded and is now populated.
		$this->assertSame( array(), $sections['recent']['data'] );

		$status = $this->options['jba_status'];
		$this->assertSame( 1, $status['stats']['consecutive_failures'] );
		$this->assertSame( 0, $status['recent']['consecutive_failures'] );
	}
}
