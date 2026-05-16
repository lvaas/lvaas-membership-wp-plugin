<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies LVAAS-derived attributes (role, names, lvaas_email meta, optional
 * Simple Restrict permission) to any WP user whose email matches an LVAAS
 * member. Triggered via the user_register action so it fires uniformly for
 * accounts created by the Add Users flow, public self-registration, REST,
 * and the wp-admin Users → Add New form.
 */
final class LVAAS_User_Provisioning {
	public function register(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
	}

	public function on_user_register( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User || $user->user_email === '' ) {
			return;
		}
		$member = $this->find_lvaas_member( $user->user_email );
		if ( $member === null ) {
			return;
		}
		$this->apply_lvaas_attributes( $user, $member );
	}

	public function apply_lvaas_attributes( WP_User $user, LVAAS_Member $member ): void {
		$update = array(
			'ID'         => $user->ID,
			'first_name' => $member->first,
			'last_name'  => $member->last,
			'role'       => LVAAS_Config::get_provisioned_role(),
		);
		$display = trim( $member->first . ' ' . $member->last );
		if ( $display !== '' ) {
			$update['display_name'] = $display;
		}
		wp_update_user( $update );

		update_user_meta( $user->ID, LVAAS_MEMBERSHIP_USER_META_EMAIL, $member->email );

		$sr_slug = LVAAS_Config::get_simple_restrict_permission();
		if ( $sr_slug !== ''
			&& LVAAS_Config::simple_restrict_available()
			&& get_term_by( 'slug', $sr_slug, LVAAS_Config::SR_TAXONOMY ) ) {
			update_user_meta( $user->ID, LVAAS_Config::SR_META_PREFIX . $sr_slug, 'yes' );
		}
	}

	private function find_lvaas_member( string $email ): ?LVAAS_Member {
		$needle = LVAAS_Member::normalize_email( $email );
		if ( $needle === '' ) {
			return null;
		}
		try {
			$members = lvaas_membership_source()->get_members();
		} catch ( \Throwable $e ) {
			return null;
		}
		foreach ( $members as $m ) {
			if ( LVAAS_Member::normalize_email( $m->email ) === $needle ) {
				return $m;
			}
		}
		return null;
	}
}
