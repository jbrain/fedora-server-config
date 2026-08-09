<?php
/**
 * Scheduler's reconcile() must repair a missing event, never duplicate an existing one, and
 * rebuild an event immediately (clear-then-reschedule) when its configured interval changed -
 * rather than leaving a stale next-run time computed under the old cadence.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Scheduler;
use JacksonBrain\Ampache\Tests\TestCase;

final class SchedulerTest extends TestCase {

	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options = array(
			'jba_settings' => array(
				'refresh_now_playing_seconds' => 60,
				'refresh_stats_seconds'       => 900,
			),
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
	}

	public function test_reconcile_repairs_a_missing_event(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->atLeast()->once();
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		Scheduler::reconcile();

		$this->assertTrue( true ); // verified via the Mockery expectations above.
	}

	public function test_reconcile_does_not_duplicate_an_existing_event(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 ); // already scheduled.
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::reconcile();

		$this->assertTrue( true );
	}

	public function test_reconcile_rebuilds_immediately_when_the_interval_changed(): void {
		// A previous run remembered a different now-playing interval than currently configured.
		$this->options['jba_scheduled_intervals'] = array(
			'now_playing' => 30,
			'stats'       => 900,
		);

		// wp_clear_scheduled_hook and wp_next_scheduled must interact statefully here, the
		// same way they really would: clearing the event is what makes the subsequent
		// wp_next_scheduled() check report "not scheduled", triggering the reschedule.
		$cleared = false;
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( Scheduler::HOOK_NOW_PLAYING )
			->andReturnUsing(
				static function () use ( &$cleared ) {
					$cleared = true;
				}
			);
		Functions\expect( 'wp_next_scheduled' )
			->andReturnUsing(
				static function ( $hook ) use ( &$cleared ) {
					return ( Scheduler::HOOK_NOW_PLAYING === $hook && $cleared ) ? false : time() + 60;
				}
			);
		Functions\expect( 'wp_schedule_event' )->atLeast()->once();

		Scheduler::reconcile();

		$this->assertTrue( true );
	}

	public function test_reconcile_leaves_a_healthy_unchanged_schedule_alone(): void {
		$this->options['jba_scheduled_intervals'] = array(
			'now_playing' => 60,
			'stats'       => 900,
		);

		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 60 );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		Scheduler::reconcile();

		$this->assertTrue( true );
	}
}
