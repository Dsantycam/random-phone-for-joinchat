<?php
/**
 * Plugin Name: Random Phone for Joinchat
 * Plugin URI:  https://github.com/Dsantycam/random-phone-for-joinchat
 * Description: Reparte los contactos de <strong>Joinchat</strong> entre una lista de teléfonos/agentes. Reparto <strong>global y equitativo</strong> entre visitantes (shuffle-bag) con asignación <strong>fija por visitante</strong> (sticky). Compatible con Joinchat 3.x (WAme) hasta 6.x.
 * Version:     4.5.0
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

define( 'JOINCHAT_RANDOM_PHONE_VERSION', '4.5.0' );
define( 'JOINCHAT_RANDOM_PHONE_FILE', __FILE__ );

/**
 * URL del repositorio de GitHub desde donde se descargan las actualizaciones.
 *
 * IMPORTANTE: cambia USUARIO_GITHUB por tu usuario real de GitHub (y el nombre
 * del repo si lo llamas distinto). El repositorio debe ser PÚBLICO y las
 * actualizaciones se publican creando "Releases" con la nueva versión.
 */
define( 'JOINCHAT_RANDOM_PHONE_GITHUB', 'https://github.com/Dsantycam/random-phone-for-joinchat/' );

/**
 * The original Creame addon this one replaces.
 *
 * Both register their scripts under the same handle ('joinchat-random-phone'),
 * so if the two are active WordPress loads only one of them and, by folder
 * order, the original wins: this plugin's public script would never load and
 * the split would silently stop working. Hence the notice below.
 */
define( 'JOINCHAT_RANDOM_PHONE_OLD', 'joinchat-random-phone/joinchat-random-phone.php' );

add_action( 'plugins_loaded', 'joinchat_random_phone_load_plugin' );
register_activation_hook( __FILE__, 'joinchat_random_phone_activate' );
add_action( 'admin_notices', 'joinchat_random_phone_conflict_notice' );
add_action( 'admin_post_jcrp_deactivate_old', 'joinchat_random_phone_deactivate_old' );

/**
 * Create the statistics table on activation (updates create it on first use).
 *
 * @since    4.5.0
 * @return   void
 */
function joinchat_random_phone_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-joinchat-random-phone-stats.php';
	JoinChatRandomPhoneStats::install();
}

/**
 * Is the original Creame Random Phone addon active?
 *
 * @since    4.2.0
 * @return   bool
 */
function joinchat_random_phone_old_is_active() {

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( JOINCHAT_RANDOM_PHONE_OLD );

}

/**
 * Warn when both this plugin and the original addon are active.
 *
 * The phone numbers are stored in the very same place by both plugins, so
 * nothing has to be migrated: deactivating the old one is enough.
 *
 * @since    4.2.0
 * @return   void
 */
function joinchat_random_phone_conflict_notice() {

	if ( ! current_user_can( 'activate_plugins' ) || ! joinchat_random_phone_old_is_active() ) {
		return;
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=jcrp_deactivate_old' ), 'jcrp_deactivate_old' );

	echo '<div class="notice notice-warning"><p><strong>' .
		esc_html__( 'Random Phone for Joinchat', 'joinchat-random-phone' ) . '</strong> — ' .
		esc_html__( 'The original "Joinchat Random Phone" addon is also active. Both use the same script name, so only one of them loads and the phone split can silently stop working.', 'joinchat-random-phone' ) .
		'</p><p>' .
		esc_html__( 'Your phone numbers are stored in the same place by both plugins, so nothing is lost: deactivating the old one keeps every number exactly as it is.', 'joinchat-random-phone' ) .
		'</p><p><a href="' . esc_url( $url ) . '" class="button button-primary">' .
		esc_html__( 'Deactivate the old addon', 'joinchat-random-phone' ) .
		'</a></p></div>';

}

/**
 * Deactivate the original Creame addon.
 *
 * @since    4.2.0
 * @return   void
 */
function joinchat_random_phone_deactivate_old() {

	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You are not allowed to deactivate plugins.', 'joinchat-random-phone' ) );
	}

	check_admin_referer( 'jcrp_deactivate_old' );

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	deactivate_plugins( JOINCHAT_RANDOM_PHONE_OLD );

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
	exit;

}

/**
 * Initialize plugin.
 *
 * Load text domain, check dependencies and start admin or public functionalities.
 *
 * @since    4.0.0
 * @return   void
 */
function joinchat_random_phone_load_plugin() {

	$plugin_name = 'joinchat-random-phone';

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-joinchat-random-phone-compat.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-joinchat-random-phone-stats.php';

	if ( is_admin() ) {
		// Load plugin translations (only needed for admin).
		add_action( 'init', 'joinchat_random_phone_load_textdomain' );

		// Auto-updates from GitHub (replaces the old Kernl updater).
		joinchat_random_phone_init_updater();
	}

	// Works with every Joinchat from 3.0 ("WAme") to 6.x: the prefixes, handles
	// and APIs that changed between them are resolved by the Compat class.
	if ( ! JoinChatRandomPhoneCompat::installed() ) {

		add_action( 'admin_notices', 'joinchat_random_phone_joinchat_required' );

	} elseif ( ! JoinChatRandomPhoneCompat::supported() ) {

		add_action( 'admin_notices', 'joinchat_random_phone_joinchat_out_of_date' );

	} else {

		require plugin_dir_path( __FILE__ ) . 'admin/class-joinchat-random-phone-admin.php';
		require plugin_dir_path( __FILE__ ) . 'public/class-joinchat-random-phone-public.php';

		// Let the admin choose which roles can manage Joinchat. Needs the
		// 'joinchat_capability' filter, which exists since Joinchat 4.2.
		if ( JoinChatRandomPhoneCompat::has_capability_filter() ) {
			require plugin_dir_path( __FILE__ ) . 'includes/class-joinchat-random-phone-roles.php';
			new JoinChatRandomPhoneRoles();
		}

		if ( is_admin() ) {
			new JoinChatRandomPhoneAdmin( $plugin_name, JOINCHAT_RANDOM_PHONE_VERSION );
		}

		// Public class also loads in admin context because the AJAX endpoint
		// (admin-ajax.php) runs with is_admin() === true.
		$public = new JoinChatRandomPhonePublic( $plugin_name, JOINCHAT_RANDOM_PHONE_VERSION );

		// Contact split panel (read only, never touches the split itself).
		if ( is_admin() ) {
			require plugin_dir_path( __FILE__ ) . 'admin/class-joinchat-random-phone-dashboard.php';
			new JoinChatRandomPhoneDashboard( $public, JOINCHAT_RANDOM_PHONE_VERSION );
		}

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

	$file_path    = JoinChatRandomPhoneCompat::is_wame() ? 'creame-whatsapp-me/whatsappme.php' : 'creame-whatsapp-me/joinchat.php';
	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	echo '<div class="error"><p>' .
		wp_kses_post( __( '<strong>Joinchat Random Phone</strong> require a newer version of <strong>Joinchat</strong>.', 'joinchat-random-phone' ) ) .
		sprintf( ' <a href="%s">%s</a>', esc_url( $upgrade_link ), esc_html__( 'Update now', 'joinchat-random-phone' ) ) .
		'</p></div>';
}
