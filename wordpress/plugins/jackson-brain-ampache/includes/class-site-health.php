<?php
/**
 * Site Health integration: reports configuration, scheduling, and refresh-lag problems
 * without exposing secrets or raw remote error text - only sanitized status/numeric codes
 * already stored by Snapshot_Repository. Never makes an outbound Ampache request itself.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Health {

	private const CRON_LAG_MULTIPLIER         = 2;
	private const CONSECUTIVE_FAILURE_WARNING = 3;

	public static function register_hooks(): void {
		add_filter( 'site_status_tests', array( self::class, 'register_tests' ) );
	}

	public static function register_tests( array $tests ): array {
		$tests['direct']['jba_configuration']  = array(
			'label' => __( 'Jackson Brain Ampache: configuration', 'jackson-brain-ampache' ),
			'test'  => array( self::class, 'test_configuration' ),
		);
		$tests['direct']['jba_scheduling']     = array(
			'label' => __( 'Jackson Brain Ampache: scheduling', 'jackson-brain-ampache' ),
			'test'  => array( self::class, 'test_scheduling' ),
		);
		$tests['direct']['jba_refresh_health'] = array(
			'label' => __( 'Jackson Brain Ampache: refresh health', 'jackson-brain-ampache' ),
			'test'  => array( self::class, 'test_refresh_health' ),
		);

		return $tests;
	}

	public static function test_configuration(): array {
		$result = self::base_result( 'jba_configuration', __( 'Ampache connection is configured', 'jackson-brain-ampache' ) );

		if ( '' === Settings::get_origin() || '' === Settings::get_api_key() ) {
			return self::degrade(
				$result,
				'recommended',
				'orange',
				__( 'Ampache connection is not fully configured', 'jackson-brain-ampache' ),
				__( 'Set the Ampache origin and API key before enabling public blocks.', 'jackson-brain-ampache' )
			);
		}

		return $result;
	}

	public static function test_scheduling(): array {
		$result  = self::base_result( 'jba_scheduling', __( 'Ampache refresh scheduling is healthy', 'jackson-brain-ampache' ) );
		$missing = array();

		if ( false === wp_next_scheduled( Scheduler::HOOK_NOW_PLAYING ) ) {
			$missing[] = __( 'now playing', 'jackson-brain-ampache' );
		}

		if ( false === wp_next_scheduled( Scheduler::HOOK_STATS ) ) {
			$missing[] = __( 'stats/recent', 'jackson-brain-ampache' );
		}

		if ( false === wp_next_scheduled( Scheduler::HOOK_DAILY_CLEANUP ) ) {
			$missing[] = __( 'daily cleanup', 'jackson-brain-ampache' );
		}

		if ( empty( $missing ) ) {
			return $result;
		}

		return self::degrade(
			$result,
			'critical',
			'red',
			__( 'Ampache refresh scheduling is missing an event', 'jackson-brain-ampache' ),
			sprintf(
				/* translators: %s: comma-separated list of missing schedules */
				__( 'The following refresh schedules are not registered: %s. Deactivate and reactivate the plugin.', 'jackson-brain-ampache' ),
				implode( ', ', $missing )
			)
		);
	}

	public static function test_refresh_health(): array {
		$result   = self::base_result( 'jba_refresh_health', __( 'Ampache refresh is running on schedule', 'jackson-brain-ampache' ) );
		$status   = Snapshot_Repository::get_status();
		$interval = Settings::get_refresh_interval_seconds( 'now_playing' );
		$attempt  = (int) ( $status['now_playing']['last_attempt'] ?? 0 );

		if ( 0 !== $attempt && ( time() - $attempt ) > ( $interval * self::CRON_LAG_MULTIPLIER ) ) {
			return self::degrade(
				$result,
				'critical',
				'red',
				__( 'Ampache now-playing refresh is lagging', 'jackson-brain-ampache' ),
				__( 'The now-playing refresh has not run recently. Confirm the host cron timer (or WP-Cron) is running.', 'jackson-brain-ampache' )
			);
		}

		foreach ( array( 'now_playing', 'stats', 'recent' ) as $section ) {
			$failures = (int) ( $status[ $section ]['consecutive_failures'] ?? 0 );

			if ( $failures >= self::CONSECUTIVE_FAILURE_WARNING ) {
				return self::degrade(
					$result,
					'recommended',
					'orange',
					__( 'An Ampache section is repeatedly failing to refresh', 'jackson-brain-ampache' ),
					sprintf(
						/* translators: 1: section name, 2: consecutive failure count, 3: numeric Ampache error code */
						__( 'The "%1$s" section has failed %2$d refreshes in a row (error code %3$d).', 'jackson-brain-ampache' ),
						$section,
						$failures,
						(int) ( $status[ $section ]['error_code'] ?? 0 )
					)
				);
			}
		}

		return $result;
	}

	private static function base_result( string $test_id, string $label ): array {
		return array(
			'label'       => $label,
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Jackson Brain Ampache', 'jackson-brain-ampache' ),
				'color' => 'green',
			),
			'description' => '',
			'actions'     => '',
			'test'        => $test_id,
		);
	}

	private static function degrade( array $result, string $status, string $color, string $label, string $description ): array {
		$result['status']         = $status;
		$result['label']          = $label;
		$result['description']    = '<p>' . esc_html( $description ) . '</p>';
		$result['badge']['color'] = $color;

		return $result;
	}
}
