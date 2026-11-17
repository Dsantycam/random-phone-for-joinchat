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

	/**
	 * Script handle.
	 *
	 * Deliberately different from the asset file name: the original Creame addon
	 * registers its script as 'joinchat-random-phone', and sharing that handle
	 * meant that with both plugins active WordPress loaded only one of the two
	 * files, silently disabling the split.
	 */
	const HANDLE = 'random-phone-for-joinchat';

	const AJAX_ACTION = 'joinchat_random_phone';
	const OPEN_ACTION = 'joinchat_random_phone_open';
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
	 * The last pop_from_bag() call handed out a new slot (not a repeat).
	 *
	 * @var bool
	 */
	private $popped = false;

	/**
	 * Initialize the class and set init hook.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( JoinChatRandomPhoneCompat::hook( 'run_pre' ), array( $this, 'init' ) );

		// AJAX endpoint that pops the next phone from the global bag.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_next_phone' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_next_phone' ) );

		// AJAX endpoint that counts WhatsApp opens for the stats panel.
		add_action( 'wp_ajax_' . self::OPEN_ACTION, array( $this, 'ajax_track_open' ) );
		add_action( 'wp_ajax_nopriv_' . self::OPEN_ACTION, array( $this, 'ajax_track_open' ) );
	}

	/**
	 * Initialize front hooks (only on non-ajax front requests).
	 *
	 * @param object $joinchat JoinChat object.
	 * @return void
	 */
	public function init( $joinchat ) {

		$loader = $joinchat->get_loader();

		// Same hooks in every version, only the prefix changes (whatsappme_ in 3.x).
		$loader->add_filter( JoinChatRandomPhoneCompat::hook( 'extra_settings' ), $this, 'extra_settings' );
		$loader->add_filter( 'option_' . JoinChatRandomPhoneCompat::option(), $this, 'fix_phones' );
		$loader->add_filter( JoinChatRandomPhoneCompat::hook( 'get_settings' ), $this, 'get_settings', 15, 2 );

		// Enqueue late (priority 20) so Joinchat script is already enqueued.
		$loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_scripts', 20 );

		// Second chance for themes/builders that enqueue Joinchat late. Footer
		// scripts are printed at wp_footer 20, so priority 5 is still in time.
		$loader->add_action( 'wp_footer', $this, 'enqueue_scripts', 5 );
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

		// If the current post/term/author has its own custom phone but no random
		// list, don't apply the site random phones on it (terms since Joinchat
		// 4.3, authors since 6.4 merge their settings over the site ones too).
		$obj_settings = $this->object_settings( $obj );
		if ( is_array( $obj_settings ) && ! empty( $obj_settings['telephone'] ) && ! isset( $obj_settings['random_phones'] ) ) {
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

			// telephone stays as the first valid phone. It is the fallback: what
			// the server reports, what is painted before the visitor's own number
			// arrives, and what is used if the assignment never arrives. Once the
			// number is assigned the front end (including the QR) uses that one.
			$settings['telephone'] = $list[0];

			// Persist the list so the AJAX endpoint can (re)build the bag.
			$this->maybe_store_list( $this->signature, $list );
		}

		// Support Agents addon: keep the original per-browser behaviour (array).
		$settings = $this->merge_agents_phones( $settings );

		return $settings;
	}

	/**
	 * Joinchat settings saved on the queried post, term or author.
	 *
	 * @param mixed $obj Queried object.
	 * @return mixed
	 */
	private function object_settings( $obj ) {

		$key = JoinChatRandomPhoneCompat::meta_key();

		if ( $obj instanceof WP_Post ) {
			return get_post_meta( $obj->ID, $key, true );
		}
		if ( $obj instanceof WP_Term && function_exists( 'get_term_meta' ) ) {
			return get_term_meta( $obj->term_id, $key, true );
		}
		if ( $obj instanceof WP_User ) {
			return get_user_meta( $obj->ID, $key, true );
		}

		return '';
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

		if ( ! $this->has_random || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		// Joinchat loads its full script when the floating button is shown, and
		// a "lite" one on pages that only have WhatsApp buttons/links in the
		// content. Both build the WhatsApp link the same way, so both need us:
		// without this, every contact made from a lite page went to the first
		// phone.
		$dep = '';
		foreach ( JoinChatRandomPhoneCompat::public_handles() as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
				$dep = $handle;
				break;
			}
		}

		if ( '' === $dep ) {
			return; // No Joinchat on this page (yet: retried on wp_footer).
		}

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			self::HANDLE,
			plugin_dir_url( __FILE__ ) . "js/{$this->plugin_name}{$min}.js",
			array( $dep ),
			$this->version,
			true
		);

		$config = array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::AJAX_ACTION,
			'nonce'  => wp_create_nonce( self::AJAX_ACTION ),
		);

		// Global list only (Agents phones stay client side).
		if ( '' !== $this->signature ) {
			$config['signature'] = $this->signature;
			// Used by the visitor side only as a fail-safe: if the server can't
			// be reached, a random phone of this list is used (never always the
			// first). They are already public: they end up in the wa.me links.
			$config['phones'] = $this->list;
			$config['def']    = $this->list[0];

			// Count WhatsApp opens for the stats panel.
			if ( JoinChatRandomPhoneStats::enabled() ) {
				$config['track'] = self::OPEN_ACTION;
			}
		}

		wp_localize_script( self::HANDLE, 'joinchat_random_phone', $config );
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

		$list = $this->resolve_list( $signature );
		if ( ! $list ) {
			wp_send_json_error( array( 'message' => 'unknown_signature' ) );
		}

		// Per-browser token: makes the call idempotent, so the request fired on
		// touch and the one fired on click get the SAME phone and only one slot
		// of the bag is spent.
		$token = isset( $_REQUEST['token'] ) ? sanitize_key( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( ! preg_match( '/^[a-z0-9]{16,64}$/', $token ) ) {
			$token = '';
		}

		$phone = $this->pop_from_bag( $signature, $list, $token );

		// Count the new visitor (a repeated request of the same visitor isn't).
		// After the lock is released, and it can never fail the answer.
		if ( $this->popped && '' !== $phone ) {
			JoinChatRandomPhoneStats::record( array( array( 'assign', $phone ) ) );
		}

		wp_send_json_success( array( 'phone' => $phone ) );
	}

	/**
	 * AJAX handler: count a WhatsApp open (sent as a beacon by the visitor).
	 *
	 * Only phones of the list are counted, so nobody can fill the stats with
	 * made up numbers. Nothing about the visitor is stored.
	 *
	 * @return void
	 */
	public function ajax_track_open() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public counter, cached pages carry stale nonces.
		$signature = isset( $_REQUEST['signature'] ) ? sanitize_key( wp_unslash( $_REQUEST['signature'] ) ) : '';
		$phone     = isset( $_REQUEST['phone'] ) ? $this->clean_phone( sanitize_text_field( wp_unslash( $_REQUEST['phone'] ) ) ) : '';
		$token     = isset( $_REQUEST['token'] ) ? sanitize_key( wp_unslash( $_REQUEST['token'] ) ) : '';
		$flag      = function ( $key ) {
			return isset( $_REQUEST[ $key ] ) && '1' === $_REQUEST[ $key ];
		};
		$trigger   = isset( $_REQUEST['src'] ) ? sanitize_key( wp_unslash( $_REQUEST['src'] ) ) : '';
		$page      = isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '';
		$agent     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		// phpcs:enable

		$list = $this->resolve_list( $signature );

		if ( ! JoinChatRandomPhoneStats::enabled() || ! $list || ! in_array( $phone, $list, true )
			|| ! preg_match( '/^[a-z0-9]{16,64}$/', $token )
			|| preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|preview/i', $agent ) ) {
			wp_send_json_error( null, 400 );
		}

		$pairs = array(
			array( 'open', $phone ),
			array( 'src', JoinChatRandomPhoneStats::source( $trigger ) ),
			array( 'page', JoinChatRandomPhoneStats::page_path( $page ) ),
		);
		if ( $flag( 'm' ) ) {
			$pairs[] = array( 'mobile', $phone );
		}
		if ( $flag( 'first' ) ) {
			$pairs[] = array( 'first', $phone );
		}
		if ( $flag( 'local' ) ) {
			$pairs[] = array( 'local', $phone );
		}

		JoinChatRandomPhoneStats::record( $pairs );

		wp_send_json_success();
	}

	/**
	 * Phone list of a signature (rebuilt from the site settings if it was lost).
	 *
	 * @param string $signature List signature.
	 * @return array Empty if unknown.
	 */
	private function resolve_list( $signature ) {

		if ( empty( $signature ) ) {
			return array();
		}

		$lists = get_option( self::OPT_LISTS, array() );
		if ( ! is_array( $lists ) ) {
			$lists = array();
		}

		// Unknown signature (lists option lost/pruned while a cached page still
		// points to it): rebuild it from the current site settings if it matches.
		if ( ! isset( $lists[ $signature ]['list'] ) ) {
			$site = $this->site_list();
			if ( count( $site ) > 1 && substr( md5( wp_json_encode( $site ) ), 0, 16 ) === $signature ) {
				$this->maybe_store_list( $signature, $site );
				return $site;
			}
			return array();
		}

		$list = $lists[ $signature ]['list'];

		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Pop the next phone from the global bag for a signature (atomic).
	 *
	 * If the browser token already got a phone for this list, that same phone is
	 * returned and nothing is popped.
	 *
	 * @param string $signature List signature.
	 * @param array  $list      Full phone list.
	 * @param string $token     Browser token ('' = none, always pop).
	 * @return string
	 */
	private function pop_from_bag( $signature, $list, $token = '' ) {

		global $wpdb;

		$this->popped = false;

		$lock_name = 'jcrp_' . substr( $signature, 0, 20 );
		$locked    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 3 ) );

		// Already assigned to this browser (checked inside the lock, so two
		// simultaneous requests of the same visitor can't both pop).
		$token_key = '' !== $token ? 'jcrp_' . md5( $signature . '|' . $token ) : '';
		if ( '' !== $token_key ) {
			$prev = get_transient( $token_key );
			if ( is_string( $prev ) && in_array( $prev, $list, true ) ) {
				if ( 1 === $locked ) {
					$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
				}
				return $prev;
			}
		}

		// Read the bags map straight from the DB (bypass any object cache).
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPT_BAGS ) );
		$bags = $raw ? maybe_unserialize( $raw ) : array();
		if ( ! is_array( $bags ) ) {
			$bags = array();
		}

		$queue = isset( $bags[ $signature ] ) && is_array( $bags[ $signature ] ) ? $bags[ $signature ] : array();

		// Only phones of the list (a damaged bag can't hand out anything else).
		$queue = array_values( array_intersect( $queue, $list ) );

		// Refill + shuffle when the bag is empty.
		if ( empty( $queue ) ) {
			$queue = $list;
			shuffle( $queue );
		}

		$phone = array_shift( $queue );

		$this->popped = ! empty( $phone );

		$bags[ $signature ] = $queue;
		$this->save_bags( $bags );

		if ( '' !== $token_key && ! empty( $phone ) ) {
			set_transient( $token_key, $phone, DAY_IN_SECONDS );
		}

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

		// A corrupt option must never break the page: start over.
		if ( ! is_array( $lists ) ) {
			$lists = array();
		}
		$lists = array_filter(
			$lists,
			function ( $item ) {
				return is_array( $item ) && isset( $item['list'] ) && is_array( $item['list'] );
			}
		);

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
	 * Cleaned site phone list straight from the Joinchat option.
	 *
	 * @return array
	 */
	public function site_list() {

		$option = $this->fix_phones( (array) get_option( JoinChatRandomPhoneCompat::option(), array() ) );
		$list   = array();

		$phones = array_merge(
			array( isset( $option['telephone'] ) ? $option['telephone'] : '' ),
			isset( $option['random_phones'] ) && is_array( $option['random_phones'] ) ? $option['random_phones'] : array()
		);

		foreach ( $phones as $phone ) {
			$phone = $this->clean_phone( is_scalar( $phone ) ? $phone : '' );
			if ( '' !== $phone ) {
				$list[] = $phone;
			}
		}

		return $list;
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
