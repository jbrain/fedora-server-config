<?php
/**
 * Converts raw Ampache JSON response shapes into the versioned internal snapshot data.
 * Only the fields listed in plans/wordpress-ampache-plugin/02-architecture-api.md's snapshot
 * model are ever read; everything else (stream URLs, filenames, mbid, catalog paths, etc.)
 * is discarded by simply never being referenced here.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Response_Normalizer {

	/**
	 * $counts holds whichever of songs/albums/artists/genres/playlists Refresh_Service
	 * successfully fetched (each is its own independent Ampache request - see
	 * Refresh_Service::fetch_stats() for why a single ping-based summary is not used
	 * here); a missing key defaults to 0 and is then omitted by Renderer's own
	 * zero-is-unavailable filtering, so partial success still renders whatever counts
	 * are actually available instead of hiding the whole section.
	 *
	 * @return array{songs:int,albums:int,artists:int,genres:int,playlists:int,updated_at:int}|null
	 */
	public static function normalize_stats( array $counts, string $updated_raw = '' ): ?array {
		if ( empty( $counts ) ) {
			return null;
		}

		return array(
			'songs'      => (int) ( $counts['songs'] ?? 0 ),
			'albums'     => (int) ( $counts['albums'] ?? 0 ),
			'artists'    => (int) ( $counts['artists'] ?? 0 ),
			'genres'     => (int) ( $counts['genres'] ?? 0 ),
			'playlists'  => (int) ( $counts['playlists'] ?? 0 ),
			'updated_at' => self::to_unix_timestamp( $updated_raw ),
		);
	}

	/**
	 * $song_lookup maps song id => that song's raw `song` response, resolved by
	 * Refresh_Service; now_playing entries never embed song metadata themselves.
	 *
	 * @param array<string,array> $song_lookup
	 * @return array<int,array>
	 */
	public static function normalize_now_playing( array $now_playing_response, array $song_lookup ): array {
		$entries = is_array( $now_playing_response['now_playing'] ?? null ) ? $now_playing_response['now_playing'] : array();
		$normalized = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || 'song' !== ( $entry['type'] ?? '' ) ) {
				continue; // MVP scope is song playback only; video/podcast now-playing is out of scope.
			}

			$song_id = (string) ( $entry['id'] ?? '' );
			$song    = $song_lookup[ $song_id ] ?? null;

			if ( null === $song ) {
				continue; // song lookup failed or missing; skip rather than publish incomplete data.
			}

			$normalized[] = array(
				'id'           => $song_id,
				'title'        => self::sanitize_text( $song['title'] ?? '' ),
				'artist'       => self::sanitize_text( $song['artist']['name'] ?? '' ),
				'album'        => self::sanitize_text( $song['album']['name'] ?? '' ),
				'duration'     => (int) ( $song['time'] ?? 0 ),
				'expires_at'   => (int) ( $entry['expire'] ?? 0 ),
				'artwork_ref'  => self::artwork_ref( $song ),
				// Stored conditionally on current policy so a future refresh naturally
				// stops persisting it if the setting tightens; Renderer independently
				// re-checks the setting too, so a stricter change takes effect immediately
				// without waiting for the next refresh.
				'user_label'   => Settings::show_usernames() ? self::sanitize_text( $entry['user']['username'] ?? '' ) : '',
				'client_label' => Settings::show_client_names() ? self::sanitize_text( $entry['client'] ?? '' ) : '',
			);
		}

		return $normalized;
	}

	/**
	 * @return array<int,array>
	 */
	public static function normalize_recent( array $stats_response ): array {
		$songs      = is_array( $stats_response['song'] ?? null ) ? $stats_response['song'] : array();
		$normalized = array();

		foreach ( $songs as $song ) {
			if ( ! is_array( $song ) ) {
				continue;
			}

			$normalized[] = array(
				'id'          => (string) ( $song['id'] ?? '' ),
				'title'       => self::sanitize_text( $song['title'] ?? '' ),
				'artist'      => self::sanitize_text( $song['artist']['name'] ?? '' ),
				'album'       => self::sanitize_text( $song['album']['name'] ?? '' ),
				'played_at'   => self::to_unix_timestamp( $song['last_played'] ?? null ),
				'artwork_ref' => self::artwork_ref( $song ),
			);
		}

		return $normalized;
	}

	private static function artwork_ref( array $song ): ?array {
		if ( empty( $song['has_art'] ) || empty( $song['id'] ) ) {
			return null;
		}

		return array(
			'type' => 'song',
			'id'   => (string) $song['id'],
			'size' => '512x512',
		);
	}

	private static function sanitize_text( $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	private static function to_unix_timestamp( $value ): int {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return 0;
		}

		$timestamp = strtotime( $value );

		return false !== $timestamp ? $timestamp : 0;
	}
}
