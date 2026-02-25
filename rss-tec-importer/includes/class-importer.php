<?php
/**
 * RSS_TEC_Importer
 *
 * Takes parsed feed items and creates or updates tribe_events posts using
 * The Events Calendar's API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Importer {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'update_option_' . RSS_TEC_IMPORTER_OPTION_KEY, [ __CLASS__, 'on_settings_saved' ], 10, 2 );
	}

	/**
	 * Run an import immediately when settings are saved (if a feed URL is set).
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value New settings.
	 */
	public static function on_settings_saved( mixed $old_value, mixed $new_value ): void {
		if ( empty( $new_value['feed_url'] ) ) {
			return;
		}

		$result = self::run( $new_value );

		if ( is_wp_error( $result ) ) {
			update_option( 'rss_tec_importer_last_error', $result->get_error_message() );
			error_log( '[RSS TEC Importer] Import on save error: ' . $result->get_error_message() );
		}
	}

	/**
	 * Run a full import based on current settings.
	 *
	 * @param array $settings Plugin settings array.
	 * @return array{created: int, updated: int, skipped: int}|WP_Error
	 */
	public static function run( array $settings ): array|WP_Error {
		$feed_url = $settings['feed_url'] ?? '';
		if ( empty( $feed_url ) ) {
			return new WP_Error( 'rss_tec_no_url', __( 'No feed URL configured.', 'rss-tec-importer' ) );
		}

		$items = RSS_TEC_Feed_Parser::fetch_and_parse( $feed_url );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$duration       = (int) ( $settings['event_duration'] ?? 1 );
		$post_status    = $settings['post_status']      ?? 'draft';
		$update_existing = ! empty( $settings['update_existing'] );

		$created = 0;
		$updated = 0;
		$skipped = 0;

		$instance = new self();

		foreach ( $items as $item ) {
			$existing_id = $instance->find_existing_event( $item['guid'] );

			// Build end datetime.
			$start_dt = DateTime::createFromFormat(
				'Y-m-d H:i',
				$item['start_date'] . ' ' . $item['start_hour'] . ':' . $item['start_minute'],
				wp_timezone()
			);

			$end_dt = clone $start_dt;
			$end_dt->modify( "+{$duration} hours" );

			$end_date   = $end_dt->format( 'Y-m-d' );
			$end_hour   = $end_dt->format( 'H' );
			$end_minute = $end_dt->format( 'i' );

			if ( $existing_id ) {
				if ( ! $update_existing ) {
					$skipped++;
					continue;
				}

				// Update the existing event.
				$result = $instance->update_event(
					$existing_id,
					$item,
					$start_dt,
					$end_dt,
					$post_status
				);

				if ( $result ) {
					$updated++;
				}
			} else {
				// Create new event.
				$post_id = $instance->create_event(
					$item,
					$end_date,
					$end_hour,
					$end_minute,
					$post_status
				);

				if ( $post_id ) {
					$created++;
				}
			}
		}

		// Persist run metadata.
		update_option( 'rss_tec_importer_last_run', time() );
		update_option( 'rss_tec_importer_last_result', compact( 'created', 'updated', 'skipped' ) );
		delete_option( 'rss_tec_importer_last_error' );

		return compact( 'created', 'updated', 'skipped' );
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Find an existing tribe_events post by source GUID.
	 *
	 * @param string $guid The RSS item GUID.
	 * @return int Post ID, or 0 if not found.
	 */
	private function find_existing_event( string $guid ): int {
		$posts = get_posts(
			[
				'post_type'      => 'tribe_events',
				'meta_key'       => '_rss_tec_source_guid',
				'meta_value'     => $guid,
				'fields'         => 'ids',
				'numberposts'    => 1,
				'post_status'    => 'any',
			]
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Create a new event via tribe_create_event().
	 *
	 * @param array  $item       Parsed feed item.
	 * @param string $end_date   End date string (Y-m-d).
	 * @param string $end_hour   End hour string (H).
	 * @param string $end_minute End minute string (i).
	 * @param string $status     WordPress post status.
	 * @return int|false Post ID on success, false on failure.
	 */
	private function create_event(
		array $item,
		string $end_date,
		string $end_hour,
		string $end_minute,
		string $status
	): int|false {
		$post_id = tribe_create_event(
			[
				'post_title'       => $item['title'],
				'post_content'     => $item['post_content'],
				'post_status'      => $status,
				'EventStartDate'   => $item['start_date'],
				'EventStartHour'   => $item['start_hour'],
				'EventStartMinute' => $item['start_minute'],
				'EventEndDate'     => $end_date,
				'EventEndHour'     => $end_hour,
				'EventEndMinute'   => $end_minute,
				'EventURL'         => $item['link'],
				'EventOrigin'      => 'rss-tec-importer',
			]
		);

		if ( ! $post_id || is_wp_error( $post_id ) ) {
			$msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'tribe_create_event returned falsy';
			error_log( '[RSS TEC Importer] Create failed for "' . $item['title'] . '": ' . $msg );
			return false;
		}

		update_post_meta( $post_id, '_rss_tec_source_guid', $item['guid'] );
		update_post_meta( $post_id, '_rss_tec_source_image_url', $item['image_url'] );

		if ( ! empty( $item['image_url'] ) ) {
			$this->sideload_image( $item['image_url'], $post_id, $item['title'] );
		}

		return (int) $post_id;
	}

	/**
	 * Update an existing event post.
	 *
	 * @param int      $post_id  Existing post ID.
	 * @param array    $item     Parsed feed item.
	 * @param DateTime $start_dt Start DateTime.
	 * @param DateTime $end_dt   End DateTime.
	 * @param string   $status   WordPress post status.
	 * @return bool
	 */
	private function update_event(
		int $post_id,
		array $item,
		DateTime $start_dt,
		DateTime $end_dt,
		string $status
	): bool {
		$result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => $item['title'],
				'post_content' => $item['post_content'],
				'post_status'  => $status,
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[RSS TEC Importer] Update failed for post ' . $post_id . ': ' . $result->get_error_message() );
			return false;
		}

		// TEC stores dates with full datetime format in meta.
		update_post_meta( $post_id, '_EventStartDate', $start_dt->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, '_EventEndDate', $end_dt->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, '_EventURL', $item['link'] );

		// Sideload image only if the URL has changed.
		$stored_image = get_post_meta( $post_id, '_rss_tec_source_image_url', true );
		if ( ! empty( $item['image_url'] ) && $item['image_url'] !== $stored_image ) {
			$this->sideload_image( $item['image_url'], $post_id, $item['title'] );
			update_post_meta( $post_id, '_rss_tec_source_image_url', $item['image_url'] );
		}

		return true;
	}

	/**
	 * Sideload a remote image and set it as the post thumbnail.
	 *
	 * @param string $url     Remote image URL.
	 * @param int    $post_id Target post ID.
	 * @param string $title   Alt text / attachment title.
	 * @return bool True on success.
	 */
	private function sideload_image( string $url, int $post_id, string $title ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( $url, $post_id, $title, 'id' );

		if ( is_wp_error( $id ) ) {
			error_log(
				'[RSS TEC Importer] Image sideload failed for post ' . $post_id . ': ' . $id->get_error_message()
			);
			return false;
		}

		set_post_thumbnail( $post_id, $id );
		return true;
	}
}
