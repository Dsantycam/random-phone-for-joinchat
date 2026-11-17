<?php
/**
 * "Contact split" panel: how the contacts have been shared among the phones.
 *
 * Read only: it never touches the shuffle-bag or the phone list. Everything is
 * computed from the daily counters of JoinChatRandomPhoneStats.
 *
 * @since      4.5.0
 * @package    JoinChatRandomPhone
 */

defined( 'WPINC' ) || exit;

/**
 * Dashboard class.
 */
class JoinChatRandomPhoneDashboard {

	const PAGE   = 'joinchat-random-phone-stats';
	const HANDLE = 'random-phone-for-joinchat-stats';
	const RANGES = array( '1', '7', '30', '90', '365' );

	/**
	 * Public class (owns the phone list helpers).
	 *
	 * @var JoinChatRandomPhonePublic
	 */
	private $public;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hook everything.
	 *
	 * @param JoinChatRandomPhonePublic $public  Public class.
	 * @param string                    $version Plugin version.
	 */
	public function __construct( $public, $version ) {

		$this->public  = $public;
		$this->version = $version;

		// After Joinchat (10) so its top level menu already exists.
		add_action( 'admin_menu', array( $this, 'menu' ), 99 );
		// After Joinchat registers intl-tel-input (used to print the phones).
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 20 );
		add_action( 'admin_post_jcrp_stats_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_jcrp_stats_reset', array( $this, 'reset' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JOINCHAT_RANDOM_PHONE_FILE ), array( $this, 'action_link' ) );

	}

	/**
	 * Capability needed: the same one that manages Joinchat (roles page aware).
	 *
	 * @return string
	 */
	public static function capability() {

		if ( JoinChatRandomPhoneCompat::has_capability_filter() ) {
			$cap = apply_filters( 'joinchat_capability', 'manage_options' );
			return is_string( $cap ) && '' !== $cap ? $cap : 'manage_options';
		}

		return 'manage_options';
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public function url( $args = array() ) {

		$parent = $this->parent();
		$base   = 'options-general.php' === $parent ? 'options-general.php' : 'admin.php';

		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( $base ) );
	}

	/**
	 * Menu parent: the Joinchat top level menu when there is one, Settings if not.
	 *
	 * @return string
	 */
	private function parent() {
		return isset( $GLOBALS['admin_page_hooks']['joinchat'] ) ? 'joinchat' : 'options-general.php';
	}

	/**
	 * Register the page.
	 *
	 * @return void
	 */
	public function menu() {

		$parent = $this->parent();
		$cap    = self::capability();

		// A top level menu with sub items needs its own page as first item, or
		// the Joinchat settings would lose their entry in the menu.
		if ( 'joinchat' === $parent ) {
			$listed = isset( $GLOBALS['submenu']['joinchat'] ) ? wp_list_pluck( $GLOBALS['submenu']['joinchat'], 2 ) : array();
			if ( ! in_array( 'joinchat', $listed, true ) ) {
				add_submenu_page( 'joinchat', 'Joinchat', __( 'Settings', 'joinchat-random-phone' ), $cap, 'joinchat' );
			}
		}

		$this->hook = (string) add_submenu_page(
			$parent,
			__( 'Contact split', 'joinchat-random-phone' ),
			'joinchat' === $parent ? __( 'Contact split', 'joinchat-random-phone' ) : __( 'Joinchat contact split', 'joinchat-random-phone' ),
			$cap,
			self::PAGE,
			array( $this, 'render' )
		);

		if ( $this->hook ) {
			add_action( 'load-' . $this->hook, array( 'JoinChatRandomPhoneStats', 'maybe_install' ) );
			add_action( 'load-' . $this->hook, array( 'JoinChatRandomPhoneStats', 'prune' ) );
		}
	}

	/**
	 * "Stats" link in the plugins list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_link( $links ) {

		if ( current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Stats', 'joinchat-random-phone' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * Styles and scripts, only on our page.
	 *
	 * @param string $hook Current page hook.
	 * @return void
	 */
	public function assets( $hook ) {

		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// intl-tel-input (when Joinchat has it) only to print the phones nicely.
		$deps = JoinChatRandomPhoneCompat::registered( array( 'joinchat-iti-core', 'intl-tel-input' ) );

		wp_enqueue_style( self::HANDLE, plugin_dir_url( __FILE__ ) . "css/joinchat-random-phone-stats{$min}.css", array( 'dashicons' ), $this->version );
		wp_enqueue_script( self::HANDLE, plugin_dir_url( __FILE__ ) . "js/joinchat-random-phone-stats{$min}.js", array_slice( $deps, 0, 1 ), $this->version, true );
	}

	// ------------------------------------------------------------------ data.

	/**
	 * Selected date range.
	 *
	 * @return array { from, to, days, range, prev_from, prev_to }
	 */
	public function period() {

		$today = JoinChatRandomPhoneStats::today();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only filters.
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30';
		$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable

		if ( 'custom' === $range && self::is_date( $from ) && self::is_date( $to ) ) {
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
			$to   = min( $to, $today );
			$from = min( $from, $to );
			// Never more than the data we keep.
			$from = max( $from, self::shift( $to, -JoinChatRandomPhoneStats::KEEP_DAYS ) );
		} else {
			$range = in_array( $range, self::RANGES, true ) ? $range : '30';
			$to    = $today;
			$from  = self::shift( $today, 1 - (int) $range );
		}

		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;

		return array(
			'range'     => $range,
			'from'      => $from,
			'to'        => $to,
			'days'      => $days,
			'prev_to'   => self::shift( $from, -1 ),
			'prev_from' => self::shift( $from, -$days ),
		);
	}

	/**
	 * Valid Y-m-d date?
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Add days to a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @param int    $days Days (negative goes back).
	 * @return string
	 */
	private static function shift( $date, $days ) {
		$d = new DateTime( $date );
		$d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/**
	 * Everything the page shows, for a period.
	 *
	 * @param array $p Period.
	 * @return array
	 */
	public function data( $p ) {

		$t    = JoinChatRandomPhoneStats::totals( $p['from'], $p['to'] );
		$prev = JoinChatRandomPhoneStats::totals( $p['prev_from'], $p['prev_to'] );

		$list  = $this->public->site_list();
		$slots = array_count_values( $list );
		$size  = count( $list );

		$assigned = array_sum( $t['assign'] );

		// Current phones (in list order) first, then any phone that is not on the
		// list any more but has data in the period.
		$phones = array_keys( $slots );
		$others = array_diff( array_keys( $t['assign'] + $t['open'] ), $phones );
		usort(
			$others,
			function ( $a, $b ) use ( $t ) {
				return ( isset( $t['assign'][ $b ] ) ? $t['assign'][ $b ] : 0 ) - ( isset( $t['assign'][ $a ] ) ? $t['assign'][ $a ] : 0 );
			}
		);

		$get  = function ( $kind, $phone ) use ( $t ) {
			return isset( $t[ $kind ][ $phone ] ) ? (int) $t[ $kind ][ $phone ] : 0;
		};
		$rows = array();
		$gap  = 0;

		foreach ( array_merge( $phones, $others ) as $phone ) {
			$phone   = (string) $phone;
			$current = isset( $slots[ $phone ] );
			$share   = $current && $size ? $slots[ $phone ] / $size : 0;
			$count   = $get( 'assign', $phone );
			$diff    = $current ? $count - $assigned * $share : 0;

			if ( $current ) {
				$gap = max( $gap, abs( $diff ) );
			}

			$rows[] = array(
				'phone'    => $phone,
				'current'  => $current,
				'slots'    => $current ? $slots[ $phone ] : 0,
				'expected' => $share,
				'assigned' => $count,
				'share'    => $assigned ? $count / $assigned : 0,
				'diff'     => $diff,
				'opens'    => $get( 'open', $phone ),
				'first'    => $get( 'first', $phone ),
				'mobile'   => $get( 'mobile', $phone ),
				'local'    => $get( 'local', $phone ),
			);
		}

		$opens = array_sum( $t['open'] );
		$first = array_sum( $t['first'] );

		arsort( $t['src'] );
		arsort( $t['page'] );

		return array(
			'rows'        => $rows,
			'list_size'   => $size,
			'unique'      => count( $slots ),
			'assigned'    => $assigned,
			'opens'       => $opens,
			'first'       => $first,
			'mobile'      => array_sum( $t['mobile'] ),
			'local'       => array_sum( $t['local'] ),
			'gap'         => $gap,
			'prev'        => array(
				'assigned' => array_sum( $prev['assign'] ),
				'opens'    => array_sum( $prev['open'] ),
				'first'    => array_sum( $prev['first'] ),
			),
			'sources'     => $t['src'],
			'pages'       => array_slice( $t['page'], 0, 8, true ),
			'daily'       => JoinChatRandomPhoneStats::daily( $p['from'], $p['to'] ),
			'round'       => $this->round( $list ),
			'first_day'   => JoinChatRandomPhoneStats::first_day(),
		);
	}

	/**
	 * State of the current round of the shuffle-bag for the site list.
	 *
	 * @param array $list Site phone list.
	 * @return array { size, pending (phone => slots left), started }
	 */
	private function round( $list ) {

		$size = count( $list );
		$out  = array(
			'size'    => $size,
			'left'    => $size,
			'pending' => array_count_values( $list ),
			'started' => false,
		);

		if ( $size < 2 ) {
			return $out;
		}

		$signature = substr( md5( wp_json_encode( $list ) ), 0, 16 );
		$bags      = get_option( JoinChatRandomPhonePublic::OPT_BAGS, array() );

		if ( is_array( $bags ) && isset( $bags[ $signature ] ) && is_array( $bags[ $signature ] ) ) {
			// Same rule as the bag itself: only phones of the list count.
			$queue = array_values( array_intersect( $bags[ $signature ], $list ) );

			// An empty bag is refilled by the next visitor: a new round.
			if ( $queue ) {
				$out['left']    = count( $queue );
				$out['pending'] = array_count_values( $queue );
				$out['started'] = true;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------- actions.

	/**
	 * Download the daily counters of the selected period as CSV.
	 *
	 * @return void
	 */
	public function export_csv() {

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'joinchat-random-phone' ), 403 );
		}

		check_admin_referer( 'jcrp_stats_csv' );

		$p    = $this->period();
		$data = array();

		foreach ( JoinChatRandomPhoneStats::rows( $p['from'], $p['to'] ) as $row ) {
			if ( in_array( $row['kind'], array( 'src', 'page' ), true ) ) {
				continue;
			}
			$key = $row['day'] . '|' . $row['item'];
			if ( ! isset( $data[ $key ] ) ) {
				$data[ $key ] = array_fill_keys( array( 'assign', 'open', 'first', 'mobile', 'local' ), 0 );
			}
			$data[ $key ][ $row['kind'] ] = (int) $row['hits'];
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reparto-joinchat-' . $p['from'] . '-' . $p['to'] . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel opens the accents right.

		$sep = ';'; // What Excel expects with Spanish (and most European) locales.
		fputcsv(
			$out,
			array(
				__( 'Date', 'joinchat-random-phone' ),
				__( 'Phone', 'joinchat-random-phone' ),
				__( 'Visitors assigned', 'joinchat-random-phone' ),
				__( 'WhatsApp opens', 'joinchat-random-phone' ),
				__( 'Visitors who reached out', 'joinchat-random-phone' ),
				__( 'Opens from mobile', 'joinchat-random-phone' ),
				__( 'Assigned by the browser (backup)', 'joinchat-random-phone' ),
			),
			$sep
		);

		foreach ( $data as $key => $row ) {
			list( $day, $phone ) = explode( '|', $key, 2 );
			fputcsv( $out, array( $day, '+' . $phone, $row['assign'], $row['open'], $row['first'], $row['mobile'], $row['local'] ), $sep );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Delete every statistic (never the phones or the bag).
	 *
	 * @return void
	 */
	public function reset() {

		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'joinchat-random-phone' ), 403 );
		}

		check_admin_referer( 'jcrp_stats_reset' );

		JoinChatRandomPhoneStats::reset();

		wp_safe_redirect( $this->url( array( 'jcrp_reset' => 1 ) ) );
		exit;
	}

	// -------------------------------------------------------------- render.

	/**
	 * Formatted integer.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		return number_format_i18n( (int) $n );
	}

	/**
	 * Formatted percentage of a ratio.
	 *
	 * @param float $ratio 0..1.
	 * @param int   $dec   Decimals.
	 * @return string
	 */
	private static function pct( $ratio, $dec = 0 ) {
		return number_format_i18n( $ratio * 100, $dec ) . '%';
	}

	/**
	 * Change against the previous period, as a small badge.
	 *
	 * @param int  $now     This period.
	 * @param int  $prev    Previous period.
	 * @param bool $covered There is data for the whole previous period.
	 * @return string HTML.
	 */
	private static function delta( $now, $prev, $covered = true ) {

		// No comparison against a period the statistics only partly cover (the
		// first days after installing): it would show meaningless jumps.
		if ( ! $covered || ! $prev ) {
			return $now ? '<span class="jcrp-delta">' . esc_html__( 'no previous data', 'joinchat-random-phone' ) . '</span>' : '';
		}

		$change = ( $now - $prev ) / $prev;
		$dir    = abs( $change ) < 0.005 ? 'flat' : ( $change > 0 ? 'up' : 'down' );
		$sign   = 'up' === $dir ? '+' : ( 'down' === $dir ? '−' : '' );

		return sprintf(
			'<span class="jcrp-delta jcrp-delta--%1$s" title="%2$s">%3$s%4$s</span><span class="jcrp-delta__vs">%5$s</span>',
			esc_attr( $dir ),
			/* translators: %s: value of the previous period. */
			esc_attr( sprintf( __( 'Previous period: %s', 'joinchat-random-phone' ), self::num( $prev ) ) ),
			esc_html( $sign ),
			esc_html( number_format_i18n( abs( $change ) * 100, 0 ) . '%' ),
			esc_html__( 'vs. previous period', 'joinchat-random-phone' )
		);
	}

	/**
	 * Status line with icon (never color alone).
	 *
	 * @param string $state good|warn|neutral.
	 * @param string $text  Label.
	 * @return string HTML.
	 */
	private static function status( $state, $text ) {

		$icons = array(
			'good'    => 'dashicons-yes-alt',
			'warn'    => 'dashicons-warning',
			'neutral' => 'dashicons-clock',
		);

		return sprintf(
			'<span class="jcrp-status jcrp-status--%1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $state ),
			esc_attr( $icons[ $state ] ),
			esc_html( $text )
		);
	}

	/**
	 * Phone markup (the script formats it when intl-tel-input is around).
	 *
	 * @param string $phone Digits.
	 * @return string HTML.
	 */
	private static function phone( $phone ) {
		return '<span class="jcrp-phone" data-phone="' . esc_attr( $phone ) . '">+' . esc_html( $phone ) . '</span>';
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {

		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$p    = $this->period();
		$d    = $this->data( $p );
		$on   = JoinChatRandomPhoneStats::enabled();
		$db   = JoinChatRandomPhoneStats::maybe_install();
		$date = function ( $ymd, $format = 'j M Y' ) {
			return date_i18n( $format, strtotime( $ymd . ' 12:00:00' ) );
		};

		$ranges = array(
			'1'   => __( 'Today', 'joinchat-random-phone' ),
			'7'   => __( '7 days', 'joinchat-random-phone' ),
			'30'  => __( '30 days', 'joinchat-random-phone' ),
			'90'  => __( '90 days', 'joinchat-random-phone' ),
			'365' => __( '12 months', 'joinchat-random-phone' ),
		);

		$period_label = $p['from'] === $p['to']
			? $date( $p['from'] )
			: $date( $p['from'] ) . ' – ' . $date( $p['to'] );

		// The previous period can only be compared if it has data from its first day.
		$covered = '' !== $d['first_day'] && $d['first_day'] <= $p['prev_from'];

		// Contact rate: visitors who reached out / visitors assigned.
		$rate = $d['assigned'] ? min( 1, $d['first'] / $d['assigned'] ) : 0;

		// Balance: biggest difference (in visitors) between what a phone got and
		// what its slots entitle it to. The shuffle-bag keeps it at about one.
		$current = count( array_filter( wp_list_pluck( $d['rows'], 'current' ) ) );
		$tol     = max( 2, 0.1 * ( $current ? $d['assigned'] / $current : 0 ) );

		$csv_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'jcrp_stats_csv',
					'range'  => $p['range'],
					'from'   => $p['from'],
					'to'     => $p['to'],
				),
				admin_url( 'admin-post.php' )
			),
			'jcrp_stats_csv'
		);

		$chart = array(
			'locale' => str_replace( '_', '-', get_user_locale() ),
			'days'   => array(),
			'labels' => array(
				'assign' => __( 'Visitors assigned', 'joinchat-random-phone' ),
				'open'   => __( 'WhatsApp opens', 'joinchat-random-phone' ),
			),
		);
		foreach ( $d['daily'] as $day => $v ) {
			$chart['days'][] = array( $day, $v['assign'], $v['open'] );
		}

		echo '<div class="wrap jcrp-stats">';

		// ---- Header.
		echo '<div class="jcrp-head">';
		echo '<div class="jcrp-head__title"><h1>' . esc_html__( 'Contact split', 'joinchat-random-phone' ) . '</h1>';
		echo '<p class="jcrp-head__sub">' . esc_html( $period_label );
		if ( $d['first_day'] ) {
			/* translators: %s: date. */
			echo ' <span class="jcrp-sep" aria-hidden="true">·</span> ' . esc_html( sprintf( __( 'Data since %s', 'joinchat-random-phone' ), $date( $d['first_day'] ) ) );
		}
		echo '</p></div>';

		echo '<div class="jcrp-head__tools">';
		echo '<nav class="jcrp-range" aria-label="' . esc_attr__( 'Period', 'joinchat-random-phone' ) . '">';
		foreach ( $ranges as $key => $label ) {
			$current_range = (string) $key === $p['range'];
			printf(
				'<a href="%1$s" class="jcrp-range__item%2$s"%3$s>%4$s</a>',
				esc_url( $this->url( array( 'range' => $key ) ) ),
				$current_range ? ' is-active' : '',
				$current_range ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		printf(
			'<button type="button" class="jcrp-range__item%1$s" aria-expanded="%2$s" aria-controls="jcrp-custom">%3$s</button>',
			'custom' === $p['range'] ? ' is-active' : '',
			'custom' === $p['range'] ? 'true' : 'false',
			esc_html__( 'Custom', 'joinchat-random-phone' )
		);
		echo '</nav>';
		echo '<a class="button jcrp-export" href="' . esc_url( $csv_url ) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'joinchat-random-phone' ) . '</a>';
		echo '</div></div>';

		// Custom range form.
		printf(
			'<form id="jcrp-custom" class="jcrp-custom" method="get" action="%1$s"%2$s>',
			esc_url( admin_url( 'options-general.php' === $this->parent() ? 'options-general.php' : 'admin.php' ) ),
			'custom' === $p['range'] ? '' : ' hidden'
		);
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="range" value="custom">';
		printf(
			'<label>%1$s <input type="date" name="from" value="%2$s" max="%4$s" required></label><label>%3$s <input type="date" name="to" value="%5$s" max="%4$s" required></label>',
			esc_html__( 'From', 'joinchat-random-phone' ),
			esc_attr( $p['from'] ),
			esc_html__( 'To', 'joinchat-random-phone' ),
			esc_attr( JoinChatRandomPhoneStats::today() ),
			esc_attr( $p['to'] )
		);
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply', 'joinchat-random-phone' ) . '</button></form>';

		// ---- Notices.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['jcrp_reset'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statistics deleted. The phone list and the current round were not touched.', 'joinchat-random-phone' ) . '</p></div>';
		}
		if ( ! $on ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Statistics are switched off on this site (joinchat_random_phone_stats filter). The split keeps working as usual.', 'joinchat-random-phone' ) . '</p></div>';
		} elseif ( ! $db ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The statistics table could not be created (the database user may lack the CREATE permission). The split keeps working as usual; it will be tried again in an hour.', 'joinchat-random-phone' ) . '</p></div>';
		}
		if ( $d['list_size'] < 2 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'There is only one phone in the Joinchat settings, so there is nothing to split. Add more phones to start sharing the contacts.', 'joinchat-random-phone' ) . '</p></div>';
		}

		// Health: visitors whose phone had to be picked by their own browser.
		if ( $d['local'] >= 3 && $d['first'] && $d['local'] / $d['first'] > 0.05 ) {
			echo '<div class="jcrp-alert" role="status"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' .
				/* translators: %s: percentage. */
				esc_html( sprintf( __( 'The server did not answer for %s of the visitors who reached out.', 'joinchat-random-phone' ), self::pct( $d['local'] / $d['first'] ) ) ) .
				'</strong> ' .
				esc_html__( 'They still got a random phone of the list, but the split is less even. A cache, firewall or security plugin blocking admin-ajax.php is the usual cause.', 'joinchat-random-phone' ) .
				'</div></div>';
		}

		// ---- KPIs.
		echo '<div class="jcrp-kpis">';

		$this->kpi(
			__( 'Visitors assigned', 'joinchat-random-phone' ),
			self::num( $d['assigned'] ),
			self::delta( $d['assigned'], $d['prev']['assigned'], $covered ),
			__( 'New visitors who got a phone. A returning visitor keeps theirs and is not counted again.', 'joinchat-random-phone' )
		);
		$this->kpi(
			__( 'WhatsApp opens', 'joinchat-random-phone' ),
			self::num( $d['opens'] ),
			self::delta( $d['opens'], $d['prev']['opens'], $covered ),
			__( 'Times WhatsApp was opened towards one of the phones of the list.', 'joinchat-random-phone' )
		);
		$this->kpi(
			__( 'Contact rate', 'joinchat-random-phone' ),
			$d['assigned'] ? self::pct( $rate ) : '—',
			$d['assigned']
				/* translators: %1$s: visitors who opened WhatsApp, %2$s: visitors assigned. */
				? '<span class="jcrp-delta">' . esc_html( sprintf( __( '%1$s of %2$s', 'joinchat-random-phone' ), self::num( min( $d['first'], $d['assigned'] ) ), self::num( $d['assigned'] ) ) ) . '</span>'
				: '',
			__( 'Assigned visitors who went on to open WhatsApp. Whether they actually sent the message happens inside WhatsApp and cannot be known.', 'joinchat-random-phone' )
		);

		if ( ! $d['assigned'] || $current < 2 ) {
			$balance = self::status( 'neutral', __( 'Waiting for visitors', 'joinchat-random-phone' ) );
			$value   = '—';
		} else {
			$gap     = (int) round( $d['gap'] );
			$value   = '±' . self::num( $gap );
			$balance = $gap <= 1
				? self::status( 'good', __( 'Exact split', 'joinchat-random-phone' ) )
				: ( $d['gap'] <= $tol ? self::status( 'good', __( 'Even split', 'joinchat-random-phone' ) ) : self::status( 'warn', __( 'Uneven split', 'joinchat-random-phone' ) ) );
		}
		$this->kpi(
			__( 'Balance', 'joinchat-random-phone' ),
			$value,
			$balance,
			__( 'Biggest difference, in visitors, between what a phone received and what its slots entitle it to. Editing the list during the period can widen it.', 'joinchat-random-phone' )
		);

		echo '</div>';

		// ---- Daily chart.
		echo '<section class="jcrp-card">';
		echo '<header class="jcrp-card__head"><h2>' . esc_html__( 'Daily activity', 'joinchat-random-phone' ) . '</h2>';
		echo '<ul class="jcrp-legend"><li><span class="jcrp-key jcrp-key--assign" aria-hidden="true"></span>' . esc_html( $chart['labels']['assign'] ) . '</li><li><span class="jcrp-key jcrp-key--open" aria-hidden="true"></span>' . esc_html( $chart['labels']['open'] ) . '</li></ul></header>';

		if ( $p['days'] < 2 ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'Choose a period of two days or more to see the daily evolution.', 'joinchat-random-phone' ) . '</p>';
		} elseif ( ! $d['assigned'] && ! $d['opens'] ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'No activity in this period yet. Every new visitor who reaches for WhatsApp will show up here.', 'joinchat-random-phone' ) . '</p>';
		} else {
			echo '<div class="jcrp-chart" data-chart="' . esc_attr( wp_json_encode( $chart ) ) . '" role="img" aria-label="' . esc_attr__( 'Visitors assigned and WhatsApp opens per day', 'joinchat-random-phone' ) . '"></div>';
		}
		echo '</section>';

		// ---- Per phone table.
		$this->render_table( $d );

		// ---- Bottom cards.
		echo '<div class="jcrp-grid">';
		$this->render_round( $d['round'] );
		$this->render_sources( $d );
		$this->render_pages( $d['pages'] );
		echo '</div>';

		// ---- Footer.
		echo '<footer class="jcrp-foot">';
		echo '<p>' . esc_html__( 'Only daily totals per phone are stored: nothing about the visitors (no IP, no phone number, no cookies). Data older than 13 months is deleted automatically.', 'joinchat-random-phone' ) . '</p>';
		printf(
			'<form method="post" action="%1$s" class="jcrp-reset" data-confirm="%2$s"><input type="hidden" name="action" value="jcrp_stats_reset">%3$s<button type="submit" class="button-link button-link-delete">%4$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr__( 'Delete all the statistics? The phone list and the split are not affected. This cannot be undone.', 'joinchat-random-phone' ),
			wp_nonce_field( 'jcrp_stats_reset', '_wpnonce', true, false ),
			esc_html__( 'Delete statistics', 'joinchat-random-phone' )
		);
		echo '</footer>';

		echo '</div>';
	}

	/**
	 * One KPI tile.
	 *
	 * @param string $label Label.
	 * @param string $value Value (plain text).
	 * @param string $meta  Extra line (HTML, already escaped).
	 * @param string $help  Explanation.
	 * @return void
	 */
	private function kpi( $label, $value, $meta, $help ) {

		echo '<div class="jcrp-kpi">';
		echo '<div class="jcrp-kpi__label">' . esc_html( $label ) . '<span class="jcrp-help" tabindex="0" role="note" aria-label="' . esc_attr( $help ) . '" data-tip="' . esc_attr( $help ) . '"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span></span></div>';
		echo '<div class="jcrp-kpi__value">' . esc_html( $value ) . '</div>';
		echo '<div class="jcrp-kpi__meta">' . $meta . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built escaped.
		echo '</div>';
	}

	/**
	 * Per phone table.
	 *
	 * @param array $d Data.
	 * @return void
	 */
	private function render_table( $d ) {

		echo '<section class="jcrp-card">';
		echo '<header class="jcrp-card__head"><h2>' . esc_html__( 'Split by phone', 'joinchat-random-phone' ) . '</h2>';
		printf(
			'<p class="jcrp-card__note">%s</p>',
			esc_html(
				sprintf(
					/* translators: %1$s: phones, %2$s: slots. */
					_n( '%1$s phone in the list', '%1$s phones in the list', $d['unique'], 'joinchat-random-phone' ),
					self::num( $d['unique'] )
				) . ( $d['list_size'] !== $d['unique']
					/* translators: %s: slots per round. */
					? ' · ' . sprintf( __( '%s slots per round', 'joinchat-random-phone' ), self::num( $d['list_size'] ) )
					: '' )
			)
		);
		echo '</header>';

		if ( ! $d['rows'] ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'No phones yet.', 'joinchat-random-phone' ) . '</p></section>';
			return;
		}

		$max = 0;
		foreach ( $d['rows'] as $r ) {
			$max = max( $max, $r['share'], $r['expected'] );
		}
		$max = $max ? $max : 1;

		$cols = array(
			array( 'phone', __( 'Phone', 'joinchat-random-phone' ), 'text' ),
			array( 'assigned', __( 'Assigned', 'joinchat-random-phone' ), 'num' ),
			array( 'share', __( 'Share of the split', 'joinchat-random-phone' ), 'num' ),
			array( 'diff', __( 'Difference', 'joinchat-random-phone' ), 'num' ),
			array( 'opens', __( 'Opens', 'joinchat-random-phone' ), 'num' ),
			array( 'rate', __( 'Contact rate', 'joinchat-random-phone' ), 'num' ),
			array( 'mobile', __( 'Mobile', 'joinchat-random-phone' ), 'num' ),
		);

		echo '<div class="jcrp-table-wrap"><table class="jcrp-table" data-sortable><thead><tr>';
		foreach ( $cols as $c ) {
			printf(
				'<th scope="col" class="jcrp-col-%1$s%2$s" aria-sort="none"><button type="button" data-sort="%1$s" data-type="%3$s">%4$s<span class="jcrp-sort" aria-hidden="true"></span></button></th>',
				esc_attr( $c[0] ),
				'num' === $c[2] ? ' is-num' : '',
				esc_attr( $c[2] ),
				esc_html( $c[1] )
			);
		}
		echo '</tr></thead><tbody>';

		foreach ( $d['rows'] as $i => $r ) {

			$rate   = $r['assigned'] ? min( 1, $r['first'] / $r['assigned'] ) : null;
			$mobile = $r['opens'] ? $r['mobile'] / $r['opens'] : null;
			$diff   = (int) round( $r['diff'] );

			printf( '<tr class="%s" data-order="%d">', $r['current'] ? '' : 'is-retired', (int) $i );

			// Phone.
			echo '<td class="jcrp-col-phone" data-value="' . esc_attr( $r['phone'] ) . '">' . self::phone( $r['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ! $r['current'] ) {
				echo ' <span class="jcrp-tag">' . esc_html__( 'Not in the list', 'joinchat-random-phone' ) . '</span>';
			} elseif ( $r['slots'] > 1 ) {
				/* translators: %s: number of slots. */
				echo ' <span class="jcrp-tag jcrp-tag--info">' . esc_html( sprintf( _n( '%s slot', '%s slots', $r['slots'], 'joinchat-random-phone' ), self::num( $r['slots'] ) ) ) . '</span>';
			}
			if ( $r['current'] && $d['assigned'] && ! $r['assigned'] ) {
				echo ' <span class="jcrp-tag jcrp-tag--warn">' . esc_html__( 'No contacts', 'joinchat-random-phone' ) . '</span>';
			}
			echo '</td>';

			// Assigned.
			echo '<td class="is-num" data-value="' . esc_attr( $r['assigned'] ) . '">' . esc_html( self::num( $r['assigned'] ) ) . '</td>';

			// Share bar with the expected share as a marker.
			$tip = $r['current']
				/* translators: %1$s: real share, %2$s: expected share. */
				? sprintf( __( 'Received %1$s · expected %2$s', 'joinchat-random-phone' ), self::pct( $r['share'], 1 ), self::pct( $r['expected'], 1 ) )
				/* translators: %s: real share. */
				: sprintf( __( 'Received %s', 'joinchat-random-phone' ), self::pct( $r['share'], 1 ) );
			printf(
				'<td class="jcrp-col-share is-num" data-value="%1$s"><div class="jcrp-bar" title="%2$s"><span class="jcrp-bar__fill" style="width:%3$s%%"></span>%4$s</div><span class="jcrp-bar__pct">%5$s</span></td>',
				esc_attr( $r['share'] ),
				esc_attr( $tip ),
				esc_attr( round( $r['share'] / $max * 100, 2 ) ),
				$r['current'] ? '<span class="jcrp-bar__mark" style="left:' . esc_attr( round( $r['expected'] / $max * 100, 2 ) ) . '%"></span>' : '',
				esc_html( self::pct( $r['share'], 1 ) )
			);

			// Difference against the expected share.
			if ( $r['current'] && $d['assigned'] ) {
				$sign = $diff > 0 ? '+' : ( $diff < 0 ? '−' : '' );
				printf( '<td class="is-num jcrp-diff%1$s" data-value="%2$s">%3$s</td>', 0 === $diff ? ' is-zero' : '', esc_attr( $r['diff'] ), esc_html( $sign . self::num( abs( $diff ) ) ) );
			} else {
				echo '<td class="is-num jcrp-muted" data-value="0">—</td>';
			}

			echo '<td class="is-num" data-value="' . esc_attr( $r['opens'] ) . '">' . esc_html( self::num( $r['opens'] ) ) . '</td>';
			echo '<td class="is-num" data-value="' . esc_attr( null === $rate ? -1 : $rate ) . '">' . ( null === $rate ? '<span class="jcrp-muted">—</span>' : esc_html( self::pct( $rate ) ) ) . '</td>';
			echo '<td class="is-num" data-value="' . esc_attr( null === $mobile ? -1 : $mobile ) . '">' . ( null === $mobile ? '<span class="jcrp-muted">—</span>' : esc_html( self::pct( $mobile ) ) ) . '</td>';

			echo '</tr>';
		}

		echo '</tbody></table></div>';
		echo '<p class="jcrp-card__foot"><span class="jcrp-mark-key" aria-hidden="true"></span>' . esc_html__( 'The line on each bar is the share its slots entitle it to.', 'joinchat-random-phone' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Current round card.
	 *
	 * @param array $round Round state.
	 * @return void
	 */
	private function render_round( $round ) {

		echo '<section class="jcrp-card jcrp-card--small">';
		echo '<header class="jcrp-card__head"><h2>' . esc_html__( 'Current round', 'joinchat-random-phone' ) . '</h2></header>';

		if ( $round['size'] < 2 ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'Add two or more phones to start the rounds.', 'joinchat-random-phone' ) . '</p></section>';
			return;
		}

		$done = $round['size'] - $round['left'];

		echo '<p class="jcrp-round__text">';
		if ( $round['started'] ) {
			/* translators: %1$s: slots handed out, %2$s: slots per round. */
			echo esc_html( sprintf( __( '%1$s of %2$s slots handed out', 'joinchat-random-phone' ), self::num( $done ), self::num( $round['size'] ) ) );
		} else {
			esc_html_e( 'A new round starts with the next visitor', 'joinchat-random-phone' );
		}
		echo '</p>';

		printf(
			'<div class="jcrp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="%1$d" aria-valuenow="%2$d"><span style="width:%3$s%%"></span></div>',
			(int) $round['size'],
			(int) $done,
			esc_attr( round( $done / $round['size'] * 100, 2 ) )
		);

		echo '<p class="jcrp-card__note">' . esc_html__( 'Every phone gets its turn before any of them repeats. Still to come in this round:', 'joinchat-random-phone' ) . '</p>';
		echo '<ul class="jcrp-chips">';
		foreach ( $round['pending'] as $phone => $left ) {
			echo '<li>' . self::phone( (string) $phone ) . ( $left > 1 ? ' <span class="jcrp-chips__n">×' . esc_html( (int) $left ) . '</span>' : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul></section>';
	}

	/**
	 * How WhatsApp was opened + device split.
	 *
	 * @param array $d Data.
	 * @return void
	 */
	private function render_sources( $d ) {

		$names = array(
			'button'  => __( 'Floating button', 'joinchat-random-phone' ),
			'contact' => __( 'Chat window button', 'joinchat-random-phone' ),
			'trigger' => __( 'Buttons and links in the page', 'joinchat-random-phone' ),
			'auto'    => __( 'Opened automatically', 'joinchat-random-phone' ),
			'other'   => __( 'Not reported', 'joinchat-random-phone' ),
		);

		echo '<section class="jcrp-card jcrp-card--small">';
		echo '<header class="jcrp-card__head"><h2>' . esc_html__( 'How they reach out', 'joinchat-random-phone' ) . '</h2></header>';

		if ( ! $d['opens'] ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'No WhatsApp opens in this period.', 'joinchat-random-phone' ) . '</p></section>';
			return;
		}

		// Device: one whole split in two.
		$mob = $d['mobile'] / $d['opens'];
		echo '<h3 class="jcrp-subhead">' . esc_html__( 'Device', 'joinchat-random-phone' ) . '</h3>';
		printf(
			'<div class="jcrp-split" role="img" aria-label="%1$s">%2$s%3$s</div>',
			/* translators: %1$s: mobile share, %2$s: desktop share. */
			esc_attr( sprintf( __( 'Mobile %1$s, computer %2$s', 'joinchat-random-phone' ), self::pct( $mob ), self::pct( 1 - $mob ) ) ),
			$mob > 0 ? '<span class="jcrp-split__a" style="flex-grow:' . esc_attr( round( $mob * 1000 ) ) . '"></span>' : '',
			$mob < 1 ? '<span class="jcrp-split__b" style="flex-grow:' . esc_attr( round( ( 1 - $mob ) * 1000 ) ) . '"></span>' : ''
		);
		echo '<ul class="jcrp-split__legend">';
		echo '<li><span class="jcrp-key jcrp-key--a" aria-hidden="true"></span>' . esc_html__( 'Mobile', 'joinchat-random-phone' ) . ' <strong>' . esc_html( self::pct( $mob ) ) . '</strong></li>';
		echo '<li><span class="jcrp-key jcrp-key--b" aria-hidden="true"></span>' . esc_html__( 'Computer', 'joinchat-random-phone' ) . ' <strong>' . esc_html( self::pct( 1 - $mob ) ) . '</strong></li>';
		echo '</ul>';

		echo '<h3 class="jcrp-subhead">' . esc_html__( 'Opened from', 'joinchat-random-phone' ) . '</h3>';
		$this->bars( $d['sources'], $d['opens'], $names );

		echo '</section>';
	}

	/**
	 * Top pages card.
	 *
	 * @param array $pages Path => opens.
	 * @return void
	 */
	private function render_pages( $pages ) {

		echo '<section class="jcrp-card jcrp-card--small">';
		echo '<header class="jcrp-card__head"><h2>' . esc_html__( 'Pages with most contacts', 'joinchat-random-phone' ) . '</h2></header>';

		if ( ! $pages ) {
			echo '<p class="jcrp-empty">' . esc_html__( 'No WhatsApp opens in this period.', 'joinchat-random-phone' ) . '</p></section>';
			return;
		}

		$names = array();
		foreach ( array_keys( $pages ) as $path ) {
			$names[ $path ] = '/' === $path ? __( 'Home page', 'joinchat-random-phone' ) : $path;
		}

		$this->bars( $pages, array_sum( $pages ), $names, true );
		echo '</section>';
	}

	/**
	 * Horizontal bar list (one series).
	 *
	 * @param array $values Key => count (sorted).
	 * @param int   $total  Total for the percentages.
	 * @param array $names  Key => label.
	 * @param bool  $links  Labels link to the page.
	 * @return void
	 */
	private function bars( $values, $total, $names, $links = false ) {

		$max = $values ? max( $values ) : 1;

		echo '<ul class="jcrp-hbars">';
		foreach ( $values as $key => $count ) {
			$key   = (string) $key;
			$label = isset( $names[ $key ] ) ? $names[ $key ] : $key;
			$label = $links
				? '<a href="' . esc_url( home_url( $key ) ) . '" target="_blank" rel="noopener" title="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>'
				: esc_html( $label );

			printf(
				'<li><div class="jcrp-hbars__row"><span class="jcrp-hbars__label">%1$s</span><span class="jcrp-hbars__value">%2$s <span class="jcrp-muted">%3$s</span></span></div><div class="jcrp-hbars__track"><span style="width:%4$s%%"></span></div></li>',
				$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( self::num( $count ) ),
				esc_html( $total ? self::pct( $count / $total ) : '' ),
				esc_attr( round( $count / $max * 100, 2 ) )
			);
		}
		echo '</ul>';
	}
}
