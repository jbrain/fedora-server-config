<?php
/**
 * Registers the shared block editor script/style handles and the three dynamic blocks from
 * their block.json files. A block's own render.php always reads Snapshot_Repository through
 * Renderer, so both the public page and the editor's ServerSideRender preview stay outbound-
 * request-free.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blocks {

	private const BLOCK_SLUGS = array( 'library-stats', 'now-playing', 'recently-played' );
	private const BLOCK_NAMES = array(
		'jackson-brain/ampache-library-stats',
		'jackson-brain/ampache-now-playing',
		'jackson-brain/ampache-recently-played',
	);

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_style' ) );
	}

	public static function register(): void {
		// File-mtime-based version, not the fixed JBA_PLUGIN_VERSION: a CSS/JS-only edit
		// (no version bump) previously left visitors' browsers serving a stale cached copy
		// of blocks.css indefinitely, since the enqueued URL's `ver` query param never
		// changed - confirmed live 2026-08-08 (the "album art still full size" report).
		wp_register_style(
			'jba-blocks',
			plugins_url( 'assets/css/blocks.css', JBA_PLUGIN_FILE ),
			array(),
			self::asset_version( 'assets/css/blocks.css' )
		);

		wp_register_script(
			'jba-blocks-editor',
			plugins_url( 'assets/js/editor.js', JBA_PLUGIN_FILE ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-components', 'wp-server-side-render' ),
			self::asset_version( 'assets/js/editor.js' ),
			true
		);

		foreach ( self::BLOCK_SLUGS as $slug ) {
			register_block_type( JBA_PLUGIN_DIR . 'blocks/' . $slug );
		}
	}

	private static function asset_version( string $relative_path ): string {
		$file  = JBA_PLUGIN_DIR . $relative_path;
		$mtime = file_exists( $file ) ? filemtime( $file ) : false;

		return false !== $mtime ? (string) $mtime : JBA_PLUGIN_VERSION;
	}

	/**
	 * block.json's own "style" key auto-enqueues jba-blocks when a block instance is
	 * actually rendered, but that only happens as the_content() runs - by then, this
	 * theme's header.php has already called wp_head() (which prints the then-current
	 * style queue) and moved on, so the auto-enqueued style is added one step too late to
	 * ever be printed (confirmed live 2026-08-08: registration/block metadata were both
	 * correct, and a direct the_content simulation DID queue it, yet it never appeared in
	 * the real page's <head> or anywhere else in the response). Hooking wp_enqueue_scripts
	 * (which fires before wp_head) and checking has_block() directly sidesteps this
	 * entirely - the same pattern already used for the shortcode (see class-shortcode.php)
	 * and observed working correctly for other plugins (e.g. Contact Form 7's own CSS).
	 */
	public static function maybe_enqueue_style(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( null === $post ) {
			return;
		}

		foreach ( self::BLOCK_NAMES as $block_name ) {
			if ( has_block( $block_name, $post ) ) {
				wp_enqueue_style( 'jba-blocks' );
				return;
			}
		}
	}

	/**
	 * A REST block-renderer call requires edit_posts, so this heuristic only ever shows the
	 * status line to an authenticated editor preview, never a public page load.
	 */
	public static function editor_status_line( string $section ): string {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) ) {
			return '';
		}

		return sprintf(
			'<p class="jba-editor-status"><em>%s</em></p>',
			esc_html(
				sprintf(
					/* translators: %s: fresh, stale, or unavailable */
					__( 'Editor preview status: %s', 'jackson-brain-ampache' ),
					Snapshot_Repository::section_state( $section )
				)
			)
		);
	}
}
