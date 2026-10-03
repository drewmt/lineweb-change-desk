<?php
/**
 * Plugin Name: Lineweb Change Desk
 * Description: Review, selectively apply and safely restore business-information text changes in Gutenberg posts and pages.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author: Lineweb
 * Author URI: https://lineweb.gr/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lineweb-change-desk
 * Domain Path: /languages
 *
 * @package Lineweb_Change_Desk
 */
defined( 'ABSPATH' ) || exit;
define( 'LWCD_VERSION', '0.1.0' );
define( 'LWCD_FILE', __FILE__ );
define( 'LWCD_DIR', __DIR__ );
foreach ( array( 'scope', 'proposals', 'text-fields', 'provider', 'db', 'jobs', 'quota', 'writer', 'lifecycle', 'rest', 'admin' ) as $lwcd_module ) {
	require_once __DIR__ . '/includes/' . $lwcd_module . '.php';
}
unset( $lwcd_module );
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'lineweb-change-desk', false, dirname( plugin_basename( LWCD_FILE ) ) . '/languages' );
	}
);
add_action( 'init', array( \Lineweb\ChangeDesk\Jobs::class, 'register' ) );
add_action( 'lwcd_cleanup', array( \Lineweb\ChangeDesk\Lifecycle::class, 'cleanup' ) );
add_action( 'before_delete_post', array( \Lineweb\ChangeDesk\Lifecycle::class, 'purge_source' ) );
add_action( 'rest_api_init', array( \Lineweb\ChangeDesk\Rest::class, 'register' ) );
add_action( 'admin_menu', array( \Lineweb\ChangeDesk\Admin::class, 'menu' ) );
add_action( 'admin_enqueue_scripts', array( \Lineweb\ChangeDesk\Admin::class, 'assets' ) );
register_activation_hook( __FILE__, array( \Lineweb\ChangeDesk\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Lineweb\ChangeDesk\Lifecycle::class, 'deactivate' ) );
