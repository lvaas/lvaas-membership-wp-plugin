<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface User_Source_Interface {
	/**
	 * @return LVAAS_Member[]
	 */
	public function get_members(): array;

	public function is_email_allowed( string $email ): bool;
}
