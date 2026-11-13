<?php
/**
 * Old term addresses that keep working.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the old archive address of a term to its new one.
 *
 * A redirect is recorded when a term's slug or its parents change, when it is
 * merged into another term, and when it is deleted with a redirect target. It
 * is served late on `template_redirect`, and only for a request that would be
 * a 404, so a new term that takes the old slug wins.
 *
 * A redirect points at a term, not at a fixed address. When that term is
 * renamed again, the old address follows it to the final one, so chains never
 * form. Feeds and paged archive addresses of the old term redirect too.
 */
final class Redirects implements Service {

	/**
	 * Option with every redirect, keyed by the old path. Not autoloaded.
	 */
	public const OPTION = 'axle_category_tag_trash_redirects';

	/**
	 * Option with hit counts, keyed by the old path. Not autoloaded.
	 */
	public const HITS_OPTION = 'axle_category_tag_trash_redirect_hits';

	/**
	 * Term meta: the term to send this term's address to if it is deleted.
	 */
	public const TARGET_META = 'axle_category_tag_trash_redirect_to';

	/**
	 * Transient prefix for the per-user list of redirects that were just removed.
	 */
	public const UNDO_PREFIX = 'axle_category_tag_trash_redirect_undo_';

	public const KIND_RENAME = 'rename';
	public const KIND_MOVE   = 'move';
	public const KIND_MERGE  = 'merge';
	public const KIND_TRASH  = 'trash';

	/**
	 * Paths of a term and its descendants before an update, keyed by term ID.
	 *
	 * @var array<int, array{paths: array<int, string>, slug: string, parent: int}>
	 */
	private array $before = array();

