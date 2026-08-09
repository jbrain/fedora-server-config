<?php
/**
 * Snapshot_Repository owns the durable last-known-good snapshot and its atomic owner-token
 * lock (see class docblock for why the lock lives here, not in Refresh_Service). The lock
 * tests are the highest-value ones: they lock in "an expired worker must never delete its
 * successor's lock" and "never steal a still-valid lock", both from 02-architecture-api.md.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Snapshot_Repository;
use JacksonBrain\Ampache\Tests\TestCase;

final class SnapshotRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => serialize( $value ) );
		Functions\when( 'maybe_unserialize' )->alias(
			static fn( $value ) => is_string( $value ) ? @unserialize( $value ) : $value
		);
	}

	private function stub_get_option( array $values ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => array_key_exists( $name, $values ) ? $values[ $name ] : $default
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
	}

	public function test_get_snapshot_defaults_to_empty_structure_when_nothing_stored(): void {
		$this->stub_get_option( array( 'jba_settings' => array( 'configuration_generation' => 3 ) ) );

		$snapshot = Snapshot_Repository::get_snapshot();

		$this->assertSame( 1, $snapshot['schema_version'] );
		$this->assertNull( $snapshot['sections']['stats'] );
		$this->assertNull( $snapshot['sections']['now_playing'] );
		$this->assertNull( $snapshot['sections']['recent'] );
	}

	public function test_get_snapshot_resets_on_an_unrecognized_schema_version(): void {
		$this->stub_get_option(
			array(
				'jba_settings' => array( 'configuration_generation' => 1 ),
				'jba_snapshot' => array(
					'schema_version' => 99,
					'sections'       => array( 'stats' => array( 'refreshed_at' => 123 ) ),
				),
			)
		);

		$snapshot = Snapshot_Repository::get_snapshot();

		$this->assertSame( 1, $snapshot['schema_version'] );
		$this->assertNull( $snapshot['sections']['stats'] );
	}

	public function test_write_section_preserves_the_other_sections(): void {
		$this->stub_get_option(
			array(
				'jba_settings' => array( 'configuration_generation' => 1 ),
				'jba_snapshot' => array(
					'schema_version'           => 1,
					'configuration_generation' => 1,
					'written_at'               => 100,
					'sections'                 => array(
						'stats'       => array(
							'refreshed_at'       => 100,
							'source_api_version' => '6.9.0',
							'data'               => array( 'songs' => 5 ),
						),
						'now_playing' => null,
						'recent'      => null,
					),
				),
			)
		);

		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$captured ) {
				$captured = array( $name, $value );

				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Snapshot_Repository::write_section( 'now_playing', array( 'id' => '1' ), '6.9.0' );

		$this->assertSame( 'jba_snapshot', $captured[0] );
		$this->assertSame( array( 'songs' => 5 ), $captured[1]['sections']['stats']['data'] );
		$this->assertSame( array( 'id' => '1' ), $captured[1]['sections']['now_playing']['data'] );
	}

	// ---- Lock ----

	public function test_acquire_lock_succeeds_immediately_when_uncontested(): void {
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-token' );

		$this->assertSame( 'fake-token', Snapshot_Repository::acquire_lock() );
	}

	public function test_acquire_lock_refuses_to_steal_a_still_valid_lock(): void {
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-token' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'someone-else',
				'expires' => time() + 100, // still valid.
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertNull( Snapshot_Repository::acquire_lock() );
		$this->assertCount( 1, $wpdb->queries ); // only the SELECT; no steal attempted.
	}

	public function test_acquire_lock_steals_an_expired_lock(): void {
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-token' );
		Functions\expect( 'wp_cache_delete' )->once()->with( 'jba_refresh_lock', 'options' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'someone-else',
				'expires' => time() - 10, // expired.
			)
		);
		$wpdb->query_return = 1; // the conditional UPDATE affected a row: we won the steal.
		$GLOBALS['wpdb']     = $wpdb;

		$this->assertSame( 'fake-token', Snapshot_Repository::acquire_lock() );
	}

	public function test_acquire_lock_loses_the_race_to_steal_an_expired_lock(): void {
		Functions\when( 'add_option' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'fake-token' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'someone-else',
				'expires' => time() - 10,
			)
		);
		$wpdb->query_return = 0; // another worker's UPDATE already changed the value.
		$GLOBALS['wpdb']     = $wpdb;

		$this->assertNull( Snapshot_Repository::acquire_lock() );
	}

	public function test_release_lock_deletes_when_the_token_matches(): void {
		Functions\expect( 'wp_cache_delete' )->once()->with( 'jba_refresh_lock', 'options' );

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'my-token',
				'expires' => time() + 100,
			)
		);
		$wpdb->query_return = 1;
		$GLOBALS['wpdb']     = $wpdb;

		Snapshot_Repository::release_lock( 'my-token' );

		$this->assertCount( 2, $wpdb->queries ); // SELECT then DELETE.
	}

	/**
	 * The core safety guarantee: a worker whose lock already expired and was taken by a
	 * successor must never delete that successor's lock.
	 */
	public function test_release_lock_does_nothing_when_the_token_no_longer_matches(): void {
		Functions\expect( 'wp_cache_delete' )->never();

		$wpdb = new FakeWpdb();
		$wpdb->get_var_return = serialize(
			array(
				'owner'   => 'a-different-worker',
				'expires' => time() + 100,
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		Snapshot_Repository::release_lock( 'my-token' );

		$this->assertCount( 1, $wpdb->queries ); // only the SELECT; no DELETE attempted.
	}
}
