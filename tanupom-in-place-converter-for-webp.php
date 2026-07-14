<?php
/**
 * Plugin Name: Tanupom In-Place Converter for WebP
 * Plugin URI:  https://github.com/tanu-pom/tanupom-in-place-converter-for-webp
 * Description: Convert new uploads and your existing media library to WebP in place, and update the references in your content. A simple, single-format approach to lighter images.
 * Version:     0.3.0
 * Author:      tanupom
 * Author URI:  https://github.com/tanu-pom
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tanupom-in-place-converter-for-webp
 * Requires PHP: 7.4
 * Requires at least: 5.5
 *
 * @package Tanupom_In_Place_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TANUPOM_IPC_VERSION', '0.3.0' );
define( 'TANUPOM_IPC_DIR', plugin_dir_path( __FILE__ ) );
define( 'TANUPOM_IPC_URL', plugin_dir_url( __FILE__ ) );

require_once TANUPOM_IPC_DIR . 'includes/convert.php';
require_once TANUPOM_IPC_DIR . 'includes/layer1.php';
require_once TANUPOM_IPC_DIR . 'includes/bulk.php';

if ( is_admin() ) {
	require_once TANUPOM_IPC_DIR . 'includes/scan.php';
	require_once TANUPOM_IPC_DIR . 'includes/replace.php';
	require_once TANUPOM_IPC_DIR . 'includes/admin.php';
}
