<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Add_Users {
	public const PAGE_SLUG          = 'lvaas-add-users';
	public const CAPABILITY         = 'create_users';
	public const NONCE_ACTION       = 'lvaas_add_users';
	public const NONCE_ACTION_INTRO = 'lvaas_save_invite_intro';
	public const FLASH_PREFIX       = 'lvaas_add_users_flash_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lvaas_add_users',         array( $this, 'handle_submit' ) );
		add_action( 'admin_post_lvaas_save_invite_intro', array( $this, 'handle_save_intro' ) );
		add_filter( 'wp_new_user_notification_email',       array( $this, 'html_user_notification' ),  10, 3 );
		add_filter( 'wp_new_user_notification_email_admin', array( $this, 'html_admin_notification' ), 10, 3 );
	}

	/**
	 * Rewrite the new-user "set your password" email as HTML for LVAAS-provisioned users.
	 *
	 * @param array{to:string, subject:string, message:string, headers:string|string[]} $email
	 */
	public function html_user_notification( array $email, WP_User $user, string $blogname ): array {
		if ( ! get_user_meta( $user->ID, LVAAS_MEMBERSHIP_USER_META_EMAIL, true ) ) {
			return $email;
		}
		$reset_url = $this->extract_reset_url( (string) $email['message'] );
		if ( $reset_url === '' ) {
			return $email;
		}
		$login_url = wp_login_url();
		$intro     = LVAAS_Config::get_invite_intro();

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1d2327;background:#f6f7f7;margin:0;padding:24px;">
	<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:24px;">
		<?php if ( $intro !== '' ) : ?>
			<p style="margin:0 0 1em;font-size:1.05em;"><?php echo nl2br( esc_html( $intro ) ); ?></p>
		<?php endif; ?>
		<p style="margin:0 0 1em;">
			<strong><?php esc_html_e( 'Username:', 'lvaas-membership' ); ?></strong>
			<code style="background:#f0f0f1;padding:2px 6px;border-radius:3px;"><?php echo esc_html( $user->user_login ); ?></code>
		</p>
		<p style="margin:0 0 1em;"><?php esc_html_e( 'Click the button below to set your password and sign in:', 'lvaas-membership' ); ?></p>
		<p style="margin:1.5em 0;text-align:center;">
			<a href="<?php echo esc_url( $reset_url ); ?>"
			   style="background:#2271b1;color:#fff;padding:.7em 1.4em;text-decoration:none;border-radius:4px;display:inline-block;font-weight:600;">
				<?php esc_html_e( 'Set your password', 'lvaas-membership' ); ?>
			</a>
		</p>
		<p style="margin:0 0 1em;color:#646970;font-size:.92em;">
			<?php esc_html_e( 'If the button does not work, copy and paste this link into your browser:', 'lvaas-membership' ); ?>
			<br>
			<a href="<?php echo esc_url( $reset_url ); ?>" style="word-break:break-all;"><?php echo esc_html( $reset_url ); ?></a>
		</p>
		<p style="margin:0 0 1em;">
			<?php esc_html_e( 'After setting your password, sign in here:', 'lvaas-membership' ); ?>
			<a href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html( $login_url ); ?></a>
		</p>
		<p style="margin-top:2em;color:#8c8f94;font-size:.85em;border-top:1px solid #f0f0f1;padding-top:1em;">
			<?php esc_html_e( 'If you weren\'t expecting this message, you can safely ignore it.', 'lvaas-membership' ); ?>
		</p>
	</div>
</body>
</html>
		<?php
		$email['message'] = (string) ob_get_clean();
		$email['headers'] = $this->merge_html_header( $email['headers'] );
		return $email;
	}

	/**
	 * Rewrite the admin "new user" notification as HTML for LVAAS-provisioned users.
	 *
	 * @param array{to:string, subject:string, message:string, headers:string|string[]} $email
	 */
	public function html_admin_notification( array $email, WP_User $user, string $blogname ): array {
		if ( ! get_user_meta( $user->ID, LVAAS_MEMBERSHIP_USER_META_EMAIL, true ) ) {
			return $email;
		}
		$user_admin_url = admin_url( 'user-edit.php?user_id=' . (int) $user->ID );
		$mailto         = 'mailto:' . rawurlencode( $user->user_email );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<body style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1d2327;background:#f6f7f7;margin:0;padding:24px;">
	<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:24px;">
		<p style="margin:0 0 1em;">
			<?php
			printf(
				/* translators: %s: blog name */
				esc_html__( 'A new LVAAS user has been provisioned on %s:', 'lvaas-membership' ),
				'<strong>' . esc_html( $blogname ) . '</strong>'
			);
			?>
		</p>
		<table style="border-collapse:collapse;margin:0 0 1em;">
			<tr><td style="padding:4px 12px 4px 0;color:#646970;"><?php esc_html_e( 'Username',     'lvaas-membership' ); ?></td><td><code style="background:#f0f0f1;padding:2px 6px;border-radius:3px;"><?php echo esc_html( $user->user_login ); ?></code></td></tr>
			<tr><td style="padding:4px 12px 4px 0;color:#646970;"><?php esc_html_e( 'Email',        'lvaas-membership' ); ?></td><td><a href="<?php echo esc_url( $mailto ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td></tr>
			<tr><td style="padding:4px 12px 4px 0;color:#646970;"><?php esc_html_e( 'Display name', 'lvaas-membership' ); ?></td><td><?php echo esc_html( $user->display_name ); ?></td></tr>
		</table>
		<p style="margin:1.5em 0 0;">
			<a href="<?php echo esc_url( $user_admin_url ); ?>"
			   style="background:#2271b1;color:#fff;padding:.5em 1em;text-decoration:none;border-radius:4px;display:inline-block;">
				<?php esc_html_e( 'View user in WP admin', 'lvaas-membership' ); ?> &rarr;
			</a>
		</p>
	</div>
</body>
</html>
		<?php
		$email['message'] = (string) ob_get_clean();
		$email['headers'] = $this->merge_html_header( $email['headers'] );
		return $email;
	}

	private function extract_reset_url( string $message ): string {
		if ( preg_match( '#https?://[^\s<>"\']+wp-login\.php\?[^\s<>"\']*action=rp[^\s<>"\']*#', $message, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/** @param string|string[] $headers */
	private function merge_html_header( $headers ): string {
		$existing = is_array( $headers ) ? implode( "\r\n", $headers ) : (string) $headers;
		if ( stripos( $existing, 'content-type:' ) !== false ) {
			return $existing;
		}
		return ( $existing !== '' ? rtrim( $existing, "\r\n" ) . "\r\n" : '' ) . 'Content-Type: text/html; charset=UTF-8';
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

			<h2><?php esc_html_e( 'Invitation message', 'lvaas-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION_INTRO ); ?>
				<input type="hidden" name="action" value="lvaas_save_invite_intro">
				<p class="description"><?php esc_html_e( 'First line of the invitation email. Plain text; line breaks are preserved.', 'lvaas-membership' ); ?></p>
				<textarea name="lvaas_invite_intro" rows="3" class="large-text" style="font-family:inherit;"><?php echo esc_textarea( LVAAS_Config::get_invite_intro() ); ?></textarea>
				<?php submit_button( __( 'Save message', 'lvaas-membership' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr>

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

	public function handle_save_intro(): void {
		check_admin_referer( self::NONCE_ACTION_INTRO );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		$text = isset( $_POST['lvaas_invite_intro'] )
			? sanitize_textarea_field( wp_unslash( $_POST['lvaas_invite_intro'] ) )
			: '';
		LVAAS_Config::set_invite_intro( $text );
		$this->set_flash( 'success', __( 'Invitation message saved.', 'lvaas-membership' ) );
		$this->redirect_back();
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
