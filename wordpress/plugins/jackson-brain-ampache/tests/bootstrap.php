<?php
/**
 * Unit test bootstrap. No full WordPress install: Brain Monkey stubs individual WP
 * functions per-test, and this file provides the small set of things Brain Monkey doesn't
 * bundle itself (WP_Error, is_wp_error, and a handful of true constants).
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

define( 'JBA_PLUGIN_FILE', dirname( __DIR__ ) . '/jackson-brain-ampache.php' );
define( 'JBA_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'JBA_PLUGIN_VERSION', '0.1.0' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in matching the subset of WP_Error's real behavior this plugin uses;
	 * Brain Monkey does not bundle WordPress's own core classes.
	 */
	class WP_Error {
		private array $errors     = array();
		private array $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;

				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );

			return $codes[0] ?? '';
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

require_once JBA_PLUGIN_DIR . 'includes/class-plugin.php';
\JacksonBrain\Ampache\Plugin::register_autoloader();
