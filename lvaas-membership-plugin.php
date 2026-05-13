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
