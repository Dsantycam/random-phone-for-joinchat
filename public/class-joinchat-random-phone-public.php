<?php
/**
 * The public-facing functionality of the plugin.
 *
 * Implements a GLOBAL + STICKY random phone distribution:
 *
 *  - GLOBAL (fair): a server-side "shuffle-bag" shared by all visitors. Every
 *    new visitor pops the next phone from a shuffled queue; when the queue is
 *    empty it is refilled and reshuffled. No phone repeats until the whole list
 *    is exhausted, so contacts are split as evenly as possible between agents.
 *    The pop happens over an AJAX call so it keeps working behind page caches.
 *
 *  - STICKY (per visitor): once a visitor gets a phone it is stored in their
 *    browser (localStorage). On later clicks/visits they always reach the SAME
 *    agent, never a different one.
 *
 * The site 'telephone' setting is always kept as a single valid string (used by
 * the QR, the server and as a safe fallback); the random selection only happens
 * on the visitor side.
 *
 * @package JoinChatRandomPhone
 * @author  Santy
 */

defined( 'WPINC' ) || exit;

/**
 * Public class.
 */
class JoinChatRandomPhonePublic {

	const AJAX_ACTION = 'joinchat_random_phone';
	const OPT_LISTS   = 'joinchat_random_phone_lists';
	const OPT_BAGS    = 'joinchat_random_phone_bags';
	const MAX_LISTS   = 50;

	/**
	 * The ID of this plugin.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * There are random phones for the current view.
	 *
	 * @var bool
	 */
	private $has_random = false;

	/**
	 * Cleaned phone list for the current view.
	 *
	 * @var array
	 */
	private $list = array();

	/**
	 * Signature (hash) of the current phone list.
	 *
	 * @var string
	 */
	private $signature = '';

	/**
	 * Initialize the class and set init hook.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( 'joinchat_run_pre', array( $this, 'init' ) );

		// AJAX endpoint that pops the next phone from the global bag.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_next_phone' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_next_phone' ) );
	}

	/**
	 * Initialize front hooks (only on non-ajax front requests).
	 *
	 * @param object $joinchat JoinChat object.
	 * @return void
	 */
	public function init( $joinchat ) {

		$loader = $joinchat->get_loader();

		$loader->add_filter( 'joinchat_extra_settings', $this, 'extra_settings' );
		$loader->add_filter( 'option_joinchat', $this, 'fix_phones' );
		$loader->add_filter( 'joinchat_get_settings', $this, 'get_settings', 15, 2 );

		// Enqueue late (priority 20) so Joinchat script is already enqueued.
		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_scripts', 20 );
	}

	/**
	 * Add Random Phone settings default.
	 *
	 * @param array $settings Current settings.
	 * @return array
	 */
	public function extra_settings( $settings ) {
		return array_merge( $settings, array( 'random_phones' => array() ) );
	}

	/**
	 * Migrate old comma separated list of phones to 'telephone' + 'random_phones'.
	 *
	 * @param array $option Current option.
	 * @return array
	 */
	public function fix_phones( $option ) {

		if ( isset( $option['telephone'] ) && is_string( $option['telephone'] ) && strpos( $option['telephone'], ',' ) !== false ) {
			$phones                  = array_map( 'trim', explode( ',', $option['telephone'] ) );
			$option['telephone']     = array_shift( $phones );
			$option['random_phones'] = $phones;
		}

		return $option;
	}

