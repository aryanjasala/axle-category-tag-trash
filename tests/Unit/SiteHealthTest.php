<?php
/**
 * Tests for the Site Health test of stuck background jobs.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Health\SiteHealth;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Trash;
use Brain\Monkey\Functions;

final class SiteHealthTest extends TestCase {

	private function health(): SiteHealth {
		$trash = new Trash( new Settings() );

		return new SiteHealth( $trash, new Merger( $trash ) );
	}

	/**
	 * Makes these trash items the ones being restored, with no merges running.
	 *
	 * @param int[] $ids Trash item IDs.
	 */
	private function restoring( array $ids ): void {
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( $ids ) {
				return Trash::STATUS_RESTORING === $args['post_status'] ? $ids : array();
			}
		);
	}

	public function test_the_test_is_added_to_site_health(): void {
		$tests = $this->health()->add_test( array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'axle_category_tag_trash_jobs', $tests['direct'] );
	}

	public function test_nothing_stuck_is_good(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		$result = $this->health()->run();

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_a_restore_that_nothing_will_finish_is_reported(): void {
		$this->restoring( array( 31 ) );
		Functions\when( 'get_post_type' )->justReturn( Trash::POST_TYPE );
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$result = $this->health()->run();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'wp axle-category-tag-trash', $result['actions'] );
	}

	public function test_a_job_with_a_cron_event_or_a_lock_is_not_stuck(): void {
		$this->restoring( array( 31, 32 ) );
		Functions\when( 'get_post_type' )->justReturn( Trash::POST_TYPE );
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( $hook, $args ) {
				return array( 31 ) === $args ? time() + 60 : false;
			}
		);
		$this->options['axle_category_tag_trash_lock_32'] = time();

		$this->assertSame( 'good', $this->health()->run()['status'] );
	}
}
