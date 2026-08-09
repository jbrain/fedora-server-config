<?php
/**
 * Shortcode validates its attributes and must route to the correct Renderer method, applying
 * the same admin-configured ceiling as the blocks (already verified in RendererTest); here the
 * focus is purely on attribute handling and view routing.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Shortcode;
use JacksonBrain\Ampache\Tests\TestCase;

final class ShortcodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$options = array(
			'jba_settings' => array(
				'recent_items'    => 5,
				'show_artwork'    => false,
				'show_timestamps' => false,
			),
			'jba_snapshot' => array(
				'schema_version'           => 1,
				'configuration_generation' => 1,
				'written_at'               => time(),
				'sections'                 => array(
					'stats'       => array(
						'refreshed_at'       => time(),
						'source_api_version' => '6.9.0',
						'data'               => array(
							'songs'      => 1,
							'albums'     => 1,
							'artists'    => 1,
							'genres'     => 1,
							'playlists'  => 1,
							'updated_at' => time(),
						),
					),
					'now_playing' => array(
						'refreshed_at'       => time(),
						'source_api_version' => '6.9.0',
						'data'               => array(
							array(
								'id'          => '1',
								'title'       => 'Now Playing Marker',
								'artist'      => 'Artist',
								'album'       => 'Album',
								'duration'    => 1,
								'expires_at'  => 0,
								'artwork_ref' => null,
								'user_label'  => '',
							),
						),
					),
					'recent'      => array(
						'refreshed_at'       => time(),
						'source_api_version' => '6.9.0',
						'data'               => array(
							array(
								'id'          => '2',
								'title'       => 'Recent Marker',
								'artist'      => 'Artist',
								'album'       => 'Album',
								'played_at'   => time(),
								'artwork_ref' => null,
							),
						),
					),
				),
			),
		);

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'get_option' )->alias(
			static fn( $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'esc_html__' )->alias( static fn( $text ) => $text );
		Functions\when( 'esc_attr' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ) => (string) $number );
		Functions\when( 'shortcode_atts' )->alias(
			static fn( $defaults, $atts ) => array_merge( $defaults, array_intersect_key( is_array( $atts ) ? $atts : array(), $defaults ) )
		);
	}

	public function test_default_view_is_stats(): void {
		$html = Shortcode::render( array() );

		$this->assertStringContainsString( 'jba-stats', $html );
	}

	public function test_view_now_playing_routes_correctly(): void {
		$html = Shortcode::render( array( 'view' => 'now-playing' ) );

		$this->assertStringContainsString( 'Now Playing Marker', $html );
		$this->assertStringNotContainsString( 'Recent Marker', $html );
	}

	public function test_view_recent_routes_correctly(): void {
		$html = Shortcode::render( array( 'view' => 'recent' ) );

		$this->assertStringContainsString( 'Recent Marker', $html );
		$this->assertStringNotContainsString( 'Now Playing Marker', $html );
	}

	public function test_an_unrecognized_view_falls_back_to_stats(): void {
		$html = Shortcode::render( array( 'view' => 'not-a-real-view' ) );

		$this->assertStringContainsString( 'jba-stats', $html );
	}
}
