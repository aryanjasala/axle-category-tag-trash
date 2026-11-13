<?php
/**
 * `wp axle-category-tag-trash` commands.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Cli;

use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\Purger;
use AryanX\Axle\CategoryTagTrash\Redirects;
use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, restores and deletes trashed terms.
 */
final class Command {

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Restore logic.
	 *
	 * @var Restorer
	 */
	private Restorer $restorer;

	/**
	 * Purging and emptying.
	 *
	 * @var Purger
	 */
	private Purger $purger;

	/**
	 * Merging terms.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Old term addresses that redirect.
	 *
	 * @var Redirects
	 */
	private Redirects $redirects;

	/**
	 * Sets up the command.
	 *
	 * @param Trash     $trash     Trashed terms.
	 * @param Restorer  $restorer  Restore logic.
	 * @param Purger    $purger    Purging and emptying.
	 * @param Merger    $merger    Merging terms.
	 * @param Redirects $redirects Old term addresses that redirect.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Purger $purger, Merger $merger, Redirects $redirects ) {
		$this->redirects = $redirects;
		$this->trash     = $trash;
		$this->restorer  = $restorer;
		$this->purger    = $purger;
		$this->merger    = $merger;
	}

	/**
	 * Lists trashed terms.
	 *
	 * ## OPTIONS
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Only this taxonomy.
	 *
	 * [--format=<format>]
	 * : table, csv, json, count or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$ids  = array();
		$page = 1;
		do {
			$found   = $this->trash->find(
				array(
					'taxonomy' => Access::taxonomy_name( $assoc_args['taxonomy'] ?? '' ),
					'per_page' => Trash::MAX_PER_PAGE,
					'page'     => $page++,
				)
			);
			$ids     = array_merge( $ids, $found['ids'] );
			$fetched = count( $found['ids'] );
		} while ( Trash::MAX_PER_PAGE === $fetched );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$rows = array();
		foreach ( $ids as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			if ( $snapshot ) {
				$rows[] = array(
					'id'          => $item_id,
					'term_id'     => $snapshot->term_id,
					'name'        => $snapshot->name,
					'taxonomy'    => $snapshot->taxonomy,
					'objects'     => $snapshot->total_objects(),
					'merged_into' => (int) ( $this->trash->merge_info( $item_id )['target_id'] ?? 0 ),
					'deleted'     => get_post_field( 'post_date_gmt', $item_id ),
				);
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'term_id', 'name', 'taxonomy', 'objects', 'merged_into', 'deleted' ) );
	}

	/**
	 * Merges one term into another, and puts the old term in the trash.
	 *
	 * Moves every post of <source> to <target>. Restore the trash item that
	 * this prints to undo it: the source comes back, and the target comes off
	 * only the posts that got it through the merge. Run it again for a merge
	 * that stopped, to finish it.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Term ID or slug to merge away.
	 *
	 * <target>
	 * : Term ID or slug to merge into.
	 *
	 * --taxonomy=<taxonomy>
	 * : Taxonomy of both terms, such as post_tag or category.
	 *
	 * [--children=<policy>]
	 * : What happens to the source's child terms: `target` moves them under the target, `parent` moves them up one level. Default `target`, changed by a filter.
	 *
	 * [--dry-run]
	 * : Show what would happen and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-category-tag-trash merge WordPress WordPress --taxonomy=post_tag --dry-run
	 *     wp axle-category-tag-trash merge 12 7 --taxonomy=category
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function merge( array $args, array $assoc_args ): void {
		$taxonomy = Access::taxonomy_name( $assoc_args['taxonomy'] ?? '' );
		$source   = $this->term_id( $args[0], $taxonomy );
		$target   = $this->term_id( $args[1], $taxonomy );
		$children = (string) ( $assoc_args['children'] ?? '' );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$preview = $this->merger->preview( $source, $target, $taxonomy, $children );
			if ( is_wp_error( $preview ) ) {
				\WP_CLI::error( $preview->get_error_message() );
			}

			\WP_CLI::line( sprintf( 'Would merge "%s" into "%s".', $preview['source_name'], $preview['target_name'] ) );
			\WP_CLI::line( sprintf( '  %d items on the source, %d already on the target, %d would move.', $preview['objects'], $preview['already'], $preview['moving'] ) );
			\WP_CLI::line( sprintf( '  %d child terms (%s), %d menu links.', $preview['children'], $preview['children_policy'], $preview['menu_links'] ) );
			\WP_CLI::line( '  The source goes to the trash. Restore it to undo the merge.' );
			return;
		}

		$result = $this->merger->merge(
			$source,
			$target,
			$taxonomy,
			array(
				'children'   => $children,
				'finish_now' => true,
			) 
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Merged "%s" into "%s". %d items moved.', $result['source_name'], $result['target_name'], $result['moved'] ) );
		foreach ( $result['notes'] as $note ) {
			\WP_CLI::line( '  ' . $note );
		}
		if ( $result['item_id'] ) {
			\WP_CLI::line( '  Undo: wp axle-category-tag-trash restore ' . $result['item_id'] );
		}
	}

	/**
	 * Finds a term ID from an ID or a slug.
	 *
	 * @param string $value    ID or slug.
	 * @param string $taxonomy Taxonomy name.
	 * @return int
	 */
	private function term_id( string $value, string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			\WP_CLI::error( 'Unknown taxonomy "' . $taxonomy . '". Pass --taxonomy=<taxonomy>.' );
		}

