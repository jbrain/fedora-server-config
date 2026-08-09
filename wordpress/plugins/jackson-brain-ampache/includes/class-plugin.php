<?php
/**
 * Plugin bootstrap: autoloading, activation/deactivation, and the singleton wiring point
 * for every other service class. This file must never perform an Ampache HTTP request.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private const MIN_PHP_VERSION = '8.1';

	private static ?Plugin $instance           = null;
	private static bool $autoloader_registered = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		self::register_autoloader();
		load_plugin_textdomain( 'jackson-brain-ampache', false, dirname( plugin_basename( JBA_PLUGIN_FILE ) ) . '/languages' );
		add_action( 'admin_init', array( Settings::class, 'register_settings' ) );
		add_action( 'update_option_jba_settings', array( self::class, 'maybe_invalidate_on_origin_change' ), 10, 2 );
		Ampache_Client::register_hooks();
		Scheduler::register_hooks();
		Site_Health::register_hooks();
		Admin_Page::register_hooks();
		Shortcode::register_hooks();
		Blocks::register_hooks();
		Artwork_Cache::register_hooks();
	}

	/**
	 * The origin now points at a (possibly different) server, so old snapshot/artwork data
	 * is no longer meaningfully tied to it; credential-only changes don't need this (see
	 * 02-architecture-api.md Retention and invalidation).
	 */
	public static function maybe_invalidate_on_origin_change( $old_settings, $new_settings ): void {
		$old_origin = is_array( $old_settings ) ? ( $old_settings['origin'] ?? '' ) : '';
		$new_origin = is_array( $new_settings ) ? ( $new_settings['origin'] ?? '' ) : '';

		if ( $old_origin !== $new_origin ) {
			Snapshot_Repository::invalidate();
			Artwork_Cache::purge_all();
		}
	}

	/**
	 * Maps JacksonBrain\Ampache\Foo_Bar to includes/class-foo-bar.php, matching WordPress's
	 * own file-naming convention so every new service class needs no manual require. Public
	 * and idempotent because activate()/deactivate() run before the singleton is ever
	 * constructed (activation hooks fire before `plugins_loaded`) and need it too.
	 */
	public static function register_autoloader(): void {
		if ( self::$autoloader_registered ) {
			return;
		}

		self::$autoloader_registered = true;

		spl_autoload_register(
			static function ( string $class ): void {
				$prefix = __NAMESPACE__ . '\\';

				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$filename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
				$path     = JBA_PLUGIN_DIR . 'includes/' . $filename;

				if ( file_exists( $path ) ) {
					require $path;
				}
			}
		);
	}

	/**
	 * Guards compatibility, records the installed version, and schedules cron.
	 */
	public static function activate(): void {
		self::register_autoloader();

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( JBA_PLUGIN_FILE ) );

			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Jackson Brain Ampache Integration requires PHP %1$s or newer. This server runs PHP %2$s.', 'jackson-brain-ampache' ),
						self::MIN_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}

		add_option( 'jba_plugin_version', JBA_PLUGIN_VERSION, '', false );
		Scheduler::activate();
	}

	/**
	 * Unschedules cron events and clears refresh locks; configuration and the last
	 * snapshot are kept, per the uninstall-vs-deactivate distinction in the plan.
	 */
	public static function deactivate(): void {
		self::register_autoloader();
		Scheduler::deactivate();
	}
}
