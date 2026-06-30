<?php
/**
 * Image-to-WebP conversion core.
 *
 * A pure conversion primitive whose only responsibility is writing a single
 * image file out as WebP. Shared by the upload-time auto-conversion path and
 * the bulk conversion engine so they apply identical quality settings.
 *
 * Context-specific work — MIME / server-capability checks, deleting the
 * original, updating WordPress attachment meta, and post-content reference
 * replacement — is left to the caller. The pipeline itself is:
 * Imagick read -> autoOrient -> WebP write -> optional EXIF strip.
 *
 * @package Simply_WebP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/* === Conversion settings === */

/**
 * Default conversion settings, used when the settings page has not been saved
 * yet or when individual keys are missing.
 *
 * - quality        : Lossy WebP quality for JPEG, 1-100. 80 stays visually
 *                    clean while producing a meaningful size reduction.
 * - png_lossless   : Encode PNG as lossless WebP (preserves transparency and
 *                    avoids fringing on logos and text).
 * - strip_metadata : Strip EXIF (smaller files, no GPS location leak).
 */
const SIMPLY_WEBP_DEFAULTS = array(
	'quality'        => 80,
	'png_lossless'   => true,
	'strip_metadata' => true,
);

/**
 * Option name used to persist the settings. Shared by the settings page and
 * the uninstaller.
 */
const SIMPLY_WEBP_SETTINGS_OPTION = 'simply_webp_settings';

/**
 * Return the active conversion settings (saved option merged with defaults).
 *
 * Reflects values saved on the settings page in includes/admin.php. Missing
 * keys (settings page never saved, or front-end contexts where the admin UI
 * is not loaded) fall back to SIMPLY_WEBP_DEFAULTS so the conversion core
 * always receives a complete settings array.
 *
 * @return array{quality:int,png_lossless:bool,strip_metadata:bool} Conversion settings.
 */
function simply_webp_get_settings() {
	$saved = get_option( SIMPLY_WEBP_SETTINGS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$config = array_merge( SIMPLY_WEBP_DEFAULTS, $saved );

	// Normalize types: the option can hold arbitrary values, so coerce them before the conversion core sees them.
	$config['quality']        = min( 100, max( 1, (int) $config['quality'] ) );
	$config['png_lossless']   = (bool) $config['png_lossless'];
	$config['strip_metadata'] = (bool) $config['strip_metadata'];

	return $config;
}

/**
 * MIME types eligible for bulk conversion. Anything else is left untouched.
 *
 * Limited to JPEG and PNG. BMP / HEIC are intentionally excluded from the
 * bulk pass; supporting them would require extra delegate-availability
 * handling and is not needed for the typical media library.
 */
const SIMPLY_WEBP_TARGET_MIMES = array(
	'image/jpeg',
	'image/png',
);


/* === Server capability check === */

/**
 * Determine whether this server can output WebP via Imagick.
 *
 * Returns false when the Imagick extension is missing or the WEBP format is
 * unsupported, in which case callers must skip conversion entirely. The
 * result is memoized in a static.
 *
 * @return bool True when WebP output is available.
 */
function simply_webp_server_supported() {
	static $supported = null;

	if ( null !== $supported ) {
		return $supported;
	}

	$supported = false;

	if ( class_exists( 'Imagick' ) ) {
		try {
			$formats   = Imagick::queryFormats( 'WEBP' );
			$supported = ! empty( $formats );
		} catch ( Exception $e ) {
			$supported = false;
		}
	}

	return $supported;
}


/* === Single-file WebP conversion === */

/**
 * Convert one image file to WebP and write it to $dest.
 *
 * PNG is encoded as lossless WebP with transparency; other formats use the
 * configured lossy quality. autoOrient() bakes EXIF orientation into the
 * pixels and stripImage() removes EXIF. This function only writes $dest; it
 * does not delete $src or update WordPress meta — those are the caller's
 * responsibility.
 *
 * On failure (read exception or write failure) the partial $dest is removed
 * and false is returned, signalling to the caller that the original must be
 * preserved.
 *
 * @param string $src  Absolute path of the source file.
 * @param string $dest Absolute path of the destination .webp file.
 * @param string $mime Source MIME (PNG triggers lossless encoding; others use the configured quality).
 * @return bool True when conversion succeeded and $dest exists.
 */
function simply_webp_convert_file( $src, $dest, $mime ) {

	if ( empty( $src ) || empty( $dest ) || ! file_exists( $src ) ) {
		return false;
	}

	$config       = simply_webp_get_settings();
	$use_lossless = ( 'image/png' === $mime && $config['png_lossless'] );

	// A single large image can exceed the default execution time. The bulk path drives
	// one conversion per AJAX request, so removing the per-request time limit ensures
	// the conversion is not interrupted by PHP's max_execution_time on shared hosting.
	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- See comment above.
		set_time_limit( 0 );
	}

	try {
		$imagick = new Imagick();
		$imagick->readImage( $src );

		// Bake EXIF orientation into the pixels so camera images render with the correct rotation.
		if ( method_exists( $imagick, 'autoOrient' ) ) {
			$imagick->autoOrient();
		}

		$imagick->setImageFormat( 'webp' );

		if ( $use_lossless ) {
			// PNG: lossless WebP, preserving transparency.
			$imagick->setOption( 'webp:lossless', 'true' );
		} else {
			// JPEG (and any other non-PNG input): lossy WebP at the configured quality.
			$imagick->setOption( 'webp:lossless', 'false' );
			$imagick->setImageCompressionQuality( (int) $config['quality'] );
		}
		// Favor compression efficiency over speed; conversion runs only once per file.
		$imagick->setOption( 'webp:method', '6' );

		if ( $config['strip_metadata'] ) {
			$imagick->stripImage();
		}

		$imagick->writeImage( $dest );
		$imagick->clear();
		$imagick->destroy();
	} catch ( Exception $e ) {
		// Clean up the partial destination on failure; the caller keeps the original.
		if ( file_exists( $dest ) ) {
			wp_delete_file( $dest );
		}
		return false;
	}

	// Treat a missing destination as failure.
	return file_exists( $dest );
}
