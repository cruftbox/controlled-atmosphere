<?php
/**
 * Uninstall routine.
 *
 * Removes local data only. Records already written to the user's AT Protocol
 * repo are deliberately left alone: deleting someone's data in their own repo
 * because they removed a plugin would be destructive and surprising.
 *
 * @package ControlledAtmosphere
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove the verification file, but only if this plugin created it -- never a
// file placed by hand or by another tool.
$ca_settings = get_option( 'controlled_atmosphere_state' );

if ( is_array( $ca_settings ) && ! empty( $ca_settings['verification_file'] ) ) {
	$ca_file = $ca_settings['verification_file'];

	if ( is_string( $ca_file ) && file_exists( $ca_file ) ) {
		wp_delete_file( $ca_file );
	}
}

delete_option( 'controlled_atmosphere_settings' );
delete_option( 'controlled_atmosphere_state' );
delete_option( 'controlled_atmosphere_blob_cache' );
delete_transient( 'controlled_atmosphere_session' );
delete_transient( 'controlled_atmosphere_notice' );

global $wpdb;

$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
		'_controlled_atmosphere_uri',
		'_controlled_atmosphere_cid',
		'_controlled_atmosphere_rkey',
		'_controlled_atmosphere_exclude'
	)"
);
