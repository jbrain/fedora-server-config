<?php
/**
 * Durable last-known-good snapshot storage, its atomic owner-token refresh lock, sanitized
 * status, and retention. The lock guards this class's own snapshot option, so it lives here
 * rather than in Refresh_Service (see 05-implementation-roadmap.md responsibilities).
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Snapshot_Repository {

	private const OPTION_SNAPSHOT = 'jba_snapshot';
	private const OPTION_STATUS   = 'jba_status';
	private const OPTION_LOCK     = 'jba_refresh_lock';
	private const CACHE_GROUP     = 'jackson_brain_ampache';
	private const SCHEMA_VERSION  = 1;
	private const LOCK_TTL        = 120;
	private const SNAPSHOT_MAX_AGE_SECONDS = 30 * DAY_IN_SECONDS;

	// ---- Reading ----

	public static function get_snapshot(): array {
		$cached = wp_cache_get( self::OPTION_SNAPSHOT, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$snapshot = get_option( self::OPTION_SNAPSHOT, self::empty_snapshot() );

		if ( ! self::is_valid_schema( $snapshot ) ) {
			// An unknown/incompatible schema is unreadable, not partially guessed.
			$snapshot = self::empty_snapshot();
		}

		wp_cache_set( self::OPTION_SNAPSHOT, $snapshot, self::CACHE_GROUP );

		return $snapshot;
	}

	public static function get_section( string $section ): ?array {
		return self::get_snapshot()['sections'][ $section ] ?? null;
	}

	public static function section_state( string $section ): string {
		$entry = self::get_section( $section );

		if ( null === $entry ) {
			return 'unavailable';
		}

		$age_group = 'now_playing' === $section ? 'now_playing' : 'stats';
		$max_age   = Settings::get_max_age_seconds( $age_group );
		$age       = time() - (int) $entry['refreshed_at'];

		return $age <= $max_age ? 'fresh' : 'stale';
	}

	private static function empty_snapshot(): array {
		return array(
			'schema_version'           => self::SCHEMA_VERSION,
			'configuration_generation' => Settings::get_configuration_generation(),
			'written_at'               => 0,
			'sections'                 => array(
				'stats'       => null,
				'now_playing' => null,
				'recent'      => null,
			),
		);
	}

	private static function is_valid_schema( $snapshot ): bool {
		return is_array( $snapshot )
			&& isset( $snapshot['schema_version'], $snapshot['sections'] )
			&& self::SCHEMA_VERSION === (int) $snapshot['schema_version']
			&& is_array( $snapshot['sections'] );
	}

	// ---- Writing ----

	/**
	 * Replaces only the given section; every other section (including one this refresh
	 * attempt failed to fetch) is preserved untouched.
	 */
	public static function write_section( string $section, array $data, string $source_api_version ): void {
		$snapshot = self::get_snapshot();

		$snapshot['sections'][ $section ]     = array(
			'refreshed_at'       => time(),
			'source_api_version' => $source_api_version,
			'data'               => $data,
		);
		$snapshot['written_at']               = time();
		$snapshot['configuration_generation'] = Settings::get_configuration_generation();

		update_option( self::OPTION_SNAPSHOT, $snapshot, false );
		wp_cache_delete( self::OPTION_SNAPSHOT, self::CACHE_GROUP );
	}

	public static function invalidate(): void {
		delete_option( self::OPTION_SNAPSHOT );
		wp_cache_delete( self::OPTION_SNAPSHOT, self::CACHE_GROUP );
	}

	// ---- Retention ----

	public static function run_daily_cleanup(): void {
		$snapshot = self::get_snapshot();
		$newest   = 0;

		foreach ( $snapshot['sections'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['refreshed_at'] ) ) {
				$newest = max( $newest, (int) $entry['refreshed_at'] );
			}
		}

		if ( $newest > 0 && ( time() - $newest ) > self::SNAPSHOT_MAX_AGE_SECONDS ) {
			self::invalidate();
		}
	}

	// ---- Sanitized, bounded status (latest result per section only) ----

	public static function record_status( string $section, array $status ): void {
		$current = get_option( self::OPTION_STATUS, array() );
		$previous = $current[ $section ] ?? array();

		$current[ $section ] = array(
			'last_attempt'         => (int) ( $status['last_attempt'] ?? time() ),
			'last_success'         => isset( $status['last_success'] ) ? (int) $status['last_success'] : (int) ( $previous['last_success'] ?? 0 ),
			'source_api_version'   => (string) ( $status['source_api_version'] ?? '' ),
			'duration_ms'          => (int) ( $status['duration_ms'] ?? 0 ),
			'consecutive_failures' => (int) ( $status['consecutive_failures'] ?? 0 ),
			'error_code'           => (int) ( $status['error_code'] ?? 0 ),
		);

		update_option( self::OPTION_STATUS, $current, false );
	}

	public static function get_status(): array {
		return get_option( self::OPTION_STATUS, array() );
	}

	// ---- Atomic owner-token refresh lock ----

	/**
	 * Returns the caller's owner token on success, or null if another worker holds a
	 * still-valid lock. Uses add_option()'s own atomicity for the uncontested case, and a
	 * prepared owner-and-value-conditional UPDATE (never a plain read-then-write) to steal
	 * an expired lock without racing another expired worker for the same steal.
	 */
	public static function acquire_lock(): ?string {
		$token = wp_generate_password( 32, false, false );
		$entry = array(
			'owner'   => $token,
			'expires' => time() + self::LOCK_TTL,
		);

		if ( add_option( self::OPTION_LOCK, $entry, '', false ) ) {
			return $token;
		}

		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
		);
		$existing = maybe_unserialize( $raw );

		if ( ! is_array( $existing ) || ! isset( $existing['expires'] ) || (int) $existing['expires'] > time() ) {
			return null;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $entry ),
				self::OPTION_LOCK,
				$raw
			)
		);

		if ( $affected > 0 ) {
			wp_cache_delete( self::OPTION_LOCK, 'options' );

			return $token;
		}

		return null; // another worker won the race to steal the expired lock first.
	}

	/**
	 * An expired worker must never delete its successor's lock: the exact-value WHERE
	 * clause only matches while the row still holds this token's own serialized entry.
	 */
	public static function release_lock( string $token ): void {
		global $wpdb;

		$raw     = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
		);
		$current = maybe_unserialize( $raw );

		if ( ! is_array( $current ) || ( $current['owner'] ?? '' ) !== $token ) {
			return;
		}

		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_LOCK,
				$raw
			)
		);

		if ( $affected > 0 ) {
			wp_cache_delete( self::OPTION_LOCK, 'options' );
		}
	}
}
