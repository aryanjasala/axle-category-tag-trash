<?php
/**
 * Tests for the per-taxonomy capability checks.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Snapshot;
use AryanX\Axle\CategoryTagTrash\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AccessTest extends TestCase {

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();

		$this->caps = array( 'manage_categories' );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return in_array( $cap, $this->caps, true );
			}
		);
		Functions\when( 'get_taxonomy' )->alias(
			static function ( $taxonomy ) {
				$caps = array(
					'category' => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
					),
					'product_brand' => array(
						'manage_terms' => 'manage_brands',
						'edit_terms'   => 'edit_brands',
						'delete_terms' => 'delete_brands',
					),
				);
				return isset( $caps[ $taxonomy ] ) ? (object) array( 'cap' => (object) $caps[ $taxonomy ] ) : false;
			}
		);
	}

	public function test_a_user_who_manages_categories_cannot_manage_a_custom_taxonomy(): void {
		$this->assertTrue( Access::can( 'category', Access::LIST ) );
		$this->assertFalse( Access::can( 'product_brand', Access::LIST ) );
		$this->assertFalse( Access::can( 'product_brand', Access::RESTORE ) );
		$this->assertFalse( Access::can( 'product_brand', Access::DELETE ) );
		$this->assertFalse( Access::can( 'product_brand', Access::MERGE ) );
	}

	public function test_each_kind_of_access_needs_its_own_capability(): void {
		$this->caps = array( 'manage_brands' );
		$this->assertTrue( Access::can( 'product_brand', Access::LIST ) );
		$this->assertFalse( Access::can( 'product_brand', Access::RESTORE ), 'restoring needs edit_terms' );
		$this->assertFalse( Access::can( 'product_brand', Access::DELETE ), 'deleting needs delete_terms' );

		$this->caps = array( 'manage_brands', 'edit_brands' );
		$this->assertTrue( Access::can( 'product_brand', Access::RESTORE ) );
		$this->assertFalse( Access::can( 'product_brand', Access::MERGE ), 'merging needs edit and delete' );

		$this->caps = array( 'manage_brands', 'edit_brands', 'delete_brands' );
		$this->assertTrue( Access::can( 'product_brand', Access::MERGE ) );
	}

	public function test_the_taxonomy_name_is_cleaned_before_the_check(): void {
		$this->assertSame( 'product_brandscript', Access::taxonomy_name( 'Product_Brand<script>' ) );
		$this->assertSame( '', Access::taxonomy_name( array( 'category' ) ) );
		$this->assertFalse( Access::can( '', Access::LIST ) );
		$this->assertFalse( Access::can( 'no such taxonomy', Access::LIST ) );
	}

	public function test_a_taxonomy_that_is_not_registered_is_for_site_admins_only(): void {
		$this->assertFalse( Access::can( 'gone', Access::LIST ) );

		$this->caps = array( 'manage_options' );
		$this->assertTrue( Access::can( 'gone', Access::LIST ) );
		$this->assertTrue( Access::can( 'gone', Access::DELETE ) );
		$this->assertFalse( Access::can( 'gone', Access::RESTORE ), 'it cannot be restored while its taxonomy is off' );
	}

	public function test_the_filter_can_take_access_away_or_give_it(): void {
		Filters\expectApplied( 'axle_category_tag_trash_can_access_taxonomy' )->once()->with( true, 'category', 'list', 5 )->andReturn( false );
		$this->assertFalse( Access::can( 'category', Access::LIST ) );
	}

	public function test_nobody_who_is_logged_out_gets_in_whatever_the_filter_says(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Filters\expectApplied( 'axle_category_tag_trash_can_access_taxonomy' )->once()->andReturn( true );

		$this->assertFalse( Access::can( 'category', Access::LIST ) );
	}

	public function test_filter_keeps_only_the_taxonomies_the_user_may_use(): void {
		$this->assertSame( array( 'category' ), Access::filter( array( 'category', 'product_brand', 'category', 'Product_Brand' ) ) );
	}

	public function test_a_trashed_term_follows_the_capabilities_of_its_own_taxonomy(): void {
		$trash                  = new Trash( new Settings() );
		$brand                  = new Snapshot();
		$brand->taxonomy        = 'product_brand';
		$category               = new Snapshot();
		$category->taxonomy     = 'category';

		$this->assertFalse( $trash->user_can_manage( $brand ) );
		$this->assertTrue( $trash->user_can_manage( $category ) );
		$this->assertTrue( $trash->user_can_manage( $category, Access::DELETE ) );
	}

	public function test_a_search_limited_to_no_taxonomy_finds_nothing_without_a_query(): void {
		$trash = new Trash( new Settings() );

		$this->assertSame(
			array(
				'ids'   => array(),
				'total' => 0,
			),
			$trash->find( array( 'taxonomies' => array() ) )
		);
		$this->assertSame( array(), \WP_Query::$made );
	}

	public function test_a_search_limited_to_some_taxonomies_asks_for_just_those(): void {
		\WP_Query::$queue = array(
			array(
				'posts'       => array( 7 ),
				'found_posts' => 1,
			),
		);
		$trash = new Trash( new Settings() );

		$found = $trash->find( array( 'taxonomies' => array( 'category' ) ) );

		$this->assertSame( array( 7 ), $found['ids'] );
		$clause = \WP_Query::$made[0]['meta_query'][0];
		$this->assertSame( Trash::TAXONOMY_KEY, $clause['key'] );
		$this->assertSame( array( 'category' ), $clause['value'] );
		$this->assertSame( 'IN', $clause['compare'] );
	}
}
