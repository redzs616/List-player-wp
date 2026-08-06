<?php
/**
 * Plugin Name:       Lejátszási Lista Player
 * Description:       Kategóriákba rendezett zenelejátszó, nyilvános lejátszás- és like-statisztikával.
 * Version:           0.5.0
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

define( 'PLP_VERSION', '0.5.0' );
define( 'PLP_DB_VERSION', '1' );
define( 'PLP_FILE', __FILE__ );
define( 'PLP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLP_URL', plugin_dir_url( __FILE__ ) );

require_once PLP_PATH . 'includes/functions.php';
require_once PLP_PATH . 'includes/class-plp-activator.php';
require_once PLP_PATH . 'includes/class-plp-post-types.php';
require_once PLP_PATH . 'includes/class-plp-meta.php';
require_once PLP_PATH . 'includes/class-plp-importer.php';
require_once PLP_PATH . 'includes/class-plp-source.php';
require_once PLP_PATH . 'includes/class-plp-visitor.php';
require_once PLP_PATH . 'includes/class-plp-stats.php';
require_once PLP_PATH . 'includes/class-plp-rest.php';
require_once PLP_PATH . 'includes/class-plp-renderer.php';
require_once PLP_PATH . 'includes/class-plp-shortcode.php';
require_once PLP_PATH . 'includes/class-plp-updater.php';

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
	PLP_Stats::init();
	PLP_Rest::init();
	PLP_Renderer::init();
	PLP_Shortcode::init();

	// Deliberately outside the admin branch: WordPress also checks for plugin updates
	// from WP-Cron, which does not run in an admin context, and background auto-updates
	// would otherwise never see our package.
	PLP_Updater::init();

	if ( is_admin() ) {
		require_once PLP_PATH . 'admin/class-plp-admin.php';
		require_once PLP_PATH . 'admin/class-plp-import-page.php';
		require_once PLP_PATH . 'admin/class-plp-settings-page.php';

		PLP_Admin::init();
		PLP_Import_Page::init();
		PLP_Settings_Page::init();
	}

	PLP_Activator::maybe_upgrade();
}
add_action( 'plugins_loaded', 'plp_bootstrap' );
