<?php
/**
 * RSS_TEC_Updater
 *
 * Checks the GitHub Releases API for new versions and injects update data
 * into WordPress's built-in plugin update system. When a new release is
 * available, WordPress shows the standard "Update available" notice in
 * Plugins > Installed Plugins and allows one-click installation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSS_TEC_Updater {

	const GITHUB_REPO   = 'jasperfrontend/rss-tec-importer';
	const TRANSIENT_KEY = 'rss_tec_github_release';
	const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_for_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
	}

	/**
	 * Inject update data into the WordPress update transient when a newer
	 * GitHub release exists.
	 *
	 * @param object $transient The current update_plugins transient.
	 * @return object Modified transient.
	 */
	public static function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( RSS_TEC_IMPORTER_VERSION, $release['version'], '<' ) ) {
			$plugin_file = plugin_basename( RSS_TEC_IMPORTER_FILE );

			$transient->response[ $plugin_file ] = (object) [
				'id'           => 'github.com/' . self::GITHUB_REPO,
				'slug'         => 'rss-tec-importer',
				'plugin'       => $plugin_file,
				'new_version'  => $release['version'],
				'url'          => 'https://github.com/' . self::GITHUB_REPO,
				'package'      => $release['download_url'],
				'icons'        => [],
				'banners'      => [],
				'requires'     => '6.0',
				'requires_php' => '8.0',
				'tested'       => '',
			];
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View version X.Y.Z details" popup.
	 *
	 * @param false|object|array $result Default false.
	 * @param string             $action API action being performed.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_info( false|object|array $result, string $action, object $args ): false|object|array {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== 'rss-tec-importer' ) {
			return $result;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'RSS TEC Importer',
			'slug'          => 'rss-tec-importer',
			'version'       => $release['version'],
			'author'        => 'Jasper',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'download_link' => $release['download_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'sections'      => [
				'description' => __( 'Imports events from an RSS feed (The Events Calendar format) into The Events Calendar on this site.', 'rss-tec-importer' ),
				'changelog'   => ! empty( $release['changelog'] )
					? '<pre>' . esc_html( $release['changelog'] ) . '</pre>'
					: '',
			],
		];
	}

	/**
	 * Fetch the latest GitHub release with transient caching.
	 *
	 * @return array{version: string, download_url: string, changelog: string}|false
	 */
	private static function get_latest_release(): array|false {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			[
				'timeout'    => 10,
				'user-agent' => 'RSS-TEC-Importer/' . RSS_TEC_IMPORTER_VERSION . '; ' . get_bloginfo( 'url' ),
				'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) {
			return false;
		}

		// Find the plugin zip in the release assets.
		$download_url = '';
		foreach ( $body['assets'] ?? [] as $asset ) {
			if ( str_ends_with( $asset['name'], '.zip' ) ) {
				$download_url = $asset['browser_download_url'];
				break;
			}
		}

		if ( empty( $download_url ) ) {
			return false;
		}

		$release = [
			'version'      => ltrim( $body['tag_name'], 'v' ),
			'download_url' => $download_url,
			'changelog'    => $body['body'] ?? '',
		];

		set_transient( self::TRANSIENT_KEY, $release, self::TRANSIENT_TTL );

		return $release;
	}
}
