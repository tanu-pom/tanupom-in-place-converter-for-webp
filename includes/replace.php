<?php
/**
 * Reference replacement after bulk conversion.
 *
 * Reads the accumulated "old URL => new URL" map and rewrites the remaining
 * references in post_content and in options to point at the new WebP URLs.
 *
 * Replacement uses strtr( $string, $pairs ), which performs all substitutions
 * simultaneously against the longest matching key. This avoids the
 * prefix-collision bug between names like foo.jpg and foo-150x150.jpg that
 * sequential array-form str_replace() would trigger.
 *
 * Options are stored serialized, so the implementation reads them with
 * get_option() (which unserializes), walks the resulting PHP value
 * recursively, and writes them back with update_option() (which
 * reserializes). This is serialize-safe by construction and avoids the
 * brittle s:N:"..." length recounting that raw string replacement would
 * require.
 *
 * @package Simply_WebP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walk a PHP value (string / array / object) recursively and apply strtr to
 * every string.
 *
 * Intended for the structure produced by get_option() after WordPress
 * unserializes the stored value. Scalars (int / bool / null / float) are
 * returned untouched. Objects are cloned before being modified.
 *
 * @param mixed $data  Value to rewrite.
 * @param array $pairs Old URL => new URL map for strtr().
 * @return mixed Rewritten value.
 */
function simply_webp_replace_recursive( $data, $pairs ) {
	if ( is_string( $data ) ) {
		return '' === $data ? $data : strtr( $data, $pairs );
	}

	if ( is_array( $data ) ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			$out[ $key ] = simply_webp_replace_recursive( $value, $pairs );
		}
		return $out;
	}

	if ( is_object( $data ) ) {
		$out = clone $data;
		foreach ( get_object_vars( $out ) as $key => $value ) {
			$out->$key = simply_webp_replace_recursive( $value, $pairs );
		}
		return $out;
	}

	return $data;
}

/**
 * Return the list of post types whose post_content is scanned for URL replacement.
 *
 * Defaults to page / post / attachment / revision. Site owners can extend the
 * list from the Settings page (option simply_webp_extra_post_types), and
 * developers can override the final list via the simply_webp_replace_post_types
 * filter.
 *
 * @return string[] Sanitized post type slugs.
 */
function simply_webp_get_replace_post_types() {
	$defaults = array( 'page', 'post', 'attachment', 'revision' );
	$extras   = (array) get_option( 'simply_webp_extra_post_types', array() );
	$extras   = array_filter( array_map( 'sanitize_key', $extras ) );

	$types = array_values( array_unique( array_merge( $defaults, $extras ) ) );

	/**
	 * Filter the post types whose post_content is scanned for URL replacement.
	 *
	 * @param string[] $types Post type slugs.
	 */
	return (array) apply_filters( 'simply_webp_replace_post_types', $types );
}

/**
 * Replace old image URLs with new ones in post_content.
 *
 * The set of post types scanned is returned by
 * simply_webp_get_replace_post_types() (default page / post / attachment /
 * revision, extendable from the settings page or the
 * simply_webp_replace_post_types filter).
 *
 * @param array $pairs Old URL => new URL.
 * @return array {
 *     @type int $candidates Number of candidate posts.
 *     @type int $changed    Number actually updated.
 * }
 */
function simply_webp_replace_in_posts( $pairs ) {
	global $wpdb;

	if ( empty( $pairs ) ) {
		return array(
			'candidates' => 0,
			'changed'    => 0,
		);
	}

	$needle = simply_webp_uploads_path_needle();
	$like   = '%' . $wpdb->esc_like( $needle ) . '%';

	$types        = simply_webp_get_replace_post_types();
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$args         = array_merge( array( $like ), $types );

	// Select only posts whose content contains an old URL. A direct query with no
	// caching is used because WP_Query and get_posts() do not handle LIKE searches
	// efficiently, and clean_post_cache() below refreshes the post object cache
	// after each update. The IN-clause uses dynamic placeholders that WPCS cannot
	// statically verify, so the PreparedSQL rule is suppressed for this query.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type IN ( $placeholders )", $args ) );

	$changed = 0;
	foreach ( $ids as $id ) {
		$id = (int) $id;
		// Read post_content directly for the IDs selected above, rewrite it, and write
		// it back. wp_update_post() would trigger a wide set of filters and hooks that
		// are unnecessary for a bulk URL substitution; clean_post_cache() below keeps
		// the WordPress object cache consistent.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
		$content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
		if ( null === $content || '' === $content ) {
			continue;
		}
		$new = strtr( $content, $pairs );
		if ( $new !== $content ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			++$changed;
		}
	}

	return array(
		'candidates' => count( $ids ),
		'changed'    => $changed,
	);
}

/**
 * Apply serialize-safe replacement to every option whose value contains the
 * upload path.
 *
 * The candidate set is recomputed on every run, so options that gain image
 * URLs during conversion are still picked up. strtr() only rewrites URLs
 * present in the map, leaving unrelated upload URLs (for not-yet-converted
 * images, for example) untouched.
 *
 * @param array $pairs Old URL => new URL.
 * @return array {
 *     @type int $candidates Number of candidate options.
 *     @type int $changed    Number actually updated.
 * }
 */
function simply_webp_replace_in_options( $pairs ) {
	global $wpdb;

	if ( empty( $pairs ) ) {
		return array(
			'candidates' => 0,
			'changed'    => 0,
		);
	}

	$needle = simply_webp_uploads_path_needle();
	$like   = '%' . $wpdb->esc_like( $needle ) . '%';

	// Select option_name for any option whose value contains an old URL.
	// wp_load_alloptions() would miss rows with autoload=no, and calling get_option()
	// for every option would be N+1. The actual writes below go through update_option(),
	// which refreshes the WordPress object cache automatically.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See comment above.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s",
			$like
		)
	);

	$changed = 0;
	foreach ( $names as $name ) {
		$value = get_option( $name );
		if ( false === $value ) {
			continue;
		}
		$new = simply_webp_replace_recursive( $value, $pairs );
		// Compare serialized forms so cloned objects are also detected as changed when their contents differ.
		if ( maybe_serialize( $new ) !== maybe_serialize( $value ) ) {
			update_option( $name, $new );
			++$changed;
		}
	}

	return array(
		'candidates' => count( $names ),
		'changed'    => $changed,
	);
}

/**
 * Read the accumulated URL map and apply it to post_content and options.
 *
 * @return array {
 *     @type int   $mapCount Number of pairs in the map.
 *     @type array $posts    Result of simply_webp_replace_in_posts.
 *     @type array $options  Result of simply_webp_replace_in_options.
 * }
 */
function simply_webp_run_replace() {
	$map = get_option( SIMPLY_WEBP_URL_MAP_OPTION, array() );
	if ( ! is_array( $map ) || empty( $map ) ) {
		return array(
			'mapCount' => 0,
			'posts'    => array(
				'candidates' => 0,
				'changed'    => 0,
			),
			'options'  => array(
				'candidates' => 0,
				'changed'    => 0,
			),
		);
	}

	return array(
		'mapCount' => count( $map ),
		'posts'    => simply_webp_replace_in_posts( $map ),
		'options'  => simply_webp_replace_in_options( $map ),
	);
}
