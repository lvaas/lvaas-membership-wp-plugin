<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVAAS_Config {
	public const OPT_SHEET_ID         = 'lvaas_sheet_id';
	public const OPT_SERVICE_ACCOUNT  = 'lvaas_service_account';
	public const OPT_PROVISIONED_ROLE = 'lvaas_provisioned_role';
	public const OPT_STALE_TTL        = 'lvaas_stale_ttl_seconds';
	public const OPT_SR_PERMISSION    = 'lvaas_simple_restrict_permission';

	public const SR_TAXONOMY    = 'simple-restrict-permission';
	public const SR_META_PREFIX = 'simple-restrict-';

	public const DEFAULT_PROVISIONED_ROLE = 'subscriber';
	public const DEFAULT_INVITE_INTRO     = 'Hi {first_name} {last_name}! Welcome to LVAAS. An account has been created for you on our website.';

	public static function get_sheet_id(): string {
		return (string) get_option( self::OPT_SHEET_ID, '' );
	}

	public static function set_sheet_id( string $sheet_id ): bool {
		return update_option( self::OPT_SHEET_ID, $sheet_id );
	}

	/** Last 6 chars, for masked UI display. */
	public static function get_sheet_id_masked(): string {
		$id = self::get_sheet_id();
		return $id === '' ? '' : '…' . substr( $id, -6 );
	}

	/** @return array<string,mixed>|null Decoded service-account JSON, or null if unset/corrupt. */
	public static function get_service_account_json(): ?array {
		$stored = (string) get_option( self::OPT_SERVICE_ACCOUNT, '' );
		if ( $stored === '' ) {
			return null;
		}
		$plain = self::decrypt( $stored );
		if ( $plain === null ) {
			return null;
		}
		$decoded = json_decode( $plain, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public static function set_service_account_json( string $json ): bool {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}
		$ciphertext = self::encrypt( $json );
		return update_option( self::OPT_SERVICE_ACCOUNT, $ciphertext, false );
	}

	public static function has_service_account(): bool {
		return self::get_service_account_json() !== null;
	}

	public static function get_service_account_masked(): string {
		$json = self::get_service_account_json();
		if ( $json === null ) {
			return '';
		}
		$cid = (string) ( $json['client_id'] ?? ( $json['client_email'] ?? '' ) );
		return $cid === '' ? '…(set)' : '…' . substr( $cid, -6 );
	}

	public static function get_provisioned_role(): string {
		$role = (string) get_option( self::OPT_PROVISIONED_ROLE, self::DEFAULT_PROVISIONED_ROLE );
		return $role === '' ? self::DEFAULT_PROVISIONED_ROLE : $role;
	}

	public static function set_provisioned_role( string $role ): bool {
		return update_option( self::OPT_PROVISIONED_ROLE, $role );
	}

	public static function simple_restrict_available(): bool {
		return taxonomy_exists( self::SR_TAXONOMY );
	}

	public static function get_simple_restrict_permission(): string {
		return (string) get_option( self::OPT_SR_PERMISSION, '' );
	}

	public static function set_simple_restrict_permission( string $slug ): bool {
		return update_option( self::OPT_SR_PERMISSION, $slug );
	}

	public static function get_stale_ttl_seconds(): int {
		$default = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$ttl     = (int) get_option( self::OPT_STALE_TTL, $default );
		return $ttl > 0 ? $ttl : $default;
	}

	private static function encryption_key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$material .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= SECURE_AUTH_KEY;
		}
		if ( $material === '' && function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'auth' );
		}
		if ( $material === '' ) {
			$material = 'lvaas-default-fallback';
		}
		return hash( 'sha256', 'lvaas-sa-v1:' . $material, true );
	}

	private static function encrypt( string $plaintext ): string {
		$key = self::encryption_key();
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( $ct === false ) {
			return '';
		}
		return 'v1:' . base64_encode( $iv . $ct );
	}

	private static function decrypt( string $stored ): ?string {
		if ( ! str_starts_with( $stored, 'v1:' ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( $raw === false || strlen( $raw ) < 17 ) {
			return null;
		}
		$iv  = substr( $raw, 0, 16 );
		$ct  = substr( $raw, 16 );
		$key = self::encryption_key();
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $pt === false ? null : $pt;
	}
}
