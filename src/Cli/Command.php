<?php
/**
 * `wp axle-category-tag-trash` commands.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Cli;

use AryanX\Axle\CategoryTagTrash\Purger;
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
	 * Sets up the command.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 * @param Purger   $purger   Purging and emptying.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Purger $purger ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
		$this->purger   = $purger;
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
					'taxonomy' => $assoc_args['taxonomy'] ?? '',
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
					'id'       => $item_id,
					'term_id'  => $snapshot->term_id,
					'name'     => $snapshot->name,
					'taxonomy' => $snapshot->taxonomy,
					'objects'  => $snapshot->total_objects(),
					'deleted'  => get_post_field( 'post_date_gmt', $item_id ),
				);
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'term_id', 'name', 'taxonomy', 'objects', 'deleted' ) );
	}

	/**
	 * Restores trashed terms.
	 *
	 * Re-attaches every post in this run, even for a term on a million posts, and finishes
	 * restores that were running in the background.
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
}
