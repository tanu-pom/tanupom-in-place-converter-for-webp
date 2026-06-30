<?php
/**
 * Inventory scan that prepares for reference replacement.
 *
 * Converting the existing media library to WebP rewrites every JPEG / PNG URL
 * to a .webp URL. This file inspects post_content, postmeta, and options to
 * report where image URLs actually appear so the replacement step can scope
 * its coverage. Findings that live in serialized data (widgets / theme_mods
 * etc.) signal the need for serialize-safe replacement instead of naive
 * string substitution.
 *
 * Read-only: COUNT / SELECT only, never UPDATE.
 *
 * @package Simply_WebP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the upload-path fragment used to detect image URLs (e.g. "/wp-content/uploads/").
 *
 * Uses the path portion of the upload URL because it is shared across http,
 * https, protocol-relative, and root-relative spellings, so matching is
 * robust to URL-formatting differences.
 *
 * @return string Upload path fragment with a trailing slash.
 */
function simply_webp_uploads_path_needle() {
	$upload = wp_upload_dir();
	$path   = wp_parse_url( $upload['baseurl'], PHP_URL_PATH );
	if ( empty( $path ) ) {
		$path = '/wp-content/uploads';
	}
	return trailingslashit( $path );
}

/**
 * Inventories the places where JPEG / PNG image URLs appear.
 *
 * Counts rows in post_content, postmeta (excluding _wp_* keys), and options
 * whose value contains both the upload-path fragment and a JPEG / PNG
 * extension, and returns a sample of the keys / names involved.
 *
 * @return array Inventory result with the keys documented below.
 */
function simply_webp_scan_url_usage() {
	global $wpdb;

	$needle = simply_webp_uploads_path_needle();
	$like   = '%' . $wpdb->esc_like( $needle ) . '%';
	$jpg    = '%.jpg%';
	$jpeg   = '%.jpeg%';
	$png    = '%.png%';

	$result = array(
		'needle'       => $needle,
		'post_content' => array(),
		'postmeta'     => array(),
		'options'      => array(),
	);

	// --- post_content (count by post type) ---------------------------------
	// LIKE-based aggregate queries that WP_Query / get_posts cannot express
	// efficiently (COUNT / GROUP BY are required). Read-only, diagnostic, and
	// intentionally not cached so each run reflects live conversion progress.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$rows                   = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_type, COUNT(*) AS cnt
			 FROM {$wpdb->posts}
			 WHERE post_status NOT IN ( 'trash', 'auto-draft' )
			   AND post_content LIKE %s
			   AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
			 GROUP BY post_type
			 ORDER BY cnt DESC",
			$like,
			$jpg,
			$jpeg,
			$png
		),
		ARRAY_A
	);
	$result['post_content'] = $rows ? $rows : array();

	// --- postmeta (count by meta_key, excluding _wp_*) ---------------------
	// Another LIKE + GROUP BY aggregate. get_post_meta() is per-ID and not suitable for a global rollup.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$rows               = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_key, COUNT(*) AS cnt
			 FROM {$wpdb->postmeta}
			 WHERE meta_key NOT LIKE %s
			   AND meta_value LIKE %s
			   AND ( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )
			 GROUP BY meta_key
			 ORDER BY cnt DESC
			 LIMIT 50",
			$wpdb->esc_like( '_wp_' ) . '%',
			$like,
			$jpg,
			$jpeg,
			$png
		),
		ARRAY_A
	);
	$result['postmeta'] = $rows ? $rows : array();

	// --- options (count by option_name + serialized flag) ------------------
	// Diagnostic LIKE query to enumerate matching options. wp_load_alloptions()
	// misses autoload=no rows and an N+1 loop over options is too heavy, so we
	// query the table directly.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_value LIKE %s
			   AND ( option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s )
			 LIMIT 100",
			$like,
			$jpg,
			$jpeg,
			$png
		),
		ARRAY_A
	);
	$opts = array();
	if ( $rows ) {
		foreach ( $rows as $row ) {
			$opts[] = array(
				'option_name' => $row['option_name'],
				'serialized'  => is_serialized( $row['option_value'] ) ? 1 : 0,
				'length'      => strlen( $row['option_value'] ),
			);
		}
	}
	$result['options'] = $opts;

	return $result;
}
