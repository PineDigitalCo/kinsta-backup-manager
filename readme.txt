=== Kinsta Backup Manager ===
Contributors: pinedigital
Tags: kinsta, backup, backups, hosting, api
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Manage Kinsta site backups from the WordPress admin using the Kinsta API.

== Description ==

Kinsta Backup Manager connects your WordPress site to the [Kinsta API](https://kinsta.com/docs/kinsta-api/) so administrators can:

* Save a company API key and select site/environment context
* List backups for the configured environment
* Create manual backups with an optional note
* Restore a backup to a target environment (with extra confirmation when restoring to Live)
* Delete backups and check async operation status

The plugin stores the API key encrypted in the database (OpenSSL). You may optionally define `KINSTA_API_KEY` in `wp-config.php` instead of saving a key in the UI.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kinsta-backup-manager`, or install the package via your preferred method.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Tools → Kinsta Backups** and add your Kinsta API key (from MyKinsta under Company settings → API Keys).
4. Choose the WordPress site and environment that match this installation, then save settings.

== Frequently Asked Questions ==

= Where do I get an API key? =

In [MyKinsta](https://my.kinsta.com/), open your company settings and create an API key under API Keys. The key needs permissions appropriate for sites, environments, and backups.

= Does this replace Kinsta’s own backups? =

No. It is a convenience layer in WordPress to trigger and monitor backup operations that still run in your Kinsta account.

= Who can restore to Live? =

Only users with the `manage_options` capability and the `kinsta_bm_restore_live` capability can restore to the live environment. Administrators receive that capability on plugin activation.

= How do I run PHPStan or work on the plugin from Git? =

Run `composer install` in the plugin directory, then `composer analyse`. Generated `vendor/` is for local development only and must not be included in distributable plugin packages.

== Changelog ==

= 1.0.1 =
* Maintenance and hardening updates.

= 1.0.0 =
* Initial release.
