<?php
/** Synthetic demo pages only. Never overwrite existing unmarked content. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	WP_CLI::error( 'Local/development CLI only.' );
}
wp_set_current_user(
	(int) get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	)[0]
);
$lwcd_demo_pages = array(
	'lineweb-change-desk-hours'   => array( 'Lineweb demo · Studio opening hours', '<!-- wp:heading --><h2 class="wp-block-heading">Visit our studio</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Our studio is open Monday to Friday, <strong>09:00–17:00</strong>.</p><!-- /wp:paragraph -->' ),
	'lineweb-change-desk-contact' => array( 'Lineweb demo · Contact information', '<!-- wp:paragraph --><p>Need help with your website? Our team is available Monday to Friday, 09:00–17:00.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p><a href="https://example.com/contact/">Send your project brief</a> and we will get back to you.</p><!-- /wp:paragraph -->' ),
);
foreach ( $lwcd_demo_pages as $slug => [$title, $content] ) {
	$existing = get_page_by_path( $slug );
	if ( $existing && ! get_post_meta( $existing->ID, '_lwcd_synthetic_demo', true ) ) {
		WP_CLI::error( 'Unmarked content already uses the demo slug.' );
	}
	if ( ! $existing ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $title,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'Cannot create demo.' );
		}update_post_meta( $id, '_lwcd_synthetic_demo', true );
	}
}
unset( $lwcd_demo_pages );
WP_CLI::success( 'Synthetic Change Desk demo pages are ready.' );
