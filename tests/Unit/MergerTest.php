<?php
/**
 * Tests for the rules of merging terms, and the abilities that expose it.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Abilities;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\Redirects;
use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class MergerTest extends TestCase {

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	/**
	 * Taxonomy filter result: whether terms are kept in the trash.
	 *
	 * @var bool
	 */
	private bool $kept = true;

	protected function setUp(): void {
		parent::setUp();

		$this->caps = array( 'manage_categories', 'delete_term', 'edit_term' );
		$this->kept = true;
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
				return in_array( $taxonomy, array( 'category', 'post_tag' ), true )
					? (object) array(
						'show_ui' => true,
						'cap'     => (object) array(
							'manage_terms' => 'manage_categories',
							'edit_terms'   => 'manage_categories',
							'delete_terms' => 'manage_categories',
						),
					)
					: false;
			}
		);
		$terms = array(
			1 => new \WP_Term(
				array(
					'term_id'  => 1,
					'name'     => 'Uncategorized',
					'taxonomy' => 'category',
				)
			),
			2 => new \WP_Term(
				array(
					'term_id'  => 2,
					'name'     => 'News',
					'taxonomy' => 'category',
				)
			),
			3 => new \WP_Term(
				array(
					'term_id'  => 3,
					'name'     => 'Press',
					'taxonomy' => 'category',
				)
			),
		);
		Functions\when( 'get_term' )->alias(
			static function ( $id ) use ( $terms ) {
				return $terms[ (int) $id ] ?? null;
			}
		);
		$this->options['default_category'] = 1;
	}

	private function merger(): Merger {
		return new Merger( new Trash( new Settings() ) );
	}

	public function test_a_term_cannot_be_merged_into_itself(): void {
		$result = $this->merger()->validate( 2, 2, 'category' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_category_tag_trash_same', $result->get_error_code() );
	}

	public function test_a_taxonomy_that_does_not_exist_is_refused(): void {
		$this->assertSame( 'axle_category_tag_trash_taxonomy', $this->merger()->validate( 2, 3, 'nope' )->get_error_code() );
	}

	public function test_the_default_category_cannot_be_merged_away_but_can_receive(): void {
		$this->assertSame( 'axle_category_tag_trash_default', $this->merger()->validate( 1, 2, 'category' )->get_error_code() );
		$this->assertIsArray( $this->merger()->validate( 2, 1, 'category' ) );
	}

	public function test_a_missing_term_is_refused(): void {
		$this->assertSame( 'axle_category_tag_trash_missing_term', $this->merger()->validate( 2, 99, 'category' )->get_error_code() );
	}

	public function test_a_merge_needs_the_taxonomy_and_both_terms_capabilities(): void {
		$this->caps = array( 'manage_categories', 'edit_term' );
		$this->assertSame( 'axle_category_tag_trash_forbidden', $this->merger()->validate( 2, 3, 'category' )->get_error_code() );

		$this->caps = array( 'delete_term', 'edit_term' );
		$this->assertSame( 'axle_category_tag_trash_forbidden', $this->merger()->validate( 2, 3, 'category' )->get_error_code() );
	}

	public function test_the_merge_filter_can_refuse_a_pair(): void {
		Filters\expectApplied( 'axle_category_tag_trash_can_merge' )->once()->andReturn( false );

		$this->assertSame( 'axle_category_tag_trash_forbidden', $this->merger()->validate( 2, 3, 'category' )->get_error_code() );
	}

	public function test_a_taxonomy_that_is_not_kept_in_the_trash_cannot_be_merged_because_it_could_not_be_undone(): void {
		Filters\expectApplied( 'axle_category_tag_trash_supports_taxonomy' )->andReturn( false );

		$this->assertSame( 'axle_category_tag_trash_unsupported', $this->merger()->validate( 2, 3, 'category' )->get_error_code() );
	}

	public function test_wp_cli_and_cron_are_not_held_to_user_capabilities(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->caps = array();

		$this->assertIsArray( $this->merger()->validate( 2, 3, 'category' ) );
	}

	public function test_child_terms_follow_the_filter_but_only_with_a_known_value(): void {
		$source = new \WP_Term();
		$target = new \WP_Term();

		$this->assertSame( 'target', Merger::default_children_policy( $source, $target ) );

		Filters\expectApplied( 'axle_category_tag_trash_merge_children' )->once()->andReturn( 'parent' );
		$this->assertSame( 'parent', Merger::default_children_policy( $source, $target ) );

		Filters\expectApplied( 'axle_category_tag_trash_merge_children' )->once()->andReturn( 'anywhere' );
		$this->assertSame( 'target', Merger::default_children_policy( $source, $target ) );
	}

	public function test_a_child_that_holds_the_target_goes_up_instead_of_under_it(): void {
		Functions\when( 'term_is_ancestor_of' )->alias(
			static function ( $ancestor, $descendant ) {
				return 10 === $ancestor && 3 === $descendant;
			}
		);
		$source = new \WP_Term(
			array(
				'term_id'  => 2,
				'parent'   => 8,
				'taxonomy' => 'category',
			)
		);
		$target = new \WP_Term(
			array(
				'term_id'  => 3,
				'taxonomy' => 'category',
			)
		);

		$merger = $this->merger();
		$method = new \ReflectionMethod( $merger, 'new_parent' );
		$method->setAccessible( true );

		$this->assertSame( 3, $method->invoke( $merger, 11, $source, $target ), 'an ordinary child goes under the target' );
		$this->assertSame( 8, $method->invoke( $merger, 10, $source, $target ), 'a child that contains the target goes to the source parent' );
		$this->assertSame( 8, $method->invoke( $merger, 3, $source, $target ), 'the target itself goes to the source parent' );
	}

	public function test_a_user_who_manages_tags_but_not_a_custom_taxonomy_cannot_list_or_merge_it(): void {
		$trash     = new Trash( new Settings() );
		$abilities = new Abilities( $trash, new Restorer( $trash ), $this->merger(), new Redirects() );

		$this->assertTrue( $abilities->can_merge( array( 'taxonomy' => 'category', 'source' => 2, 'target' => 3 ) ) );
		$this->assertFalse( $abilities->can_merge( array( 'taxonomy' => 'product_brand', 'source' => 2, 'target' => 3 ) ) );

		$result = $abilities->list_items( array( 'taxonomy' => 'product_brand' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_category_tag_trash_forbidden', $result->get_error_code() );
	}

	public function test_merging_needs_the_right_to_delete_the_source_and_edit_the_target(): void {
		$trash     = new Trash( new Settings() );
		$abilities = new Abilities( $trash, new Restorer( $trash ), $this->merger(), new Redirects() );
		$input     = array(
			'taxonomy' => 'category',
			'source'   => 2,
			'target'   => 3,
		);

		$this->caps = array( 'manage_categories', 'edit_term' );
		$this->assertFalse( $abilities->can_merge( $input ) );

		$this->caps = array( 'manage_categories', 'delete_term' );
		$this->assertFalse( $abilities->can_merge( $input ) );
	}
}
