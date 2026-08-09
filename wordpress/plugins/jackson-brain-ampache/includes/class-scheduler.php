<?php
/**
 * Registers the two refresh cadences, reconciles missing/duplicate events on every request,
 * and rebuilds an event immediately when its configured interval changes rather than waiting
 * for a stale next-run timestamp computed under the old cadence.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {

	public const HOOK_NOW_PLAYING   = 'jba_refresh_now_playing';
	public const HOOK_STATS         = 'jba_refresh_stats';
	public const HOOK_DAILY_CLEANUP = 'jba_daily_cleanup';

	private const RECURRENCE_NOW_PLAYING = 'jba_now_playing_interval';
	private const RECURRENCE_STATS       = 'jba_stats_interval';
	private const OPTION_LAST_INTERVALS  = 'jba_scheduled_intervals';

	public static function register_hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'register_cron_schedules' ) );
		add_action( 'init', array( self::class, 'reconcile' ) );
		add_action( self::HOOK_NOW_PLAYING, array( Refresh_Service::class, 'refresh_now_playing' ) );
		add_action( self::HOOK_STATS, array( Refresh_Service::class, 'refresh_stats_and_recent' ) );
		add_action( self::HOOK_DAILY_CLEANUP, array( Snapshot_Repository::class, 'run_daily_cleanup' ) );
		add_action( self::HOOK_DAILY_CLEANUP, array( Artwork_Cache::class, 'run_daily_cleanup' ) );
	}

	public static function register_cron_schedules( array $schedules ): array {
		$schedules[ self::RECURRENCE_NOW_PLAYING ] = array(
			'interval' => Settings::get_refresh_interval_seconds( 'now_playing' ),
			'display'  => __( 'Jackson Brain Ampache: now playing interval', 'jackson-brain-ampache' ),
		);
		$schedules[ self::RECURRENCE_STATS ]       = array(
			'interval' => Settings::get_refresh_interval_seconds( 'stats' ),
			'display'  => __( 'Jackson Brain Ampache: stats/recent interval', 'jackson-brain-ampache' ),
		);

		return $schedules;
	}

	public static function activate(): void {
		self::schedule( self::HOOK_NOW_PLAYING, self::RECURRENCE_NOW_PLAYING );
		self::schedule( self::HOOK_STATS, self::RECURRENCE_STATS );
		self::schedule( self::HOOK_DAILY_CLEANUP, 'daily' );
		self::remember_intervals();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK_NOW_PLAYING );
		wp_clear_scheduled_hook( self::HOOK_STATS );
		wp_clear_scheduled_hook( self::HOOK_DAILY_CLEANUP );
		delete_option( self::OPTION_LAST_INTERVALS );
	}

	public static function reconcile(): void {
		$current = array(
			'now_playing' => Settings::get_refresh_interval_seconds( 'now_playing' ),
			'stats'       => Settings::get_refresh_interval_seconds( 'stats' ),
		);
		$remembered = get_option( self::OPTION_LAST_INTERVALS, array() );

		self::reconcile_one( self::HOOK_NOW_PLAYING, self::RECURRENCE_NOW_PLAYING, $current['now_playing'], $remembered['now_playing'] ?? null );
		self::reconcile_one( self::HOOK_STATS, self::RECURRENCE_STATS, $current['stats'], $remembered['stats'] ?? null );

		if ( false === wp_next_scheduled( self::HOOK_DAILY_CLEANUP ) ) {
			self::schedule( self::HOOK_DAILY_CLEANUP, 'daily' );
		}

		if ( $remembered !== $current ) {
			update_option( self::OPTION_LAST_INTERVALS, $current, false );
		}
	}

	private static function reconcile_one( string $hook, string $recurrence, int $current_interval, ?int $remembered_interval ): void {
		$interval_changed = null !== $remembered_interval && $remembered_interval !== $current_interval;

		if ( $interval_changed ) {
			// Exactly matching arguments: our events are always scheduled with none.
			wp_clear_scheduled_hook( $hook );
		}

		if ( false === wp_next_scheduled( $hook ) ) {
			self::schedule( $hook, $recurrence );
		}
	}

	private static function schedule( string $hook, string $recurrence ): void {
		if ( false === wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $recurrence, $hook );
		}
	}

	private static function remember_intervals(): void {
		update_option(
			self::OPTION_LAST_INTERVALS,
			array(
				'now_playing' => Settings::get_refresh_interval_seconds( 'now_playing' ),
				'stats'       => Settings::get_refresh_interval_seconds( 'stats' ),
			),
			false
		);
	}
}
