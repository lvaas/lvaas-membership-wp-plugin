<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Settings {
	public const MENU_SLUG            = LVAAS_MEMBERSHIP_MENU_SLUG;
	public const PAGE_SLUG            = 'lvaas-settings';
	public const CAPABILITY           = 'manage_options';
	public const NONCE_ACTION_SAVE    = 'lvaas_save_settings';
	public const NONCE_ACTION_REFRESH = 'lvaas_force_refresh';
	public const NONCE_ACTION_TEST    = 'lvaas_test_connection';
	public const FLASH_PREFIX         = 'lvaas_admin_flash_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lvaas_save_settings',   array( $this, 'handle_save' ) );
		add_action( 'admin_post_lvaas_force_refresh',   array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_lvaas_test_connection', array( $this, 'handle_test' ) );
		add_action( 'admin_notices', array( $this, 'render_sync_notice' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			self::MENU_SLUG,
			'LVAAS Settings',
			'Settings',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_settings' ),
			50
		);
	}

	public function render_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}

		$flash = $this->take_flash();
		$sheet_masked = LVAAS_Config::get_sheet_id_masked();
		$sa_set       = LVAAS_Config::has_service_account();
		$sa_masked    = LVAAS_Config::get_service_account_masked();
		$current_role = LVAAS_Config::get_provisioned_role();
		$stale_hours  = max( 0, (int) round( LVAAS_Config::get_stale_ttl_seconds() / HOUR_IN_SECONDS ) );
		$role_names   = wp_roles()->get_names();
		$post_url     = esc_url( admin_url( 'admin-post.php' ) );
		$show_invalid = isset( $_GET['lvaas_view'] ) && $_GET['lvaas_view'] === 'invalid';
		$notice       = get_option( LVAAS_GDatabase::NOTICE_OPT, null );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LVAAS Membership — Settings', 'lvaas-membership' ); ?></h1>

			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible">
					<p><?php echo wp_kses_post( $flash['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo $post_url; ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION_SAVE ); ?>
				<input type="hidden" name="action" value="lvaas_save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lvaas_sheet_id"><?php esc_html_e( 'Google Sheet ID', 'lvaas-membership' ); ?></label></th>
						<td>
							<input type="text" id="lvaas_sheet_id" name="lvaas_sheet_id" value="" class="regular-text" autocomplete="off">
							<p class="description">
								<?php
								printf(
									/* translators: %s: masked sheet ID, e.g. "…ABCDEF" or "(not set)" */
									esc_html__( 'Currently stored: %s. Leave blank to keep the existing value.', 'lvaas-membership' ),
									'<code>' . esc_html( $sheet_masked === '' ? __( '(not set)', 'lvaas-membership' ) : $sheet_masked ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="lvaas_sa_json"><?php esc_html_e( 'Service Account JSON', 'lvaas-membership' ); ?></label></th>
						<td>
							<input type="file" id="lvaas_sa_json" name="lvaas_sa_json" accept="application/json,.json">
							<p class="description">
								<?php
								printf(
									/* translators: %s: masked SA identifier */
									esc_html__( 'Currently stored: %s. Upload a JSON file to replace.', 'lvaas-membership' ),
									'<code>' . esc_html( $sa_masked === '' ? __( '(not set)', 'lvaas-membership' ) : $sa_masked ) . '</code>'
								);
								?>
							</p>
							<?php if ( $sa_set ) : ?>
								<p>
									<label>
										<input type="checkbox" name="lvaas_remove_sa" value="1">
										<?php esc_html_e( 'Remove the stored service account', 'lvaas-membership' ); ?>
									</label>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="lvaas_provisioned_role"><?php esc_html_e( 'Provisioned WP Role', 'lvaas-membership' ); ?></label></th>
						<td>
							<select id="lvaas_provisioned_role" name="lvaas_provisioned_role">
								<?php foreach ( $role_names as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_role, $slug ); ?>>
										<?php echo esc_html( translate_user_role( $name ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Role assigned to WP users provisioned from the membership DB.', 'lvaas-membership' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="lvaas_stale_ttl_hours"><?php esc_html_e( 'Stale TTL (hours)', 'lvaas-membership' ); ?></label></th>
						<td>
							<input type="number" id="lvaas_stale_ttl_hours" name="lvaas_stale_ttl_hours" value="<?php echo esc_attr( $stale_hours ); ?>" min="0" step="1">
							<p class="description"><?php esc_html_e( 'When the Google API is unreachable, serve last good data as stale for this many hours. Default: 24.', 'lvaas-membership' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'lvaas-membership' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Actions', 'lvaas-membership' ); ?></h2>
			<p>
				<form method="post" action="<?php echo $post_url; ?>" style="display:inline-block;margin-right:.5em;">
					<?php wp_nonce_field( self::NONCE_ACTION_TEST ); ?>
					<input type="hidden" name="action" value="lvaas_test_connection">
					<?php submit_button( __( 'Test Connection', 'lvaas-membership' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo $post_url; ?>" style="display:inline-block;">
					<?php wp_nonce_field( self::NONCE_ACTION_REFRESH ); ?>
					<input type="hidden" name="action" value="lvaas_force_refresh">
					<?php submit_button( __( 'Force Refresh', 'lvaas-membership' ), 'secondary', 'submit', false ); ?>
				</form>
			</p>

			<?php $this->render_status_block( $notice ); ?>

			<?php if ( $show_invalid && is_array( $notice ) && ( $notice['type'] ?? '' ) === 'invalid' ) : ?>
				<h2><?php esc_html_e( 'Invalid rows from last sync', 'lvaas-membership' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Sheet row', 'lvaas-membership' ); ?></th><th><?php esc_html_e( 'Reason', 'lvaas-membership' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( (array) ( $notice['rows'] ?? array() ) as $row ) : ?>
							<tr>
								<td><?php echo (int) ( $row['row'] ?? 0 ); ?></td>
								<td><?php echo esc_html( (string) ( $row['reason'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_status_block( $notice ): void {
		$fallback = get_option( LVAAS_GDatabase::FALLBACK_OPT, null );
		$last     = is_array( $fallback ) && isset( $fallback['fetched_at'] )
			? human_time_diff( (int) $fallback['fetched_at'], time() ) . ' ' . __( 'ago', 'lvaas-membership' )
			: __( 'never', 'lvaas-membership' );
		$count    = is_array( $fallback ) && isset( $fallback['members'] ) ? count( $fallback['members'] ) : 0;
		?>
		<h2><?php esc_html_e( 'Sync status', 'lvaas-membership' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: cached row count, 2: human-readable last-fetched time */
				esc_html__( 'Last successful fetch: %1$d member(s), %2$s.', 'lvaas-membership' ),
				(int) $count,
				esc_html( $last )
			);
			?>
		</p>
		<?php if ( is_array( $notice ) ) : ?>
			<?php $type = (string) ( $notice['type'] ?? '' ); ?>
			<?php if ( $type === 'invalid' ) : ?>
				<?php
				$url = add_query_arg( 'lvaas_view', 'invalid', menu_page_url( self::PAGE_SLUG, false ) );
				$cnt = (int) ( $notice['count'] ?? 0 );
				?>
				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html( sprintf( _n( '%d row excluded.', '%d rows excluded.', $cnt, 'lvaas-membership' ), $cnt ) ); ?>
						<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View details', 'lvaas-membership' ); ?></a>
					</p>
				</div>
			<?php elseif ( $type === 'stale' ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						$when = (int) ( $notice['fetched_at'] ?? 0 );
						echo esc_html( sprintf(
							/* translators: 1: human time diff, 2: reason */
							__( 'Serving stale data — last refreshed %1$s ago. Reason: %2$s', 'lvaas-membership' ),
							human_time_diff( $when, time() ),
							(string) ( $notice['reason'] ?? '' )
						) );
						?>
					</p>
				</div>
			<?php elseif ( $type === 'outage' ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						echo esc_html( sprintf(
							/* translators: %s: outage reason */
							__( 'Data source unavailable: %s', 'lvaas-membership' ),
							(string) ( $notice['reason'] ?? '' )
						) );
						?>
					</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	public function render_sync_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, self::MENU_SLUG ) === false ) {
			return;
		}
		// On the settings page itself the status block already shows it.
		if ( strpos( (string) $screen->id, self::PAGE_SLUG ) !== false ) {
			return;
		}
		$notice = get_option( LVAAS_GDatabase::NOTICE_OPT, null );
		if ( ! is_array( $notice ) ) {
			return;
		}
		$type = (string) ( $notice['type'] ?? '' );
		if ( $type === 'invalid' ) {
			$cnt = (int) ( $notice['count'] ?? 0 );
			$url = add_query_arg( 'lvaas_view', 'invalid', menu_page_url( self::PAGE_SLUG, false ) );
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( _n( '%d row excluded — ', '%d rows excluded — ', $cnt, 'lvaas-membership' ), $cnt ) ),
				esc_url( $url ),
				esc_html__( 'view details', 'lvaas-membership' )
			);
		}
	}

	public function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION_SAVE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}

		$changes = array();
		$errors  = array();

		$sheet_id = isset( $_POST['lvaas_sheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['lvaas_sheet_id'] ) ) : '';
		if ( $sheet_id !== '' ) {
			LVAAS_Config::set_sheet_id( $sheet_id );
			$changes[] = __( 'Sheet ID updated.', 'lvaas-membership' );
		}

		if ( ! empty( $_POST['lvaas_remove_sa'] ) ) {
			delete_option( LVAAS_Config::OPT_SERVICE_ACCOUNT );
			$changes[] = __( 'Service account removed.', 'lvaas-membership' );
		} elseif ( ! empty( $_FILES['lvaas_sa_json']['tmp_name'] ) && is_uploaded_file( $_FILES['lvaas_sa_json']['tmp_name'] ) ) {
			$contents = file_get_contents( $_FILES['lvaas_sa_json']['tmp_name'] );
			if ( $contents === false ) {
				$errors[] = __( 'Could not read uploaded file.', 'lvaas-membership' );
			} elseif ( LVAAS_Config::set_service_account_json( $contents ) ) {
				$changes[] = __( 'Service account JSON updated.', 'lvaas-membership' );
			} else {
				$errors[] = __( 'Uploaded file is not valid JSON.', 'lvaas-membership' );
			}
		}

		$role = isset( $_POST['lvaas_provisioned_role'] ) ? sanitize_key( wp_unslash( $_POST['lvaas_provisioned_role'] ) ) : '';
		if ( $role !== '' ) {
			$valid = array_keys( wp_roles()->roles );
			if ( in_array( $role, $valid, true ) ) {
				LVAAS_Config::set_provisioned_role( $role );
				$changes[] = sprintf( __( 'Provisioned role set to %s.', 'lvaas-membership' ), $role );
			} else {
				$errors[] = __( 'Invalid role selection.', 'lvaas-membership' );
			}
		}

		if ( isset( $_POST['lvaas_stale_ttl_hours'] ) && $_POST['lvaas_stale_ttl_hours'] !== '' ) {
			$hours = max( 0, (int) $_POST['lvaas_stale_ttl_hours'] );
			update_option( LVAAS_Config::OPT_STALE_TTL, $hours * HOUR_IN_SECONDS );
			$changes[] = sprintf( __( 'Stale TTL set to %d hour(s).', 'lvaas-membership' ), $hours );
		}

		if ( ! empty( $errors ) ) {
			$this->set_flash( 'error', implode( ' ', $errors ) );
		} elseif ( ! empty( $changes ) ) {
			$this->set_flash( 'success', implode( ' ', $changes ) );
		} else {
			$this->set_flash( 'info', __( 'No changes.', 'lvaas-membership' ) );
		}

		$this->redirect_back();
	}

	public function handle_refresh(): void {
		check_admin_referer( self::NONCE_ACTION_REFRESH );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		try {
			$source = lvaas_membership_source();
			if ( ! method_exists( $source, 'force_refresh' ) ) {
				throw new \RuntimeException( 'Active data source does not support force_refresh.' );
			}
			$result      = $source->force_refresh();
			$invalid_msg = $result['invalid'] > 0
				? ' ' . sprintf( __( '%d invalid row(s) skipped.', 'lvaas-membership' ), (int) $result['invalid'] )
				: '';
			$this->set_flash(
				'success',
				sprintf( __( 'Refresh complete: %d valid member(s).', 'lvaas-membership' ), (int) $result['count'] ) . $invalid_msg
			);
		} catch ( \Throwable $e ) {
			$this->set_flash( 'error', __( 'Refresh failed: ', 'lvaas-membership' ) . $e->getMessage() );
		}
		$this->redirect_back();
	}

	public function handle_test(): void {
		check_admin_referer( self::NONCE_ACTION_TEST );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient privileges.', 'lvaas-membership' ) );
		}
		try {
			$source = lvaas_membership_source();
			if ( ! method_exists( $source, 'test_connection' ) ) {
				throw new \RuntimeException( 'Active data source does not support test_connection.' );
			}
			$result = $source->test_connection();
			$this->set_flash(
				'success',
				sprintf(
					/* translators: 1: sheet name, 2: column count */
					__( 'Connection OK — sheet "%1$s" (%2$d columns).', 'lvaas-membership' ),
					(string) $result['sheet_name'],
					(int) $result['column_count']
				)
			);
		} catch ( \Throwable $e ) {
			$this->set_flash( 'error', __( 'Connection failed: ', 'lvaas-membership' ) . $e->getMessage() );
		}
		$this->redirect_back();
	}

	private function set_flash( string $type, string $message ): void {
		set_transient( $this->flash_key(), array( 'type' => $type, 'message' => $message ), 60 );
	}

	private function take_flash(): ?array {
		$f = get_transient( $this->flash_key() );
		if ( is_array( $f ) ) {
			delete_transient( $this->flash_key() );
			$type = (string) ( $f['type'] ?? '' );
			if ( ! in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ) {
				$type = 'info';
			}
			return array( 'type' => $type, 'message' => (string) ( $f['message'] ?? '' ) );
		}
		return null;
	}

	private function flash_key(): string {
		return self::FLASH_PREFIX . get_current_user_id();
	}

	private function redirect_back(): void {
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}
