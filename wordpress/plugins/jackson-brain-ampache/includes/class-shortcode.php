<?php
/**
 * Compatibility shortcode: [jba_ampache view="stats|now-playing|recent" limit="5" show_art="true"].
 * Validates attributes and delegates to Renderer, which enforces the same admin-configured
 * privacy/volume ceiling regardless of what a shortcode author requests.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcode {

	private const TAG = 'jba_ampache';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_style' ) );
	}

	/**
	 * Hooked to wp_enqueue_scripts (before wp_head/wp_print_styles) rather than enqueued
	 * from render() itself - by the time a shortcode callback runs (during the theme's
	 * main loop), wp_head() has typically already printed and closed <head> in a standard
	 * theme, so a wp_enqueue_style() call made that late never actually reaches the page
	 * (confirmed live 2026-08-08: the shortcode rendered completely unstyled). Gated on
	 * has_shortcode() so pages that don't use [jba_ampache] don't load this CSS for nothing.
	 */
	public static function maybe_enqueue_style(): void {
		if ( is_singular() && has_shortcode( (string) get_post()->post_content, self::TAG ) ) {
			wp_enqueue_style( 'jba-blocks' );
		}
	}

	public static function render( $atts ): string {
		// Belt-and-suspenders for contexts maybe_enqueue_style() can't see (e.g. a widget
		// or a direct do_shortcode() call outside post_content) - harmless no-op if the
		// style was already enqueued/printed above.
		wp_enqueue_style( 'jba-blocks' );

		$atts = shortcode_atts(
			array(
				'view'     => 'stats',
				'limit'    => '',
				'show_art' => '',
			),
			$atts,
			self::TAG
		);

		$args = array();

		if ( '' !== $atts['limit'] ) {
			$args['limit'] = (int) $atts['limit'];
		}

		if ( '' !== $atts['show_art'] ) {
			$args['show_art'] = filter_var( $atts['show_art'], FILTER_VALIDATE_BOOLEAN );
		}

		switch ( sanitize_key( $atts['view'] ) ) {
			case 'now-playing':
				return Renderer::render_now_playing( $args );

			case 'recent':
				return Renderer::render_recent( $args );

			case 'stats':
			default:
				return Renderer::render_stats( $args );
		}
	}
}
