<?php
/**
 * Plugin Name: Random Phone for Joinchat
 * Plugin URI:  https://github.com/Dsantycam/random-phone-for-joinchat
 * Description: Reparte los contactos de <strong>Joinchat</strong> entre una lista de teléfonos/agentes. Reparto <strong>global y equitativo</strong> entre visitantes (shuffle-bag) con asignación <strong>fija por visitante</strong> (sticky). Compatible con Joinchat 6.x.
 * Version:     4.0.2
 * Author:      Santiago Camacho
 * Author URI:  https://santiagocamachomkt.com
 * License:     GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires at least: 5.0.0
 * Tested up to: 6.9
 * Requires PHP: 7.0
 * Text Domain: joinchat-random-phone
 *
 * @package JoinChatRandomPhone
 */

defined( 'WPINC' ) || exit;

define( 'JOINCHAT_RANDOM_PHONE_VERSION', '4.0.2' );
define( 'JOINCHAT_RANDOM_PHONE_FILE', __FILE__ );

/**
 * URL del repositorio de GitHub desde donde se descargan las actualizaciones.
 *
 * IMPORTANTE: cambia USUARIO_GITHUB por tu usuario real de GitHub (y el nombre
 * del repo si lo llamas distinto). El repositorio debe ser PÚBLICO y las
 * actualizaciones se publican creando "Releases" con la nueva versión.
 */
define( 'JOINCHAT_RANDOM_PHONE_GITHUB', 'https://github.com/Dsantycam/random-phone-for-joinchat/' );

add_action( 'plugins_loaded', 'joinchat_random_phone_load_plugin' );

/**
 * Initialize plugin.
 *
 * Load text domain, check dependencies and start admin or public functionalities.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_load_plugin() {

	$plugin_name  = 'joinchat-random-phone';
	$joinchat_min = '6.0.0';

	if ( is_admin() ) {
		// Load plugin translations (only needed for admin).
		add_action( 'init', 'joinchat_random_phone_load_textdomain' );

		// Auto-updates from GitHub (replaces the old Kernl updater).
		joinchat_random_phone_init_updater();
	}

	if ( defined( 'WHATSAPPME_VERSION' ) ) {

		add_action( 'admin_notices', 'joinchat_random_phone_wame_to_joinchat' );

	} elseif ( ! defined( 'JOINCHAT_VERSION' ) ) {

		add_action( 'admin_notices', 'joinchat_random_phone_joinchat_required' );

	} elseif ( version_compare( JOINCHAT_VERSION, $joinchat_min, '<' ) ) {

		add_action( 'admin_notices', 'joinchat_random_phone_joinchat_out_of_date' );

	} else {

		require plugin_dir_path( __FILE__ ) . 'admin/class-joinchat-random-phone-admin.php';
		require plugin_dir_path( __FILE__ ) . 'public/class-joinchat-random-phone-public.php';

		if ( is_admin() ) {
			new JoinChatRandomPhoneAdmin( $plugin_name, JOINCHAT_RANDOM_PHONE_VERSION );
		}

		// Public class also loads in admin context because the AJAX endpoint
		// (admin-ajax.php) runs with is_admin() === true.
		new JoinChatRandomPhonePublic( $plugin_name, JOINCHAT_RANDOM_PHONE_VERSION );

		// Disable Joinchat Gutenberg sidebar (same as original addon).
		add_filter( 'joinchat_gutenberg_sidebar', '__return_false' );
	}
}

/**
 * Init the GitHub based Plugin Update Checker (PUC v5).
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_init_updater() {

	// Don't try to update if the repo URL wasn't configured.
	if ( false !== strpos( JOINCHAT_RANDOM_PHONE_GITHUB, 'USUARIO_GITHUB' ) ) {
		return;
	}

	require plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';

	$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		JOINCHAT_RANDOM_PHONE_GITHUB,
		__FILE__,
		'random-phone-for-joinchat'
	);

	/*
	 * How updates are published on GitHub (any of these works):
	 *  - Create a "Release" with a tag like "4.0.1" (recommended), or
	 *  - Push a git tag with the version number.
	 * PUC reads the version from the plugin header, so bump "Version:" too.
	 */
}

/**
 * Load plugin textdomain.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_load_textdomain() {
	load_plugin_textdomain( 'joinchat-random-phone', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Joinchat required admin notice.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_joinchat_required() {
	echo '<div class="error"><p>' .
		wp_kses_post( __( 'You need to install and activate <strong>Joinchat</strong> in order to use <strong>Joinchat Random Phone</strong>', 'joinchat-random-phone' ) ) .
		'</p></div>';
}

/**
 * Joinchat outdated admin notice.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_joinchat_out_of_date() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$file_path    = 'creame-whatsapp-me/joinchat.php';
	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	echo '<div class="error"><p>' .
		wp_kses_post( __( '<strong>Joinchat Random Phone</strong> require a newer version of <strong>Joinchat</strong>.', 'joinchat-random-phone' ) ) .
		sprintf( ' <a href="%s">%s</a>', esc_url( $upgrade_link ), esc_html__( 'Update now', 'joinchat-random-phone' ) ) .
		'</p></div>';
}

/**
 * WAme to Joinchat outdated admin notice.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_wame_to_joinchat() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$file_path    = 'creame-whatsapp-me/whatsappme.php';
	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	echo '<div class="error"><p>' .
		wp_kses_post( __( '<strong>Joinchat Random Phone</strong> require to update WAme to the new <strong>Joinchat</strong>.', 'joinchat-random-phone' ) ) .
		sprintf( ' <a href="%s">%s</a>', esc_url( $upgrade_link ), esc_html__( 'Update now', 'joinchat-random-phone' ) ) .
		'</p></div>';
}
