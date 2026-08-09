<?php
/**
 * Coordinates endpoint calls, partial-success snapshot writes, and the refresh lock.
 * A manual refresh calls the exact same methods as cron; only Ampache_Client's context
 * guard differs by how the caller invoked it (see class-scheduler.php / the future admin
 * action handler, which must wrap a manual call in Ampache_Client::run_in_manual_context()).
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Refresh_Service {

	private const SECTION_NOW_PLAYING = 'now_playing';
	private const SECTION_STATS       = 'stats';
	private const SECTION_RECENT      = 'recent';

	public static function refresh_now_playing(): bool {
		return self::run( array( self::SECTION_NOW_PLAYING ) );
	}

	public static function refresh_stats_and_recent(): bool {
		return self::run( array( self::SECTION_STATS, self::SECTION_RECENT ) );
	}

	public static function refresh_all(): bool {
		return self::run( array( self::SECTION_NOW_PLAYING, self::SECTION_STATS, self::SECTION_RECENT ) );
	}

	/**
	 * Returns false (not an error) when another refresh already holds the lock.
	 */
	private static function run( array $sections ): bool {
		$token = Snapshot_Repository::acquire_lock();

		if ( null === $token ) {
			return false;
		}

		try {
			foreach ( $sections as $section ) {
				self::refresh_section( $section );
			}
		} finally {
			Snapshot_Repository::release_lock( $token );
		}

		return true;
	}

	private static function refresh_section( string $section ): void {
		$start = microtime( true );

		$result = self::fetch( $section );

		$duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		$previous    = Snapshot_Repository::get_status()[ $section ] ?? array();

		if ( is_wp_error( $result ) ) {
			// One failed endpoint must not erase a healthy prior section.
			Snapshot_Repository::record_status(
				$section,
				array(
					'last_attempt'         => time(),
					'duration_ms'          => $duration_ms,
					'consecutive_failures' => (int) ( $previous['consecutive_failures'] ?? 0 ) + 1,
					'error_code'           => (int) ( $result->get_error_data()['error_code'] ?? 0 ),
				)
			);

			return;
		}

		Snapshot_Repository::write_section( $section, $result, Ampache_Client::requested_api_version() );
		Snapshot_Repository::record_status(
			$section,
			array(
				'last_attempt'         => time(),
				'last_success'         => time(),
				'source_api_version'   => Ampache_Client::requested_api_version(),
				'duration_ms'          => $duration_ms,
				'consecutive_failures' => 0,
				'error_code'           => 0,
			)
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function fetch( string $section ) {
		switch ( $section ) {
			case self::SECTION_NOW_PLAYING:
				return self::fetch_now_playing();

			case self::SECTION_STATS:
				return self::fetch_stats();

			case self::SECTION_RECENT:
				return self::fetch_recent();

			default:
				return new \WP_Error( 'jba_unknown_section', 'Unknown refresh section.' );
		}
	}

	/**
	 * Ampache's ping/handshake summary (songs/albums/artists/genres/playlists) is a
	 * confirmed server-side bug on this install - always reports 0 regardless of the real
	 * library size (see plans/wordpress-ampache-plugin/gate-a-report.md "Library
	 * statistics"). Each count is instead fetched from its own list action's `total_count`
	 * envelope field (confirmed correct against a direct DB count), independently - one
	 * action failing (observed: `songs` 500s on this server, likely due to its ~48k rows)
	 * must not blank out the other four, so failures are simply omitted rather than
	 * aborting the whole section.
	 *
	 * @return array|\WP_Error
	 */
	private static function fetch_stats() {
		$counts = array();

		foreach (
			array(
				'songs'     => 'count_songs',
				'albums'    => 'count_albums',
				'artists'   => 'count_artists',
				'genres'    => 'count_genres',
				'playlists' => 'count_playlists',
			) as $key => $kind
		) {
			$response = Ampache_Client::request( $kind );

			if ( ! is_wp_error( $response ) && isset( $response['total_count'] ) ) {
				$counts[ $key ] = (int) $response['total_count'];
			}
		}

		$data = Response_Normalizer::normalize_stats( $counts, self::fetch_catalog_updated_raw() );

		return null !== $data
			? $data
			: new \WP_Error( 'jba_invalid_response', 'None of the Ampache library count requests succeeded.' );
	}

	/**
	 * `ping`'s own `update` field is unix-epoch-zero on this install (same underlying bug
	 * as the summary counts), but its `add` field (last catalog addition time) has been
	 * confirmed to report real, correct values - close enough to "library last updated"
	 * for display purposes, and this call is otherwise unused/cheap.
	 */
	private static function fetch_catalog_updated_raw(): string {
		$response = Ampache_Client::request( 'ping_stats' );

		if ( is_wp_error( $response ) || empty( $response['add'] ) ) {
			return '';
		}

		return (string) $response['add'];
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function fetch_recent() {
		$response = Ampache_Client::request(
			'stats_recent',
			array( 'limit' => Settings::get_recent_items_limit() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = Response_Normalizer::normalize_recent( $response );
		self::warm_artwork( $data );

		return $data;
	}

	/**
	 * @return array|\WP_Error
	 */
	private static function fetch_now_playing() {
		$response = Ampache_Client::request( 'now_playing' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$song_lookup = self::resolve_song_lookup( $response );
		$data        = Response_Normalizer::normalize_now_playing( $response, $song_lookup );
		self::warm_artwork( $data );

		return $data;
	}

	/**
	 * Fetches and stores any artwork not already cached locally; a fetch/store failure is
	 * skipped silently and simply tried again on the next refresh, never blocking the rest
	 * of this one.
	 */
	private static function warm_artwork( array $entries ): void {
		foreach ( $entries as $entry ) {
			$ref = $entry['artwork_ref'] ?? null;

			if ( ! is_array( $ref ) || null !== Artwork_Cache::get_url( $ref['type'], $ref['id'], $ref['size'] ) ) {
				continue;
			}

			$art = Ampache_Client::request_art(
				array(
					'filter' => $ref['id'],
					'type'   => $ref['type'],
				)
			);

			if ( ! is_wp_error( $art ) ) {
				Artwork_Cache::store( $ref['type'], $ref['id'], $ref['size'], $art['body'] );
			}
		}
	}

	/**
	 * Resolves each unique now-playing song id to its full `song` object. A song lookup
	 * that fails is simply omitted from the map; Response_Normalizer then skips that entry
	 * rather than publishing incomplete data.
	 *
	 * @return array<string,array>
	 */
	private static function resolve_song_lookup( array $now_playing_response ): array {
		$entries = is_array( $now_playing_response['now_playing'] ?? null ) ? $now_playing_response['now_playing'] : array();
		$song_ids = array();

		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && 'song' === ( $entry['type'] ?? '' ) && ! empty( $entry['id'] ) ) {
				$song_ids[ (string) $entry['id'] ] = true;
			}
		}

		$song_lookup = array();

		foreach ( array_keys( $song_ids ) as $song_id ) {
			$song_response = Ampache_Client::request( 'song', array( 'filter' => $song_id ) );

			if ( ! is_wp_error( $song_response ) ) {
				$song_lookup[ $song_id ] = $song_response;
			}
		}

		return $song_lookup;
	}
}
