<?php
/**
 * Who may see and change trashed terms, taxonomy by taxonomy.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the capabilities of the taxonomy a term belongs to.
 *
 * A global capability such as manage_categories says nothing about a custom
 * taxonomy that has its own capabilities, so every list, restore, delete and
 * merge asks the term's own taxonomy.
 */
final class Access {

	public const LIST    = 'list';
	public const RESTORE = 'restore';
	public const DELETE  = 'delete';
	public const MERGE   = 'merge';

	/**
	 * Changing or removing the redirects of a taxonomy's terms.
	 */
	public const REDIRECTS = 'redirects';

	/**
	 * Taxonomy capabilities each kind of access needs, all of them.
	 */
	private const NEEDS = array(
		self::LIST      => array( 'manage_terms' ),
		self::RESTORE   => array( 'manage_terms', 'edit_terms' ),
		self::DELETE    => array( 'manage_terms', 'delete_terms' ),
		self::MERGE     => array( 'manage_terms', 'edit_terms', 'delete_terms' ),
		self::REDIRECTS => array( 'manage_terms', 'edit_terms' ),
	);

	/**
	 * Cleans a taxonomy name from user input.
	 *
	 * @param mixed $value Raw value.
	 * @return string Lower case key, or an empty string.
	 */
	public static function taxonomy_name( $value ): string {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * Whether the current user may do something with terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name, already cleaned or not.
	 * @param string $context  LIST, RESTORE, DELETE, MERGE or REDIRECTS.
	 * @return bool
	 */
	public static function can( string $taxonomy, string $context = self::LIST ): bool {
		$taxonomy = self::taxonomy_name( $taxonomy );
		$context  = isset( self::NEEDS[ $context ] ) ? $context : self::MERGE;
		$object   = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : false;

		if ( $object ) {
			$allowed = true;
			foreach ( self::NEEDS[ $context ] as $cap ) {
				$allowed = $allowed && ! empty( $object->cap->{$cap} ) && current_user_can( $object->cap->{$cap} );
			}
		} else {
			// A taxonomy that is not registered (its plugin is off) has no capabilities of its own.
			$allowed = '' !== $taxonomy && in_array( $context, array( self::LIST, self::DELETE, self::REDIRECTS ), true ) && current_user_can( 'manage_options' );
		}

		/**
		 * Filters whether the current user may use Axle Category & Tag Trash on a taxonomy.
		 *
		 * Runs for every list, restore, delete and merge, on screens, in the REST API,
		 * abilities and row actions. Use it to hide a taxonomy from some users or to
		 * give another role access.
		 *
		 * @param bool   $allowed  Whether the user's own capabilities for the taxonomy allow it.
		 * @param string $taxonomy Taxonomy name.
		 * @param string $context  list, restore, delete, merge or redirects.
		 * @param int    $user_id  Current user ID.
		 */
		$allowed = (bool) apply_filters( 'axle_category_tag_trash_can_access_taxonomy', $allowed, $taxonomy, $context, get_current_user_id() );

		// Whatever the filter says, nobody who is not logged in gets in.
		return $allowed && get_current_user_id() > 0;
	}

	/**
	 * The taxonomies from a list that the current user may use.
	 *
	 * @param string[] $taxonomies Taxonomy names.
	 * @param string   $context    LIST, RESTORE, DELETE, MERGE or REDIRECTS.
	 * @return string[]
	 */
	public static function filter( array $taxonomies, string $context = self::LIST ): array {
		return array_values(
			array_filter(
				array_unique( array_map( array( self::class, 'taxonomy_name' ), $taxonomies ) ),
				static function ( string $taxonomy ) use ( $context ): bool {
					return self::can( $taxonomy, $context );
				}
			)
		);
	}
}
