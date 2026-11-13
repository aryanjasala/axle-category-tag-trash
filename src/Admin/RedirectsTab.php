<?php
/**
 * The Redirects tab, and the "if deleted" field on term edit screens.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Admin;

use AryanX\Axle\CategoryTagTrash\Access;
use AryanX\Axle\CategoryTagTrash\Redirects;
use AryanX\Axle\CategoryTagTrash\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Lists old term addresses with their hits, removes them, and undoes a removal.
 */
final class RedirectsTab implements Service {

	private const NONCE = 'axle_category_tag_trash_redirect_to';
	private const FIELD = 'axle_category_tag_trash_redirect_to';

	/**
	 * Redirects.
	 *
	 * @var Redirects
	 */
	private Redirects $redirects;

	/**
	 * Sets up the tab.
	 *
	 * @param Redirects $redirects Redirects.
	 */
	public function __construct( Redirects $redirects ) {
		$this->redirects = $redirects;
	}

	/**
	 * Adds the term edit field to every taxonomy that has redirects.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_fields' ) );
	}

	/**
	 * Hooks the field into each taxonomy's edit screen.
	 */
	public function add_fields(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( Redirects::supports( $taxonomy ) ) {
				add_action( "{$taxonomy}_edit_form_fields", array( $this, 'term_field' ), 20, 2 );
				add_action( "edited_{$taxonomy}", array( $this, 'save_field' ), 10, 1 );
			}
		}
	}

	/**
	 * URL of the tab.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'view' => 'redirects' ), $args ), admin_url( 'tools.php?page=' . TrashPage::SLUG ) );
	}

	/**
	 * URL that removes a redirect, or undoes the last removal.
	 *
	 * @param string $task redirect_remove or redirect_undo.
	 * @param string $id   Redirect ID, or "undo".
	 * @return string
	 */
	public static function action_url( string $task, string $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => TrashPage::ACTION,
					'task'   => $task,
					'items'  => $id,
				),
				admin_url( 'admin-post.php' )
			),
			TrashPage::ACTION . '_' . $task . '_' . $id
		);
	}

	/**
	 * Prints "If this term is deleted" on a term's edit screen.
	 *
	 * @param mixed  $term     The term being edited, a WP_Term.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function term_field( $term, $taxonomy ): void {
		if ( ! $term instanceof \WP_Term || ! Access::can( (string) $taxonomy, Access::REDIRECTS ) || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}

		/**
		 * Filters how many terms the "If this term is deleted" list offers.
		 *
		 * @param int    $limit    Default 200.
		 * @param string $taxonomy Taxonomy name.
		 */
		$limit = max( 1, (int) apply_filters( 'axle_category_tag_trash_redirect_choices_limit', 200, (string) $taxonomy ) );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( self::FIELD ); ?>"><?php esc_html_e( 'If this term is deleted', 'axle-category-tag-trash' ); ?></label></th>
			<td>
				<?php wp_nonce_field( self::NONCE, self::NONCE . '_nonce' ); ?>
				<?php
				wp_dropdown_categories(
					array(
						'taxonomy'          => $taxonomy,
						'name'              => self::FIELD,
						'id'                => self::FIELD,
						'hide_empty'        => 0,
						'hierarchical'      => is_taxonomy_hierarchical( $taxonomy ),
						'orderby'           => 'name',
						'number'            => $limit,
						'exclude'           => array( (int) $term->term_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A term list, one ID.
						'selected'          => (int) get_term_meta( $term->term_id, Redirects::TARGET_META, true ),
						'show_option_none'  => __( 'Do nothing, the old address shows a 404', 'axle-category-tag-trash' ),
						'option_none_value' => '0',
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Send this term\'s address to another term if you delete this one. Slug changes and merges redirect on their own.', 'axle-category-tag-trash' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the redirect target of a term.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_field( $term_id ): void {
		$term_id = (int) $term_id;
		$nonce   = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term || ! Access::can( $term->taxonomy, Access::REDIRECTS ) ) {
			return;
		}

		$target = isset( $_POST[ self::FIELD ] ) ? absint( $_POST[ self::FIELD ] ) : 0;
		$found  = $target && $target !== $term_id ? get_term( $target, $term->taxonomy ) : null;

		if ( $found instanceof \WP_Term ) {
			update_term_meta( $term_id, Redirects::TARGET_META, $target );
		} else {
			delete_term_meta( $term_id, Redirects::TARGET_META );
		}
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters and display values set by our own redirect.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$removed = isset( $_GET['actt_redirect_removed'] ) ? absint( $_GET['actt_redirect_removed'] ) : 0;
		$undone  = isset( $_GET['actt_redirect_undone'] ) ? absint( $_GET['actt_redirect_undone'] ) : 0;
		$error   = isset( $_GET['actt_redirect_error'] ) ? sanitize_text_field( wp_unslash( $_GET['actt_redirect_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters how many redirects the Redirects tab shows on a page.
		 *
		 * @param int $per_page Default 50.
		 */
		$per_page = max( 5, min( 200, (int) apply_filters( 'axle_category_tag_trash_redirects_per_page', 50 ) ) );
		$found    = Redirects::find(
			array(
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$everyone = $search ? Redirects::find( array( 'per_page' => 1 ) )['total'] : $found['total'];

		$this->render_notices( $removed, $undone, $error );
		?>
		<p class="axle-intro actt-intro"><?php esc_html_e( 'When you change a term\'s slug, merge it, or delete it with a redirect target, its old address is sent to the new one. Visitors and search engines land on the right page instead of a 404. Feeds and numbered pages of the old address are sent along.', 'axle-category-tag-trash' ); ?></p>

		<?php if ( ! $everyone ) : ?>
			<div class="axle-empty">
				<p><strong><?php esc_html_e( 'No old addresses redirect yet.', 'axle-category-tag-trash' ); ?></strong></p>
				<p><?php esc_html_e( 'Change the slug of a category or tag, merge two terms, or pick a redirect on a term before you delete it. The old address will show up here, with how many times it was used.', 'axle-category-tag-trash' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=category' ) ); ?>"><?php esc_html_e( 'Go to categories', 'axle-category-tag-trash' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=post_tag' ) ); ?>"><?php esc_html_e( 'Go to tags', 'axle-category-tag-trash' ); ?></a>
				</p>
			</div>
			<?php
			return;
		endif;
		?>

		<form method="get" class="axle-toolbar">
			<input type="hidden" name="page" value="<?php echo esc_attr( TrashPage::SLUG ); ?>">
			<input type="hidden" name="view" value="redirects">
			<span class="axle-toolbar__end">
				<label class="screen-reader-text" for="actt-redirect-search"><?php esc_html_e( 'Search redirects', 'axle-category-tag-trash' ); ?></label>
				<input type="search" id="actt-redirect-search" name="s" value="<?php echo esc_attr( $search ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search redirects', 'axle-category-tag-trash' ); ?></button>
			</span>
		</form>

		<?php if ( ! $found['items'] ) : ?>
			<div class="axle-empty">
				<p><strong><?php esc_html_e( 'No redirects match that search.', 'axle-category-tag-trash' ); ?></strong></p>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show all redirects', 'axle-category-tag-trash' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>

		<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Redirects', 'axle-category-tag-trash' ); ?>">
			<table class="widefat striped actt-redirects">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Old address', 'axle-category-tag-trash' ); ?></th>
						<th scope="col"><?php esc_html_e( 'New address', 'axle-category-tag-trash' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Why', 'axle-category-tag-trash' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Since', 'axle-category-tag-trash' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Hits', 'axle-category-tag-trash' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Remove', 'axle-category-tag-trash' ); ?></th>
					</tr>
				</thead>
				<tbody id="the-list">
					<?php foreach ( $found['items'] as $row ) : ?>
						<tr>
							<td><code class="actt-old"><?php echo esc_html( '/' . $row['path'] . '/' ); ?></code></td>
							<td>
								<?php if ( $row['target_gone'] ) : ?>
									<span class="axle-badge axle-badge--warning"><?php esc_html_e( 'Target deleted', 'axle-category-tag-trash' ); ?></span>
									<span class="axle-muted"><?php esc_html_e( 'This address shows a 404 until the term comes back.', 'axle-category-tag-trash' ); ?></span>
								<?php else : ?>
									<a href="<?php echo esc_url( $row['new_url'] ); ?>"><?php echo esc_html( $row['term_name'] ); ?></a>
									<br><code class="actt-new"><?php echo esc_html( (string) wp_parse_url( $row['new_url'], PHP_URL_PATH ) ); ?></code>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::kind_label( $row['kind'] ) ); ?></td>
							<td><?php echo wp_kses_post( AxleUi::relative_time( $row['created'] ) ); ?></td>
							<td>
								<?php echo esc_html( number_format_i18n( $row['hits'] ) ); ?>
								<?php if ( $row['last_hit'] ) : ?>
									<br><span class="axle-muted"><?php echo wp_kses_post( AxleUi::relative_time( $row['last_hit'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( Access::can( $row['taxonomy'], Access::REDIRECTS ) ) : ?>
									<a
										class="button button-small actt-delete"
										href="<?php echo esc_url( self::action_url( 'redirect_remove', $row['id'] ) ); ?>"
										data-title="<?php esc_attr_e( 'Remove this redirect?', 'axle-category-tag-trash' ); ?>"
										data-body="<?php esc_attr_e( 'The old address shows a 404 again. You can undo this right after.', 'axle-category-tag-trash' ); ?>"
										data-yes="<?php esc_attr_e( 'Remove redirect', 'axle-category-tag-trash' ); ?>"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: old address. */ __( 'Remove the redirect from %s', 'axle-category-tag-trash' ), '/' . $row['path'] . '/' ) ); ?>"
									><?php esc_html_e( 'Remove', 'axle-category-tag-trash' ); ?></a>
								<?php else : ?>
									<span class="axle-muted"><?php esc_html_e( 'Not allowed', 'axle-category-tag-trash' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		$pages = (int) ceil( $found['total'] / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', self::url( array_filter( array( 's' => $search ) ) ) ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => __( 'Previous', 'axle-category-tag-trash' ),
						'next_text' => __( 'Next', 'axle-category-tag-trash' ),
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * What a redirect came from, in words.
	 *
	 * @param string $kind One of the Redirects::KIND_ constants.
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			Redirects::KIND_RENAME => __( 'Slug changed', 'axle-category-tag-trash' ),
			Redirects::KIND_MOVE   => __( 'Parent changed', 'axle-category-tag-trash' ),
			Redirects::KIND_MERGE  => __( 'Merged', 'axle-category-tag-trash' ),
			Redirects::KIND_TRASH  => __( 'Deleted with a redirect', 'axle-category-tag-trash' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Prints what the last action did, with Undo after a removal.
	 *
	 * @param int    $removed How many redirects were just removed.
	 * @param int    $undone  How many were just put back.
	 * @param string $error   An error message.
	 */
	private function render_notices( int $removed, int $undone, string $error ): void {
		if ( $removed ) {
			$message = sprintf(
				/* translators: %s: number of redirects. */
				_n( 'Removed %s redirect. Its old address shows a 404 again.', 'Removed %s redirects. Their old addresses show a 404 again.', $removed, 'axle-category-tag-trash' ),
				number_format_i18n( $removed )
			);
			/**
			 * Filters the notice shown after a redirect is removed.
			 *
			 * @param string $message Plain text of the notice.
			 * @param int    $removed Redirects removed.
			 */
			$message = (string) apply_filters( 'axle_category_tag_trash_redirect_removed_notice', $message, $removed );
			printf(
				'<div class="notice notice-success is-dismissible" role="status"><p>%1$s <a href="%2$s" class="button button-small">%3$s</a></p></div>',
				esc_html( $message ),
				esc_url( self::action_url( 'redirect_undo', 'undo' ) ),
				esc_html__( 'Undo', 'axle-category-tag-trash' )
			);
		}

		if ( $undone ) {
			printf(
				'<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of redirects. */
						_n( 'Put back %s redirect.', 'Put back %s redirects.', $undone, 'axle-category-tag-trash' ),
						number_format_i18n( $undone )
					)
				)
			);
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error" role="alert"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Removes a redirect or undoes the last removal, then returns to the tab. Called after the nonce is checked.
	 *
	 * @param string $task redirect_remove or redirect_undo.
	 * @param string $id   Redirect ID, or "undo".
	 */
	public function handle( string $task, string $id ): void {
		$user_id = get_current_user_id();
		$args    = array();

		if ( 'redirect_undo' === $task ) {
			$args['actt_redirect_undone'] = $this->redirects->undo( $user_id );
			if ( ! $args['actt_redirect_undone'] ) {
				$args['actt_redirect_error'] = rawurlencode( __( 'There is nothing to undo. The undo link expires after a few minutes.', 'axle-category-tag-trash' ) );
			}
		} else {
			$key   = Redirects::resolve_key( $id );
			$entry = '' !== $key ? ( Redirects::all()[ $key ] ?? null ) : null;

			if ( ! $entry ) {
				$args['actt_redirect_error'] = rawurlencode( __( 'That redirect is already gone.', 'axle-category-tag-trash' ) );
			} elseif ( ! Access::can( $entry['taxonomy'], Access::REDIRECTS ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to remove redirects of this taxonomy.', 'axle-category-tag-trash' ), 403 );
			} else {
				$removed = $this->redirects->remove( array( $key ) );
				$this->redirects->stash( $user_id, $removed );
				$args['actt_redirect_removed'] = count( $removed );
			}
		}

		wp_safe_redirect( self::url( array_filter( $args ) ) );
		exit;
	}
}