	/**
	 * Terms being deleted in this request, keyed by term ID.
	 *
	 * @var array<int, array{path: string, target: int, item: int, children: array<int, string>}>
	 */
	private array $deleting = array();

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'edit_terms', array( $this, 'before_update' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'after_update' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'before_delete' ), 5, 2 );
		add_action( 'axle_category_tag_trash_trashed', array( $this, 'note_item' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'after_delete' ), 20, 3 );
		add_action( 'axle_category_tag_trash_merged', array( $this, 'after_merge' ), 10, 3 );
		add_action( 'axle_category_tag_trash_restored', array( $this, 'after_restore' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 9 );
	}

	/**
	 * Whether redirects are recorded and served at all.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether old term addresses are recorded and redirected.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'axle_category_tag_trash_redirects_enabled', true );
	}

	/**
	 * Whether a taxonomy has archive addresses that can be redirected.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function supports( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );
		$yes    = $object && ! empty( $object->public ) && false !== $object->rewrite && (bool) get_option( 'permalink_structure' );

		/**
		 * Filters whether old addresses of a taxonomy's terms are redirected.
		 *
		 * Needs pretty permalinks: with plain ones a term address is a query string that never changes.
		 *
		 * @param bool   $yes      Default true for public taxonomies with a rewrite rule.
		 * @param string $taxonomy Taxonomy name.
		 */
		return (bool) apply_filters( 'axle_category_tag_trash_redirect_supports_taxonomy', $yes, $taxonomy );
	}

	/**
	 * Most redirects kept. The oldest go first.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many redirects are kept.
		 *
		 * @param int $limit Default 1000.
		 */
		return max( 10, (int) apply_filters( 'axle_category_tag_trash_redirect_limit', 1000 ) );
	}

	/**
	 * Most child terms whose addresses are checked when a term changes.
	 *
	 * @return int
	 */
	public static function descendant_limit(): int {
		/**
		 * Filters how many child terms are checked for a changed address when their parent changes.
		 *
		 * @param int $limit Default 200.
		 */
		return max( 0, (int) apply_filters( 'axle_category_tag_trash_redirect_descendant_limit', 200 ) );
	}

	/**
	 * Cleans a path for storing and comparing: decoded, lower case, no slashes at the ends.
	 *
	 * @param string $path Path relative to the site address.
	 * @return string
	 */
	public static function normalize( string $path ): string {
		return strtolower( trim( rawurldecode( $path ), '/' ) );
	}

	/**
	 * Path of an address on this site, relative to the site address.
	 *
	 * @param string $url Address.
	 * @return string|null Null for another site, or an address with a query string.
	 */
	public static function path_from_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );

		if ( ! is_array( $parts ) || ! empty( $parts['query'] ) || empty( $parts['path'] ) ) {
			return null;
		}

		if ( ! empty( $parts['host'] ) && ! empty( $home['host'] ) && 0 !== strcasecmp( (string) $parts['host'], (string) $home['host'] ) ) {
			return null;
		}

		$path = (string) $parts['path'];
		$base = isset( $home['path'] ) ? rtrim( (string) $home['path'], '/' ) : '';
		if ( '' !== $base && 0 === stripos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		$path = self::normalize( $path );

		return '' === $path ? null : $path;
	}

	/**
	 * Current archive path of a term.
	 *
	 * @param mixed $term The term.
	 * @return string|null
	 */
	public static function term_path( $term ): ?string {
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$link = get_term_link( $term );

		return is_string( $link ) ? self::path_from_url( $link ) : null;
	}

	/**
	 * Archive paths of a term and its descendants, now.
	 *
	 * @param \WP_Term $term         The term.
	 * @param bool     $include_self Whether to include the term itself.
	 * @return array<int, string> Path by term ID.
	 */
	private function paths_of( \WP_Term $term, bool $include_self = true ): array {
		$paths = array();

		if ( $include_self ) {
			$path = self::term_path( $term );
			if ( null !== $path ) {
				$paths[ (int) $term->term_id ] = $path;
			}
		}

		$limit = self::descendant_limit();
		if ( $limit && is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$children = get_term_children( (int) $term->term_id, $term->taxonomy );
			foreach ( is_wp_error( $children ) ? array() : array_slice( array_map( 'intval', $children ), 0, $limit ) as $child_id ) {
				$path = self::term_path( get_term( $child_id, $term->taxonomy ) );
				if ( null !== $path ) {
					$paths[ $child_id ] = $path;
				}
			}
		}

		return $paths;
	}

	/**
	 * Remembers addresses just before a term is updated.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function before_update( $term_id, $taxonomy ): void {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		$term     = get_term( $term_id, $taxonomy );

		if ( ! self::enabled() || ! self::supports( $taxonomy ) || ! $term instanceof \WP_Term ) {
			return;
		}

		$this->before[ $term_id ] = array(
			'paths'  => $this->paths_of( $term ),
			'slug'   => $term->slug,
			'parent' => (int) $term->parent,
		);
	}

	/**
	 * Records a redirect for every address that changed.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function after_update( $term_id, $tt_id, $taxonomy ): void {
		$term_id = (int) $term_id;
		$before  = $this->before[ $term_id ] ?? null;
		unset( $this->before[ $term_id ] );

		if ( ! $before ) {
			return;
		}

		$taxonomy = (string) $taxonomy;
		foreach ( $before['paths'] as $id => $old_path ) {
			$term = get_term( (int) $id, $taxonomy );
			if ( ! $term instanceof \WP_Term || self::term_path( $term ) === $old_path ) {
				continue;
			}

			$kind = ( (int) $id === $term_id && $term->slug !== $before['slug'] ) ? self::KIND_RENAME : self::KIND_MOVE;
			$this->record( $old_path, $term, $kind );
		}
	}

	/**
	 * Remembers a term's address, its target and its children's addresses before it is deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function before_delete( $term_id, $taxonomy ): void {
		$term_id  = (int) $term_id;
		$taxonomy = (string) $taxonomy;
		$term     = get_term( $term_id, $taxonomy );

		if ( ! self::enabled() || ! self::supports( $taxonomy ) || ! $term instanceof \WP_Term ) {
			return;
		}

		$paths = $this->paths_of( $term );
		$path  = $paths[ $term_id ] ?? '';
		unset( $paths[ $term_id ] );

		$this->deleting[ $term_id ] = array(
			'path'     => $path,
			'target'   => (int) get_term_meta( $term_id, self::TARGET_META, true ),
			'item'     => 0,
			'children' => $paths,
		);
	}

	/**
	 * Notes the trash item a deleted term landed in, so Undo can take its redirect away.
	 *
	 * @param int   $item_id  Trash item ID.
	 * @param mixed $snapshot What was saved, a Snapshot.
	 */
	public function note_item( $item_id, $snapshot ): void {
		if ( $snapshot instanceof Snapshot && isset( $this->deleting[ $snapshot->term_id ] ) ) {
			$this->deleting[ $snapshot->term_id ]['item'] = (int) $item_id;
		}
	}

	/**
	 * Records the redirect chosen for a deleted term, and the new addresses of its children.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function after_delete( $term_id, $tt_id, $taxonomy ): void {
		$term_id = (int) $term_id;
		$pending = $this->deleting[ $term_id ] ?? null;
		unset( $this->deleting[ $term_id ] );

		if ( ! $pending ) {
			return;
		}

		$taxonomy = (string) $taxonomy;
		foreach ( $pending['children'] as $id => $old_path ) {
			$child = get_term( (int) $id, $taxonomy );
			if ( $child instanceof \WP_Term && self::term_path( $child ) !== $old_path ) {
				$this->record( $old_path, $child, self::KIND_MOVE );
			}
		}

		$target = $pending['target'] > 0 && $pending['target'] !== $term_id ? get_term( $pending['target'], $taxonomy ) : null;
		if ( '' !== $pending['path'] && $target instanceof \WP_Term ) {
			$this->record( $pending['path'], $target, self::KIND_TRASH, $term_id );
		}
	}

	/**
	 * Records the redirect of a term that was merged away.
	 *
	 * @param int   $item_id Trash item ID, 0 when it was not kept.
	 * @param mixed $source  The merged-away term, a WP_Term.
	 * @param mixed $target  The term that received its objects, a WP_Term.
	 */
	public function after_merge( $item_id, $source, $target ): void {
		if ( ! $source instanceof \WP_Term || ! $target instanceof \WP_Term || ! self::enabled() || ! self::supports( $source->taxonomy ) ) {
			return;
		}

		$path = self::term_path( $source );
		if ( null !== $path ) {
			$this->record( $path, $target, self::KIND_MERGE, (int) $source->term_id );
		}
	}

	/**
	 * Takes back the redirects of a term that is restored, including an undone merge.
	 *
	 * @param int    $term_id  Restored term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param mixed  $snapshot What was saved when the term was deleted, a Snapshot.
	 */
	public function after_restore( $term_id, $taxonomy, $snapshot ): void {
		$old_id = $snapshot instanceof Snapshot ? $snapshot->term_id : (int) $term_id;
		$map    = self::all();
		$dirty  = false;

		foreach ( $map as $key => $entry ) {
			if ( $entry['taxonomy'] !== $taxonomy ) {
				continue;
			}

			if ( $entry['old_term'] === $old_id && in_array( $entry['kind'], array( self::KIND_MERGE, self::KIND_TRASH ), true ) ) {
				unset( $map[ $key ] );
				$dirty = true;
				continue;
			}

			foreach ( $entry['chain'] as $index => $link ) {
				if ( $link['by'] === $old_id ) {
					$map[ $key ]['term'] = $link['from'];
					array_splice( $map[ $key ]['chain'], $index );
					$dirty = true;
					break;
				}
			}
		}

		if ( $dirty ) {
			$this->save( $map );
		}
	}

	/**
	 * Records that the address of a term used to be somewhere else.
	 *
	 * @param string   $old_path Old path.
	 * @param \WP_Term $target   The term the old address should lead to.
	 * @param string   $kind     One of the KIND_ constants.
	 * @param int      $old_term ID of the term that lived at the old address, when it is gone.
	 * @return bool Whether a redirect is now stored.
	 */
	public function record( string $old_path, \WP_Term $target, string $kind, int $old_term = 0 ): bool {
		$old_path = self::normalize( $old_path );
		$new_path = self::term_path( $target );

		if ( '' === $old_path || null === $new_path || $old_path === $new_path || ! self::enabled() || ! self::supports( $target->taxonomy ) ) {
			return false;
		}

		$map = self::all();

		// The term is back at an address that used to redirect.
		unset( $map[ $new_path ] );

		// Redirects that led to a term that is going away now lead to its target.
		if ( $old_term > 0 ) {
			foreach ( $map as $key => $entry ) {
				if ( $entry['taxonomy'] === $target->taxonomy && $entry['term'] === $old_term ) {
					$map[ $key ]['chain'][] = array(
						'from' => $old_term,
						'by'   => $old_term,
					);
					$map[ $key ]['term']    = (int) $target->term_id;
				}
			}
		}

		$entry = array(
			'taxonomy' => $target->taxonomy,
			'term'     => (int) $target->term_id,
			'old_term' => $old_term,
			'kind'     => $kind,
			'created'  => time(),
			'chain'    => array(),
		);

		$map[ $old_path ] = $entry;
		$this->save( $this->trim( $map ) );

		/**
		 * Fires after an old term address is set to redirect.
		 *
		 * @param string               $old_path Old path, relative to the site address.
		 * @param array<string, mixed> $entry    taxonomy, term (target term ID), old_term, kind, created, chain.
		 */
		do_action( 'axle_category_tag_trash_redirect_added', $old_path, $entry );

		return true;
	}

	/**
	 * Every redirect, cleaned, keyed by old path.
	 *
	 * @return array<string, array{taxonomy: string, term: int, old_term: int, kind: string, created: int, chain: array<int, array{from: int, by: int}>}>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$map   = array();

		foreach ( is_array( $saved ) ? $saved : array() as $key => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['taxonomy'] ) || empty( $entry['term'] ) || '' === (string) $key ) {
				continue;
			}

			$chain = array();
			foreach ( (array) ( $entry['chain'] ?? array() ) as $link ) {
				if ( is_array( $link ) && isset( $link['from'], $link['by'] ) ) {
					$chain[] = array(
						'from' => (int) $link['from'],
						'by'   => (int) $link['by'],
					);
				}
			}

			$map[ (string) $key ] = array(
				'taxonomy' => (string) $entry['taxonomy'],
				'term'     => (int) $entry['term'],
				'old_term' => (int) ( $entry['old_term'] ?? 0 ),
				'kind'     => (string) ( $entry['kind'] ?? self::KIND_RENAME ),
				'created'  => (int) ( $entry['created'] ?? 0 ),
				'chain'    => $chain,
			);
		}

		return $map;
	}

	/**
	 * Saves the redirects, and drops hit counts of redirects that are gone.
	 *
	 * @param array<string, array> $map Redirects by old path.
	 */
	private function save( array $map ): void {
		update_option( self::OPTION, $map, false );

		$hits = self::hits();
		$kept = array_intersect_key( $hits, $map );
		if ( count( $kept ) !== count( $hits ) ) {
			update_option( self::HITS_OPTION, $kept, false );
		}
	}

	/**
	 * Drops the oldest redirects above the limit.
	 *
	 * @param array<string, array> $map Redirects by old path.
	 * @return array<string, array>
	 */
	private function trim( array $map ): array {
		$limit = self::limit();
		if ( count( $map ) <= $limit ) {
			return $map;
		}

		uasort(
			$map,
			static function ( array $a, array $b ): int {
				return $b['created'] <=> $a['created'];
			}
		);

		return array_slice( $map, 0, $limit, true );
	}

	/**
	 * Hit counts by old path.
	 *
	 * @return array<string, array{n: int, last: int}>
	 */
	public static function hits(): array {
		$saved = get_option( self::HITS_OPTION, array() );
		$hits  = array();

		foreach ( is_array( $saved ) ? $saved : array() as $key => $row ) {
			if ( is_array( $row ) ) {
				$hits[ (string) $key ] = array(
					'n'    => (int) ( $row['n'] ?? 0 ),
					'last' => (int) ( $row['last'] ?? 0 ),
				);
			}
		}

		return $hits;
	}

	/**
	 * Finds the redirect for a request path, with the feed or page part that follows it.
	 *
	 * @param string $path Normalized request path.
	 * @return array{key: string, tail: string, entry: array}|null
	 */
	public static function match( string $path ): ?array {
		$map = self::all();

		if ( isset( $map[ $path ] ) ) {
			return array(
				'key'   => $path,
				'tail'  => '',
				'entry' => $map[ $path ],
			);
		}

		if ( ! preg_match( '#^(?<base>.+?)(?<paged>/page/[0-9]+)?(?<feed>/feed(?:/(?:feed|rdf|rss|rss2|atom))?|/(?:feed|rdf|rss|rss2|atom))?$#', $path, $m ) ) {
			return null;
		}

		$tail = ( $m['paged'] ?? '' ) . ( $m['feed'] ?? '' );
		if ( '' === $tail || ! isset( $map[ $m['base'] ] ) ) {
			return null;
		}

		return array(
			'key'   => $m['base'],
			'tail'  => $tail,
			'entry' => $map[ $m['base'] ],
		);
	}

	/**
	 * Where a redirect leads now.
	 *
	 * @param string               $key   Old path.
	 * @param array<string, mixed> $entry The redirect.
	 * @param string               $tail  Feed or page part of the request, such as "/page/2".
	 * @return string Empty when the target term is gone.
	 */
	public static function target_url( string $key, array $entry, string $tail = '' ): string {
		$term = get_term( (int) $entry['term'], (string) $entry['taxonomy'] );
		$url  = $term instanceof \WP_Term ? get_term_link( $term ) : '';

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		if ( '' !== $tail ) {
			$url = user_trailingslashit( trailingslashit( $url ) . ltrim( $tail, '/' ), false !== strpos( $tail, 'feed' ) ? 'feed' : 'paged' );
		}

		/**
		 * Filters the address an old term address redirects to.
		 *
		 * @param string               $url   New address. Return an empty string to not redirect.
		 * @param string               $key   Old path, relative to the site address.
		 * @param array<string, mixed> $entry The redirect.
		 */
		return (string) apply_filters( 'axle_category_tag_trash_redirect_target_url', $url, $key, $entry );
	}

	/**
	 * Whether a request could be an old term address, so that the redirects are worth reading.
	 *
	 * That is a 404, a feed of a term that does not exist (WordPress answers those with an empty feed),
	 * and an archive that opens a term at an address other than its own: WordPress serves
	 * /category/old-parent/child/ for a child that has since moved. Every other request, and every
	 * archive at its own address, returns here without reading anything.
	 *
	 * @param string $path Normalized request path.
	 * @return bool
	 */
	private function is_candidate( string $path ): bool {
		if ( is_404() ) {
			return true;
		}

		$object = get_queried_object();

		if ( is_feed() && ! $object ) {
			return true;
		}

		if ( ! $object instanceof \WP_Term ) {
			return false;
		}

		$own = self::term_path( $object );

		return null !== $own && $path !== $own && 0 !== strpos( $path, $own . '/' );
	}

	/**
	 * Redirects a request for an address that used to belong to a term.
	 */
	public function maybe_redirect(): void {
		global $wp;

		if ( ! self::enabled() ) {
			return;
		}

		$path = self::normalize( isset( $wp->request ) ? (string) $wp->request : '' );
		if ( '' === $path || ! $this->is_candidate( $path ) ) {
			return;
		}

		$match = self::match( $path );
		if ( ! $match ) {
			return;
		}

		$url  = self::target_url( $match['key'], $match['entry'], $match['tail'] );
		$here = self::path_from_url( $url );
		if ( '' === $url || $here === $path ) {
			return;
		}

		// Keep tracking parameters such as utm_source.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Parsed and cleaned below.
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		if ( '' !== $query ) {
			parse_str( $query, $args );
			$args = array_map( 'sanitize_text_field', array_filter( wp_unslash( $args ), 'is_scalar' ) );
			$url  = add_query_arg( array_map( 'rawurlencode', $args ), $url );
		}

		/**
		 * Filters the status code of an old term address redirect.
		 *
		 * @param int $status 301, 302, 307 or 308. Default 301.
		 */
		$status = (int) apply_filters( 'axle_category_tag_trash_redirect_status', 301 );
		$status = in_array( $status, array( 301, 302, 307, 308 ), true ) ? $status : 301;

		$this->count_hit( $match['key'] );

		/**
		 * Fires just before an old term address is redirected.
		 *
		 * @param string               $path  Old path that was requested.
		 * @param string               $url   Address it redirects to.
		 * @param array<string, mixed> $entry The redirect.
		 */
		do_action( 'axle_category_tag_trash_redirected', $path, $url, $match['entry'] );

		wp_safe_redirect( $url, $status, 'Axle Category & Tag Trash' );
		exit;
	}

	/**
	 * Counts one visit to an old address.
	 *
	 * @param string $key Old path.
	 */
	private function count_hit( string $key ): void {
		$hits         = self::hits();
		$row          = $hits[ $key ] ?? array(
			'n'    => 0,
			'last' => 0,
		);
		$hits[ $key ] = array(
			'n'    => $row['n'] + 1,
			'last' => time(),
		);
		update_option( self::HITS_OPTION, $hits, false );
	}

	/**
	 * Redirects for display, newest first.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string $taxonomy     Only this taxonomy.
	 *     @type string $search       Old or new address contains this text.
	 *     @type int    $per_page     Rows per page. Default 50.
	 *     @type int    $page         Page number. Default 1.
	 *     @type bool   $check_access Only taxonomies the current user may list. Default true.
	 * }
	 * @return array{total: int, items: array<int, array<string, mixed>>}
	 */
	public static function find( array $args = array() ): array {
		$taxonomy = Access::taxonomy_name( $args['taxonomy'] ?? '' );
		$search   = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$check    = ! isset( $args['check_access'] ) || $args['check_access'];
		$hits     = self::hits();
		$rows     = array();

		foreach ( self::all() as $key => $entry ) {
			if ( ( '' !== $taxonomy && $entry['taxonomy'] !== $taxonomy ) || ( $check && ! Access::can( $entry['taxonomy'], Access::LIST ) ) ) {
				continue;
			}

			$rows[ $key ] = $entry;
		}

		uasort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['created'] <=> $a['created'];
			}
		);

		$items = array();
		foreach ( $rows as $key => $entry ) {
			$url  = self::target_url( $key, $entry );
			$term = get_term( $entry['term'], $entry['taxonomy'] );
			$row  = array(
				'path'        => $key,
				'id'          => md5( $key ),
				'old_url'     => home_url( user_trailingslashit( $key ) ),
				'new_url'     => $url,
				'taxonomy'    => $entry['taxonomy'],
				'term_id'     => $entry['term'],
				'term_name'   => $term instanceof \WP_Term ? $term->name : '',
				'kind'        => $entry['kind'],
				'created'     => $entry['created'],
				'hits'        => $hits[ $key ]['n'] ?? 0,
				'last_hit'    => $hits[ $key ]['last'] ?? 0,
				'target_gone' => '' === $url,
			);

			if ( '' !== $search && false === strpos( strtolower( $key . ' ' . $url . ' ' . $row['term_name'] ), $search ) ) {
				continue;
			}

			$items[] = $row;
		}

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		return array(
			'total' => count( $items ),
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
		);
	}

	/**
	 * Redirects whose target term no longer exists.
	 *
	 * @return int
	 */
	public static function count_broken(): int {
		$broken = 0;
		foreach ( self::all() as $key => $entry ) {
			if ( '' === self::target_url( $key, $entry ) ) {
				++$broken;
			}
		}

		return $broken;
	}

	/**
	 * Finds an old path from a path or the short ID shown on the redirects screen.
	 *
	 * @param string $value Path or ID.
	 * @return string Old path, or an empty string.
	 */
	public static function resolve_key( string $value ): string {
		$map  = self::all();
		$path = self::normalize( $value );

		if ( isset( $map[ $path ] ) ) {
			return $path;
		}

		foreach ( array_keys( $map ) as $key ) {
			if ( md5( $key ) === $value ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * Removes redirects.
	 *
	 * @param string[] $keys Old paths.
	 * @return array<string, array> The removed redirects, with their hits, for an Undo.
	 */
	public function remove( array $keys ): array {
		$map     = self::all();
		$hits    = self::hits();
		$removed = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}

			$removed[ $key ] = $map[ $key ] + array( 'hits' => $hits[ $key ] ?? array() );
			unset( $map[ $key ] );
		}

		if ( $removed ) {
			$this->save( $map );
			foreach ( $removed as $key => $entry ) {
				/**
				 * Fires after an old term address stops redirecting.
				 *
				 * @param string               $key   Old path.
				 * @param array<string, mixed> $entry The redirect that was removed.
				 */
				do_action( 'axle_category_tag_trash_redirect_removed', $key, $entry );
			}
		}

		return $removed;
	}

	/**
	 * Keeps removed redirects for a few minutes so the person can undo.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, array> $removed What remove() returned.
	 */
	public function stash( int $user_id, array $removed ): void {
		/** This filter is documented in src/Snapshotter.php */
		$seconds = max( 30, (int) apply_filters( 'axle_category_tag_trash_undo_seconds', 5 * MINUTE_IN_SECONDS ) );
		set_transient( self::UNDO_PREFIX . $user_id, $removed, $seconds );
	}

	/**
	 * Puts back the redirects this user removed last.
	 *
	 * @param int  $user_id      User ID.
	 * @param bool $check_access Only bring back redirects of taxonomies the current user may change.
	 * @return int How many came back.
	 */
	public function undo( int $user_id, bool $check_access = true ): int {
		$removed = get_transient( self::UNDO_PREFIX . $user_id );
		delete_transient( self::UNDO_PREFIX . $user_id );

		if ( ! is_array( $removed ) || ! $removed ) {
			return 0;
		}

		$map  = self::all();
		$hits = self::hits();
		$back = 0;

		foreach ( $removed as $key => $entry ) {
			if ( ! is_array( $entry ) || isset( $map[ $key ] ) || ( $check_access && ! Access::can( (string) ( $entry['taxonomy'] ?? '' ), Access::REDIRECTS ) ) ) {
				continue;
			}

			if ( ! empty( $entry['hits'] ) ) {
				$hits[ $key ] = $entry['hits'];
			}
			unset( $entry['hits'] );
			$map[ (string) $key ] = $entry;
			++$back;
		}

		if ( $back ) {
			update_option( self::HITS_OPTION, $hits, false );
			$this->save( $this->trim( $map ) );
		}

		return $back;
	}
}