	/**
	 * Prepare Random Phone front settings.
	 *
	 * Builds the cleaned phone list for the current view, keeps 'telephone' as a
	 * single string and stores the list (keyed by signature) for the AJAX bag.
	 * Agents phones keep the original client-side behaviour (array merged).
	 *
	 * @param array $settings Current settings.
	 * @param mixed $obj      Queried object.
	 * @return array
	 */
	public function get_settings( $settings, $obj ) {

		// If the current post has its own custom phone but no random list, don't
		// apply the site random phones on it.
		$post_settings = is_a( $obj, 'WP_Post' ) ? get_post_meta( $obj->ID, '_joinchat', true ) : '';
		if ( is_array( $post_settings ) && isset( $post_settings['telephone'] ) && ! isset( $post_settings['random_phones'] ) ) {
			$settings['random_phones'] = array();
		}

		$randoms = isset( $settings['random_phones'] ) && is_array( $settings['random_phones'] ) ? $settings['random_phones'] : array();

		// Build cleaned list: default telephone first, then the extra phones.
		$list    = array();
		$default = $this->clean_phone( isset( $settings['telephone'] ) ? $settings['telephone'] : '' );

		if ( '' !== $default ) {
			$list[] = $default;
		}
		foreach ( $randoms as $phone ) {
			$phone = $this->clean_phone( $phone );
			if ( '' !== $phone ) {
				$list[] = $phone;
			}
		}

		unset( $settings['random_phones'] );

		if ( count( $list ) > 1 ) {
			$this->has_random = true;
			$this->list       = $list;
			$this->signature  = substr( md5( wp_json_encode( $list ) ), 0, 16 );

			// telephone stays as the first valid phone (default / fallback / QR).
			$settings['telephone'] = $list[0];

			// Persist the list so the AJAX endpoint can (re)build the bag.
			$this->maybe_store_list( $this->signature, $list );
		}

		// Support Agents addon: keep the original per-browser behaviour (array).
		$settings = $this->merge_agents_phones( $settings );

		return $settings;
	}

	/**
	 * Merge Agents addon random phones into the agent phone arrays (client-side).
	 *
	 * @param array $settings Current settings.
	 * @return array
	 */
	private function merge_agents_phones( $settings ) {

		if ( ! isset( $settings['agents'] ) ) {
			return $settings;
		}

		if ( isset( $settings['agents']['list'] ) ) {
			$agents = &$settings['agents']['list']; // Agents >= 3.0.
		} else {
			$agents = &$settings['agents']; // Agents < 3.0.
		}

		foreach ( $agents as $key => $agent ) {
			if ( isset( $agent['random_phones'] ) && is_array( $agent['random_phones'] ) ) {
				$phones = array( $agent['phone'] );
				foreach ( $agent['random_phones'] as $phone ) {
					$phones[] = $this->clean_phone( $phone );
				}

				$agents[ $key ]['phone'] = array_values( array_filter( $phones ) );
				unset( $agents[ $key ]['random_phones'] );

				$this->has_random = true;
			}
		}

		return $settings;
	}

	/**
	 * Enqueue the public script and pass the AJAX config.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {

		if ( ! $this->has_random || ! wp_script_is( 'joinchat', 'enqueued' ) ) {
			return;
		}

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . "js/{$this->plugin_name}{$min}.js",
			array( 'joinchat' ),
			$this->version,
			true
		);

		wp_localize_script(
			$this->plugin_name,
			'joinchat_random_phone',
			array(
				'url'       => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::AJAX_ACTION ),
				'signature' => $this->signature,
			)
		);
	}

	/**
	 * AJAX handler: pop and return the next phone from the global bag.
	 *
	 * Atomic across concurrent visitors thanks to a MySQL named lock. If the lock
	 * is unavailable it degrades to best-effort (Santy accepts non-perfect split).
	 *
	 * @return void
	 */
	public function ajax_next_phone() {

		$signature = isset( $_REQUEST['signature'] ) ? sanitize_key( wp_unslash( $_REQUEST['signature'] ) ) : '';

		// Non-fatal nonce check (page caches can serve stale nonces; the response
		// only reveals an already-public phone number, so we never hard fail).
		if ( isset( $_REQUEST['nonce'] ) ) {
			check_ajax_referer( self::AJAX_ACTION, 'nonce', false );
		}

		$lists = get_option( self::OPT_LISTS, array() );

		if ( empty( $signature ) || ! isset( $lists[ $signature ]['list'] ) ) {
			wp_send_json_error( array( 'message' => 'unknown_signature' ) );
		}

		$list  = $lists[ $signature ]['list'];
		$phone = $this->pop_from_bag( $signature, $list );

		wp_send_json_success( array( 'phone' => $phone ) );
	}

