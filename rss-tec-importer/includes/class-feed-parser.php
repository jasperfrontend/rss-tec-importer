<?php
/**
 * RSS_TEC_Logger
 *
 * Tiny static log collector. Every component writes to it during a manual
 * import; the admin page reads and displays the entries after the redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Logger {

	private static array $entries = [];

	public static function info( string $msg, array $data = [] ): void {
		self::add( 'info', $msg, $data );
	}

	public static function success( string $msg, array $data = [] ): void {
		self::add( 'success', $msg, $data );
	}

	public static function warning( string $msg, array $data = [] ): void {
		self::add( 'warning', $msg, $data );
	}

	public static function error( string $msg, array $data = [] ): void {
		self::add( 'error', $msg, $data );
	}

	private static function add( string $level, string $msg, array $data ): void {
		self::$entries[] = [
			'level'   => $level,
			'message' => $msg,
			'data'    => $data,
		];
	}

	public static function get(): array {
		return self::$entries;
	}

	public static function clear(): void {
		self::$entries = [];
	}
}

// ---------------------------------------------------------------------------

/**
 * RSS_TEC_Feed_Parser
 *
 * Fetches and parses a TEC-flavoured RSS feed, extracting the data needed
 * to create or update events.
 */
class RSS_TEC_Feed_Parser {

