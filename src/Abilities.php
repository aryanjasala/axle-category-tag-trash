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
	 * Sets up the abilities.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 */
	public function __construct( Trash $trash, Restorer $restorer ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
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
								'id'         => array( 'type' => 'integer' ),
								'term_id'    => array( 'type' => 'integer' ),
								'name'       => array( 'type' => 'string' ),
								'slug'       => array( 'type' => 'string' ),
								'taxonomy'   => array( 'type' => 'string' ),
								'objects'    => array( 'type' => 'integer' ),
								'children'   => array( 'type' => 'integer' ),
								'menu_links' => array( 'type' => 'integer' ),
								'deleted_at' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			array( $this, 'list_items' ),
			static function (): bool {
				return current_user_can( TrashPage::capability() );
			},
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'restore',
			__( 'Restore a trashed term', 'axle-category-tag-trash' ),
			__( 'Puts a deleted term back with its original ID, meta, posts, child terms and menu links. Very large terms finish in the background.', 'axle-category-tag-trash' ),
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
			array( $this, 'can_manage_item' ),
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Ability: trashed terms.
	 *
	 * @param array|null $input Ability input.
	 * @return array{total: int, items: array[]}
	 */
	public function list_items( $input = null ): array {
		$input = (array) $input;
		$found = $this->trash->find(
			array(
				'taxonomy' => sanitize_key( (string) ( $input['taxonomy'] ?? '' ) ),
				'search'   => sanitize_text_field( (string) ( $input['search'] ?? '' ) ),
				'per_page' => max( 1, min( 100, (int) ( $input['limit'] ?? 20 ) ) ),
				'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			)
		);

		$items = array();
		foreach ( $found['ids'] as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			if ( ! $snapshot || ! $this->trash->user_can_manage( $snapshot ) ) {
				continue;
			}
			$items[] = array(
				'id'         => $item_id,
				'term_id'    => $snapshot->term_id,
				'name'       => $snapshot->name,
				'slug'       => $snapshot->slug,
				'taxonomy'   => $snapshot->taxonomy,
				'objects'    => $snapshot->total_objects(),
				'children'   => count( $snapshot->children ),
				'menu_links' => count( $snapshot->menu_items ),
				'deleted_at' => gmdate( 'c', (int) strtotime( (string) get_post_field( 'post_date_gmt', $item_id ) . ' UTC' ) ),
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
	 * Permission: the screen's capability, and the right to manage the term's own taxonomy.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_manage_item( $input = null ): bool {
		if ( ! current_user_can( TrashPage::capability() ) ) {
			return false;
		}

		$snapshot = $this->trash->get( (int) ( ( (array) $input )['id'] ?? 0 ) );

		// A missing item is reported by the ability itself, as "no longer in Axle Category & Tag Trash".
		return ! $snapshot || $this->trash->user_can_manage( $snapshot );
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
