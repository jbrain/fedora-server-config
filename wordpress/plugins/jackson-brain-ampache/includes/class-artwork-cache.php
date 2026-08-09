<?php
/**
 * Local artwork cache: validates and stores fetched artwork under wp-content/uploads, and
 * serves it from a public REST route keyed by an opaque hash - never an upstream Ampache URL.
 * A cache miss is never filled during rendering; only a scheduled/admin refresh writes files.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Artwork_Cache {

	private const SUBDIR               = 'jba-ampache-art';
	// Ampache has been observed ignoring the requested `size` and returning a full-resolution
	// original (confirmed live during Gate A: a 512x512 request came back 1988x1830 / 3.66MB),
	// so the fetch ceiling must accommodate that - MAX_DIMENSION below is what gets stored.
	private const MAX_FETCH_BYTES       = 8388608;
	// Guards decoded-pixel memory use (a decompression-bomb-style image) independent of the
	// compressed byte size; oversized-but-reasonable originals are resized, not rejected.
	private const MAX_SOURCE_DIMENSION  = 4000;
	private const MAX_DIMENSION         = 512;
	private const MAX_AGE_SECONDS       = 30 * DAY_IN_SECONDS;
	private const REST_NAMESPACE        = 'jackson-brain-ampache/v1';

	private const ALLOWED_MIME = array(
		'image/jpeg' => "\xFF\xD8\xFF",
		'image/png'  => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",
	);

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/art/(?P<key>[a-f0-9]{64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'serve' ),
				// Public: artwork shares the visibility of the page it's embedded in, and
				// carries no Ampache credential, session, or upstream URL.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns the local URL for already-cached artwork, or null on a cache miss. Never
	 * fetches from Ampache itself - this method only reads the local filesystem.
	 */
	public static function get_url( string $type, string $id, string $size ): ?string {
		$key  = self::make_key( $type, $id, $size );
		$path = self::cache_dir() . '/' . $key;

		if ( ! file_exists( $path ) ) {
			return null;
		}

		self::touch_referenced( $path );

		return rest_url( self::REST_NAMESPACE . '/art/' . $key );
	}

	/**
	 * Validates and stores fetched artwork bytes. Only called from a scheduled/admin
	 * refresh (Refresh_Service), never while rendering a public page.
	 */
	public static function store( string $type, string $id, string $size, string $bytes ): bool {
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_FETCH_BYTES ) {
			return false;
		}

		$mime = self::sniff_mime( $bytes );

		if ( null === $mime ) {
			return false;
		}

		$dimensions = @getimagesizefromstring( $bytes );

		if ( false === $dimensions || $dimensions[0] > self::MAX_SOURCE_DIMENSION || $dimensions[1] > self::MAX_SOURCE_DIMENSION ) {
			return false;
		}

		$dir = self::cache_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$path = $dir . '/' . self::make_key( $type, $id, $size );
		$tmp  = $path . '.tmp-' . wp_generate_password( 12, false, false );

		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}

		if ( $dimensions[0] > self::MAX_DIMENSION || $dimensions[1] > self::MAX_DIMENSION ) {
			if ( ! self::resize_in_place( $tmp, $mime ) ) {
				@unlink( $tmp );
				return false;
			}
		}

		chmod( $tmp, 0644 );

		return rename( $tmp, $path );
	}

	/**
	 * Ampache may ignore our requested `size` and return a full-resolution original; this
	 * downsizes it in place to fit within MAX_DIMENSION rather than storing/serving the
	 * oversized original as-is. Uses WordPress core's own image editor (GD or Imagick,
	 * whichever is available) - no additional dependency.
	 */
	private static function resize_in_place( string $path, string $mime ): bool {
		$editor = wp_get_image_editor( $path );

		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$resized = $editor->resize( self::MAX_DIMENSION, self::MAX_DIMENSION, false );

		if ( is_wp_error( $resized ) ) {
			return false;
		}

		return ! is_wp_error( $editor->save( $path, $mime ) );
	}

	public static function serve( \WP_REST_Request $request ) {
		$key  = (string) $request->get_param( 'key' );
		$path = self::cache_dir() . '/' . $key;

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $key ) || ! file_exists( $path ) ) {
			return new \WP_Error( 'jba_art_not_found', 'Not found.', array( 'status' => 404 ) );
		}

		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$mime  = self::sniff_mime( $bytes );

		if ( null === $mime ) {
			return new \WP_Error( 'jba_art_invalid', 'Not found.', array( 'status' => 404 ) );
		}

		self::touch_referenced( $path );

		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=' . DAY_IN_SECONDS );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw binary image data.
		exit;
	}

	public static function run_daily_cleanup(): void {
		self::for_each_cached_file(
			static function ( string $path ) {
				$mtime = @filemtime( $path );

				if ( false !== $mtime && ( time() - $mtime ) > self::MAX_AGE_SECONDS ) {
					@unlink( $path );
				}
			}
		);
	}

	/**
	 * Called on an origin change or an explicit "clear cached snapshot" action; old artwork
	 * is no longer meaningfully tied to the (possibly different) currently configured server.
	 */
	public static function purge_all(): void {
		self::for_each_cached_file(
			static function ( string $path ) {
				@unlink( $path );
			}
		);
	}

	private static function for_each_cached_file( callable $callback ): void {
		$dir = self::cache_dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) ?: array() as $file ) {
			if ( '.' === $file || '..' === $file || str_contains( $file, '.tmp-' ) ) {
				continue;
			}

			$callback( $dir . '/' . $file );
		}
	}

	private static function touch_referenced( string $path ): void {
		// Throttled to avoid a filesystem write on every single page render.
		if ( ( time() - (int) @filemtime( $path ) ) > HOUR_IN_SECONDS ) {
			@touch( $path );
		}
	}

	private static function sniff_mime( string $bytes ): ?string {
		foreach ( self::ALLOWED_MIME as $mime => $magic ) {
			if ( 0 === strncmp( $bytes, $magic, strlen( $magic ) ) ) {
				return $mime;
			}
		}

		return null;
	}

	private static function make_key( string $type, string $id, string $size ): string {
		return hash( 'sha256', $type . ':' . $id . ':' . $size );
	}

	private static function cache_dir(): string {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . self::SUBDIR;
	}
}
