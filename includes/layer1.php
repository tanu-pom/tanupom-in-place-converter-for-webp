<?php
/**
 * Upload-time auto-conversion to WebP.
 *
 * On the `wp_handle_upload` filter (which runs before the attachment post is
 * created), the freshly uploaded original is converted to WebP, the original
 * file is deleted, and the upload array's file / url / type are swapped to
 * the WebP version. As a result the attachment that WordPress creates is
 * image/webp, and every subsize WordPress generates is built from the WebP
 * original.
 *
 * The handler never breaks the upload: if the server cannot output WebP or
 * conversion fails, the upload array is returned unchanged and the original
 * file remains in place.
 *
 * @package Tanupom_In_Place_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * MIME types eligible for upload-time auto-conversion.
 *
 * Broader than the bulk path (TANUPOM_IPC_TARGET_MIMES = JPEG / PNG) because
 * uploads arrive in many formats. HEIC / HEIF are only actually converted on
 * servers whose Imagick build has the HEIC delegate; on unsupported servers
 * the conversion core returns false and the original is left untouched.
 */
const TANUPOM_IPC_UPLOAD_MIMES = array(
	'image/jpeg',
	'image/png',
	'image/bmp',
	'image/x-ms-bmp',
	'image/heic',
	'image/heif',
);


/**
 * Convert an uploaded image to WebP and swap it in for the original.
 *
 * Runs on the `wp_handle_upload` filter, before the attachment post is
 * created. Replaces the upload array's file / url / type with the WebP
 * version so the resulting attachment and every regenerated subsize are
 * WebP.
 *
 * For unsupported MIME types, an unsupported server, or any conversion
 * failure, the upload array is returned unchanged so the upload still
 * succeeds.
 *
 * @param array $upload `array( 'file' => absolute path, 'url' => URL, 'type' => MIME )`.
 * @return array The replaced upload array, or the original unchanged.
 */
function tanupom_ipc_convert_upload( $upload ) {

	// Defensive: bail if the upload array does not have the expected shape.
	if ( ! is_array( $upload ) || empty( $upload['file'] ) || empty( $upload['type'] ) ) {
		return $upload;
	}

	// Pass through unsupported MIME types.
	if ( ! in_array( $upload['type'], TANUPOM_IPC_UPLOAD_MIMES, true ) ) {
		return $upload;
	}

	// Fall back to the original when the server cannot produce WebP.
	if ( ! tanupom_ipc_server_supported() ) {
		return $upload;
	}

	$src = $upload['file'];
	if ( ! file_exists( $src ) ) {
		return $upload;
	}

	// Destination: same directory, extension replaced with .webp. Collisions are resolved by wp_unique_filename().
	$dir       = dirname( $src );
	$webp_base = preg_replace( '/\.[^.]+$/', '', wp_basename( $src ) ) . '.webp';
	$webp_base = wp_unique_filename( $dir, $webp_base );
	$dest      = trailingslashit( $dir ) . $webp_base;

	// Delegate the actual encoding (orientation, transparency, quality, EXIF strip)
	// to the conversion core. On failure the core cleans up the partial output and
	// returns false; we then pass through the original.
	if ( ! tanupom_ipc_convert_file( $src, $dest, $upload['type'] ) ) {
		return $upload;
	}

	// Success: delete the original and point the upload array at the new WebP file.
	wp_delete_file( $src );

	$upload_dir     = wp_upload_dir();
	$upload['file'] = $dest;
	// Rebuild the URL via basedir => baseurl substitution so a custom upload_path is handled correctly.
	$upload['url']  = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $dest );
	$upload['type'] = 'image/webp';

	return $upload;
}
add_filter( 'wp_handle_upload', 'tanupom_ipc_convert_upload' );
