<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Member {
	public function __construct(
		public string $username,
		public string $email,
		public string $first,
		public string $last,
		public string $mbr_cat,
		public string $mbr_type,
		public string $phone,
		public string $codes
	) {}

	public function status_label(): string {
		$suffix_map = array(
			'R'  => '',
			'L'  => '(Life)',
			'H'  => '(Honorary)',
			'U'  => '(Student)',
			'J'  => '(Junior)',
			'F1' => '(Family)',
			'F2' => '(Family)',
			'FD' => '(Family)',
			'FJ' => '(Family)',
		);
		$suffix = $suffix_map[ $this->mbr_type ] ?? '';
		return $suffix === '' ? $this->mbr_cat : $this->mbr_cat . ' ' . $suffix;
	}

	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	public function to_array(): array {
		return array(
			'username' => $this->username,
			'email'    => $this->email,
			'first'    => $this->first,
			'last'     => $this->last,
			'mbr_cat'  => $this->mbr_cat,
			'mbr_type' => $this->mbr_type,
			'phone'    => $this->phone,
			'codes'    => $this->codes,
		);
	}

	public static function from_array( array $a ): self {
		return new self(
			(string) ( $a['username'] ?? '' ),
			(string) ( $a['email']    ?? '' ),
			(string) ( $a['first']    ?? '' ),
			(string) ( $a['last']     ?? '' ),
			(string) ( $a['mbr_cat']  ?? '' ),
			(string) ( $a['mbr_type'] ?? '' ),
			(string) ( $a['phone']    ?? '' ),
			(string) ( $a['codes']    ?? '' )
		);
	}
}
