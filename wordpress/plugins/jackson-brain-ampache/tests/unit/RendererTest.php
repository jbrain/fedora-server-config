<?php
/**
 * Renderer is the public-safe output layer: fresh/stale must render identically, unavailable
 * is the only state that changes what's shown, and privacy settings are re-checked at render
 * time (not just at normalize time) so a stricter setting takes effect immediately even
 * against already-stored data from before the change - see 02-architecture-api.md and
 * 04-wordpress-experience.md "Rendering behavior".
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Renderer;
use JacksonBrain\Ampache\Tests\TestCase;

final class RendererTest extends TestCase {

	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array(
			'jba_settings' => array(
				'recent_items'              => 5,
				'show_artwork'              => true,
				'show_timestamps'           => true,
				'show_usernames'            => false,
				'show_client_names'         => false,
				'unavailable_mode'          => 'omit',
				'unavailable_fallback_text' => '',
			),
		);

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_html__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_attr' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ) => (string) $number );
		Functions\when( 'wp_date' )->justReturn( '2026-08-01 12:00' );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );

		// No cached artwork exists in any of these tests; get_url() returning null (a true
		// cache miss) is exercised for real, not stubbed away.
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => sys_get_temp_dir() . '/jba-renderer-test-empty' ) );
	}

	private function set_snapshot( array $sections ): void {
		$this->options['jba_snapshot'] = array(
			'schema_version'           => 1,
			'configuration_generation' => 1,
			'written_at'               => time(),
			'sections'                 => array_merge(
				array(
					'stats'       => null,
					'now_playing' => null,
					'recent'      => null,
				),
				$sections
			),
		);
	}

	public function test_stats_renders_totals(): void {
		$this->set_snapshot(
			array(
				'stats' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(
						'songs'      => 100,
						'albums'     => 10,
						'artists'    => 5,
						'genres'     => 3,
						'playlists'  => 2,
						'updated_at' => time(),
					),
				),
			)
		);

		$html = Renderer::render_stats();

		$this->assertStringContainsString( 'jba-stats', $html );
		$this->assertStringContainsString( '100', $html );
	}

	public function test_stats_is_unavailable_and_omitted_by_default(): void {
		$this->set_snapshot( array() ); // no stats section ever stored.

		$this->assertSame( '', Renderer::render_stats() );
	}

	public function test_stats_omits_zero_counts_but_keeps_nonzero_ones(): void {
		// Some Ampache servers report 0 for every count field even on a non-empty library
		// (confirmed live during Gate A); a real 0 and "not reported" are indistinguishable,
		// so zero-valued rows are dropped rather than shown as misleading "0" values.
		$this->set_snapshot(
			array(
				'stats' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(
						'songs'      => 0,
						'albums'     => 10,
						'artists'    => 0,
						'genres'     => 3,
						'playlists'  => 0,
						'updated_at' => time(),
					),
				),
			)
		);

		$html = Renderer::render_stats();

		$this->assertStringNotContainsString( 'Songs', $html );
		$this->assertStringNotContainsString( 'Artists', $html );
		$this->assertStringNotContainsString( 'Playlists', $html );
		$this->assertStringContainsString( 'Albums', $html );
		$this->assertStringContainsString( 'Genres', $html );
	}

	public function test_stats_renders_unavailable_message_when_all_counts_are_zero(): void {
		$this->set_snapshot(
			array(
				'stats' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(
						'songs'      => 0,
						'albums'     => 0,
						'artists'    => 0,
						'genres'     => 0,
						'playlists'  => 0,
						'updated_at' => time(),
					),
				),
			)
		);

		$html = Renderer::render_stats();

		$this->assertStringContainsString( 'jba-empty', $html );
		$this->assertStringContainsString( 'Library statistics are not available yet.', $html );
	}

	public function test_unavailable_renders_the_configured_fallback_text_instead_of_omitting(): void {
		$this->options['jba_settings']['unavailable_mode']          = 'fallback';
		$this->options['jba_settings']['unavailable_fallback_text'] = 'Music info coming soon.';
		$this->set_snapshot( array() );

		$this->assertSame( '<p class="jba-fallback">Music info coming soon.</p>', Renderer::render_stats() );
	}

	public function test_stale_data_renders_identically_to_fresh_data(): void {
		// Stale: refreshed long before its max age, but still a valid prior success.
		$this->set_snapshot(
			array(
				'now_playing' => array(
					'refreshed_at'       => time() - 100000,
					'source_api_version' => '6.9.0',
					'data'               => array(
						array(
							'id'          => '1',
							'title'       => 'Old Song',
							'artist'      => 'Artist',
							'album'       => 'Album',
							'duration'    => 200,
							'expires_at'  => 0,
							'artwork_ref' => null,
							'user_label'  => '',
						),
					),
				),
			)
		);

		$html = Renderer::render_now_playing();

		// No warning/staleness indicator in public output - just the track, same as fresh.
		$this->assertStringContainsString( 'Old Song', $html );
		$this->assertStringNotContainsString( 'stale', $html );
	}

	public function test_now_playing_empty_list_is_a_valid_empty_state_not_unavailable(): void {
		$this->set_snapshot(
			array(
				'now_playing' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(),
				),
			)
		);

		$html = Renderer::render_now_playing();

		$this->assertStringContainsString( 'jba-empty', $html );
	}

	public function test_username_is_hidden_when_the_setting_is_currently_off_even_if_stored(): void {
		// Simulates stale data written before the privacy setting was tightened: the render
		// layer must independently re-check the current setting, not trust what's stored.
		$this->set_snapshot(
			array(
				'now_playing' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(
						array(
							'id'          => '1',
							'title'       => 'Song',
							'artist'      => 'Artist',
							'album'       => 'Album',
							'duration'    => 200,
							'expires_at'  => 0,
							'artwork_ref' => null,
							'user_label'  => 'alice',
						),
					),
				),
			)
		);

		$html = Renderer::render_now_playing();

		$this->assertStringNotContainsString( 'alice', $html );
	}

	public function test_username_appears_when_the_setting_is_currently_on(): void {
		$this->options['jba_settings']['show_usernames'] = true;
		$this->set_snapshot(
			array(
				'now_playing' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => array(
						array(
							'id'          => '1',
							'title'       => 'Song',
							'artist'      => 'Artist',
							'album'       => 'Album',
							'duration'    => 200,
							'expires_at'  => 0,
							'artwork_ref' => null,
							'user_label'  => 'alice',
						),
					),
				),
			)
		);

		$this->assertStringContainsString( 'alice', Renderer::render_now_playing() );
	}

	public function test_recent_limit_argument_can_only_narrow_the_admin_ceiling_not_widen_it(): void {
		$entries = array();

		for ( $i = 1; $i <= 5; $i++ ) {
			$entries[] = array(
				'id'          => (string) $i,
				'title'       => "Track $i",
				'artist'      => 'Artist',
				'album'       => 'Album',
				'played_at'   => time(),
				'artwork_ref' => null,
			);
		}

		$this->set_snapshot(
			array(
				'recent' => array(
					'refreshed_at'       => time(),
					'source_api_version' => '6.9.0',
					'data'               => $entries,
				),
			)
		);

		// Admin ceiling is 5 (recent_items); requesting 25 must not exceed it.
		$html = Renderer::render_recent( array( 'limit' => 25 ) );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertStringContainsString( "Track $i", $html );
		}

		// Requesting fewer than the ceiling narrows correctly.
		$narrowed = Renderer::render_recent( array( 'limit' => 2 ) );

		$this->assertStringContainsString( 'Track 1', $narrowed );
		$this->assertStringContainsString( 'Track 2', $narrowed );
		$this->assertStringNotContainsString( 'Track 3', $narrowed );
	}
}
