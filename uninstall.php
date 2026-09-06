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

delete_option( 'controlled_atmosphere_settings' );
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
