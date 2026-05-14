<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Prune_Users {
	public const PAGE_SLUG    = 'lvaas-prune-users';
	public const CAPABILITY   = 'delete_users';
	public const NONCE_ACTION = 'lvaas_prune_users';
	public const FLASH_PREFIX = 'lvaas_prune_users_flash_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lvaas_prune_users', array( $this, 'handle_submit' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			LVAAS_MEMBERSHIP_MENU_SLUG,
			'Prune LVAAS Users',
			'Prune Users',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			30
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		$flash = $this->take_flash();
		try {
			$lvaas_emails = $this->lvaas_email_set();
		} catch ( \Throwable $e ) {
			$this->render_error( $e->getMessage() );
			return;
		}
		$candidates = $this->prune_candidates( $lvaas_emails );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prune LVAAS Users', 'lvaas-membership' ); ?></h1>
			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible"><p><?php echo wp_kses_post( $flash['message'] ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'WP users whose email is not in the LVAAS DB are listed below. Administrators are excluded for safety.', 'lvaas-membership' ); ?>
				<?php
				printf(
					' ' . esc_html__( '%d candidate(s).', 'lvaas-membership' ),
					count( $candidates )
				);
				?>
			</p>

			<?php if ( empty( $candidates ) ) : ?>
				<p><em><?php esc_html_e( 'Nothing to prune.', 'lvaas-membership' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="lvaas_prune_users">

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="lvaas-prune-check-all"></td>
							<th><?php esc_html_e( 'Email', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Login', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Display name', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Roles', 'lvaas-membership' ); ?></th>
							<th><?php esc_html_e( 'Last login', 'lvaas-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $candidates as $u ) : ?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" name="lvaas_user_ids[]" value="<?php echo esc_attr( (int) $u->ID ); ?>">
								</th>
								<td><?php echo esc_html( $u->user_email ); ?></td>
								<td><?php echo esc_html( $u->user_login ); ?></td>
								<td><?php echo esc_html( $u->display_name ); ?></td>
								<td><?php echo esc_html( implode( ', ', (array) $u->roles ) ); ?></td>
								<td><?php echo esc_html( $this->last_login_label( $u ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:1em;">
					<label><input type="radio" name="lvaas_op" value="revoke" checked> <?php esc_html_e( 'Revoke (remove all roles; account preserved, login disabled)', 'lvaas-membership' ); ?></label><br>
					<label><input type="radio" name="lvaas_op" value="delete"> <?php esc_html_e( 'Delete account permanently', 'lvaas-membership' ); ?></label>
				</p>
				<?php submit_button( __( 'Apply', 'lvaas-membership' ), 'delete', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Apply this action to all selected users? This cannot be undone.', 'lvaas-membership' ) ) . "');" ) ); ?>
			</form>

			<script>
				document.getElementById('lvaas-prune-check-all').addEventListener('change', function (e) {
					var boxes = document.querySelectorAll('input[name="lvaas_user_ids[]"]');
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

		$ids = isset( $_POST['lvaas_user_ids'] ) && is_array( $_POST['lvaas_user_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['lvaas_user_ids'] ) )
			: array();
		$op  = isset( $_POST['lvaas_op'] ) ? sanitize_key( wp_unslash( $_POST['lvaas_op'] ) ) : '';

		if ( ! in_array( $op, array( 'revoke', 'delete' ), true ) ) {
			$this->set_flash( 'error', __( 'Invalid action.', 'lvaas-membership' ) );
			$this->redirect_back();
		}
		if ( empty( $ids ) ) {
			$this->set_flash( 'info', __( 'No users were selected.', 'lvaas-membership' ) );
			$this->redirect_back();
		}

		try {
			$lvaas_emails = $this->lvaas_email_set();
		} catch ( \Throwable $e ) {
			$this->set_flash( 'error', __( 'Cannot read membership data: ', 'lvaas-membership' ) . $e->getMessage() );
			$this->redirect_back();
		}

		$current_id = get_current_user_id();
		$processed  = array();
		$skipped    = array();

		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			if ( $id === $current_id ) {
				$skipped[] = "user $id (self)";
				continue;
			}
			$u = get_user_by( 'id', $id );
			if ( ! $u ) {
				$skipped[] = "user $id (not found)";
				continue;
			}
			if ( in_array( 'administrator', (array) $u->roles, true ) ) {
				$skipped[] = $u->user_login . ' (administrator)';
				continue;
			}
			$email = LVAAS_Member::normalize_email( $u->user_email );
			if ( $email !== '' && isset( $lvaas_emails[ $email ] ) ) {
				$skipped[] = $u->user_login . ' (' . __( 'now in LVAAS', 'lvaas-membership' ) . ')';
				continue;
			}
			if ( $op === 'delete' ) {
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				if ( wp_delete_user( $id ) ) {
					$processed[] = $id;
				} else {
					$skipped[] = $u->user_login . ' (delete failed)';
				}
			} else {
				$u->set_role( '' );
				$processed[] = $id;
			}
		}

		if ( ! empty( $processed ) ) {
			LVAAS_Audit_Log::append(
				$op === 'delete' ? LVAAS_Audit_Log::ACTION_PRUNE_DELETE : LVAAS_Audit_Log::ACTION_PRUNE_REVOKE,
				$processed
			);
		}

		$parts = array();
		$parts[] = $op === 'delete'
			? sprintf( __( '%d account(s) deleted.', 'lvaas-membership' ), count( $processed ) )
			: sprintf( __( '%d account(s) revoked.', 'lvaas-membership' ), count( $processed ) );
		if ( ! empty( $skipped ) ) {
			$parts[] = sprintf( __( '%d skipped: ', 'lvaas-membership' ), count( $skipped ) ) . esc_html( implode( '; ', $skipped ) );
		}
		$this->set_flash( 'success', implode( ' ', $parts ) );
		$this->redirect_back();
	}

	/** @return array<string, true> set of normalized LVAAS emails */
	private function lvaas_email_set(): array {
		$out = array();
		foreach ( lvaas_membership_source()->get_members() as $m ) {
			$e = LVAAS_Member::normalize_email( $m->email );
			if ( $e !== '' ) {
				$out[ $e ] = true;
			}
		}
		return $out;
	}

	/** @param array<string, true> $lvaas_emails */
	private function prune_candidates( array $lvaas_emails ): array {
		$users = get_users( array( 'fields' => array( 'ID', 'user_email', 'user_login', 'display_name' ), 'number' => -1 ) );
		$out   = array();
		foreach ( $users as $stub ) {
			$u = get_user_by( 'id', (int) $stub->ID );
			if ( ! $u || in_array( 'administrator', (array) $u->roles, true ) ) {
				continue;
			}
			$e = LVAAS_Member::normalize_email( $u->user_email );
			if ( $e !== '' && isset( $lvaas_emails[ $e ] ) ) {
				continue;
			}
			$out[] = $u;
		}
		return $out;
	}

	private function last_login_label( WP_User $u ): string {
		$ts = get_user_meta( $u->ID, 'session_tokens', true );
		// Best-effort: scan session tokens for the most recent "last_login" timestamp.
		if ( is_array( $ts ) && ! empty( $ts ) ) {
			$latest = 0;
			foreach ( $ts as $tok ) {
				if ( isset( $tok['login'] ) && (int) $tok['login'] > $latest ) {
					$latest = (int) $tok['login'];
				}
			}
			if ( $latest > 0 ) {
				return human_time_diff( $latest, time() ) . ' ' . __( 'ago', 'lvaas-membership' );
			}
		}
		return __( 'never', 'lvaas-membership' );
	}

	private function render_error( string $reason ): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prune LVAAS Users', 'lvaas-membership' ); ?></h1>
			<div class="notice notice-error">
				<p><?php echo esc_html( sprintf( __( 'Unable to load members: %s', 'lvaas-membership' ), $reason ) ); ?></p>
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
