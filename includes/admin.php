<?php
/**
 * Admin tools page and AJAX handlers.
 *
 * Registers the Settings API fields, renders the Tools > Tanupom In-Place Converter for WebP page, and
 * exposes the admin-ajax actions that drive the inventory scan, the batched
 * bulk conversion, and the reference replacement step. All entry points
 * require the manage_options capability and a valid nonce.
 *
 * @package Tanupom_In_Place_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number of attachments converted per AJAX batch.
 *
 * Kept conservative so a batch completes within the typical shared-hosting
 * execution time limit.
 */
const TANUPOM_IPC_BATCH_SIZE = 5;

/**
 * Option name that accumulates the old URL => new URL pairs produced during
 * conversion. Consumed by the reference replacement step.
 */
const TANUPOM_IPC_URL_MAP_OPTION = 'tanupom_ipc_url_map';

/**
 * Register the Settings API option, section, and fields.
 *
 * Storage is delegated to the Settings API (options.php) so this plugin does
 * not need a hand-written admin-post handler. Sanitization is centralized in
 * the register_setting sanitize_callback below.
 *
 * @return void
 */
function tanupom_ipc_register_settings() {
	register_setting(
		'tanupom_ipc_options',
		TANUPOM_IPC_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'tanupom_ipc_sanitize_settings',
			'default'           => TANUPOM_IPC_DEFAULTS,
		)
	);

	register_setting(
		'tanupom_ipc_options',
		'tanupom_ipc_extra_post_types',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'tanupom_ipc_sanitize_extra_post_types',
			'default'           => array(),
		)
	);

	add_settings_section(
		'tanupom_ipc_conversion',
		__( 'Conversion settings', 'tanupom-in-place-converter-for-webp' ),
		'__return_false',
		'tanupom-in-place-converter-for-webp'
	);

	add_settings_field(
		'tanupom_ipc_quality',
		__( 'WebP quality (JPEG)', 'tanupom-in-place-converter-for-webp' ),
		'tanupom_ipc_field_quality',
		'tanupom-in-place-converter-for-webp',
		'tanupom_ipc_conversion',
		array( 'label_for' => 'tanupom-ipc-quality' )
	);

	add_settings_field(
		'tanupom_ipc_png_lossless',
		__( 'PNG', 'tanupom-in-place-converter-for-webp' ),
		'tanupom_ipc_field_png_lossless',
		'tanupom-in-place-converter-for-webp',
		'tanupom_ipc_conversion'
	);

	add_settings_field(
		'tanupom_ipc_strip_metadata',
		__( 'Metadata', 'tanupom-in-place-converter-for-webp' ),
		'tanupom_ipc_field_strip_metadata',
		'tanupom-in-place-converter-for-webp',
		'tanupom_ipc_conversion'
	);

	add_settings_section(
		'tanupom_ipc_replacement',
		__( 'Reference replacement', 'tanupom-in-place-converter-for-webp' ),
		'tanupom_ipc_section_replacement_intro',
		'tanupom-in-place-converter-for-webp'
	);

	add_settings_field(
		'tanupom_ipc_extra_post_types',
		__( 'Additional post types', 'tanupom-in-place-converter-for-webp' ),
		'tanupom_ipc_field_extra_post_types',
		'tanupom-in-place-converter-for-webp',
		'tanupom_ipc_replacement'
	);
}
add_action( 'admin_init', 'tanupom_ipc_register_settings' );

/**
 * Sanitize the saved settings array.
 *
 * Clamps quality to 1-100 and coerces the two checkboxes to booleans. An
 * unchecked checkbox is absent from $input, so empty() resolves it to false.
 *
 * @param mixed $input Raw submitted value for the settings option.
 * @return array Sanitized settings (quality / png_lossless / strip_metadata).
 */
function tanupom_ipc_sanitize_settings( $input ) {
	if ( ! is_array( $input ) ) {
		$input = array();
	}

	$quality = isset( $input['quality'] ) ? (int) $input['quality'] : TANUPOM_IPC_DEFAULTS['quality'];

	return array(
		'quality'        => min( 100, max( 1, $quality ) ),
		'png_lossless'   => ! empty( $input['png_lossless'] ),
		'strip_metadata' => ! empty( $input['strip_metadata'] ),
	);
}

