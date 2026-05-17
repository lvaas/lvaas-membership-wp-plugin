<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_History {
	public const PAGE_SLUG  = 'lvaas-history';
	public const CAPABILITY = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			LVAAS_MEMBERSHIP_MENU_SLUG,
			'LVAAS History',
			'History',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			40
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		$entries = LVAAS_Audit_Log::entries( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LVAAS History', 'lvaas-membership' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Audit log of LVAAS Add and Prune actions. Most recent first.', 'lvaas-membership' ); ?></p>

			<?php if ( empty( $entries ) ) : ?>
				<p><em><?php esc_html_e( 'No audit log entries yet.', 'lvaas-membership' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'lvaas-membership' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'lvaas-membership' ); ?></th>
						<th><?php esc_html_e( 'Action', 'lvaas-membership' ); ?></th>
						<th><?php esc_html_e( '# affected', 'lvaas-membership' ); ?></th>
						<th><?php esc_html_e( 'Affected users', 'lvaas-membership' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td>
								<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) $e['timestamp'] ) ); ?>
								<br><span class="description"><?php
									echo esc_html( human_time_diff( (int) $e['timestamp'], time() ) . ' ' . __( 'ago', 'lvaas-membership' ) );
								?></span>
							</td>
							<td><?php
								$actor = get_user_by( 'id', (int) $e['actor_id'] );
								echo $actor ? esc_html( $actor->user_login ) : esc_html( '#' . (int) $e['actor_id'] . ' [deleted]' );
							?></td>
							<td><?php echo esc_html( LVAAS_Audit_Log::action_label( (string) $e['action'] ) ); ?></td>
							<td><?php echo (int) count( (array) $e['affected'] ); ?></td>
							<td><?php echo esc_html( $this->format_affected( (array) $e['affected'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function format_affected( array $items ): string {
		$out = array();
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$out[] = $item;
				continue;
			}
			$u = get_user_by( 'id', (int) $item );
			if ( $u ) {
				$out[] = $u->user_login;
			} else {
				$out[] = '#' . (int) $item . ' [gone]';
			}
		}
		return implode( ', ', $out );
	}
}
