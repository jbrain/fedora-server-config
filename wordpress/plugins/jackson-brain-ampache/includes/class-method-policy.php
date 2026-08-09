<?php
/**
 * The closed set of Ampache actions this plugin may ever request, with typed parameters.
 * This is the enforceable read-only boundary described in
 * plans/wordpress-ampache-plugin/03-security-privacy.md - not the Ampache account's own level.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Method_Policy {

	public const ACTION_PING        = 'ping';
	public const ACTION_HANDSHAKE   = 'handshake';
	public const ACTION_NOW_PLAYING = 'now_playing';
	public const ACTION_SONG        = 'song';
	public const ACTION_STATS       = 'stats';
	public const ACTION_TIMELINE    = 'timeline';
	public const ACTION_GET_ART     = 'get_art';
	public const ACTION_SONGS       = 'songs';
	public const ACTION_ALBUMS      = 'albums';
	public const ACTION_ARTISTS     = 'artists';
	public const ACTION_GENRES      = 'genres';
	public const ACTION_PLAYLISTS   = 'playlists';

	private const MAX_LIMIT = 25;

	/**
	 * Keyed by internal request "kind" rather than raw Ampache action, because one action
	 * (ping) is reused for two different purposes with different auth requirements.
	 */
	private static function definitions(): array {
		return array(
			'ping_probe'      => array(
				'action'       => self::ACTION_PING,
				'require_auth' => false,
				'params'       => array(),
			),
			'ping_stats'      => array(
				'action'       => self::ACTION_PING,
				'require_auth' => true,
				'params'       => array(),
			),
			'now_playing'     => array(
				'action'       => self::ACTION_NOW_PLAYING,
				'require_auth' => true,
				'params'       => array(),
			),
			'song'            => array(
				'action'       => self::ACTION_SONG,
				'require_auth' => true,
				'params'       => array(
					'filter' => array( 'type' => 'id' ),
				),
			),
			'stats_recent'    => array(
				'action'       => self::ACTION_STATS,
				'require_auth' => true,
				'params'       => array(
					'type'   => array(
						'type'  => 'fixed',
						'value' => 'song',
					),
					'filter' => array(
						'type'  => 'fixed',
						'value' => 'recent',
					),
					'limit'  => array( 'type' => 'limit' ),
				),
			),
			// Library-wide totals for the stats view. Ampache's ping/handshake summary
			// fields (songs/albums/artists/genres/playlists) are a confirmed server-side bug
			// on this install (always 0 - see plans/wordpress-ampache-plugin/gate-a-report.md
			// "Library statistics") - these list actions' own `total_count` envelope field is
			// the real, correct count instead. `limit=1` keeps the actual item payload (which
			// is discarded, only total_count is read) minimal.
			'count_songs'     => array(
				'action'       => self::ACTION_SONGS,
				'require_auth' => true,
				'params'       => array(
					'limit' => array(
						'type'  => 'fixed',
						'value' => 1,
					),
				),
			),
			'count_albums'    => array(
				'action'       => self::ACTION_ALBUMS,
				'require_auth' => true,
				'params'       => array(
					'limit' => array(
						'type'  => 'fixed',
						'value' => 1,
					),
				),
			),
			'count_artists'   => array(
				'action'       => self::ACTION_ARTISTS,
				'require_auth' => true,
				'params'       => array(
					'limit' => array(
						'type'  => 'fixed',
						'value' => 1,
					),
				),
			),
			'count_genres'    => array(
				'action'       => self::ACTION_GENRES,
				'require_auth' => true,
				'params'       => array(
					'limit' => array(
						'type'  => 'fixed',
						'value' => 1,
					),
				),
			),
			'count_playlists' => array(
				'action'       => self::ACTION_PLAYLISTS,
				'require_auth' => true,
				'params'       => array(
					'limit' => array(
						'type'  => 'fixed',
						'value' => 1,
					),
				),
			),
			'timeline'        => array(
				'action'       => self::ACTION_TIMELINE,
				'require_auth' => true,
				'params'       => array(
					'filter' => array( 'type' => 'username' ),
					'limit'  => array( 'type' => 'limit' ),
				),
			),
			'get_art'         => array(
				'action'       => self::ACTION_GET_ART,
				'require_auth' => true,
				'params'       => array(
					'filter' => array( 'type' => 'id' ),
					'type'   => array(
						'type'    => 'enum',
						'allowed' => array( 'song' ),
					),
					'size'   => array(
						'type'  => 'fixed',
						'value' => '512x512',
					),
				),
			),
			// Gate A compatibility probe only (see 02-architecture-api.md Authentication
			// lifecycle). Not wired into Ampache_Client's runtime request methods; the
			// returned session token must be discarded immediately by whatever Gate A
			// tooling uses this definition directly.
			'handshake_probe' => array(
				'action'       => self::ACTION_HANDSHAKE,
				'require_auth' => true,
				'params'       => array(),
			),
		);
	}

	public static function get( string $kind ): ?array {
		$definitions = self::definitions();

		return $definitions[ $kind ] ?? null;
	}

	public static function is_allowed( string $kind ): bool {
		return null !== self::get( $kind );
	}

	/**
	 * Builds the exact request body from typed internal values only; an unknown kind or a
	 * value that fails its type's check is rejected before any HTTP request is constructed.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function validate_params( string $kind, array $input ) {
		$definition = self::get( $kind );

		if ( null === $definition ) {
			return new \WP_Error( 'jba_unknown_request', 'Unknown or disallowed Ampache request.' );
		}

		$output = array();

		foreach ( $definition['params'] as $name => $spec ) {
			$result = self::validate_one( $name, $spec, $input );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$output[ $name ] = $result;
		}

		return $output;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function validate_one( string $name, array $spec, array $input ) {
		switch ( $spec['type'] ) {
			case 'fixed':
				return (string) $spec['value'];

			case 'id':
				$value = isset( $input[ $name ] ) ? (int) $input[ $name ] : 0;

				return $value > 0 ? (string) $value : self::invalid_param_error( $name );

			case 'username':
				$value = isset( $input[ $name ] ) ? sanitize_user( (string) $input[ $name ], true ) : '';

				return '' !== $value ? $value : self::invalid_param_error( $name );

			case 'limit':
				$value = isset( $input[ $name ] ) ? (int) $input[ $name ] : 0;

				return $value > 0 ? (string) min( $value, self::MAX_LIMIT ) : self::invalid_param_error( $name );

			case 'enum':
				$value = isset( $input[ $name ] ) ? (string) $input[ $name ] : '';

				return in_array( $value, $spec['allowed'], true ) ? $value : self::invalid_param_error( $name );

			default:
				return self::invalid_param_error( $name );
		}
	}

	private static function invalid_param_error( string $name ): \WP_Error {
		return new \WP_Error(
			'jba_invalid_param',
			/* translators: %s: parameter name */
			sprintf( __( 'Missing or invalid "%s" parameter.', 'jackson-brain-ampache' ), $name )
		);
	}
}
