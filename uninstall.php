<?php
/**
 * Uninstall cleanup for Simply WebP.
 *
 * Removes the options created by the plugin when the user deletes it from the
 * WordPress admin. Already-converted WebP files are left untouched because they
 * are the live site content (the originals have been replaced in place and
 * cannot be restored).
 *
 * @package Simply_WebP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'simply_webp_url_map' );
delete_option( 'simply_webp_settings' );
delete_option( 'simply_webp_extra_post_types' );

// On multisite, clean up the options on every site as well.
if ( is_multisite() ) {
	$simply_webp_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $simply_webp_site_ids as $simply_webp_site_id ) {
		switch_to_blog( (int) $simply_webp_site_id );
		delete_option( 'simply_webp_url_map' );
		delete_option( 'simply_webp_settings' );
		delete_option( 'simply_webp_extra_post_types' );
		restore_current_blog();
	}
}
