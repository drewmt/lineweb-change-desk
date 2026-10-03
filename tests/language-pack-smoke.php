<?php
/** Synthetic language-pack fixtures, never an approved WordPress.org pack. */
namespace Lineweb\ChangeDesk\Tests;

use Lineweb\ChangeDesk\Admin;

require_once __DIR__ . '/runtime-helpers.php';
if ( ! defined( 'WP_CLI' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	throw new \RuntimeException( 'Isolated local CLI tests only' );
}
$mode = $args[0] ?? 'fallback';
if ( ! in_array( $mode, array( 'fallback', 'pack' ), true ) ) {
	throw new \RuntimeException( 'Choose fallback or pack' );
}
admin();
$domain = 'lineweb-change-desk';
ok( ! is_file( LWCD_DIR . '/languages/' . $domain . '-el.mo' ), 'Directory ZIP contains no Greek catalog' );
$owned = array();
try {
	if ( 'pack' === $mode ) {
		$directory = WP_LANG_DIR . '/plugins';
		wp_mkdir_p( $directory );
		foreach ( array(
			$domain . '-el.mo' => $domain . '-el.mo',
			$domain . '-el-' . md5( 'build/admin/index.js' ) . '.json' => $domain . '-el-lwcd-admin.json',
		) as $destination => $source ) {
			$file = $directory . '/' . $destination;
			ok( ! file_exists( $file ), 'Never overwrite an existing language pack' );
			ok( copy( WP_CONTENT_DIR . '/lineweb-catalogs/' . $source, $file ), 'Copy synthetic catalog fixture' );
			$owned[] = $file;
		}
		// Mirror the language-pack upgrader's cache invalidation after installing fixtures.
		$GLOBALS['wp_textdomain_registry']->invalidate_mo_files_cache( null, array( 'type' => 'translation', 'translations' => array( array( 'type' => 'plugin' ) ) ) );
	}
	ok( switch_to_locale( 'el' ), 'Native Greek locale is installed' );
	$text = __( 'Exact text match. No AI request.', 'lineweb-change-desk' );
	equal( 'pack' === $mode ? 'Ακριβής αντιστοίχιση κειμένου. Χωρίς κλήση AI.' : 'Exact text match. No AI request.', $text, 'Core JIT PHP translations or English fallback' );
	Admin::assets( 'toplevel_page_lineweb-change-desk' );
	$script = load_script_textdomain( 'lwcd-admin', $domain, LWCD_DIR . '/languages' );
	if ( 'pack' === $mode ) {
		ok( is_string( $script ) && str_contains( $script, 'Παλιό κείμενο' ), 'JS translations load from the standard hashed language-pack filename' );
	} else {
		equal( false, $script, 'No bundled JS translation masquerades as a directory pack' );
	}
} finally {
	restore_previous_locale();
	foreach ( $owned as $file ) {
		unlink( $file );
	}
}
echo "PASS directory {$mode}: PHP/JS standard language-pack contract, fixture cleanup\n";
