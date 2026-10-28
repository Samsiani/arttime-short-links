<?php
/**
 * Plugin Name:       Arttime Short Links
 * Plugin URI:        https://github.com/Samsiani/arttime-short-links
 * Description:       Lightweight URL shortener: paste any link in the dashboard and get arttime.ge/k7m3p instantly, or choose your own ending (arttime.ge/my-url) when it is not already used on the site.
 * Version:           1.0.1
 * Author:            Samsiani
 * Text Domain:       atsl
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATSL_VERSION', '1.0.1' );
define( 'ATSL_FILE',    __FILE__ );
define( 'ATSL_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ATSL_URL',     plugin_dir_url( __FILE__ ) );

require_once ATSL_DIR . 'includes/class-atsl-repo.php';
require_once ATSL_DIR . 'includes/class-atsl-redirect.php';

// Hot path: a short-link hit is answered right here, while WordPress is still
// including plugins — before WooCommerce, WPML, Elementor etc. boot. Every
// other request leaves after one regex on the path.
ATSL_Redirect::maybe_redirect();

require_once ATSL_DIR . 'includes/class-atsl-core.php';

register_activation_hook( __FILE__, [ 'ATSL_Core', 'activate' ] );

add_action( 'plugins_loaded', [ 'ATSL_Core', 'instance' ] );
