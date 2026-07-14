<?php
/**
 * Uninstall cleanup for Tanupom In-Place Converter for WebP.
 *
 * Removes the options created by the plugin when the user deletes it from the
 * WordPress admin. Already-converted WebP files are left untouched because they
 * are the live site content (the originals have been replaced in place and
 * cannot be restored).
 *
 * @package Tanupom_In_Place_Converter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tanupom_ipc_url_map' );
delete_option( 'tanupom_ipc_settings' );
delete_option( 'tanupom_ipc_extra_post_types' );

// On multisite, clean up the options on every site as well.
if ( is_multisite() ) {
	$tanupom_ipc_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $tanupom_ipc_site_ids as $tanupom_ipc_site_id ) {
		switch_to_blog( (int) $tanupom_ipc_site_id );
		delete_option( 'tanupom_ipc_url_map' );
		delete_option( 'tanupom_ipc_settings' );
		delete_option( 'tanupom_ipc_extra_post_types' );
		restore_current_blog();
	}
}
