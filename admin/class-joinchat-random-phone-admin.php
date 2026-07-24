<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version and init hooks to
 * enqueue the admin-specific functionalities.
 *
 * @since      2.0.0
 * @package    JoinChatRandomPhone
 * @author     Creame <hola@crea.me>
 */
class JoinChatRandomPhoneAdmin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    2.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set init hook.
	 *
	 * @since    2.0.0
	 * @param    string $plugin_name       The name of this plugin.
	 * @param    string $version           The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( 'joinchat_run_pre', array( $this, 'init' ) );

	}

	/**
	 * Initialize all hooks
	 *
	 * @since    2.0.0
	 * @param    array $joinchat       JoinChat object.
	 * @return   void
	 */
	public function init( $joinchat ) {

		$loader = $joinchat->get_loader();

		$loader->add_filter( 'joinchat_extra_settings', $this, 'extra_settings' );
		$loader->add_filter( 'option_joinchat', $this, 'fix_phones' );
		$loader->add_filter( 'joinchat_field_output', $this, 'fields_output', 10, 3 );
		$loader->add_filter( 'joinchat_settings_validate', $this, 'settings_validate', 11 );
		$loader->add_filter( 'joinchat_agents_settings_validate', $this, 'agents_settings_validate' );
		$loader->add_filter( 'joinchat_metabox_output', $this, 'metabox_output', 10, 2 );
		$loader->add_filter( 'joinchat_term_metabox_output', $this, 'term_metabox_output', 10, 2 );
		$loader->add_filter( 'joinchat_metabox_save', $this, 'metabox_save' );

		$loader->add_action( 'admin_enqueue_scripts', $this, 'register_assets' );

		// Support Agents addon compatible
		$loader->add_filter( 'joinchat_agent_phone', $this, 'agent_phones', 10, 3 );

	}

	/**
	 * Add Random Phone settings defaults
	 *
	 * @since    2.0.0
	 * @param    array $extra       current settings.
	 * @return   array
	 */
	public function extra_settings( $extra ) {

		return array_merge( $extra, array( 'random_phones' => array() ) );

	}

	/**
	 * Fix previous version of Joinchat Random Phones
	 *
	 * Convert comma separated list of phones to one 'telephone'
	 * and 'random_phones' fields.
	 *
	 * @since    2.0.0
	 * @param    array $option       current option.
	 * @return   array
	 */
	public function fix_phones( $option ) {

		if ( strpos( $option['telephone'], ',' ) !== false ) {
			$phones                  = explode( ',', $option['telephone'] );
			$phones                  = array_map( 'trim', $phones );
			$option['telephone']     = array_shift( $phones );
			$option['random_phones'] = $phones;
		}

		return $option;

	}

	/**
	 * Random Phones fields HTML output
	 *
	 * @since    2.0.0
	 * @param    string $output       current field output.
	 * @param    string $field_id     current field id.
	 * @param    array  $settings     current joinchat settings.
	 * @return   string
	 */
	public function fields_output( $output, $field_id, $settings ) {

		if ( 'telephone' === $field_id ) {

			// Enqueue assets
			wp_enqueue_script( $this->plugin_name );
			wp_enqueue_style( $this->plugin_name );

			$phone  = isset( $settings['telephone'] ) ? $settings['telephone'] : '';
			$phones = isset( $settings['random_phones'] ) ? $settings['random_phones'] : array();

			$name = strpos( $output, 'data-name' ) !== false ? 'data-name' : 'name';
			// Try to disable Chrome autocomplete and autofill phone fields
			$autocomplete = 'autocomplete="off_' . substr( str_shuffle( md5( mt_rand() ) ), 0, 10 ) . '"';

			$phone  = esc_attr( $phone );
			$output = '<div class="joinchat_phones">' .
				"<input class=\"joinchat_phone\" $name=\"joinchat[telephone][0]\" value=\"$phone\" type=\"text\" $autocomplete>";

			if ( count( $phones ) ) {
				foreach ( $phones as $key => $value ) {
					$count   = $key + 1;
					$value   = esc_attr( $value );
					$output .= "<br><input class=\"joinchat_phone\" $name=\"joinchat[telephone][$count]\" value=\"$value\" type=\"text\" $autocomplete>";
				}
			}

			$output .= '<a href="#" class="joinchat_phone__add dashicons dashicons-plus-alt" role="button" title="' . __( 'Add phone number', 'joinchat-random-phone' ) . '"></a>' .
				'</div>' .
				'<p class="description">' . __( 'Contact phone numbers <strong>(users will contact one of them randomly)</strong>', 'joinchat-random-phone' ) . '</p>';

		}

		return $output;

	}

	/**
	 * Random Phone settings validation
	 *
	 * @since    2.0.0
	 * @param    array $input       form input.
	 * @return   array
	 */
	public function settings_validate( $input ) {

		if ( is_array( $input['telephone'] ) ) {
			$phones                 = array_filter( $input['telephone'] );
			$input['telephone']     = array_shift( $phones );
			$input['random_phones'] = $phones;
		}

		// Support Agents addon ( < 3.0 )
		if ( isset( $input['agents'] ) && ! isset( $input['agents']['list'] ) ) {
			foreach ( $input['agents'] as $key => $agent ) {
				$phones = array_filter( (array) $agent['phone'] );

				$input['agents'][ $key ]['phone'] = array_shift( $phones );
				if ( count( $phones ) ) {
					$input['agents'][ $key ]['random_phones'] = $phones;
				}
			}
		}

		return $input;

	}

	/**
	 * Support Agents phones field HTML output
	 *
	 * @since    3.0.0
	 * @param    string $output     current phone field output.
	 * @param    string $agent      agent data.
	 * @param    array  $key        field key.
	 * @return   string
	 */
	public function agent_phones( $output, $agent, $key ) {

		// Enqueue assets
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name );

		$phone  = isset( $agent['phone'] ) ? $agent['phone'] : '';
		$phones = isset( $agent['random_phones'] ) ? $agent['random_phones'] : array();

		$name  = strpos( $output, 'data-name' ) !== false ? 'data-name' : 'name';
		$field = strpos( $output, 'joinchat_agents[list]' ) !== false ? 'joinchat_agents[list]' : 'joinchat[agents]'; // >= 3.0 : < 3.0
		// Try to disable Chrome autocomplete and autofill phone fields
		$autocomplete = 'autocomplete="off_' . substr( str_shuffle( md5( mt_rand() ) ), 0, 10 ) . '"';

		$phone  = esc_attr( $phone );
		$output = '<div class="p"><label>' . __( 'Telephone', 'creame-whatsapp-me' ) . '</label> ' .
			'<div class="joinchat_phones">' .
			"<input type=\"text\" class=\"joinchat_phone\" $name=\"{$field}[$key][phone][0]\" value=\"$phone\" $autocomplete>";

		if ( count( $phones ) ) {
			foreach ( $phones as $pos => $value ) {
				$count   = $pos + 1;
				$value   = esc_attr( $value );
				$output .= "<br><input type=\"text\" class=\"joinchat_phone\" $name=\"{$field}[$key][phone][$count]\" value=\"$value\" $autocomplete>";
			}
		}

		$output .= '<a href="#" class="joinchat_phone__add dashicons dashicons-plus-alt" role="button" title="' . __( 'Add phone number', 'joinchat-random-phone' ) . '"></a>' .
			'<br><span class="description">' . __( 'User will contact a random one', 'joinchat-random-phone' ) . '</span>' .
			'</div></div>';

		return $output;

	}

	/**
	 * Support Agents ( >= 3.0 ) settings validation
	 *
	 * @since    3.2.0
	 * @param    array $input       form input.
	 * @return   array
	 */
	public function agents_settings_validate( $input ) {

		foreach ( $input['list'] as $key => $agent ) {
			$phones = array_filter( (array) $agent['phone'] );

			$input['list'][ $key ]['phone'] = array_shift( $phones );
			if ( count( $phones ) ) {
				$input['list'][ $key ]['random_phones'] = $phones;
			}
		}

		return $input;

	}

	/**
	 * Random Phone metabox output
	 *
	 * @since    2.1.0
	 * @param    string  $output       metabox output.
	 * @param    WP_Post $post         Current post object.
	 * @return   string
	 */
	public function metabox_output( $output, $post ) {

		// Enqueue assets
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name );

		$replace = '<div class="joinchat_phones"><label>' . __( 'Telephone', 'creame-whatsapp-me' ) . '</label>';

		$postmeta = get_post_meta( $post->ID, '_joinchat', true ) ?: array();

		$phones = array_merge(
			array( isset( $postmeta['telephone'] ) ? $postmeta['telephone'] : '' ),
			isset( $postmeta['random_phones'] ) ? $postmeta['random_phones'] : array()
		);

		$name = strpos( $output, 'data-name' ) !== false ? 'data-name' : 'name';

		foreach ( $phones as $key => $value ) {
			$value    = esc_attr( $value );
			$replace .= "<br><input class=\"joinchat_phone\" $name=\"joinchat_telephone[$key]\" value=\"$value\" type=\"text\" autocomplete=\"off\">";
		}

		$replace .= '<a href="#" class="joinchat_phone__add dashicons dashicons-plus-alt" role="button" ' .
			'title="' . __( 'Add phone number', 'joinchat-random-phone' ) . '"></a></div>';

		// Replace phone input
		preg_match( '/<p>\s*<label for="joinchat_phone">/m', $output, $matches, PREG_OFFSET_CAPTURE );
		$from   = $matches[0][1];
		$length = strpos( $output, '</p>', $from ) + strlen( '</p>' ) - $from;
		$output = substr_replace( $output, $replace, $from, $length );

		return $output;

	}


	/**
	 * Random Phone term edit form phone field
	 *
	 * @since    3.4.0
	 * @param    string  $output       metabox output.
	 * @param    WP_Term $term         Current post object.
	 * @return   string
	 */
	public function term_metabox_output( $output, $term ) {

		// Enqueue assets
		wp_enqueue_script( $this->plugin_name );
		wp_enqueue_style( $this->plugin_name );

		$replace = '<div class="joinchat_phones">';

		$meta = get_term_meta( $term->term_id, '_joinchat', true ) ?: array();

		$phones = array_merge(
			array( isset( $meta['telephone'] ) ? $meta['telephone'] : '' ),
			isset( $meta['random_phones'] ) ? $meta['random_phones'] : array()
		);

		$name = strpos( $output, 'data-name' ) !== false ? 'data-name' : 'name';

		foreach ( $phones as $key => $value ) {
			$value    = esc_attr( $value );
			$replace .= $key > 0 ? '<br>' : '';
			$replace .= "<input class=\"joinchat_phone\" $name=\"joinchat_telephone[$key]\" value=\"$value\" type=\"text\" autocomplete=\"off\">";
		}

		$replace .= '<a href="#" class="joinchat_phone__add dashicons dashicons-plus-alt" role="button" ' .
			'title="' . __( 'Add phone number', 'joinchat-random-phone' ) . '"></a></div>';

		// Replace phone input
		$from   = strpos( $output, '<input id="joinchat_phone"' );
		$length = strpos( $output, '</td>', $from ) - $from;
		$output = substr_replace( $output, $replace, $from, $length );

		return $output;

	}

	/**
	 * Split telephone array in to first phone number
	 * and other number as extra random_phones
	 *
	 * @since    2.1.0
	 * @param    array $metadata         _joinchat data to save.
	 * @return   array
	 */
	public function metabox_save( $metadata ) {

		if ( isset( $metadata['telephone'] ) && is_array( $metadata['telephone'] ) ) {
			$phones = array_filter( $metadata['telephone'] );

			if ( count( $phones ) ) {
				$metadata['telephone'] = array_shift( $phones );
			} else {
				unset( $metadata['telephone'] );
			}

			if ( count( $phones ) ) {
				$metadata['random_phones'] = $phones;
			}
		}

		return $metadata;

	}

	/**
	 * Register the stylesheets ans scripts for the admin area
	 *
	 * @since    2.0.0
	 * @return   void
	 */
	public function register_assets() {

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . "js/{$this->plugin_name}{$min}.js", array( 'joinchat-admin' ), $this->version, true );
		wp_register_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . "css/{$this->plugin_name}{$min}.css", array( 'joinchat-admin' ), $this->version, 'all' );

	}

}
