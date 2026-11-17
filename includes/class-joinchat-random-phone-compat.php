<?php
/**
 * Compatibility layer: one place that knows how each Joinchat generation names
 * things, so the rest of the plugin never hard-codes a prefix or a handle.
 *
 *  - 3.x  "WAme" (WhatsApp me): hooks/option/meta prefixed `whatsappme`.
 *  - 4.x+ "Joinchat": hooks/option/meta prefixed `joinchat`.
 *
 * Verified against the real code of 3.0.0 to 6.4.4.
 *
 * @package JoinChatRandomPhone
 */

defined( 'WPINC' ) || exit;

/**
 * Static helpers.
 */
class JoinChatRandomPhoneCompat {

	/**
	 * Oldest supported versions.
	 */
	const MIN_WAME     = '3.0.0';
	const MIN_JOINCHAT = '4.0.0';

	/**
	 * Is the installed plugin the old "WAme" (Joinchat 3.x)?
	 *
	 * @return bool
	 */
	public static function is_wame() {
		return ! defined( 'JOINCHAT_VERSION' ) && defined( 'WHATSAPPME_VERSION' );
	}

	/**
	 * Installed Joinchat/WAme version ('' if none).
	 *
	 * @return string
	 */
	public static function version() {
		if ( defined( 'JOINCHAT_VERSION' ) ) {
			return (string) JOINCHAT_VERSION;
		}
		return defined( 'WHATSAPPME_VERSION' ) ? (string) WHATSAPPME_VERSION : '';
	}

	/**
	 * Is Joinchat/WAme installed at all?
	 *
	 * @return bool
	 */
	public static function installed() {
		return '' !== self::version();
	}

	/**
	 * Is the installed version supported?
	 *
	 * @return bool
	 */
	public static function supported() {
		if ( ! self::installed() ) {
			return false;
		}
		return version_compare( self::version(), self::is_wame() ? self::MIN_WAME : self::MIN_JOINCHAT, '>=' );
	}

	/**
	 * Installed version is at least $version.
	 *
	 * @param string $version Version to compare.
	 * @return bool
	 */
	public static function at_least( $version ) {
		return self::installed() && version_compare( self::version(), $version, '>=' );
	}

	/**
	 * Prefix of hooks, option, fields and meta.
	 *
	 * @return string
	 */
	public static function prefix() {
		return self::is_wame() ? 'whatsappme' : 'joinchat';
	}

	/**
	 * Hook name with the right prefix ('get_settings' => 'joinchat_get_settings').
	 *
	 * @param string $name Hook name without prefix.
	 * @return string
	 */
	public static function hook( $name ) {
		return self::prefix() . '_' . $name;
	}

	/**
	 * Name of the settings option.
	 *
	 * @return string
	 */
	public static function option() {
		return self::prefix();
	}

	/**
	 * Post/term/user meta key.
	 *
	 * @return string
	 */
	public static function meta_key() {
		return '_' . self::prefix();
	}

	/**
	 * Front script handles that build the WhatsApp link (full and lite).
	 *
	 * @return array
	 */
	public static function public_handles() {
		return self::is_wame() ? array( 'whatsappme' ) : array( 'joinchat', 'joinchat-lite' );
	}

	/**
	 * Admin script/style handles of Joinchat (6.x renamed 'joinchat-admin').
	 *
	 * @return array
	 */
	public static function admin_handles() {
		return self::is_wame() ? array( 'whatsappme-admin' ) : array( 'joinchat', 'joinchat-admin' );
	}

	/**
	 * intl-tel-input script/style handles (6.3+ renamed them).
	 *
	 * @return array
	 */
	public static function iti_handles() {
		return array( 'joinchat-iti', 'joinchat-iti-core', 'intl-tel-input' );
	}

	/**
	 * The per role permissions page needs the 'joinchat_capability' filter
	 * (added in Joinchat 4.2.0).
	 *
	 * @return bool
	 */
	public static function has_capability_filter() {
		return ! self::is_wame() && self::at_least( '4.2.0' );
	}

	/**
	 * Registered handles out of a candidate list.
	 *
	 * @param array  $handles Candidates.
	 * @param string $type    'script' or 'style'.
	 * @return array
	 */
	public static function registered( $handles, $type = 'script' ) {
		$fn = 'style' === $type ? 'wp_style_is' : 'wp_script_is';
		return array_values(
			array_filter(
				$handles,
				function ( $handle ) use ( $fn ) {
					return $fn( $handle, 'registered' );
				}
			)
		);
	}
}
