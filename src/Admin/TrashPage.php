<?php
/**
 * Tools > Axle Category & Tag Trash.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Admin;

use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\Purger;
use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Service;
use AryanX\Axle\CategoryTagTrash\Settings;
use AryanX\Axle\CategoryTagTrash\Trash;
use const AryanX\Axle\CategoryTagTrash\PLUGIN_FILE;
use const AryanX\Axle\CategoryTagTrash\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The trash screen: list, bulk actions, empty trash and the retention setting.
 */
final class TrashPage implements Service {

	public const SLUG   = 'axle-category-tag-trash';
	public const ACTION = 'axle_category_tag_trash';

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
	 * Purging and emptying.
	 *
	 * @var Purger
	 */
	private Purger $purger;

	/**
	 * Merging terms.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Sets up the screen.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 * @param Purger   $purger   Purging and emptying.
	 * @param Merger   $merger   Merging terms.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Purger $purger, Merger $merger ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
		$this->purger   = $purger;
		$this->merger   = $merger;
	}

	/**
	 * Capability needed to open Tools > Axle Category & Tag Trash.
	 *
	 * Each restore and delete still checks the term's own taxonomy.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to open Tools > Axle Category & Tag Trash.
		 *
		 * @param string $capability Capability. Default manage_categories.
		 */
		return (string) apply_filters( 'axle_category_tag_trash_capability', 'manage_categories' );
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds a link to the trash to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;

		if ( current_user_can( self::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open the trash', 'axle-category-tag-trash' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * URL that runs an action on trash items.
	 *
	 * @param string $task     restore or delete.
	 * @param int[]  $item_ids Trash item IDs.
	 * @param string $back     Where to return afterwards; the trash screen by default.
	 * @return string
	 */
	public static function action_url( string $task, array $item_ids, string $back = '' ): string {
		$ids = implode( ',', array_map( 'intval', $item_ids ) );

		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => self::ACTION,
						'task'   => $task,
						'items'  => $ids,
						'back'   => $back ? rawurlencode( $back ) : '',
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $task . '_' . $ids
		);
	}

	/**
	 * URL of the merge screen.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $term_ids Terms to merge away.
	 * @param int    $target   Term to merge into, once picked.
	 * @param string $search   Search text for the target.
	 * @return string
	 */
	public static function merge_url( string $taxonomy, array $term_ids, int $target = 0, string $search = '' ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'     => self::SLUG,
					'view'     => 'merge',
					'taxonomy' => $taxonomy,
					'terms'    => implode( ',', array_map( 'absint', $term_ids ) ),
					'target'   => $target,
					'q'        => rawurlencode( $search ),
				)
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Registers Tools > Axle Category & Tag Trash, and handles bulk actions before output.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Axle Category & Tag Trash', 'axle-category-tag-trash' ),
			__( 'Axle Category & Tag Trash', 'axle-category-tag-trash' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'handle_bulk_action' ) );
			add_action( "load-{$hook}", array( $this, 'add_help' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
			add_action( "admin_print_scripts-{$hook}", array( $this, 'enqueue_scripts' ) );
		}
	}

