<?php
/**
 * Escapes and renders redacted view models shared by blocks and the shortcode. Public output
 * never triggers an outbound Ampache request and never distinguishes fresh from stale data;
 * only "unavailable" changes what gets rendered (see 01-product-scope.md view states).
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Renderer {

	public static function render_stats( array $args = array() ): string {
		if ( 'unavailable' === Snapshot_Repository::section_state( 'stats' ) ) {
			return self::render_unavailable();
		}

		$data = Snapshot_Repository::get_section( 'stats' )['data'] ?? array();

		return self::wrap( 'stats', self::build_stats_markup( $data, $args ) );
	}

	public static function render_now_playing( array $args = array() ): string {
		if ( 'unavailable' === Snapshot_Repository::section_state( 'now_playing' ) ) {
			return self::render_unavailable();
		}

		$entries = Snapshot_Repository::get_section( 'now_playing' )['data'] ?? array();

		if ( empty( $entries ) ) {
			return self::render_empty( __( 'Nothing is playing right now.', 'jackson-brain-ampache' ) );
		}

		return self::wrap( 'now-playing', self::build_track_list_markup( $entries, false, __( 'Now playing', 'jackson-brain-ampache' ), $args ) );
	}

	public static function render_recent( array $args = array() ): string {
		if ( 'unavailable' === Snapshot_Repository::section_state( 'recent' ) ) {
			return self::render_unavailable();
		}

		$entries = Snapshot_Repository::get_section( 'recent' )['data'] ?? array();

		if ( empty( $entries ) ) {
			return self::render_empty( __( 'No recent activity yet.', 'jackson-brain-ampache' ) );
		}

		$limit = self::effective_limit( $args );
		$entries = array_slice( $entries, 0, $limit );

		return self::wrap( 'recently-played', self::build_track_list_markup( $entries, true, __( 'Recently played', 'jackson-brain-ampache' ), $args ) );
	}

	private static function render_unavailable(): string {
		if ( 'fallback' !== Settings::get_unavailable_mode() ) {
			return '';
		}

		$text = Settings::get_unavailable_fallback_text();

		return '' !== $text ? '<p class="jba-fallback">' . esc_html( $text ) . '</p>' : '';
	}

	private static function render_empty( string $message ): string {
		return '<p class="jba-empty">' . esc_html( $message ) . '</p>';
	}

	private static function wrap( string $modifier, string $inner ): string {
		return sprintf( '<div class="jba jba-%s">%s</div>', esc_attr( $modifier ), $inner );
	}

	/**
	 * A shortcode/block 'limit' attribute may only narrow the admin-configured maximum,
	 * never widen it - the administrator-defined privacy/volume ceiling always wins.
	 */
	private static function effective_limit( array $args ): int {
		$max = Settings::get_recent_items_limit();

		if ( ! isset( $args['limit'] ) ) {
			return $max;
		}

		return max( 1, min( $max, (int) $args['limit'] ) );
	}

	/**
	 * Same ceiling principle as effective_limit(): 'show_art' can only turn artwork off
	 * when the admin enabled it, never force it on when the admin disabled it.
	 */
	private static function effective_show_artwork( array $args ): bool {
		$allowed = Settings::show_artwork();

		return isset( $args['show_art'] ) ? ( $allowed && (bool) $args['show_art'] ) : $allowed;
	}

	private static function heading_level( array $args ): string {
		$level = isset( $args['heading_level'] ) ? (int) $args['heading_level'] : 2;

		return 'h' . max( 2, min( 6, $level ) );
	}

	private static function build_stats_markup( array $data, array $args ): string {
		if ( empty( $data ) ) {
			return self::render_empty( __( 'Library statistics are not available yet.', 'jackson-brain-ampache' ) );
		}

		$rows = array(
			__( 'Songs', 'jackson-brain-ampache' )     => $data['songs'] ?? 0,
			__( 'Albums', 'jackson-brain-ampache' )    => $data['albums'] ?? 0,
			__( 'Artists', 'jackson-brain-ampache' )   => $data['artists'] ?? 0,
			__( 'Genres', 'jackson-brain-ampache' )    => $data['genres'] ?? 0,
			__( 'Playlists', 'jackson-brain-ampache' ) => $data['playlists'] ?? 0,
		);

		// Ampache has been observed reporting 0 for every count on some servers even when
		// the library is non-empty; a real 0 and "not reported" are indistinguishable, so
		// zero values are treated as unavailable and simply omitted rather than shown.
		$rows = array_filter( $rows );

		if ( empty( $rows ) ) {
			return self::render_empty( __( 'Library statistics are not available yet.', 'jackson-brain-ampache' ) );
		}

		$items = '';

		foreach ( $rows as $label => $value ) {
			$items .= sprintf(
				'<li><span class="jba-stat-label">%s</span> <span class="jba-stat-value">%s</span></li>',
				esc_html( $label ),
				esc_html( number_format_i18n( (int) $value ) )
			);
		}

		$updated = '';

		if ( Settings::show_timestamps() && ! empty( $data['updated_at'] ) ) {
			$updated = sprintf(
				'<p class="jba-updated">%1$s %2$s</p>',
				esc_html__( 'Catalog last updated', 'jackson-brain-ampache' ),
				self::time_element( (int) $data['updated_at'] )
			);
		}

		$heading = self::heading_level( $args );

		return sprintf(
			'<%1$s class="jba-heading">%2$s</%1$s><ul class="jba-stat-list">%3$s</ul>%4$s',
			esc_html( $heading ),
			esc_html__( 'Library statistics', 'jackson-brain-ampache' ),
			$items,
			$updated
		);
	}

	private static function build_track_list_markup( array $entries, bool $is_recent, string $heading_text, array $args ): string {
		$items = '';

		foreach ( $entries as $entry ) {
			$items .= self::build_track_markup( $entry, $is_recent, $args );
		}

		$heading = self::heading_level( $args );

		return sprintf(
			'<%1$s class="jba-heading">%2$s</%1$s><ul class="jba-track-list">%3$s</ul>',
			esc_html( $heading ),
			esc_html( $heading_text ),
			$items
		);
	}

	private static function build_track_markup( array $entry, bool $is_recent, array $args ): string {
		$title  = esc_html( $entry['title'] ?? '' );
		$artist = esc_html( $entry['artist'] ?? '' );
		$album  = esc_html( $entry['album'] ?? '' );
		$meta   = trim( $artist . ( '' !== $album ? ' - ' . $album : '' ) );

		$figure = self::effective_show_artwork( $args ) ? self::build_artwork_markup( $entry['artwork_ref'] ?? null ) : '';

		$time_markup = '';

		if ( $is_recent && Settings::show_timestamps() && ! empty( $entry['played_at'] ) ) {
			$time_markup = self::time_element( (int) $entry['played_at'] );
		}

		$user_markup = '';

		if ( ! $is_recent && Settings::show_usernames() && ! empty( $entry['user_label'] ) ) {
			$user_markup = sprintf( '<span class="jba-user">%s</span>', esc_html( $entry['user_label'] ) );
		}

		$client_markup = '';

		if ( ! $is_recent && Settings::show_client_names() && ! empty( $entry['client_label'] ) ) {
			$client_markup = sprintf( '<span class="jba-client">%s</span>', esc_html( $entry['client_label'] ) );
		}

		return sprintf(
			'<li class="jba-track">%1$s<div class="jba-track-info"><span class="jba-track-title">%2$s</span><span class="jba-track-meta">%3$s</span></div>%4$s%5$s%6$s</li>',
			$figure,
			$title,
			esc_html( $meta ),
			$time_markup,
			$user_markup,
			$client_markup
		);
	}

	/**
	 * Only ever reads the local filesystem via Artwork_Cache::get_url(); a cache miss
	 * (artwork not yet fetched by a scheduled/admin refresh) simply omits the image rather
	 * than triggering any request during a public render. alt is always empty: the track
	 * title is already shown as adjacent visible text, making the image purely decorative.
	 */
	private static function build_artwork_markup( ?array $artwork_ref ): string {
		if ( null === $artwork_ref ) {
			return '';
		}

		$url = Artwork_Cache::get_url( $artwork_ref['type'], $artwork_ref['id'], $artwork_ref['size'] );

		if ( null === $url ) {
			return '';
		}

		[ $width, $height ] = array_map( 'intval', explode( 'x', $artwork_ref['size'] ) );

		return sprintf(
			'<img class="jba-art" src="%1$s" width="%2$d" height="%3$d" alt="" loading="lazy" />',
			esc_url( $url ),
			$width,
			$height
		);
	}

	private static function time_element( int $timestamp ): string {
		$absolute = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		$relative = sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 minutes" */
			__( '%s ago', 'jackson-brain-ampache' ),
			human_time_diff( $timestamp, time() )
		);

		return sprintf(
			'<time datetime="%1$s" title="%2$s" class="jba-time">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( $absolute ),
			esc_html( $relative )
		);
	}
}
