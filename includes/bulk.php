<?php
/**
 * Bulk conversion engine for existing attachments.
 *
 * Converts media library attachments to WebP one at a time, in place.
 * Subsize regeneration is delegated to wp_generate_attachment_metadata() and
 * the WordPress attachment meta (_wp_attached_file, _wp_attachment_metadata,
 * post_mime_type) is kept consistent. Each successful conversion returns
 * old URL => new URL pairs that the caller feeds into reference replacement.
 *
 * The engine is idempotent (already-WebP attachments are skipped) and
 * resumable: a failed attachment leaves the original untouched and the next
 * batch picks up where the previous one stopped.
 *
 * @package Tanupom_In_Place_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/*
 * -----------------------------------------------------------------------------
 * Target attachment enumeration
 * -----------------------------------------------------------------------------
 */

/**
 * Count attachments still pending conversion (target MIME, not yet WebP).
 *
 * @return int Number of attachments remaining.
 */
function tanupom_ipc_count_pending() {
	global $wpdb;

	$mimes        = TANUPOM_IPC_TARGET_MIMES;
	$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

	// $placeholders is a self-built "%s,%s,..." sequence with no user input; the actual
	// values are passed to $wpdb->prepare() via $mimes and are properly escaped. The
	// variable-length IN-clause placeholders are a known blind spot for WPCS static
	// analysis, so the IN-clause rules are suppressed while the surrounding PreparedSQL
	// rule remains active. Direct query and no-caching are required because WP core
	// post counting does not support dynamic IN-clauses, and the count must reflect
	// live conversion progress.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( $placeholders )", $mimes ) );
}

/**
 * Total number of attachments currently matching a target MIME type (excludes
 * already converted WebP).
 *
 * Note: as conversion progresses the target-MIME count shrinks and the WebP
 * count grows, so the "total" moves over time. Use the initial pending count
 * as the progress-bar denominator for a stable value.
 *
 * @return int Current target-MIME count.
 */
function tanupom_ipc_count_target_total() {
	return tanupom_ipc_count_pending();
}

/**
 * Get the next $limit attachment IDs to convert (ascending by ID).
 *
 * @param int $limit Number of IDs to fetch.
 * @return int[] Attachment IDs.
 */
function tanupom_ipc_get_pending_ids( $limit ) {
	global $wpdb;

	$limit        = max( 1, (int) $limit );
	$mimes        = TANUPOM_IPC_TARGET_MIMES;
	$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );

	$args   = $mimes;
	$args[] = $limit;

	// $placeholders is a self-built "%s,%s,..." sequence with no user input; values
	// are passed via $args to $wpdb->prepare() and are properly escaped. The IN-clause
	// placeholders cannot be statically verified by WPCS, so the rule is suppressed.
	// Direct query / no-caching are required because the batch must see the latest
	// conversion state and core lookups do not support dynamic IN-clauses.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( $placeholders ) ORDER BY ID ASC LIMIT %d", $args ) ) );
}


/*
 * -----------------------------------------------------------------------------
 * URL collection / pair building / old-file deletion helpers
 * -----------------------------------------------------------------------------
 */

/**
 * Collect the URL paths for an attachment's original, every subsize, and the
 * -scaled source.
 *
 * Can be called before or after conversion (pre: old original + old meta;
 * post: new original + new meta).
 *
 * Returns the path portion of each URL (e.g. `/wp-content/uploads/foo-1024x768.jpg`),
 * not the full URL. Content may reference images using the original domain, a
 * clone or staging domain, protocol-relative, root-relative, or www-prefixed
 * forms; storing only the path keeps replacement domain-agnostic. This also
 * handles cloned environments where content retains source URLs while
 * wp_upload_dir() returns the new domain.
 *
 * @param string     $file Absolute path of the original (pre: old .jpg/.png; post: new .webp).
 * @param array|bool $meta Return value of wp_get_attachment_metadata (sizes / original_image).
 * @return array Size name => URL path ('full' = original, '__original' = pre-scaled source, plus each size).
 */
