<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Auth_Gate {
	public const STATUS_ALLOWED     = 'allowed';
	public const STATUS_DENIED      = 'denied';
	public const STATUS_UNAVAILABLE = 'unavailable';
	public const ERROR_CODE         = 'lvaas_not_member';

	public function register(): void {
		add_filter( 'registration_errors', array( $this, 'gate_registration' ), 10, 3 );
		add_action( 'lostpassword_post',   array( $this, 'gate_lostpassword' ), 10, 2 );
	}

	public function gate_registration( WP_Error $errors, string $sanitized_user_login, string $user_email ): WP_Error {
		if ( ! is_email( $user_email ) ) {
			return $errors;
		}
		$result = $this->authorize_email( $user_email );
		if ( $result['status'] !== self::STATUS_ALLOWED ) {
			$errors->add( self::ERROR_CODE, $result['message'] );
		}
		return $errors;
	}

	public function gate_lostpassword( WP_Error $errors, $user_data ): void {
		$email = '';
		if ( $user_data instanceof WP_User ) {
			$email = (string) $user_data->user_email;
		} elseif ( ! empty( $_POST['user_login'] ) ) {
			$input = trim( (string) wp_unslash( $_POST['user_login'] ) );
			if ( is_email( $input ) ) {
				$email = $input;
			}
		}
		if ( $email === '' ) {
			return;
		}
		$result = $this->authorize_email( $email );
		if ( $result['status'] !== self::STATUS_ALLOWED ) {
			$errors->add( self::ERROR_CODE, $result['message'] );
		}
	}

	/**
	 * @return array{status:string, message:string}
	 */
	public function authorize_email( string $email ): array {
		$needle = LVAAS_Member::normalize_email( $email );
		if ( $needle === '' ) {
			return array(
				'status'  => self::STATUS_DENIED,
				'message' => __( 'Email is required.', 'lvaas-membership' ),
			);
		}
		try {
			$members = lvaas_membership_source()->get_members();
		} catch ( \Throwable $e ) {
			return array(
				'status'  => self::STATUS_UNAVAILABLE,
				'message' => __( 'LVAAS member service is temporarily unavailable, please try again later.', 'lvaas-membership' ),
			);
		}
		foreach ( $members as $m ) {
			if ( LVAAS_Member::normalize_email( $m->email ) === $needle ) {
				return array( 'status' => self::STATUS_ALLOWED, 'message' => '' );
			}
		}
		$membership_url = esc_url( home_url( '/membership' ) );
		$message = sprintf(
			wp_kses(
				/* translators: %s: membership application URL */
				__( 'You must be an LVAAS member to log in to this site. <a href="%s">Apply for membership</a>.', 'lvaas-membership' ),
				array( 'a' => array( 'href' => array() ) )
			),
			$membership_url
		);
		return array( 'status' => self::STATUS_DENIED, 'message' => $message );
	}
}