	/**
	 * Fetch the RSS feed and return a normalised array of event items.
	 *
	 * @param string $feed_url The RSS feed URL.
	 * @return array[]|WP_Error Array of event arrays, or WP_Error on failure.
	 */
	public static function fetch_and_parse( string $feed_url ): array|WP_Error {
		RSS_TEC_Logger::info( 'Fetching feed', [ 'url' => $feed_url ] );

		$response = wp_remote_get(
			$feed_url,
			[
				'timeout'    => 15,
				'user-agent' => 'RSS-TEC-Importer/' . RSS_TEC_IMPORTER_VERSION . '; ' . get_bloginfo( 'url' ),
			]
		);

		if ( is_wp_error( $response ) ) {
			RSS_TEC_Logger::error( 'wp_remote_get failed', [ 'error' => $response->get_error_message() ] );
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
		RSS_TEC_Logger::info( 'HTTP response', [ 'code' => $http_code ] );

		if ( 200 !== (int) $http_code ) {
			RSS_TEC_Logger::error( 'Non-200 HTTP response', [ 'code' => $http_code ] );
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
		RSS_TEC_Logger::info( 'Feed body received', [ 'bytes' => strlen( $body ) ] );

		if ( empty( $body ) ) {
			RSS_TEC_Logger::error( 'Feed body is empty' );
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
		// Strip duplicate xmlns: declarations from the root <rss> element.
		// A feed is invalid XML if the same namespace prefix is declared more
		// than once on the same element (e.g. two WordPress hooks both outputting
		// xmlns:ev=). libxml rejects such a document outright. We keep the first
		// occurrence of each prefix and silently drop the rest before parsing.
		$xml_string = preg_replace_callback(
			'/(<rss\b[^>]*>)/s',
			static function ( array $m ) {
				$seen = [];
				return preg_replace_callback(
					'/\s+xmlns:(\w+)="[^"]*"/',
					static function ( array $attr ) use ( &$seen ) {
						$prefix = $attr[1];
						if ( isset( $seen[ $prefix ] ) ) {
							return ''; // drop the duplicate
						}
						$seen[ $prefix ] = true;
						return $attr[0];
					},
					$m[1]
				);
			},
			$xml_string
		);

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_string );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$msg = ! empty( $errors ) ? $errors[0]->message : 'Unknown XML error';
			RSS_TEC_Logger::error( 'XML parse failed', [ 'libxml_error' => $msg ] );
			return new WP_Error( 'rss_tec_xml_parse', sprintf( __( 'XML parse error: %s', 'rss-tec-importer' ), $msg ) );
		}

		// Detect namespaces used in this feed.
		$ns         = $xml->getNamespaces( true );
		$content_ns = $ns['content'] ?? null;

		RSS_TEC_Logger::info( 'Namespaces detected', $ns );

		$items = [];

		if ( ! isset( $xml->channel ) ) {
			RSS_TEC_Logger::error( 'No <channel> element found in feed' );
			return new WP_Error( 'rss_tec_no_channel', __( 'No <channel> element found in feed.', 'rss-tec-importer' ) );
		}

		$item_count = iterator_count( $xml->channel->item );
		RSS_TEC_Logger::info( 'Items found in feed', [ 'count' => $item_count ] );

		foreach ( $xml->channel->item as $item ) {
			$guid        = trim( (string) $item->guid );
			$title       = wp_strip_all_tags( (string) $item->title );
			$link        = trim( (string) $item->link );
			$pub_date    = trim( (string) $item->pubDate );
			$description = (string) $item->description;

			RSS_TEC_Logger::info( '── Processing item', [
				'title'    => $title,
				'guid'     => $guid,
				'pubDate'  => $pub_date,
			] );

			// Prefer content:encoded for body; fall back to description.
			$encoded = $content_ns
				? (string) $item->children( $content_ns )->encoded
				: $description;

			// --- Extract ev:startdate / ev:enddate from raw item XML ---
			// We use regex on the raw XML rather than SimpleXML namespace-aware
			// methods because namespace resolution is unreliable when the source
			// feed has conflicting xmlns:ev declarations (e.g. a legacy snippet
			// that redeclares ev: locally). The pattern only matches ISO 8601
			// datetimes (YYYY-MM-DDTHH:MM:SS±...) so it ignores any locale-
			// formatted strings that older snippet configurations might produce.
			$raw_item     = $item->asXML();
			$ev_start_str = '';
			$ev_end_str   = '';

			// Log the raw ev: section so we can see exactly what SimpleXML received.
			$ev_pos     = strpos( $raw_item, '<ev:' );
			$ev_snippet = ( false !== $ev_pos )
				? substr( $raw_item, $ev_pos, min( 600, strlen( $raw_item ) - $ev_pos ) )
				: '(no <ev: tags found anywhere in this item\'s raw XML)';

			RSS_TEC_Logger::info( 'Raw ev: XML section', [ 'snippet' => $ev_snippet ] );

			if ( preg_match( '#<ev:startdate[^>]*>(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^<]+)</ev:startdate>#', $raw_item, $m ) ) {
				$ev_start_str = trim( $m[1] );
				RSS_TEC_Logger::success( 'ev:startdate regex matched', [ 'value' => $ev_start_str ] );
			} else {
				RSS_TEC_Logger::warning( 'ev:startdate regex did NOT match — will fall back to pubDate' );
			}

			if ( preg_match( '#<ev:enddate[^>]*>(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^<]+)</ev:enddate>#', $raw_item, $m ) ) {
				$ev_end_str = trim( $m[1] );
				RSS_TEC_Logger::success( 'ev:enddate regex matched', [ 'value' => $ev_end_str ] );
			} else {
				RSS_TEC_Logger::warning( 'ev:enddate regex did NOT match — importer will use configured duration' );
			}

			// --- Start date ---
			// Prefer ev:startdate; fall back to pubDate (RFC 2822).
			$start_dt = null;

			if ( $ev_start_str ) {
				$start_dt = date_create( $ev_start_str ) ?: null;
				if ( $start_dt ) {
					RSS_TEC_Logger::info( 'Start date parsed from ev:startdate', [ 'raw' => $ev_start_str ] );
				} else {
					RSS_TEC_Logger::error( 'date_create() failed for ev:startdate', [ 'raw' => $ev_start_str ] );
				}
			}

			if ( ! $start_dt ) {
				$start_dt = DateTime::createFromFormat( DateTime::RFC2822, $pub_date ) ?: null;
				if ( $start_dt ) {
					RSS_TEC_Logger::info( 'Start date parsed from pubDate (RFC2822)', [ 'raw' => $pub_date ] );
				}
			}

			if ( ! $start_dt ) {
				try {
					$start_dt = new DateTime( $pub_date, wp_timezone() );
					RSS_TEC_Logger::info( 'Start date parsed from pubDate (loose)', [ 'raw' => $pub_date ] );
				} catch ( Exception $e ) {
					RSS_TEC_Logger::error( 'Could not parse date at all — skipping item', [
						'pubDate' => $pub_date,
						'error'   => $e->getMessage(),
					] );
					error_log( '[RSS TEC Importer] Could not parse date "' . $pub_date . '" for item: ' . $title );
					continue;
				}
			}

			$start_dt->setTimezone( wp_timezone() );

			$start_date   = $start_dt->format( 'Y-m-d' );
			$start_hour   = $start_dt->format( 'H' );
			$start_minute = $start_dt->format( 'i' );

			RSS_TEC_Logger::info( 'Final start datetime (site timezone)', [
				'date'   => $start_date,
				'hour'   => $start_hour,
				'minute' => $start_minute,
			] );

			// --- End date ---
			// Use ev:enddate when present; null tells the importer to apply
			// the configured duration offset instead.
			$end_dt = null;

			if ( $ev_end_str ) {
				$parsed = date_create( $ev_end_str );
				if ( $parsed ) {
					$end_dt = $parsed;
					$end_dt->setTimezone( wp_timezone() );
					RSS_TEC_Logger::success( 'End date parsed from ev:enddate', [
						'raw'    => $ev_end_str,
						'date'   => $end_dt->format( 'Y-m-d' ),
						'hour'   => $end_dt->format( 'H' ),
						'minute' => $end_dt->format( 'i' ),
					] );
				} else {
					RSS_TEC_Logger::error( 'date_create() failed for ev:enddate', [ 'raw' => $ev_end_str ] );
				}
			} else {
				RSS_TEC_Logger::warning( 'No ev:enddate available — end_date will be null in item array; importer uses configured duration' );
			}

			// Extract first image from the description (not encoded — description tends to have the img).
			$image_url = self::extract_first_image( $description ?: $encoded );

			// Strip the first <img> tag from the encoded content that will become post_content.
			$post_content = preg_replace( '/<img\s[^>]*>/i', '', $encoded, 1 );
			$post_content = trim( $post_content );

			$item_data = [
				'guid'         => $guid,
				'title'        => $title,
				'link'         => $link,
				'start_date'   => $start_date,
				'start_hour'   => $start_hour,
				'start_minute' => $start_minute,
				// These three are null when no ev:enddate is in the feed;
				// the importer will compute end time from the configured duration.
				'end_date'     => $end_dt ? $end_dt->format( 'Y-m-d' ) : null,
				'end_hour'     => $end_dt ? $end_dt->format( 'H' )     : null,
				'end_minute'   => $end_dt ? $end_dt->format( 'i' )     : null,
				'post_content' => $post_content,
				'image_url'    => $image_url,
			];

			RSS_TEC_Logger::info( 'Item array being passed to importer', array_diff_key( $item_data, [ 'post_content' => 1 ] ) );

			$items[] = $item_data;
		}

		RSS_TEC_Logger::info( 'Parser finished', [ 'items_returned' => count( $items ) ] );

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