function tanupom_ipc_collect_urls( $file, $meta ) {
	$upload  = wp_upload_dir();
	$basedir = $upload['basedir'];
	$baseurl = $upload['baseurl'];

	// Build the full URL first, then strip down to the path. The base URL comes
	// from wp_upload_dir(), which already reflects any custom uploads location.
	$full_url  = str_replace( $basedir, $baseurl, $file );
	$full_path = wp_parse_url( $full_url, PHP_URL_PATH );
	if ( empty( $full_path ) ) {
		return array();
	}

	$urls         = array();
	$urls['full'] = $full_path;
	$dir_path     = dirname( $full_path );

	if ( is_array( $meta ) ) {
		// Map the "true original" (original_image) that WordPress preserves when it creates a -scaled version of a large image.
		if ( ! empty( $meta['original_image'] ) ) {
			$urls['__original'] = trailingslashit( $dir_path ) . $meta['original_image'];
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( ! empty( $info['file'] ) ) {
					$urls[ $size ] = trailingslashit( $dir_path ) . $info['file'];
				}
			}
		}
	}

	return $urls;
}

/**
 * Build "old URL path => new URL path" pairs from the old and new URL maps.
 *
 * Same-named sizes are mapped to each other. If a corresponding size is
 * missing on the new side (because the registered subsizes changed), the
 * mapping falls back to the new full-size URL. Values are paths and therefore
 * domain-agnostic.
 *
 * @param array $old_urls Old-side output of tanupom_ipc_collect_urls.
 * @param array $new_urls New-side output of tanupom_ipc_collect_urls.
 * @return array<string,string> Old URL path => new URL path.
 */
function tanupom_ipc_build_url_pairs( $old_urls, $new_urls ) {
	$pairs    = array();
	$new_full = isset( $new_urls['full'] ) ? $new_urls['full'] : '';

	foreach ( $old_urls as $size => $old_url ) {
		if ( isset( $new_urls[ $size ] ) ) {
			$pairs[ $old_url ] = $new_urls[ $size ];
		} elseif ( '' !== $new_full ) {
			// The matching subsize disappeared after regeneration: fall back to the new full-size URL.
			$pairs[ $old_url ] = $new_full;
		}
	}

	return $pairs;
}

/**
 * Delete the old original, old subsize files, and the old pre-scaled source (original_image) from disk.
 *
 * Assumes all of them live in the same directory as the old original. Guarded
 * by file_exists() before unlinking.
 *
 * @param string     $old_file Absolute path of the old original.
 * @param array|bool $old_meta Old attachment metadata (sizes / original_image).
 * @return void
 */
function tanupom_ipc_delete_old_files( $old_file, $old_meta ) {
	$dir = trailingslashit( dirname( $old_file ) );

	if ( file_exists( $old_file ) ) {
		wp_delete_file( $old_file );
	}

	if ( is_array( $old_meta ) ) {
		if ( ! empty( $old_meta['original_image'] ) ) {
			$orig = $dir . $old_meta['original_image'];
			if ( file_exists( $orig ) ) {
				wp_delete_file( $orig );
			}
		}
		if ( ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
			foreach ( $old_meta['sizes'] as $info ) {
				if ( ! empty( $info['file'] ) ) {
					$p = $dir . $info['file'];
					if ( file_exists( $p ) ) {
						wp_delete_file( $p );
					}
				}
			}
		}
	}
}


/*
 * -----------------------------------------------------------------------------
 * Single-attachment conversion
 * -----------------------------------------------------------------------------
 */

/**
 * Convert a single attachment to WebP in place and keep its WordPress meta consistent.
 *
 * Idempotent (already-WebP attachments are skipped) and non-destructive on
 * failure (the original file and meta are left untouched). On success the
 * returned 'pairs' array maps old URLs to new URLs for reference replacement.
 *
 * @param int $id Attachment ID.
 * @return array {
 *     @type bool   $success Whether conversion succeeded (skips are reported as success=true).
 *     @type bool   $skipped Whether the attachment was skipped (already WebP / unsupported MIME).
 *     @type array  $pairs   Old URL => new URL (only on successful conversion).
 *     @type string $message Human-readable result message for logs / UI.
 * }
 */
