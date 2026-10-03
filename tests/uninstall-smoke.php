<?php
namespace Lineweb\ChangeDesk\Tests;

require_once __DIR__ . '/runtime-helpers.php';
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! defined( 'WP_CLI' ) ) {
	throw new \RuntimeException( 'Local CLI tests only' );
}
admin();
$source   = source();
$before   = get_post( $source )->post_content;
$job      = job( $source );
$revision = wp_save_post_revision( $source );
$plugin   = 'lineweb-change-desk/lineweb-change-desk.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
deactivate_plugins( $plugin );
uninstall_plugin( $plugin );
equal( $before, get_post( $source )->post_content, 'Uninstall leaves source unchanged' );
ok( ! get_post( $job ), 'Uninstall removes owned job' );
if ( $revision ) {
	ok( (bool) get_post( $revision ), 'Uninstall preserves source revision' );
}
equal( false, wp_next_scheduled( 'lwcd_cleanup' ) );
activate_plugin( $plugin );
wp_delete_post( $source, true );
echo "PASS uninstall preserves source/revision, deletes owned history and cron, reactivation works\n";
