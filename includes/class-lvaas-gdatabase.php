<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Google\Client as Google_Client;
use Google\Service\Sheets as Google_Sheets;

final class LVAAS_GDatabase implements User_Source_Interface {
	public const REQUIRED_HEADERS = array( 'last', 'first', 'email', 'mbr_type', 'mbr_cat', 'codes', 'phone', 'username' );
	public const SHEET_TAB        = 'members';
	public const TRANSIENT_KEY    = 'lvaas_members_cache';
	public const FALLBACK_OPT     = 'lvaas_members_fallback';
	public const NOTICE_OPT       = 'lvaas_sync_notice';
	public const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;

	/** @return LVAAS_Member[] */
	public function get_members(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && isset( $cached['members'] ) ) {
			return $this->hydrate( $cached['members'] );
		}

		try {
			$rows      = $this->fetch_rows_remote();
			$validated = ( new LVAAS_Member_Validator() )->validate_rows( $rows );
			$payload   = array(
				'fetched_at' => time(),
				'members'    => array_map( static fn( LVAAS_Member $m ) => $m->to_array(), $validated['members'] ),
				'invalid'    => $validated['invalid'],
			);
			set_transient( self::TRANSIENT_KEY, $payload, self::CACHE_TTL );
			update_option( self::FALLBACK_OPT, $payload, false );
			$this->record_validation_outcome( $validated['invalid'] );
			return $validated['members'];
		} catch ( \Throwable $e ) {
			$fallback = get_option( self::FALLBACK_OPT, null );
			$stale_ok = is_array( $fallback )
				&& isset( $fallback['fetched_at'] )
				&& ( time() - (int) $fallback['fetched_at'] ) < LVAAS_Config::get_stale_ttl_seconds();
			if ( $stale_ok ) {
				$this->set_notice( array(
					'type'       => 'stale',
					'fetched_at' => (int) $fallback['fetched_at'],
					'reason'     => $e->getMessage(),
				) );
				return $this->hydrate( $fallback['members'] ?? array() );
			}
			$this->set_notice( array(
				'type'   => 'outage',
				'reason' => $e->getMessage(),
			) );
			throw $e;
		}
	}

	public function is_email_allowed( string $email ): bool {
		$needle = LVAAS_Member::normalize_email( $email );
		if ( $needle === '' ) {
			return false;
		}
		try {
			$members = $this->get_members();
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $members as $m ) {
			if ( LVAAS_Member::normalize_email( $m->email ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop the cache and refetch immediately.
	 *
	 * @return array{count:int, invalid:int, notice:mixed}
	 */
	public function force_refresh(): array {
		delete_transient( self::TRANSIENT_KEY );
		$members = $this->get_members();
		$cached  = get_transient( self::TRANSIENT_KEY );
		$invalid = is_array( $cached ) && isset( $cached['invalid'] ) ? count( $cached['invalid'] ) : 0;
		return array(
			'count'   => count( $members ),
			'invalid' => $invalid,
			'notice'  => get_option( self::NOTICE_OPT, null ),
		);
	}

	/**
	 * Authenticate and probe the sheet.
	 *
	 * @return array{sheet_name:string, column_count:int}
	 */
	public function test_connection(): array {
		$service  = new Google_Sheets( $this->build_client() );
		$sheet_id = LVAAS_Config::get_sheet_id();
		$meta     = $service->spreadsheets->get( $sheet_id );
		$title    = (string) $meta->getProperties()->getTitle();
		$resp     = $service->spreadsheets_values->get( $sheet_id, self::SHEET_TAB . '!1:1' );
		$values   = $resp->getValues() ?? array();
		$cols     = isset( $values[0] ) ? count( $values[0] ) : 0;
		return array(
			'sheet_name'   => $title,
			'column_count' => $cols,
		);
	}

	private function build_client(): Google_Client {
		$json = LVAAS_Config::get_service_account_json();
		if ( $json === null ) {
			throw new \RuntimeException( 'Service Account JSON is not configured.' );
		}
		if ( LVAAS_Config::get_sheet_id() === '' ) {
			throw new \RuntimeException( 'Google Sheet ID is not configured.' );
		}
		$client = new Google_Client();
		$client->setAuthConfig( $json );
		$client->setScopes( array( Google_Sheets::SPREADSHEETS_READONLY ) );
		return $client;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private function fetch_rows_remote(): array {
		$service  = new Google_Sheets( $this->build_client() );
		$sheet_id = LVAAS_Config::get_sheet_id();
		$resp     = $service->spreadsheets_values->get( $sheet_id, self::SHEET_TAB );
		$values   = $resp->getValues();
		if ( ! is_array( $values ) || count( $values ) === 0 ) {
			throw new \RuntimeException( "Sheet tab '" . self::SHEET_TAB . "' is empty." );
		}
		$headers = array_map( 'strval', $values[0] );
		$missing = array_values( array_diff( self::REQUIRED_HEADERS, $headers ) );
		if ( ! empty( $missing ) ) {
			throw new \RuntimeException( 'Missing required header(s): ' . implode( ', ', $missing ) );
		}
		$rows = array();
		$n    = count( $values );
		for ( $i = 1; $i < $n; $i++ ) {
			$row   = $values[ $i ];
			$assoc = array( '__sheet_row' => $i + 1 );
			foreach ( $headers as $col_idx => $h ) {
				$assoc[ $h ] = isset( $row[ $col_idx ] ) ? (string) $row[ $col_idx ] : '';
			}
			$rows[] = $assoc;
		}
		return $rows;
	}

	/** @param array<int, array<string,mixed>> $rows */
	private function hydrate( array $rows ): array {
		return array_map( static fn( $r ) => LVAAS_Member::from_array( (array) $r ), $rows );
	}

	/** @param array<int, array{row:int, reason:string}> $invalid */
	private function record_validation_outcome( array $invalid ): void {
		if ( empty( $invalid ) ) {
			delete_option( self::NOTICE_OPT );
			return;
		}
		foreach ( $invalid as $row ) {
			error_log( sprintf(
				'[LVAAS] excluded sheet row %d: %s',
				(int) ( $row['row'] ?? 0 ),
				(string) ( $row['reason'] ?? 'unknown' )
			) );
		}
		$this->set_notice( array(
			'type'  => 'invalid',
			'count' => count( $invalid ),
			'rows'  => $invalid,
		) );
	}

	private function set_notice( array $notice ): void {
		update_option( self::NOTICE_OPT, $notice, false );
	}
}
