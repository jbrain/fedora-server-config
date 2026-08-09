<?php
/**
 * Site_Health's three checks: configuration completeness, missing scheduled events, and
 * refresh-lag/repeated-failure detection - using only sanitized status Snapshot_Repository
 * already stores, never a live Ampache call.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Site_Health;
use JacksonBrain\Ampache\Tests\TestCase;

final class SiteHealthTest extends TestCase {

	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array(
			'jba_settings' => array(
				'refresh_now_playing_seconds' => 60,
			),
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	}

	public function test_configuration_is_flagged_when_unconfigured(): void {
		// Neither JBA_AMPACHE_ORIGIN nor JBA_AMPACHE_API_KEY is defined in this process.
		$result = Site_Health::test_configuration();

		$this->assertSame( 'recommended', $result['status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_configuration_is_good_when_fully_configured(): void {
		define( 'JBA_AMPACHE_ORIGIN', 'https://music.jackson-brain.com' );
		define( 'JBA_AMPACHE_API_KEY', 'test-key' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $v ) => rtrim( (string) $v, '/' ) );

		$result = Site_Health::test_configuration();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_scheduling_is_healthy_when_every_event_is_registered(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 );

		$this->assertSame( 'good', Site_Health::test_scheduling()['status'] );
	}

	public function test_scheduling_is_critical_when_an_event_is_missing(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$result = Site_Health::test_scheduling();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'now playing', $result['description'] );
	}

	public function test_refresh_health_is_good_with_no_prior_attempt(): void {
		$this->options['jba_status'] = array();

		$this->assertSame( 'good', Site_Health::test_refresh_health()['status'] );
	}

	public function test_refresh_health_is_critical_when_lag_exceeds_two_intervals(): void {
		// interval is 60s, so anything over 120s ago is lagging.
		$this->options['jba_status'] = array(
			'now_playing' => array( 'last_attempt' => time() - 300 ),
		);

		$this->assertSame( 'critical', Site_Health::test_refresh_health()['status'] );
	}

	public function test_refresh_health_is_good_within_the_lag_threshold(): void {
		$this->options['jba_status'] = array(
			'now_playing' => array( 'last_attempt' => time() - 30 ),
		);

		$this->assertSame( 'good', Site_Health::test_refresh_health()['status'] );
	}

	public function test_refresh_health_warns_at_three_consecutive_failures(): void {
		$this->options['jba_status'] = array(
			'now_playing' => array( 'last_attempt' => time() ),
			'stats'       => array(
				'consecutive_failures' => 3,
				'error_code'           => 4701,
			),
			'recent'      => array(),
		);

		$result = Site_Health::test_refresh_health();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '4701', $result['description'] );
	}

	public function test_refresh_health_is_good_with_only_two_consecutive_failures(): void {
		$this->options['jba_status'] = array(
			'now_playing' => array( 'last_attempt' => time() ),
			'stats'       => array( 'consecutive_failures' => 2 ),
			'recent'      => array(),
		);

		$this->assertSame( 'good', Site_Health::test_refresh_health()['status'] );
	}
}
