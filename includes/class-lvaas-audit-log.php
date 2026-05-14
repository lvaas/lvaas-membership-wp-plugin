<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Audit_Log {
	public const OPT_KEY     = 'lvaas_audit_log';
	public const MAX_ENTRIES = 1000;

	public const ACTION_ADD          = 'add';
	public const ACTION_PRUNE_REVOKE = 'prune_revoke';
	public const ACTION_PRUNE_DELETE = 'prune_delete';

	/**
	 * Append an entry. Most recent first.
	 *
	 * @param string $action  one of the ACTION_* constants
	 * @param int[]  $affected WP user IDs touched by this action
	 */
	public static function append( string $action, array $affected ): void {
		$log = (array) get_option( self::OPT_KEY, array() );
		array_unshift( $log, array(
			'timestamp' => time(),
			'actor_id'  => get_current_user_id(),
			'action'    => $action,
			'affected'  => array_values( array_map( 'intval', $affected ) ),
		) );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPT_KEY, $log, false );
	}

	/** @return array<int, array{timestamp:int, actor_id:int, action:string, affected:int[]}> */
	public static function entries( int $limit = 100 ): array {
		$log = (array) get_option( self::OPT_KEY, array() );
		return array_slice( $log, 0, max( 0, $limit ) );
	}

	public static function action_label( string $action ): string {
		switch ( $action ) {
			case self::ACTION_ADD:          return __( 'Add users', 'lvaas-membership' );
			case self::ACTION_PRUNE_REVOKE: return __( 'Prune (revoke)', 'lvaas-membership' );
			case self::ACTION_PRUNE_DELETE: return __( 'Prune (delete)', 'lvaas-membership' );
		}
		return $action;
	}
}
