<?php
/**
 * List table for Tools > Axle Category & Tag Trash.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Admin;

use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Snapshot;
use AryanX\Axle\CategoryTagTrash\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Trashed terms with taxonomy tabs, search, bulk actions and details.
 *
 * Load wp-admin/includes/class-wp-list-table.php before creating it.
 */
final class TrashListTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Snapshots of the items on this page, keyed by trash item ID.
	 *
	 * @var array<int, Snapshot>
	 */
	private array $snapshots = array();

	/**
	 * Sets up the table.
	 *
	 * @param Trash $trash Trashed terms.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;

		parent::__construct(
			array(
				'singular' => 'axle_category_tag_trash_item',
				'plural'   => 'axle_category_tag_trash_items',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Taxonomy tab requested in the URL.
	 *
	 * @return string
	 */
	public function current_taxonomy(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
	}

	/**
	 * Loads the items for the current page, tab and search.
	 */
	public function prepare_items(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// Only taxonomies this user may manage, so the list, its counts and its pages never include the rest.
		$found = $this->trash->find(
			array(
				'taxonomy'   => $this->current_taxonomy(),
				'taxonomies' => $this->trash->accessible_taxonomies( Access::LIST ),
				'search'     => $search,
				'per_page'   => self::PER_PAGE,
				'page'       => $this->get_pagenum(),
			)
		);

		foreach ( $found['ids'] as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			if ( $snapshot && $this->trash->user_can_manage( $snapshot ) ) {
				$this->snapshots[ $item_id ] = $snapshot;
			}
		}

		$this->items = array();
		foreach ( $this->snapshots as $item_id => $snapshot ) {
			$this->items[] = array(
				'id'       => $item_id,
				'snapshot' => $snapshot,
			);
		}
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
		$this->set_pagination_args(
			array(
				'total_items' => $found['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => __( 'Term', 'axle-category-tag-trash' ),
			'taxonomy' => __( 'Taxonomy', 'axle-category-tag-trash' ),
			'attached' => __( 'Attached to', 'axle-category-tag-trash' ),
			'deleted'  => __( 'Deleted', 'axle-category-tag-trash' ),
		);
	}

	/**
	 * "All" plus one tab per taxonomy with trashed terms.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts  = $this->trash->count_by_taxonomy( Access::LIST );
		$current = $this->current_taxonomy();
		$base    = admin_url( 'tools.php?page=' . TrashPage::SLUG );
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'axle-category-tag-trash' ),
				esc_html( number_format_i18n( array_sum( $counts ) ) )
			),
		);

		foreach ( $counts as $taxonomy => $count ) {
			$object             = get_taxonomy( $taxonomy );
			$views[ $taxonomy ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'taxonomy', $taxonomy, $base ) ),
				$current === $taxonomy ? ' class="current" aria-current="page"' : '',
				esc_html( $object ? $object->labels->name : $taxonomy ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'restore' => __( 'Restore', 'axle-category-tag-trash' ),
			'delete'  => __( 'Delete permanently', 'axle-category-tag-trash' ),
		);
	}

	/**
	 * Message when the trash is empty.
	 */
	public function no_items(): void {
		esc_html_e( 'Nothing in the trash matches. Clear the search or pick All.', 'axle-category-tag-trash' );
	}

	/**
	 * Row checkbox.
	 *
	 * @param array{id: int, snapshot: Snapshot} $item Row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		$item_id = (int) $item['id'];
		return sprintf(
			'<label class="screen-reader-text" for="actt-item-%1$d">%2$s</label><input type="checkbox" id="actt-item-%1$d" name="items[]" value="%1$d">',
			(int) $item_id,
			/* translators: %s: term name. */
			esc_html( sprintf( __( 'Select %s', 'axle-category-tag-trash' ), $this->snapshots[ $item_id ]->name ) )
		);
	}

	/**
	 * Name, slug, row actions and the details toggle.
	 *
	 * @param array{id: int, snapshot: Snapshot} $item Row.
	 * @return string
	 */
	protected function column_name( $item ): string {
		$item_id  = (int) $item['id'];
		$snapshot = $this->snapshots[ $item_id ];
		$actions  = array();

		$merge = $this->trash->merge_info( $item_id );

		if ( taxonomy_exists( $snapshot->taxonomy ) && $this->trash->user_can_manage( $snapshot, Access::RESTORE ) ) {
			$actions['restore'] = sprintf(
				'<a href="%s" class="actt-restore">%s</a>',
				esc_url( TrashPage::action_url( 'restore', array( $item_id ) ) ),
				$merge ? esc_html__( 'Undo merge', 'axle-category-tag-trash' ) : esc_html__( 'Restore', 'axle-category-tag-trash' )
			);
		}
		if ( $this->trash->user_can_manage( $snapshot, Access::DELETE ) ) {
			$actions['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete actt-delete" data-title="%2$s" data-body="%3$s" data-yes="%4$s">%5$s</a>',
				esc_url( TrashPage::action_url( 'delete', array( $item_id ) ) ),
				/* translators: %s: term name. */
				esc_attr( sprintf( __( 'Delete "%s" for good?', 'axle-category-tag-trash' ), $snapshot->name ) ),
				esc_attr( __( 'It leaves the trash and cannot be restored. This cannot be undone.', 'axle-category-tag-trash' ) ),
				esc_attr__( 'Delete permanently', 'axle-category-tag-trash' ),
				esc_html__( 'Delete permanently', 'axle-category-tag-trash' )
			);
		}

		$note = '';
		if ( $merge ) {
			$note = sprintf(
				'<br><span class="axle-badge axle-badge--info">%s</span>',
				/* translators: %s: name of the term this one was merged into. */
				esc_html( sprintf( __( 'Merged into %s', 'axle-category-tag-trash' ), $merge['target_name'] ) )
			);
		}

		return sprintf(
			'<strong class="actt-name">%s</strong> <code class="actt-slug">%s</code>%s%s%s',
			esc_html( $snapshot->name ),
			esc_html( $snapshot->slug ),
			$note,
			$this->row_actions( $actions ),
			$this->details( $snapshot, $merge )
		);
	}

	/**
	 * Taxonomy name.
	 *
	 * @param array{id: int, snapshot: Snapshot} $item Row.
	 * @return string
	 */
	protected function column_taxonomy( $item ): string {
		$item_id  = (int) $item['id'];
		$snapshot = $this->snapshots[ $item_id ];
		$object   = get_taxonomy( $snapshot->taxonomy );

		if ( ! $object ) {
			return sprintf( '%s<br><span class="axle-badge axle-badge--warning">%s</span>', esc_html( $snapshot->taxonomy ), esc_html__( 'Not registered, so it cannot be restored', 'axle-category-tag-trash' ) );
		}

		return esc_html( $object->labels->singular_name );
	}

	/**
	 * Summary of what the term was attached to.
	 *
	 * @param array{id: int, snapshot: Snapshot} $item Row.
	 * @return string
	 */
	protected function column_attached( $item ): string {
		$item_id = (int) $item['id'];
		return esc_html( implode( ' · ', $this->summary( $this->snapshots[ $item_id ] ) ) );
	}

	/**
	 * When and by whom.
	 *
	 * @param array{id: int, snapshot: Snapshot} $item Row.
	 * @return string
	 */
	protected function column_deleted( $item ): string {
		$item_id = (int) $item['id'];
		$post    = get_post( $item_id );
		$deleter = get_userdata( (int) $post->post_author );
		$time    = (int) get_post_time( 'U', true, $post );

		return sprintf(
			'%s<br><span class="axle-muted">%s</span>',
			AxleUi::relative_time( $time ),
			/* translators: %s: user name. */
			esc_html( sprintf( __( 'by %s', 'axle-category-tag-trash' ), $deleter ? $deleter->display_name : __( 'WP-CLI or a script', 'axle-category-tag-trash' ) ) )
		);
	}

	/**
	 * Short counts: posts, child terms, menu links.
	 *
	 * @param Snapshot $snapshot Trashed term.
	 * @return string[]
	 */
	private function summary( Snapshot $snapshot ): array {
		/* translators: %s: number of posts or other items. */
		$parts = array( sprintf( _n( '%s item', '%s items', $snapshot->total_objects(), 'axle-category-tag-trash' ), number_format_i18n( $snapshot->total_objects() ) ) );

		if ( $snapshot->children ) {
			/* translators: %d: number of child terms. */
			$parts[] = sprintf( _n( '%d child term', '%d child terms', count( $snapshot->children ), 'axle-category-tag-trash' ), count( $snapshot->children ) );
		}
		if ( $snapshot->menu_items ) {
			/* translators: %d: number of menu links. */
			$parts[] = sprintf( _n( '%d menu link', '%d menu links', count( $snapshot->menu_items ), 'axle-category-tag-trash' ), count( $snapshot->menu_items ) );
		}

		return $parts;
	}

	/**
	 * Expandable list of the posts, child terms and menus restore will bring back.
	 *
	 * @param Snapshot   $snapshot Trashed term.
	 * @param array|null $merge    The merge saved with the item, when it was merged away.
	 * @return string
	 */
	private function details( Snapshot $snapshot, ?array $merge = null ): string {
		$lines = array();

		if ( $merge ) {
			$lines[] = '<strong>' . esc_html__( 'Merge:', 'axle-category-tag-trash' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: 1: name of the term it was merged into, 2: number of posts that got that term through the merge. */
						_n( 'Merged into "%1$s", which %2$s post got through the merge. Undo takes "%1$s" off just that post.', 'Merged into "%1$s", which %2$s posts got through the merge. Undo takes "%1$s" off just those posts.', (int) $merge['moved'], 'axle-category-tag-trash' ),
						$merge['target_name'],
						number_format_i18n( (int) $merge['moved'] )
					)
				);
		}

		$posts  = array();
		$sample = $snapshot->sample();
		_prime_post_caches( $sample, false, false );
		foreach ( $sample as $object_id ) {
			$post    = get_post( $object_id );
			$posts[] = $post
				? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post ) ), esc_html( get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'axle-category-tag-trash' ) ) )
				/* translators: %d: object ID. */
				: esc_html( sprintf( __( '#%d (deleted)', 'axle-category-tag-trash' ), $object_id ) );
		}
		if ( $posts ) {
			$more    = $snapshot->total_objects() - count( $posts );
			$lines[] = '<strong>' . esc_html__( 'Items:', 'axle-category-tag-trash' ) . '</strong> ' . implode( ', ', $posts )
				/* translators: %s: number of further items. */
				. ( $more > 0 ? ' ' . esc_html( sprintf( __( 'and %s more', 'axle-category-tag-trash' ), number_format_i18n( $more ) ) ) : '' );
		}

		$children = array();
		foreach ( $snapshot->children as $child_id ) {
			$child = get_term( $child_id, $snapshot->taxonomy );
			if ( $child instanceof \WP_Term ) {
				$children[] = esc_html( $child->name );
			}
		}
		if ( $children ) {
			$lines[] = '<strong>' . esc_html__( 'Child terms:', 'axle-category-tag-trash' ) . '</strong> ' . implode( ', ', $children );
		}

		$menus = array();
		foreach ( $snapshot->menu_items as $link ) {
			$menu    = wp_get_nav_menu_object( $link['menu_id'] );
			$menus[] = esc_html( $menu ? $menu->name : __( 'deleted menu', 'axle-category-tag-trash' ) );
		}
		if ( $menus ) {
			$lines[] = '<strong>' . esc_html__( 'Menus:', 'axle-category-tag-trash' ) . '</strong> ' . implode( ', ', array_unique( $menus ) );
		}

		if ( '' !== $snapshot->description ) {
			$lines[] = '<strong>' . esc_html__( 'Description:', 'axle-category-tag-trash' ) . '</strong> ' . esc_html( wp_trim_words( $snapshot->description, 30 ) );
		}

		if ( ! $lines ) {
			return '';
		}

		return sprintf(
			'<details class="actt-details"><summary>%s</summary><p>%s</p></details>',
			esc_html__( 'What restore brings back', 'axle-category-tag-trash' ),
			implode( '</p><p>', $lines )
		);
	}
}
