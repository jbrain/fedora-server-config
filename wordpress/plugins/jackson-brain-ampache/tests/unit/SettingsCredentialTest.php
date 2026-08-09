<?php
/**
 * The production-vs-dev-mode credential/origin precedence is the enforceable production
 * contract from plans/wordpress-ampache-plugin/03-security-privacy.md: a wp-config.php
 * constant always wins, and the database-backed fallback only ever activates in explicit
 * dev mode. Each test runs in its own process because PHP constants, once defined, cannot
 * be undefined for a later test in the same process.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Settings;
use JacksonBrain\Ampache\Tests\TestCase;

final class SettingsCredentialTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'untrailingslashit' )->alias( static fn( $value ) => rtrim( (string) $value, '/' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_origin_constant_wins_over_dev_mode_database_value(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_DEV_MODE', true );

		Functions\when( 'get_option' )->justReturn( array( 'origin' => 'https://staging.example.com' ) );

		$this->assertSame( 'https://music.jackson-brain.com', Settings::get_origin() );
		$this->assertFalse( Settings::origin_is_editable() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_origin_falls_back_to_dev_mode_value_without_the_constant(): void {
		define( 'JBA_AMPACHE_DEV_MODE', true );

		Functions\when( 'get_option' )->justReturn( array( 'origin' => 'https://staging.example.com' ) );

		$this->assertSame( 'https://staging.example.com', Settings::get_origin() );
		$this->assertTrue( Settings::origin_is_editable() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_origin_is_empty_when_unconfigured(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', Settings::get_origin() );
		$this->assertFalse( Settings::origin_is_editable() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_key_constant_wins_and_is_reported_as_such(): void {
		define( 'JBA_AMPACHE_API_KEY', 'secret-from-constant' );
		define( 'JBA_AMPACHE_DEV_MODE', true );

		Functions\when( 'get_option' )->justReturn( 'secret-from-database' );

		$this->assertSame( 'secret-from-constant', Settings::get_api_key() );
		$this->assertSame( 'constant', Settings::credential_source() );
		$this->assertFalse( Settings::api_key_is_editable() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_key_falls_back_to_database_only_in_dev_mode(): void {
		define( 'JBA_AMPACHE_DEV_MODE', true );

		Functions\when( 'get_option' )->justReturn( 'secret-from-database' );

		$this->assertSame( 'secret-from-database', Settings::get_api_key() );
		$this->assertSame( 'database', Settings::credential_source() );
		$this->assertTrue( Settings::api_key_is_editable() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_key_is_unconfigured_without_dev_mode_or_constant(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', Settings::get_api_key() );
		$this->assertSame( 'unconfigured', Settings::credential_source() );
		$this->assertFalse( Settings::api_key_is_editable() );
	}
}