	/**
	 * Help tab content for the screen.
	 */
	public function add_help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-category-tag-trash' ),
					'content' => '<p>' . esc_html__( 'Deleted categories, tags and other terms wait here, so a wrong delete is not final. Open "What restore brings back" on a row to see its posts, child terms, menu links and description.', 'axle-category-tag-trash' ) . '</p>',
				),
				array(
					'id'      => 'restoring',
					'title'   => __( 'Restoring', 'axle-category-tag-trash' ),
					'content' => '<p>' . esc_html__( 'Restore puts a term back with its original ID, slug, meta, posts, child terms and menu links. A term on many posts is finished in the background, and the screen shows how far it got. If a term is not in the list, it was restored or deleted for good.', 'axle-category-tag-trash' ) . '</p>',
				),
				array(
					'id'      => 'merging',
					'title'   => __( 'Merging', 'axle-category-tag-trash' ),
					'content' => '<p>' . esc_html__( 'To merge terms, choose "Merge into…" on a term or in the bulk actions of Categories, Tags or another term screen. Pick the term to keep and check the preview. The merged-away term lands here.', 'axle-category-tag-trash' ) . '</p><p>' . esc_html__( 'Choose "Undo merge" on it to bring it back. The term you merged into comes off only the posts that got it through the merge. Posts that already had both keep both.', 'axle-category-tag-trash' ) . '</p>',
				),
				array(
					'id'      => 'retention',
					'title'   => __( 'Retention and emptying', 'axle-category-tag-trash' ),
					'content' => '<p>' . esc_html__( 'Terms are deleted for good after the number of days set under Retention. "Empty trash" and "Delete permanently" do it now. Neither can be undone.', 'axle-category-tag-trash' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-category-tag-trash-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * Screen script: the in-place confirm steps.
	 */
	public function enqueue_scripts(): void {
		wp_enqueue_script( 'axle-category-tag-trash-trash-screen', plugins_url( 'assets/trash-screen.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'axle-category-tag-trash-trash-screen',
			'axleCategoryTagTrashConfirm',
			array(
				'cancel' => __( 'Cancel', 'axle-category-tag-trash' ),
			)
		);
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		?>
		<div class="wrap axle-ui actt-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Category & Tag Trash', 'axle-category-tag-trash' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			if ( 'merge' === $view ) {
				$this->render_merge();
			} else {
				$this->render_trash();
			}

			AxleUi::footer();
			?>
		</div>
		<?php
	}

	/**
	 * Prints the trash: notices, running merges, the list and the settings.
	 */
	private function render_trash(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new TrashListTable( $this->trash );
		$table->prepare_items();
		$days      = $this->trash->settings()->retention_days();
		$total     = array_sum( $this->trash->count_by_taxonomy( Access::LIST ) );
		$deletable = array_sum( $this->trash->count_by_taxonomy( Access::DELETE ) );

		$this->render_notices();
		$this->render_running_merges();
		?>
		<p class="axle-intro actt-intro">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Deleted categories, tags and other terms are kept here for %d day, then deleted for good.', 'Deleted categories, tags and other terms are kept here for %d days, then deleted for good.', $days, 'axle-category-tag-trash' ),
					$days
				)
			);
			?>
		</p>

		<?php if ( $total || $table->has_items() ) : ?>
			<form method="get" id="actt-list-form" data-confirm-id="actt-bulk-confirm">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( $table->current_taxonomy() ) : ?>
					<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $table->current_taxonomy() ); ?>">
				<?php endif; ?>
				<div class="axle-toolbar">
					<?php $table->views(); ?>
					<span class="axle-toolbar__end">
						<?php $table->search_box( __( 'Search trash', 'axle-category-tag-trash' ), 'axle-category-tag-trash' ); ?>
					</span>
				</div>
				<div id="actt-bulk-confirm" class="axle-confirm axle-confirm--danger" role="group" aria-labelledby="actt-bulk-confirm-text" hidden>
					<p id="actt-bulk-confirm-text"><?php esc_html_e( 'Delete the selected terms for good? They leave the trash and cannot be restored. This cannot be undone.', 'axle-category-tag-trash' ); ?></p>
					<div class="axle-actions">
						<button type="button" class="button axle-button--danger actt-confirm-yes"><?php esc_html_e( 'Delete selected terms for good', 'axle-category-tag-trash' ); ?></button>
						<button type="button" class="button-link actt-confirm-no"><?php esc_html_e( 'Cancel', 'axle-category-tag-trash' ); ?></button>
					</div>
				</div>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Trashed terms', 'axle-category-tag-trash' ); ?>">
					<?php $table->display(); ?>
				</div>
			</form>
		<?php else : ?>
			<div class="axle-empty">
				<p><strong><?php esc_html_e( 'The trash is empty.', 'axle-category-tag-trash' ); ?></strong></p>
				<p><?php esc_html_e( 'Categories, tags and other terms you delete will show up here for a while, with everything a restore brings back. You can also merge two terms into one, and undo it from here.', 'axle-category-tag-trash' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=post_tag' ) ); ?>"><?php esc_html_e( 'Go to tags', 'axle-category-tag-trash' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=category' ) ); ?>"><?php esc_html_e( 'Go to categories', 'axle-category-tag-trash' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<?php
		$this->render_retention_form( $days );
		$this->render_empty_card( $deletable );
	}

	/**
	 * Prints the danger card with Empty trash.
	 *
	 * @param int $count Number of trashed terms the user may delete.
	 */
	private function render_empty_card( int $count ): void {
		if ( ! $count ) {
			return;
		}
		?>
		<section class="axle-card axle-card--danger">
			<h2 class="axle-card__title"><?php esc_html_e( 'Empty the trash', 'axle-category-tag-trash' ); ?></h2>
			<p><?php esc_html_e( 'Deletes every term in the trash that you can manage, for good. Restore can no longer bring them back. This cannot be undone.', 'axle-category-tag-trash' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm-id="actt-empty-confirm">
				<?php wp_nonce_field( self::ACTION . '_empty' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="empty">
				<div id="actt-empty-confirm" class="axle-confirm axle-confirm--danger" role="group" aria-labelledby="actt-empty-confirm-text" hidden>
					<p id="actt-empty-confirm-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of trashed terms. */
								_n( 'Delete %s term for good? It cannot be restored afterwards. This cannot be undone.', 'Delete %s terms for good? They cannot be restored afterwards. This cannot be undone.', $count, 'axle-category-tag-trash' ),
								number_format_i18n( $count )
							)
						);
						?>
					</p>
					<div class="axle-actions">
						<button type="submit" class="button axle-button--danger"><?php esc_html_e( 'Delete everything for good', 'axle-category-tag-trash' ); ?></button>
						<button type="button" class="button-link actt-confirm-no"><?php esc_html_e( 'Cancel', 'axle-category-tag-trash' ); ?></button>
					</div>
				</div>
				<button type="submit" class="button axle-button--danger actt-confirm-open"><?php esc_html_e( 'Empty trash', 'axle-category-tag-trash' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints a progress card for merges that are still moving posts.
	 */
	private function render_running_merges(): void {
		$jobs = array();
		foreach ( $this->merger->running() as $job_id => $job ) {
			if ( Access::can( (string) $job['taxonomy'], Access::MERGE ) ) {
				$jobs[ $job_id ] = $job;
			}
		}

		if ( ! $jobs ) {
			return;
		}
		?>
		<section class="axle-card actt-running" aria-busy="true">
			<h2 class="axle-card__title"><span class="spinner is-active actt-spinner"></span> <?php esc_html_e( 'Merging in the background', 'axle-category-tag-trash' ); ?></h2>
			<p><?php esc_html_e( 'These merges move posts a batch at a time through WP-Cron. When one finishes, the merged-away term appears in the list below with an Undo link. Reload this page to see how far they got.', 'axle-category-tag-trash' ); ?></p>
			<?php foreach ( $jobs as $job ) : ?>
				<?php
				$percent = $job['total'] > 0 ? (int) min( 100, floor( 100 * $job['moved'] / $job['total'] ) ) : 0;
				$label   = sprintf(
					/* translators: 1: source term name, 2: target term name, 3: posts moved so far, 4: posts in total. */
					__( '%1$s into %2$s: %3$s of %4$s moved', 'axle-category-tag-trash' ),
					$job['source_name'],
					$job['target_name'],
					number_format_i18n( (int) $job['moved'] ),
					number_format_i18n( (int) $job['total'] )
				);
				?>
				<p><?php echo esc_html( $label ); ?> (<?php echo esc_html( (string) $percent ); ?>%)</p>
				<div class="axle-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( $label ); ?>"><span class="axle-progress__bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></span></div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Prints the merge screen: pick the target, then check the preview and confirm.
	 */
	private function render_merge(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only; nothing changes until the merge form is posted with its own nonce.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$raw      = isset( $_GET['terms'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['terms'] ) ) ) : array();
		$target   = isset( $_GET['target'] ) ? absint( $_GET['target'] ) : 0;
		$search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $taxonomy || ! get_taxonomy( $taxonomy ) || ! Access::can( $taxonomy, Access::MERGE ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You cannot merge terms of this taxonomy, or it does not exist.', 'axle-category-tag-trash' ) . '</p></div>';
			return;
		}

		$sources = array();
		foreach ( array_unique( array_filter( array_map( 'absint', $raw ) ) ) as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term instanceof \WP_Term && current_user_can( 'delete_term', $term->term_id ) && ! Merger::is_default_term( $term ) && $term_id !== $target ) {
				$sources[ $term_id ] = $term;
			}
		}

		if ( ! $sources ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'There is nothing to merge. The terms are gone, are the default term, or you may not delete them.', 'axle-category-tag-trash' ) . ' <a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy ) ) . '">' . esc_html__( 'Back to the terms', 'axle-category-tag-trash' ) . '</a></p></div>';
			return;
		}

		$object = get_taxonomy( $taxonomy );
		?>
		<p class="axle-intro actt-intro">
			<?php esc_html_e( 'Merging moves every post of the terms below to the term you pick. The merged-away terms go to the trash, and Undo brings them back.', 'axle-category-tag-trash' ); ?>
		</p>

		<section class="axle-card">
			<h2 class="axle-card__title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: taxonomy label, e.g. "Tags". */
						_n( 'Merge away (%s)', 'Merge away (%s)', count( $sources ), 'axle-category-tag-trash' ),
						$object->labels->name
					)
				);
				?>
			</h2>
			<ul class="actt-sources">
				<?php foreach ( $sources as $term ) : ?>
					<li><strong><?php echo esc_html( $term->name ); ?></strong> <code><?php echo esc_html( $term->slug ); ?></code>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of posts. */
								_n( '%s post', '%s posts', (int) $term->count, 'axle-category-tag-trash' ),
								number_format_i18n( (int) $term->count )
							)
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php
		if ( $target ) {
			$this->render_merge_preview( $taxonomy, $sources, $target, $search );
		} else {
			$this->render_merge_picker( $taxonomy, $sources, $search );
		}
	}

	/**
	 * Step one: search for the term to merge into.
	 *
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<int, \WP_Term> $sources  Terms to merge away.
	 * @param string               $search   Search text.
	 */
	private function render_merge_picker( string $taxonomy, array $sources, string $search ): void {
		/**
		 * Filters how many terms the merge screen lists as possible targets.
		 *
		 * @param int    $limit    Default 20, at most 100.
		 * @param string $taxonomy Taxonomy name.
		 */
		$limit = max( 1, min( 100, (int) apply_filters( 'axle_category_tag_trash_merge_search_limit', 20, $taxonomy ) ) );
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => $search,
				'number'     => $limit + count( $sources ),
				'orderby'    => '' === $search ? 'count' : 'name',
				'order'      => '' === $search ? 'DESC' : 'ASC',
			)
		);
		$found = is_wp_error( $found ) ? array() : array_filter(
			$found,
			static function ( $term ): bool {
				return current_user_can( 'edit_term', $term->term_id );
			}
		);
		// The sources cannot be their own target. Left out here rather than in the query, which would need an exclusion.
		$found  = array_slice(
			array_values(
				array_filter(
					$found,
					static function ( \WP_Term $term ) use ( $sources ): bool {
						return ! isset( $sources[ (int) $term->term_id ] );
					}
				)
			),
			0,
			$limit
		);
		$hidden = array(
			'page'     => self::SLUG,
			'view'     => 'merge',
			'taxonomy' => $taxonomy,
			'terms'    => implode( ',', array_keys( $sources ) ),
		);
		?>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Merge into', 'axle-category-tag-trash' ); ?></h2>
			<form method="get" class="axle-toolbar" role="search">
				<?php foreach ( $hidden as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
				<?php endforeach; ?>
				<label for="actt-merge-search"><?php esc_html_e( 'Find the term to keep', 'axle-category-tag-trash' ); ?></label>
				<input type="search" id="actt-merge-search" name="q" value="<?php echo esc_attr( $search ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'axle-category-tag-trash' ); ?></button>
			</form>

			<?php if ( ! $found ) : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'No term matches. Try a shorter search.', 'axle-category-tag-trash' ); ?></p>
				</div>
			<?php else : ?>
				<form method="get">
					<?php foreach ( $hidden as $name => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
					<?php endforeach; ?>
					<input type="hidden" name="q" value="<?php echo esc_attr( $search ); ?>">
					<fieldset>
						<legend><?php echo esc_html( '' === $search ? __( 'Most used terms', 'axle-category-tag-trash' ) : __( 'Matching terms', 'axle-category-tag-trash' ) ); ?></legend>
						<?php foreach ( $found as $index => $term ) : ?>
							<p>
								<label>
									<input type="radio" name="target" value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php checked( 0, $index ); ?>>
									<strong><?php echo esc_html( $term->name ); ?></strong> <code><?php echo esc_html( $term->slug ); ?></code>
									<span class="axle-muted">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of posts. */
												_n( '%s post', '%s posts', (int) $term->count, 'axle-category-tag-trash' ),
												number_format_i18n( (int) $term->count )
											)
										);
										?>
									</span>
								</label>
							</p>
						<?php endforeach; ?>
					</fieldset>
					<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Preview the merge', 'axle-category-tag-trash' ); ?></button></p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Step two: what the merge will do, and the confirm step.
	 *
	 * @param string               $taxonomy Taxonomy name.
	 * @param array<int, \WP_Term> $sources  Terms to merge away.
	 * @param int                  $target   Term to merge into.
	 * @param string               $search   Search text, to go back to the list.
	 */
	private function render_merge_preview( string $taxonomy, array $sources, int $target, string $search ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only choice shown in the preview.
		$children = isset( $_GET['children'] ) ? sanitize_key( wp_unslash( $_GET['children'] ) ) : '';
		$previews = array();
		$errors   = array();

		foreach ( $sources as $term_id => $term ) {
			$preview = $this->merger->preview( $term_id, $target, $taxonomy, $children );
			if ( is_wp_error( $preview ) ) {
				$errors[] = $preview->get_error_message();
			} else {
				$previews[ $term_id ] = $preview;
			}
		}

		$change = self::merge_url( $taxonomy, array_keys( $sources ), 0, $search );

		if ( ! $previews ) {
			foreach ( array_unique( $errors ) as $error ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
			}
			echo '<p><a class="button" href="' . esc_url( $change ) . '">' . esc_html__( 'Pick another term', 'axle-category-tag-trash' ) . '</a></p>';
			return;
		}

		$first       = reset( $previews );
		$target_name = $first['target_name'];
		$moving      = array_sum( wp_list_pluck( $previews, 'moving' ) );
		$kids        = array_sum( wp_list_pluck( $previews, 'children' ) );
		$names       = wp_list_pluck( $previews, 'source_name' );
		$policy      = $first['children_policy'];
		?>
		<?php foreach ( array_unique( $errors ) as $error ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endforeach; ?>

		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'What will change', 'axle-category-tag-trash' ); ?></h2>
			<div class="axle-diff">
				<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'axle-category-tag-trash' ); ?></span> <?php echo esc_html( implode( ', ', $names ) ); ?></del>
				<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'axle-category-tag-trash' ); ?></span> <?php echo esc_html( $target_name ); ?></ins>
			</div>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Merge preview', 'axle-category-tag-trash' ); ?>">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Term', 'axle-category-tag-trash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Posts', 'axle-category-tag-trash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Already on the target', 'axle-category-tag-trash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Posts that move', 'axle-category-tag-trash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Child terms', 'axle-category-tag-trash' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Menu links', 'axle-category-tag-trash' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $previews as $preview ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $preview['source_name'] ); ?></th>
								<td><?php echo esc_html( number_format_i18n( $preview['objects'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $preview['already'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $preview['moving'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $preview['children'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $preview['menu_links'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<ul class="actt-notes">
				<li><?php esc_html_e( 'The name, slug, description and meta of the term you keep do not change. The merged-away terms keep theirs in the trash for Undo.', 'axle-category-tag-trash' ); ?></li>
				<li><?php esc_html_e( 'Posts that already have both terms keep one copy of the target.', 'axle-category-tag-trash' ); ?></li>
				<li><?php esc_html_e( 'Menu links to a merged-away term point at the term you keep.', 'axle-category-tag-trash' ); ?></li>
			</ul>
		</section>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION . '_merge' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="task" value="merge">
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>">
			<input type="hidden" name="terms" value="<?php echo esc_attr( implode( ',', array_keys( $previews ) ) ); ?>">
			<input type="hidden" name="target" value="<?php echo esc_attr( (string) $target ); ?>">

			<?php if ( $kids ) : ?>
				<section class="axle-card">
					<fieldset>
						<legend class="axle-card__title"><?php esc_html_e( 'Child terms', 'axle-category-tag-trash' ); ?></legend>
						<p><label><input type="radio" name="children" value="<?php echo esc_attr( Merger::CHILDREN_TARGET ); ?>" <?php checked( Merger::CHILDREN_TARGET, $policy ); ?>> <?php echo esc_html( sprintf( /* translators: %s: target term name. */ __( 'Move them under "%s"', 'axle-category-tag-trash' ), $target_name ) ); ?></label></p>
						<p><label><input type="radio" name="children" value="<?php echo esc_attr( Merger::CHILDREN_PARENT ); ?>" <?php checked( Merger::CHILDREN_PARENT, $policy ); ?>> <?php esc_html_e( 'Move them up one level, like a normal delete', 'axle-category-tag-trash' ); ?></label></p>
					</fieldset>
				</section>
			<?php endif; ?>

			<div class="axle-confirm" role="group" aria-labelledby="actt-merge-confirm-text">
				<p id="actt-merge-confirm-text">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of posts that move, 2: names of the terms merged away, 3: name of the term kept. */
							_n( 'This moves %1$s post from %2$s to "%3$s" and puts the old term in the trash. You can undo it from the trash.', 'This moves %1$s posts from %2$s to "%3$s" and puts the old terms in the trash. You can undo it from the trash.', max( 1, $moving ), 'axle-category-tag-trash' ),
							number_format_i18n( $moving ),
							'"' . implode( '", "', $names ) . '"',
							$target_name
						)
					);
					?>
				</p>
				<div class="axle-actions">
					<button type="submit" class="button button-primary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of terms, 2: name of the term kept. */
								_n( 'Merge %1$s term into "%2$s"', 'Merge %1$s terms into "%2$s"', count( $previews ), 'axle-category-tag-trash' ),
								number_format_i18n( count( $previews ) ),
								$target_name
							)
						);
						?>
					</button>
					<a class="button" href="<?php echo esc_url( $change ); ?>"><?php esc_html_e( 'Pick another term', 'axle-category-tag-trash' ); ?></a>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy ) ); ?>"><?php esc_html_e( 'Cancel', 'axle-category-tag-trash' ); ?></a>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Prints the "Keep deleted terms for" setting.
	 *
	 * @param int $days Current retention in days.
	 */
	private function render_retention_form( int $days ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Retention', 'axle-category-tag-trash' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_settings' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="actt-retention"><?php esc_html_e( 'Keep deleted terms for', 'axle-category-tag-trash' ); ?></label></th>
						<td>
							<select id="actt-retention" name="retention_days">
								<?php foreach ( Settings::retention_choices() as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $days, $option ); ?>>
										<?php
										/* translators: %d: number of days. */
										echo esc_html( sprintf( _n( '%d day', '%d days', $option, 'axle-category-tag-trash' ), $option ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'After this time a term is deleted for good and can no longer be restored.', 'axle-category-tag-trash' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save retention', 'axle-category-tag-trash' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$restored = isset( $_GET['actt_restored'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['actt_restored'] ) ) ) ) ) : array();
		$deleted  = isset( $_GET['actt_deleted'] ) ? absint( $_GET['actt_deleted'] ) : 0;
		$saved    = isset( $_GET['actt_saved'] );
		$notes    = isset( $_GET['actt_notes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['actt_notes'] ) ) : array();
		$errors   = isset( $_GET['actt_errors'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['actt_errors'] ) ) : array();
		$merged   = isset( $_GET['actt_merged'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['actt_merged'] ) ) ) ) ) : array();
		$merging  = isset( $_GET['actt_merging'] ) ? absint( $_GET['actt_merging'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::print_restored_notice( $restored, $notes );
		$this->print_merged_notice( $merged, $merging, $restored ? array() : $notes );

		if ( $deleted ) {
			/* translators: %d: number of terms. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d term deleted for good.', '%d terms deleted for good.', $deleted, 'axle-category-tag-trash' ), $deleted ) ) );
		}

		if ( $saved ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved.', 'axle-category-tag-trash' ) );
		}

		foreach ( $errors as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Prints what a merge did, with an Undo link.
	 *
	 * @param int[]    $item_ids Trash items of the terms that were merged away.
	 * @param int      $running  Merges that carry on in the background.
	 * @param string[] $notes    Notes to show under it.
	 */
	private function print_merged_notice( array $item_ids, int $running, array $notes ): void {
		$lines = array();
		$undo  = array();
		$moved = 0;

		foreach ( $item_ids as $item_id ) {
			$snapshot = $this->trash->get( $item_id );
			$merge    = $snapshot ? $this->trash->merge_info( $item_id ) : null;
			if ( ! $snapshot || ! $merge || ! $this->trash->user_can_manage( $snapshot, Access::RESTORE ) ) {
				continue;
			}
			$undo[]  = $item_id;
			$moved  += (int) $merge['moved'];
			$lines[] = sprintf( '"%s"', $snapshot->name ) . ' &rarr; ' . sprintf( '"%s"', $merge['target_name'] );
		}

		$message = '';
		if ( $undo ) {
			$message = sprintf(
				/* translators: %s: number of terms merged. */
				_n( 'Merged %s term.', 'Merged %s terms.', count( $undo ), 'axle-category-tag-trash' ),
				number_format_i18n( count( $undo ) )
			) . ' ' . sprintf(
				/* translators: %s: number of posts or other items that got the target term. */
				_n( '%s item moved.', '%s items moved.', $moved, 'axle-category-tag-trash' ),
				number_format_i18n( $moved )
			);
			/**
			 * Filters the notice shown after a merge.
			 *
			 * @param string $message Plain text of the notice.
			 * @param int[]  $item_ids Trash items of the merged-away terms.
			 * @param int    $moved    Posts that moved.
			 */
			$message = (string) apply_filters( 'axle_category_tag_trash_merged_notice', $message, $undo, $moved );
		}

		if ( ! $message && ! $running ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible actt-merged" role="status">';
		if ( $message ) {
			echo '<p>' . esc_html( $message ) . ' ' . wp_kses( implode( ', ', $lines ), array() ) . ' ';
			printf( '<a href="%s" class="button button-small">%s</a></p>', esc_url( self::action_url( 'restore', $undo ) ), esc_html__( 'Undo merge', 'axle-category-tag-trash' ) );
		}
		if ( $running ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of merges. */
					_n( '%s merge is big, so it carries on in the background.', '%s merges are big, so they carry on in the background.', $running, 'axle-category-tag-trash' ),
					number_format_i18n( $running )
				)
			) . '</p>';
		}
		foreach ( $notes as $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Prints "Restored X" with links to the restored terms, plus any notes.
	 *
	 * @param int[]    $term_ids Restored term IDs.
	 * @param string[] $notes    Notes about anything that changed.
	 */
	public static function print_restored_notice( array $term_ids, array $notes ): void {
		$links = array();
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_term_link( $term ) ), esc_html( $term->name ) );
			}
		}

		if ( ! $links ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible actt-restored"><p>';
		/* translators: %s: list of restored term names. */
		printf( esc_html__( 'Restored %s.', 'axle-category-tag-trash' ), wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ) );
		echo '</p>';
		foreach ( $notes as $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Runs list table bulk actions, then redirects to a clean URL.
	 */
	public function handle_bulk_action(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below once an action is present.
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! $action && isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		$item_ids = isset( $_REQUEST['items'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['items'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'restore', 'delete' ), true ) ) {
			return;
		}

		check_admin_referer( 'bulk-axle_category_tag_trash_items' );
		$this->run( $action, $item_ids, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * Handles row actions, Undo links, Empty trash and the retention setting.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to use Axle Category & Tag Trash.', 'axle-category-tag-trash' ), 403 );
		}

		$task  = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$items = isset( $_REQUEST['items'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['items'] ) ) : '';
		$back  = admin_url( 'tools.php?page=' . self::SLUG );

		if ( 'settings' === $task ) {
			check_admin_referer( self::ACTION . '_settings' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to change this setting.', 'axle-category-tag-trash' ), 403 );
			}
			$this->trash->settings()->set_retention_days( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30 );
			wp_safe_redirect( add_query_arg( 'actt_saved', '1', $back ) );
			exit;
		}

		if ( 'merge' === $task ) {
			check_admin_referer( self::ACTION . '_merge' );
			$this->run_merge( $back );
		}

		if ( 'empty' === $task ) {
			check_admin_referer( self::ACTION . '_empty' );
			// Only taxonomies the user may delete from. A user who may delete from all of them
			// also lets the rest go in the background.
			$allowed = $this->trash->accessible_taxonomies( Access::DELETE );
			$all     = count( $allowed ) === count( $this->trash->count_by_taxonomy() ) && current_user_can( 'manage_options' );
			$result  = $this->purger->empty_trash( 200, $all ? null : $allowed );
			$args    = array( 'actt_deleted' => $result['deleted'] );
			if ( $result['remaining'] && ! $all ) {
				$args['actt_notes'] = array( rawurlencode( __( 'Some terms are left because you cannot delete them. Empty the trash again to delete the rest of what you may.', 'axle-category-tag-trash' ) ) );
			}
			if ( $result['remaining'] && $all ) {
				$args['actt_notes'] = array(
					rawurlencode(
						sprintf(
							/* translators: %s: number of trashed terms. */
							_n( 'The last %s term is being deleted in the background.', 'The other %s terms are being deleted in the background.', $result['remaining'], 'axle-category-tag-trash' ),
							number_format_i18n( $result['remaining'] )
						)
					),
				);
			}
			wp_safe_redirect( add_query_arg( array_filter( $args ), $back ) );
			exit;
		}

		check_admin_referer( self::ACTION . '_' . $task . '_' . $items );

		if ( isset( $_REQUEST['back'] ) ) {
			$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['back'] ) ), $back );
		}

		$this->run( $task, array_map( 'absint', explode( ',', $items ) ), $back );
	}

	/**
	 * Merges the posted terms into the posted target, then redirects with the result.
	 *
	 * @param string $back The trash screen.
	 */
	private function run_merge( string $back ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked in handle_action() before this runs.
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$target   = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
		$children = isset( $_POST['children'] ) ? sanitize_key( wp_unslash( $_POST['children'] ) ) : '';
		$sources  = isset( $_POST['terms'] ) ? array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['terms'] ) ) ) ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$merged  = array();
		$errors  = array();
		$notes   = array();
		$running = 0;

		foreach ( $sources as $source_id ) {
			if ( $source_id === $target ) {
				continue;
			}

			$result = $this->merger->merge( $source_id, $target, $taxonomy, array( 'children' => $children ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}

			$notes = array_merge( $notes, $result['notes'] );
			if ( $result['done'] && $result['item_id'] ) {
				$merged[] = $result['item_id'];
			} elseif ( ! $result['done'] ) {
				++$running;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'actt_merged'  => implode( ',', $merged ),
						'actt_merging' => $running,
						'actt_notes'   => array_map( 'rawurlencode', $notes ),
						'actt_errors'  => array_map( 'rawurlencode', $errors ),
					)
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Restores or deletes items, then redirects with the result.
	 *
	 * @param string $task     restore or delete.
	 * @param int[]  $item_ids Trash item IDs.
	 * @param string $back     URL to return to.
	 */
	private function run( string $task, array $item_ids, string $back ): void {
		$args = array();

		if ( 'restore' === $task ) {
			$result = $this->restorer->restore_many( $item_ids );
			$args   = array(
				'actt_restored' => implode( ',', $result['restored'] ),
				'actt_notes'    => array_map( 'rawurlencode', $result['notes'] ),
				'actt_errors'   => array_map( 'rawurlencode', $result['errors'] ),
			);
		} elseif ( 'delete' === $task ) {
			$deleted = 0;
			foreach ( $item_ids as $item_id ) {
				$snapshot = $this->trash->get( $item_id );
				if ( $snapshot && $this->trash->user_can_manage( $snapshot, Access::DELETE ) ) {
					$deleted += (int) $this->trash->delete( $item_id );
				}
			}
			$args = array( 'actt_deleted' => $deleted );
		}

		wp_safe_redirect( add_query_arg( array_filter( $args ), $back ) );
		exit;
	}
}
