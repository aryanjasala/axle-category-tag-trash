<?php
/**
 * Storage for trashed terms.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps each trashed term as a hidden post with its snapshot in post meta.
 */
final class Trash implements Service {

	public const POST_TYPE    = 'axle_ctt_item';
	public const SNAPSHOT_KEY = '_axle_category_tag_trash_snapshot';
	public const TAXONOMY_KEY = '_axle_category_tag_trash_taxonomy';
	public const TERM_KEY     = '_axle_category_tag_trash_term_id';
	public const OBJECTS_KEY  = '_axle_category_tag_trash_objects';
	public const FALLBACK_KEY = '_axle_category_tag_trash_fallback';
	public const STATE_KEY    = '_axle_category_tag_trash_restore_state';

	/**
	 * Post status of an item in the trash.
	 */
	public const STATUS_TRASHED = 'publish';

	/**
	 * Post status of an item whose objects are being re-attached in the background.
	 */
	public const STATUS_RESTORING = 'pending';

	/**
	 * Most items find() returns at once.
	 */
	public const MAX_PER_PAGE = 500;

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up storage.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hidden post type.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the post type that holds trashed terms.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'            => __( 'Axle Category & Tag Trash', 'axle-category-tag-trash' ),
				'public'           => false,
				'show_ui'          => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'supports'         => array( 'title', 'author' ),
				// Deleting the user who deleted a term must not take the term with it.
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Saved settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Taxonomies whose deleted terms are kept.
	 *
	 * Only taxonomies people manage on a screen. Menus are excluded because
	 * deleting a menu removes its items before the menu term itself.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public function supports( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );
		$keep   = $object && $object->show_ui && 'nav_menu' !== $taxonomy;

