<?php
/**
 * Artwork_Cache validates real bytes (magic-byte MIME sniff + decoded dimensions) and writes
 * to a real temp directory per test, rather than mocking the filesystem - this is exactly the
 * kind of logic where a mock would just re-assert the implementation instead of catching bugs.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Artwork_Cache;
use JacksonBrain\Ampache\Tests\TestCase;

final class ArtworkCacheTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempDir = sys_get_temp_dir() . '/jba-artwork-test-' . uniqid();
		mkdir( $this->tempDir, 0777, true );

		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->tempDir ) );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( $value ) => rtrim( (string) $value, '/\\' ) . '/'
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'tmp-suffix' );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.test/wp-json/' . $path );
	}

	protected function tearDown(): void {
		$this->remove_recursively( $this->tempDir );
		parent::tearDown();
	}

	private function remove_recursively( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: array() as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->remove_recursively( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	/**
	 * Builds a structurally minimal PNG: PHP's getimagesizefromstring() reads width/height
	 * directly from the fixed-offset IHDR chunk without needing a real CRC or pixel data.
	 */
	private function fake_png( int $width, int $height ): string {
		$signature = "\x89PNG\r\n\x1a\n";
		$ihdr_data = pack( 'N', $width ) . pack( 'N', $height ) . pack( 'C', 8 ) . pack( 'C', 2 ) . pack( 'C', 0 ) . pack( 'C', 0 ) . pack( 'C', 0 );

		return $signature . pack( 'N', 13 ) . 'IHDR' . $ihdr_data . pack( 'N', 0 );
	}

	/**
	 * wp_get_image_editor()/WP_Image_Editor are WP-core, unavailable in this sandbox; this is
	 * the one deliberate exception to "use real bytes, not mocks" since there's no real
	 * implementation to fall back to outside a full WP bootstrap. The fake always succeeds and
	 * writes a real, already-in-bounds PNG so the rest of the store()/get_url() round trip is
	 * still exercised for real.
	 */
	private function stub_working_image_editor(): void {
		$fake_png = $this->fake_png( 512, 512 );

		Functions\when( 'wp_get_image_editor' )->alias(
			function ( $path ) use ( $fake_png ) {
				return new class( $path, $fake_png ) {
					private string $path;
					private string $resized_bytes;

					public function __construct( string $path, string $resized_bytes ) {
						$this->path          = $path;
						$this->resized_bytes = $resized_bytes;
					}

					public function resize( $max_w, $max_h, $crop ) {
						return true;
					}

					public function save( $destination, $mime_type = null ) {
						file_put_contents( $destination, $this->resized_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

						return array( 'path' => $destination );
					}
				};
			}
		);
	}

	public function test_store_rejects_bytes_over_the_fetch_size_limit(): void {
		$this->assertFalse( Artwork_Cache::store( 'song', '1', '512x512', str_repeat( 'x', 8388609 ) ) );
	}

	public function test_store_rejects_bytes_that_do_not_match_an_allowed_magic_header(): void {
		$this->assertFalse( Artwork_Cache::store( 'song', '1', '512x512', 'not a real image' ) );
	}

	public function test_store_rejects_images_over_the_decompression_bomb_guard(): void {
		$this->assertFalse( Artwork_Cache::store( 'song', '1', '512x512', $this->fake_png( 5000, 5000 ) ) );
	}

	/**
	 * Ampache has been observed ignoring the requested `size` and returning a full-resolution
	 * original (confirmed live during Gate A); such images are resized down, not rejected.
	 */
	public function test_store_resizes_images_over_the_dimension_limit_instead_of_rejecting(): void {
		$this->stub_working_image_editor();

		$this->assertTrue( Artwork_Cache::store( 'song', '1', '512x512', $this->fake_png( 1988, 1830 ) ) );
		$this->assertIsString( Artwork_Cache::get_url( 'song', '1', '512x512' ) );
	}

	public function test_store_fails_closed_when_the_image_editor_cannot_resize(): void {
		Functions\when( 'wp_get_image_editor' )->justReturn( new \WP_Error( 'no_editor' ) );

		$this->assertFalse( Artwork_Cache::store( 'song', '1', '512x512', $this->fake_png( 1988, 1830 ) ) );
		$this->assertNull( Artwork_Cache::get_url( 'song', '1', '512x512' ) );
	}

	public function test_store_and_get_url_round_trip(): void {
		$this->assertTrue( Artwork_Cache::store( 'song', '42', '512x512', $this->fake_png( 100, 100 ) ) );

		$url = Artwork_Cache::get_url( 'song', '42', '512x512' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'jackson-brain-ampache/v1/art/', $url );
	}

	public function test_get_url_is_null_on_a_genuine_cache_miss(): void {
		$this->assertNull( Artwork_Cache::get_url( 'song', 'never-stored', '512x512' ) );
	}

	public function test_different_type_id_size_produce_different_cache_entries(): void {
		Artwork_Cache::store( 'song', '1', '512x512', $this->fake_png( 10, 10 ) );

		// Same store call, different id -> must be a distinct cache miss, not accidentally hit.
		$this->assertNull( Artwork_Cache::get_url( 'song', '2', '512x512' ) );
	}

	public function test_run_daily_cleanup_removes_only_files_unreferenced_for_30_days(): void {
		Artwork_Cache::store( 'song', 'old', '512x512', $this->fake_png( 10, 10 ) );
		Artwork_Cache::store( 'song', 'fresh', '512x512', $this->fake_png( 10, 10 ) );

		$oldPath = $this->tempDir . '/jba-ampache-art/' . hash( 'sha256', 'song:old:512x512' );
		touch( $oldPath, time() - ( 31 * DAY_IN_SECONDS ) );

		Artwork_Cache::run_daily_cleanup();

		$this->assertNull( Artwork_Cache::get_url( 'song', 'old', '512x512' ) );
		$this->assertIsString( Artwork_Cache::get_url( 'song', 'fresh', '512x512' ) );
	}

	public function test_purge_all_removes_every_cached_file(): void {
		Artwork_Cache::store( 'song', '1', '512x512', $this->fake_png( 10, 10 ) );
		Artwork_Cache::store( 'song', '2', '512x512', $this->fake_png( 10, 10 ) );

		Artwork_Cache::purge_all();

		$this->assertNull( Artwork_Cache::get_url( 'song', '1', '512x512' ) );
		$this->assertNull( Artwork_Cache::get_url( 'song', '2', '512x512' ) );
	}
}
