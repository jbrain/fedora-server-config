<?php
/**
 * @var array $attributes Block attributes (headingLevel, showArt).
 */

use JacksonBrain\Ampache\Blocks;
use JacksonBrain\Ampache\Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = array();

if ( isset( $attributes['headingLevel'] ) ) {
	$args['heading_level'] = (int) $attributes['headingLevel'];
}

if ( isset( $attributes['showArt'] ) ) {
	$args['show_art'] = (bool) $attributes['showArt'];
}

printf(
	'<div %1$s>%2$s%3$s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by WordPress core.
	Renderer::render_now_playing( $args ), // phpcs:ignore WordPress.Security.EscapeOutput -- Renderer output is already escaped.
	Blocks::editor_status_line( 'now_playing' ) // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped.
);