/**
 * Print the intro paragraph for the Reference replacement section.
 *
 * @return void
 */
function tanupom_ipc_section_replacement_intro() {
	echo '<p>' . esc_html__( 'Reference replacement rewrites old image URLs in post_content to the new .webp URLs after conversion. By default it scans posts, pages, attachments, and revisions. Use the field below to add custom post types that also contain image URLs.', 'tanupom-in-place-converter-for-webp' ) . '</p>';
}

/**
 * Sanitize the additional-post-types option (array of post_type slugs).
 *
 * Drops empty values and normalizes every entry with sanitize_key().
 *
 * @param mixed $input Raw submitted value.
 * @return string[] Sanitized list of post type slugs.
 */
function tanupom_ipc_sanitize_extra_post_types( $input ) {
	if ( ! is_array( $input ) ) {
		return array();
	}
	return array_values( array_filter( array_map( 'sanitize_key', $input ) ) );
}

/**
 * Render the "additional post types" checkbox list.
 *
 * Lists every public custom post type registered on this site so the site
 * owner can add it to the URL replacement scan.
 *
 * @return void
 */
function tanupom_ipc_field_extra_post_types() {
	$value      = (array) get_option( 'tanupom_ipc_extra_post_types', array() );
	$candidates = get_post_types(
		array(
			'public'   => true,
			'_builtin' => false,
		),
		'objects'
	);

	if ( empty( $candidates ) ) {
		echo '<p class="description">' . esc_html__( 'No public custom post types are registered on this site.', 'tanupom-in-place-converter-for-webp' ) . '</p>';
		return;
	}

	foreach ( $candidates as $cpt ) {
		$checked = in_array( $cpt->name, $value, true );
		printf(
			'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="tanupom_ipc_extra_post_types[]" value="%1$s" %2$s /> %3$s <code>%1$s</code></label>',
			esc_attr( $cpt->name ),
			checked( $checked, true, false ),
			esc_html( $cpt->labels->name )
		);
	}

	echo '<p class="description">' . esc_html__( 'Each checked post type is added to the URL replacement scan in addition to the defaults.', 'tanupom-in-place-converter-for-webp' ) . '</p>';
}

/**
 * Render the WebP quality field (JPEG lossy quality).
 *
 * @return void
 */
function tanupom_ipc_field_quality() {
	$settings = tanupom_ipc_get_settings();
	?>
	<input type="number" min="1" max="100" step="1" id="tanupom-ipc-quality" name="<?php echo esc_attr( TANUPOM_IPC_SETTINGS_OPTION ); ?>[quality]" value="<?php echo esc_attr( (string) $settings['quality'] ); ?>" class="small-text" />
	<p class="description"><?php esc_html_e( '1–100. Higher means better quality but larger files. Default 80. Applies to lossy (JPEG) conversion.', 'tanupom-in-place-converter-for-webp' ); ?></p>
	<?php
}

/**
 * Render the "encode PNG as lossless WebP" checkbox.
 *
 * @return void
 */
function tanupom_ipc_field_png_lossless() {
	$settings = tanupom_ipc_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( TANUPOM_IPC_SETTINGS_OPTION ); ?>[png_lossless]" value="1" <?php checked( $settings['png_lossless'] ); ?> />
		<?php esc_html_e( 'Encode PNG as lossless WebP (keeps transparency, avoids fringing on logos and text)', 'tanupom-in-place-converter-for-webp' ); ?>
	</label>
	<?php
}

/**
 * Render the "strip image metadata (EXIF)" checkbox.
 *
 * @return void
 */
function tanupom_ipc_field_strip_metadata() {
	$settings = tanupom_ipc_get_settings();
	?>
	<label>
		<input type="checkbox" name="<?php echo esc_attr( TANUPOM_IPC_SETTINGS_OPTION ); ?>[strip_metadata]" value="1" <?php checked( $settings['strip_metadata'] ); ?> />
		<?php esc_html_e( 'Strip image metadata (EXIF) — smaller files and no leaking of GPS location data', 'tanupom-in-place-converter-for-webp' ); ?>
	</label>
	<?php
}

/**
 * Add the Tanupom In-Place Converter for WebP page under the Tools menu.
 *
 * @return void
 */