		$term = ctype_digit( $value ) ? get_term( (int) $value, $taxonomy ) : get_term_by( 'slug', $value, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			\WP_CLI::error( 'No term "' . $value . '" in ' . $taxonomy . '.' );
		}

		return (int) $term->term_id;
	}

	/**
	 * Restores trashed terms.
	 *
	 * Re-attaches every post in this run, even for a term on a million posts, and finishes
	 * restores that were running in the background. A term that was merged away comes back
	 * as an undo of the merge.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Trash item IDs from `wp axle-category-tag-trash list`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function restore( array $args ): void {
		foreach ( $args as $item_id ) {
			$result = $this->restorer->restore( (int) $item_id, true );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $item_id . ': ' . $result->get_error_message() );
				continue;
			}

			\WP_CLI::success( 'Restored term ' . $result['term_id'] . '.' );
			foreach ( $result['notes'] as $note ) {
				\WP_CLI::line( '  ' . $note );
			}
		}
	}

	/**
	 * Deletes trashed terms for good.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Trash item IDs from `wp axle-category-tag-trash list`.
	 *
	 * [--all]
	 * : Empty the whole trash.
	 *
	 * [--yes]
	 * : Skip the confirmation with --all.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['all'] ) ) {
			\WP_CLI::confirm( 'Delete everything in Axle Category & Tag Trash for good?', $assoc_args );
			$deleted = 0;
			do {
				$result   = $this->purger->empty_trash( 1000 );
				$deleted += $result['deleted'];
			} while ( $result['remaining'] && $result['deleted'] );
			\WP_CLI::success( 'Deleted ' . $deleted . ' trash items.' );
			return;
		}

		foreach ( array_map( 'intval', $args ) as $item_id ) {
			if ( $this->trash->delete( $item_id ) ) {
				\WP_CLI::success( 'Deleted trash item ' . $item_id . '.' );
			}
		}
	}

	/**
	 * Lists or removes the redirects from old term addresses.
	 *
	 * A redirect is added when a term's slug or parent changes, when a term is merged
	 * away, and when a term with a redirect target is deleted. To set a target before
	 * a delete, run `wp term meta update <term> axle_category_tag_trash_redirect_to <target-term-id>`.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : `list` shows the redirects, newest first. `remove` removes the ones named after it, by old path such as `topics/seo`.
	 *
	 * [<path>...]
	 * : Old paths to remove.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Only this taxonomy, for `list`.
	 *
	 * [--format=<format>]
	 * : table, csv, json, count or ids (the old paths).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-category-tag-trash redirects list --taxonomy=category
	 *     wp axle-category-tag-trash redirects remove topics/seo
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function redirects( array $args, array $assoc_args ): void {
		$action = (string) array_shift( $args );

		if ( 'remove' === $action ) {
			$keys = array();
			foreach ( $args as $path ) {
				$key = Redirects::resolve_key( (string) $path );
				if ( '' === $key ) {
					\WP_CLI::warning( 'No redirect from "' . $path . '".' );
					continue;
				}
				$keys[] = $key;
			}

			$removed = $this->redirects->remove( $keys );
			foreach ( array_keys( $removed ) as $key ) {
				\WP_CLI::success( 'Removed the redirect from ' . $key . '.' );
			}
			return;
		}

		if ( 'list' !== $action ) {
			\WP_CLI::error( 'Use "list" or "remove".' );
		}

		$items = array();
		$page  = 1;
		do {
			$found   = Redirects::find(
				array(
					'taxonomy'     => $assoc_args['taxonomy'] ?? '',
					'per_page'     => 500,
					'page'         => $page++,
					'check_access' => false,
				)
			);
			$items   = array_merge( $items, $found['items'] );
			$fetched = count( $found['items'] );
		} while ( 500 === $fetched );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'path' ) ) );
			return;
		}

		$rows = array();
		foreach ( $items as $row ) {
			$rows[] = array(
				'old'      => $row['path'],
				'new'      => $row['new_url'],
				'taxonomy' => $row['taxonomy'],
				'kind'     => $row['kind'],
				'hits'     => $row['hits'],
				'created'  => gmdate( 'Y-m-d H:i:s', (int) $row['created'] ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'old', 'new', 'taxonomy', 'kind', 'hits', 'created' ) );
	}
}
