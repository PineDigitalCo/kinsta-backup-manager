<?php
/**
 * Encrypt API key at rest when not using wp-config constant.
 *
 * @package Kinsta_BM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-CBC using WordPress salts (not for high-threat models; prefer KINSTA_API_KEY in wp-config.php).
 */
final class Kinsta_BM_Crypto {

	/**
	 * @param string $plaintext Raw API key.
	 * @return string|false Base64(iv+ciphertext) or false on failure.
	 */
	public static function encrypt( string $plaintext ) {
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$key = hash( 'sha256', wp_salt( 'kinsta-bm-crypto' ), true );
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ct ) {
			return false;
		}
		return base64_encode( $iv . $ct );
	}

	/**
	 * @param string $encoded From encrypt().
	 * @return string|false Plaintext or false.
	 */
	public static function decrypt( string $encoded ) {
		if ( $encoded === '' || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return false;
		}
		$key = hash( 'sha256', wp_salt( 'kinsta-bm-crypto' ), true );
		$iv  = substr( $raw, 0, 16 );
		$ct  = substr( $raw, 16 );
		$pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $pt ? false : $pt;
	}
}
