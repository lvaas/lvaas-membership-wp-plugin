<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Add_Users {
	public const PAGE_SLUG     = 'lvaas-add-users';
	public const CAPABILITY    = 'create_users';
	public const NONCE_ACTION  = 'lvaas_add_users';
	public const FLASH_PREFIX  = 'lvaas_add_users_flash_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lvaas_add_users', array( $this, 'handle_submit' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			LVAAS_MEMBERSHIP_MENU_SLUG,
			'Add LVAAS Users',
			'Add Users',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			20
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		$flash = $this->take_flash();
		try {
			$members = lvaas_membership_source()->get_members();
		} catch ( \Throwable $e ) {
			$this->render_error( $e->getMessage() );
			return;
		}
		$candidates = array();
		foreach ( $members as $m ) {
			$email = LVAAS_Member::normalize_email( $m->email );
			if ( $email === '' || email_exists( $email ) ) {
				continue;
			}
			$candidates[] = $m;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add LVAAS Users', 'lvaas-membership' ); ?></h1>
			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $flash['message'] ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: 1: candidate count, 2: total LVAAS member count */
					esc_html__( '%1$d of %2$d LVAAS members have no matching WP account.', 'lvaas-membership' ),
					count( $candidates ),
					count( $members )
				);
				?>
				<?php
				printf(
					/* translators: %s: role slug */
					esc_html__( 'New accounts will be created with role %s.', 'lvaas-membership' ),
					'<code>' . esc_html( LVAAS_Config::get_provisioned_role() ) . '</code>'
				);
				?>
			</p>

			<?php if ( empty( $candidates ) ) : ?>
				<p><em><?php esc_html_e( 'Nothing to do — every LVAAS member already has a WP account.', 'lvaas-membership' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="lvaas_add_users">

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="lvaas-check-all" checked></td>
							<th><?php esc_html_e( 'Email', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Name', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Username', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Status', 'lvaas-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $candidates as $m ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="lvaas_emails[]" value="<?php echo esc_attr( $m->email ); ?>" checked>
								</th>
								<td><?php echo esc_html( $m->email ); ?></td>
								<td><?php echo esc_html( trim( $m->first . ' ' . $m->last ) ); ?></td>
								<td><?php echo esc_html( $m->username ); ?></td>
								<td><?php echo esc_html( $m->status_label() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top:1em;">
					<?php esc_html_e( 'Each selected member will receive a WP "new user" email with a password-set link. The admin will receive a notification as well.', 'lvaas-membership' ); ?>
				</p>
				<?php submit_button( __( 'Send invitations', 'lvaas-membership' ), 'primary', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Send invitation emails to all selected members?', 'lvaas-membership' ) ) . "');" ) ); ?>
			</form>

			<script>
				document.getElementById('lvaas-check-all').addEventListener('change', function (e) {
					var boxes = document.querySelectorAll('input[name="lvaas_emails[]"]');
					for (var i = 0; i < boxes.length; i++) { boxes[i].checked = e.target.checked; }
				});
			</script>
		</div>
		<?php
	}

	public function handle_submit(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}

		$selected = isset( $_POST['lvaas_emails'] ) && is_array( $_POST['lvaas_emails'] )
			? array_map( 'sanitize_email', wp_unslash( $_POST['lvaas_emails'] ) )
			: array();

		$selected = array_filter( array_map( array( 'LVAAS_Member', 'normalize_email' ), $selected ) );

		if ( empty( $selected ) ) {
			$this->set_flash( 'info', __( 'No members were selected.', 'lvaas-membership' ) );
			$this->redirect_back();
		}

		try {
			$members = lvaas_membership_source()->get_members();
		} catch ( \Throwable $e ) {
			$this->set_flash( 'error', __( 'Cannot read membership data: ', 'lvaas-membership' ) . $e->getMessage() );
			$this->redirect_back();
		}

		$by_email = array();
		foreach ( $members as $m ) {
			$by_email[ LVAAS_Member::normalize_email( $m->email ) ] = $m;
		}

		$role     = LVAAS_Config::get_provisioned_role();
		$created  = array();
		$skipped  = array();
		$failures = array();

		foreach ( $selected as $email ) {
			if ( ! isset( $by_email[ $email ] ) ) {
				$skipped[] = $email . ' (' . __( 'not in LVAAS DB', 'lvaas-membership' ) . ')';
				continue;
			}
			if ( email_exists( $email ) ) {
				$skipped[] = $email . ' (' . __( 'already in WP', 'lvaas-membership' ) . ')';
				continue;
			}
			$m       = $by_email[ $email ];
			$user_id = $this->create_one( $m, $role );
			if ( is_wp_error( $user_id ) ) {
				$failures[] = $email . ' — ' . $user_id->get_error_message();
				continue;
			}
			$created[] = (int) $user_id;
		}

		if ( ! empty( $created ) ) {
			LVAAS_Audit_Log::append( LVAAS_Audit_Log::ACTION_ADD, $created );
		}

		$parts = array();
		$parts[] = sprintf( __( '%d account(s) created.', 'lvaas-membership' ), count( $created ) );
		if ( ! empty( $skipped ) ) {
			$parts[] = sprintf( __( '%d skipped: ', 'lvaas-membership' ), count( $skipped ) ) . esc_html( implode( '; ', $skipped ) );
		}
		if ( ! empty( $failures ) ) {
			$parts[] = sprintf( __( '%d failed: ', 'lvaas-membership' ), count( $failures ) ) . esc_html( implode( '; ', $failures ) );
		}
		$this->set_flash( empty( $failures ) ? 'success' : 'warning', implode( ' ', $parts ) );
		$this->redirect_back();
	}

	/**
	 * @return int|WP_Error new user ID or error
	 */
	private function create_one( LVAAS_Member $m, string $role ) {
		$base = sanitize_user( $m->username !== '' ? $m->username : $m->email, true );
		if ( $base === '' ) {
			return new WP_Error( 'lvaas_no_username', __( 'Could not derive a valid username.', 'lvaas-membership' ) );
		}
		$login    = $base;
		$attempt  = 1;
		while ( username_exists( $login ) && $attempt < 100 ) {
			$login = $base . $attempt;
			$attempt++;
		}
		$user_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $m->email );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $m->first,
			'last_name'    => $m->last,
			'display_name' => trim( $m->first . ' ' . $m->last ) !== '' ? trim( $m->first . ' ' . $m->last ) : $login,
			'role'         => $role,
		) );
		update_user_meta( $user_id, LVAAS_MEMBERSHIP_USER_META_EMAIL, $m->email );
		wp_send_new_user_notifications( $user_id, 'both' );
		return (int) $user_id;
	}

	private function render_error( string $reason ): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add LVAAS Users', 'lvaas-membership' ); ?></h1>
			<div class="notice notice-error">
				<p><?php
					/* translators: %s: error reason */
					echo esc_html( sprintf( __( 'Unable to load members: %s', 'lvaas-membership' ), $reason ) );
				?></p>
			</div>
		</div>
		<?php
	}

	private function set_flash( string $type, string $message ): void {
		set_transient( self::FLASH_PREFIX . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	private function take_flash(): ?array {
		$f = get_transient( self::FLASH_PREFIX . get_current_user_id() );
		if ( is_array( $f ) ) {
			delete_transient( self::FLASH_PREFIX . get_current_user_id() );
			return $f;
		}
		return null;
	}

	private function redirect_back(): void {
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}
