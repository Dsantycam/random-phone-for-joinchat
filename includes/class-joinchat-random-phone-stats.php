<?php
/**
 * Distribution statistics: storage and queries.
 *
 * Only daily COUNTERS are stored, never anything about the visitor (no IP, no
 * token, no visitor number): one row per day + kind + item, incremented with a
 * single atomic UPSERT, so concurrent visitors never lose a count and the table
 * stays tiny (a few rows per phone and day).
 *
 * Kinds (item between brackets):
 *   assign [phone]  New visitor assigned by the server (shuffle-bag pop).
 *   open   [phone]  WhatsApp opened towards that phone.
 *   first  [phone]  First time this visitor opened WhatsApp (unique contact).
 *   mobile [phone]  Opens made from a mobile device.
 *   local  [phone]  Visitor whose phone was picked by the browser because the
 *                   server did not answer (health indicator).
 *   src    [source] What opened WhatsApp (floating button, chatbox, link...).
 *   page   [path]   Page where WhatsApp was opened.
 *
 * Recording can NEVER break the distribution: every write is wrapped, errors
 * are suppressed and a missing table only means nothing is counted.
 *
 * @since      4.5.0
 * @package    JoinChatRandomPhone
 */

defined( 'WPINC' ) || exit;

/**
 * Stats class.
 */
class JoinChatRandomPhoneStats {

	const DB_VERSION = '1';
	const OPT_DB     = 'joinchat_random_phone_stats_db';
	const RETRY      = 'jcrp_stats_install_retry';
	const KEEP_DAYS  = 400; // A bit more than a year, to compare with last year.

	// Sizes chosen so the primary key fits the 767 bytes index limit of old
	// MySQL versions with utf8mb4 (3 + 10*4 + 150*4 = 643 bytes).
	const ITEM_MAX = 150;

	const KINDS   = array( 'assign', 'open', 'first', 'mobile', 'local', 'src', 'page' );
	const SOURCES = array( 'button', 'contact', 'trigger', 'auto', 'other' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'jcrp_stats';
	}

	/**
	 * Are stats enabled? Filterable, so a site can switch them off entirely.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'joinchat_random_phone_stats', true );
	}

	/**
	 * Today in the site time zone (Y-m-d).
	 *
	 * @return string
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Create/upgrade the table when needed. Cheap when already installed (one
	 * autoloaded option), and a failed install is retried at most once an hour.
	 *
	 * @return bool Table ready.
	 */
	public static function maybe_install() {

		if ( self::DB_VERSION === get_option( self::OPT_DB ) ) {
			return true;
		}

		if ( get_transient( self::RETRY ) ) {
			return false;
		}

		return self::install();
	}

