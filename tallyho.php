<?php
/**
 * Plugin Name:       Tallyho Form Tracking
 * Plugin URI:        https://github.com/lifexmarketing/tallyho
 * Description:       Pushes one deduplicated dataLayer event per genuine Gravity Forms submission (spam excluded, privacy signals honored) for GTM to route to GA4, Google Ads and Meta.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LifeX Marketing
 * Author URI:        https://lifexmarketing.com
 * Copyright:         (C) 2026 LifeX Marketing
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       tallyho
 * Update URI:        https://github.com/lifexmarketing/tallyho
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Another copy of Tallyho is already loaded (for example a second folder from a
// source-code zip). Stop here instead of redeclaring classes.
if ( defined( 'TALLYHO_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Two copies of Tallyho Form Tracking are active. Deactivate and delete the one not in the "tallyho" folder.', 'tallyho' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'TALLYHO_VERSION', '1.0.0' );
define( 'TALLYHO_FILE', __FILE__ );
define( 'TALLYHO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TALLYHO_URL', plugin_dir_url( __FILE__ ) );
define( 'TALLYHO_GITHUB_REPO', 'lifexmarketing/tallyho' );

// Shared LifeX privacy-signal helper. Guarded with function_exists, so it is
// safe alongside the standalone LifeX Tracking Opt-Out Helper plugin.
require_once TALLYHO_DIR . 'includes/privacy/is-tracking-opt-out.php';
require_once TALLYHO_DIR . 'includes/class-tallyho-conversion.php';
require_once TALLYHO_DIR . 'includes/class-tallyho-gravity-forms.php';
require_once TALLYHO_DIR . 'includes/class-tallyho-frontend.php';
require_once TALLYHO_DIR . 'includes/class-tallyho-updater.php';

Tallyho_Frontend::init();
Tallyho_Gravity_Forms::init();
Tallyho_Updater::init( TALLYHO_FILE, TALLYHO_GITHUB_REPO, TALLYHO_VERSION );
