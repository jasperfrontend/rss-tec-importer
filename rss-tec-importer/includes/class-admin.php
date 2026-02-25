<?php
/**
 * RSS_TEC_Admin
 *
 * Registers the Settings API page, handles the manual import POST action,
 * and renders import-result notices.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Admin {

	/** Settings page slug. */
	const PAGE_SLUG = 'rss-tec-importer';

	/** Allowed values for enum settings fields. */
	private const SCHEDULE_OPTIONS = [
		'every_15_minutes',
		'hourly',
		'twicedaily',
		'daily',
		'weekly',
	];

	private const DURATION_OPTIONS = [ 1, 2, 3, 4, 8, 24 ];

	private const STATUS_OPTIONS = [ 'publish', 'draft' ];

	// ---------------------------------------------------------------------------
	// Bootstrap
	// ---------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_rss_tec_import_now', [ __CLASS__, 'handle_manual_import' ] );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_show_import_result_notice' ] );
	}

	// ---------------------------------------------------------------------------
	// Menu & page
	// ---------------------------------------------------------------------------

	public static function add_settings_page(): void {
		add_options_page(
			__( 'RSS TEC Importer', 'rss-tec-importer' ),
			__( 'RSS TEC Importer', 'rss-tec-importer' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	// ---------------------------------------------------------------------------
	// Settings API registration
	// ---------------------------------------------------------------------------

	public static function register_settings(): void {
		register_setting(
			RSS_TEC_IMPORTER_OPTION_KEY,          // option group
			RSS_TEC_IMPORTER_OPTION_KEY,          // option name
			[ 'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ] ]
		);

		// Section: Feed Settings
		add_settings_section(
			'rss_tec_feed_settings',
			__( 'Feed Settings', 'rss-tec-importer' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'feed_url',
			__( 'Feed URL', 'rss-tec-importer' ),
			[ __CLASS__, 'field_feed_url' ],
			self::PAGE_SLUG,
			'rss_tec_feed_settings'
		);

		add_settings_field(
			'cron_schedule',
			__( 'Import Schedule', 'rss-tec-importer' ),
			[ __CLASS__, 'field_cron_schedule' ],
			self::PAGE_SLUG,
			'rss_tec_feed_settings'
		);

		// Section: Event Defaults
		add_settings_section(
			'rss_tec_event_defaults',
			__( 'Event Defaults', 'rss-tec-importer' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'event_duration',
			__( 'Default Event Duration', 'rss-tec-importer' ),
			[ __CLASS__, 'field_event_duration' ],
			self::PAGE_SLUG,
			'rss_tec_event_defaults'
		);

		add_settings_field(
			'post_status',
			__( 'Post Status', 'rss-tec-importer' ),
			[ __CLASS__, 'field_post_status' ],
			self::PAGE_SLUG,
			'rss_tec_event_defaults'
		);

		add_settings_field(
			'update_existing',
			__( 'On Re-import', 'rss-tec-importer' ),
			[ __CLASS__, 'field_update_existing' ],
			self::PAGE_SLUG,
			'rss_tec_event_defaults'
		);
	}

	/**
	 * Sanitize and validate settings before saving.
	 *
	 * @param array $raw Raw input from the form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( array $raw ): array {
		$clean = [];

		$clean['feed_url'] = esc_url_raw( $raw['feed_url'] ?? '' );

		$schedule = $raw['cron_schedule'] ?? 'daily';
		$clean['cron_schedule'] = in_array( $schedule, self::SCHEDULE_OPTIONS, true ) ? $schedule : 'daily';

		$duration = absint( $raw['event_duration'] ?? 1 );
		$clean['event_duration'] = in_array( $duration, self::DURATION_OPTIONS, true ) ? $duration : 1;

		$status = $raw['post_status'] ?? 'draft';
		$clean['post_status'] = in_array( $status, self::STATUS_OPTIONS, true ) ? $status : 'draft';

		$clean['update_existing'] = ! empty( $raw['update_existing'] ) ? 1 : 0;

		return $clean;
	}

	// ---------------------------------------------------------------------------
	// Field renderers
	// ---------------------------------------------------------------------------

	public static function field_feed_url(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$value    = esc_attr( $settings['feed_url'] ?? '' );
		echo '<input type="url" id="feed_url" name="' . esc_attr( RSS_TEC_IMPORTER_OPTION_KEY ) . '[feed_url]"'
			. ' value="' . $value . '" class="regular-text" placeholder="https://example.com/events/feed" />';
		echo '<p class="description">' . esc_html__( 'The Events Calendar RSS feed URL from the source site.', 'rss-tec-importer' ) . '</p>';
	}

	public static function field_cron_schedule(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$current  = $settings['cron_schedule'] ?? 'daily';

		$options = [
			'every_15_minutes' => __( 'Every 15 Minutes', 'rss-tec-importer' ),
			'hourly'           => __( 'Hourly', 'rss-tec-importer' ),
			'twicedaily'       => __( 'Twice Daily', 'rss-tec-importer' ),
			'daily'            => __( 'Daily', 'rss-tec-importer' ),
			'weekly'           => __( 'Weekly', 'rss-tec-importer' ),
		];

		echo '<select id="cron_schedule" name="' . esc_attr( RSS_TEC_IMPORTER_OPTION_KEY ) . '[cron_schedule]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function field_event_duration(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$current  = (int) ( $settings['event_duration'] ?? 1 );

		$options = [
			1  => __( '1 hour', 'rss-tec-importer' ),
			2  => __( '2 hours', 'rss-tec-importer' ),
			3  => __( '3 hours', 'rss-tec-importer' ),
			4  => __( '4 hours', 'rss-tec-importer' ),
			8  => __( '8 hours', 'rss-tec-importer' ),
			24 => __( '24 hours (all day)', 'rss-tec-importer' ),
		];

		echo '<select id="event_duration" name="' . esc_attr( RSS_TEC_IMPORTER_OPTION_KEY ) . '[event_duration]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used when the feed does not provide an end time.', 'rss-tec-importer' ) . '</p>';
	}

	public static function field_post_status(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$current  = $settings['post_status'] ?? 'draft';

		$options = [
			'publish' => __( 'Published', 'rss-tec-importer' ),
			'draft'   => __( 'Draft', 'rss-tec-importer' ),
		];

		echo '<select id="post_status" name="' . esc_attr( RSS_TEC_IMPORTER_OPTION_KEY ) . '[post_status]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>'
				. esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function field_update_existing(): void {
		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$checked  = ! empty( $settings['update_existing'] );

		echo '<label>';
		echo '<input type="checkbox" id="update_existing" name="'
			. esc_attr( RSS_TEC_IMPORTER_OPTION_KEY ) . '[update_existing]" value="1"'
			. checked( $checked, true, false ) . ' />';
		echo ' ' . esc_html__( 'Update existing events (uncheck to skip)', 'rss-tec-importer' );
		echo '</label>';
	}

	// ---------------------------------------------------------------------------
	// Settings page render
	// ---------------------------------------------------------------------------

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php self::render_status_box(); ?>
			<?php self::maybe_render_debug_log(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( RSS_TEC_IMPORTER_OPTION_KEY );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'rss-tec-importer' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Manual Import', 'rss-tec-importer' ); ?></h2>
			<p><?php esc_html_e( 'Run an import immediately using the current settings.', 'rss-tec-importer' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rss_tec_import_now" />
				<?php wp_nonce_field( 'rss_tec_import_now_action', 'rss_tec_import_now_nonce' ); ?>
				<?php submit_button( __( 'Import Now', 'rss-tec-importer' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the status information box.
	 */
	private static function render_status_box(): void {
		$last_run    = get_option( 'rss_tec_importer_last_run', 0 );
		$last_result = get_option( 'rss_tec_importer_last_result', [] );
		$last_error  = get_option( 'rss_tec_importer_last_error', '' );
		$next_run    = wp_next_scheduled( RSS_TEC_IMPORTER_CRON_HOOK );

		?>
		<div class="notice notice-info is-dismissible" style="padding: 12px 16px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Import Status', 'rss-tec-importer' ); ?></h3>
			<ul style="margin: 0; list-style: disc inside;">
				<li>
					<strong><?php esc_html_e( 'Last run:', 'rss-tec-importer' ); ?></strong>
					<?php
					if ( $last_run ) {
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time ago */
								__( '%s ago', 'rss-tec-importer' ),
								human_time_diff( $last_run, time() )
							)
						);
					} else {
						esc_html_e( 'Never', 'rss-tec-importer' );
					}
					?>
				</li>

				<?php if ( ! empty( $last_result ) ) : ?>
				<li>
					<strong><?php esc_html_e( 'Last result:', 'rss-tec-importer' ); ?></strong>
					<?php
					printf(
						/* translators: 1: created count, 2: updated count, 3: skipped count */
						esc_html__( '%1$d created, %2$d updated, %3$d skipped', 'rss-tec-importer' ),
						(int) ( $last_result['created'] ?? 0 ),
						(int) ( $last_result['updated'] ?? 0 ),
						(int) ( $last_result['skipped'] ?? 0 )
					);
					?>
				</li>
				<?php endif; ?>

				<li>
					<strong><?php esc_html_e( 'Next scheduled run:', 'rss-tec-importer' ); ?></strong>
					<?php
					if ( $next_run ) {
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time from now */
								__( 'in %s', 'rss-tec-importer' ),
								human_time_diff( time(), $next_run )
							)
						);
					} else {
						esc_html_e( 'Not scheduled', 'rss-tec-importer' );
					}
					?>
				</li>

				<?php if ( $last_error ) : ?>
				<li>
					<strong style="color: #d63638;"><?php esc_html_e( 'Last error:', 'rss-tec-importer' ); ?></strong>
					<span style="color: #d63638;"><?php echo esc_html( $last_error ); ?></span>
				</li>
				<?php endif; ?>
			</ul>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Debug log display
	// ---------------------------------------------------------------------------

	private static function maybe_render_debug_log(): void {
		$log = get_transient( 'rss_tec_debug_log' );
		if ( empty( $log ) ) {
			return;
		}
		delete_transient( 'rss_tec_debug_log' );

		$colors = [
			'info'    => [ 'bg' => '#f0f6fc', 'border' => '#72aee6', 'label' => '#2271b1' ],
			'success' => [ 'bg' => '#edfaef', 'border' => '#68de7c', 'label' => '#1a7a34' ],
			'warning' => [ 'bg' => '#fcf9e8', 'border' => '#f0c33c', 'label' => '#8a6f0a' ],
			'error'   => [ 'bg' => '#fcf0f1', 'border' => '#f86368', 'label' => '#c02b2b' ],
		];
		?>
		<div style="margin: 20px 0;">
			<h2 style="margin-bottom: 8px;">🪲 Import Debug Log</h2>
			<p style="margin-top: 0; color: #666;">Generated by the last manual import. Disappears on next page load.</p>
			<div style="
				font-family: monospace;
				font-size: 12px;
				line-height: 1.6;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				overflow: auto;
				max-height: 600px;
				background: #fff;
				padding: 0;
			">
				<?php foreach ( $log as $i => $entry ) :
					$level  = $entry['level'] ?? 'info';
					$c      = $colors[ $level ] ?? $colors['info'];
					$is_odd = $i % 2 === 0;
					$bg     = $is_odd ? $c['bg'] : '#fff';
				?>
				<div style="
					display: flex;
					gap: 0;
					border-bottom: 1px solid #e0e0e0;
					background: <?php echo esc_attr( $bg ); ?>;
					border-left: 4px solid <?php echo esc_attr( $c['border'] ); ?>;
				">
					<div style="
						min-width: 70px;
						padding: 4px 8px;
						font-weight: bold;
						text-transform: uppercase;
						font-size: 10px;
						color: <?php echo esc_attr( $c['label'] ); ?>;
						display: flex;
						align-items: flex-start;
						padding-top: 6px;
						border-right: 1px solid #e0e0e0;
					"><?php echo esc_html( strtoupper( $level ) ); ?></div>

					<div style="padding: 4px 12px; flex: 1; word-break: break-word;">
						<span style="font-weight: 600;"><?php echo esc_html( $entry['message'] ); ?></span>

						<?php if ( ! empty( $entry['data'] ) ) : ?>
						<div style="margin-top: 3px; padding-left: 8px; border-left: 2px solid <?php echo esc_attr( $c['border'] ); ?>; color: #444;">
							<?php foreach ( $entry['data'] as $key => $value ) : ?>
							<div>
								<span style="color: #888;"><?php echo esc_html( $key ); ?>:</span>
								<span style="color: #1a1a1a;">
									<?php
									if ( is_array( $value ) ) {
										echo '<pre style="display:inline;margin:0;">' . esc_html( json_encode( $value, JSON_PRETTY_PRINT ) ) . '</pre>';
									} else {
										echo esc_html( (string) $value );
									}
									?>
								</span>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Manual import handler
	// ---------------------------------------------------------------------------

	public static function handle_manual_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rss-tec-importer' ) );
		}

		check_admin_referer( 'rss_tec_import_now_action', 'rss_tec_import_now_nonce' );

		RSS_TEC_Logger::clear();

		$settings = get_option( RSS_TEC_IMPORTER_OPTION_KEY, [] );
		$result   = RSS_TEC_Importer::run( $settings );

		// Persist the debug log so it survives the redirect.
		set_transient( 'rss_tec_debug_log', RSS_TEC_Logger::get(), 5 * MINUTE_IN_SECONDS );

		$redirect_args = [ 'page' => self::PAGE_SLUG, 'rss_tec_imported' => '1' ];

		if ( is_wp_error( $result ) ) {
			update_option( 'rss_tec_importer_last_error', $result->get_error_message() );
			$redirect_args['rss_tec_error'] = '1';
		} else {
			$redirect_args['created'] = $result['created'];
			$redirect_args['updated'] = $result['updated'];
			$redirect_args['skipped'] = $result['skipped'];
		}

		wp_safe_redirect(
			add_query_arg( $redirect_args, admin_url( 'options-general.php' ) )
		);
		exit;
	}

	// ---------------------------------------------------------------------------
	// Import result admin notice
	// ---------------------------------------------------------------------------

	public static function maybe_show_import_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['rss_tec_imported'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		if ( ! empty( $_GET['rss_tec_error'] ) ) {
			$last_error = get_option( 'rss_tec_importer_last_error', '' );
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo '<strong>' . esc_html__( 'Import failed:', 'rss-tec-importer' ) . '</strong> ';
			echo esc_html( $last_error );
			echo '</p></div>';
			return;
		}

		$created = (int) ( $_GET['created'] ?? 0 );
		$updated = (int) ( $_GET['updated'] ?? 0 );
		$skipped = (int) ( $_GET['skipped'] ?? 0 );

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: 1: created, 2: updated, 3: skipped */
			esc_html__( 'Import complete: %1$d created, %2$d updated, %3$d skipped.', 'rss-tec-importer' ),
			$created,
			$updated,
			$skipped
		);
		echo '</p></div>';
		// phpcs:enable
	}
}
