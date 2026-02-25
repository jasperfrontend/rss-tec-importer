<?php
/**
 * RSS_TEC_Feed_Parser
 *
 * Fetches and parses a TEC-flavoured RSS feed, extracting the data needed
 * to create or update events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Feed_Parser {

	/**
	 * Fetch the RSS feed and return a normalised array of event items.
	 *
	 * @param string $feed_url The RSS feed URL.
	 * @return array[]|WP_Error Array of event arrays, or WP_Error on failure.
	 */
	public static function fetch_and_parse( string $feed_url ): array|WP_Error {
		$response = wp_remote_get(
			$feed_url,
			[
				'timeout'    => 15,
				'user-agent' => 'RSS-TEC-Importer/' . RSS_TEC_IMPORTER_VERSION . '; ' . get_bloginfo( 'url' ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'rss_tec_fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch feed: %s', 'rss-tec-importer' ),
					$response->get_error_message()
				)
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $http_code ) {
			return new WP_Error(
				'rss_tec_bad_response',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Feed returned HTTP %d.', 'rss-tec-importer' ),
					$http_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'rss_tec_empty_body', __( 'Feed body is empty.', 'rss-tec-importer' ) );
		}

		return self::parse( $body );
	}

	/**
	 * Parse raw XML string into an array of event data.
	 *
	 * @param string $xml_string Raw RSS XML.
	 * @return array[]|WP_Error
	 */
	private static function parse( string $xml_string ): array|WP_Error {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_string );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$msg = ! empty( $errors ) ? $errors[0]->message : 'Unknown XML error';
			return new WP_Error( 'rss_tec_xml_parse', sprintf( __( 'XML parse error: %s', 'rss-tec-importer' ), $msg ) );
		}

		// Detect content:encoded namespace.
		$ns          = $xml->getNamespaces( true );
		$content_ns  = $ns['content'] ?? null;

		$items = [];

		if ( ! isset( $xml->channel ) ) {
			return new WP_Error( 'rss_tec_no_channel', __( 'No <channel> element found in feed.', 'rss-tec-importer' ) );
		}

		foreach ( $xml->channel->item as $item ) {
			$guid        = trim( (string) $item->guid );
			$title       = wp_strip_all_tags( (string) $item->title );
			$link        = trim( (string) $item->link );
			$pub_date    = trim( (string) $item->pubDate );
			$description = (string) $item->description;

			// Prefer content:encoded for body; fall back to description.
			$encoded = $content_ns
				? (string) $item->children( $content_ns )->encoded
				: $description;

			// Parse pubDate as start datetime.
			$start_dt = DateTime::createFromFormat( DateTime::RFC2822, $pub_date );
			if ( false === $start_dt ) {
				// Try a looser parse as a fallback.
				try {
					$start_dt = new DateTime( $pub_date, wp_timezone() );
				} catch ( Exception $e ) {
					error_log( '[RSS TEC Importer] Could not parse date "' . $pub_date . '" for item: ' . $title );
					continue;
				}
			}

			// Convert to site timezone.
			$start_dt->setTimezone( wp_timezone() );

			$start_date   = $start_dt->format( 'Y-m-d' );
			$start_hour   = $start_dt->format( 'H' );
			$start_minute = $start_dt->format( 'i' );

			// Extract first image from the description (not encoded — description tends to have the img).
			$image_url = self::extract_first_image( $description ?: $encoded );

			// Strip the first <img> tag from the encoded content that will become post_content.
			$post_content = preg_replace( '/<img\s[^>]*>/i', '', $encoded, 1 );
			$post_content = trim( $post_content );

			$items[] = [
				'guid'         => $guid,
				'title'        => $title,
				'link'         => $link,
				'start_date'   => $start_date,
				'start_hour'   => $start_hour,
				'start_minute' => $start_minute,
				'post_content' => $post_content,
				'image_url'    => $image_url,
			];
		}

		return $items;
	}

	/**
	 * Extract the src of the first <img> tag in an HTML string.
	 *
	 * @param string $html HTML content.
	 * @return string Image URL or empty string.
	 */
	private static function extract_first_image( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		$dom = new DOMDocument();
		// Suppress warnings from malformed HTML; use mb-safe encoding hint.
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );

		$imgs = $dom->getElementsByTagName( 'img' );
		if ( $imgs->length > 0 ) {
			$src = $imgs->item( 0 )->getAttribute( 'src' );
			return esc_url_raw( $src );
		}

		return '';
	}
}
