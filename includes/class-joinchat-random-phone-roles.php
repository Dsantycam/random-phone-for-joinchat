<?php
/**
 * Choose which roles can manage Joinchat.
 *
 * Joinchat asks for the capability through the 'joinchat_capability' filter
 * ('manage_options' by default). We swap it for a custom capability that
 * administrators always have and that is granted on the fly to the roles
 * selected in Settings > Joinchat permissions. Roles are not modified in the
 * database, so deactivating the plugin restores the default behaviour.
 *
 * @since      4.3.0
 * @package    JoinChatRandomPhone
 */

defined( 'WPINC' ) || exit;

/**
 * Roles settings class.
 */
class JoinChatRandomPhoneRoles {

	const CAP    = 'jcrp_manage_joinchat';
	const OPTION = 'joinchat_random_phone_roles';
	const PAGE   = 'joinchat-permissions';

	/**
	 * Hook everything.
	 *
	 * @since    4.3.0
	 */
	public function __construct() {

		add_filter( 'joinchat_capability', array( $this, 'capability' ) );
		add_filter( 'option_page_capability_joinchat', array( $this, 'capability' ) );
		add_filter( 'user_has_cap', array( $this, 'grant_cap' ), 10, 4 );

		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );

	}

	/**
	 * Capability Joinchat must check.
	 *
	 * @since    4.3.0
	 * @param    string $capability Requested capability.
	 * @return   string
	 */
	public function capability( $capability ) {

		return 'manage_options' === $capability ? self::CAP : $capability;

	}

	/**
	 * Give the custom capability to admins and to the selected roles.
	 *
	 * @since    4.3.0
	 * @param    array   $allcaps All capabilities of the user.
	 * @param    array   $caps    Required primitive capabilities.
	 * @param    array   $args    Arguments (requested cap first).
	 * @param    WP_User $user    The user.
	 * @return   array
	 */
	public function grant_cap( $allcaps, $caps, $args, $user ) {

		if ( ! in_array( self::CAP, $caps, true ) ) {
			return $allcaps;
		}

		$allowed = ! empty( $allcaps['manage_options'] ) || array_intersect( (array) $user->roles, self::roles() );

		if ( $allowed ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;

	}

	/**
	 * Selected roles.
	 *
	 * @since    4.3.0
	 * @return   array
	 */
	public static function roles() {

		return array_values( array_filter( (array) get_option( self::OPTION, array() ), 'is_string' ) );

	}

	/**
	 * Add the settings page (administrators only).
	 *
	 * @since    4.3.0
	 * @return   void
	 */
	public function menu() {

		add_options_page(
			__( 'Joinchat permissions', 'joinchat-random-phone' ),
			__( 'Joinchat permissions', 'joinchat-random-phone' ),
			'manage_options',
			self::PAGE,
			array( $this, 'page' )
		);

	}

	/**
	 * Register the option.
	 *
	 * @since    4.3.0
	 * @return   void
	 */
	public function register() {

		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

	}

	/**
	 * Keep only existing roles.
	 *
	 * @since    4.3.0
	 * @param    mixed $input Submitted roles.
	 * @return   array
	 */
	public function sanitize( $input ) {

		$valid = array_keys( wp_roles()->get_names() );

		return array_values( array_intersect( array_map( 'sanitize_key', (array) $input ), $valid ) );

	}

	/**
	 * Render the settings page.
	 *
	 * @since    4.3.0
	 * @return   void
	 */
	public function page() {

		$selected = self::roles();

		echo '<div class="wrap"><h1>' . esc_html__( 'Joinchat permissions', 'joinchat-random-phone' ) . '</h1>';
		echo '<p>' . esc_html__( 'Choose which roles, besides administrators, can view and change the Joinchat settings.', 'joinchat-random-phone' ) . '</p>';
		echo '<form method="post" action="options.php">';

		settings_fields( self::PAGE );

		echo '<fieldset>';

		foreach ( wp_roles()->get_names() as $role => $name ) {
			if ( 'administrator' === $role ) {
				continue;
			}

			printf(
				'<p><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label></p>',
				esc_attr( self::OPTION ),
				esc_attr( $role ),
				checked( in_array( $role, $selected, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}

		echo '</fieldset>';

		submit_button();

		echo '</form></div>';

	}
}
