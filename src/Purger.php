<?php
/**
 * Deletes trashed terms after the retention period.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Daily cron task that empties old items from the trash.
 */
final class Purger implements Service {

	public const HOOK = 'axle_category_tag_trash_purge';

	/**
	 * Cron hook that keeps emptying the trash after "Empty trash".
	 */
	public const EMPTY_HOOK = 'axle_category_tag_trash_empty';

	private const BATCH       = 100;
	private const MAX_BATCHES = 20;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up purging.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Hooks the cron task, and keeps it scheduled.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::EMPTY_HOOK, array( $this, 'continue_emptying' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily purge. Runs on activation and in wp-admin.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes the daily purge. Runs on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::EMPTY_HOOK );
	}

	/**
	 * Cron callback.
	 */
	public function run(): void {
		$this->purge();
	}

	/**
	 * Deletes items older than the retention period.
	 *
	 * @return int Number of items deleted.
	 */
	public function purge(): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $this->trash->settings()->retention_days() * DAY_IN_SECONDS );
		$deleted = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$expired = get_posts(
				array(
					'post_type'   => Trash::POST_TYPE,
					// Items being restored in the background are not purged.
					'post_status' => Trash::STATUS_TRASHED,
					'numberposts' => self::BATCH,
					'fields'      => 'ids',
					'date_query'  => array(
						array(
							'column' => 'post_date_gmt',
							'before' => $cutoff,
						),
					),
				)
			);

			if ( ! $expired ) {
				break;
			}

			foreach ( $expired as $item_id ) {
				$deleted += (int) $this->trash->delete( (int) $item_id );
			}
		}

		if ( $deleted ) {
			/**
			 * Fires after the daily clean-up deleted terms past their retention period.
			 *
			 * @param int $deleted Number of trashed terms deleted.
			 */
			do_action( 'axle_category_tag_trash_purged', $deleted );
		}

		return $deleted;
	}

	/**
	 * Deletes everything in the trash, a batch at a time.
	 *
	 * Deletes up to $limit items now. If more are left, the rest are deleted
	 * through WP-Cron, so emptying a big trash never times out a request.
	 *
	 * @param int           $limit    Items to delete in this request.
	 * @param callable|null $can_user Optional check run on each snapshot before it is deleted.
	 * @return array{deleted: int, remaining: int}
	 */
	public function empty_trash( int $limit = 200, ?callable $can_user = null ): array {
		$deleted = 0;

		while ( $deleted < $limit ) {
			$ids = $this->trash->find( array( 'per_page' => min( self::BATCH, $limit - $deleted ) ) )['ids'];
			if ( ! $ids ) {
				break;
			}

			$progress = 0;
			foreach ( $ids as $item_id ) {
				$snapshot = $this->trash->get( $item_id );
				if ( $can_user && ( ! $snapshot || ! $can_user( $snapshot ) ) ) {
					continue;
				}
				$progress += (int) $this->trash->delete( $item_id );
			}

			$deleted += $progress;
			if ( ! $progress ) {
				break;
			}
		}

		$remaining = $this->trash->find( array( 'per_page' => 1 ) )['total'];
		if ( $remaining && ! $can_user && ! wp_next_scheduled( self::EMPTY_HOOK ) ) {
			wp_schedule_single_event( time(), self::EMPTY_HOOK );
		}

		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
		);
	}

	/**
	 * Cron callback: keeps emptying the trash until it is empty.
	 */
	public function continue_emptying(): void {
		$this->empty_trash( 1000 );
	}
}