function tanupom_ipc_admin_menu() {
	add_management_page(
		__( 'Tanupom In-Place Converter for WebP', 'tanupom-in-place-converter-for-webp' ),
		// メニュー項目は幅が限られるため短縮形を使う（ページ見出しは正式名）。
		__( 'In-Place WebP', 'tanupom-in-place-converter-for-webp' ),
		'manage_options',
		'tanupom-in-place-converter-for-webp',
		'tanupom_ipc_render_tools_page'
	);
}
add_action( 'admin_menu', 'tanupom_ipc_admin_menu' );

/**
 * Enqueue admin.js on the Tanupom In-Place Converter for WebP tools page and pass the nonce / AJAX URL.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function tanupom_ipc_admin_enqueue( $hook ) {
	if ( 'tools_page_tanupom-in-place-converter-for-webp' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'tanupom-ipc-admin',
		TANUPOM_IPC_URL . 'includes/admin.js',
		array(),
		TANUPOM_IPC_VERSION,
		true
	);

	wp_localize_script(
		'tanupom-ipc-admin',
		'TanupomIpc',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tanupom_ipc' ),
			'i18n'    => array(
				'scanning'    => __( 'Scanning…', 'tanupom-in-place-converter-for-webp' ),
				'converting'  => __( 'Converting…', 'tanupom-in-place-converter-for-webp' ),
				'replacing'   => __( 'Replacing references…', 'tanupom-in-place-converter-for-webp' ),
				'done'        => __( 'Done', 'tanupom-in-place-converter-for-webp' ),
				'error'       => __( 'An error occurred.', 'tanupom-in-place-converter-for-webp' ),
				/* translators: 1: number converted, 2: total, 3: number remaining, 4: number of URL pairs accumulated. */
				'progress'    => __( 'Converting %1$d / %2$d (remaining %3$d, map %4$d)', 'tanupom-in-place-converter-for-webp' ),
				/* translators: 1: number converted, 2: total, 3: number of old-to-new URL pairs accumulated. */
				'doneSummary' => __( 'Done — %1$d / %2$d (%3$d old-to-new URL pairs accumulated)', 'tanupom-in-place-converter-for-webp' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'tanupom_ipc_admin_enqueue' );

/**
 * Render the tools page HTML.
 *
 * @return void
 */
function tanupom_ipc_render_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$pending   = tanupom_ipc_count_pending();
	$supported = tanupom_ipc_server_supported();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Tanupom In-Place Converter for WebP', 'tanupom-in-place-converter-for-webp' ); ?></h1>

		<?php if ( ! $supported ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'This server’s Imagick build cannot output WebP, so conversion is unavailable.', 'tanupom-in-place-converter-for-webp' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag. The actual save was nonce-verified by options.php (Settings API).
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'tanupom_ipc_messages', 'tanupom_ipc_saved', __( 'Settings saved.', 'tanupom-in-place-converter-for-webp' ), 'updated' );
		}
		settings_errors( 'tanupom_ipc_messages' );
		?>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'tanupom_ipc_options' );
			do_settings_sections( 'tanupom-in-place-converter-for-webp' );
			submit_button( __( 'Save settings', 'tanupom-in-place-converter-for-webp' ) );
			?>
		</form>

		<hr />

		<h2><?php esc_html_e( '1. Inventory (scan for reference locations)', 'tanupom-in-place-converter-for-webp' ); ?></h2>
		<p><?php esc_html_e( 'Scans where image URLs are used across post content, meta, and options. Nothing is modified.', 'tanupom-in-place-converter-for-webp' ); ?></p>
		<p>
			<button type="button" class="button" id="tanupom-ipc-scan"><?php esc_html_e( 'Run inventory', 'tanupom-in-place-converter-for-webp' ); ?></button>
		</p>
		<pre id="tanupom-ipc-scan-result" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:400px;overflow:auto;display:none;"></pre>

		<h2><?php esc_html_e( '2. Bulk WebP conversion', 'tanupom-in-place-converter-for-webp' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d: number of unconverted attachments. */
				esc_html__( 'Unconverted images (JPEG / PNG): %d', 'tanupom-in-place-converter-for-webp' ),
				(int) $pending
			);
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Replaces originals with WebP in place (irreversible). Back up your media before running. Reference replacement is performed separately.', 'tanupom-in-place-converter-for-webp' ); ?></p>
		<p>
			<button type="button" class="button button-primary" id="tanupom-ipc-convert" <?php disabled( ! $supported || 0 === (int) $pending ); ?>><?php esc_html_e( 'Start bulk conversion', 'tanupom-in-place-converter-for-webp' ); ?></button>
		</p>
		<div id="tanupom-ipc-progress" style="display:none;">
			<progress id="tanupom-ipc-bar" value="0" max="<?php echo (int) $pending; ?>" style="width:100%;height:20px;"></progress>
			<p id="tanupom-ipc-status"></p>
			<pre id="tanupom-ipc-log" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:300px;overflow:auto;"></pre>
		</div>

		<h2><?php esc_html_e( '3. Replace references', 'tanupom-in-place-converter-for-webp' ); ?></h2>
		<p><?php esc_html_e( 'Applies the accumulated “old URL → new URL” pairs to post content and options. Run this after conversion.', 'tanupom-in-place-converter-for-webp' ); ?></p>
		<p>
			<?php
			$map      = get_option( TANUPOM_IPC_URL_MAP_OPTION, array() );
			$map_size = is_array( $map ) ? count( $map ) : 0;
			printf(
				/* translators: %d: number of accumulated URL pairs. */
				esc_html__( 'Accumulated URL pairs: %d', 'tanupom-in-place-converter-for-webp' ),
				(int) $map_size
			);
			?>
		</p>
		<p>
			<button type="button" class="button" id="tanupom-ipc-replace" <?php disabled( 0 === $map_size ); ?>><?php esc_html_e( 'Run reference replacement', 'tanupom-in-place-converter-for-webp' ); ?></button>
		</p>
		<pre id="tanupom-ipc-replace-result" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:300px;overflow:auto;display:none;"></pre>
	</div>
	<?php
}