	/**
	 * Pop the next phone from the global bag for a signature (atomic).
	 *
	 * @param string $signature List signature.
	 * @param array  $list      Full phone list.
	 * @return string
	 */
	private function pop_from_bag( $signature, $list ) {

		global $wpdb;

		$lock_name = 'jcrp_' . substr( $signature, 0, 20 );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 3 ) );

		// Read the bags map straight from the DB (bypass any object cache).
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_BAGS ) );
		$bags = $raw ? maybe_unserialize( $raw ) : array();
		if ( ! is_array( $bags ) ) {
			$bags = array();
		}

		$queue = isset( $bags[ $signature ] ) && is_array( $bags[ $signature ] ) ? $bags[ $signature ] : array();

		// Refill + shuffle when the bag is empty.
		if ( empty( $queue ) ) {
			$queue = $list;
			shuffle( $queue );
		}

		$phone = array_shift( $queue );

		$bags[ $signature ] = $queue;
		$this->save_bags( $bags );

		if ( 1 === $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		// Fallback safety.
		return ! empty( $phone ) ? $phone : ( isset( $list[0] ) ? $list[0] : '' );
	}

	/**
	 * Persist the bags map straight to the DB (bypass object cache).
	 *
	 * @param array $bags Bags map.
	 * @return void
	 */
	private function save_bags( $bags ) {

		global $wpdb;

		// Drop bags whose list no longer exists. The lists map is already capped
		// at MAX_LISTS, so this keeps the bags from growing forever as the phone
		// list gets edited over the years.
		if ( count( $bags ) > self::MAX_LISTS ) {
			$lists = get_option( self::OPT_LISTS, array() );
			if ( is_array( $lists ) && ! empty( $lists ) ) {
				$bags = array_intersect_key( $bags, $lists );
			}
		}

		$value = maybe_serialize( $bags );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				self::OPT_BAGS,
				$value
			)
		);

		// Keep any object cache coherent.
		wp_cache_delete( self::OPT_BAGS, 'options' );
	}

	/**
	 * Store the phone list for a signature (only writes when new).
	 *
	 * @param string $signature List signature.
	 * @param array  $list      Full phone list.
	 * @return void
	 */
	private function maybe_store_list( $signature, $list ) {

		$lists = get_option( self::OPT_LISTS, array() );

		if ( isset( $lists[ $signature ]['list'] ) && $lists[ $signature ]['list'] === $list ) {
			return; // Already stored, no write.
		}

		$lists[ $signature ] = array(
			'list' => $list,
			'ts'   => time(),
		);

		// Prune oldest entries if we grow too much.
		if ( count( $lists ) > self::MAX_LISTS ) {
			uasort(
				$lists,
				function ( $a, $b ) {
					$ta = isset( $a['ts'] ) ? $a['ts'] : 0;
					$tb = isset( $b['ts'] ) ? $b['ts'] : 0;
					return $ta <=> $tb;
				}
			);
			$lists = array_slice( $lists, -self::MAX_LISTS, null, true );
		}

		update_option( self::OPT_LISTS, $lists, false );
	}

	/**
	 * Clean a phone number to digits only (drops leading zeros).
	 *
	 * Done in two steps on purpose: a single '/^0+|\D/' pass would leave the
	 * zeros of "+0034600111222" behind, because once the '+' is dropped the
	 * zeros are no longer at the start of the subject string.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	private function clean_phone( $phone ) {
		return ltrim( preg_replace( '/\D/', '', (string) $phone ), '0' );
	}
}
