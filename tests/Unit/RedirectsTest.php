<?php
/**
 * Tests for redirects from old term addresses.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Abilities;
use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\HitCounter;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\OldAddresses;
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
		// No persistent object cache, and nothing waiting to be written.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_cache_get' )->justReturn( false );

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
		$abilities = new Abilities( $trash, new Restorer( $trash ), new Merger( $trash ), $this->redirects(), new OldAddresses( $trash, $this->redirects() ) );

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

	/**
	 * Puts a trashed term with an old address in the stubbed trash.
	 *
	 * @param int    $item_id  Trash item ID.
	 * @param string $path     Old address.
	 * @param string $taxonomy Taxonomy.
	 */
	private function trashed( int $item_id, string $path, string $taxonomy = 'category' ): void {
		$snapshot           = new Snapshot();
		$snapshot->term_id  = 90 + $item_id;
		$snapshot->taxonomy = $taxonomy;
		$snapshot->name     = 'Recipes';
		$snapshot->slug     = 'recipes';
		$snapshot->path     = $path;

		Functions\when( 'get_post_type' )->justReturn( Trash::POST_TYPE );
		Functions\when( 'get_post_meta' )->justReturn( $snapshot->to_array() );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	private function old_addresses(): OldAddresses {
		return new OldAddresses( new Trash( new Settings() ), $this->redirects() );
	}

	public function test_bots_and_scripts_are_not_counted(): void {
		$this->assertTrue( OldAddresses::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
		$this->assertTrue( OldAddresses::is_bot( 'curl/8.4.0' ) );
		$this->assertTrue( OldAddresses::is_bot( '' ), 'no user agent' );
		$this->assertFalse( OldAddresses::is_bot( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15' ) );

		Filters\expectApplied( 'axle_category_tag_trash_bot_agents' )->once()->andReturn( array( 'safari' ) );
		$this->assertTrue( OldAddresses::is_bot( 'Mozilla/5.0 Safari/605' ), 'the list is filterable' );
	}

	public function test_a_request_matches_a_trashed_address_with_its_feed_or_page(): void {
		$map = array( 'topics/recipes' => 31 );

		$this->assertSame( 'topics/recipes', OldAddresses::key_for( 'topics/recipes', $map ) );
		$this->assertSame( 'topics/recipes', OldAddresses::key_for( 'topics/recipes/feed', $map ) );
		$this->assertSame( 'topics/recipes', OldAddresses::key_for( 'topics/recipes/page/3', $map ) );
		$this->assertNull( OldAddresses::key_for( 'topics/recipes-two', $map ) );
		$this->assertNull( OldAddresses::key_for( 'topics/other', $map ) );
	}

	public function test_a_trashed_address_is_kept_on_trash_and_dropped_with_its_counts_on_delete_or_restore(): void {
		$old      = $this->old_addresses();
		$snapshot = new Snapshot();
		$snapshot->path = 'topics/recipes';

		$old->add( 31, $snapshot );
		$this->assertSame( array( 'topics/recipes' => 31 ), OldAddresses::map() );

		$empty = new Snapshot();
		$old->add( 32, $empty );
		$this->assertCount( 1, OldAddresses::map(), 'a term with no known address is not watched' );

		$this->options[ OldAddresses::HITS_OPTION ] = array( 'topics/recipes' => array( 'n' => 3, 'last' => 9, 'd' => array() ) );
		$old->forget_item( 99, $snapshot );
		$this->assertCount( 1, OldAddresses::map(), 'another item with the same address does not remove it' );

		$old->forget_restored( 91, 'category', $snapshot );
		$this->assertSame( array(), OldAddresses::map() );
		$this->assertSame( array(), $this->options[ OldAddresses::HITS_OPTION ] );
	}

	public function test_a_bot_visit_to_a_trashed_address_writes_nothing(): void {
		$this->options[ OldAddresses::MAP_OPTION ] = array( 'topics/recipes' => 31 );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		$GLOBALS['wp']          = (object) array( 'request' => 'topics/recipes/' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';

		$this->old_addresses()->count_visit();

		$this->assertArrayNotHasKey( OldAddresses::HITS_OPTION, $this->options );
	}

	public function test_a_person_is_counted_once_a_minute_without_an_object_cache(): void {
		$this->options[ OldAddresses::MAP_OPTION ] = array( 'topics/recipes' => 31 );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		$GLOBALS['wp']          = (object) array( 'request' => 'topics/recipes/' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Safari/605';

		Actions\expectDone( 'axle_category_tag_trash_old_address_visited' )->twice();
		$old = $this->old_addresses();
		$old->count_visit();
		$old->count_visit();

		$row = $this->options[ OldAddresses::HITS_OPTION ]['topics/recipes'];
		$this->assertSame( 1, $row['n'], 'the second visit in the same minute does not write' );
		$this->assertSame( 1, array_sum( $row['d'] ) );
	}

	public function test_with_an_object_cache_visits_wait_in_the_cache_until_the_hourly_write(): void {
		$cache = array();
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_cache_add' )->alias(
			function ( $key, $value ) use ( &$cache ) {
				if ( isset( $cache[ $key ] ) ) {
					return false;
				}
				$cache[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_incr' )->alias(
			function ( $key, $by ) use ( &$cache ) {
				$cache[ $key ] = ( $cache[ $key ] ?? 0 ) + $by;
				return $cache[ $key ];
			}
		);
		Functions\when( 'wp_cache_decr' )->alias(
			function ( $key, $by ) use ( &$cache ) {
				$cache[ $key ] -= $by;
				return $cache[ $key ];
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value ) use ( &$cache ) {
				$cache[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			function ( $key ) use ( &$cache ) {
				return $cache[ $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key ) use ( &$cache ) {
				unset( $cache[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once();
		Functions\when( 'get_post_type' )->justReturn( Trash::POST_TYPE );

		$this->options[ OldAddresses::MAP_OPTION ] = array( 'topics/recipes' => 31 );
		$this->options[ Redirects::OPTION ]        = array(
			'topics/seo' => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_RENAME,
				'created'  => 100,
			),
		);

		for ( $i = 0; $i < 25; $i++ ) {
			HitCounter::record( HitCounter::OLD, 'topics/recipes' );
		}
		HitCounter::record( HitCounter::REDIRECT, 'topics/seo' );
		HitCounter::record( HitCounter::REDIRECT, 'topics/seo' );

		$this->assertArrayNotHasKey( OldAddresses::HITS_OPTION, $this->options, 'no database write for a visit' );
		$this->assertArrayNotHasKey( Redirects::HITS_OPTION, $this->options );

		HitCounter::flush();

		$this->assertSame( 25, $this->options[ OldAddresses::HITS_OPTION ]['topics/recipes']['n'] );
		$this->assertSame( 2, Redirects::hits()['topics/seo']['n'] );

		HitCounter::flush();
		$this->assertSame( 25, $this->options[ OldAddresses::HITS_OPTION ]['topics/recipes']['n'], 'a second write adds nothing' );
	}

	public function test_visits_this_week_do_not_count_older_days(): void {
		$old = gmdate( 'Ymd', time() - 10 * DAY_IN_SECONDS );
		$new = gmdate( 'Ymd', time() - DAY_IN_SECONDS );

		$this->assertSame(
			4,
			OldAddresses::week_visits(
				array(
					'n'    => 14,
					'last' => time(),
					'd'    => array(
						$new => 4,
						$old => 10,
					),
				)
			)
		);
	}

	public function test_an_old_address_can_be_redirected_to_a_term_after_the_delete(): void {
		$this->trashed( 31, 'topics/recipes' );

		Actions\expectDone( 'axle_category_tag_trash_redirect_added' )->once();
		$result = $this->old_addresses()->redirect( 31, array( 'term' => 1 ) );

		$this->assertSame( 'topics/recipes', $result['path'] );
		$this->assertSame( 'https://example.test/topics/search/', $result['new_url'] );
		$entry = Redirects::all()['topics/recipes'];
		$this->assertSame( Redirects::KIND_MANUAL, $entry['kind'] );
		$this->assertSame( 121, $entry['old_term'] );

		$again = $this->old_addresses()->redirect( 31, array( 'term' => 2 ) );
		$this->assertSame( 'axle_category_tag_trash_exists', $again->get_error_code() );
	}

	public function test_an_old_address_can_be_redirected_to_an_address_on_this_site_only(): void {
		$this->trashed( 31, 'topics/recipes' );
		$old = $this->old_addresses();

		$bad = $old->redirect( 31, array( 'url' => 'https://elsewhere.test/page/' ) );
		$this->assertSame( 'axle_category_tag_trash_bad_target', $bad->get_error_code() );
		$this->assertSame( array(), Redirects::all() );

		$loop = $old->redirect( 31, array( 'url' => '/topics/recipes/' ) );
		$this->assertSame( 'axle_category_tag_trash_bad_target', $loop->get_error_code(), 'it cannot redirect to itself' );

		$result = $old->redirect( 31, array( 'url' => '/blog/' ) );
		$this->assertSame( 'https://example.test/blog/', $result['new_url'] );
		$entry = Redirects::all()['topics/recipes'];
		$this->assertSame( 0, $entry['term'] );
		$this->assertSame( 'https://example.test/blog/page/2/', Redirects::target_url( 'topics/recipes', $entry, '/page/2' ), 'numbered pages follow' );
	}

	public function test_redirecting_an_old_address_needs_rights_for_its_taxonomy(): void {
		$this->trashed( 31, 'topics/brand-old', 'product_brand' );
		$old = $this->old_addresses();

		$result = $old->redirect( 31, array( 'term' => 3 ) );
		$this->assertSame( 'axle_category_tag_trash_forbidden', $result->get_error_code() );
		$this->assertSame( array(), Redirects::all() );

		$trash     = new Trash( new Settings() );
		$abilities = new Abilities( $trash, new Restorer( $trash ), new Merger( $trash ), $this->redirects(), $old );
		$this->assertFalse( $abilities->can_redirect_old_address( array( 'id' => 31 ) ) );

		$this->assertSame( array(), $old->visited( 1 ), 'no counts for a taxonomy the user may not change' );

		$this->caps = array( 'manage_categories', 'manage_brands' );
		$this->assertTrue( $abilities->can_redirect_old_address( array( 'id' => 31 ) ) );
		$this->assertIsArray( $old->redirect( 31, array( 'term' => 3 ) ) );
	}

	public function test_a_term_with_no_known_address_cannot_be_redirected(): void {
		$this->trashed( 31, '' );

		$result = $this->old_addresses()->redirect( 31, array( 'term' => 1 ) );
		$this->assertSame( 'axle_category_tag_trash_no_address', $result->get_error_code() );
	}

	public function test_visited_lists_only_addresses_with_visits_that_do_not_redirect(): void {
		$this->trashed( 31, 'topics/recipes' );
		$this->options[ OldAddresses::MAP_OPTION ]  = array(
			'topics/recipes' => 31,
			'topics/quiet'   => 32,
			'topics/done'    => 33,
		);
		$day                                         = gmdate( 'Ymd' );
		$this->options[ OldAddresses::HITS_OPTION ] = array(
			'topics/recipes' => array( 'n' => 42, 'last' => time(), 'd' => array( $day => 42 ) ),
			'topics/done'    => array( 'n' => 90, 'last' => time(), 'd' => array( $day => 90 ) ),
		);
		$this->options[ Redirects::OPTION ]          = array(
			'topics/done' => array(
				'taxonomy' => 'category',
				'term'     => 1,
				'kind'     => Redirects::KIND_MANUAL,
				'created'  => 100,
			),
		);

		$rows = $this->old_addresses()->visited( 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'topics/recipes', $rows[0]['path'] );
		$this->assertSame( 42, $rows[0]['week'] );
	}

	public function test_a_restored_term_takes_its_added_redirect_away(): void {
		$this->trashed( 31, 'topics/recipes' );
		$this->old_addresses()->redirect( 31, array( 'term' => 1 ) );
		$this->assertArrayHasKey( 'topics/recipes', Redirects::all() );

		$snapshot          = new Snapshot();
		$snapshot->term_id = 121;
		$this->redirects()->after_restore( 121, 'category', $snapshot );

		$this->assertArrayNotHasKey( 'topics/recipes', Redirects::all() );
	}
}
