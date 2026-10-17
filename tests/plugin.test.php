<?php
/**
 * Unit tests with minimal WordPress stubs. Run: php tests/plugin.test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['tallyho_test'] = array( 'filters' => array(), 'transients' => array(), 'http' => null );

function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['tallyho_test']['filters'][ $tag ][] = $cb; return true; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { return add_filter( $tag, $cb, $prio, $args ); }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['tallyho_test']['filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/tallyho/'; }
function plugin_basename( $f ) { return 'tallyho/tallyho.php'; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return esc_html( $s ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function is_ssl() { return true; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_site_transient( $k ) { return $GLOBALS['tallyho_test']['transients'][ $k ] ?? false; }
function set_site_transient( $k, $v, $t = 0 ) { $GLOBALS['tallyho_test']['transients'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['tallyho_test']['transients'][ $k ] ); return true; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['tallyho_test']['last_url'] = $url; return $GLOBALS['tallyho_test']['http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}

require __DIR__ . '/../tallyho.php';

$failed = 0;
$count  = 0;
function check( $name, $cond ) {
	global $failed, $count;
	$count++;
	if ( $cond ) {
		echo "ok   $name\n";
	} else {
		$failed++;
		echo "FAIL $name\n";
	}
}

// ---- Conversion name sanitizing -------------------------------------------
check( 'name: keeps valid names', 'estimate_form' === Tallyho_Conversion::sanitize_event_name( 'estimate_form' ) );
check( 'name: lowercases and replaces spaces', 'request_an_estimate' === Tallyho_Conversion::sanitize_event_name( ' Request an Estimate ' ) );
check( 'name: must start with a letter', '' === Tallyho_Conversion::sanitize_event_name( '123_form' ) );
check( 'name: max 40 chars', 40 >= strlen( Tallyho_Conversion::sanitize_event_name( str_repeat( 'abc_', 20 ) ) ) );
check( 'name: no trailing underscore after cut', '_' !== substr( Tallyho_Conversion::sanitize_event_name( str_repeat( 'a', 39 ) . '_b' ), -1 ) );
check( 'name: empty stays empty', '' === Tallyho_Conversion::sanitize_event_name( '' ) );

// ---- Confirmation handling --------------------------------------------------
$form  = array( 'id' => 3, 'title' => 'Request an <b>Estimate</b> "Deck"', 'tallyhoConversionName' => 'Estimate Form' );
$entry = array( 'id' => 1542, 'status' => 'active' );

$out = Tallyho_Gravity_Forms::filter_confirmation( '<div>Thanks!</div>', $form, $entry, true );
check( 'text confirmation keeps original markup', 0 === strpos( $out, '<div>Thanks!</div>' ) );
check( 'marker added with entry id', false !== strpos( $out, 'data-entry-id="1542"' ) );
check( 'marker has site key', false !== strpos( $out, 'data-site="' . substr( md5( 'example.test' ), 0, 6 ) . '"' ) );
check( 'marker has ajax delivery', false !== strpos( $out, 'data-delivery="ajax"' ) );
check( 'marker has sanitized conversion name', false !== strpos( $out, 'data-conversion-name="estimate_form"' ) );
check( 'marker escapes and strips title', false !== strpos( $out, 'data-form-title="Request an Estimate &quot;Deck&quot;"' ) );

$out = Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, $entry, false );
check( 'non-AJAX marker has page delivery', false !== strpos( $out, 'data-delivery="page"' ) );

$spam = array( 'id' => 1543, 'status' => 'spam' );
check( 'spam entry gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, $spam, true ) );
check( 'empty entry gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, array(), true ) );

$off = $form + array( 'tallyhoEnabled' => '0' );
check( 'form toggle off gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $off, $entry, true ) );
check( 'form toggle false gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', array( 'tallyhoEnabled' => false ) + $form, $entry, true ) );
check( 'form toggle on gets marker', 'Thanks' !== Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', array( 'tallyhoEnabled' => true ) + $form, $entry, true ) );

$_SERVER['HTTP_SEC_GPC'] = '1';
check( 'GPC on submission request gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, $entry, true ) );
unset( $_SERVER['HTTP_SEC_GPC'] );
$_SERVER['HTTP_DNT'] = '1';
check( 'DNT on submission request gets no marker', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, $entry, true ) );
unset( $_SERVER['HTTP_DNT'] );

$redirect = array( 'redirect' => 'https://example.test/thank-you/' );
check( 'redirect confirmation returned unchanged', $redirect === Tallyho_Gravity_Forms::filter_confirmation( $redirect, $form, $entry, true ) );

$p = Tallyho_Conversion::payload( 'gravityforms', 3, 777, 'A | B & C', 'estimate_form', 'redirect' );
$site = substr( md5( 'example.test' ), 0, 6 );
check( 'site key is short host hash', $site === Tallyho_Conversion::site_key() );
check( 'cookie value format', "v1|gravityforms|$site|3|777|estimate_form|A%20%7C%20B%20%26%20C" === Tallyho_Conversion::cookie_value( $p ) );
check( 'cookie value only cookie-safe chars', 1 === preg_match( '/^[A-Za-z0-9._~%|-]+$/', Tallyho_Conversion::cookie_value( $p ) ) );

add_filter( 'tallyho_should_track', function () { return false; } );
add_filter( 'tallyho_site_key', function () { return 'Prod-Site!'; } );
check( 'site key filter is sanitized', 'prodsite' === Tallyho_Conversion::site_key() );
$GLOBALS['tallyho_test']['filters']['tallyho_site_key'] = array();

check( 'tallyho_should_track filter can veto', 'Thanks' === Tallyho_Gravity_Forms::filter_confirmation( 'Thanks', $form, $entry, true ) );
$GLOBALS['tallyho_test']['filters']['tallyho_should_track'] = array();

// ---- Updater ----------------------------------------------------------------
function release( array $over = array() ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array_merge(
				array(
					'tag_name'     => 'v1.2.0',
					'draft'        => false,
					'prerelease'   => false,
					'published_at' => '2026-10-20T12:00:00Z',
					'body'         => "- Fixed a thing\n\nRequires at least: 6.0\nRequires PHP: 7.4",
					'assets'       => array(
						array(
							'name'                 => 'tallyho.zip',
							'browser_download_url' => 'https://github.com/lifexmarketing/tallyho/releases/download/v1.2.0/tallyho.zip',
						),
					),
				),
				$over
			)
		),
	);
}

$GLOBALS['tallyho_test']['http'] = release();
$u = Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() );
check( 'updater: returns update for our plugin', is_array( $u ) && '1.2.0' === $u['version'] );
check( 'updater: package is the release asset', is_array( $u ) && false !== strpos( $u['package'], '/releases/download/v1.2.0/tallyho.zip' ) );
check( 'updater: reads requirement lines', is_array( $u ) && '7.4' === $u['requires_php'] && '6.0' === $u['requires'] );
check( 'updater: hits the releases/latest endpoint', 'https://api.github.com/repos/lifexmarketing/tallyho/releases/latest' === $GLOBALS['tallyho_test']['last_url'] );
check( 'updater: ignores other plugins', 'untouched' === Tallyho_Updater::check_update( 'untouched', array(), 'trace-ids/trace-ids.php', array() ) );

$GLOBALS['tallyho_test']['http'] = null; // would fail if not cached
$u2 = Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() );
check( 'updater: second check uses cache', is_array( $u2 ) && '1.2.0' === $u2['version'] );

Tallyho_Updater::clear_cache();
$GLOBALS['tallyho_test']['http'] = release( array( 'prerelease' => true ) );
check( 'updater: ignores pre-releases', false === Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() ) );

Tallyho_Updater::clear_cache();
$GLOBALS['tallyho_test']['http'] = release( array( 'assets' => array() ) );
check( 'updater: ignores releases without the zip asset', false === Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() ) );

Tallyho_Updater::clear_cache();
$GLOBALS['tallyho_test']['http'] = release(
	array(
		'assets' => array(
			array(
				'name'                 => 'tallyho.zip',
				'browser_download_url' => 'https://evil.example/tallyho.zip',
			),
		),
	)
);
check( 'updater: rejects packages hosted outside the repo', false === Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() ) );

Tallyho_Updater::clear_cache();
$GLOBALS['tallyho_test']['http'] = array( 'response' => array( 'code' => 404 ), 'body' => '' );
check( 'updater: no release yet means no update', false === Tallyho_Updater::check_update( false, array(), 'tallyho/tallyho.php', array() ) );
check( 'updater: failure is cached briefly', array() === $GLOBALS['tallyho_test']['transients']['tallyho_github_release'] );

Tallyho_Updater::clear_cache();
$GLOBALS['tallyho_test']['http'] = release();
$info = Tallyho_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'tallyho' ) );
check( 'updater: details modal has changelog', is_object( $info ) && false !== strpos( $info->sections['changelog'], 'Fixed a thing' ) );
check( 'updater: details modal hides requirement lines', is_object( $info ) && false === strpos( $info->sections['changelog'], 'Requires PHP' ) );
check( 'updater: details ignores other slugs', false === Tallyho_Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );

echo $failed ? "$failed failed\n" : "all $count passed\n";
exit( $failed ? 1 : 0 );
