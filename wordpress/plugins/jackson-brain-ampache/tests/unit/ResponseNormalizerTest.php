<?php
/**
 * Response_Normalizer converts raw Ampache shapes into the internal snapshot; these tests
 * lock in the two contract points most likely to regress silently: now_playing never embeds
 * song metadata (a missing lookup must be skipped, not published incomplete), and
 * usernames/client names only ever appear when the current privacy setting allows it.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Response_Normalizer;
use JacksonBrain\Ampache\Tests\TestCase;

final class ResponseNormalizerTest extends TestCase {

	private function stub_settings( array $overrides = array() ): void {
		$settings = array_merge(
			array(
				'show_usernames'    => false,
				'show_client_names' => false,
			),
			$overrides
		);

		Functions\when( 'get_option' )->justReturn( $settings );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
	}

	public function test_normalize_stats_allows_partial_counts_but_not_empty(): void {
		$this->stub_settings();

		$this->assertNull( Response_Normalizer::normalize_stats( array() ) );

		// Only 2 of 5 counts succeeded (e.g. the other 3 requests failed/errored) - still
		// returns a result; Renderer's own zero-is-unavailable filtering handles the rest.
		$partial = Response_Normalizer::normalize_stats( array( 'songs' => 10 ) );
		$this->assertSame( 10, $partial['songs'] );
		$this->assertSame( 0, $partial['albums'] );

		$result = Response_Normalizer::normalize_stats(
			array(
				'songs'     => '10',
				'albums'    => '2',
				'artists'   => '3',
				'genres'    => '1',
				'playlists' => '0',
			),
			'2026-08-01T00:00:00+00:00'
		);

		$this->assertSame( 10, $result['songs'] );
		$this->assertGreaterThan( 0, $result['updated_at'] );
	}

	public function test_now_playing_skips_entries_with_a_failed_song_lookup(): void {
		$this->stub_settings();

		$now_playing = array(
			'now_playing' => array(
				array(
					'id'     => '1',
					'type'   => 'song',
					'expire' => 100,
					'user'   => array( 'username' => 'alice' ),
				),
				array(
					'id'     => '2',
					'type'   => 'song',
					'expire' => 100,
					'user'   => array( 'username' => 'bob' ),
				),
			),
		);

		// Song "2" is deliberately missing, simulating a failed lookup.
		$song_lookup = array(
			'1' => array(
				'title'  => 'Song One',
				'artist' => array( 'name' => 'Artist' ),
				'album'  => array( 'name' => 'Album' ),
				'time'   => 200,
			),
		);

		$result = Response_Normalizer::normalize_now_playing( $now_playing, $song_lookup );

		$this->assertCount( 1, $result );
		$this->assertSame( '1', $result[0]['id'] );
		$this->assertSame( '', $result[0]['user_label'] );
	}

	public function test_now_playing_ignores_non_song_types(): void {
		$this->stub_settings();

		$now_playing = array(
			'now_playing' => array(
				array(
					'id'     => '9',
					'type'   => 'video',
					'expire' => 100,
				),
			),
		);

		$result = Response_Normalizer::normalize_now_playing( $now_playing, array( '9' => array( 'title' => 'x' ) ) );

		$this->assertSame( array(), $result );
	}

	public function test_username_and_client_name_are_included_only_when_enabled(): void {
		$this->stub_settings(
			array(
				'show_usernames'    => true,
				'show_client_names' => true,
			)
		);

		$now_playing = array(
			'now_playing' => array(
				array(
					'id'     => '1',
					'type'   => 'song',
					'expire' => 100,
					'client' => 'MyClient',
					'user'   => array( 'username' => 'alice' ),
				),
			),
		);
		$song_lookup = array(
			'1' => array(
				'title'  => 'Song',
				'artist' => array( 'name' => 'A' ),
				'album'  => array( 'name' => 'B' ),
				'time'   => 100,
			),
		);

		$result = Response_Normalizer::normalize_now_playing( $now_playing, $song_lookup );

		$this->assertSame( 'alice', $result[0]['user_label'] );
		$this->assertSame( 'MyClient', $result[0]['client_label'] );
	}

	public function test_username_and_client_name_are_empty_by_default(): void {
		$this->stub_settings();

		$now_playing = array(
			'now_playing' => array(
				array(
					'id'     => '1',
					'type'   => 'song',
					'expire' => 100,
					'client' => 'MyClient',
					'user'   => array( 'username' => 'alice' ),
				),
			),
		);
		$song_lookup = array(
			'1' => array(
				'title'  => 'Song',
				'artist' => array( 'name' => 'A' ),
				'album'  => array( 'name' => 'B' ),
				'time'   => 100,
			),
		);

		$result = Response_Normalizer::normalize_now_playing( $now_playing, $song_lookup );

		$this->assertSame( '', $result[0]['user_label'] );
		$this->assertSame( '', $result[0]['client_label'] );
	}

	public function test_normalize_recent_maps_song_fields(): void {
		$this->stub_settings();

		$stats = array(
			'song' => array(
				array(
					'id'          => '7',
					'title'       => 'Track',
					'artist'      => array( 'name' => 'Artist' ),
					'album'       => array( 'name' => 'Album' ),
					'last_played' => '2026-08-01T12:00:00+00:00',
					'has_art'     => true,
				),
			),
		);

		$result = Response_Normalizer::normalize_recent( $stats );

		$this->assertSame( '7', $result[0]['id'] );
		$this->assertSame(
			array(
				'type' => 'song',
				'id'   => '7',
				'size' => '512x512',
			),
			$result[0]['artwork_ref']
		);
		$this->assertGreaterThan( 0, $result[0]['played_at'] );
	}

	public function test_artwork_ref_is_null_without_has_art(): void {
		$this->stub_settings();

		$stats = array(
			'song' => array(
				array(
					'id'      => '8',
					'title'   => 'No Art',
					'artist'  => array(),
					'album'   => array(),
					'has_art' => false,
				),
			),
		);

		$result = Response_Normalizer::normalize_recent( $stats );

		$this->assertNull( $result[0]['artwork_ref'] );
	}
}