	/**
	 * Create the table.
	 *
	 * @return bool
	 */
	public static function install() {

		global $wpdb;

		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$table = self::table();
			$sql   = "CREATE TABLE {$table} (
  day date NOT NULL,
  kind varchar(10) NOT NULL,
  item varchar(" . self::ITEM_MAX . ") NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,kind,item)
) " . $wpdb->get_charset_collate() . ';';

			$suppress = $wpdb->suppress_errors( true );
			dbDelta( $sql );
			$wpdb->suppress_errors( $suppress );

			$ok = self::table_exists();
		} catch ( \Throwable $e ) {
			$ok = false;
		}

		if ( $ok ) {
			update_option( self::OPT_DB, self::DB_VERSION, true );
			delete_transient( self::RETRY );
		} else {
			set_transient( self::RETRY, 1, HOUR_IN_SECONDS );
		}

		return $ok;
	}

	/**
	 * Does the table exist?
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Add one hit to each [kind, item] pair, for today, in a single query.
	 *
	 * @param array $pairs List of array( kind, item ).
	 * @return bool
	 */
	public static function record( $pairs ) {

		if ( ! self::enabled() || empty( $pairs ) ) {
			return false;
		}

		global $wpdb;

		try {
			if ( ! self::maybe_install() ) {
				return false;
			}

			$day    = self::today();
			$rows   = array();
			$values = array();

			foreach ( $pairs as $pair ) {
				if ( ! is_array( $pair ) || 2 !== count( $pair ) || ! in_array( $pair[0], self::KINDS, true ) ) {
					continue;
				}
				$item = self::clip( (string) $pair[1] );

				$rows[]   = '(%s, %s, %s, 1)';
				$values[] = $day;
				$values[] = $pair[0];
				$values[] = $item;
			}

			if ( ! $rows ) {
				return false;
			}

			$table = self::table();
			$sql   = "INSERT INTO {$table} (day, kind, item, hits) VALUES " . implode( ', ', $rows ) . ' ON DUPLICATE KEY UPDATE hits = hits + 1';

			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->suppress_errors( $suppress );

			// The table vanished (restored backup, manual cleanup...): rebuild it
			// next time instead of failing silently forever.
			if ( false === $result && ! self::table_exists() ) {
				delete_option( self::OPT_DB );
			}

			return false !== $result;

		} catch ( \Throwable $e ) {
			// Same check when the database layer throws instead of returning false.
			try {
				if ( ! self::table_exists() ) {
					delete_option( self::OPT_DB );
				}
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
			return false;
		}
	}

	/**
	 * Cut an item to the column size (multibyte safe).
	 *
	 * @param string $item Item.
	 * @return string
	 */
	private static function clip( $item ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $item, 0, self::ITEM_MAX );
		}
		return substr( $item, 0, self::ITEM_MAX );
	}

	/**
	 * Map a Joinchat trigger name to one of our sources.
	 *
	 * Joinchat 6.x names: button (floating button), contact (chatbox button),
	 * trigger (buttons and links in the content), auto / screen / hover / url
	 * (opened on its own). Older versions don't say.
	 *
	 * @param string $trigger Joinchat trigger.
	 * @return string
	 */
	public static function source( $trigger ) {

		$trigger = sanitize_key( (string) $trigger );

		if ( in_array( $trigger, array( 'auto', 'screen', 'hover', 'url' ), true ) ) {
			return 'auto';
		}

		return in_array( $trigger, self::SOURCES, true ) ? $trigger : 'other';
	}

	/**
	 * Clean a page URL to a short path (no domain, no query, no fragment).
	 *
	 * @param string $page Path or URL.
	 * @return string
	 */
	public static function page_path( $page ) {

		$path = wp_parse_url( (string) $page, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );

		// Strip the subfolder of a WordPress installed in a directory.
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $home ) && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}

		return self::clip( $path );
	}

	// ------------------------------------------------------------- queries.

	/**
	 * Totals by kind and item for a date range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array [kind][item] => hits
	 */
	public static function totals( $from, $to ) {

		global $wpdb;

		$out = array_fill_keys( self::KINDS, array() );

		if ( ! self::maybe_install() ) {
			return $out;
		}

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kind, item, SUM(hits) AS hits FROM {$table} WHERE day BETWEEN %s AND %s GROUP BY kind, item", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['kind'] ] ) ) {
				$out[ $row['kind'] ][ (string) $row['item'] ] = (int) $row['hits'];
			}
		}

		return $out;
	}

	/**
	 * Daily totals of some kinds, with every day of the range present.
	 *
	 * @param string $from  Y-m-d.
	 * @param string $to    Y-m-d.
	 * @param array  $kinds Kinds.
	 * @return array [Y-m-d] => [kind => hits]
	 */
	public static function daily( $from, $to, $kinds = array( 'assign', 'open' ) ) {

		global $wpdb;

		$out  = array();
		$day  = new DateTime( $from );
		$last = new DateTime( $to );

		while ( $day <= $last ) {
			$out[ $day->format( 'Y-m-d' ) ] = array_fill_keys( $kinds, 0 );
			$day->modify( '+1 day' );
		}

		if ( ! $kinds || ! self::maybe_install() ) {
			return $out;
		}

		$table = self::table();
		$in    = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, kind, SUM(hits) AS hits FROM {$table} WHERE day BETWEEN %s AND %s AND kind IN ({$in}) GROUP BY day, kind", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $from, $to ), $kinds )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ][ $row['kind'] ] ) ) {
				$out[ $row['day'] ][ $row['kind'] ] = (int) $row['hits'];
			}
		}

		return $out;
	}

	/**
	 * Every row of a range (for the CSV export).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function rows( $from, $to ) {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return array();
		}

		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, kind, item, hits FROM {$table} WHERE day BETWEEN %s AND %s ORDER BY day, kind, item", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			),
			ARRAY_A
		);
	}

	/**
	 * First day with data ('' when empty).
	 *
	 * @return string
	 */
	public static function first_day() {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return '';
		}

		$table = self::table();
		return (string) $wpdb->get_var( "SELECT MIN(day) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete data older than KEEP_DAYS.
	 *
	 * @return void
	 */
	public static function prune() {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return;
		}

		$limit = gmdate( 'Y-m-d', strtotime( self::today() . ' -' . self::KEEP_DAYS . ' days' ) );
		$table = self::table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every statistic.
	 *
	 * @return void
	 */
	public static function reset() {

		global $wpdb;

		if ( ! self::maybe_install() ) {
			return;
		}

		$table = self::table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the table (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {

		global $wpdb;

		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::OPT_DB );
		delete_transient( self::RETRY );
	}
}
