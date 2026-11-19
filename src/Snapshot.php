<?php
/**
 * Everything about a term at the moment it was deleted.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Value object stored with each trashed term.
 */
final class Snapshot {

	/**
	 * Objects per packed chunk.
	 */
	public const CHUNK = 1000;

	/**
	 * Object IDs kept in $sample_objects.
	 */
	public const SAMPLE = 10;

	/**
	 * Original term ID.
	 *
	 * @var int
	 */
	public int $term_id = 0;

	/**
	 * Original term taxonomy ID.
	 *
	 * @var int
	 */
	public int $term_taxonomy_id = 0;

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	public string $taxonomy = '';

	/**
	 * Term name.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Term slug.
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * Archive address of the term when it was deleted, relative to the site address, such as "category/recipes".
	 *
	 * Empty when the taxonomy has no archive addresses, or for terms trashed before addresses were kept.
	 *
	 * @var string
	 */
	public string $path = '';

	/**
	 * Term description.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * Parent term ID.
	 *
	 * @var int
	 */
	public int $parent = 0;

	/**
	 * Term group.
	 *
	 * @var int
	 */
	public int $term_group = 0;

	/**
	 * Raw term meta: key => list of stored values.
	 *
	 * @var array<string, string[]>
	 */
	public array $meta = array();

	/**
	 * Attached objects as [ object ID, term order ] pairs.
	 *
	 * Only snapshots saved by version 1.1.0 and earlier keep them here. Newer
	 * ones keep them in packed chunks, see $object_chunks.
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	public array $objects = array();

	/**
	 * Number of attached objects.
	 *
	 * @var int
	 */
	public int $object_count = 0;

	/**
	 * The first few attached object IDs, for the trash screen.
	 *
	 * @var int[]
	 */
	public array $sample_objects = array();

	/**
	 * Attached objects packed as "id:order,id:order" strings of up to CHUNK pairs.
	 *
	 * Stored as separate meta rows on the trash item, never in the snapshot
	 * itself, so a term on a million posts does not become one huge value.
	 *
	 * @var string[]
	 */
	public array $object_chunks = array();

	/**
	 * Term WordPress gave to objects left with no term, or 0.
	 *
	 * @var int
	 */
	public int $default_term = 0;

	/**
	 * Objects that only had this term, so got the default term instead.
	 *
	 * Only in snapshots saved by version 1.1.0 and earlier, see $fallback_chunks.
	 *
	 * @var int[]
	 */
	public array $fallback_objects = array();

	/**
	 * Objects that only had this term, packed as "id,id,id" strings of up to CHUNK IDs.
	 *
	 * Stored as separate meta rows on the trash item, like $object_chunks.
	 *
	 * @var string[]
	 */
	public array $fallback_chunks = array();

	/**
	 * Direct child term IDs.
	 *
	 * @var int[]
	 */
	public array $children = array();

	/**
	 * Menu links to the term.
	 *
	 * @var array[]
	 */
	public array $menu_items = array();

	/**
	 * Builds a snapshot from stored data.
	 *
	 * @param array $data Stored data.
	 * @return Snapshot|null Null when the data is not a snapshot.
	 */
	public static function from_array( array $data ): ?Snapshot {
		if ( empty( $data['term_id'] ) || empty( $data['taxonomy'] ) ) {
			return null;
		}

		$snapshot = new self();
		foreach ( get_object_vars( $snapshot ) as $key => $default ) {
			if ( array_key_exists( $key, $data ) ) {
				$value            = $data[ $key ];
				$snapshot->{$key} = is_int( $default ) ? (int) $value : ( is_array( $default ) ? (array) $value : (string) $value );
			}
		}

		return $snapshot;
	}

	/**
	 * Data to store.
	 *
	 * @return array
	 */
	public function to_array(): array {
		$data = get_object_vars( $this );
		unset( $data['object_chunks'], $data['fallback_chunks'] );

		return array( 'version' => 3 ) + $data;
	}

	/**
	 * IDs of the attached objects kept in the snapshot itself (1.1.0 and earlier).
	 *
	 * @return int[]
	 */
	public function object_ids(): array {
		return array_map( 'intval', wp_list_pluck( $this->objects, 0 ) );
	}

	/**
	 * Number of attached objects, for either storage format.
	 *
	 * @return int
	 */
	public function total_objects(): int {
		return $this->object_count ? $this->object_count : count( $this->objects );
	}

	/**
	 * The first few attached object IDs, for either storage format.
	 *
	 * @return int[]
	 */
	public function sample(): array {
		return $this->sample_objects ? $this->sample_objects : array_slice( $this->object_ids(), 0, self::SAMPLE );
	}

	/**
	 * Adds a batch of [ object ID, term order ] pairs as packed chunks.
	 *
	 * @param array<int, array{0: int, 1: int}> $pairs Attached objects.
	 */
	public function add_objects( array $pairs ): void {
		foreach ( array_chunk( $pairs, self::CHUNK ) as $chunk ) {
			$this->object_chunks[] = self::pack_pairs( $chunk );
		}

		$this->object_count += count( $pairs );
		foreach ( $pairs as $pair ) {
			if ( count( $this->sample_objects ) >= self::SAMPLE ) {
				break;
			}
			$this->sample_objects[] = (int) $pair[0];
		}
	}

	/**
	 * Adds a batch of object IDs that only had this term, as packed chunks.
	 *
	 * @param int[] $ids Object IDs.
	 */
	public function add_fallback_objects( array $ids ): void {
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$this->fallback_chunks[] = self::pack_ids( $chunk );
		}
	}

	/**
	 * Packed chunks of the objects kept in the snapshot itself (1.1.0 and earlier).
	 *
	 * @return string[]
	 */
	public function legacy_object_chunks(): array {
		return array_map( array( self::class, 'pack_pairs' ), array_chunk( $this->objects, self::CHUNK ) );
	}

	/**
	 * Packed chunks of the fallback objects kept in the snapshot itself (1.1.0 and earlier).
	 *
	 * @return string[]
	 */
	public function legacy_fallback_chunks(): array {
		return array_map( array( self::class, 'pack_ids' ), array_chunk( $this->fallback_objects, self::CHUNK ) );
	}

	/**
	 * Packs [ object ID, term order ] pairs as "id:order,id:order".
	 *
	 * @param array<int, array{0: int, 1: int}> $pairs Pairs.
	 * @return string
	 */
	public static function pack_pairs( array $pairs ): string {
		$packed = array();
		foreach ( $pairs as $pair ) {
			$packed[] = (int) $pair[0] . ':' . (int) $pair[1];
		}

		return implode( ',', $packed );
	}

	/**
	 * Unpacks "id:order,id:order" into [ object ID, term order ] pairs.
	 *
	 * @param string $packed Packed pairs.
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function unpack_pairs( string $packed ): array {
		$pairs = array();
		foreach ( array_filter( explode( ',', $packed ) ) as $item ) {
			$parts = explode( ':', $item );
			if ( (int) $parts[0] > 0 ) {
				$pairs[] = array( (int) $parts[0], (int) ( $parts[1] ?? 0 ) );
			}
		}

		return $pairs;
	}

	/**
	 * Packs object IDs as "id,id,id".
	 *
	 * @param int[] $ids Object IDs.
	 * @return string
	 */
	public static function pack_ids( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Unpacks "id,id,id" into object IDs.
	 *
	 * @param string $packed Packed IDs.
	 * @return int[]
	 */
	public static function unpack_ids( string $packed ): array {
		return array_values( array_filter( array_map( 'intval', explode( ',', $packed ) ) ) );
	}
}
