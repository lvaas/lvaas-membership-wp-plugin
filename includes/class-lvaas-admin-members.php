<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Admin_Members {
	public const PAGE_SLUG     = 'lvaas-members';
	public const CAPABILITY    = 'list_users';
	public const SCRIPT_HANDLE = 'lvaas-members';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'rename_default_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function register_menu(): void {
		$effective_cap = $this->effective_menu_capability();
		if ( $effective_cap === null ) {
			return;
		}
		add_menu_page(
			'LVAAS Members',
			'LVAAS Admin',
			$effective_cap,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-id-alt',
			70
		);
	}

	/**
	 * The capability used to gate the top-level menu item. Picks the first
	 * relevant capability that the current user has, so the menu shows iff at
	 * least one child page is usable. Returns null when none apply — caller
	 * skips menu registration.
	 */
	private function effective_menu_capability(): ?string {
		$caps = array(
			self::CAPABILITY,
			LVAAS_Admin_Add_Users::CAPABILITY,
			LVAAS_Admin_Invite_Users::CAPABILITY,
			LVAAS_Admin_Prune_Users::CAPABILITY,
			LVAAS_Admin_History::CAPABILITY,
			LVAAS_Admin_Settings::CAPABILITY,
		);
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}
		return null;
	}

	public function rename_default_submenu(): void {
		global $submenu;
		if ( isset( $submenu[ self::PAGE_SLUG ][0][0] ) ) {
			$submenu[ self::PAGE_SLUG ][0][0] = __( 'Members', 'lvaas-membership' );
		}
	}

	public function maybe_enqueue( string $hook_suffix ): void {
		if ( strpos( $hook_suffix, self::PAGE_SLUG ) === false ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			LVAAS_MEMBERSHIP_PLUGIN_URL . 'assets/members.js',
			array(),
			LVAAS_MEMBERSHIP_VERSION,
			true
		);
	}

	public function render(): void {
		$can_list     = current_user_can( self::CAPABILITY );
		$settings_url = add_query_arg( 'page', LVAAS_Admin_Settings::PAGE_SLUG, admin_url( 'admin.php' ) );

		$actions = array(
			array(
				'slug'  => LVAAS_Admin_Add_Users::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Add_Users::CAPABILITY,
				'label' => __( 'Add Users', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Invite_Users::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Invite_Users::CAPABILITY,
				'label' => __( 'Invite Users', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Prune_Users::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Prune_Users::CAPABILITY,
				'label' => __( 'Prune Users', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_History::PAGE_SLUG,
				'cap'   => LVAAS_Admin_History::CAPABILITY,
				'label' => __( 'History', 'lvaas-membership' ),
			),
			array(
				'slug'  => LVAAS_Admin_Settings::PAGE_SLUG,
				'cap'   => LVAAS_Admin_Settings::CAPABILITY,
				'label' => __( 'Settings', 'lvaas-membership' ),
			),
		);

		$rows  = array();
		$error = null;
		if ( $can_list ) {
			try {
				$members = lvaas_membership_source()->get_members();
				foreach ( $members as $m ) {
					$rows[] = array(
						'last'   => $m->last,
						'first'  => $m->first,
						'email'  => $m->email,
						'phone'  => $m->phone,
						'status' => $m->status_label(),
					);
				}
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}

			wp_localize_script(
				self::SCRIPT_HANDLE,
				'LVAASMembers',
				array(
					'rows' => $rows,
					'i18n' => array(
						'noMatches' => __( 'No members match.', 'lvaas-membership' ),
					),
				)
			);
		}

		$notice   = get_option( LVAAS_GDatabase::NOTICE_OPT, null );
		$fallback = get_option( LVAAS_GDatabase::FALLBACK_OPT, null );
		$fetched  = is_array( $fallback ) && isset( $fallback['fetched_at'] ) ? (int) $fallback['fetched_at'] : 0;
		?>
		<div class="wrap lvaas-members">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'LVAAS Members', 'lvaas-membership' ); ?></h1>

			<style>
				.lvaas-members .lvaas-actions { display:flex; gap:.35em; flex-wrap:wrap; margin:.75em 0 1em; }
				.lvaas-members .lvaas-actions .button { font-size:11px; line-height:1.6; height:auto; padding:1px 8px; min-height:0; }
				.lvaas-members .lvaas-toolbar { display:flex; gap:.5em; align-items:center; margin:1em 0; flex-wrap:wrap; }
				.lvaas-members .lvaas-toolbar input[type="search"] { min-width: 18em; }
				.lvaas-members th[data-key] { cursor:pointer; user-select:none; white-space:nowrap; }
				.lvaas-members th[data-key]::after { content: " \2195"; color:#bbb; font-weight:normal; }
				.lvaas-members th.sorted-asc::after  { content: " \2191"; color:#1d2327; }
				.lvaas-members th.sorted-desc::after { content: " \2193"; color:#1d2327; }
				.lvaas-members .lvaas-count { color:#646970; }
				.lvaas-members .lvaas-stale { margin:1em 0; }
			</style>

			<div class="lvaas-actions">
				<?php foreach ( $actions as $a ) :
					if ( ! current_user_can( $a['cap'] ) ) {
						continue;
					}
					$url = add_query_arg( 'page', $a['slug'], admin_url( 'admin.php' ) );
					?>
					<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $a['label'] ); ?></a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! $can_list ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'You do not have permission to view the member list. Use the actions above for tasks you can perform.', 'lvaas-membership' ); ?></p>
				</div>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $error !== null ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php esc_html_e( 'Unable to load members.', 'lvaas-membership' ); ?></strong>
						<?php esc_html_e( 'Check the configuration in Settings (Google Sheet ID and Service Account JSON).', 'lvaas-membership' ); ?>
					</p>
					<p>
						<?php
						echo esc_html( sprintf(
							/* translators: %s: error reason */
							__( 'Details: %s', 'lvaas-membership' ),
							$error
						) );
						?>
					</p>
					<?php if ( current_user_can( LVAAS_Admin_Settings::CAPABILITY ) ) : ?>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
								<?php esc_html_e( 'Open Settings', 'lvaas-membership' ); ?> &rarr;
							</a>
						</p>
					<?php endif; ?>
				</div>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( is_array( $notice ) && ( $notice['type'] ?? '' ) === 'stale' ) : ?>
				<div class="notice notice-warning inline lvaas-stale">
					<p>
						<?php
						echo esc_html( sprintf(
							/* translators: %s: human time diff */
							__( 'Stale data — last refreshed %s ago.', 'lvaas-membership' ),
							human_time_diff( (int) ( $notice['fetched_at'] ?? 0 ), time() )
						) );
						?>
					</p>
				</div>
			<?php elseif ( $fetched > 0 ) : ?>
				<p class="description">
					<?php
					echo esc_html( sprintf(
						/* translators: %s: human time diff */
						__( 'Last refreshed %s ago.', 'lvaas-membership' ),
						human_time_diff( $fetched, time() )
					) );
					?>
				</p>
			<?php endif; ?>

			<div class="lvaas-toolbar">
				<label for="lvaas-members-search" class="screen-reader-text">
					<?php esc_html_e( 'Search members', 'lvaas-membership' ); ?>
				</label>
				<input type="search" id="lvaas-members-search" placeholder="<?php esc_attr_e( 'Search…', 'lvaas-membership' ); ?>">
				<button type="button" id="lvaas-members-export" class="button button-secondary">
					<?php esc_html_e( 'Export CSV', 'lvaas-membership' ); ?>
				</button>
				<span class="lvaas-count">
					<?php esc_html_e( 'Showing', 'lvaas-membership' ); ?>
					<span id="lvaas-members-count">—</span>
				</span>
			</div>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th data-key="last"><?php   esc_html_e( 'Last',   'lvaas-membership' ); ?></th>
						<th data-key="first"><?php  esc_html_e( 'First',  'lvaas-membership' ); ?></th>
						<th data-key="email"><?php  esc_html_e( 'Email',  'lvaas-membership' ); ?></th>
						<th data-key="phone"><?php  esc_html_e( 'Phone',  'lvaas-membership' ); ?></th>
						<th data-key="status"><?php esc_html_e( 'Status', 'lvaas-membership' ); ?></th>
					</tr>
				</thead>
				<tbody id="lvaas-members-tbody"></tbody>
			</table>
		</div>
		<?php
	}
}
