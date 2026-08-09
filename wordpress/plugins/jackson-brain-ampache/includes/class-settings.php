<?php
/**
 * Reads and validates plugin configuration. Production credentials/origin come from
 * wp-config.php constants; the database-backed fallback only activates in explicit
 * development/staging mode (see plans/wordpress-ampache-plugin/03-security-privacy.md).
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	private const OPTION_SETTINGS = 'jba_settings';
	private const OPTION_API_KEY  = 'jba_ampache_api_key';

	private const REFRESH_NOW_PLAYING_MIN = 30;
	private const REFRESH_NOW_PLAYING_MAX = 300;
	private const REFRESH_STATS_MIN       = 600;
	private const REFRESH_STATS_MAX       = 1800;
	private const MAX_AGE_NOW_PLAYING_MIN = 60;
	private const MAX_AGE_NOW_PLAYING_MAX = 3600;
	private const MAX_AGE_STATS_MIN       = 3600;
	private const MAX_AGE_STATS_MAX       = 172800;
	private const MIN_RECENT_ITEMS        = 1;
	private const MAX_RECENT_ITEMS        = 25;
	private const MAX_FALLBACK_TEXT_LENGTH = 200;

	public static function register_settings(): void {
		register_setting(
			'jackson-brain-ampache',
			self::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function defaults(): array {
		return array(
			'origin'                      => '',
			'refresh_now_playing_seconds' => 60,
			'refresh_stats_seconds'       => 900,
			'max_age_now_playing_seconds' => 300,
			'max_age_stats_seconds'       => 86400,
			'unavailable_mode'            => 'omit',
			'unavailable_fallback_text'   => '',
			'recent_items'                => 5,
			'show_artwork'                => true,
			'show_timestamps'             => true,
			'show_usernames'              => false,
			'show_client_names'           => false,
			'ampache_link_enabled'        => false,
			'delete_data_on_uninstall'    => false,
			'configuration_generation'    => 1,
		);
	}

	public static function get_settings(): array {
		return wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), self::defaults() );
	}

	public static function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = self::get_settings();
		$output   = $previous;

		$output['refresh_now_playing_seconds'] = self::clamp_int(
			$input['refresh_now_playing_seconds'] ?? $previous['refresh_now_playing_seconds'],
			self::REFRESH_NOW_PLAYING_MIN,
			self::REFRESH_NOW_PLAYING_MAX
		);

		$output['refresh_stats_seconds'] = self::clamp_int(
			$input['refresh_stats_seconds'] ?? $previous['refresh_stats_seconds'],
			self::REFRESH_STATS_MIN,
			self::REFRESH_STATS_MAX
		);

		$output['max_age_now_playing_seconds'] = self::clamp_int(
			$input['max_age_now_playing_seconds'] ?? $previous['max_age_now_playing_seconds'],
			self::MAX_AGE_NOW_PLAYING_MIN,
			self::MAX_AGE_NOW_PLAYING_MAX
		);

		$output['max_age_stats_seconds'] = self::clamp_int(
			$input['max_age_stats_seconds'] ?? $previous['max_age_stats_seconds'],
			self::MAX_AGE_STATS_MIN,
			self::MAX_AGE_STATS_MAX
		);

		$unavailable_mode           = $input['unavailable_mode'] ?? $previous['unavailable_mode'];
		$output['unavailable_mode'] = in_array( $unavailable_mode, array( 'omit', 'fallback' ), true )
			? $unavailable_mode
			: 'omit';

		$output['unavailable_fallback_text'] = mb_substr(
			sanitize_text_field( (string) ( $input['unavailable_fallback_text'] ?? $previous['unavailable_fallback_text'] ) ),
			0,
			self::MAX_FALLBACK_TEXT_LENGTH
		);

		$output['recent_items'] = self::clamp_int(
			$input['recent_items'] ?? $previous['recent_items'],
			self::MIN_RECENT_ITEMS,
			self::MAX_RECENT_ITEMS
		);

		foreach ( array( 'show_artwork', 'show_timestamps', 'show_usernames', 'show_client_names', 'ampache_link_enabled', 'delete_data_on_uninstall' ) as $flag ) {
			$output[ $flag ] = ! empty( $input[ $flag ] );
		}

		$origin_changed = false;
		if ( self::origin_is_editable() ) {
			$new_origin = self::sanitize_origin( (string) ( $input['origin'] ?? '' ), $previous['origin'] );
			if ( $new_origin !== $previous['origin'] ) {
				$origin_changed = true;
			}
			$output['origin'] = $new_origin;
		}

		$output['configuration_generation'] = $origin_changed
			? ( (int) $previous['configuration_generation'] ) + 1
			: (int) $previous['configuration_generation'];

		return $output;
	}

	private static function clamp_int( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	private static function sanitize_origin( string $raw, string $previous ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		$validated = wp_http_validate_url( $raw );

		if ( ! $validated ) {
			return $previous;
		}

		$parts = wp_parse_url( $validated );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $previous;
		}

		$require_https = apply_filters( 'jba_require_https', true );

		if ( $require_https && 'https' !== strtolower( $parts['scheme'] ) ) {
			return $previous;
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	public static function is_dev_mode(): bool {
		return defined( 'JBA_AMPACHE_DEV_MODE' ) && true === JBA_AMPACHE_DEV_MODE;
	}

	public static function origin_is_editable(): bool {
		return self::is_dev_mode() && ! defined( 'JBA_AMPACHE_ORIGIN' );
	}

	public static function api_key_is_editable(): bool {
		return self::is_dev_mode() && ! defined( 'JBA_AMPACHE_API_KEY' );
	}

	public static function get_origin(): string {
		if ( defined( 'JBA_AMPACHE_ORIGIN' ) && '' !== JBA_AMPACHE_ORIGIN ) {
			return untrailingslashit( JBA_AMPACHE_ORIGIN );
		}

		if ( self::is_dev_mode() ) {
			return untrailingslashit( self::get_settings()['origin'] );
		}

		return '';
	}

	public static function get_api_key(): string {
		if ( defined( 'JBA_AMPACHE_API_KEY' ) && '' !== JBA_AMPACHE_API_KEY ) {
			return (string) JBA_AMPACHE_API_KEY;
		}

		if ( self::is_dev_mode() ) {
			return (string) get_option( self::OPTION_API_KEY, '' );
		}

		return '';
	}

	public static function has_api_key(): bool {
		return '' !== self::get_api_key();
	}

	/**
	 * For admin display only; callers must never render the credential value itself.
	 */
	public static function credential_source(): string {
		if ( defined( 'JBA_AMPACHE_API_KEY' ) && '' !== JBA_AMPACHE_API_KEY ) {
			return 'constant';
		}

		if ( self::is_dev_mode() && '' !== get_option( self::OPTION_API_KEY, '' ) ) {
			return 'database';
		}

		return 'unconfigured';
	}

	/**
	 * Blank submissions must preserve the existing key (rendered field is always empty).
	 */
	public static function maybe_update_api_key( string $submitted ): bool {
		$submitted = trim( $submitted );

		if ( '' === $submitted ) {
			return false;
		}

		return self::set_api_key( $submitted );
	}

	public static function set_api_key( string $key ): bool {
		if ( ! self::api_key_is_editable() ) {
			return false;
		}

		update_option( self::OPTION_API_KEY, $key, false );
		self::bump_configuration_generation();

		return true;
	}

	public static function remove_api_key(): bool {
		if ( ! self::api_key_is_editable() ) {
			return false;
		}

		$existed = '' !== get_option( self::OPTION_API_KEY, '' );
		delete_option( self::OPTION_API_KEY );

		if ( $existed ) {
			self::bump_configuration_generation();
		}

		return true;
	}

	private static function bump_configuration_generation(): void {
		$settings                             = self::get_settings();
		$settings['configuration_generation'] = ( (int) $settings['configuration_generation'] ) + 1;
		update_option( self::OPTION_SETTINGS, $settings, false );
	}

	public static function get_configuration_generation(): int {
		return (int) self::get_settings()['configuration_generation'];
	}

	public static function get_recent_items_limit(): int {
		return (int) self::get_settings()['recent_items'];
	}

	public static function get_unavailable_mode(): string {
		return (string) self::get_settings()['unavailable_mode'];
	}

	public static function get_unavailable_fallback_text(): string {
		return (string) self::get_settings()['unavailable_fallback_text'];
	}

	public static function show_artwork(): bool {
		return (bool) self::get_settings()['show_artwork'];
	}

	public static function show_timestamps(): bool {
		return (bool) self::get_settings()['show_timestamps'];
	}

	public static function show_usernames(): bool {
		return (bool) self::get_settings()['show_usernames'];
	}

	public static function show_client_names(): bool {
		return (bool) self::get_settings()['show_client_names'];
	}

	public static function delete_data_on_uninstall(): bool {
		return (bool) self::get_settings()['delete_data_on_uninstall'];
	}

	public static function get_refresh_interval_seconds( string $section ): int {
		$settings = self::get_settings();

		return 'now_playing' === $section
			? (int) $settings['refresh_now_playing_seconds']
			: (int) $settings['refresh_stats_seconds'];
	}

	public static function get_max_age_seconds( string $section ): int {
		$settings = self::get_settings();

		return 'now_playing' === $section
			? (int) $settings['max_age_now_playing_seconds']
			: (int) $settings['max_age_stats_seconds'];
	}

	/**
	 * Token-free by construction: this is always the bare configured/canonical origin.
	 */
	public static function get_ampache_link_url(): string {
		$settings = self::get_settings();
		$origin   = self::get_origin();

		return ( ! empty( $settings['ampache_link_enabled'] ) && '' !== $origin ) ? $origin : '';
	}
}