		/**
		 * Filters whether deleted terms of a taxonomy go to Axle Category & Tag Trash.
		 *
		 * @param bool   $keep     Whether to keep deleted terms.
		 * @param string $taxonomy Taxonomy name.
		 */
		return (bool) apply_filters( 'axle_category_tag_trash_supports_taxonomy', $keep, $taxonomy );
	}

	/**
	 * Stores a snapshot.
	 *
	 * @param Snapshot $snapshot Deleted term.
	 * @return int Trash item ID, or 0 on failure.
	 */
	public function add( Snapshot $snapshot ): int {
		$item_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => self::STATUS_TRASHED,
				'post_title'  => wp_slash( $snapshot->name ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $item_id ) ) {
			return 0;
		}

		update_post_meta( $item_id, self::SNAPSHOT_KEY, wp_slash( $snapshot->to_array() ) );
		update_post_meta( $item_id, self::TAXONOMY_KEY, $snapshot->taxonomy );
		update_post_meta( $item_id, self::TERM_KEY, $snapshot->term_id );

		foreach ( $snapshot->object_chunks as $chunk ) {
			add_post_meta( $item_id, self::OBJECTS_KEY, $chunk );
		}
		foreach ( $snapshot->fallback_chunks as $chunk ) {
			add_post_meta( $item_id, self::FALLBACK_KEY, $chunk );
		}

		return (int) $item_id;
	}

	/**
	 * Packed chunks of a trash item's objects, oldest first.
	 *
	 * Reads one page of meta rows at a time, so a term on a million posts is
	 * never loaded into memory at once.
	 *
	 * @param int      $item_id  Trash item ID.
	 * @param Snapshot $snapshot The item's snapshot.
	 * @param string   $key      OBJECTS_KEY or FALLBACK_KEY.
	 * @param int      $offset   Chunks to skip.
	 * @param int      $limit    Chunks to return.
	 * @return string[]
	 */
	public function chunks( int $item_id, Snapshot $snapshot, string $key, int $offset, int $limit ): array {
		global $wpdb;

		if ( ! $this->chunk_count( $item_id, $key ) ) {
			// Saved by 1.1.0 or earlier, with the objects inside the snapshot.
			$legacy = self::OBJECTS_KEY === $key ? $snapshot->legacy_object_chunks() : $snapshot->legacy_fallback_chunks();
			return array_slice( $legacy, $offset, $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged read of rows that are read once, during a restore.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT %d OFFSET %d",
				$item_id,
				$key,
				$limit,
				$offset
			)
		);

		return array_map( 'strval', $rows );
	}

	/**
	 * Number of packed chunk rows stored for a trash item.
	 *
	 * @param int    $item_id Trash item ID.
	 * @param string $key     OBJECTS_KEY or FALLBACK_KEY.
	 * @return int
	 */
	public function chunk_count( int $item_id, string $key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count of rows read once, during a restore.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $item_id, $key ) );
	}

	/**
	 * Whether a trash item's objects are still being re-attached in the background.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool
	 */
	public function is_restoring( int $item_id ): bool {
		return self::STATUS_RESTORING === get_post_status( $item_id );
	}

	/**
	 * Snapshot stored in a trash item.
	 *
	 * @param int $item_id Trash item ID.
	 * @return Snapshot|null
	 */
	public function get( int $item_id ): ?Snapshot {
		if ( self::POST_TYPE !== get_post_type( $item_id ) ) {
			return null;
		}

		$data = get_post_meta( $item_id, self::SNAPSHOT_KEY, true );

		return is_array( $data ) ? Snapshot::from_array( $data ) : null;
	}

	/**
	 * Trash item IDs, newest first.
	 *
	 * @param array $args {
	 *     Optional filters.
	 *
	 *     @type string $taxonomy Only this taxonomy.
	 *     @type int    $term_id  Only this original term ID.
	 *     @type string $search   Name contains this text.
	 *     @type int    $per_page Items per page, at most MAX_PER_PAGE. Default 100.
	 *     @type int    $page     Page number. Default 1.
	 * }
	 * @return array{ids: int[], total: int}
	 */
	public function find( array $args = array() ): array {
		$meta_query = array();
		if ( ! empty( $args['taxonomy'] ) ) {
			$meta_query[] = array(
				'key'   => self::TAXONOMY_KEY,
				'value' => $args['taxonomy'],
			);
		}
		if ( ! empty( $args['term_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::TERM_KEY,
				'value' => (int) $args['term_id'],
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				// Items being restored in the background are no longer in the trash.
				'post_status'    => self::STATUS_TRASHED,
				'fields'         => 'ids',
				'orderby'        => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'posts_per_page' => max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 100 ) ) ),
				'paged'          => $args['page'] ?? 1,
				's'              => $args['search'] ?? '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, private post type.
				'meta_query'     => $meta_query,
			)
		);

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Number of trashed terms per taxonomy.
	 *
	 * @return array<string, int>
	 */
	public function count_by_taxonomy(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Grouped count, no API for it.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS taxonomy, COUNT(*) AS total FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = %s GROUP BY pm.meta_value",
				self::TAXONOMY_KEY,
				self::POST_TYPE,
				self::STATUS_TRASHED
			)
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row->taxonomy ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Most recent trash item for an original term.
	 *
	 * @param int    $term_id  Original term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int Trash item ID, or 0.
	 */
	public function find_latest_for_term( int $term_id, string $taxonomy ): int {
		$found = $this->find(
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
				'per_page' => 1,
			)
		);

		return $found['ids'][0] ?? 0;
	}

	/**
	 * Deletes a trash item for good.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool
	 */
	public function delete( int $item_id ): bool {
		$snapshot = $this->get( $item_id );
		if ( ! $snapshot ) {
			return false;
		}

		/**
		 * Fires before a trashed term is deleted for good: Empty trash, Delete
		 * permanently, or the daily clean-up. Not when it is restored.
		 *
		 * @param int      $item_id  Trash item ID.
		 * @param Snapshot $snapshot What was saved.
		 */
		do_action( 'axle_category_tag_trash_deleted', $item_id, $snapshot );

		return $this->remove( $item_id );
	}

	/**
	 * Removes a trash item without announcing it, once its term is restored.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool
	 */
	public function remove( int $item_id ): bool {
		return self::POST_TYPE === get_post_type( $item_id ) && (bool) wp_delete_post( $item_id, true );
	}

	/**
	 * Whether the current user may restore or delete a trashed term.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @return bool
	 */
	public function user_can_manage( Snapshot $snapshot ): bool {
		$taxonomy = get_taxonomy( $snapshot->taxonomy );

		return $taxonomy ? current_user_can( $taxonomy->cap->manage_terms ) : current_user_can( 'manage_options' );
	}
}
