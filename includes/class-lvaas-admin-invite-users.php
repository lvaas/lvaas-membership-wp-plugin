<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Invite_Users {
	public const PAGE_SLUG    = 'lvaas-invite-users';
	public const CAPABILITY   = 'create_users';
	public const NONCE_ACTION = 'lvaas_invite_users';
	public const FLASH_PREFIX = 'lvaas_invite_users_flash_';
	public const TEMPLATE_REL = 'templates/invite-default.html';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lvaas_invite_users', array( $this, 'handle_submit' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			LVAAS_MEMBERSHIP_MENU_SLUG,
			'Invite LVAAS Users',
			'Invite Users',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			25
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
			<h1><?php esc_html_e( 'Invite LVAAS Users', 'lvaas-membership' ); ?></h1>
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
				<?php esc_html_e( 'Selected members will receive an invitation email. No WP accounts will be created.', 'lvaas-membership' ); ?>
			</p>

			<?php if ( empty( $candidates ) ) : ?>
				<p><em><?php esc_html_e( 'Nothing to do — every LVAAS member already has a WP account.', 'lvaas-membership' ); ?></em></p>
				<?php return; ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="lvaas_invite_users">

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="lvaas-invite-check-all" checked></td>
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

				<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Email subject', 'lvaas-membership' ); ?></h2>
				<input type="text" name="lvaas_invite_subject" class="large-text" value="<?php echo esc_attr( $this->default_subject() ); ?>">

				<h2 style="margin-top:1.5em;"><?php esc_html_e( 'Email body (HTML)', 'lvaas-membership' ); ?></h2>
				<p class="description">
					<?php
					echo wp_kses(
						__( 'HTML email body. Use <code>{first_name}</code> and <code>{last_name}</code> to insert the recipient&#8217;s name. Edits are not saved between sessions.', 'lvaas-membership' ),
						array( 'code' => array() )
					);
					?>
				</p>
				<textarea name="lvaas_invite_body" rows="14" class="large-text code" style="font-family:Menlo,Consolas,monospace;"><?php echo esc_textarea( $this->default_body() ); ?></textarea>

				<p class="description" style="margin-top:1em;">
					<?php esc_html_e( 'Each selected member will receive the HTML email above. No account is created; recipients self-register from the link in the message.', 'lvaas-membership' ); ?>
				</p>
				<?php submit_button( __( 'Send invitations', 'lvaas-membership' ), 'primary', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Send invitation emails to all selected members?', 'lvaas-membership' ) ) . "');" ) ); ?>
			</form>

			<script>
				document.getElementById('lvaas-invite-check-all').addEventListener('change', function (e) {
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

		$subject_raw = isset( $_POST['lvaas_invite_subject'] )
			? sanitize_text_field( wp_unslash( $_POST['lvaas_invite_subject'] ) )
			: '';
		$subject = $subject_raw !== '' ? $subject_raw : $this->default_subject();

		$body = isset( $_POST['lvaas_invite_body'] )
			? (string) wp_unslash( $_POST['lvaas_invite_body'] )
			: '';
		if ( trim( $body ) === '' ) {
			$this->set_flash( 'error', __( 'Email body is empty.', 'lvaas-membership' ) );
			$this->redirect_back();
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

		$sent     = array();
		$skipped  = array();
		$failures = array();
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );

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
			$message = $this->expand_placeholders( $body, $m );
			$ok      = wp_mail( $m->email, $subject, $message, $headers );
			if ( $ok ) {
				$sent[] = $email;
			} else {
				$failures[] = $email . ' — ' . __( 'wp_mail returned false', 'lvaas-membership' );
			}
		}

		if ( ! empty( $sent ) ) {
			LVAAS_Audit_Log::append_invites( $sent );
		}

		$parts   = array();
		$parts[] = sprintf( __( '%d invitation(s) sent.', 'lvaas-membership' ), count( $sent ) );
		if ( ! empty( $skipped ) ) {
			$parts[] = sprintf( __( '%d skipped: ', 'lvaas-membership' ), count( $skipped ) ) . esc_html( implode( '; ', $skipped ) );
		}
		if ( ! empty( $failures ) ) {
			$parts[] = sprintf( __( '%d failed: ', 'lvaas-membership' ), count( $failures ) ) . esc_html( implode( '; ', $failures ) );
		}
		$this->set_flash( empty( $failures ) ? 'success' : 'warning', implode( ' ', $parts ) );
		$this->redirect_back();
	}

	private function expand_placeholders( string $body, LVAAS_Member $m ): string {
		$first = trim( $m->first );
		$last  = trim( $m->last );
		return strtr( $body, array(
			'{first_name}' => $first !== '' ? $first : __( 'there', 'lvaas-membership' ),
			'{last_name}'  => $last,
		) );
	}

	private function default_subject(): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf( __( 'You are invited to join %s', 'lvaas-membership' ), $site );
	}

	private function default_body(): string {
		$path = LVAAS_MEMBERSHIP_PLUGIN_DIR . self::TEMPLATE_REL;
		if ( is_readable( $path ) ) {
			$contents = file_get_contents( $path );
			if ( is_string( $contents ) ) {
				return $contents;
			}
		}
		return '';
	}

	private function render_error( string $reason ): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Invite LVAAS Users', 'lvaas-membership' ); ?></h1>
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
