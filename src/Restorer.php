<?php
/**
 * Puts trashed terms back.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a term, its meta, its objects, its children and its menu links.
 *
 * Reuses the original term ID when it is still free, so links that store the
 * ID (navigation blocks, shortcodes, widgets) work again.
 *
 * The term itself, its meta, children and menu links come back at once. Its
 * objects come back in batches: the first batch during the request, the rest
 * through WP-Cron, so a term on a million posts never times out a request.
 * WP-CLI restores everything at once.
 */
final class Restorer implements Service {

	/**
	 * Cron hook that re-attaches the next batch of objects.
	 */
	public const HOOK = 'axle_category_tag_trash_restore_batch';

	/**
	 * Seconds after which a background restore lock is treated as stale.
	 */
	private const LOCK_TTL = 600;

	/**
	 * Object IDs passed to axle_category_tag_trash_restored at most.
	 */
	private const REPORTED_IDS = 5000;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up restoring.
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
		add_action( self::HOOK, array( $this, 'continue_restore' ) );
	}

	/**
	 * Restores several trash items.
	 *
	 * @param int[] $item_ids Trash item IDs.
	 * @return array{restored: int[], notes: string[], errors: string[]} Restored term IDs, notes and errors.
	 */
	public function restore_many( array $item_ids ): array {
		$result = array(
			'restored' => array(),
			'notes'    => array(),
			'errors'   => array(),
		);

		foreach ( $item_ids as $item_id ) {
			$snapshot = $this->trash->get( (int) $item_id );
			if ( $snapshot && ! $this->trash->user_can_manage( $snapshot, Access::RESTORE ) ) {
				$result['errors'][] = sprintf(
					/* translators: %s: term name. */
					__( 'You are not allowed to restore "%s".', 'axle-category-tag-trash' ),
					$snapshot->name
				);
				continue;
			}

			$restored = $this->restore( (int) $item_id );
			if ( is_wp_error( $restored ) ) {
				$result['errors'][] = $restored->get_error_message();
				continue;
			}

			$result['restored'][] = $restored['term_id'];
			$result['notes']      = array_merge( $result['notes'], $restored['notes'] );
		}

		return $result;
	}

	/**
	 * Puts a trashed term back the way it was.
	 *
	 * @param int  $item_id    Trash item ID.
	 * @param bool $finish_now Re-attach every object in this request instead of in the background. WP-CLI uses this.
	 * @return array{term_id: int, notes: string[], background: bool}|\WP_Error
	 */
	public function restore( int $item_id, bool $finish_now = false ) {
		global $wpdb;

		$snapshot = $this->trash->get( $item_id );
		if ( ! $snapshot ) {
			return new \WP_Error( 'axle_category_tag_trash_missing', __( 'That term is no longer in Axle Category & Tag Trash.', 'axle-category-tag-trash' ) );
		}

		if ( $this->trash->is_restoring( $item_id ) ) {
			if ( $finish_now ) {
				return $this->finish_background( $item_id, $snapshot );
			}
			/* translators: %s: term name. */
			return new \WP_Error( 'axle_category_tag_trash_busy', sprintf( __( '"%s" is already being restored.', 'axle-category-tag-trash' ), $snapshot->name ) );
		}

		$taxonomy = $snapshot->taxonomy;
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'axle_category_tag_trash_taxonomy',
				sprintf(
					/* translators: %s: taxonomy name. */
					__( 'The "%s" taxonomy is not registered, so this term cannot be restored. Activate the plugin or theme that adds it, then try again.', 'axle-category-tag-trash' ),
					$taxonomy
				)
			);
		}

		$notes  = array();
		$parent = $snapshot->parent;
		if ( $parent && ! term_exists( $parent, $taxonomy ) ) {
			$parent = 0;
			/* translators: %s: term name. */
			$notes[] = sprintf( __( '"%s" was restored at the top level because its parent no longer exists.', 'axle-category-tag-trash' ), $snapshot->name );
		}

		$slug = $snapshot->slug;
		if ( get_term_by( 'slug', $slug, $taxonomy ) ) {
			$slug = wp_unique_term_slug(
				$slug,
				(object) array(
					'taxonomy' => $taxonomy,
					'parent'   => $parent,
				)
			);
			/* translators: 1: term name, 2: new slug. */
			$notes[] = sprintf( __( 'Another term now uses the slug of "%1$s", so it was restored as "%2$s".', 'axle-category-tag-trash' ), $snapshot->name, $slug );
		}

		// Inserting directly is the only way to keep the original IDs.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$term_row = array(
			'name'       => $snapshot->name,
			'slug'       => $slug,
			'term_group' => $snapshot->term_group,
		);
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $snapshot->term_id ) ) ) {
			$term_row['term_id'] = $snapshot->term_id;
		}

		if ( false === $wpdb->insert( $wpdb->terms, $term_row ) ) {
			return new \WP_Error( 'axle_category_tag_trash_db', __( 'The database refused to restore the term.', 'axle-category-tag-trash' ) );
		}

		$term_id = $term_row['term_id'] ?? (int) $wpdb->insert_id;
		if ( $term_id !== $snapshot->term_id ) {
			/* translators: %s: term name. */
			$notes[] = sprintf( __( '"%s" has a new ID because its old one was taken. Links that stored the old ID need updating.', 'axle-category-tag-trash' ), $snapshot->name );
		}

		$taxonomy_row = array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'description' => $snapshot->description,
			'parent'      => $parent,
			'count'       => 0,
		);
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $snapshot->term_taxonomy_id ) ) ) {
			$taxonomy_row['term_taxonomy_id'] = $snapshot->term_taxonomy_id;
		}

		if ( false === $wpdb->insert( $wpdb->term_taxonomy, $taxonomy_row ) ) {
			$wpdb->delete( $wpdb->terms, array( 'term_id' => $term_id ) );
			return new \WP_Error( 'axle_category_tag_trash_db', __( 'The database refused to restore the term.', 'axle-category-tag-trash' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$tt_id = $taxonomy_row['term_taxonomy_id'] ?? (int) $wpdb->insert_id;

		foreach ( $snapshot->meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_term_meta( $term_id, wp_slash( (string) $key ), wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		clean_term_cache( $term_id, $taxonomy );

		$this->restore_children( $snapshot, $term_id );
		if ( $this->restore_menu_links( $snapshot, $term_id ) < count( $snapshot->menu_items ) ) {
			/* translators: %s: term name. */
			$notes[] = sprintf( __( 'Some menu links to "%s" were not restored because their menu no longer exists.', 'axle-category-tag-trash' ), $snapshot->name );
		}

		$merge = $this->trash->merge_info( $item_id );
		if ( $merge ) {
			$this->undo_merge_links( $merge, $snapshot, $term_id );
			/* translators: 1: restored term name, 2: name of the term it was merged into. */
			$notes[] = sprintf( __( 'Merge undone. "%2$s" is taken off the items that only got it through the merge, and "%1$s" is back on all its items.', 'axle-category-tag-trash' ), $snapshot->name, $merge['target_name'] );
		}

		$state = array(
			'term_id' => $term_id,
			'tt_id'   => $tt_id,
			'phase'   => 'objects',
			'offset'  => 0,
		);
		if ( $merge ) {
			$state['target_tt_id'] = (int) $merge['target_tt_id'];
			$state['target_id']    = (int) $merge['target_id'];
		}

		$object_ids = array();
		$done       = $this->run_batches( $item_id, $snapshot, $state, $finish_now ? PHP_INT_MAX : $this->batch_size(), $object_ids );

		/** This action is documented in wp-includes/taxonomy.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so other plugins see the restored term.
		do_action( 'created_term', $term_id, $tt_id, $taxonomy, array() );

		/**
		 * Fires after a term is restored from Axle Category & Tag Trash.
		 *
		 * For a term with more objects than one batch, the rest are re-attached in
		 * the background afterwards: axle_category_tag_trash_objects_restored fires for every
		 * batch and axle_category_tag_trash_restore_complete once they are all back.
		 *
		 * @param int      $term_id    Restored term ID.
		 * @param string   $taxonomy   Taxonomy name.
		 * @param Snapshot $snapshot   What was saved when the term was deleted.
		 * @param int[]    $object_ids Objects re-attached in this request, at most 5,000.
		 */
		do_action( 'axle_category_tag_trash_restored', $term_id, $taxonomy, $snapshot, $object_ids );

		if ( $done ) {
			$this->finish( $item_id, $snapshot, $state );
		} else {
			$this->defer( $item_id, $state );
			$notes[] = sprintf(
				/* translators: 1: term name, 2: number of posts or other items. */
				__( '"%1$s" is back. Its %2$s items are being re-attached in the background.', 'axle-category-tag-trash' ),
				$snapshot->name,
				number_format_i18n( $snapshot->total_objects() )
			);
		}

		return array(
			'term_id'    => $term_id,
			'notes'      => $notes,
			'background' => ! $done,
		);
	}

	/**
	 * Cron callback: re-attaches the next batch of a background restore.
	 *
	 * @param int $item_id Trash item ID.
	 */
	public function continue_restore( $item_id ): void {
		$item_id  = (int) $item_id;
		$snapshot = $this->trash->get( $item_id );
		$state    = get_post_meta( $item_id, Trash::STATE_KEY, true );

		if ( ! $snapshot || ! is_array( $state ) || ! $this->trash->is_restoring( $item_id ) ) {
			return;
		}

		if ( ! $this->lock( $item_id ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $item_id ) );
			return;
		}

		$object_ids = array();
		if ( $this->run_batches( $item_id, $snapshot, $state, $this->batch_size(), $object_ids ) ) {
			$this->finish( $item_id, $snapshot, $state );
		} else {
			update_post_meta( $item_id, Trash::STATE_KEY, $state );
			wp_schedule_single_event( time(), self::HOOK, array( $item_id ) );
		}

		$this->unlock( $item_id );
	}

	/**
	 * Finishes a background restore in this request. Used by WP-CLI.
	 *
	 * @param int      $item_id  Trash item ID.
	 * @param Snapshot $snapshot The item's snapshot.
	 * @return array{term_id: int, notes: string[], background: bool}|\WP_Error
	 */
	private function finish_background( int $item_id, Snapshot $snapshot ) {
		$state = get_post_meta( $item_id, Trash::STATE_KEY, true );
		if ( ! is_array( $state ) ) {
			return new \WP_Error( 'axle_category_tag_trash_state', __( 'The background restore lost its place. Delete the trash item and restore the term by hand.', 'axle-category-tag-trash' ) );
		}

		if ( ! $this->lock( $item_id ) ) {
			/* translators: %s: term name. */
			return new \WP_Error( 'axle_category_tag_trash_busy', sprintf( __( '"%s" is being restored by another process right now.', 'axle-category-tag-trash' ), $snapshot->name ) );
		}

		wp_clear_scheduled_hook( self::HOOK, array( $item_id ) );
		$object_ids = array();
		$this->run_batches( $item_id, $snapshot, $state, PHP_INT_MAX, $object_ids );
		$this->finish( $item_id, $snapshot, $state );
		$this->unlock( $item_id );

		return array(
			'term_id'    => (int) $state['term_id'],
			'notes'      => array(),
			'background' => false,
		);
	}

	/**
	 * Objects re-attached per request or cron run.
	 *
	 * @return int
	 */
	private function batch_size(): int {
		/**
		 * Filters how many objects a restore re-attaches per request or cron run.
		 *
		 * @param int $size Objects per batch. Default 5000.
		 */
		return max( Snapshot::CHUNK, (int) apply_filters( 'axle_category_tag_trash_restore_batch_size', 5000 ) );
	}

	/**
	 * Re-attaches objects, then takes back the default term, chunk by chunk.
	 *
	 * @param int                  $item_id    Trash item ID.
	 * @param Snapshot             $snapshot   The item's snapshot.
	 * @param array<string, mixed> $state      Restore state, moved forward in place.
	 * @param int                  $budget     Objects to handle before stopping.
	 * @param int[]                $object_ids Re-attached object IDs, filled up to REPORTED_IDS.
	 * @return bool Whether everything is done.
	 */
	private function run_batches( int $item_id, Snapshot $snapshot, array &$state, int $budget, array &$object_ids ): bool {
		$spent = 0;
		$done  = false;

		wp_defer_term_counting( true );
		while ( $spent < $budget ) {
			$keys  = array(
				'objects'  => Trash::OBJECTS_KEY,
				'fallback' => Trash::FALLBACK_KEY,
				'gained'   => Trash::GAINED_KEY,
			);
			$chunk = $this->trash->chunks( $item_id, $snapshot, $keys[ $state['phase'] ] ?? Trash::GAINED_KEY, (int) $state['offset'], 1 );

			if ( ! $chunk ) {
				if ( 'objects' === $state['phase'] ) {
					$state['phase']  = 'fallback';
					$state['offset'] = 0;
					continue;
				}
				if ( 'fallback' === $state['phase'] && ! empty( $state['target_tt_id'] ) ) {
					// Undoing a merge: the target term comes off what only got it through the merge.
					$state['phase']  = 'gained';
					$state['offset'] = 0;
					continue;
				}
				$done = true;
				break;
			}

			if ( 'objects' === $state['phase'] ) {
				$pairs = Snapshot::unpack_pairs( $chunk[0] );
				$ids   = $this->attach( $snapshot, (int) $state['term_id'], (int) $state['tt_id'], $pairs );
				if ( count( $object_ids ) < self::REPORTED_IDS ) {
					$object_ids = array_merge( $object_ids, array_slice( $ids, 0, self::REPORTED_IDS - count( $object_ids ) ) );
				}
				$spent += count( $pairs );
			} elseif ( 'fallback' === $state['phase'] ) {
				$ids = Snapshot::unpack_ids( $chunk[0] );
				$this->drop_fallback( $snapshot, (int) $state['term_id'], (int) $state['tt_id'], $ids );
				$spent += count( $ids );
			} else {
				$ids = Snapshot::unpack_ids( $chunk[0] );
				$this->release_target( $snapshot->taxonomy, (int) $state['target_tt_id'], $ids );
				$spent += count( $ids );
			}

			++$state['offset'];
		}
		wp_defer_term_counting( false );

		return $done;
	}

	/**
	 * Re-attaches one chunk of objects, keeping term order.
	 *
	 * Objects deleted while the term was in the trash are skipped.
	 *
	 * @param Snapshot                          $snapshot Trashed term.
	 * @param int                               $term_id  Restored term ID.
	 * @param int                               $tt_id    Restored term taxonomy ID.
	 * @param array<int, array{0: int, 1: int}> $pairs    [ object ID, term order ] pairs.
	 * @return int[] Object IDs re-attached.
	 */
	private function attach( Snapshot $snapshot, int $term_id, int $tt_id, array $pairs ): array {
		global $wpdb;

		$taxonomy     = $snapshot->taxonomy;
		$object_types = (array) get_taxonomy( $taxonomy )->object_type;

		if ( $pairs && ! array_diff( $object_types, get_post_types() ) ) {
			$ids          = wp_list_pluck( $pairs, 0 );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
			$existing = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )", $ids ) ) ) );
			$pairs    = array_values(
				array_filter(
					$pairs,
					static function ( array $pair ) use ( $existing ): bool {
						return isset( $existing[ (int) $pair[0] ] );
					}
				)
			);
		}

		if ( ! $pairs ) {
			return array();
		}

		$ids = array_map( 'intval', wp_list_pluck( $pairs, 0 ) );
		$old = $this->terms_in_taxonomy( $ids, $taxonomy );

		$values = array();
		foreach ( $pairs as $pair ) {
			array_push( $values, (int) $pair[0], $tt_id, (int) $pair[1] );
		}
		$placeholders = implode( ',', array_fill( 0, count( $pairs ), '(%d, %d, %d)' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->term_relationships} ( object_id, term_taxonomy_id, term_order ) VALUES {$placeholders}", $values ) );

		// Once per object type: core accepts an array here, but it passes it on to the
		// clean_object_term_cache action, and callbacks there (Yoast SEO's sitemap cache)
		// expect a string.
		foreach ( $object_types as $object_type ) {
			clean_object_term_cache( $ids, $object_type );
		}

		// Fire what wp_set_object_terms() fires, so search indexes (ElasticPress,
		// VIP Enterprise Search) and caches pick up the restored terms.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hooks.
		foreach ( $ids as $object_id ) {
			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'added_term_relationship', $object_id, $tt_id, $taxonomy );
			/** This action is documented in wp-includes/taxonomy.php */
			do_action( 'set_object_terms', $object_id, array( $term_id ), array( $tt_id ), $taxonomy, true, $old[ $object_id ] ?? array() );
		}
		// phpcs:enable

		/**
		 * Fires after a batch of objects is re-attached to a restored term.
		 *
		 * @param int    $term_id    Restored term ID.
		 * @param string $taxonomy   Taxonomy name.
		 * @param int[]  $object_ids Objects in this batch.
		 */
		do_action( 'axle_category_tag_trash_objects_restored', $term_id, $taxonomy, $ids );

		return $ids;
	}

	/**
	 * Term taxonomy IDs each object has in a taxonomy.
	 *
	 * @param int[]  $ids      Object IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, int[]>
	 */
	private function terms_in_taxonomy( array $ids, string $taxonomy ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s AND tr.object_id IN ( {$placeholders} )",
				array_merge( array( $taxonomy ), $ids )
			),
			ARRAY_N
		);
		// phpcs:enable

		$terms = array();
		foreach ( $rows as $row ) {
			$terms[ (int) $row[0] ][] = (int) $row[1];
		}

		return $terms;
	}

	/**
	 * Takes back the default term WordPress gave to objects that only had this term.
	 *
	 * Leaves objects alone if someone has changed their terms since: only
	 * objects whose terms are exactly the default and the restored term lose
	 * the default.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 * @param int      $tt_id    Restored term taxonomy ID.
	 * @param int[]    $ids      Objects that only had this term.
	 */
	private function drop_fallback( Snapshot $snapshot, int $term_id, int $tt_id, array $ids ): void {
		global $wpdb;

		$default = $snapshot->default_term;
		$term    = $default && $default !== $term_id ? get_term( $default, $snapshot->taxonomy ) : null;
		if ( ! $term instanceof \WP_Term || ! $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are built from the chunk size.
		$matched = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s AND tr.object_id IN ( {$placeholders} )
				GROUP BY tr.object_id
				HAVING COUNT(*) = 2 AND SUM( tr.term_taxonomy_id IN ( %d, %d ) ) = 2",
				array_merge( array( $snapshot->taxonomy ), $ids, array( (int) $term->term_taxonomy_id, $tt_id ) )
			)
		);
		// phpcs:enable

		foreach ( $matched as $object_id ) {
			wp_remove_object_terms( (int) $object_id, $default, $snapshot->taxonomy );
		}
	}

	/**
	 * Takes the target term of a merge off objects that only got it through the merge.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $tt_id    Term taxonomy ID of the merge target.
	 * @param int[]  $ids      Objects that got the target through the merge.
	 */
	private function release_target( string $taxonomy, int $tt_id, array $ids ): void {
		global $wpdb;

		if ( ! $ids || ! get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy ) ) {
			// The target was deleted since the merge: nothing to take it off.
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built from the chunk size.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN ( {$placeholders} )", array_merge( array( $tt_id ), $ids ) ) );

		foreach ( (array) get_taxonomy( $taxonomy )->object_type as $object_type ) {
			clean_object_term_cache( $ids, $object_type );
		}

		foreach ( $ids as $object_id ) {
			/** This action is documented in wp-includes/taxonomy.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			do_action( 'deleted_term_relationships', $object_id, array( $tt_id ), $taxonomy );
		}
	}

	/**
	 * Puts back what a merge changed besides the objects: child terms and menu links.
	 *
	 * @param array    $merge    The merge saved with the trash item.
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 */
	private function undo_merge_links( array $merge, Snapshot $snapshot, int $term_id ): void {
		foreach ( (array) ( $merge['reparented'] ?? array() ) as $move ) {
			$child = get_term( (int) $move['id'], $snapshot->taxonomy );
			if ( $child instanceof \WP_Term && (int) $child->parent === (int) $move['to'] ) {
				wp_update_term( (int) $move['id'], $snapshot->taxonomy, array( 'parent' => $term_id ) );
			}
		}

		foreach ( array_map( 'intval', (array) ( $merge['menu_items'] ?? array() ) ) as $menu_item_id ) {
			if ( (int) get_post_meta( $menu_item_id, '_menu_item_object_id', true ) === (int) $merge['target_id'] ) {
				update_post_meta( $menu_item_id, '_menu_item_object_id', (string) $term_id );
			}
		}
	}

	/**
	 * Final step: recounts the term, announces it and empties the trash item.
	 *
	 * @param int                  $item_id  Trash item ID.
	 * @param Snapshot             $snapshot The item's snapshot.
	 * @param array<string, mixed> $state    Restore state.
	 */
	private function finish( int $item_id, Snapshot $snapshot, array $state ): void {
		wp_update_term_count_now( array( (int) $state['tt_id'] ), $snapshot->taxonomy );
		if ( ! empty( $state['target_tt_id'] ) && get_term_by( 'term_taxonomy_id', (int) $state['target_tt_id'], $snapshot->taxonomy ) ) {
			wp_update_term_count_now( array( (int) $state['target_tt_id'] ), $snapshot->taxonomy );

			/**
			 * Fires once a merge is undone: the source term is back on all its objects.
			 *
			 * @param int      $term_id   Restored source term ID.
			 * @param int      $target_id Term ID the source was merged into.
			 * @param string   $taxonomy  Taxonomy name.
			 * @param Snapshot $snapshot  What was saved when the source was merged away.
			 */
			do_action( 'axle_category_tag_trash_merge_undone', (int) $state['term_id'], (int) ( $state['target_id'] ?? 0 ), $snapshot->taxonomy, $snapshot );
		}

		/**
		 * Fires once every object of a restored term is re-attached.
		 *
		 * @param int      $term_id  Restored term ID.
		 * @param string   $taxonomy Taxonomy name.
		 * @param Snapshot $snapshot What was saved when the term was deleted.
		 */
		do_action( 'axle_category_tag_trash_restore_complete', (int) $state['term_id'], $snapshot->taxonomy, $snapshot );

		$this->trash->remove( $item_id );
	}

	/**
	 * Hands the rest of a restore to WP-Cron.
	 *
	 * @param int                  $item_id Trash item ID.
	 * @param array<string, mixed> $state   Restore state.
	 */
	private function defer( int $item_id, array $state ): void {
		update_post_meta( $item_id, Trash::STATE_KEY, $state );
		wp_update_post(
			array(
				'ID'          => $item_id,
				'post_status' => Trash::STATUS_RESTORING,
			)
		);

		if ( ! wp_next_scheduled( self::HOOK, array( $item_id ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $item_id ) );
		}
	}

	/**
	 * Takes the lock for a background restore, so two cron runs never overlap.
	 *
	 * @param int $item_id Trash item ID.
	 * @return bool Whether the lock was taken.
	 */
	private function lock( int $item_id ): bool {
		$name = 'axle_category_tag_trash_lock_' . $item_id;
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
	 * Releases the lock for a background restore.
	 *
	 * @param int $item_id Trash item ID.
	 */
	private function unlock( int $item_id ): void {
		delete_option( 'axle_category_tag_trash_lock_' . $item_id );
	}

	/**
	 * Moves child terms back under the restored term.
	 *
	 * WordPress moves them up to the deleted term's parent. Children that were
	 * moved somewhere else since are left alone.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 */
	private function restore_children( Snapshot $snapshot, int $term_id ): void {
		foreach ( $snapshot->children as $child_id ) {
			$child = get_term( $child_id, $snapshot->taxonomy );
			if ( $child instanceof \WP_Term && (int) $child->parent === $snapshot->parent ) {
				wp_update_term( $child_id, $snapshot->taxonomy, array( 'parent' => $term_id ) );
			}
		}
	}

	/**
	 * Recreates menu links to the term, and points their sub-items back at them.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @param int      $term_id  Restored term ID.
	 * @return int Number of menu links restored.
	 */
	private function restore_menu_links( Snapshot $snapshot, int $term_id ): int {
		$restored = 0;

		foreach ( $snapshot->menu_items as $link ) {
			if ( ! wp_get_nav_menu_object( $link['menu_id'] ) ) {
				continue;
			}

			$item_id = wp_update_nav_menu_item(
				$link['menu_id'],
				0,
				array(
					'menu-item-object-id'   => $term_id,
					'menu-item-object'      => $snapshot->taxonomy,
					'menu-item-type'        => 'taxonomy',
					'menu-item-title'       => $link['title'],
					'menu-item-attr-title'  => $link['attr_title'],
					'menu-item-description' => $link['description'],
					'menu-item-target'      => $link['target'],
					'menu-item-classes'     => implode( ' ', $link['classes'] ),
					'menu-item-xfn'         => $link['xfn'],
					'menu-item-position'    => $link['position'],
					'menu-item-parent-id'   => $link['parent_id'] && get_post( $link['parent_id'] ) ? $link['parent_id'] : 0,
					'menu-item-status'      => 'publish',
				)
			);

			if ( is_wp_error( $item_id ) ) {
				continue;
			}

			// Core reads position 0 as "add at the end", but 0 is a real position for
			// items created outside the menu screen, so set it directly.
			if ( (int) get_post_field( 'menu_order', $item_id ) !== (int) $link['position'] ) {
				wp_update_post(
					array(
						'ID'         => $item_id,
						'menu_order' => $link['position'],
					)
				);
			}

			++$restored;

			foreach ( $link['children'] as $child_id ) {
				if ( (int) get_post_meta( $child_id, '_menu_item_menu_item_parent', true ) === (int) $link['id'] ) {
					update_post_meta( $child_id, '_menu_item_menu_item_parent', (string) $item_id );
				}
			}
		}

		return $restored;
	}
}
