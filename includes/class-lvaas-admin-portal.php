<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Portal {
	public const MENU_SLUG  = LVAAS_MEMBERSHIP_MENU_SLUG;
	public const CAPABILITY = 'read';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'rename_default_submenu' ), 999 );
	}

	public function register_menu(): void {
		add_menu_page(
			'LVAAS Membership',
			'LVAAS Admin',
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-id-alt',
			70
		);
	}

	public function rename_default_submenu(): void {
		global $submenu;
		if ( isset( $submenu[ self::MENU_SLUG ][0][0] ) ) {
			$submenu[ self::MENU_SLUG ][0][0] = __( 'Portal', 'lvaas-membership' );
		}
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}

		$tiles = array(
			array(
				'slug'  => LVAAS_Admin_Members::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Members::CAPABILITY,
				'title' => __( 'Members', 'lvaas-membership' ),
				'desc'  => __( 'Browse, sort, filter and export the LVAAS membership database.', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Add_Users::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Add_Users::CAPABILITY,
				'title' => __( 'Add LVAAS Users', 'lvaas-membership' ),
				'desc'  => __( 'Provision WP accounts for LVAAS members not yet in WP and send invitation emails.', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Prune_Users::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Prune_Users::CAPABILITY,
				'title' => __( 'Prune Users', 'lvaas-membership' ),
				'desc'  => __( 'Revoke or delete WP accounts whose email is no longer in the LVAAS database.', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_History::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Settings::CAPABILITY,
				'title' => __( 'History', 'lvaas-membership' ),
				'desc'  => __( 'Audit log of past Add and Prune actions.', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Settings::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Settings::CAPABILITY,
				'title' => __( 'Settings', 'lvaas-membership' ),
				'desc'  => __( 'Google Sheet ID, Service Account JSON, provisioned role, stale TTL.', 'lvaas-membership' ),
			),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LVAAS Membership', 'lvaas-membership' ); ?></h1>
			<style>
				.lvaas-portal-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1em; margin-top:1.5em; }
				.lvaas-portal-tile { background:#fff; border:1px solid #c3c4c7; padding:1em 1.25em; border-radius:4px; }
				.lvaas-portal-tile h2 { margin:0 0 .25em; font-size:1.1em; }
				.lvaas-portal-tile p { margin:.25em 0 .75em; color:#50575e; }
				.lvaas-portal-tile.disabled { opacity:.55; }
				.lvaas-portal-tile.disabled .button { pointer-events:none; }
			</style>
			<div class="lvaas-portal-grid">
				<?php foreach ( $tiles as $t ) :
					$can = current_user_can( $t['cap'] );
					$url = $can ? add_query_arg( 'page', $t['slug'], admin_url( 'admin.php' ) ) : '';
					?>
					<div class="lvaas-portal-tile<?php echo $can ? '' : ' disabled'; ?>">
						<h2><?php echo esc_html( $t['title'] ); ?></h2>
						<p><?php echo esc_html( $t['desc'] ); ?></p>
						<?php if ( $can ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open', 'lvaas-membership' ); ?> &rarr;</a>
						<?php else : ?>
							<span class="description"><?php
								printf(
									/* translators: %s: required capability slug */
									esc_html__( 'Requires %s capability', 'lvaas-membership' ),
									'<code>' . esc_html( $t['cap'] ) . '</code>'
								);
							?></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