/**
 * AJAX handler for the inventory scan (read-only).
 *
 * @return void
 */
function tanupom_ipc_ajax_scan() {
	check_ajax_referer( 'tanupom_ipc', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	wp_send_json_success( tanupom_ipc_scan_url_usage() );
}
add_action( 'wp_ajax_tanupom_ipc_scan', 'tanupom_ipc_ajax_scan' );

/**
 * AJAX handler for one bulk conversion batch.
 *
 * Converts the next TANUPOM_IPC_BATCH_SIZE attachments and returns progress.
 * The old URL => new URL pairs produced are accumulated in
 * TANUPOM_IPC_URL_MAP_OPTION for the reference replacement step.
 *
 * @return void
 */
function tanupom_ipc_ajax_convert_batch() {
	check_ajax_referer( 'tanupom_ipc', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$ids = tanupom_ipc_get_pending_ids( TANUPOM_IPC_BATCH_SIZE );
	$map = get_option( TANUPOM_IPC_URL_MAP_OPTION, array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}

	$results = array();
	foreach ( $ids as $id ) {
		$res       = tanupom_ipc_convert_attachment( $id );
		$results[] = array(
			'id'      => $id,
			'success' => ! empty( $res['success'] ),
			'skipped' => ! empty( $res['skipped'] ),
			'message' => isset( $res['message'] ) ? $res['message'] : '',
		);
		if ( ! empty( $res['pairs'] ) && is_array( $res['pairs'] ) ) {
			// Accumulate old URL => new URL pairs for the later reference replacement step.
			$map = $map + $res['pairs'];
		}
	}

	update_option( TANUPOM_IPC_URL_MAP_OPTION, $map, false );

	wp_send_json_success(
		array(
			'processed' => count( $ids ),
			'remaining' => tanupom_ipc_count_pending(),
			'map_count' => count( $map ),
			'results'   => $results,
		)
	);
}
add_action( 'wp_ajax_tanupom_ipc_convert_batch', 'tanupom_ipc_ajax_convert_batch' );

/**
 * AJAX handler for reference replacement.
 *
 * Applies the accumulated URL map to post content and options.
 *
 * @return void
 */
function tanupom_ipc_ajax_replace() {
	check_ajax_referer( 'tanupom_ipc', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	wp_send_json_success( tanupom_ipc_run_replace() );
}
add_action( 'wp_ajax_tanupom_ipc_replace', 'tanupom_ipc_ajax_replace' );
