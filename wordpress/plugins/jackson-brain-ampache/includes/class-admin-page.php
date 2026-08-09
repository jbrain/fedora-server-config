<?php
/**
 * The wp-admin "Ampache Integration" settings page: renders Settings' data and drives the
 * four manual admin actions. Kept separate from Settings so that class stays a pure
 * data/validation layer with no HTML/output concerns.
 */

namespace JacksonBrain\Ampache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Page {

	private const PAGE_SLUG    = 'jackson-brain-ampache';
	private const NONCE_ACTION = 'jba_admin_action';

	private const ACTION_TEST_CONNECTION = 'jba_test_connection';
	private const ACTION_REFRESH_NOW     = 'jba_refresh_now';
	private const ACTION_CLEAR_SNAPSHOT  = 'jba_clear_snapshot';
	private const ACTION_SAVE_API_KEY    = 'jba_save_api_key';

	private const NOTICES = array(
		'connection_ok'     => array( 'success', 'Connection test succeeded.' ),
		'connection_failed' => array( 'error', 'Connection test failed. Check the Site Health page for details.' ),
		'refresh_ok'        => array( 'success', 'Refresh completed.' ),
		'refresh_busy'      => array( 'warning', 'A refresh was already in progress; nothing was done.' ),
		'snapshot_cleared'  => array( 'success', 'The cached snapshot was cleared.' ),
		'key_saved'         => array( 'success', 'API key saved.' ),
		'key_removed'       => array( 'success', 'Stored API key removed.' ),
		'key_unchanged'     => array( 'warning', 'No key was submitted; the existing key was kept.' ),
		'key_not_editable'  => array( 'error', 'The API key is managed by a wp-config.php constant and cannot be changed here.' ),
		'cooldown'          => array( 'warning', 'Please wait a little longer before trying that again.' ),
	);

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION_TEST_CONNECTION, array( self::class, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH_NOW, array( self::class, 'handle_refresh_now' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_SNAPSHOT, array( self::class, 'handle_clear_snapshot' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_API_KEY, array( self::class, 'handle_save_api_key' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JBA_PLUGIN_FILE ), array( self::class, 'add_settings_link' ) );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Ampache Integration', 'jackson-brain-ampache' ),
			__( 'Ampache Integration', 'jackson-brain-ampache' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function add_settings_link( array $links ): array {
		$url           = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'jackson-brain-ampache' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ampache Integration', 'jackson-brain-ampache' ); ?></h1>

			<h2><?php esc_html_e( 'Adding this to a page', 'jackson-brain-ampache' ); ?></h2>
			<p>
				<?php esc_html_e( 'In the block editor, search the block inserter for "Ampache" to find three blocks:', 'jackson-brain-ampache' ); ?>
			</p>
			<ul style="list-style: disc; margin-left: 2em;">
				<li><strong><?php esc_html_e( 'Ampache Library Statistics', 'jackson-brain-ampache' ); ?></strong></li>
				<li><strong><?php esc_html_e( 'Ampache Now Playing', 'jackson-brain-ampache' ); ?></strong></li>
				<li><strong><?php esc_html_e( 'Ampache Recently Played', 'jackson-brain-ampache' ); ?></strong></li>
			</ul>
			<p>
				<?php esc_html_e( 'For the Classic Editor, widgets, or any other shortcode-capable area, use the [jba_ampache] shortcode instead:', 'jackson-brain-ampache' ); ?>
			</p>
			<ul style="list-style: disc; margin-left: 2em;">
				<li><code>[jba_ampache view="stats"]</code></li>
				<li><code>[jba_ampache view="now-playing"]</code></li>
				<li><code>[jba_ampache view="recent" limit="5" show_art="true"]</code></li>
			</ul>
			<p class="description">
				<?php esc_html_e( '"limit" and "show_art" are optional and only apply to the "recent" view; both blocks and the shortcode always respect the display settings configured below regardless of what is requested.', 'jackson-brain-ampache' ); ?>
			</p>

			<h2><?php esc_html_e( 'Connection', 'jackson-brain-ampache' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Ampache origin', 'jackson-brain-ampache' ); ?></th>
					<td>
						<code><?php echo esc_html( '' !== Settings::get_origin() ? Settings::get_origin() : __( '(not configured)', 'jackson-brain-ampache' ) ); ?></code>
						<?php if ( ! Settings::origin_is_editable() ) : ?>
							<p class="description"><?php esc_html_e( 'Set by the JBA_AMPACHE_ORIGIN constant; read-only here.', 'jackson-brain-ampache' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API key source', 'jackson-brain-ampache' ); ?></th>
					<td><?php echo esc_html( self::credential_source_label() ); ?></td>
				</tr>
			</table>

			<?php self::render_api_key_form(); ?>
			<?php self::render_settings_form( $settings ); ?>
			<?php self::render_actions_form(); ?>
			<?php self::render_status_table(); ?>
		</div>
		<?php
	}

	private static function render_api_key_form(): void {
		if ( ! Settings::api_key_is_editable() ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'API key (development/staging only)', 'jackson-brain-ampache' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_API_KEY ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="jba_api_key"><?php esc_html_e( 'API key', 'jackson-brain-ampache' ); ?></label></th>
					<td>
						<input type="password" id="jba_api_key" name="jba_api_key" value="" autocomplete="off" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Leave blank to keep the current key.', 'jackson-brain-ampache' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save key', 'jackson-brain-ampache' ), 'secondary', 'submit', false ); ?>
			<?php if ( Settings::has_api_key() ) : ?>
				<button type="submit" name="jba_remove_key" value="1" class="button-link-delete">
					<?php esc_html_e( 'Remove stored key', 'jackson-brain-ampache' ); ?>
				</button>
			<?php endif; ?>
		</form>
		<?php
	}

	private static function render_settings_form( array $settings ): void {
		?>
		<h2><?php esc_html_e( 'Display and refresh', 'jackson-brain-ampache' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'jackson-brain-ampache' ); ?>
			<table class="form-table" role="presentation">
				<?php if ( Settings::origin_is_editable() ) : ?>
					<tr>
						<th scope="row"><label for="jba_origin"><?php esc_html_e( 'Ampache origin (dev/staging)', 'jackson-brain-ampache' ); ?></label></th>
						<td><input type="url" id="jba_origin" name="jba_settings[origin]" value="<?php echo esc_attr( $settings['origin'] ); ?>" class="regular-text" /></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="jba_recent_items"><?php esc_html_e( 'Recent items', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="number" min="1" max="25" id="jba_recent_items" name="jba_settings[recent_items]" value="<?php echo esc_attr( $settings['recent_items'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_refresh_now_playing"><?php esc_html_e( 'Now-playing refresh interval (seconds)', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="number" min="30" max="300" id="jba_refresh_now_playing" name="jba_settings[refresh_now_playing_seconds]" value="<?php echo esc_attr( $settings['refresh_now_playing_seconds'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_refresh_stats"><?php esc_html_e( 'Statistics/recent refresh interval (seconds)', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="number" min="600" max="1800" id="jba_refresh_stats" name="jba_settings[refresh_stats_seconds]" value="<?php echo esc_attr( $settings['refresh_stats_seconds'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_max_age_now_playing"><?php esc_html_e( 'Now-playing maximum age (seconds)', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="number" min="60" max="3600" id="jba_max_age_now_playing" name="jba_settings[max_age_now_playing_seconds]" value="<?php echo esc_attr( $settings['max_age_now_playing_seconds'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_max_age_stats"><?php esc_html_e( 'Statistics/recent maximum age (seconds)', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="number" min="3600" max="172800" id="jba_max_age_stats" name="jba_settings[max_age_stats_seconds]" value="<?php echo esc_attr( $settings['max_age_stats_seconds'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_unavailable_mode"><?php esc_html_e( 'Unavailable behavior', 'jackson-brain-ampache' ); ?></label></th>
					<td>
						<select id="jba_unavailable_mode" name="jba_settings[unavailable_mode]">
							<option value="omit" <?php selected( $settings['unavailable_mode'], 'omit' ); ?>><?php esc_html_e( 'Omit the view', 'jackson-brain-ampache' ); ?></option>
							<option value="fallback" <?php selected( $settings['unavailable_mode'], 'fallback' ); ?>><?php esc_html_e( 'Show a neutral fallback', 'jackson-brain-ampache' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="jba_unavailable_fallback_text"><?php esc_html_e( 'Fallback text', 'jackson-brain-ampache' ); ?></label></th>
					<td><input type="text" id="jba_unavailable_fallback_text" name="jba_settings[unavailable_fallback_text]" value="<?php echo esc_attr( $settings['unavailable_fallback_text'] ); ?>" class="regular-text" /></td>
				</tr>
				<?php
				self::render_checkbox( 'show_artwork', __( 'Show artwork', 'jackson-brain-ampache' ), $settings );
				self::render_checkbox( 'show_timestamps', __( 'Show timestamps', 'jackson-brain-ampache' ), $settings );
				self::render_checkbox( 'show_usernames', __( 'Show Ampache usernames (public disclosure)', 'jackson-brain-ampache' ), $settings );
				self::render_checkbox( 'show_client_names', __( 'Show client names (public disclosure)', 'jackson-brain-ampache' ), $settings );
				self::render_checkbox( 'ampache_link_enabled', __( 'Link to the Ampache site', 'jackson-brain-ampache' ), $settings );
				self::render_checkbox( 'delete_data_on_uninstall', __( 'Delete all plugin data on uninstall', 'jackson-brain-ampache' ), $settings );
				?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function render_checkbox( string $key, string $label, array $settings ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="jba_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
					<?php esc_html_e( 'Enabled', 'jackson-brain-ampache' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	private static function render_actions_form(): void {
		$actions = array(
			self::ACTION_TEST_CONNECTION => __( 'Test connection', 'jackson-brain-ampache' ),
			self::ACTION_REFRESH_NOW     => __( 'Refresh now', 'jackson-brain-ampache' ),
			self::ACTION_CLEAR_SNAPSHOT  => __( 'Clear cached snapshot', 'jackson-brain-ampache' ),
		);
		?>
		<h2><?php esc_html_e( 'Actions', 'jackson-brain-ampache' ); ?></h2>
		<p>
			<?php foreach ( $actions as $action => $label ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
					<?php submit_button( $label, 'secondary', 'submit', false ); ?>
				</form>
			<?php endforeach; ?>
		</p>
		<?php
	}

	private static function render_status_table(): void {
		$status = Snapshot_Repository::get_status();
		?>
		<h2><?php esc_html_e( 'Section status', 'jackson-brain-ampache' ); ?></h2>
		<table class="widefat" style="max-width:640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Section', 'jackson-brain-ampache' ); ?></th>
					<th><?php esc_html_e( 'State', 'jackson-brain-ampache' ); ?></th>
					<th><?php esc_html_e( 'Last success', 'jackson-brain-ampache' ); ?></th>
					<th><?php esc_html_e( 'Consecutive failures', 'jackson-brain-ampache' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array( 'now_playing', 'stats', 'recent' ) as $section ) : ?>
					<tr>
						<td><?php echo esc_html( $section ); ?></td>
						<td><?php echo esc_html( Snapshot_Repository::section_state( $section ) ); ?></td>
						<td>
							<?php
							$last_success = (int) ( $status[ $section ]['last_success'] ?? 0 );
							echo 0 === $last_success
								? esc_html__( 'never', 'jackson-brain-ampache' )
								: esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_success ) );
							?>
						</td>
						<td><?php echo esc_html( (string) (int) ( $status[ $section ]['consecutive_failures'] ?? 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function credential_source_label(): string {
		switch ( Settings::credential_source() ) {
			case 'constant':
				return __( 'Constant (wp-config.php secret file)', 'jackson-brain-ampache' );

			case 'database':
				return __( 'Database (development/staging only)', 'jackson-brain-ampache' );

			default:
				return __( 'Not configured', 'jackson-brain-ampache' );
		}
	}

	public static function render_notices(): void {
		if ( ! isset( $_GET['page'], $_GET['jba_notice'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['jba_notice'] ) );

		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}

		[ $type, $message ] = self::NOTICES[ $code ];
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html__( $message, 'jackson-brain-ampache' ); ?></p>
		</div>
		<?php
	}

	// ---- Manual action handlers ----

	public static function handle_test_connection(): void {
		self::verify_request();

		if ( Ampache_Client::manual_action_cooldown_remaining() > 0 ) {
			self::redirect_with_notice( 'cooldown' );
		}

		Ampache_Client::record_manual_action();

		$result = Ampache_Client::run_in_manual_context(
			static function () {
				return Ampache_Client::request( 'ping_stats' );
			}
		);

		// A bad/revoked API key does not make `ping` return an error - it silently falls
		// back to the same shape as an unauthenticated request (confirmed live during
		// Gate A). `username` is only ever present once authentication actually succeeded,
		// so its absence is the real signal here, not is_wp_error() alone.
		$authenticated = ! is_wp_error( $result ) && ! empty( $result['username'] );

		self::redirect_with_notice( $authenticated ? 'connection_ok' : 'connection_failed' );
	}

	public static function handle_refresh_now(): void {
		self::verify_request();

		if ( Ampache_Client::manual_action_cooldown_remaining() > 0 ) {
			self::redirect_with_notice( 'cooldown' );
		}

		Ampache_Client::record_manual_action();

		$success = Ampache_Client::run_in_manual_context(
			static function () {
				return Refresh_Service::refresh_all();
			}
		);

		self::redirect_with_notice( $success ? 'refresh_ok' : 'refresh_busy' );
	}

	public static function handle_clear_snapshot(): void {
		self::verify_request();

		Snapshot_Repository::invalidate();
		Artwork_Cache::purge_all();

		self::redirect_with_notice( 'snapshot_cleared' );
	}

	public static function handle_save_api_key(): void {
		self::verify_request();

		if ( ! Settings::api_key_is_editable() ) {
			self::redirect_with_notice( 'key_not_editable' );
		}

		if ( ! empty( $_POST['jba_remove_key'] ) ) {
			Settings::remove_api_key();
			self::redirect_with_notice( 'key_removed' );
		}

		$submitted = isset( $_POST['jba_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['jba_api_key'] ) ) : '';
		$updated   = Settings::maybe_update_api_key( $submitted );

		self::redirect_with_notice( $updated ? 'key_saved' : 'key_unchanged' );
	}

	private static function verify_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'jackson-brain-ampache' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_ACTION );
	}

	private static function redirect_with_notice( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'jba_notice' => $code,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
