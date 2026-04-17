<?php
/**
 * Kinsta API v2 client (wp_remote_*).
 *
 * @package Kinsta_BM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kinsta_BM_API {

	private const BASE_URL = 'https://api.kinsta.com/v2';

	/**
	 * @var string
	 */
	private $token;

	public function __construct( string $api_token ) {
		$this->token = $api_token;
	}

	/**
	 * @param array<string, mixed>|null $body JSON body for POST/PUT/PATCH/DELETE when needed.
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function request( string $method, string $path, ?array $body = null ): array {
		$url = self::BASE_URL . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = null;
		if ( $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			$data    = is_array( $decoded ) ? $decoded : null;
		}

		return array(
			'code' => $code,
			'body' => $data,
		);
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function validate_key(): array {
		return $this->request( 'GET', '/validate' );
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function get_sites( string $company_id, bool $include_environments = true ): array {
		$q = rawurlencode( $company_id );
		$e = $include_environments ? 'true' : 'false';
		return $this->request( 'GET', '/sites?company=' . $q . '&include_environments=' . $e );
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function get_company_users( string $company_id ): array {
		$id = rawurlencode( $company_id );
		return $this->request( 'GET', '/company/' . $id . '/users' );
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function get_backups( string $env_id ): array {
		$id = rawurlencode( $env_id );
		return $this->request( 'GET', '/sites/environments/' . $id . '/backups' );
	}

	/**
	 * @param array<string, mixed> $payload Optional e.g. tag.
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function create_manual_backup( string $env_id, array $payload = array() ): array {
		$id   = rawurlencode( $env_id );
		$body = count( $payload ) > 0 ? $payload : null;
		return $this->request( 'POST', '/sites/environments/' . $id . '/manual-backups', $body );
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function restore_backup( string $target_env_id, int $backup_id, string $notified_user_id ): array {
		$id = rawurlencode( $target_env_id );
		return $this->request(
			'POST',
			'/sites/environments/' . $id . '/backups/restore',
			array(
				'backup_id'         => $backup_id,
				'notified_user_id'  => $notified_user_id,
			)
		);
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function delete_backup( int $backup_id ): array {
		$id = rawurlencode( (string) $backup_id );
		return $this->request( 'DELETE', '/sites/environments/backups/' . $id );
	}

	/**
	 * @return array{code:int,body:array<string,mixed>|null,error?:string}
	 */
	public function get_operation( string $operation_id ): array {
		$id = rawurlencode( $operation_id );
		return $this->request( 'GET', '/operations/' . $id );
	}
}
