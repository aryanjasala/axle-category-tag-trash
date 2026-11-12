<?php
/**
 * Merges one term into another, with undo.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Moves every object of a source term to a target term, then puts the source in the trash.
 *
 * The source goes through the normal trash capture, so its snapshot records
 * exactly which objects it was on. A list of the objects that got the target
 * only through the merge is saved next to it, so Undo can restore the source
 * and take the target off just those objects. Objects that already had both
 * keep both.
 *
 * Objects move in batches. The first batch runs in the request, the rest
 * through WP-Cron, and WP-CLI finishes everything at once.
 */
final class Merger implements Service {

	/**
	 * Cron hook that moves the next batch of a merge.
	 */
	public const HOOK = 'axle_category_tag_trash_merge_batch';

	/**
	 * Post meta on a running merge: what is being merged and how far it got.
	 */
	public const JOB_KEY = '_axle_category_tag_trash_merge_job';

	public const CHILDREN_TARGET = 'target';
	public const CHILDREN_PARENT = 'parent';

	/**
	 * Seconds after which a merge lock is treated as stale.
	 */
	private const LOCK_TTL = 600;

	/**
	 * Most menu links to one term that are moved.
	 */
	private const MENU_LINKS = 200;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up merging.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the background batches.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'continue_merge' ) );
	}

	/**
	 * The ways child terms of the source can be handled.
	 *
	 * @return string[]
	 */
	public static function children_policies(): array {
		return array( self::CHILDREN_TARGET, self::CHILDREN_PARENT );
	}

	/**
	 * What happens to child terms of the source when none is chosen.
	 *
	 * @param \WP_Term $source Source term.
	 * @param \WP_Term $target Target term.
	 * @return string One of children_policies().
	 */
	public static function default_children_policy( \WP_Term $source, \WP_Term $target ): string {
		/**
		 * Filters what happens to the child terms of a term that is merged away.
		 *
		 * `target` moves them under the target term. `parent` moves them up to the
		 * source's own parent, which is what deleting a term does.
		 *
		 * @param string   $policy `target` (default) or `parent`.
		 * @param \WP_Term $source Term merged away.
		 * @param \WP_Term $target Term that receives the objects.
		 */
		$policy = (string) apply_filters( 'axle_category_tag_trash_merge_children', self::CHILDREN_TARGET, $source, $target );

		return in_array( $policy, self::children_policies(), true ) ? $policy : self::CHILDREN_TARGET;
	}

	/**
	 * Whether WordPress keeps this term as the default of its taxonomy, which cannot be deleted.
	 *
	 * @param \WP_Term $term Term.
	 * @return bool
	 */
	public static function is_default_term( \WP_Term $term ): bool {
		$object = get_taxonomy( $term->taxonomy );
		// phpcs:ignore WordPressVIPMinimum.Performance.TaxonomyMetaInOptions.PossibleTermMetaInOptions -- Reads core's own default-term option, not term meta.
		$default = $object && ! empty( $object->default_term ) ? (int) get_option( 'default_term_' . $term->taxonomy ) : ( 'category' === $term->taxonomy ? (int) get_option( 'default_category' ) : 0 );

		return $default > 0 && $default === (int) $term->term_id;
	}

	/**
	 * Checks that a merge is allowed, and loads both terms.
	 *
	 * @param int    $source_id Term to merge away.
	 * @param int    $target_id Term that receives the objects.
	 * @param string $taxonomy  Taxonomy name.
	 * @return array{0: \WP_Term, 1: \WP_Term}|\WP_Error
	 */
	public function validate( int $source_id, int $target_id, string $taxonomy ) {
		$taxonomy = Access::taxonomy_name( $taxonomy );
		$object   = get_taxonomy( $taxonomy );

		if ( ! $object ) {
			return new \WP_Error( 'axle_category_tag_trash_taxonomy', __( 'That taxonomy does not exist.', 'axle-category-tag-trash' ) );
		}

		if ( ! $this->trash->supports( $taxonomy ) ) {
			return new \WP_Error( 'axle_category_tag_trash_unsupported', __( 'Terms of this taxonomy are not kept in the trash, so a merge could not be undone.', 'axle-category-tag-trash' ) );
		}

		if ( $source_id === $target_id ) {
			return new \WP_Error( 'axle_category_tag_trash_same', __( 'Pick two different terms.', 'axle-category-tag-trash' ) );
		}

		$source = get_term( $source_id, $taxonomy );
		$target = get_term( $target_id, $taxonomy );
		if ( ! $source instanceof \WP_Term || ! $target instanceof \WP_Term ) {
			return new \WP_Error( 'axle_category_tag_trash_missing_term', __( 'One of the terms no longer exists.', 'axle-category-tag-trash' ) );
		}

		if ( self::is_default_term( $source ) ) {
			/* translators: %s: term name. */
			return new \WP_Error( 'axle_category_tag_trash_default', sprintf( __( '"%s" is the default term and cannot be merged away. Merge other terms into it instead.', 'axle-category-tag-trash' ), $source->name ) );
		}

		if ( get_current_user_id() > 0 ) {
			$allowed = Access::can( $taxonomy, Access::MERGE ) && current_user_can( 'delete_term', $source_id ) && current_user_can( 'edit_term', $target_id );

			/**
			 * Filters whether the current user may merge one term into another.
			 *
			 * Runs after the taxonomy's own capabilities and the per-term ones.
			 *
			 * @param bool     $allowed Whether the merge is allowed.
			 * @param \WP_Term $source  Term merged away.
			 * @param \WP_Term $target  Term that receives the objects.
			 */
			if ( ! apply_filters( 'axle_category_tag_trash_can_merge', $allowed, $source, $target ) ) {
				return new \WP_Error( 'axle_category_tag_trash_forbidden', __( 'Sorry, you are not allowed to merge these terms.', 'axle-category-tag-trash' ), array( 'status' => 403 ) );
			}
		}

		return array( $source, $target );
	}

	/**
	 * What a merge would do, without doing it.
	 *
	 * @param int    $source_id Term to merge away.
	 * @param int    $target_id Term that receives the objects.
	 * @param string $taxonomy  Taxonomy name.
	 * @param string $children  Child term handling, or empty for the default.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( int $source_id, int $target_id, string $taxonomy, string $children = '' ) {
		$valid = $this->validate( $source_id, $target_id, $taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		list( $source, $target ) = $valid;

		$objects = $this->count_objects( (int) $source->term_taxonomy_id );
		$already = $this->count_shared( (int) $source->term_taxonomy_id, (int) $target->term_taxonomy_id );
		$kids    = get_terms(
			array(
				'taxonomy'   => $source->taxonomy,
				'parent'     => $source->term_id,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		return array(
			'source_id'       => (int) $source->term_id,
			'source_name'     => $source->name,
			'source_slug'     => $source->slug,
			'target_id'       => (int) $target->term_id,
			'target_name'     => $target->name,
			'target_slug'     => $target->slug,
			'taxonomy'        => $source->taxonomy,
			'objects'         => $objects,
			'already'         => $already,
			'moving'          => max( 0, $objects - $already ),
			'children'        => is_wp_error( $kids ) ? 0 : count( $kids ),
			'children_policy' => in_array( $children, self::children_policies(), true ) ? $children : self::default_children_policy( $source, $target ),
			'menu_links'      => count( $this->menu_item_ids( (int) $source->term_id, $source->taxonomy ) ),
			'has_description' => '' !== trim( $source->description ),
			'meta_keys'       => count( (array) get_term_meta( $source->term_id ) ),
			'running'         => (bool) $this->find_job( (int) $source->term_id, $source->taxonomy ),
		);
	}

	/**
	 * Starts a merge, and finishes it when it is small enough or when asked to.
	 *
	 * @param int    $source_id Term to merge away.
	 * @param int    $target_id Term that receives the objects.
	 * @param string $taxonomy  Taxonomy name.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string $children   Child term handling: `target` or `parent`. Default from the filter.
	 *     @type bool   $finish_now Move every object in this request. WP-CLI does.
	 * }
	 * @return array{job_id: int, item_id: int, done: bool, moved: int, total: int, notes: string[], source_name: string, target_name: string, target_id: int}|\WP_Error
	 */
	public function merge( int $source_id, int $target_id, string $taxonomy, array $args = array() ) {
		$valid = $this->validate( $source_id, $target_id, $taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		list( $source, $target ) = $valid;

		$job_id = $this->find_job( $source_id, $source->taxonomy );
		if ( $job_id ) {
			$job = $this->job( $job_id );
			if ( (int) ( $job['target_id'] ?? 0 ) !== $target_id ) {
				/* translators: %s: term name. */
				return new \WP_Error( 'axle_category_tag_trash_running', sprintf( __( '"%s" is already being merged into another term. Wait for that to finish.', 'axle-category-tag-trash' ), $source->name ) );
			}
		} else {
			$children = isset( $args['children'] ) && in_array( $args['children'], self::children_policies(), true ) ? (string) $args['children'] : self::default_children_policy( $source, $target );

			/**
			 * Fires before a merge starts.
			 *
			 * @param \WP_Term $source Term merged away.
			 * @param \WP_Term $target Term that receives the objects.
			 * @param string   $children How child terms are handled: `target` or `parent`.
			 */
			do_action( 'axle_category_tag_trash_before_merge', $source, $target, $children );

			$job_id = $this->create_job( $source, $target, $children );
			if ( ! $job_id ) {
				return new \WP_Error( 'axle_category_tag_trash_db', __( 'Could not start the merge.', 'axle-category-tag-trash' ) );
			}
		}

		$result = $this->step( $job_id, ! empty( $args['finish_now'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['done'] ) {
			$this->defer( $job_id );
		}

		return $result;
	}

	/**
	 * Cron callback: moves the next batch of a running merge.
	 *
	 * @param int $job_id Merge job ID.
	 */
	public function continue_merge( $job_id ): void {
		$job_id = (int) $job_id;
		if ( ! $this->job( $job_id ) ) {
			return;
		}

		$result = $this->step( $job_id, false );
		if ( is_wp_error( $result ) ) {
			// Leave the job where it is: Site Health reports it and `wp axle-category-tag-trash merge` resumes it.
			return;
		}

		if ( ! $result['done'] ) {
			$this->defer( $job_id );
		}
	}

	/**
	 * Merges that are still moving objects.
	 *
	 * @return array<int, array<string, mixed>> Job data keyed by job ID.
	 */
	public function running(): array {
		$ids = get_posts(
			array(
				'post_type'   => Trash::POST_TYPE,
				'post_status' => Trash::STATUS_MERGING,
				'numberposts' => 50,
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$jobs = array();
		foreach ( $ids as $job_id ) {
			$job = $this->job( (int) $job_id );
			if ( $job ) {
				$jobs[ (int) $job_id ] = $job + array( 'job_id' => (int) $job_id );
			}
		}

		return $jobs;
	}

	/**
	 * Data of a running merge.
	 *
	 * @param int $job_id Merge job ID.
	 * @return array<string, mixed>|null
	 */
	public function job( int $job_id ): ?array {
		if ( Trash::POST_TYPE !== get_post_type( $job_id ) || Trash::STATUS_MERGING !== get_post_status( $job_id ) ) {
			return null;
		}

		$job = get_post_meta( $job_id, self::JOB_KEY, true );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * The running merge of a source term.
	 *
	 * @param int    $source_id Source term ID.
	 * @param string $taxonomy  Taxonomy name.
	 * @return int Job ID, or 0.
	 */
	public function find_job( int $source_id, string $taxonomy ): int {
		foreach ( $this->running() as $job_id => $job ) {
			if ( (int) $job['source_id'] === $source_id && $job['taxonomy'] === $taxonomy ) {
				return $job_id;
			}
		}

		return 0;
	}

	/**
	 * Saves a new job.
	 *
	 * @param \WP_Term $source   Source term.
	 * @param \WP_Term $target   Target term.
	 * @param string   $children Child term handling.
	 * @return int Job ID, or 0.
	 */
	private function create_job( \WP_Term $source, \WP_Term $target, string $children ): int {
		$job_id = wp_insert_post(
			array(
				'post_type'   => Trash::POST_TYPE,
				'post_status' => Trash::STATUS_MERGING,
				'post_title'  => wp_slash( $source->name ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $job_id ) ) {
			return 0;
		}

		update_post_meta(
			$job_id,
			self::JOB_KEY,
			wp_slash(
				array(
					'source_id'    => (int) $source->term_id,
					'source_tt_id' => (int) $source->term_taxonomy_id,
					'source_name'  => $source->name,
					'target_id'    => (int) $target->term_id,
					'target_tt_id' => (int) $target->term_taxonomy_id,
					'target_name'  => $target->name,
					'target_slug'  => $target->slug,
					'taxonomy'     => $source->taxonomy,
					'children'     => $children,
					'total'        => $this->count_objects( (int) $source->term_taxonomy_id ),
					'moved'        => 0,
				)
			)
		);

		return (int) $job_id;
	}

	/**
	 * Runs a merge forward: moves objects, then finishes it once none are left.
	 *
	 * @param int  $job_id     Merge job ID.
	 * @param bool $finish_now Move every object in this request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function step( int $job_id, bool $finish_now ) {
		$job = $this->job( $job_id );
		if ( ! $job ) {
			return new \WP_Error( 'axle_category_tag_trash_missing', __( 'That merge is no longer running.', 'axle-category-tag-trash' ) );
		}

		if ( ! $this->lock( $job_id ) ) {
			return new \WP_Error( 'axle_category_tag_trash_busy', __( 'That merge is already running. Wait a minute and try again.', 'axle-category-tag-trash' ) );
		}

		$source = get_term( (int) $job['source_id'], $job['taxonomy'] );
		$target = get_term( (int) $job['target_id'], $job['taxonomy'] );
		if ( ! $source instanceof \WP_Term || ! $target instanceof \WP_Term ) {
			$this->unlock( $job_id );
			wp_delete_post( $job_id, true );
			return new \WP_Error( 'axle_category_tag_trash_missing_term', __( 'One of the terms was deleted while the merge ran, so it was stopped.', 'axle-category-tag-trash' ) );
		}

		$budget = $finish_now ? PHP_INT_MAX : $this->batch_size();
		$spent  = 0;
		$moved  = 0;

		wp_defer_term_counting( true );
		while ( $spent < $budget ) {
			$batch = $this->move_batch( $job_id, $job, (int) min( Snapshot::CHUNK, $budget - $spent ) );
			if ( is_wp_error( $batch ) ) {
				wp_defer_term_counting( false );
				$this->unlock( $job_id );
				return $batch;
			}
			if ( ! $batch ) {
				break;
			}
			$spent += $batch;
			$moved += $batch;
		}
		wp_defer_term_counting( false );

		$job['moved'] = (int) $job['moved'] + $moved;
		update_post_meta( $job_id, self::JOB_KEY, wp_slash( $job ) );

		$done = ! $this->has_unmoved( (int) $job['source_tt_id'], (int) $job['target_tt_id'] );
		if ( ! $done ) {
			$this->unlock( $job_id );
			return $this->summary( $job_id, $job, 0, false, array() );
		}

		$result = $this->finish( $job_id, $job, $source, $target );
		$this->unlock( $job_id );

		return $result;
	}

	/**
	 * Moves one batch of objects that do not have the target yet.
	 *
	 * Looks for source rows with no target row beside them, so it needs no
	 * cursor: a batch that was cut short, or an object added to the source
	 * while the merge ran, is simply found by the next one.
	 *
	 * @param int                  $job_id Merge job ID.
	 * @param array<string, mixed> $job    Job data.
	 * @param int                  $limit  Most objects to move.
	 * @return int|\WP_Error Objects moved, 0 when none are left.
	 */
	private function move_batch( int $job_id, array $job, int $limit ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.object_id, s.term_order FROM {$wpdb->term_relationships} s
				LEFT JOIN {$wpdb->term_relationships} t ON t.object_id = s.object_id AND t.term_taxonomy_id = %d
				WHERE s.term_taxonomy_id = %d AND t.object_id IS NULL
				ORDER BY s.object_id ASC LIMIT %d",
				(int) $job['target_tt_id'],
				(int) $job['source_tt_id'],
				max( 1, $limit )
			),
			ARRAY_N
		);

		if ( ! $rows ) {
			return 0;
		}

		$ids    = array();
		$values = array();
		foreach ( $rows as $row ) {
			$ids[] = (int) $row[0];
			array_push( $values, (int) $row[0], (int) $job['target_tt_id'], (int) $row[1] );
		}

		// Save which objects gained the target first. If the request dies before the insert,
		// Undo only tries to take the target off objects that do not have it.
		foreach ( array_chunk( $ids, Snapshot::CHUNK ) as $chunk ) {
			add_post_meta( $job_id, Trash::GAINED_KEY, Snapshot::pack_ids( $chunk ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '(%d, %d, %d)' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the batch size.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order ) VALUES {$placeholders}", $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( ! $inserted ) {
			return new \WP_Error( 'axle_category_tag_trash_db', __( 'The database refused to move the posts to the other term. The merge was paused.', 'axle-category-tag-trash' ) );
		}

		foreach ( (array) get_taxonomy( (string) $job['taxonomy'] )->object_type as $object_type ) {
			clean_object_term_cache( $ids, $object_type );
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hooks, so search indexes and caches pick up the change.
		foreach ( $ids as $object_id ) {
			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'added_term_relationship', $object_id, (int) $job['target_tt_id'], (string) $job['taxonomy'] );
			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'set_object_terms', $object_id, array( (int) $job['target_id'] ), array( (int) $job['target_tt_id'] ), (string) $job['taxonomy'], true, array() );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		/**
		 * Fires after a batch of objects is moved to the target term of a merge.
		 *
		 * @param int   $target_id Target term ID.
		 * @param int   $source_id Source term ID.
		 * @param int[] $object_ids Objects moved in this batch.
		 */
		do_action( 'axle_category_tag_trash_merge_batch_moved', (int) $job['target_id'], (int) $job['source_id'], $ids );

		return count( $ids );
	}

	/**
	 * Last step: menu links, child terms, then the source goes to the trash.
	 *
	 * @param int                  $job_id Merge job ID.
	 * @param array<string, mixed> $job    Job data.
	 * @param \WP_Term             $source Source term.
	 * @param \WP_Term             $target Target term.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function finish( int $job_id, array $job, \WP_Term $source, \WP_Term $target ) {
		$notes    = array();
		$taxonomy = (string) $job['taxonomy'];

		wp_update_term_count_now( array( (int) $job['target_tt_id'] ), $taxonomy );

		$menu_items = array();
		foreach ( $this->menu_item_ids( (int) $source->term_id, $taxonomy ) as $menu_item_id ) {
			update_post_meta( $menu_item_id, '_menu_item_object_id', (string) $target->term_id );
			$menu_items[] = $menu_item_id;
		}

		$reparented = array();
		if ( self::CHILDREN_TARGET === $job['children'] ) {
			$child_ids = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'parent'     => $source->term_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			foreach ( is_wp_error( $child_ids ) ? array() : array_map( 'intval', $child_ids ) as $child_id ) {
				$new_parent = $this->new_parent( $child_id, $source, $target );
				$updated    = wp_update_term( $child_id, $taxonomy, array( 'parent' => $new_parent ) );
				if ( is_wp_error( $updated ) ) {
					$child   = get_term( $child_id, $taxonomy );
					$notes[] = sprintf(
						/* translators: %s: term name. */
						__( '"%s" stays one level up, because the target already has a child with that name.', 'axle-category-tag-trash' ),
						$child instanceof \WP_Term ? $child->name : (string) $child_id
					);
					continue;
				}
				$reparented[] = array(
					'id'   => $child_id,
					'to'   => $new_parent,
					'from' => (int) $source->term_id,
				);
			}
		}

		$item_id = 0;
		$catch   = static function ( $trashed_id ) use ( &$item_id ): void {
			$item_id = (int) $trashed_id;
		};
		add_action( 'axle_category_tag_trash_trashed', $catch, 10, 1 );
		Snapshotter::quiet( true );
		$deleted = wp_delete_term( (int) $source->term_id, $taxonomy );
		Snapshotter::quiet( false );
		remove_action( 'axle_category_tag_trash_trashed', $catch, 10 );

		if ( ! $deleted || is_wp_error( $deleted ) ) {
			// Put things back the way the term screens expect them.
			foreach ( $menu_items as $menu_item_id ) {
				update_post_meta( $menu_item_id, '_menu_item_object_id', (string) $source->term_id );
			}
			return new \WP_Error( 'axle_category_tag_trash_delete', __( 'The posts were moved, but the old term could not be deleted. Run the merge again to finish it.', 'axle-category-tag-trash' ) );
		}

		if ( $item_id ) {
			$this->attach_merge( $job_id, $item_id, $job, $reparented, $menu_items );
		} else {
			$notes[] = __( 'The old term was not kept in the trash, so this merge cannot be undone.', 'axle-category-tag-trash' );
		}

		wp_delete_post( $job_id, true );

		/**
		 * Fires after a term is merged into another and the old term is in the trash.
		 *
		 * @param int      $item_id Trash item ID of the merged-away term. 0 when it was not kept.
		 * @param \WP_Term $source  The term that was merged away. It no longer exists.
		 * @param \WP_Term $target  The term that received the objects.
		 * @param int      $moved   Objects that got the target through the merge.
		 */
		do_action( 'axle_category_tag_trash_merged', $item_id, $source, $target, (int) $job['moved'] );

		return $this->summary( $job_id, $job, $item_id, true, $notes );
	}

	/**
	 * Saves what the merge did on the new trash item, so Undo can reverse it.
	 *
	 * @param int                  $job_id     Merge job ID.
	 * @param int                  $item_id    Trash item ID.
	 * @param array<string, mixed> $job        Job data.
	 * @param array                $reparented Child terms that were moved: id, from, to.
	 * @param int[]                $menu_items Menu items that now point at the target.
	 */
	private function attach_merge( int $job_id, int $item_id, array $job, array $reparented, array $menu_items ): void {
		global $wpdb;

		// Hand the list of objects that gained the target from the job to the trash item.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Moves meta rows by post ID in one query.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'post_id' => $item_id ),
			array(
				'post_id'  => $job_id,
				'meta_key' => Trash::GAINED_KEY,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		wp_cache_delete( $job_id, 'post_meta' );
		wp_cache_delete( $item_id, 'post_meta' );

		update_post_meta(
			$item_id,
			Trash::MERGE_KEY,
			wp_slash(
				array(
					'target_id'    => (int) $job['target_id'],
					'target_tt_id' => (int) $job['target_tt_id'],
					'target_name'  => (string) $job['target_name'],
					'target_slug'  => (string) $job['target_slug'],
					'moved'        => (int) $job['moved'],
					'reparented'   => $reparented,
					'menu_items'   => $menu_items,
					'merged_at'    => time(),
				)
			)
		);
	}

	/**
	 * Where a child of the source goes.
	 *
	 * Not under the target when the target sits inside that child, since a term
	 * cannot be its own ancestor. Such a child goes up to the source's parent.
	 *
	 * @param int      $child_id Child term ID.
	 * @param \WP_Term $source   Source term.
	 * @param \WP_Term $target   Target term.
	 * @return int New parent term ID.
	 */
	private function new_parent( int $child_id, \WP_Term $source, \WP_Term $target ): int {
		if ( $child_id === (int) $target->term_id || term_is_ancestor_of( $child_id, (int) $target->term_id, $source->taxonomy ) ) {
			return (int) $source->parent;
		}

		return (int) $target->term_id;
	}

	/**
	 * The result of a step.
	 *
	 * @param int                  $job_id  Merge job ID.
	 * @param array<string, mixed> $job     Job data.
	 * @param int                  $item_id Trash item ID, once done.
	 * @param bool                 $done    Whether the merge finished.
	 * @param string[]             $notes   Things to tell the user.
	 * @return array<string, mixed>
	 */
	private function summary( int $job_id, array $job, int $item_id, bool $done, array $notes ): array {
		return array(
			'job_id'      => $job_id,
			'item_id'     => $item_id,
			'done'        => $done,
			'moved'       => (int) $job['moved'],
			'total'       => (int) $job['total'],
			'notes'       => $notes,
			'source_name' => (string) $job['source_name'],
			'target_name' => (string) $job['target_name'],
			'target_id'   => (int) $job['target_id'],
		);
	}

	/**
	 * Hands the rest of a merge to WP-Cron.
	 *
	 * @param int $job_id Merge job ID.
	 */
	private function defer( int $job_id ): void {
		if ( ! wp_next_scheduled( self::HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $job_id ) );
		}
	}

	/**
	 * Objects moved per request or cron run.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		/**
		 * Filters how many objects a merge moves per request or cron run.
		 *
		 * @param int $size Objects per batch. Default 5000, at least 100.
		 */
		return max( 100, (int) apply_filters( 'axle_category_tag_trash_merge_batch_size', 5000 ) );
	}

	/**
	 * Objects attached to a term.
	 *
	 * @param int $tt_id Term taxonomy ID.
	 * @return int
	 */
	private function count_objects( int $tt_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count for the preview.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt_id ) );
	}

	/**
	 * Objects that have both terms.
	 *
	 * @param int $source_tt_id Source term taxonomy ID.
	 * @param int $target_tt_id Target term taxonomy ID.
	 * @return int
	 */
	private function count_shared( int $source_tt_id, int $target_tt_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count for the preview.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} s INNER JOIN {$wpdb->term_relationships} t ON t.object_id = s.object_id AND t.term_taxonomy_id = %d WHERE s.term_taxonomy_id = %d", $target_tt_id, $source_tt_id ) );
	}

	/**
	 * Whether any object of the source still lacks the target.
	 *
	 * @param int $source_tt_id Source term taxonomy ID.
	 * @param int $target_tt_id Target term taxonomy ID.
	 * @return bool
	 */
	private function has_unmoved( int $source_tt_id, int $target_tt_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checked once per step.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT s.object_id FROM {$wpdb->term_relationships} s LEFT JOIN {$wpdb->term_relationships} t ON t.object_id = s.object_id AND t.term_taxonomy_id = %d WHERE s.term_taxonomy_id = %d AND t.object_id IS NULL LIMIT 1", $target_tt_id, $source_tt_id ) );
	}

	/**
	 * Menu items that link to a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	private function menu_item_ids( int $term_id, string $taxonomy ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'   => 'nav_menu_item',
					'post_status' => 'any',
					'numberposts' => self::MENU_LINKS,
					'fields'      => 'ids',
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
			)
		);
	}

	/**
	 * Takes the lock for a merge, so two runs never overlap.
	 *
	 * @param int $job_id Merge job ID.
	 * @return bool Whether the lock was taken.
	 */
	private function lock( int $job_id ): bool {
		$name = 'axle_category_tag_trash_lock_' . $job_id;
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( $name ) > self::LOCK_TTL ) {
			delete_option( $name );
			return add_option( $name, time(), '', false );
		}

		return false;
	}

	/**
	 * Releases the lock for a merge.
	 *
	 * @param int $job_id Merge job ID.
	 */
	private function unlock( int $job_id ): void {
		delete_option( 'axle_category_tag_trash_lock_' . $job_id );
	}
}
