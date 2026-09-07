<?php
/**
 * Plugin Name:       Controlled Atmosphere
 * Plugin URI:        https://github.com/cruftbox/controlled-atmosphere
 * Description:       Publishes Standard.site records for your posts so Bluesky renders them as native "View Publication" articles. Never posts to your feed.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Michael Pusateri
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       controlled-atmosphere
 *
 * @package ControlledAtmosphere
 */

namespace ControlledAtmosphere;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

/**
 * Absolute path to the plugin directory, with trailing slash.
 */
define( 'ControlledAtmosphere\PATH', plugin_dir_path( __FILE__ ) );

/**
 * Option key holding all plugin settings as a single array.
 *
 * One option keeps reads to a single autoloaded row and makes the
 * uninstall routine trivial.
 */
const OPTION_KEY = 'controlled_atmosphere_settings';

/**
 * Post meta keys.
 *
 * Underscore-prefixed so they stay out of the custom fields UI.
 */
const META_URI     = '_controlled_atmosphere_uri';
const META_CID     = '_controlled_atmosphere_cid';
const META_RKEY    = '_controlled_atmosphere_rkey';
const META_EXCLUDE = '_controlled_atmosphere_exclude';

require_once PATH . 'includes/class-settings.php';
require_once PATH . 'includes/class-atproto-client.php';
require_once PATH . 'includes/class-publication.php';
require_once PATH . 'includes/class-blobs.php';
require_once PATH . 'includes/class-post-meta.php';
require_once PATH . 'includes/class-document.php';

/**
 * Boots the plugin.
 *
 * Everything is wired on plugins_loaded so that the frontend hooks and the
 * .well-known handler are registered regardless of whether credentials are
 * configured. Each component decides for itself whether it has enough
 * configuration to act.
 */
function bootstrap(): void {
	Settings::instance()->init();
	Publication::instance()->init();
	Post_Meta::instance()->init();
	Document::instance()->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Returns a single setting, or the whole settings array when no key is given.
 *
 * @param string|null $key     Setting name.
 * @param mixed       $default Value returned when the setting is absent.
 * @return mixed
 */
function get_setting( ?string $key = null, $default = null ) {
	$settings = get_option( OPTION_KEY, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	if ( null === $key ) {
		return $settings;
	}

	return $settings[ $key ] ?? $default;
}

/**
 * Returns the configured app password.
 *
 * A wp-config.php constant takes precedence over the stored option, so users
 * who prefer to keep the secret out of the database can do so.
 *
 * @return string Empty string when unconfigured.
 */
function get_app_password(): string {
	if ( defined( 'CONTROLLED_ATMOSPHERE_APP_PASSWORD' ) && CONTROLLED_ATMOSPHERE_APP_PASSWORD ) {
		return (string) CONTROLLED_ATMOSPHERE_APP_PASSWORD;
	}

	return (string) get_setting( 'app_password', '' );
}

/**
 * Whether the app password is supplied by a constant rather than the database.
 */
function app_password_is_constant(): bool {
	return defined( 'CONTROLLED_ATMOSPHERE_APP_PASSWORD' ) && CONTROLLED_ATMOSPHERE_APP_PASSWORD;
}

/**
 * Flushes rewrite rules on activation.
 *
 * The .well-known endpoint is served by inspecting the request rather than by
 * a rewrite rule, so this is only here to clear any stale 404 caching a host
 * may have applied to the path.
 */
function activate(): void {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Cleans up scheduled events on deactivation.
 *
 * Remote records are deliberately left in place. Deactivating a plugin must
 * not destroy the user's data in their own repo.
 */
function deactivate(): void {
	wp_clear_scheduled_hook( 'controlled_atmosphere_retry_queue' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
