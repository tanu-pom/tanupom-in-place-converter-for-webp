<?php
/**
 * Plugin Name: Simply WebP
 * Plugin URI:  https://github.com/tanu-pom/simply-webp
 * Description: Convert new uploads and your existing media library to WebP in place, and update the references in your content. A simple, single-format approach to lighter images.
 * Version:     0.2.0
 * Author:      tanupom
 * Author URI:  https://github.com/tanu-pom
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simply-webp
 * Requires PHP: 7.4
 * Requires at least: 5.5
 *
 * @package Simply_WebP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SIMPLY_WEBP_VERSION', '0.2.0' );
define( 'SIMPLY_WEBP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLY_WEBP_URL', plugin_dir_url( __FILE__ ) );

require_once SIMPLY_WEBP_DIR . 'includes/convert.php';
require_once SIMPLY_WEBP_DIR . 'includes/layer1.php';
require_once SIMPLY_WEBP_DIR . 'includes/bulk.php';

if ( is_admin() ) {
	require_once SIMPLY_WEBP_DIR . 'includes/scan.php';
	require_once SIMPLY_WEBP_DIR . 'includes/replace.php';
	require_once SIMPLY_WEBP_DIR . 'includes/admin.php';
}

/**
 * Load the plugin text domain for bundled translations.
 *
 * On WordPress.org-hosted copies, translations load automatically (just-in-time)
 * since WP 4.6, so this call is not strictly required there. It is kept for
 * self-distributed copies (e.g. installed from GitHub) that ship .mo files in
 * /languages, where automatic loading does not apply. Hooked on `init` to avoid
 * the "translation loading triggered too early" notice in WP 6.7+.
 *
 * @return void
 */
function simply_webp_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for self-distributed copies that ship .mo files outside the WordPress.org translation system.
	load_plugin_textdomain( 'simply-webp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'simply_webp_load_textdomain' );
