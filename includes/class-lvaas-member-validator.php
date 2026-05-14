<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Member_Validator {
	public const VALID_CAT   = array( 'R', 'L', 'H', 'U', 'J', 'F1', 'F2', 'FD', 'FJ' );
	public const SILENT_CAT  = array( 'P' );
	public const VALID_TYPE  = array( 'Full', 'Assoc' );
	public const SILENT_TYPE = array( 'Prospect' );

	/**
	 * @param array<int, array<string, string>> $rows  Assoc rows keyed by header, plus `__sheet_row`.
	 * @return array{members: LVAAS_Member[], invalid: array<int, array{row:int, reason:string}>}
	 */
	public function validate_rows( array $rows ): array {
		$members = array();
		$invalid = array();

		foreach ( $rows as $row ) {
			$sheet_row = (int) ( $row['__sheet_row'] ?? 0 );
			$res       = $this->validate_row( $row, $sheet_row );
			if ( $res['member'] instanceof LVAAS_Member ) {
				$members[] = $res['member'];
			} elseif ( ! $res['silent'] ) {
				$invalid[] = array(
					'row'    => $sheet_row,
					'reason' => (string) $res['reason'],
				);
			}
		}

		return array(
			'members' => $members,
			'invalid' => $invalid,
		);
	}

	/**
	 * @return array{member: ?LVAAS_Member, silent: bool, reason: ?string}
	 */
	private function validate_row( array $row, int $sheet_row ): array {
		$username = trim( (string) ( $row['username'] ?? '' ) );
		$email    = trim( (string) ( $row['email'] ?? '' ) );
		$cat      = trim( (string) ( $row['mbr_cat'] ?? '' ) );
		$type     = trim( (string) ( $row['mbr_type'] ?? '' ) );

		if ( in_array( $cat, self::SILENT_CAT, true ) || in_array( $type, self::SILENT_TYPE, true ) ) {
			return array(
				'member' => null,
				'silent' => true,
				'reason' => null,
			);
		}

		if ( ! in_array( $cat, self::VALID_CAT, true ) ) {
			return array(
				'member' => null,
				'silent' => false,
				'reason' => "unknown mbr_cat '{$cat}'",
			);
		}
		if ( ! in_array( $type, self::VALID_TYPE, true ) ) {
			return array(
				'member' => null,
				'silent' => false,
				'reason' => "unknown mbr_type '{$type}'",
			);
		}

		if ( $username === '' && $email === '' ) {
			return array(
				'member' => null,
				'silent' => false,
				'reason' => 'missing both username and email',
			);
		}

		if ( $username === '' ) {
			$username = LVAAS_Member::normalize_email( $email );
		}

		$member = new LVAAS_Member(
			$username,
			$email,
			trim( (string) ( $row['first'] ?? '' ) ),
			trim( (string) ( $row['last']  ?? '' ) ),
			$cat,
			$type,
			trim( (string) ( $row['phone'] ?? '' ) ),
			trim( (string) ( $row['codes'] ?? '' ) )
		);

		return array(
			'member' => $member,
			'silent' => false,
			'reason' => null,
		);
	}
}
