<?php
/**
 * Updates from GitHub Releases through WordPress's normal update flow.
 *
 * The plugin header sets "Update URI: https://github.com/<owner>/<repo>", so
 * WordPress (5.8+) asks the update_plugins_github.com filter instead of
 * WordPress.org. This class answers it from the repo's latest published
 * release, using the zip attached to that release (built by
 * .github/workflows/release.yml) so the installed folder name never changes.
 *
 * Updates then appear on Plugins and Dashboard → Updates, work with
 * "Enable auto-updates", and install with the standard upgrader.
 *
 * @package Tallyho
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tallyho_Updater {

	const CACHE_KEY       = 'tallyho_github_release';
	const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
	const CACHE_TTL_ERROR = HOUR_IN_SECONDS;

	/** @var string */
	private static $plugin_file = '';
	/** @var string */
	private static $basename = '';
	/** @var string */
	private static $slug = '';
	/** @var string */
	private static $repo = '';
	/** @var string */
	private static $version = '';

	public static function init( string $plugin_file, string $repo, string $version ): void {
		self::$plugin_file = $plugin_file;
		self::$basename    = plugin_basename( $plugin_file );
		self::$slug        = dirname( self::$basename );
		self::$repo        = $repo;
		self::$version     = $version;

		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @param string[]    $locales
	 * @return array|false
	 */
	public static function check_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( $plugin_file !== self::$basename ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'id'           => 'github.com/' . self::$repo,
			'slug'         => self::$slug,
			'plugin'       => self::$basename,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::$repo,
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/**
	 * Powers the "View details" modal on the Plugins and Updates screens.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::$slug ) {
			return $result;
		}

		$release = self::latest_release();
		$data    = function_exists( 'get_plugin_data' ) ? get_plugin_data( self::$plugin_file, false, false ) : array();

		$info                = new stdClass();
		$info->name          = $data['Name'] ?? 'Tallyho Form Tracking';
		$info->slug          = self::$slug;
		$info->version       = $release ? $release['version'] : self::$version;
		$info->author        = $data['Author'] ?? 'LifeX Marketing';
		$info->homepage      = 'https://github.com/' . self::$repo;
		$info->requires      = $release['requires'] ?? '';
		$info->requires_php  = $release['requires_php'] ?? '';
		$info->tested        = $release['tested'] ?? '';
		$info->last_updated  = $release['published'] ?? '';
		$info->download_link = $release['package'] ?? '';
		$info->sections      = array(
			'description' => esc_html( $data['Description'] ?? '' ),
			'changelog'   => $release ? self::format_notes( $release['notes'], $release['version'] ) : '',
		);

		return $info;
	}

	/**
	 * Latest non-draft, non-prerelease GitHub release with a zip asset.
	 *
	 * @return array{version:string,package:string,notes:string,published:string,requires:string,requires_php:string,tested:string}|null
	 */
	public static function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::$repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'tallyho/' . self::$version,
				),
			)
		);

		$release = self::parse_release( $response );
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : self::CACHE_TTL_ERROR );

		return $release;
	}

	/**
	 * @param array|WP_Error $response
	 */
	public static function parse_release( $response ): ?array {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			return null;
		}

		$version = ltrim( (string) ( $body['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		$package = '';
		$expect  = self::$slug . '.zip';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && ( $asset['name'] ?? '' ) === $expect && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package || 0 !== strpos( $package, 'https://github.com/' . self::$repo . '/releases/download/' ) ) {
			return null;
		}

		$notes = (string) ( $body['body'] ?? '' );

		return array(
			'version'      => $version,
			'package'      => $package,
			'notes'        => $notes,
			'published'    => (string) ( $body['published_at'] ?? '' ),
			'requires'     => self::notes_field( $notes, 'Requires at least' ),
			'requires_php' => self::notes_field( $notes, 'Requires PHP' ),
			'tested'       => self::notes_field( $notes, 'Tested up to' ),
		);
	}

	/**
	 * Optional "Requires PHP: 7.4" style lines in the release notes.
	 */
	private static function notes_field( string $notes, string $label ): string {
		return preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':\s*([0-9.]+)\s*$/mi', $notes, $m ) ? $m[1] : '';
	}

	private static function format_notes( string $notes, string $version ): string {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $notes ) );
		$html  = '<h4>' . esc_html( $version ) . '</h4><ul>';
		foreach ( (array) $lines as $line ) {
			$line = trim( preg_replace( '/^[-*]\s+/', '', (string) $line ) );
			if ( '' !== $line && ! preg_match( '/^(Requires at least|Requires PHP|Tested up to):/i', $line ) && 0 !== strpos( $line, '#' ) ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
		}
		return $html . '</ul>';
	}

	public static function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * "Check again" on Dashboard → Updates also refreshes the GitHub lookup.
	 */
	public static function maybe_force_check(): void {
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::clear_cache();
		}
	}
}
