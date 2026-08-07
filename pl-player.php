<?php
/**
 * Plugin Name:       Lejátszási Lista Player
 * Description:       Kategóriákba rendezett zenelejátszó, nyilvános lejátszás- és like-statisztikával.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pl-player
 * Domain Path:       /languages
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

define( 'PLP_VERSION', '1.1.2' );
define( 'PLP_DB_VERSION', '2' );
define( 'PLP_FILE', __FILE__ );
define( 'PLP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLP_URL', plugin_dir_url( __FILE__ ) );

require_once PLP_PATH . 'includes/functions.php';
require_once PLP_PATH . 'includes/class-plp-activator.php';
require_once PLP_PATH . 'includes/class-plp-post-types.php';
require_once PLP_PATH . 'includes/class-plp-meta.php';
require_once PLP_PATH . 'includes/class-plp-playlist.php';
require_once PLP_PATH . 'includes/class-plp-importer.php';
require_once PLP_PATH . 'includes/class-plp-source.php';
require_once PLP_PATH . 'includes/class-plp-visitor.php';
require_once PLP_PATH . 'includes/class-plp-stats.php';
require_once PLP_PATH . 'includes/class-plp-rest.php';
require_once PLP_PATH . 'includes/class-plp-renderer.php';
require_once PLP_PATH . 'includes/class-plp-shortcode.php';
require_once PLP_PATH . 'includes/class-plp-updater.php';
require_once PLP_PATH . 'includes/class-plp-divi.php';
require_once PLP_PATH . 'includes/class-plp-popup.php';
require_once PLP_PATH . 'includes/class-plp-cron.php';

register_activation_hook( PLP_FILE, array( 'PLP_Activator', 'activate' ) );
register_deactivation_hook( PLP_FILE, array( 'PLP_Activator', 'deactivate' ) );

/**
 * Boots the plugin once every other plugin is in place.
 *
 * Post types and meta only attach their own `init` hooks here, so registration
 * still happens at the right moment in the request.
 */
function plp_bootstrap() {
	load_plugin_textdomain( 'pl-player', false, dirname( plugin_basename( PLP_FILE ) ) . '/languages' );

	PLP_Post_Types::init();
	PLP_Meta::init();
	PLP_Playlist::init();
	PLP_Stats::init();
	PLP_Rest::init();
	PLP_Renderer::init();
	PLP_Shortcode::init();

	// Only attaches a hook; the module itself loads when Divi says it is ready.
	PLP_Divi::init();

	PLP_Popup::init();
	PLP_Cron::init();

	// Deliberately outside the admin branch: WordPress also checks for plugin updates
	// from WP-Cron, which does not run in an admin context, and background auto-updates
	// would otherwise never see our package.
	PLP_Updater::init();

	if ( is_admin() ) {
		require_once PLP_PATH . 'admin/class-plp-admin.php';
		require_once PLP_PATH . 'admin/class-plp-import-page.php';
		require_once PLP_PATH . 'admin/class-plp-settings-page.php';
		require_once PLP_PATH . 'admin/class-plp-stats-page.php';

		PLP_Admin::init();
		PLP_Import_Page::init();
		PLP_Settings_Page::init();
		PLP_Stats_Page::init();
	}

	PLP_Activator::maybe_upgrade();
}
add_action( 'plugins_loaded', 'plp_bootstrap' );
