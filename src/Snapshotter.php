<?php
/**
 * Captures terms as WordPress deletes them.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Records a term on `pre_delete_term` and saves it on `delete_term`.
 *
 * `pre_delete_term` fires before child terms are re-parented and before posts
 * are moved to the default term, so the original state is still there. The
 * snapshot is only saved once `delete_term` confirms the deletion.
 */
final class Snapshotter implements Service {

	/**
	 * Relationship rows read per query while capturing.
	 */
	private const BATCH = Snapshot::CHUNK;

	/**
	 * Most menu links to one term that are captured.
	 */
	private const MENU_LINKS = 100;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Whether the Undo notice after a bulk delete is held back, for deletes that are part of a merge.
	 *
	 * @var bool
	 */
	private static bool $quiet = false;

	/**
	 * Snapshots of terms being deleted in this request, keyed by term ID.
	 *
	 * @var array<int, Snapshot>
	 */
	private array $pending = array();

	/**
	 * Sets up capture.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks into term deletion.
	 */
	public function register(): void {
		add_action( 'pre_delete_term', array( $this, 'capture' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'commit' ), 10, 3 );
	}

	/**
	 * Holds back the Undo notice for deletes that a merge does, which have their own Undo.
	 *
	 * @param bool $quiet Whether to hold it back.
	 */
	public static function quiet( bool $quiet ): void {
		self::$quiet = $quiet;
	}

	/**
	 * Name of the per-user transient that lists terms to offer an Undo for.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function undo_key( int $user_id ): string {
		return 'axle_category_tag_trash_undo_' . $user_id;
	}

	/**
	 * Records a term just before deletion.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function capture( $term_id, $taxonomy ): void {
		if ( ! $this->trash->supports( (string) $taxonomy ) ) {
			return;
		}

		$term = get_term( (int) $term_id, (string) $taxonomy );

		/**
		 * Filters whether one deleted term goes to Axle Category & Tag Trash.
		 *
		 * The taxonomy filter (axle_category_tag_trash_supports_taxonomy) decides per taxonomy.
		 * This one decides per term, for example to let empty tags go for good.
		 *
		 * @param bool     $keep Whether to keep the term. Default true.
		 * @param \WP_Term $term The term being deleted.
		 */
		if ( ! $term instanceof \WP_Term || ! apply_filters( 'axle_category_tag_trash_keep_term', true, $term ) ) {
			return;
		}

