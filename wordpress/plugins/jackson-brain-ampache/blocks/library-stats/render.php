<?php
/**
 * @var array $attributes Block attributes (headingLevel).
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

printf(
	'<div %1$s>%2$s%3$s</div>',
	get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by WordPress core.
	Renderer::render_stats( $args ), // phpcs:ignore WordPress.Security.EscapeOutput -- Renderer output is already escaped.
	Blocks::editor_status_line( 'stats' ) // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped.
);
