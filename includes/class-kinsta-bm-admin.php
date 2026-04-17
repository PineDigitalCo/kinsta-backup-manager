<?php
/**
 * Admin UI and form handlers.
 *
 * @package Kinsta_BM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kinsta_BM_Admin {

	private const MENU_SLUG = 'kinsta-backup-manager';
	private const NONCE     = 'kinsta_bm_nonce';
	private const TRANSIENT_BACKUPS_PREFIX = 'kinsta_bm_backups_';
	private const TRANSIENT_SITES_PREFIX   = 'kinsta_bm_sites_';
	private const TRANSIENT_USERS_KEY      = 'kinsta_bm_company_users';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_remove_legacy_default_notify_option' ), 0 );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	public function maybe_remove_legacy_default_notify_option(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( false !== get_transient( 'kinsta_bm_legacy_notify_default_cleared' ) ) {
			return;
		}
		delete_option( 'kinsta_bm_default_notify_user_id' );
		set_transient( 'kinsta_bm_legacy_notify_default_cleared', '1', 10 * YEAR_IN_SECONDS );
	}

	public function register_menu(): void {
		add_management_page(
			__( 'Kinsta Backups', 'kinsta-backup-manager' ),
			__( 'Kinsta Backups', 'kinsta-backup-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_admin_scripts( string $hook ): void {
		if ( $hook !== 'tools_page_' . self::MENU_SLUG ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sites = $this->load_sorted_sites_for_settings();
		if ( empty( $sites ) ) {
			return;
		}
		$plugin_file = dirname( __DIR__ ) . '/kinsta-backup-manager.php';
		$script_rel  = 'assets/kinsta-bm-settings.js';
		$script_path = dirname( __DIR__ ) . '/' . $script_rel;
		$url         = plugins_url( $script_rel, $plugin_file );
		$ver         = is_readable( $script_path ) ? (string) filemtime( $script_path ) : '1.0';
		wp_register_script( 'kinsta-bm-settings', $url, array(), $ver, true );
		wp_localize_script(
			'kinsta-bm-settings',
			'kinstaBmSettings',
			array(
				'sites' => $this->build_sites_env_payload( $sites ),
				'i18n'  => array(
					'select'         => __( '— Select —', 'kinsta-backup-manager' ),
					'envPlaceholder' => __( 'Environment UUID', 'kinsta-backup-manager' ),
					'noEnvsHint'     => __( 'No environments in cached site list. Re-save settings after selecting the site, or enter the environment UUID.', 'kinsta-backup-manager' ),
				),
			)
		);
		wp_enqueue_script( 'kinsta-bm-settings' );
	}

	public function render_admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'tools_page_' . self::MENU_SLUG ) {
			return;
		}
		$uid   = get_current_user_id();
		$flash = get_transient( 'kinsta_bm_admin_notice_' . $uid );
		if ( ! is_array( $flash ) || ! isset( $flash['message'], $flash['type'] ) ) {
			return;
		}
		delete_transient( 'kinsta_bm_admin_notice_' . $uid );
		$type = in_array( $flash['type'], array( 'success', 'error', 'warning', 'info' ), true )
			? $flash['type']
			: 'info';
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			wp_kses_post( (string) $flash['message'] )
		);
	}

	public function handle_post(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		if ( ! isset( $_POST['kinsta_bm_action'] ) || ! is_string( $_POST['kinsta_bm_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( $_POST['kinsta_bm_action'] );
		check_admin_referer( self::NONCE, self::NONCE );

		$redirect = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		if ( isset( $_POST['kinsta_bm_tab'] ) && $_POST['kinsta_bm_tab'] === 'settings' ) {
			$redirect = add_query_arg( 'tab', 'settings', $redirect );
		} elseif ( in_array( $action, array( 'create_manual_backup', 'restore_backup', 'delete_backup', 'check_operation' ), true ) ) {
			$redirect = add_query_arg( 'tab', 'backups', $redirect );
		}

		switch ( $action ) {
			case 'save_settings':
				$this->handle_save_settings();
				break;
			case 'create_manual_backup':
				$this->handle_create_manual_backup();
				break;
			case 'restore_backup':
				$this->handle_restore_backup();
				break;
			case 'delete_backup':
				$this->handle_delete_backup();
				break;
			case 'check_operation':
				$this->handle_check_operation();
				break;
			default:
				return;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	private function handle_save_settings(): void {
		$token = $this->get_api_token_for_edit();
		if ( $token === '' ) {
			$this->set_flash( __( 'API key is required (or define KINSTA_API_KEY in wp-config.php).', 'kinsta-backup-manager' ), 'error' );
			return;
		}

		$api   = new Kinsta_BM_API( $token );
		$valid = $api->validate_key();
		if ( 200 !== $valid['code'] || ! is_array( $valid['body'] ) || empty( $valid['body']['company'] ) ) {
			$msg = is_array( $valid['body'] ) && isset( $valid['body']['message'] )
				? (string) $valid['body']['message']
				: ( $valid['error'] ?? __( 'Validation failed.', 'kinsta-backup-manager' ) );
			$this->set_flash( esc_html( $msg ), 'error' );
			return;
		}

		$company_id = sanitize_text_field( (string) $valid['body']['company'] );

		if ( kinsta_bm_get_config_api_key() === '' && isset( $_POST['kinsta_bm_api_key'] ) && is_string( $_POST['kinsta_bm_api_key'] ) && $_POST['kinsta_bm_api_key'] !== '' ) {
			$enc = Kinsta_BM_Crypto::encrypt( sanitize_text_field( wp_unslash( $_POST['kinsta_bm_api_key'] ) ) );
			if ( false === $enc ) {
				$this->set_flash( __( 'Could not encrypt the API key. Ensure PHP OpenSSL is available.', 'kinsta-backup-manager' ), 'error' );
				return;
			}
			update_option( 'kinsta_bm_api_key_cipher', $enc, false );
		}

		update_option( 'kinsta_bm_company_id', $company_id, false );

		if ( isset( $_POST['kinsta_bm_site_id'] ) && is_string( $_POST['kinsta_bm_site_id'] ) ) {
			update_option( 'kinsta_bm_site_id', sanitize_text_field( wp_unslash( $_POST['kinsta_bm_site_id'] ) ), false );
		}
		if ( isset( $_POST['kinsta_bm_env_id'] ) && is_string( $_POST['kinsta_bm_env_id'] ) ) {
			update_option( 'kinsta_bm_env_id', sanitize_text_field( wp_unslash( $_POST['kinsta_bm_env_id'] ) ), false );
		}

		delete_transient( self::TRANSIENT_USERS_KEY );
		$this->purge_sites_transient_for_company( $company_id );
		$this->purge_backup_transients();

		$this->set_flash( __( 'Settings saved.', 'kinsta-backup-manager' ), 'success' );
	}

	private function handle_create_manual_backup(): void {
		$api = $this->api_or_bail();
		if ( null === $api ) {
			return;
		}
		$env_id = (string) get_option( 'kinsta_bm_env_id', '' );
		if ( $env_id === '' ) {
			$this->set_flash( __( 'Select an environment in Settings first.', 'kinsta-backup-manager' ), 'error' );
			return;
		}
		$payload = array();
		if ( isset( $_POST['kinsta_bm_backup_tag'] ) && is_string( $_POST['kinsta_bm_backup_tag'] ) ) {
			$tag = sanitize_text_field( wp_unslash( $_POST['kinsta_bm_backup_tag'] ) );
			if ( $tag !== '' ) {
				$payload['tag'] = $tag;
			}
		}
		$res = $api->create_manual_backup( $env_id, $payload );
		$this->flash_from_api_response( $res, __( 'Manual backup request accepted.', 'kinsta-backup-manager' ) );
		$this->purge_backup_transients();
	}

	private function handle_restore_backup(): void {
		$api = $this->api_or_bail();
		if ( null === $api ) {
			return;
		}
		$backup_id = isset( $_POST['kinsta_bm_backup_id'] ) ? absint( $_POST['kinsta_bm_backup_id'] ) : 0;
		$target    = isset( $_POST['kinsta_bm_target_env_id'] ) ? sanitize_text_field( wp_unslash( $_POST['kinsta_bm_target_env_id'] ) ) : '';
		$notify    = isset( $_POST['kinsta_bm_notify_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['kinsta_bm_notify_user_id'] ) ) : '';
		if ( $backup_id < 1 || $target === '' || $notify === '' ) {
			$this->set_flash( __( 'Restore requires backup, target environment, and notify user.', 'kinsta-backup-manager' ), 'error' );
			return;
		}

		$site_id = (string) get_option( 'kinsta_bm_site_id', '' );
		if ( $site_id === '' ) {
			$this->set_flash( __( 'Site ID missing in settings.', 'kinsta-backup-manager' ), 'error' );
			return;
		}

		$env_meta = $this->get_environment_meta( $api, $site_id, $target );
		if ( null === $env_meta ) {
			$this->set_flash( __( 'Could not resolve target environment.', 'kinsta-backup-manager' ), 'error' );
			return;
		}

		if ( $env_meta['name'] === 'live' ) {
			if ( ! current_user_can( 'kinsta_bm_restore_live' ) ) {
				$this->set_flash( __( 'You do not have permission to restore to the live environment.', 'kinsta-backup-manager' ), 'error' );
				return;
			}
			$confirm = isset( $_POST['kinsta_bm_confirm_live'] ) ? sanitize_text_field( wp_unslash( $_POST['kinsta_bm_confirm_live'] ) ) : '';
			if ( $confirm !== 'RESTORE' ) {
				$this->set_flash( __( 'Type RESTORE to confirm restoring to Live.', 'kinsta-backup-manager' ), 'error' );
				return;
			}
		}

		$res = $api->restore_backup( $target, $backup_id, $notify );
		$this->flash_from_api_response( $res, __( 'Restore started.', 'kinsta-backup-manager' ) );
		$this->purge_backup_transients();
	}

	private function handle_delete_backup(): void {
		$api = $this->api_or_bail();
		if ( null === $api ) {
			return;
		}
		$backup_id = isset( $_POST['kinsta_bm_backup_id'] ) ? absint( $_POST['kinsta_bm_backup_id'] ) : 0;
		$confirm   = isset( $_POST['kinsta_bm_confirm_delete'] ) ? sanitize_text_field( wp_unslash( $_POST['kinsta_bm_confirm_delete'] ) ) : '';
		if ( $backup_id < 1 || (string) $backup_id !== $confirm ) {
			$this->set_flash( __( 'Enter the backup ID to confirm deletion.', 'kinsta-backup-manager' ), 'error' );
			return;
		}
		$res = $api->delete_backup( $backup_id );
		$this->flash_from_api_response( $res, __( 'Backup removal started.', 'kinsta-backup-manager' ) );
		$this->purge_backup_transients();
	}

	private function handle_check_operation(): void {
		$api = $this->api_or_bail();
		if ( null === $api ) {
			return;
		}
		$op = isset( $_POST['kinsta_bm_operation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['kinsta_bm_operation_id'] ) ) : '';
		if ( $op === '' ) {
			$this->set_flash( __( 'No operation ID.', 'kinsta-backup-manager' ), 'error' );
			return;
		}
		$res = $api->get_operation( $op );
		if ( isset( $res['error'] ) ) {
			$this->set_flash( esc_html( $res['error'] ), 'error' );
			return;
		}
		$code = $res['code'];
		$body = $res['body'];
		$msg  = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : __( 'Unknown response.', 'kinsta-backup-manager' );
		if ( 200 === $code ) {
			$this->set_flash( sprintf( '<strong>%s</strong> — %s', esc_html__( 'Complete', 'kinsta-backup-manager' ), esc_html( $msg ) ), 'success' );
		} elseif ( 202 === $code ) {
			$this->set_flash( sprintf( '<strong>%s</strong> — %s', esc_html__( 'In progress', 'kinsta-backup-manager' ), esc_html( $msg ) ), 'info' );
		} elseif ( 500 === $code ) {
			$this->set_flash( esc_html( $msg ), 'error' );
		} else {
			$this->set_flash( esc_html( $msg ), 'warning' );
		}
	}

	/**
	 * @param array{code:int,body:array<string,mixed>|null,error?:string} $res
	 */
	private function flash_from_api_response( array $res, string $ok_message ): void {
		if ( isset( $res['error'] ) ) {
			$this->set_flash( esc_html( $res['error'] ), 'error' );
			return;
		}
		$code = $res['code'];
		$body = $res['body'];
		if ( in_array( $code, array( 200, 202 ), true ) ) {
			$op = is_array( $body ) && isset( $body['operation_id'] ) ? (string) $body['operation_id'] : '';
			$api_msg = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : '';
			$parts   = array( esc_html( $ok_message ) );
			if ( $api_msg !== '' ) {
				$parts[] = esc_html( $api_msg );
			}
			if ( $op !== '' ) {
				$parts[] = '<code>' . esc_html( $op ) . '</code>';
			}
			$this->set_flash( implode( ' ', $parts ), 202 === $code ? 'info' : 'success' );
			return;
		}
		$msg = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : __( 'Request failed.', 'kinsta-backup-manager' );
		$this->set_flash( esc_html( $msg ), 'error' );
	}

	private function set_flash( string $message, string $type = 'info' ): void {
		set_transient(
			'kinsta_bm_admin_notice_' . get_current_user_id(),
			array( 'message' => $message, 'type' => $type ),
			120
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kinsta-backup-manager' ) );
		}

		$tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'backups' ? 'backups' : 'settings';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Kinsta Backups', 'kinsta-backup-manager' ) . '</h1>';

		$base = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%1$s" class="nav-tab%3$s">%2$s</a>',
			esc_url( $base ),
			esc_html__( 'Settings', 'kinsta-backup-manager' ),
			$tab === 'settings' ? ' nav-tab-active' : ''
		);
		printf(
			'<a href="%1$s" class="nav-tab%3$s">%2$s</a>',
			esc_url( add_query_arg( 'tab', 'backups', $base ) ),
			esc_html__( 'Backups', 'kinsta-backup-manager' ),
			$tab === 'backups' ? ' nav-tab-active' : ''
		);
		echo '</h2>';

		if ( $tab === 'settings' ) {
			$this->render_settings_tab();
		} else {
			$this->render_backups_tab();
		}

		echo '</div>';
	}

	private function render_settings_tab(): void {
		$site_id = (string) get_option( 'kinsta_bm_site_id', '' );
		$env_id  = (string) get_option( 'kinsta_bm_env_id', '' );

		$sites          = $this->load_sorted_sites_for_settings();
		$environments   = array();

		foreach ( $sites as $s ) {
			if ( (string) $s['id'] === $site_id && ! empty( $s['environments'] ) ) {
				$environments = $s['environments'];
				break;
			}
		}

		$key_constant = kinsta_bm_get_config_api_key() !== '';

		echo '<p class="description">';
		esc_html_e( 'Prefer defining KINSTA_API_KEY in wp-config.php so the key is not stored in the database. Otherwise the key is encrypted with PHP OpenSSL using WordPress salts.', 'kinsta-backup-manager' );
		echo '</p>';

		echo '<form method="post" style="max-width:720px">';
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<input type="hidden" name="kinsta_bm_action" value="save_settings" />';
		echo '<input type="hidden" name="kinsta_bm_tab" value="settings" />';

		echo '<table class="form-table" role="presentation">';

		if ( $key_constant ) {
			echo '<tr><th>' . esc_html__( 'API key', 'kinsta-backup-manager' ) . '</th><td><em>' . esc_html__( 'Set via KINSTA_API_KEY in wp-config.php', 'kinsta-backup-manager' ) . '</em></td></tr>';
		} else {
			echo '<tr><th scope="row"><label for="kinsta_bm_api_key">' . esc_html__( 'API key', 'kinsta-backup-manager' ) . '</label></th><td>';
			echo '<input type="password" class="regular-text" id="kinsta_bm_api_key" name="kinsta_bm_api_key" autocomplete="off" placeholder="' . esc_attr__( 'Paste key to add or replace', 'kinsta-backup-manager' ) . '" />';
			echo '<p class="description">' . esc_html__( 'Leave blank to keep the stored key. Generate keys in MyKinsta under Company settings → API Keys.', 'kinsta-backup-manager' ) . '</p>';
			echo '</td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'WordPress Site', 'kinsta-backup-manager' ) . '</th><td>';
		if ( empty( $sites ) ) {
			echo '<p class="description">' . esc_html__( 'Save a valid API key to load sites.', 'kinsta-backup-manager' ) . '</p>';
			echo '<input type="text" class="regular-text" name="kinsta_bm_site_id" value="' . esc_attr( $site_id ) . '" placeholder="' . esc_attr__( 'Site UUID', 'kinsta-backup-manager' ) . '" />';
		} else {
			echo '<select name="kinsta_bm_site_id" id="kinsta_bm_site_id">';
			echo '<option value="">' . esc_html__( '— Select —', 'kinsta-backup-manager' ) . '</option>';
			foreach ( $sites as $s ) {
				printf(
					'<option value="%1$s" %3$s>%2$s</option>',
					esc_attr( (string) $s['id'] ),
					esc_html( (string) ( $s['display_name'] !== '' ? $s['display_name'] : $s['name'] ) ),
					selected( (string) $s['id'], $site_id, false )
				);
			}
			echo '</select>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Environment', 'kinsta-backup-manager' ) . '</th><td>';
		echo '<div id="kinsta_bm_env_field_wrap">';
		if ( empty( $environments ) && $site_id !== '' ) {
			echo '<p class="description">' . esc_html__( 'No environments in cached site list. Re-save settings after selecting the site, or enter the environment UUID.', 'kinsta-backup-manager' ) . '</p>';
		}
		if ( ! empty( $environments ) ) {
			echo '<select name="kinsta_bm_env_id" id="kinsta_bm_env_id">';
			echo '<option value="">' . esc_html__( '— Select —', 'kinsta-backup-manager' ) . '</option>';
			foreach ( $environments as $e ) {
				printf(
					'<option value="%1$s" %3$s>%2$s (%1$s)</option>',
					esc_attr( (string) $e['id'] ),
					esc_html( (string) ( $e['display_name'] !== '' ? $e['display_name'] : $e['name'] ) ),
					selected( (string) $e['id'], $env_id, false )
				);
			}
			echo '</select>';
		} else {
			echo '<input type="text" class="regular-text" name="kinsta_bm_env_id" id="kinsta_bm_env_id" value="' . esc_attr( $env_id ) . '" placeholder="' . esc_attr__( 'Environment UUID', 'kinsta-backup-manager' ) . '" />';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Backups and manual backup actions use this environment.', 'kinsta-backup-manager' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		submit_button( __( 'Save settings', 'kinsta-backup-manager' ) );
		echo '</form>';
	}

	/**
	 * @return list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function load_sorted_sites_for_settings(): array {
		$sites      = array();
		$company_id = (string) get_option( 'kinsta_bm_company_id', '' );
		$api        = $this->get_client_if_configured();
		if ( null !== $api && $company_id !== '' ) {
			$sites = $this->get_cached_sites( $api, $company_id );
		}
		if ( ! empty( $sites ) ) {
			$sites = $this->sort_sites_for_display( $sites );
		}
		return $sites;
	}

	/**
	 * @param list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}> $sites
	 * @return list<array{id:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function build_sites_env_payload( array $sites ): array {
		$out = array();
		foreach ( $sites as $s ) {
			$envs = array();
			foreach ( $s['environments'] as $e ) {
				$envs[] = array(
					'id'           => (string) $e['id'],
					'name'         => (string) $e['name'],
					'display_name' => (string) $e['display_name'],
				);
			}
			$out[] = array(
				'id'            => (string) $s['id'],
				'environments'  => $envs,
			);
		}
		return $out;
	}

	/**
	 * @return list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function get_cached_sites( Kinsta_BM_API $api, string $company_id ): array {
		if ( $company_id === '' ) {
			return array();
		}
		$key    = self::TRANSIENT_SITES_PREFIX . md5( $company_id );
		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $this->coerce_sites_from_transient( $cached );
		}
		$res = $api->get_sites( $company_id, true );
		if ( 200 !== $res['code'] || ! is_array( $res['body'] ) ) {
			return array();
		}
		$sites = $this->parse_sites_response( $res['body'] );
		set_transient( $key, $sites, 5 * MINUTE_IN_SECONDS );
		return $sites;
	}

	private function purge_sites_transient_for_company( string $company_id ): void {
		if ( $company_id !== '' ) {
			delete_transient( self::TRANSIENT_SITES_PREFIX . md5( $company_id ) );
		}
	}

	/**
	 * @param list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}> $sites
	 * @return list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function sort_sites_for_display( array $sites ): array {
		usort(
			$sites,
			static function ( array $a, array $b ): int {
				$la = $a['display_name'] !== '' ? $a['display_name'] : $a['name'];
				$lb = $b['display_name'] !== '' ? $b['display_name'] : $b['name'];
				return strnatcasecmp( $la, $lb );
			}
		);
		return $sites;
	}

	/**
	 * @return list<array{id:string,name:string,email:string}>
	 */
	private function get_cached_company_users( Kinsta_BM_API $api, string $company_id ): array {
		$cached = get_transient( self::TRANSIENT_USERS_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $this->coerce_users_from_transient( $cached );
		}
		$res = $api->get_company_users( $company_id );
		if ( 200 !== $res['code'] || ! is_array( $res['body'] ) ) {
			return array();
		}
		$out = array();
		$users = $res['body']['company']['users'] ?? array();
		if ( ! is_array( $users ) ) {
			return array();
		}
		foreach ( $users as $row ) {
			if ( ! is_array( $row ) || empty( $row['user'] ) || ! is_array( $row['user'] ) ) {
				continue;
			}
			$u = $row['user'];
			$out[] = array(
				'id'    => (string) ( $u['id'] ?? '' ),
				'name'  => (string) ( $u['full_name'] ?? '' ),
				'email' => (string) ( $u['email'] ?? '' ),
			);
		}
		set_transient( self::TRANSIENT_USERS_KEY, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	private function render_backups_tab(): void {
		$api = $this->get_client_if_configured();
		if ( null === $api ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Configure your API key under the Settings tab.', 'kinsta-backup-manager' );
			echo '</p></div>';
			return;
		}

		$env_id = (string) get_option( 'kinsta_bm_env_id', '' );
		if ( $env_id === '' ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Choose a site and environment in Settings.', 'kinsta-backup-manager' );
			echo '</p></div>';
			return;
		}

		$site_id = (string) get_option( 'kinsta_bm_site_id', '' );
		$targets = $this->get_restore_targets( $api, $site_id );

		$company_id   = (string) get_option( 'kinsta_bm_company_id', '' );
		$notify_users = array();
		if ( $company_id !== '' ) {
			$notify_users = $this->get_cached_company_users( $api, $company_id );
		}

		$backups = $this->get_cached_backups( $api, $env_id );

		echo '<h2>' . esc_html__( 'Create manual backup', 'kinsta-backup-manager' ) . '</h2>';
		echo '<form method="post" style="margin-bottom:2em">';
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<input type="hidden" name="kinsta_bm_action" value="create_manual_backup" />';
		echo '<p><label>' . esc_html__( 'Optional tag', 'kinsta-backup-manager' ) . ' ';
		echo '<input type="text" name="kinsta_bm_backup_tag" class="regular-text" maxlength="120" /></label></p>';
		submit_button( __( 'Create manual backup', 'kinsta-backup-manager' ), 'secondary' );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Backups for selected environment', 'kinsta-backup-manager' ) . '</h2>';
		if ( null === $backups ) {
			echo '<p>' . esc_html__( 'Could not load backups. Check API permissions and environment ID.', 'kinsta-backup-manager' ) . '</p>';
			return;
		}

		if ( empty( $backups ) ) {
			echo '<p>' . esc_html__( 'No backups returned.', 'kinsta-backup-manager' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'kinsta-backup-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'kinsta-backup-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'kinsta-backup-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Note', 'kinsta-backup-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'kinsta-backup-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $backups as $b ) {
			$id = (int) $b['id'];
			$type = isset( $b['type'] ) ? (string) $b['type'] : '';
			$note = isset( $b['note'] ) ? (string) $b['note'] : '';
			$ts   = isset( $b['created_at'] ) ? (int) $b['created_at'] : 0;
			$date_fmt = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) round( $ts / 1000 ) );
			$date     = ( $ts > 0 && is_string( $date_fmt ) ) ? $date_fmt : '—';

			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $id ) . '</code></td>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . esc_html( $date ) . '</td>';
			echo '<td>' . esc_html( $note ) . '</td>';
			echo '<td>';

			echo '<details style="margin-bottom:8px"><summary>' . esc_html__( 'Restore', 'kinsta-backup-manager' ) . '</summary>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( self::NONCE, self::NONCE );
			echo '<input type="hidden" name="kinsta_bm_action" value="restore_backup" />';
			echo '<input type="hidden" name="kinsta_bm_backup_id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<p><label>' . esc_html__( 'Target environment', 'kinsta-backup-manager' ) . '<br />';
			echo '<select name="kinsta_bm_target_env_id">';
			foreach ( $targets as $t ) {
				printf(
					'<option value="%1$s">%2$s (%3$s)</option>',
					esc_attr( $t['id'] ),
					esc_html( $t['label'] ),
					esc_html( $t['name'] )
				);
			}
			echo '</select></label></p>';
			echo '<p><label>' . esc_html__( 'Notify user', 'kinsta-backup-manager' ) . '<br />';
			if ( ! empty( $notify_users ) ) {
				echo '<select name="kinsta_bm_notify_user_id">';
				echo '<option value="">' . esc_html__( '— Select —', 'kinsta-backup-manager' ) . '</option>';
				foreach ( $notify_users as $u ) {
					printf(
						'<option value="%1$s">%2$s</option>',
						esc_attr( $u['id'] ),
						esc_html( $u['name'] . ' <' . $u['email'] . '>' )
					);
				}
				echo '</select></label></p>';
			} else {
				echo '<input type="text" class="regular-text" name="kinsta_bm_notify_user_id" value="" placeholder="' . esc_attr__( 'User UUID', 'kinsta-backup-manager' ) . '" /></label></p>';
			}
			if ( current_user_can( 'kinsta_bm_restore_live' ) ) {
				echo '<p><label>' . esc_html__( 'If restoring to Live, type RESTORE', 'kinsta-backup-manager' ) . '<br />';
				echo '<input type="text" name="kinsta_bm_confirm_live" class="regular-text" autocomplete="off" /></label></p>';
			}
			submit_button( __( 'Restore backup', 'kinsta-backup-manager' ), 'primary small', 'submit', false );
			echo '</form></details>';

			echo '<details><summary>' . esc_html__( 'Delete', 'kinsta-backup-manager' ) . '</summary>';
			echo '<form method="post" style="margin-top:8px">';
			wp_nonce_field( self::NONCE, self::NONCE );
			echo '<input type="hidden" name="kinsta_bm_action" value="delete_backup" />';
			echo '<input type="hidden" name="kinsta_bm_backup_id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<p><label>' . esc_html__( 'Type backup ID to confirm', 'kinsta-backup-manager' ) . '<br />';
			echo '<input type="text" name="kinsta_bm_confirm_delete" class="regular-text" autocomplete="off" /></label></p>';
			submit_button( __( 'Delete backup', 'kinsta-backup-manager' ), 'delete small', 'submit', false );
			echo '</form></details>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Check async operation', 'kinsta-backup-manager' ) . '</h2>';
		echo '<form method="post" class="inline">';
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<input type="hidden" name="kinsta_bm_action" value="check_operation" />';
		echo '<input type="text" name="kinsta_bm_operation_id" class="regular-text" placeholder="backups:add-manual-…" /> ';
		submit_button( __( 'Check status', 'kinsta-backup-manager' ), 'secondary small', 'submit', false );
		echo '</form>';
		echo '<p class="description"><a href="https://my.kinsta.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open MyKinsta', 'kinsta-backup-manager' ) . '</a></p>';
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function get_cached_backups( Kinsta_BM_API $api, string $env_id ): ?array {
		$key = self::TRANSIENT_BACKUPS_PREFIX . md5( $env_id );
		$cached = get_transient( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $this->coerce_backups_from_transient( $cached );
		}
		$res = $api->get_backups( $env_id );
		if ( 200 !== $res['code'] || ! is_array( $res['body'] ) ) {
			return null;
		}
		$list = $res['body']['environment']['backups'] ?? null;
		if ( ! is_array( $list ) ) {
			return array();
		}
		$rows = $this->coerce_backups_from_transient( $list );
		set_transient( $key, $rows, MINUTE_IN_SECONDS );
		return $rows;
	}

	private function purge_backup_transients(): void {
		$env_id = (string) get_option( 'kinsta_bm_env_id', '' );
		if ( $env_id !== '' ) {
			delete_transient( self::TRANSIENT_BACKUPS_PREFIX . md5( $env_id ) );
		}
	}

	/**
	 * @return list<array{id:string,label:string,name:string}>
	 */
	private function get_restore_targets( Kinsta_BM_API $api, string $site_id ): array {
		if ( $site_id === '' ) {
			return array();
		}
		$company_id = (string) get_option( 'kinsta_bm_company_id', '' );
		if ( $company_id === '' ) {
			return array();
		}
		$sites = $this->get_cached_sites( $api, $company_id );
		foreach ( $sites as $s ) {
			if ( (string) $s['id'] !== $site_id ) {
				continue;
			}
			$out = array();
			foreach ( $s['environments'] as $e ) {
				$out[] = array(
					'id'    => (string) $e['id'],
					'label' => (string) ( $e['display_name'] !== '' ? $e['display_name'] : ( $e['name'] !== '' ? $e['name'] : $e['id'] ) ),
					'name'  => (string) $e['name'],
				);
			}
			return $out;
		}
		return array();
	}

	/**
	 * @return array{name:string}|null
	 */
	private function get_environment_meta( Kinsta_BM_API $api, string $site_id, string $env_id ): ?array {
		foreach ( $this->get_restore_targets( $api, $site_id ) as $t ) {
			if ( $t['id'] === $env_id ) {
				return array( 'name' => $t['name'] );
			}
		}
		return null;
	}

	/**
	 * @param mixed $cached
	 * @return list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function coerce_sites_from_transient( $cached ): array {
		if ( ! is_array( $cached ) ) {
			return array();
		}
		$out = array();
		foreach ( $cached as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			$id = isset( $site['id'] ) ? (string) $site['id'] : '';
			if ( $id === '' ) {
				continue;
			}
			$envs = array();
			$raw  = $site['environments'] ?? array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $e ) {
					if ( ! is_array( $e ) ) {
						continue;
					}
					$eid = isset( $e['id'] ) ? (string) $e['id'] : '';
					if ( $eid === '' ) {
						continue;
					}
					$envs[] = array(
						'id'           => $eid,
						'name'         => isset( $e['name'] ) ? (string) $e['name'] : '',
						'display_name' => isset( $e['display_name'] ) ? (string) $e['display_name'] : '',
					);
				}
			}
			$out[] = array(
				'id'           => $id,
				'name'         => isset( $site['name'] ) ? (string) $site['name'] : '',
				'display_name' => isset( $site['display_name'] ) ? (string) $site['display_name'] : '',
				'environments' => $envs,
			);
		}
		return $out;
	}

	/**
	 * @param mixed $cached
	 * @return list<array{id:string,name:string,email:string}>
	 */
	private function coerce_users_from_transient( $cached ): array {
		if ( ! is_array( $cached ) ) {
			return array();
		}
		$out = array();
		foreach ( $cached as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( $id === '' ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'name'  => isset( $row['name'] ) ? (string) $row['name'] : '',
				'email' => isset( $row['email'] ) ? (string) $row['email'] : '',
			);
		}
		return $out;
	}

	/**
	 * @param mixed $cached
	 * @return list<array<string, mixed>>
	 */
	private function coerce_backups_from_transient( $cached ): array {
		if ( ! is_array( $cached ) ) {
			return array();
		}
		$out = array();
		foreach ( $cached as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return list<array{id:string,name:string,display_name:string,environments:list<array{id:string,name:string,display_name:string}>}>
	 */
	private function parse_sites_response( array $body ): array {
		$sites = $body['company']['sites'] ?? array();
		if ( ! is_array( $sites ) ) {
			return array();
		}
		$out = array();
		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}
			$envs = array();
			$raw  = $site['environments'] ?? array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $e ) {
					if ( ! is_array( $e ) ) {
						continue;
					}
					$envs[] = array(
						'id'           => (string) ( $e['id'] ?? '' ),
						'name'         => (string) ( $e['name'] ?? '' ),
						'display_name' => (string) ( $e['display_name'] ?? '' ),
					);
				}
			}
			$out[] = array(
				'id'            => (string) ( $site['id'] ?? '' ),
				'name'          => (string) ( $site['name'] ?? '' ),
				'display_name'  => (string) ( $site['display_name'] ?? '' ),
				'environments'  => $envs,
			);
		}
		return $out;
	}

	private function get_api_token_for_edit(): string {
		$from_config = kinsta_bm_get_config_api_key();
		if ( $from_config !== '' ) {
			return $from_config;
		}
		$cipher = (string) get_option( 'kinsta_bm_api_key_cipher', '' );
		if ( $cipher === '' ) {
			if ( isset( $_POST['kinsta_bm_api_key'] ) && is_string( $_POST['kinsta_bm_api_key'] ) ) {
				return sanitize_text_field( wp_unslash( $_POST['kinsta_bm_api_key'] ) );
			}
			return '';
		}
		$plain = Kinsta_BM_Crypto::decrypt( $cipher );
		if ( false === $plain || $plain === '' ) {
			return '';
		}
		if ( isset( $_POST['kinsta_bm_api_key'] ) && is_string( $_POST['kinsta_bm_api_key'] ) && $_POST['kinsta_bm_api_key'] !== '' ) {
			return sanitize_text_field( wp_unslash( $_POST['kinsta_bm_api_key'] ) );
		}
		return $plain;
	}

	private function get_client_if_configured(): ?Kinsta_BM_API {
		$from_config = kinsta_bm_get_config_api_key();
		if ( $from_config !== '' ) {
			return new Kinsta_BM_API( $from_config );
		}
		$cipher = (string) get_option( 'kinsta_bm_api_key_cipher', '' );
		if ( $cipher === '' ) {
			return null;
		}
		$plain = Kinsta_BM_Crypto::decrypt( $cipher );
		if ( false === $plain || $plain === '' ) {
			return null;
		}
		return new Kinsta_BM_API( $plain );
	}

	private function api_or_bail(): ?Kinsta_BM_API {
		$api = $this->get_client_if_configured();
		if ( null === $api ) {
			$this->set_flash( __( 'API key not configured.', 'kinsta-backup-manager' ), 'error' );
			return null;
		}
		return $api;
	}
}
