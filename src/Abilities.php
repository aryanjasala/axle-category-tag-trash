<?php
/**
 * Abilities: the trash for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

use AryanX\Axle\CategoryTagTrash\Admin\TrashPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Axle Category & Tag Trash's abilities on WordPress 6.9 and later.
 *
 * An agent that deleted the wrong category can find it and put it back, with
 * its posts, child terms and menu links, the same way a person would.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-category-tag-trash';

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
	 * Merge logic.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Sets up the abilities.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 * @param Merger   $merger   Merge logic.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Merger $merger ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
		$this->merger   = $merger;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Axle Category & Tag Trash category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Category & Tag Trash', 'axle-category-tag-trash' ),
				'description' => __( 'Deleted categories, tags and custom terms, kept for a while so they can be restored.', 'axle-category-tag-trash' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$item_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Trash item ID from axle-category-tag-trash/list. Not the term ID.', 'axle-category-tag-trash' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);

		$this->ability(
			'list',
			__( 'List trashed terms', 'axle-category-tag-trash' ),
			__( 'Deleted categories, tags and other terms that can still be restored, newest first, with what a restore brings back.', 'axle-category-tag-trash' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'Only this taxonomy, such as category or post_tag.', 'axle-category-tag-trash' ),
					),
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Name contains this text.', 'axle-category-tag-trash' ),
					),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'total' => array( 'type' => 'integer' ),
					'items' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'integer' ),
								'term_id'     => array( 'type' => 'integer' ),
								'name'        => array( 'type' => 'string' ),
								'slug'        => array( 'type' => 'string' ),
								'taxonomy'    => array( 'type' => 'string' ),
								'objects'     => array( 'type' => 'integer' ),
								'children'    => array( 'type' => 'integer' ),
								'menu_links'  => array( 'type' => 'integer' ),
								'deleted_at'  => array( 'type' => 'string' ),
								'merged_into' => array(
									'type'        => 'integer',
									'description' => __( 'Term ID this term was merged into, or 0 when it was deleted.', 'axle-category-tag-trash' ),
								),
							),
						),
					),
				),
			),
			array( $this, 'list_items' ),
			array( $this, 'can_list' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'restore',
			__( 'Restore a trashed term', 'axle-category-tag-trash' ),
			__( 'Puts a deleted term back with its original ID, meta, posts, child terms and menu links. For a term that was merged away, it undoes the merge. Very large terms finish in the background.', 'axle-category-tag-trash' ),
			$item_input,
			array(
				'type'       => 'object',
				'properties' => array(
					'term_id'    => array( 'type' => 'integer' ),
					'notes'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'background' => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'restore' ),
			array( $this, 'can_manage_item' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'delete',
			__( 'Delete a trashed term for good', 'axle-category-tag-trash' ),
			__( 'Removes a term from Axle Category & Tag Trash permanently. It cannot be restored afterwards.', 'axle-category-tag-trash' ),
			$item_input,
			array(
				'type'       => 'object',
				'properties' => array(
					'deleted' => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'delete' ),
			array( $this, 'can_delete_item' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$merge_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy' => array(
					'type'        => 'string',
					'description' => __( 'Taxonomy of both terms, such as post_tag or category.', 'axle-category-tag-trash' ),
				),
				'source'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Term ID to merge away. It goes to the trash.', 'axle-category-tag-trash' ),
				),
				'target'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Term ID that receives all the posts.', 'axle-category-tag-trash' ),
				),
				'children' => array(
					'type'        => 'string',
					'enum'        => Merger::children_policies(),
					'description' => __( '"target" moves the source\'s child terms under the target. "parent" moves them up one level.', 'axle-category-tag-trash' ),
				),
			),
			'required'             => array( 'taxonomy', 'source', 'target' ),
			'additionalProperties' => false,
		);

		$this->ability(
			'preview-merge',
			__( 'Preview a term merge', 'axle-category-tag-trash' ),
			__( 'Shows what merging one term into another would do: how many posts move, how many already have the target, and what happens to child terms and menu links. Changes nothing.', 'axle-category-tag-trash' ),
			$merge_input,
			array(
				'type'       => 'object',
				'properties' => array(
					'source_id'       => array( 'type' => 'integer' ),
					'source_name'     => array( 'type' => 'string' ),
					'target_id'       => array( 'type' => 'integer' ),
					'target_name'     => array( 'type' => 'string' ),
					'objects'         => array( 'type' => 'integer' ),
					'already'         => array( 'type' => 'integer' ),
					'moving'          => array( 'type' => 'integer' ),
					'children'        => array( 'type' => 'integer' ),
					'children_policy' => array( 'type' => 'string' ),
					'menu_links'      => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'preview_merge' ),
			array( $this, 'can_merge' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'merge',
			__( 'Merge one term into another', 'axle-category-tag-trash' ),
			__( 'Moves every post of the source term to the target term and puts the source in the trash. Restore the trash item (axle-category-tag-trash/restore) to undo it: the source comes back and the target comes off only the posts that got it through the merge. Very large merges finish in the background.', 'axle-category-tag-trash' ),
			$merge_input,
			array(
				'type'       => 'object',
				'properties' => array(
					'done'    => array( 'type' => 'boolean' ),
					'item_id' => array(
						'type'        => 'integer',
						'description' => __( 'Trash item ID to restore for an undo. 0 until the merge is done.', 'axle-category-tag-trash' ),
					),
					'moved'   => array( 'type' => 'integer' ),
					'total'   => array( 'type' => 'integer' ),
					'notes'   => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'merge' ),
			array( $this, 'can_merge' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Ability: trashed terms.
	 *
	 * @param array|null $input Ability input.
	 * @return array{total: int, items: array[]}|\WP_Error
	 */
	public function list_items( $input = null ) {
		$input    = (array) $input;
		$taxonomy = Access::taxonomy_name( $input['taxonomy'] ?? '' );

		if ( '' !== $taxonomy && ! Access::can( $taxonomy, Access::LIST ) ) {
			return new \WP_Error( 'axle_category_tag_trash_forbidden', __( 'Sorry, you are not allowed to see trashed terms of this taxonomy.', 'axle-category-tag-trash' ), array( 'status' => 403 ) );
		}

		$found = $this->trash->find(
			array(
				// Only taxonomies the user may manage, so counts and pages never include the rest.
				'taxonomies' => '' !== $taxonomy ? array( $taxonomy ) : $this->trash->accessible_taxonomies( Access::LIST ),
				'search'     => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'per_page'   => max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) ),
				'page'       => max( 1, (int) ( $input['page'] ?? 1 ) ),
			)
		);

		$items = array();
		foreach ( $found['ids'] as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			if ( ! $snapshot || ! $this->trash->user_can_manage( $snapshot ) ) {
				continue;
			}
			$merge   = $this->trash->merge_info( $item_id );
			$items[] = array(
				'id'          => $item_id,
				'term_id'     => $snapshot->term_id,
				'name'        => $snapshot->name,
				'slug'        => $snapshot->slug,
				'taxonomy'    => $snapshot->taxonomy,
				'objects'     => $snapshot->total_objects(),
				'children'    => count( $snapshot->children ),
				'menu_links'  => count( $snapshot->menu_items ),
				'deleted_at'  => gmdate( 'c', (int) strtotime( (string) get_post_field( 'post_date_gmt', $item_id ) . ' UTC' ) ),
				'merged_into' => $merge ? (int) $merge['target_id'] : 0,
			);
		}

		return array(
			'total' => $found['total'],
			'items' => $items,
		);
	}

	/**
	 * Ability: restores a trashed term.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function restore( $input = null ) {
		return $this->restorer->restore( (int) ( ( (array) $input )['id'] ?? 0 ) );
	}

	/**
	 * Ability: deletes a trashed term for good.
	 *
	 * @param array|null $input Ability input.
	 * @return array{deleted: bool}
	 */
	public function delete( $input = null ): array {
		return array( 'deleted' => $this->trash->delete( (int) ( ( (array) $input )['id'] ?? 0 ) ) );
	}

	/**
	 * Ability: previews a merge.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function preview_merge( $input = null ) {
		$input = (array) $input;

		return $this->merger->preview( (int) ( $input['source'] ?? 0 ), (int) ( $input['target'] ?? 0 ), Access::taxonomy_name( $input['taxonomy'] ?? '' ), (string) ( $input['children'] ?? '' ) );
	}

	/**
	 * Ability: merges one term into another.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function merge( $input = null ) {
		$input = (array) $input;

		return $this->merger->merge(
			(int) ( $input['source'] ?? 0 ),
			(int) ( $input['target'] ?? 0 ),
			Access::taxonomy_name( $input['taxonomy'] ?? '' ),
			array( 'children' => (string) ( $input['children'] ?? '' ) )
		);
	}

	/**
	 * Permission for listing: the screen's capability. The list itself only holds taxonomies the user may manage.
	 *
	 * @return bool
	 */
	public function can_list(): bool {
		// What the user may see is scoped in list_items(), so an empty trash is an empty list, not a refusal.
		return current_user_can( TrashPage::capability() );
	}

	/**
	 * Permission to restore: the screen's capability, and the right to edit the term's own taxonomy.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_manage_item( $input = null ): bool {
		return $this->can_item( $input, Access::RESTORE );
	}

	/**
	 * Permission to delete for good: the screen's capability, and the right to delete terms of the term's own taxonomy.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_delete_item( $input = null ): bool {
		return $this->can_item( $input, Access::DELETE );
	}

	/**
	 * Permission to merge: the screen's capability, the taxonomy's own capabilities and the two terms' own.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_merge( $input = null ): bool {
		$input    = (array) $input;
		$taxonomy = Access::taxonomy_name( $input['taxonomy'] ?? '' );
		$source   = (int) ( $input['source'] ?? 0 );
		$target   = (int) ( $input['target'] ?? 0 );

		if ( ! current_user_can( TrashPage::capability() ) || ! Access::can( $taxonomy, Access::MERGE ) ) {
			return false;
		}

		// A missing term is reported by the ability itself. The terms that exist must be ones this user may change.
		return ( ! $source || current_user_can( 'delete_term', $source ) ) && ( ! $target || current_user_can( 'edit_term', $target ) );
	}

	/**
	 * Permission for one trash item.
	 *
	 * @param array|null $input   Ability input.
	 * @param string     $context Access::RESTORE or DELETE.
	 * @return bool
	 */
	private function can_item( $input, string $context ): bool {
		if ( ! current_user_can( TrashPage::capability() ) ) {
			return false;
		}

		$snapshot = $this->trash->get( (int) ( ( (array) $input )['id'] ?? 0 ) );

		// A missing item is reported by the ability itself, as "no longer in Axle Category & Tag Trash".
		return ! $snapshot || $this->trash->user_can_manage( $snapshot, $context );
	}

	/**
	 * Registers one ability in the Axle Category & Tag Trash category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
