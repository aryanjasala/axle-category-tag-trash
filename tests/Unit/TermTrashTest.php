<?php
/**
 * Tests for snapshots, retention and which taxonomies are kept.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Tests\Unit;

use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Snapshot;
use AryanX\Axle\CategoryTagTrash\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CategoryTagTrashTest extends TestCase {

	public function test_snapshot_round_trips_through_stored_data(): void {
		Functions\when( 'wp_list_pluck' )->alias( 'array_column' );

		$snapshot                   = new Snapshot();
		$snapshot->term_id          = 12;
		$snapshot->term_taxonomy_id = 13;
		$snapshot->taxonomy         = 'category';
		$snapshot->name             = 'Recipes';
		$snapshot->slug             = 'recipes';
		$snapshot->objects          = array( array( 40, 0 ), array( 41, 2 ) );
		$snapshot->children         = array( 15 );
		$snapshot->meta             = array( 'color' => array( 'red' ) );

		$copy = Snapshot::from_array( $snapshot->to_array() );

		$this->assertEquals( $snapshot, $copy );
		$this->assertSame( array( 40, 41 ), $copy->object_ids() );
	}

	public function test_objects_are_packed_in_chunks_with_a_sample(): void {
		$snapshot = new Snapshot();
		$pairs    = array();
		for ( $id = 1; $id <= Snapshot::CHUNK + 5; $id++ ) {
			$pairs[] = array( $id, $id % 3 );
		}

		$snapshot->add_objects( $pairs );
		$snapshot->add_fallback_objects( array( 2, 4, 6 ) );

		$this->assertSame( Snapshot::CHUNK + 5, $snapshot->total_objects() );
		$this->assertCount( 2, $snapshot->object_chunks );
		$this->assertSame( range( 1, Snapshot::SAMPLE ), $snapshot->sample() );
		$this->assertSame( array_slice( $pairs, Snapshot::CHUNK ), Snapshot::unpack_pairs( $snapshot->object_chunks[1] ) );
		$this->assertSame( array( '2,4,6' ), $snapshot->fallback_chunks );
	}

	public function test_packed_chunks_are_not_stored_inside_the_snapshot(): void {
		$snapshot           = new Snapshot();
		$snapshot->term_id  = 12;
		$snapshot->taxonomy = 'post_tag';
		$snapshot->add_objects( array( array( 40, 0 ), array( 41, 2 ) ) );

		$data = $snapshot->to_array();

		$this->assertSame( 3, $data['version'] );
		$this->assertArrayNotHasKey( 'object_chunks', $data );
		$this->assertArrayNotHasKey( 'fallback_chunks', $data );
		$this->assertSame( 2, Snapshot::from_array( $data )->total_objects() );
		$this->assertSame( array( 40, 41 ), Snapshot::from_array( $data )->sample() );
	}

	public function test_snapshots_from_1_1_0_still_give_their_objects_as_chunks(): void {
		Functions\when( 'wp_list_pluck' )->alias( 'array_column' );

		$old = Snapshot::from_array(
			array(
				'version'          => 2,
				'term_id'          => 12,
				'taxonomy'         => 'category',
				'objects'          => array( array( 40, 0 ), array( 41, 2 ) ),
				'fallback_objects' => array( 41 ),
			)
		);

		$this->assertSame( 2, $old->total_objects() );
		$this->assertSame( array( 40, 41 ), $old->sample() );
		$this->assertSame( array( '40:0,41:2' ), $old->legacy_object_chunks() );
		$this->assertSame( array( '41' ), $old->legacy_fallback_chunks() );
	}

	public function test_unpacking_ignores_empty_and_broken_entries(): void {
		$this->assertSame( array(), Snapshot::unpack_pairs( '' ) );
		$this->assertSame( array( array( 7, 0 ), array( 9, 3 ) ), Snapshot::unpack_pairs( '7,9:3,:4,0:1' ) );
		$this->assertSame( array( 5, 6 ), Snapshot::unpack_ids( '5,,6,x' ) );
	}

	public function test_snapshot_casts_stored_values_to_their_types(): void {
		$snapshot = Snapshot::from_array(
			array(
				'term_id'  => '12',
				'taxonomy' => 'post_tag',
				'parent'   => '3',
				'name'     => 5,
			)
		);

		$this->assertSame( 12, $snapshot->term_id );
		$this->assertSame( 3, $snapshot->parent );
		$this->assertSame( '5', $snapshot->name );
	}

	public function test_data_without_a_term_or_taxonomy_is_not_a_snapshot(): void {
		$this->assertNull( Snapshot::from_array( array( 'taxonomy' => 'category' ) ) );
		$this->assertNull( Snapshot::from_array( array( 'term_id' => 4 ) ) );
	}

	public function test_retention_defaults_to_30_days(): void {
		$this->assertSame( 30, ( new Settings() )->retention_days() );
	}

	public function test_retention_uses_the_saved_value_and_the_filter_wins(): void {
		$this->options[ Settings::OPTION ] = array( 'retention_days' => 90 );
		$this->assertSame( 90, ( new Settings() )->retention_days() );

		Filters\expectApplied( 'axle_category_tag_trash_retention_days' )->once()->with( 90 )->andReturn( 7 );
		$this->assertSame( 7, ( new Settings() )->retention_days() );
	}

	public function test_retention_choices_can_be_filtered_and_are_cleaned(): void {
		Filters\expectApplied( 'axle_category_tag_trash_retention_choices' )->andReturn( array( 180, 0, 14, 14, 'x' ) );

		$this->assertSame( array( 14, 180 ), Settings::retention_choices() );

		( new Settings() )->set_retention_days( 180 );
		$this->assertSame( 180, $this->options[ Settings::OPTION ]['retention_days'] );
	}

	public function test_only_offered_retention_periods_are_saved(): void {
		$settings = new Settings();

		$settings->set_retention_days( 45 );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->options );

		$settings->set_retention_days( 365 );
		$this->assertSame( 365, $settings->retention_days() );
	}

	public function test_taxonomies_with_a_screen_are_kept_but_menus_are_not(): void {
		Functions\when( 'get_taxonomy' )->alias(
			static function ( $taxonomy ) {
				$known = array(
					'category'    => true,
					'nav_menu'    => true,
					'post_format' => false,
				);
				return isset( $known[ $taxonomy ] ) ? (object) array( 'show_ui' => $known[ $taxonomy ] ) : false;
			}
		);
		$trash = new Trash( new Settings() );

		$this->assertTrue( $trash->supports( 'category' ) );
		$this->assertFalse( $trash->supports( 'nav_menu' ) );
		$this->assertFalse( $trash->supports( 'post_format' ) );
		$this->assertFalse( $trash->supports( 'not_registered' ) );
	}
}