		$snapshot = $this->build( (int) $term_id, (string) $taxonomy );
		if ( $snapshot ) {
			$this->pending[ (int) $term_id ] = $snapshot;
		}
	}

	/**
	 * Saves the snapshot once the term is really gone.
	 *
	 * Deletions made on a normal admin page load (bulk delete) are remembered
	 * for an Undo notice on the next screen. Ajax deletions get their Undo
	 * from the screen's script instead.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function commit( $term_id, $tt_id, $taxonomy ): void {
		$snapshot = $this->pending[ (int) $term_id ] ?? null;
		unset( $this->pending[ (int) $term_id ] );

		if ( ! $snapshot || $snapshot->taxonomy !== $taxonomy ) {
			return;
		}

		$item_id = $this->trash->add( $snapshot );
		$user_id = get_current_user_id();

		if ( $item_id ) {
			/**
			 * Fires after a deleted term lands in Axle Category & Tag Trash.
			 *
			 * @param int      $item_id  Trash item ID.
			 * @param Snapshot $snapshot What was saved.
			 */
			do_action( 'axle_category_tag_trash_trashed', $item_id, $snapshot );
		}

		if ( $item_id && $user_id && is_admin() && ! wp_doing_ajax() && ! self::$quiet ) {
			$undo   = (array) get_transient( self::undo_key( $user_id ) );
			$undo[] = $item_id;
			/**
			 * Filters how long the Undo notice after a bulk delete stays available.
			 *
			 * @param int $seconds Default 300.
			 */
			set_transient( self::undo_key( $user_id ), array_values( array_filter( $undo ) ), max( 30, (int) apply_filters( 'axle_category_tag_trash_undo_seconds', 5 * MINUTE_IN_SECONDS ) ) );
		}
	}

	/**
	 * Collects a term's row data, meta, object relationships, children and menu links.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return Snapshot|null
	 */
	public function build( int $term_id, string $taxonomy ): ?Snapshot {
		global $wpdb;

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$snapshot                   = new Snapshot();
		$snapshot->term_id          = (int) $term->term_id;
		$snapshot->term_taxonomy_id = (int) $term->term_taxonomy_id;
		$snapshot->taxonomy         = $taxonomy;
		$snapshot->name             = $term->name;
		$snapshot->slug             = $term->slug;
		$snapshot->description      = $term->description;
		$snapshot->parent           = (int) $term->parent;
		$snapshot->term_group       = (int) $term->term_group;
		$snapshot->meta             = (array) get_term_meta( $term->term_id );
		$snapshot->default_term     = $this->default_term( $taxonomy );
		$snapshot->menu_items       = $this->menu_links( $snapshot->term_id, $taxonomy );
		$snapshot->children         = array_map(
			'intval',
			get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $term->term_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);

		// Read the relationships a page at a time and keep them packed, so a term
		// on a million posts costs a few MB of strings, not a few hundred MB of arrays.
		$after = 0;
		do {
			// Raw relationship rows keep term_order, which the term APIs drop.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id > %d ORDER BY object_id ASC LIMIT %d",
					$term->term_taxonomy_id,
					$after,
					self::BATCH
				),
				ARRAY_N
			);

			$pairs = array();
			foreach ( $rows as $row ) {
				$pairs[] = array( (int) $row[0], (int) $row[1] );
			}
			if ( ! $pairs ) {
				break;
			}

			$snapshot->add_objects( $pairs );
			if ( $snapshot->default_term ) {
				$snapshot->add_fallback_objects( $this->sole_term_objects( wp_list_pluck( $pairs, 0 ), $taxonomy ) );
			}

			$after   = (int) end( $pairs )[0];
			$fetched = count( $pairs );
		} while ( self::BATCH === $fetched );

		return $snapshot;
	}

	/**
	 * Term that WordPress gives to objects left with no term in this taxonomy.
	 *
	 * Mirrors wp_delete_term(): the default category, or the taxonomy's default term.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return int Term ID, or 0 when the taxonomy has none.
	 */
	private function default_term( string $taxonomy ): int {
		$object = get_taxonomy( $taxonomy );

		if ( ! empty( $object->default_term ) ) {
			return (int) get_option( 'default_term_' . $taxonomy );
		}

		return 'category' === $taxonomy ? (int) get_option( 'default_category' ) : 0;
	}

	/**
	 * Objects whose only term in the taxonomy is the one being deleted.
	 *
	 * @param int[]  $object_ids Objects attached to the term.
	 * @param string $taxonomy   Taxonomy name.
	 * @return int[]
	 */
	private function sole_term_objects( array $object_ids, string $taxonomy ): array {
		global $wpdb;

		$sole = array();
		foreach ( array_chunk( array_map( 'intval', $object_ids ), self::BATCH ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tr.object_id IN ( {$placeholders} )
					GROUP BY tr.object_id HAVING COUNT(*) = 1",
					array_merge( array( $taxonomy ), $chunk )
				)
			);
			// phpcs:enable

			$sole = array_merge( $sole, array_map( 'intval', $found ) );
		}

		return $sole;
	}

	/**
	 * Menu items that link to the term, which WordPress deletes along with it.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array[]
	 */
	private function menu_links( int $term_id, string $taxonomy ): array {
		$items = get_posts(
			array(
				'post_type'   => 'nav_menu_item',
				'post_status' => 'any',
				'numberposts' => self::MENU_LINKS,
				// Menu items keep their target in meta; there is no other index.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => array(
					array(
						'key'   => '_menu_item_type',
						'value' => 'taxonomy',
					),
					array(
						'key'   => '_menu_item_object',
						'value' => $taxonomy,
					),
					array(
						'key'   => '_menu_item_object_id',
						'value' => (string) $term_id,
					),
				),
			)
		);

		$links = array();
		foreach ( $items as $item ) {
			$menus = wp_get_object_terms( $item->ID, 'nav_menu', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $menus ) || ! $menus ) {
				continue;
			}

			$links[] = array(
				'id'          => (int) $item->ID,
				'menu_id'     => (int) $menus[0],
				'position'    => (int) $item->menu_order,
				'parent_id'   => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ),
				'title'       => $item->post_title,
				'attr_title'  => $item->post_excerpt,
				'description' => $item->post_content,
				'target'      => (string) get_post_meta( $item->ID, '_menu_item_target', true ),
				'classes'     => array_values( array_filter( (array) get_post_meta( $item->ID, '_menu_item_classes', true ) ) ),
				'xfn'         => (string) get_post_meta( $item->ID, '_menu_item_xfn', true ),
				'children'    => array_map(
					'intval',
					get_posts(
						array(
							'post_type'   => 'nav_menu_item',
							'post_status' => 'any',
							'numberposts' => self::MENU_LINKS,
							'fields'      => 'ids',
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'meta_key'    => '_menu_item_menu_item_parent',
							// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'meta_value'  => (string) $item->ID,
						)
					)
				),
			);
		}

		return $links;
	}
}
