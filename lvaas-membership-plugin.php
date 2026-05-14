<?php
/**
 * Plugin Name:       LVAAS Membership
 * Plugin URI:        https://github.com/lvaas/lvaas-membership-plugin
 * Description:       Manages WordPress user access based on the LVAAS external membership database (Google Sheet → pluggable source of truth).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            LVAAS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lvaas-membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LVAAS_MEMBERSHIP_VERSION', '0.1.0' );
define( 'LVAAS_MEMBERSHIP_PLUGIN_FILE', __FILE__ );
define( 'LVAAS_MEMBERSHIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LVAAS_MEMBERSHIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LVAAS_MEMBERSHIP_MENU_SLUG', 'lvaas-membership' );
define( 'LVAAS_MEMBERSHIP_USER_META_EMAIL', 'lvaas_email' );

if ( is_readable( LVAAS_MEMBERSHIP_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/interface-user-source.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-member.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-member-validator.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-config.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-gdatabase.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-audit-log.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-settings.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-members.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-add-users.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-prune-users.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-history.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-admin-portal.php';
require_once LVAAS_MEMBERSHIP_PLUGIN_DIR . 'includes/class-lvaas-auth-gate.php';

function lvaas_membership_source(): User_Source_Interface {
	static $instance = null;
	if ( $instance === null ) {
		$instance = apply_filters( 'lvaas_user_source', new LVAAS_GDatabase() );
	}
	return $instance;
}

( new LVAAS_Auth_Gate() )->register();

if ( is_admin() ) {
	( new LVAAS_Admin_Portal() )->register();
	( new LVAAS_Admin_Members() )->register();
	( new LVAAS_Admin_Add_Users() )->register();
	( new LVAAS_Admin_Prune_Users() )->register();
	( new LVAAS_Admin_History() )->register();
	( new LVAAS_Admin_Settings() )->register();
}

register_activation_hook( __FILE__, 'lvaas_membership_activate' );
register_deactivation_hook( __FILE__, 'lvaas_membership_deactivate' );

function lvaas_membership_activate() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'LVAAS Membership requires PHP 8.0 or higher.', 'lvaas-membership' ),
			esc_html__( 'Plugin activation error', 'lvaas-membership' ),
			array( 'back_link' => true )
		);
	}
}

function lvaas_membership_deactivate() {
}
