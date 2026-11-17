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
	 * Script/style handle.
	 *
	 * Deliberately different from $plugin_name (which is the asset file name and
	 * the text domain). The original Creame addon registers its assets as
	 * 'joinchat-random-phone'; sharing that handle meant that with both plugins
	 * active WordPress silently loaded only one of the two files.
	 *
	 * @since    4.2.0
	 * @access   private
	 * @var      string
	 */
	private $handle = 'random-phone-for-joinchat';

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

		add_action( JoinChatRandomPhoneCompat::hook( 'run_pre' ), array( $this, 'init' ) );

	}

	/**
	 * Initialize all hooks
	 *
	 * The same hooks exist from WAme 3.x to Joinchat 6.x, only the prefix changes
	 * ('whatsappme_' in 3.x). Hooks a version doesn't have simply never fire
	 * (term metabox since 4.3, author metabox since 6.4).
	 *
	 * @since    2.0.0
	 * @param    array $joinchat       JoinChat object.
	 * @return   void
	 */
	public function init( $joinchat ) {

		$loader = $joinchat->get_loader();
		$c      = 'JoinChatRandomPhoneCompat';

		$loader->add_filter( $c::hook( 'extra_settings' ), $this, 'extra_settings' );
		$loader->add_filter( 'option_' . $c::option(), $this, 'fix_phones' );
		$loader->add_filter( $c::hook( 'field_output' ), $this, 'fields_output', 10, 3 );
		$loader->add_filter( $c::hook( 'settings_validate' ), $this, 'settings_validate', 11 );
		$loader->add_filter( 'joinchat_agents_settings_validate', $this, 'agents_settings_validate' );
		$loader->add_filter( $c::hook( 'metabox_output' ), $this, 'metabox_output', 10, 2 );
		$loader->add_filter( $c::hook( 'term_metabox_output' ), $this, 'term_metabox_output', 10, 2 );
		$loader->add_filter( $c::hook( 'user_metabox_output' ), $this, 'user_metabox_output', 10, 2 );
		$loader->add_filter( $c::hook( 'metabox_save' ), $this, 'metabox_save' );

		// After Joinchat registered its own assets (we depend on them).
		$loader->add_action( 'admin_enqueue_scripts', $this, 'register_assets', 20 );

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

		if ( is_array( $option ) && isset( $option['telephone'] ) && is_string( $option['telephone'] ) && strpos( $option['telephone'], ',' ) !== false ) {
			$phones                  = explode( ',', $option['telephone'] );
			$phones                  = array_map( 'trim', $phones );
			$option['telephone']     = array_shift( $phones );
			$option['random_phones'] = $phones;
		}

		return $option;

	}

	/**
	 * Render the multi phone field.
	 *
	 * Every phone is a plain text input with a real `name`, so the browser always
	 * submits it and the saved value stays exactly what it has always been: the
	 * full international number in digits. Flags, formatting and the country
	 * picker are display helpers on top, they never own the value.
	 *
	 * @since    4.2.0
	 * @param    string $name_tpl    Name template with %d for the index.
	 * @param    array  $phones      Phone list (the first one is the default).
	 * @param    array  $args        'compact' and 'description'.
	 * @return   string
	 */
	private function render_phones( $name_tpl, $phones, $args = array() ) {

		$args = array_merge(
			array(
				'compact'     => false,
				'description' => '',
			),
			$args
		);

		// Try to disable Chrome autocomplete and autofill phone fields.
		$autocomplete = 'autocomplete="off_' . substr( str_shuffle( md5( mt_rand() ) ), 0, 10 ) . '"';

		$phones = array_values( (array) $phones );
		if ( empty( $phones ) ) {
			$phones = array( '' );
		}

		$rows = '';
		foreach ( $phones as $index => $value ) {
			$rows .= $this->render_phone_row( $name_tpl, $index, $value, $autocomplete );
		}

		$output = '<div class="joinchat_phones jcrp' . ( $args['compact'] ? ' jcrp--compact' : '' ) . '" data-name="' . esc_attr( $name_tpl ) . '">';

		if ( ! $args['compact'] ) {
			$output .= '<div class="jcrp__country-row">' .
				'<span class="jcrp__country-label">' . esc_html__( 'Default country', 'joinchat-random-phone' ) . '</span>' .
				'<select class="jcrp__country"></select>' .
				'<span class="jcrp__country-help">' . esc_html__( 'Added automatically to the numbers you type without a country code.', 'joinchat-random-phone' ) . '</span>' .
				'</div>';
		}

		$output .= '<div class="jcrp__rows">' . $rows . '</div>' .
			'<p class="jcrp__actions">' .
			'<button type="button" class="button jcrp__add">' . esc_html__( 'Add phone', 'joinchat-random-phone' ) . '</button> ' .
			'<button type="button" class="button jcrp__paste-open">' . esc_html__( 'Paste list', 'joinchat-random-phone' ) . '</button>' .
			'</p>' .
			'<div class="jcrp__paste" hidden>' .
			'<textarea class="jcrp__paste-text" rows="4" spellcheck="false" placeholder="' . esc_attr__( 'One phone per line, or separated by commas. Repeat a number to give it more slots.', 'joinchat-random-phone' ) . '"></textarea>' .
			'<p>' .
			'<button type="button" class="button button-primary jcrp__paste-add">' . esc_html__( 'Add to the list', 'joinchat-random-phone' ) . '</button> ' .
			'<button type="button" class="button jcrp__paste-cancel">' . esc_html__( 'Cancel', 'joinchat-random-phone' ) . '</button> ' .
			'<label class="jcrp__paste-replace"><input type="checkbox"> ' . esc_html__( 'Replace the current list', 'joinchat-random-phone' ) . '</label>' .
			'</p></div>' .
			'<p class="jcrp__summary" aria-live="polite"></p>' .
			'</div>';

		if ( $args['description'] ) {
			$output .= '<p class="description">' . $args['description'] . '</p>';
		}

		return $output;

	}

	/**
	 * Render one phone row.
	 *
	 * @since    4.2.0
	 * @param    string $name_tpl        Name template with %d for the index.
	 * @param    int    $index           Row index.
	 * @param    string $value           Phone value.
	 * @param    string $autocomplete    Autocomplete attribute.
	 * @return   string
	 */
	private function render_phone_row( $name_tpl, $index, $value, $autocomplete ) {

		return '<div class="jcrp__row">' .
			'<span class="jcrp__index">' . ( (int) $index + 1 ) . '</span>' .
			'<span class="jcrp__flag" aria-hidden="true"></span>' .
			'<input class="joinchat_phone jcrp__input" type="text" name="' . esc_attr( sprintf( $name_tpl, $index ) ) . '" value="' . esc_attr( $value ) . '" ' . $autocomplete . '>' .
			'<span class="jcrp__pretty" aria-hidden="true"></span>' .
			'<span class="jcrp__weight"></span>' .
			'<button type="button" class="jcrp__del" title="' . esc_attr__( 'Remove this phone', 'joinchat-random-phone' ) . '" aria-label="' . esc_attr__( 'Remove this phone', 'joinchat-random-phone' ) . '">&times;</button>' .
			'</div>';

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
			wp_enqueue_script( $this->handle );
			wp_enqueue_style( $this->handle );

			$phones = array_merge(
				array( isset( $settings['telephone'] ) ? $settings['telephone'] : '' ),
				isset( $settings['random_phones'] ) && is_array( $settings['random_phones'] ) ? $settings['random_phones'] : array()
			);

			$output = $this->render_phones(
				JoinChatRandomPhoneCompat::option() . '[telephone][%d]',
				$phones,
				array(
					'description' => __( 'Contact phone numbers <strong>(users will contact one of them randomly)</strong>', 'joinchat-random-phone' ) .
						'<br>' . __( 'Repeat a number to give it more slots. The first one is only the fallback: it is used when the server cannot assign one in time.', 'joinchat-random-phone' ),
				)
			);

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
		wp_enqueue_script( $this->handle );
		wp_enqueue_style( $this->handle );

		$field = strpos( $output, 'joinchat_agents[list]' ) !== false ? 'joinchat_agents[list]' : 'joinchat[agents]'; // >= 3.0 : < 3.0

		$phones = array_merge(
			array( isset( $agent['phone'] ) ? $agent['phone'] : '' ),
			isset( $agent['random_phones'] ) && is_array( $agent['random_phones'] ) ? $agent['random_phones'] : array()
		);

		return '<div class="p"><label>' . __( 'Telephone', 'creame-whatsapp-me' ) . '</label> ' .
			$this->render_phones(
				"{$field}[$key][phone][%d]",
				$phones,
				array(
					'compact'     => true,
					'description' => __( 'User will contact a random one', 'joinchat-random-phone' ),
				)
			) .
			'</div>';

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

		$p = JoinChatRandomPhoneCompat::prefix();

		$phones  = $this->meta_phones( is_object( $post ) ? get_post_meta( $post->ID, JoinChatRandomPhoneCompat::meta_key(), true ) : array() );
		$replace = '<label class="jcrp__title">' . __( 'Telephone', 'creame-whatsapp-me' ) . '</label>' .
			$this->render_phones( $p . '_telephone[%d]', $phones, array( 'compact' => true ) );

		// The whole "<p><label for=..._phone>...<input ...></p>" block. Same in
		// every version from WAme 3.0.3 to Joinchat 6.4.
		if ( preg_match( '/<p>\s*<label for="' . $p . '_phone">/', $output, $m, PREG_OFFSET_CAPTURE ) ) {
			$from = $m[0][1];
			$end  = strpos( $output, '</p>', $from );
			if ( false !== $end ) {
				return $this->enqueue_and( substr_replace( $output, $replace, $from, $end + 4 - $from ) );
			}
		}

		// Unknown markup: replace only the input (or keep Joinchat's own field).
		return $this->replace_input( $output, $p, $replace );

	}

	/**
	 * Random Phone term edit form phone field (Joinchat 4.3+)
	 *
	 * @since    3.4.0
	 * @param    string  $output       metabox output.
	 * @param    WP_Term $term         Current term object.
	 * @return   string
	 */
	public function term_metabox_output( $output, $term ) {

		$meta = is_object( $term ) && function_exists( 'get_term_meta' ) ? get_term_meta( $term->term_id, JoinChatRandomPhoneCompat::meta_key(), true ) : array();

		return $this->replace_input(
			$output,
			JoinChatRandomPhoneCompat::prefix(),
			$this->render_phones( JoinChatRandomPhoneCompat::prefix() . '_telephone[%d]', $this->meta_phones( $meta ), array( 'compact' => true ) )
		);

	}

	/**
	 * Random Phone author profile phone field (Joinchat 6.4+)
	 *
	 * @since    4.4.0
	 * @param    string  $output       metabox output.
	 * @param    WP_User $user         Current user object.
	 * @return   string
	 */
	public function user_metabox_output( $output, $user ) {

		$meta = is_object( $user ) ? get_user_meta( $user->ID, JoinChatRandomPhoneCompat::meta_key(), true ) : array();

		return $this->replace_input(
			$output,
			JoinChatRandomPhoneCompat::prefix(),
			$this->render_phones( JoinChatRandomPhoneCompat::prefix() . '_telephone[%d]', $this->meta_phones( $meta ), array( 'compact' => true ) )
		);

	}

	/**
	 * Phone list saved in a post/term/user meta.
	 *
	 * @param    mixed $meta  Saved meta.
	 * @return   array
	 */
	private function meta_phones( $meta ) {

		$meta = is_array( $meta ) ? $meta : array();

		return array_merge(
			array( isset( $meta['telephone'] ) && is_scalar( $meta['telephone'] ) ? (string) $meta['telephone'] : '' ),
			isset( $meta['random_phones'] ) && is_array( $meta['random_phones'] ) ? $meta['random_phones'] : array()
		);

	}

	/**
	 * Replace Joinchat's single phone <input id="{prefix}_phone"> with our field.
	 *
	 * If the input can't be found the output is returned untouched: Joinchat's
	 * own field keeps working (one phone), nothing breaks.
	 *
	 * @param    string $output   Metabox output.
	 * @param    string $p        Prefix.
	 * @param    string $replace  Our field.
	 * @return   string
	 */
	private function replace_input( $output, $p, $replace ) {

		if ( ! is_string( $output ) || ! preg_match( '/<input\b[^>]*\bid="' . $p . '_phone"[^>]*>/', $output, $m, PREG_OFFSET_CAPTURE ) ) {
			return $output;
		}

		return $this->enqueue_and( substr_replace( $output, $replace, $m[0][1], strlen( $m[0][0] ) ) );

	}

	/**
	 * Enqueue our assets and return the given output.
	 *
	 * @param    string $output  Output.
	 * @return   string
	 */
	private function enqueue_and( $output ) {

		wp_enqueue_script( $this->handle );
		wp_enqueue_style( $this->handle );

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

		// Depend only on handles that exist in this Joinchat version: a missing
		// dependency makes WordPress silently skip our script. Admin handle:
		// 'whatsappme-admin' (3.x), 'joinchat-admin' (4.x-5.x), 'joinchat' (6.x).
		// intl-tel-input (flags, formats): 'intl-tel-input' or 'joinchat-iti'
		// (6.3+); loading it first lets the field use it on every screen.
		$c           = 'JoinChatRandomPhoneCompat';
		$script_deps = array_merge( array( 'jquery' ), $c::registered( $c::iti_handles() ), $c::registered( $c::admin_handles() ) );
		$style_deps  = array_merge( $c::registered( $c::iti_handles(), 'style' ), $c::registered( $c::admin_handles(), 'style' ) );

		wp_register_script( $this->handle, plugin_dir_url( __FILE__ ) . "js/{$this->plugin_name}{$min}.js", array_unique( $script_deps ), $this->version, true );
		wp_register_style( $this->handle, plugin_dir_url( __FILE__ ) . "css/{$this->plugin_name}{$min}.css", array_unique( $style_deps ), $this->version, 'all' );

		wp_localize_script(
			$this->handle,
			'jcrp_l10n',
			array(
				'slot'       => __( 'slot', 'joinchat-random-phone' ),
				'slots'      => __( 'slots', 'joinchat-random-phone' ),
				'number'     => __( 'number', 'joinchat-random-phone' ),
				'numbers'    => __( 'numbers', 'joinchat-random-phone' ),
				/* translators: %1$s: number of slots, %2$s: percentage. */
				'most'       => __( 'the most loaded gets %1$s slots (%2$s%%)', 'joinchat-random-phone' ),
				/* translators: %s: percentage. */
				'even'       => __( 'even split, %s%% each', 'joinchat-random-phone' ),
				'remove'     => __( 'Remove this phone', 'joinchat-random-phone' ),
				/* translators: %1$s: times repeated, %2$s: total slots, %3$s: percentage. */
				'weight_tip' => __( 'This number is repeated %1$s times: it takes %1$s of the %2$s slots (%3$s%%)', 'joinchat-random-phone' ),
				'no_country' => __( '— none —', 'joinchat-random-phone' ),
			)
		);

	}

}
