<?php
/**
 * Uninstall routine: only ever removes plugin options, cron events, and local artwork when
 * the administrator explicitly enabled "Delete all plugin data on uninstall"; a normal
 * deactivation always leaves configuration and the last snapshot in place.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'jba_settings', array() );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

require __DIR__ . '/includes/class-scheduler.php';
require __DIR__ . '/includes/class-artwork-cache.php';

wp_clear_scheduled_hook( \JacksonBrain\Ampache\Scheduler::HOOK_NOW_PLAYING );
wp_clear_scheduled_hook( \JacksonBrain\Ampache\Scheduler::HOOK_STATS );
wp_clear_scheduled_hook( \JacksonBrain\Ampache\Scheduler::HOOK_DAILY_CLEANUP );

\JacksonBrain\Ampache\Artwork_Cache::purge_all();

// Must stay in sync with each owning class's private OPTION_* constant.
$options = array(
	'jba_settings',
	'jba_ampache_api_key',
	'jba_status',
	'jba_snapshot',
	'jba_refresh_lock',
	'jba_scheduled_intervals',
	'jba_plugin_version',
	'jba_last_manual_action',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
