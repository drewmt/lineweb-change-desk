<?php
namespace Lineweb\ChangeDesk;

defined( 'ABSPATH' ) || exit;
final class Admin {
	public static function menu(): void {
		add_menu_page( __( 'Lineweb Change Desk', 'lineweb-change-desk' ), __( 'Change Desk', 'lineweb-change-desk' ), 'edit_others_posts', 'lineweb-change-desk', array( self::class, 'render' ), 'dashicons-edit-page', 59 );
	}
	public static function assets( string $hook ): void {
		if ( 'toplevel_page_lineweb-change-desk' !== $hook ) {
			return;
		}
		$url = plugin_dir_url( LWCD_FILE );
		wp_enqueue_style( 'lwcd-brand', $url . 'assets/admin.css', array(), LWCD_VERSION );
		if ( ! is_file( LWCD_DIR . '/build/admin/index.asset.php' ) ) {
			return;
		}
		$asset = require LWCD_DIR . '/build/admin/index.asset.php';
		wp_enqueue_style( 'lwcd-admin', $url . 'build/admin/style-index.css', array( 'lwcd-brand' ), LWCD_VERSION );
		wp_style_add_data( 'lwcd-admin', 'rtl', 'replace' );
		wp_enqueue_script( 'lwcd-admin', $url . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'lwcd-admin', 'lineweb-change-desk', LWCD_DIR . '/languages' );
		wp_add_inline_script(
			'lwcd-admin',
			'window.lwcdAdmin=' . wp_json_encode(
				array(
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'provider' => Provider::status(),
				)
			) . ';',
			'before'
		);
	}
	public static function render(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You cannot access this page.', 'lineweb-change-desk' ) );
		}
		?>
		<div class="wrap lineweb-suite-admin lwcd">
			<section class="lineweb-suite-admin__hero" aria-labelledby="lwcd-title">
				<div><p class="lineweb-suite-admin__eyebrow">Lineweb Change Desk</p>
					<h1 id="lwcd-title"><?php esc_html_e( 'Keep business information consistent.', 'lineweb-change-desk' ); ?></h1>
					<p class="lineweb-suite-admin__lede"><?php esc_html_e( 'New opening hours, a changed policy, an updated service. Find the old references, review each proposal and change only what you approve.', 'lineweb-change-desk' ); ?></p>
					<div class="lineweb-suite-admin__actions"><a class="lineweb-suite-admin__button lineweb-suite-admin__button--primary" href="#lwcd-workspace"><?php esc_html_e( 'Start a reviewed change', 'lineweb-change-desk' ); ?></a><a class="lineweb-suite-admin__button lineweb-suite-admin__button--secondary" href="#lwcd-history"><?php esc_html_e( 'View history', 'lineweb-change-desk' ); ?></a></div>
				</div>
				<aside class="lineweb-suite-admin__brand-card" aria-label="<?php esc_attr_e( 'Lineweb branding', 'lineweb-change-desk' ); ?>"><img class="lineweb-suite-admin__brand-mark" src="<?php echo esc_url( plugin_dir_url( LWCD_FILE ) . 'assets/lineweb-logo.png' ); ?>" alt="Lineweb Creative Digital Agency"><div class="lineweb-suite-admin__brand-status"><strong><?php esc_html_e( 'You review. You decide.', 'lineweb-change-desk' ); ?></strong><p class="lineweb-suite-admin__brand-meta"><?php esc_html_e( 'Version 0.1 · No autonomous publishing', 'lineweb-change-desk' ); ?></p></div></aside>
			</section>
			<div id="lwcd-root"><p><?php esc_html_e( 'Loading your workspace…', 'lineweb-change-desk' ); ?></p></div>
			<section class="lineweb-suite-admin__section lineweb-suite-admin__support"><div class="lineweb-suite-admin__support-brand"><img src="<?php echo esc_url( plugin_dir_url( LWCD_FILE ) . 'assets/lineweb-logo.png' ); ?>" alt="Lineweb Creative Digital Agency"></div><div><h2><?php esc_html_e( 'A free, independent WordPress tool.', 'lineweb-change-desk' ); ?></h2><p><?php esc_html_e( 'No Lineweb account or telemetry. AI sends selected text to your configured provider only after confirmation; provider charges may apply.', 'lineweb-change-desk' ); ?></p></div><div class="lineweb-suite-admin__support-links"><a href="https://lineweb.gr/" target="_blank" rel="noopener noreferrer">lineweb.gr</a><a href="https://lineweb.gr/contact/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'lineweb-change-desk' ); ?></a></div></section>
		</div>
		<?php
	}
}
