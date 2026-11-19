<?php
/**
 * Old addresses of deleted terms that still get visits.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Counts 404 visits to the addresses of trashed terms, and redirects them after the fact.
 *
 * The address of each trashed term is saved in its snapshot. A small list of those
 * addresses is kept in one option, read only on a 404. A visit is counted by
 * HitCounter, so it never writes on every request. Known bots are not counted.
 */
final class OldAddresses implements Service {

	/**
	 * Option with trashed addresses: old path => trash item ID. Not autoloaded.
	 */
	public const MAP_OPTION = 'axle_category_tag_trash_old_paths';

	/**
	 * Option with visit counts, keyed by old path. Not autoloaded.
	 */
	public const HITS_OPTION = 'axle_category_tag_trash_old_hits';

	/**
	 * Kind of a redirect added from the trash.
	 */
	public const KIND = 'manual';

	/**
	 * Redirects.
	 *
	 * @var Redirects
	 */
	private Redirects $redirects;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up the service.
	 *
	 * @param Trash     $trash     Trashed terms.
	 * @param Redirects $redirects Redirects.
	 */
	public function __construct( Trash $trash, Redirects $redirects ) {
		$this->trash     = $trash;
		$this->redirects = $redirects;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'axle_category_tag_trash_trashed', array( $this, 'add' ), 10, 2 );
		add_action( 'axle_category_tag_trash_deleted', array( $this, 'forget_item' ), 10, 2 );
		add_action( 'axle_category_tag_trash_restored', array( $this, 'forget_restored' ), 10, 3 );
		// After the redirects (priority 9) and the usual redirect plugins, so only a real 404 is counted.
		add_action( 'template_redirect', array( $this, 'count_visit' ), 20 );
	}

	/**
	 * Whether old addresses of deleted terms are watched at all.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether visits to the old addresses of trashed terms are counted.
		 *
		 * @param bool $enabled Default true.
		 */
		return Redirects::enabled() && (bool) apply_filters( 'axle_category_tag_trash_old_address_counting', true );
	}

	/**
	 * Trashed addresses by trash item.
	 *
	 * @return array<string, int> Old path => trash item ID.
	 */
	public static function map(): array {
		$saved = get_option( self::MAP_OPTION, array() );
		$map   = array();

		foreach ( is_array( $saved ) ? $saved : array() as $path => $item_id ) {
			if ( '' !== (string) $path && (int) $item_id > 0 ) {
				$map[ (string) $path ] = (int) $item_id;
			}
		}

		return $map;
	}

	/**
	 * Remembers the address of a term that was just trashed.
	 *
	 * @param int   $item_id  Trash item ID.
	 * @param mixed $snapshot What was saved, a Snapshot.
	 */
	public function add( $item_id, $snapshot ): void {
		if ( ! $snapshot instanceof Snapshot || '' === $snapshot->path ) {
			return;
		}

		$map                    = self::map();
		$map[ $snapshot->path ] = (int) $item_id;
		update_option( self::MAP_OPTION, $map, false );
	}

	/**
	 * Forgets the address of a trash item that is deleted for good.
	 *
	 * @param int   $item_id  Trash item ID.
	 * @param mixed $snapshot What was saved, a Snapshot.
	 */
	public function forget_item( $item_id, $snapshot ): void {
		if ( $snapshot instanceof Snapshot ) {
			$this->forget( $snapshot->path, (int) $item_id );
		}
	}

	/**
	 * Forgets the address of a term that is restored.
	 *
	 * @param int    $term_id  Restored term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param mixed  $snapshot What was saved when the term was deleted, a Snapshot.
	 */
	public function forget_restored( $term_id, $taxonomy, $snapshot ): void {
		if ( $snapshot instanceof Snapshot ) {
			$this->forget( $snapshot->path, 0 );
		}
	}

	/**
	 * Takes an address off the list, and drops its counts.
	 *
	 * @param string $path    Old path.
	 * @param int    $item_id Only when the address belongs to this item. 0 for any.
	 */
	private function forget( string $path, int $item_id ): void {
		$map = self::map();

		if ( '' === $path || ! isset( $map[ $path ] ) || ( $item_id && $map[ $path ] !== $item_id ) ) {
			return;
		}

		unset( $map[ $path ] );
		update_option( self::MAP_OPTION, $map, false );

		$hits = HitCounter::read( HitCounter::OLD );
		if ( isset( $hits[ $path ] ) ) {
			unset( $hits[ $path ] );
			HitCounter::write( HitCounter::OLD, $hits );
		}
	}

	/**
	 * Drops addresses whose trash item is gone, and their counts. Runs with the hourly write.
	 */
	public static function prune(): void {
		$map  = self::map();
		$keep = $map;

		foreach ( $map as $path => $item_id ) {
			if ( Trash::POST_TYPE !== get_post_type( $item_id ) ) {
				unset( $keep[ $path ] );
			}
		}

		if ( count( $keep ) !== count( $map ) ) {
			update_option( self::MAP_OPTION, $keep, false );
		}

		$hits = HitCounter::read( HitCounter::OLD );
		$kept = array_intersect_key( $hits, $keep );
		if ( count( $kept ) !== count( $hits ) ) {
			HitCounter::write( HitCounter::OLD, $kept );
		}
	}

	/**
	 * Whether a user agent is a known bot, a link checker or a script.
	 *
	 * @param string $agent User agent.
	 * @return bool
	 */
	public static function is_bot( string $agent ): bool {
		$agent = strtolower( trim( $agent ) );

		/**
		 * Filters the parts of a user agent that mark a visit as not a person.
		 *
		 * A visit whose user agent contains one of these, or is empty, is not counted.
		 *
		 * @param string[] $needles Lower case parts of a user agent.
		 */
		$needles = array_filter(
			array_map(
				'strval',
				(array) apply_filters(
					'axle_category_tag_trash_bot_agents',
					array(
						'bot',
						'crawl',
						'spider',
						'slurp',
						'bingpreview',
						'facebookexternalhit',
						'embedly',
						'preview',
						'whatsapp',
						'headless',
						'lighthouse',
						'pingdom',
						'uptime',
						'monitor',
						'semrush',
						'ahrefs',
						'mj12',
						'yandex',
						'baidu',
						'duckduck',
						'ia_archiver',
						'feedfetcher',
						'curl/',
						'wget/',
						'python',
						'go-http-client',
						'java/',
						'libwww',
						'httpclient',
						'scrapy',
					)
				) 
			) 
		);

		if ( '' === $agent ) {
			return true;
		}

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $agent, strtolower( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Counts a visit to the old address of a trashed term, when the request is a 404.
	 */
	public function count_visit(): void {
		global $wp;

		if ( ! is_404() || is_admin() || ! self::enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared against a fixed word.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__ -- Only searched for bot words to skip a count; the page does not change with it, and it is never stored or printed.
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		if ( 'GET' !== $method || self::is_bot( $agent ) ) {
			return;
		}

		$map = self::map();
		if ( ! $map ) {
			return;
		}

		$key = self::key_for( self::normalize_request( isset( $wp->request ) ? (string) $wp->request : '' ), $map );
		if ( null === $key ) {
			return;
		}

		HitCounter::record( HitCounter::OLD, $key );

		/**
		 * Fires when a visitor lands on the old address of a trashed term and gets a 404.
		 *
		 * @param string $key     Old path.
		 * @param int    $item_id Trash item ID.
		 */
		do_action( 'axle_category_tag_trash_old_address_visited', $key, $map[ $key ] );
	}

	/**
	 * Cleans a request path.
	 *
	 * @param string $request Request path.
	 * @return string
	 */
	private static function normalize_request( string $request ): string {
		return Redirects::normalize( $request );
	}

	/**
	 * Finds the trashed address a request path belongs to, with a feed or page part cut off.
	 *
	 * @param string             $path Normalized request path.
	 * @param array<string, int> $map  Trashed addresses.
	 * @return string|null
	 */
	public static function key_for( string $path, array $map ): ?string {
		if ( isset( $map[ $path ] ) ) {
			return $path;
		}

		if ( ! preg_match( '#^(?<base>.+?)(?<paged>/page/[0-9]+)?(?<feed>/feed(?:/(?:feed|rdf|rss|rss2|atom))?|/(?:feed|rdf|rss|rss2|atom))?$#', $path, $m ) ) {
			return null;
		}

		return ( '' !== ( $m['paged'] ?? '' ) || '' !== ( $m['feed'] ?? '' ) ) && isset( $map[ $m['base'] ] ) ? $m['base'] : null;
	}

	/**
	 * Visits to an address in the last seven days.
	 *
	 * @param array{n: int, last: int, d: array<string, int>} $row Counts of one address.
	 * @return int
	 */
	public static function week_visits( array $row ): int {
		$total = 0;
		$since = gmdate( 'Ymd', time() - 6 * DAY_IN_SECONDS );

		foreach ( $row['d'] as $day => $count ) {
			if ( (string) $day >= $since ) {
				$total += (int) $count;
			}
		}

		return $total;
	}

	/**
	 * What is known about the old address of one trashed term.
	 *
	 * @param int      $item_id  Trash item ID.
	 * @param Snapshot $snapshot The item's snapshot.
	 * @return array{path: string, week: int, total: int, last: int, redirect: string}|null Null when the address is unknown.
	 */
	public static function stats( int $item_id, Snapshot $snapshot ): ?array {
		if ( '' === $snapshot->path ) {
			return null;
		}

		HitCounter::flush();
		$hits = HitCounter::read( HitCounter::OLD );
		$row  = $hits[ $snapshot->path ] ?? array(
			'n'    => 0,
			'last' => 0,
			'd'    => array(),
		);

		return array(
			'path'     => $snapshot->path,
			'week'     => self::week_visits( $row ),
			'total'    => $row['n'],
			'last'     => $row['last'],
			'redirect' => isset( Redirects::all()[ $snapshot->path ] ) ? $snapshot->path : '',
		);
	}

	/**
	 * Trashed terms whose old address got visits this week and does not redirect.
	 *
	 * @param int    $minimum  Fewest visits this week.
	 * @param string $taxonomy Only this taxonomy. Empty for all.
	 * @param bool   $check    Only taxonomies the current user may redirect. Default true.
	 * @return array<int, array{item_id: int, term_id: int, name: string, taxonomy: string, path: string, week: int, total: int, last: int}> Most visited first.
	 */
	public function visited( int $minimum = 1, string $taxonomy = '', bool $check = true ): array {
		HitCounter::flush();

		$hits = HitCounter::read( HitCounter::OLD );
		$all  = Redirects::all();
		$rows = array();

		foreach ( self::map() as $path => $item_id ) {
			if ( isset( $all[ $path ] ) || ! isset( $hits[ $path ] ) ) {
				continue;
			}

			$week = self::week_visits( $hits[ $path ] );
			if ( $week < max( 1, $minimum ) ) {
				continue;
			}

			$snapshot = $this->trash->get( $item_id );
			if ( ! $snapshot || ( '' !== $taxonomy && $snapshot->taxonomy !== $taxonomy ) || ( $check && ! Access::can( $snapshot->taxonomy, Access::REDIRECTS ) ) ) {
				continue;
			}

			$rows[] = array(
				'item_id'  => $item_id,
				'term_id'  => $snapshot->term_id,
				'name'     => $snapshot->name,
				'taxonomy' => $snapshot->taxonomy,
				'path'     => $path,
				'week'     => $week,
				'total'    => $hits[ $path ]['n'],
				'last'     => $hits[ $path ]['last'],
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['week'] <=> $a['week'];
			}
		);

		return $rows;
	}

	/**
	 * Redirects the old address of a trashed term.
	 *
	 * @param int                  $item_id Trash item ID.
	 * @param array<string, mixed> $target  `term` (a term ID in the same taxonomy) or `url` (an address on this site).
	 * @param bool                 $check   Check the current user's rights for the taxonomy. WP-CLI passes false.
	 * @return array{path: string, new_url: string}|\WP_Error
	 */
	public function redirect( int $item_id, array $target, bool $check = true ) {
		$snapshot = $this->trash->get( $item_id );
		if ( ! $snapshot ) {
			return new \WP_Error( 'axle_category_tag_trash_gone', __( 'That term is no longer in the trash.', 'axle-category-tag-trash' ), array( 'status' => 404 ) );
		}

		if ( $check && ! Access::can( $snapshot->taxonomy, Access::REDIRECTS ) ) {
			return new \WP_Error( 'axle_category_tag_trash_forbidden', __( 'Sorry, you are not allowed to add redirects for this taxonomy.', 'axle-category-tag-trash' ), array( 'status' => 403 ) );
		}

		if ( '' === $snapshot->path || ! Redirects::enabled() || ! Redirects::supports( $snapshot->taxonomy ) ) {
			return new \WP_Error( 'axle_category_tag_trash_no_address', __( 'The old address of this term is not known, so it cannot be redirected.', 'axle-category-tag-trash' ), array( 'status' => 400 ) );
		}

		if ( isset( Redirects::all()[ $snapshot->path ] ) ) {
			return new \WP_Error( 'axle_category_tag_trash_exists', __( 'This address already redirects.', 'axle-category-tag-trash' ), array( 'status' => 409 ) );
		}

		$term_id = (int) ( $target['term'] ?? 0 );
		$url     = trim( (string) ( $target['url'] ?? '' ) );

		if ( $term_id > 0 ) {
			$term = get_term( $term_id, $snapshot->taxonomy );
			if ( ! $term instanceof \WP_Term || (int) $term->term_id === $snapshot->term_id ) {
				return new \WP_Error( 'axle_category_tag_trash_bad_target', __( 'Pick a term that exists.', 'axle-category-tag-trash' ), array( 'status' => 400 ) );
			}

			if ( ! $this->redirects->record( $snapshot->path, $term, self::KIND, $snapshot->term_id ) ) {
				return new \WP_Error( 'axle_category_tag_trash_bad_target', __( 'That term has no address of its own to redirect to.', 'axle-category-tag-trash' ), array( 'status' => 400 ) );
			}

			return array(
				'path'    => $snapshot->path,
				'new_url' => Redirects::target_url( $snapshot->path, Redirects::all()[ $snapshot->path ] ),
			);
		}

		$clean = $this->clean_url( $url );
		if ( '' === $clean || Redirects::path_from_url( $clean ) === $snapshot->path ) {
			return new \WP_Error( 'axle_category_tag_trash_bad_target', __( 'Enter an address on this site, such as /blog/ or a full address from this site.', 'axle-category-tag-trash' ), array( 'status' => 400 ) );
		}

		if ( ! $this->redirects->record_url( $snapshot->path, $snapshot->taxonomy, $clean, self::KIND, $snapshot->term_id ) ) {
			return new \WP_Error( 'axle_category_tag_trash_bad_target', __( 'This address cannot be redirected.', 'axle-category-tag-trash' ), array( 'status' => 400 ) );
		}

		return array(
			'path'    => $snapshot->path,
			'new_url' => $clean,
		);
	}

	/**
	 * A redirect target on this site, as a full address.
	 *
	 * @param string $url Address or path typed by a person.
	 * @return string Empty when it is not on this site.
	 */
	private function clean_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url || ! $this->same_site( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Whether an address is on this site's own host.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	private function same_site( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home ) && 0 === strcasecmp( $host, $home );
	}
}
