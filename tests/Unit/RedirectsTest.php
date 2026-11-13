<?php
/**
 * Tests for redirects from old term addresses.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Abilities;
use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\Redirects;
use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Snapshot;
use AryanX\Axle\CategoryTagTrash\Trash;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class RedirectsTest extends TestCase {

	/**
	 * Terms by ID. Their archive path is "topics/<slug>", under the parent's path when there is one.
	 *
	 * @var array<int, \WP_Term>
	 */
	private array $terms = array();

	/**
	 * Capabilities the current user has.
	 *
	 * @var string[]
	 */
	private array $caps = array();

	/**
	 * Transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->caps       = array( 'manage_categories' );
		$this->transients = array();
		$this->terms      = array(
			1 => new \WP_Term(
				array(
					'term_id'  => 1,
					'name'     => 'Search',
					'slug'     => 'search',
					'taxonomy' => 'category',
				)
			),
			2 => new \WP_Term(
				array(
					'term_id'  => 2,
					'name'     => 'Links',
					'slug'     => 'links',
					'parent'   => 1,
					'taxonomy' => 'category',
				)
			),
			3 => new \WP_Term(
				array(
					'term_id'  => 3,
					'name'     => 'Brand',
					'slug'     => 'brand',
					'taxonomy' => 'product_brand',
				)
			),
		);

		$this->options['permalink_structure'] = '/%postname%/';
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
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
					'category'      => array(
						'manage_terms' => 'manage_categories',
						'edit_terms'   => 'manage_categories',
						'delete_terms' => 'manage_categories',
					),
					'product_brand' => array(
						'manage_terms' => 'manage_brands',
						'edit_terms'   => 'manage_brands',
						'delete_terms' => 'manage_brands',
					),
				);
				return isset( $caps[ $taxonomy ] ) ? (object) array(
					'public'  => true,
					'rewrite' => array( 'slug' => 'topics' ),
					'cap'     => (object) $caps[ $taxonomy ],
				) : false;
			}
		);
		Functions\when( 'get_term' )->alias(
			function ( $id ) {
				return $this->terms[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'get_term_link' )->alias(
			function ( $term ) {
				$path = 'topics/' . $term->slug;
				if ( $term->parent && isset( $this->terms[ $term->parent ] ) ) {
					$path = 'topics/' . $this->terms[ $term->parent ]->slug . '/' . $term->slug;
				}
				return 'https://example.test/' . $path . '/';
			}
		);
		Functions\when( 'user_trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/' ) . '/';
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/' ) . '/';
			}
		);
		Functions\when( 'is_taxonomy_hierarchical' )->alias(
			static function ( $taxonomy ) {
				return 'category' === $taxonomy;
			}
		);
		Functions\when( 'get_term_children' )->alias(
			function ( $id ) {
				$ids = array();
				foreach ( $this->terms as $term ) {
					if ( $term->parent === (int) $id ) {
						$ids[] = $term->term_id;
					}
				}
				return $ids;
			}
		);
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ) {
				return $value instanceof \WP_Error;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
	}

	private function redirects(): Redirects {
		return new Redirects();
	}

	public function test_a_path_is_lower_case_decoded_and_has_no_slashes_at_the_ends(): void {
		$this->assertSame( 'topics/seo', Redirects::normalize( '/Topics/SEO/' ) );
		$this->assertSame( 'topics/café', Redirects::normalize( 'topics/caf%C3%A9' ) );
	}

	public function test_a_term_path_is_relative_to_the_site_address(): void {
		$this->assertSame( 'topics/search', Redirects::term_path( $this->terms[1] ) );
		$this->assertSame( 'topics/search/links', Redirects::term_path( $this->terms[2] ) );
		$this->assertNull( Redirects::path_from_url( 'https://elsewhere.test/topics/search/' ), 'another site' );
		$this->assertNull( Redirects::path_from_url( 'https://example.test/?cat=3' ), 'plain permalinks' );
	}

	public function test_a_changed_slug_is_recorded_and_its_children_move_with_it(): void {
		$redirects = $this->redirects();
		$redirects->before_update( 1, 'category' );
		$this->terms[1]->slug = 'find';
		$redirects->after_update( 1, 1, 'category' );

		$all = Redirects::all();
		$this->assertSame( array( 'topics/search', 'topics/search/links' ), array_keys( $all ) );
		$this->assertSame( Redirects::KIND_RENAME, $all['topics/search']['kind'] );
		$this->assertSame( 1, $all['topics/search']['term'] );
		$this->assertSame( Redirects::KIND_MOVE, $all['topics/search/links']['kind'] );
		$this->assertSame( 2, $all['topics/search/links']['term'] );
	}

	public function test_nothing_is_recorded_when_the_address_did_not_change(): void {
		$redirects = $this->redirects();
		$redirects->before_update( 1, 'category' );
		$this->terms[1]->name = 'Searching';
		$redirects->after_update( 1, 1, 'category' );

		$this->assertSame( array(), Redirects::all() );
	}

	public function test_renaming_twice_still_leads_to_the_final_address(): void {
		$redirects = $this->redirects();

		$redirects->before_update( 2, 'category' );
		$this->terms[2]->slug = 'urls';
		$redirects->after_update( 2, 2, 'category' );
		$redirects->before_update( 2, 'category' );
		$this->terms[2]->slug = 'addresses';
		$redirects->after_update( 2, 2, 'category' );

		$all = Redirects::all();
		$this->assertArrayHasKey( 'topics/search/links', $all );
		$this->assertArrayHasKey( 'topics/search/urls', $all );
		foreach ( $all as $key => $entry ) {
			$this->assertSame( 'https://example.test/topics/search/addresses/', Redirects::target_url( $key, $entry ) );
		}
	}

	public function test_a_term_that_returns_to_an_old_address_drops_that_redirect(): void {
		$redirects = $this->redirects();
		$redirects->before_update( 1, 'category' );
		$this->terms[1]->slug = 'find';
		$redirects->after_update( 1, 1, 'category' );
		$this->assertArrayHasKey( 'topics/search', Redirects::all() );

		$redirects->before_update( 1, 'category' );
		$this->terms[1]->slug = 'search';
		$redirects->after_update( 1, 1, 'category' );

		$all = Redirects::all();
		$this->assertArrayNotHasKey( 'topics/search', $all, 'the live term owns that address again' );
		$this->assertArrayHasKey( 'topics/find', $all );
	}

	public function test_a_merge_redirects_the_source_and_redirects_that_led_to_it(): void {
		$redirects = $this->redirects();
		$redirects->before_update( 2, 'category' );
		$this->terms[2]->slug = 'urls';
		$redirects->after_update( 2, 2, 'category' );

		$source = $this->terms[2];
		$target = new \WP_Term(
			array(
				'term_id'  => 9,
				'name'     => 'Web',
				'slug'     => 'web',
				'taxonomy' => 'category',
			)
		);
		$this->terms[9] = $target;
		unset( $this->terms[2] );
		$redirects->after_merge( 40, $source, $target );

		$all = Redirects::all();
		$this->assertSame( 9, $all['topics/search/urls']['term'] );
		$this->assertSame( Redirects::KIND_MERGE, $all['topics/search/urls']['kind'] );
		$this->assertSame( 9, $all['topics/search/links']['term'], 'the older address follows the merge, no chain' );
	}

	public function test_undoing_a_merge_takes_the_redirects_back(): void {
		$redirects = $this->redirects();
		$redirects->before_update( 2, 'category' );
		$this->terms[2]->slug = 'urls';
		$redirects->after_update( 2, 2, 'category' );

		$source = $this->terms[2];
		$target = new \WP_Term(
			array(
				'term_id'  => 9,
				'name'     => 'Web',
				'slug'     => 'web',
				'taxonomy' => 'category',
			)
		);
		$this->terms[9] = $target;
		$redirects->after_merge( 40, $source, $target );

		$snapshot          = new Snapshot();
		$snapshot->term_id = 2;
		$redirects->after_restore( 2, 'category', $snapshot );

		$all = Redirects::all();
		$this->assertArrayNotHasKey( 'topics/search/urls', $all, 'the term is back at that address' );
		$this->assertSame( 2, $all['topics/search/links']['term'], 'the older address leads to the restored term again' );
	}

	public function test_a_deleted_term_redirects_only_when_a_target_was_chosen(): void {
		$redirects = $this->redirects();

		$redirects->before_delete( 3, 'product_brand' );
		$redirects->after_delete( 3, 3, 'product_brand' );
		$this->assertSame( array(), Redirects::all(), 'no target, no redirect' );

		Functions\when( 'get_term_meta' )->justReturn( '1' );
		$this->terms[3]->taxonomy = 'category';
		$redirects->before_delete( 3, 'category' );
		unset( $this->terms[3] );
		$this->terms[1]->slug = 'search';
		$redirects->after_delete( 3, 3, 'category' );

		$all = Redirects::all();
		$this->assertSame( array( 'topics/brand' ), array_keys( $all ) );
		$this->assertSame( 1, $all['topics/brand']['term'] );
		$this->assertSame( Redirects::KIND_TRASH, $all['topics/brand']['kind'] );
	}

	public function test_feeds_and_paged_addresses_match_the_redirect_of_their_base(): void {
		$this->options[ Redirects::OPTION ] = array(
			'topics/seo' => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_RENAME,
				'created'  => 100,
			),
		);

		$this->assertSame( '', Redirects::match( 'topics/seo' )['tail'] );
		$this->assertSame( '/page/3', Redirects::match( 'topics/seo/page/3' )['tail'] );
		$this->assertSame( '/feed', Redirects::match( 'topics/seo/feed' )['tail'] );
		$this->assertSame( '/feed/atom', Redirects::match( 'topics/seo/feed/atom' )['tail'] );
		$this->assertSame( '/page/2/feed', Redirects::match( 'topics/seo/page/2/feed' )['tail'] );
		$this->assertNull( Redirects::match( 'topics/other' ) );
		$this->assertNull( Redirects::match( 'topics/seo/extra' ) );

		$match = Redirects::match( 'topics/seo/page/3' );
		$this->assertSame( 'https://example.test/topics/search/page/3/', Redirects::target_url( $match['key'], $match['entry'], $match['tail'] ) );
	}

	public function test_a_redirect_to_a_deleted_term_has_no_address(): void {
		$entry = array(
			'taxonomy' => 'category',
			'term'     => 77,
			'kind'     => Redirects::KIND_MERGE,
			'created'  => 1,
		);

		$this->assertSame( '', Redirects::target_url( 'topics/gone', $entry ) );
	}

	public function test_the_target_address_has_a_filter(): void {
		$this->options[ Redirects::OPTION ] = array(
			'topics/seo' => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_RENAME,
				'created'  => 100,
			),
		);
		Filters\expectApplied( 'axle_category_tag_trash_redirect_target_url' )->once()->andReturn( 'https://example.test/custom/' );

		$match = Redirects::match( 'topics/seo' );
		$this->assertSame( 'https://example.test/custom/', Redirects::target_url( $match['key'], $match['entry'] ) );
	}

	public function test_redirects_can_be_switched_off(): void {
		Filters\expectApplied( 'axle_category_tag_trash_redirects_enabled' )->andReturn( false );

		$redirects = $this->redirects();
		$redirects->before_update( 1, 'category' );
		$this->terms[1]->slug = 'find';
		$redirects->after_update( 1, 1, 'category' );

		$this->assertSame( array(), Redirects::all() );
	}

	public function test_a_taxonomy_needs_pretty_permalinks(): void {
		$this->assertTrue( Redirects::supports( 'category' ) );
		$this->options['permalink_structure'] = '';
		$this->assertFalse( Redirects::supports( 'category' ) );
	}

	public function test_the_oldest_redirects_go_first_above_the_limit(): void {
		Filters\expectApplied( 'axle_category_tag_trash_redirect_limit' )->andReturn( 10 );
		$redirects = $this->redirects();

		foreach ( range( 1, 12 ) as $i ) {
			$this->terms[1]->slug = 'slug' . $i;
			$redirects->record( 'topics/old' . $i, $this->terms[1], Redirects::KIND_RENAME );
		}

		$this->assertCount( 10, Redirects::all() );
	}

	public function test_a_removed_redirect_comes_back_with_undo_and_keeps_its_hits(): void {
		$this->options[ Redirects::OPTION ]      = array(
			'topics/seo' => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_RENAME,
				'created'  => 100,
			),
		);
		$this->options[ Redirects::HITS_OPTION ] = array(
			'topics/seo' => array(
				'n'    => 7,
				'last' => 200,
			),
		);

		$redirects = $this->redirects();
		Actions\expectDone( 'axle_category_tag_trash_redirect_removed' )->once();
		$removed = $redirects->remove( array( 'topics/seo', 'topics/missing' ) );
		$redirects->stash( 5, $removed );

		$this->assertSame( array(), Redirects::all() );
		$this->assertSame( array(), Redirects::hits() );

		$this->assertSame( 1, $redirects->undo( 5 ) );
		$this->assertArrayHasKey( 'topics/seo', Redirects::all() );
		$this->assertSame( 7, Redirects::hits()['topics/seo']['n'] );
		$this->assertSame( 0, $redirects->undo( 5 ), 'the undo is used up' );
	}

	public function test_undo_only_brings_back_taxonomies_the_user_may_change(): void {
		$this->options[ Redirects::OPTION ] = array(
			'topics/brand-old' => array(
				'taxonomy' => 'product_brand',
				'term'     => 3,
				'kind'     => Redirects::KIND_MERGE,
				'created'  => 100,
			),
		);

		$redirects = $this->redirects();
		$redirects->stash( 5, $redirects->remove( array( 'topics/brand-old' ) ) );

		$this->assertSame( 0, $redirects->undo( 5 ), 'a tag manager has no manage_brands' );
		$this->assertSame( array(), Redirects::all() );
	}

	public function test_the_list_only_holds_taxonomies_the_user_may_see(): void {
		$this->options[ Redirects::OPTION ] = array(
			'topics/seo'       => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_RENAME,
				'created'  => 200,
			),
			'topics/brand-old' => array(
				'taxonomy' => 'product_brand',
				'term'     => 3,
				'kind'     => Redirects::KIND_MERGE,
				'created'  => 100,
			),
		);

		$found = Redirects::find();
		$this->assertSame( 1, $found['total'] );
		$this->assertSame( 'topics/seo', $found['items'][0]['path'] );

		$this->assertSame( 2, Redirects::find( array( 'check_access' => false ) )['total'], 'WP-CLI has no user' );

		$this->caps = array( 'manage_categories', 'manage_brands' );
		$this->assertSame( 2, Redirects::find()['total'] );
		$this->assertSame( 1, Redirects::find( array( 'search' => 'brand' ) )['total'] );
	}

	public function test_abilities_check_the_taxonomy_of_the_redirect(): void {
		$this->options[ Redirects::OPTION ] = array(
			'topics/brand-old' => array(
				'taxonomy' => 'product_brand',
				'term'     => 3,
				'kind'     => Redirects::KIND_MERGE,
				'created'  => 100,
			),
		);
		$trash     = new Trash( new Settings() );
		$abilities = new Abilities( $trash, new Restorer( $trash ), new Merger( $trash ), $this->redirects() );

		$this->assertFalse( $abilities->can_remove_redirect( array( 'path' => 'topics/brand-old' ) ) );
		$this->assertTrue( $abilities->can_remove_redirect( array( 'path' => 'topics/not-there' ) ), 'a missing redirect is reported by the ability' );

		$result = $abilities->remove_redirect( array( 'path' => 'topics/brand-old' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayHasKey( 'topics/brand-old', Redirects::all() );

		$result = $abilities->list_redirects( array( 'taxonomy' => 'product_brand' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 0, $abilities->list_redirects( array() )['total'] );

		$this->caps = array( 'manage_categories', 'manage_brands' );
		$this->assertTrue( $abilities->can_remove_redirect( array( 'path' => 'topics/brand-old' ) ) );
		$this->assertSame( array( 'removed' => true ), $abilities->remove_redirect( array( 'path' => 'topics/brand-old' ) ) );
	}

	public function test_access_for_redirects_needs_manage_and_edit_terms(): void {
		$this->assertTrue( Access::can( 'category', Access::REDIRECTS ) );
		$this->assertFalse( Access::can( 'product_brand', Access::REDIRECTS ) );
	}
}
