<?php
/**
 * Visit counts that do not write to the database on every request.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Counts visits to old term addresses.
 *
 * With a persistent object cache a visit only bumps a counter in the cache, and a
 * WP-Cron event writes the counters to the database once an hour. Without one, a
 * visit writes at most once a minute per address, so the count is a lower bound
 * for a very busy address. Either way, a crawler hammering an old address never
 * means a database write per request.
 */
final class HitCounter implements Service {

	/**
	 * Cron event that writes the cached counters.
	 */
	public const HOOK = 'axle_category_tag_trash_flush_hits';

	/**
	 * Counts for addresses that redirect.
	 */
	public const REDIRECT = 'redirect';

	/**
	 * Counts for addresses of trashed terms that do not redirect.
	 */
	public const OLD = 'old';

	/**
	 * Cache group of the counters.
	 */
	private const GROUP = 'axle_category_tag_trash_hits';

	/**
	 * Days of per-day counts kept for an old address.
	 */
	private const DAYS = 14;

	/**
	 * Adds the cron callback.
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'flush' ) );
	}

	/**
	 * Option that holds the counts of a store.
	 *
	 * @param string $store REDIRECT or OLD.
	 * @return string
	 */
	public static function option( string $store ): string {
		return self::OLD === $store ? OldAddresses::HITS_OPTION : Redirects::HITS_OPTION;
	}

	/**
	 * Whether visits are held in the object cache until the hourly write.
	 *
	 * @return bool
	 */
	public static function buffered(): bool {
		/**
		 * Filters whether visit counts are held in the object cache and written once an hour.
		 *
		 * @param bool $buffered Default true when a persistent object cache is in use.
		 */
		return (bool) apply_filters( 'axle_category_tag_trash_hit_buffer', wp_using_ext_object_cache() );
	}

	/**
	 * Shortest time between two database writes for one address, when no object cache holds the counts.
	 *
	 * @return int Seconds. 0 writes on every visit.
	 */
	public static function write_interval(): int {
		/**
		 * Filters the shortest time between two database writes for the visits of one old address.
		 *
		 * Only used without a persistent object cache. With one, counts are written once an hour.
		 *
		 * @param int $seconds Default 60. 0 writes on every visit.
		 */
		return max( 0, (int) apply_filters( 'axle_category_tag_trash_hit_write_interval', MINUTE_IN_SECONDS ) );
	}

	/**
	 * Counts one visit.
	 *
	 * @param string $store REDIRECT or OLD.
	 * @param string $key   Old path.
	 */
	public static function record( string $store, string $key ): void {
		$now = time();

		if ( self::buffered() ) {
			$name = self::cache_key( $store, $key );

			wp_cache_add( $name, 0, self::GROUP );
			if ( false === wp_cache_incr( $name, 1, self::GROUP ) ) {
				wp_cache_set( $name, 1, self::GROUP );
			}
			wp_cache_set( $name . ':t', $now, self::GROUP );

			if ( false === wp_cache_get( 'dirty', self::GROUP ) ) {
				wp_cache_set( 'dirty', $now, self::GROUP );
				if ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( $now + HOUR_IN_SECONDS, self::HOOK );
				}
			}
			return;
		}

		$stored = self::read( $store );
		$last   = $stored[ $key ]['last'] ?? 0;
		if ( $last && $now - $last < self::write_interval() ) {
			return;
		}

		self::write( $store, self::add( $stored, $key, 1, $now ) );
	}

	/**
	 * Writes the cached counters to the database. Runs hourly, and before a screen shows counts.
	 */
	public static function flush(): void {
		if ( false === wp_cache_get( 'dirty', self::GROUP ) ) {
			return;
		}
		wp_cache_delete( 'dirty', self::GROUP );

		$keys = array(
			self::REDIRECT => array_keys( Redirects::all() ),
			self::OLD      => array_keys( OldAddresses::map() ),
		);

		foreach ( $keys as $store => $paths ) {
			$stored  = self::read( $store );
			$changed = false;

			foreach ( $paths as $key ) {
				$name = self::cache_key( $store, (string) $key );
				$n    = (int) wp_cache_get( $name, self::GROUP );
				if ( $n < 1 ) {
					continue;
				}

				$when = (int) wp_cache_get( $name . ':t', self::GROUP );
				wp_cache_decr( $name, $n, self::GROUP );
				$stored  = self::add( $stored, (string) $key, $n, $when ? $when : time() );
				$changed = true;
			}

			if ( $changed ) {
				self::write( $store, $stored );
			}
		}

		OldAddresses::prune();
	}

	/**
	 * Counts of a store, cleaned.
	 *
	 * @param string $store REDIRECT or OLD.
	 * @return array<string, array{n: int, last: int, d: array<string, int>}>
	 */
	public static function read( string $store ): array {
		$saved = get_option( self::option( $store ), array() );
		$rows  = array();

		foreach ( is_array( $saved ) ? $saved : array() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$days = array();
			foreach ( (array) ( $row['d'] ?? array() ) as $day => $count ) {
				$days[ (string) $day ] = (int) $count;
			}

			$rows[ (string) $key ] = array(
				'n'    => (int) ( $row['n'] ?? 0 ),
				'last' => (int) ( $row['last'] ?? 0 ),
				'd'    => $days,
			);
		}

		return $rows;
	}

	/**
	 * Saves the counts of a store. Not autoloaded.
	 *
	 * @param string               $store REDIRECT or OLD.
	 * @param array<string, array> $rows  Counts.
	 */
	public static function write( string $store, array $rows ): void {
		if ( self::REDIRECT === $store ) {
			// Redirect counts keep the shape they always had.
			foreach ( $rows as $key => $row ) {
				$rows[ $key ] = array(
					'n'    => (int) $row['n'],
					'last' => (int) $row['last'],
				);
			}
		}

		update_option( self::option( $store ), $rows, false );
	}

	/**
	 * Adds visits to one address.
	 *
	 * @param array<string, array> $rows  Counts.
	 * @param string               $key   Old path.
	 * @param int                  $count Visits to add.
	 * @param int                  $when  Time of the last of them.
	 * @return array<string, array>
	 */
	private static function add( array $rows, string $key, int $count, int $when ): array {
		$row = $rows[ $key ] ?? array(
			'n'    => 0,
			'last' => 0,
			'd'    => array(),
		);

		$day         = gmdate( 'Ymd', $when );
		$row['n']    = (int) $row['n'] + $count;
		$row['last'] = max( (int) $row['last'], $when );
		$row['d']    = (array) ( $row['d'] ?? array() );

		$row['d'][ $day ] = (int) ( $row['d'][ $day ] ?? 0 ) + $count;
		krsort( $row['d'] );
		$row['d'] = array_slice( $row['d'], 0, self::DAYS, true );

		$rows[ $key ] = $row;

		return $rows;
	}

	/**
	 * Cache key of one counter.
	 *
	 * @param string $store REDIRECT or OLD.
	 * @param string $key   Old path.
	 * @return string
	 */
	private static function cache_key( string $store, string $key ): string {
		return $store . ':' . md5( $key );
	}
}
