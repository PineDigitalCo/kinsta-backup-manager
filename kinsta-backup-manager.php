<?php
/**
 * Plugin Name: Kinsta Backup Manager
 * Description: Manage Kinsta site backups from WordPress via the Kinsta API (list, manual backup, restore, delete).
 * Version: 1.0.0
 * Author: Site Owner
 * License: GPL v2 or later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Kinsta_BM
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KINSTA_BM_VERSION', '1.0.0' );
define( 'KINSTA_BM_PLUGIN_FILE', __FILE__ );
define( 'KINSTA_BM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * API key from wp-config when defined.
 *
 * @return string
 */
function kinsta_bm_get_config_api_key(): string {
	if ( ! defined( 'KINSTA_API_KEY' ) ) {
		return '';
	}
	$v = constant( 'KINSTA_API_KEY' );
	return is_string( $v ) && $v !== '' ? $v : '';
}

require_once KINSTA_BM_PLUGIN_DIR . 'includes/class-kinsta-bm-crypto.php';
require_once KINSTA_BM_PLUGIN_DIR . 'includes/class-kinsta-bm-api.php';
require_once KINSTA_BM_PLUGIN_DIR . 'includes/class-kinsta-bm-admin.php';

/**
 * Plugin activation: grant live-restore capability to administrators.
 */
function kinsta_bm_activate(): void {
	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( 'kinsta_bm_restore_live' ) ) {
		$role->add_cap( 'kinsta_bm_restore_live' );
	}
}

/**
 * Plugin uninstall cleanup (options only; constants are not stored).
 */
function kinsta_bm_uninstall(): void {
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->remove_cap( 'kinsta_bm_restore_live' );
	}
	delete_option( 'kinsta_bm_api_key_cipher' );
	delete_option( 'kinsta_bm_company_id' );
	delete_option( 'kinsta_bm_site_id' );
	delete_option( 'kinsta_bm_env_id' );
	delete_option( 'kinsta_bm_default_notify_user_id' );
}

register_activation_hook( __FILE__, 'kinsta_bm_activate' );
register_uninstall_hook( __FILE__, 'kinsta_bm_uninstall' );

if ( is_admin() ) {
	Kinsta_BM_Admin::instance();
}