function tanupom_ipc_convert_attachment( $id ) {
	$id   = (int) $id;
	$post = get_post( $id );

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return array(
			'success' => false,
			'skipped' => true,
			'pairs'   => array(),
			'message' => 'not an attachment',
		);
	}

	$mime = get_post_mime_type( $id );

	// Idempotency: already-WebP attachments are skipped so the bulk job is resumable.
	if ( 'image/webp' === $mime ) {
		return array(
			'success' => true,
			'skipped' => true,
			'pairs'   => array(),
			'message' => 'already webp',
		);
	}

	// Do not touch unsupported MIME types.
	if ( ! in_array( $mime, TANUPOM_IPC_TARGET_MIMES, true ) ) {
		return array(
			'success' => true,
			'skipped' => true,
			'pairs'   => array(),
			'message' => 'not a target mime: ' . $mime,
		);
	}

	if ( ! tanupom_ipc_server_supported() ) {
		return array(
			'success' => false,
			'skipped' => false,
			'pairs'   => array(),
			'message' => 'server does not support webp output',
		);
	}

	$old_file = get_attached_file( $id );
	if ( empty( $old_file ) || ! file_exists( $old_file ) ) {
		return array(
			'success' => false,
			'skipped' => false,
			'pairs'   => array(),
			'message' => 'original file missing: ' . $old_file,
		);
	}

	// Capture the old meta and old URL paths before conversion (they change after update_attached_file).
	$old_meta = wp_get_attachment_metadata( $id );
	$old_urls = tanupom_ipc_collect_urls( $old_file, $old_meta );

	// Destination: the canonical .webp filename in the same directory (in-place conversion).
	//
	// wp_unique_filename() is intentionally avoided. That helper treats names ending in
	// WordPress-generated suffix patterns ("-scaled", "-{W}x{H}") as reserved and appends
	// a numeric suffix even when no file exists (e.g. foo-scaled.jpg => foo-scaled-1.webp).
	// In-place conversion wants the canonical .webp name, so the destination is computed
	// deterministically and a numeric suffix is only added on a real on-disk collision
	// (a rare case where another attachment owns the same .webp name).
	$dir      = dirname( $old_file );
	$base     = preg_replace( '/\.[^.]+$/', '', wp_basename( $old_file ) );
	$new_file = trailingslashit( $dir ) . $base . '.webp';
	$suffix   = 1;
	while ( file_exists( $new_file ) ) {
		$new_file = trailingslashit( $dir ) . $base . '-' . $suffix . '.webp';
		++$suffix;
	}

	// Convert (on failure the partial destination is cleaned up and the original is preserved).
	if ( ! tanupom_ipc_convert_file( $old_file, $new_file, $mime ) ) {
		return array(
			'success' => false,
			'skipped' => false,
			'pairs'   => array(),
			'message' => 'conversion failed',
		);
	}

	// Point WordPress at the new original and regenerate subsizes as WebP.
	update_attached_file( $id, $new_file );

	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	$new_meta = wp_generate_attachment_metadata( $id, $new_file );
	if ( is_array( $new_meta ) && ! empty( $new_meta ) ) {
		wp_update_attachment_metadata( $id, $new_meta );
	} else {
		// Even if subsize metadata regeneration fails, the original is already WebP; fall back so we can at least map the full-size URL.
		$new_meta = wp_get_attachment_metadata( $id );
	}

	// Update post_mime_type to image/webp using a direct $wpdb update to avoid the
	// side effects of wp_update_post() (post_modified bump and a wide hook cascade)
	// for what is only a single-column update. clean_post_cache() below keeps the
	// WordPress object cache consistent.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$wpdb->update(
		$wpdb->posts,
		array( 'post_mime_type' => 'image/webp' ),
		array( 'ID' => $id )
	);
	clean_post_cache( $id );

	// Delete the old files (original + subsizes + pre-scaled source).
	tanupom_ipc_delete_old_files( $old_file, $old_meta );

	// Build the old URL => new URL pairs.
	$new_urls = tanupom_ipc_collect_urls( $new_file, $new_meta );
	$pairs    = tanupom_ipc_build_url_pairs( $old_urls, $new_urls );

	return array(
		'success' => true,
		'skipped' => false,
		'pairs'   => $pairs,
		'message' => sprintf( 'converted %s -> %s', wp_basename( $old_file ), wp_basename( $new_file ) ),
	);
}
