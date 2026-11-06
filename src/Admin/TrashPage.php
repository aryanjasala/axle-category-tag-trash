<?php
/**
 * Tools > Category & Tag Trash.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Admin;

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

	public const SLUG   = 'category-tag-trash';
	public const ACTION = 'category_tag_trash';

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
	 * Sets up the screen.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 * @param Purger   $purger   Purging and emptying.
	 */
	public function __construct( Trash $trash, Restorer $restorer, Purger $purger ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
		$this->purger   = $purger;
	}

	/**
	 * Capability needed to open Tools > Category & Tag Trash.
	 *
	 * Each restore and delete still checks the term's own taxonomy.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to open Tools > Category & Tag Trash.
		 *
		 * @param string $capability Capability. Default manage_categories.
		 */
		return (string) apply_filters( 'category_tag_trash_capability', 'manage_categories' );
	}

	/**
	 * Adds the menu entry and the action handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
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
	 * Registers Tools > Category & Tag Trash, and handles bulk actions before output.
	 */
	public function add_page(): void {
		$hook = add_management_page(
			__( 'Category & Tag Trash', 'category-tag-trash' ),
			__( 'Category & Tag Trash', 'category-tag-trash' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( "load-{$hook}", array( $this, 'handle_bulk_action' ) );
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Screen styles.
	 */
	public function enqueue_styles(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'category-tag-trash-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new TrashListTable( $this->trash );
		$table->prepare_items();
		$days  = $this->trash->settings()->retention_days();
		$total = array_sum( $this->trash->count_by_taxonomy() );
		?>
		<div class="wrap axle-ui ctt-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Category & Tag Trash', 'category-tag-trash' ); ?></h1>
			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<p class="axle-intro ctt-intro">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of days. */
						_n( 'Deleted categories, tags and other terms are kept here for %d day, then deleted for good.', 'Deleted categories, tags and other terms are kept here for %d days, then deleted for good.', $days, 'category-tag-trash' ),
						$days
					)
				);
				?>
			</p>

			<?php if ( $total || $table->has_items() ) : ?>
				<?php $table->views(); ?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php if ( $table->current_taxonomy() ) : ?>
						<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $table->current_taxonomy() ); ?>">
					<?php endif; ?>
					<?php $table->search_box( __( 'Search trash', 'category-tag-trash' ), 'category-tag-trash' ); ?>
					<?php $table->display(); ?>
				</form>
			<?php else : ?>
				<div class="axle-empty">
					<p><?php esc_html_e( 'Category & Tag Trash is empty.', 'category-tag-trash' ); ?></p>
					<p><?php esc_html_e( 'Categories, tags and other terms you delete will show up here, so you can restore them.', 'category-tag-trash' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_retention_form( $days ); ?>
			<?php $this->render_empty_card( $table->has_items() ); ?>

			<?php AxleUi::footer(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the danger card with Empty trash.
	 *
	 * @param bool $show Whether there is anything to empty.
	 */
	private function render_empty_card( bool $show ): void {
		if ( ! $show ) {
			return;
		}

		$confirm = __( 'Delete everything in Category & Tag Trash that you can manage, for good? This cannot be undone.', 'category-tag-trash' );
		?>
		<section class="axle-card axle-card--danger">
			<h2 class="axle-card__title"><?php esc_html_e( 'Empty the trash', 'category-tag-trash' ); ?></h2>
			<p><?php esc_html_e( 'Deletes every term in the trash that you can manage, for good. Restore can no longer bring them back. This cannot be undone.', 'category-tag-trash' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_empty' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="empty">
				<button type="submit" class="button axle-button--danger" onclick="return window.confirm( '<?php echo esc_js( $confirm ); ?>' );"><?php esc_html_e( 'Empty trash', 'category-tag-trash' ); ?></button>
			</form>
		</section>
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
			<h2 class="axle-card__title"><?php esc_html_e( 'Retention', 'category-tag-trash' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_settings' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="task" value="settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ctt-retention"><?php esc_html_e( 'Keep deleted terms for', 'category-tag-trash' ); ?></label></th>
						<td>
							<select id="ctt-retention" name="retention_days">
								<?php foreach ( Settings::retention_choices() as $option ) : ?>
									<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $days, $option ); ?>>
										<?php
										/* translators: %d: number of days. */
										echo esc_html( sprintf( _n( '%d day', '%d days', $option, 'category-tag-trash' ), $option ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'After this time a term is deleted for good and can no longer be restored.', 'category-tag-trash' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save retention', 'category-tag-trash' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$restored = isset( $_GET['ctt_restored'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ctt_restored'] ) ) ) ) ) : array();
		$deleted  = isset( $_GET['ctt_deleted'] ) ? absint( $_GET['ctt_deleted'] ) : 0;
		$saved    = isset( $_GET['ctt_saved'] );
		$notes    = isset( $_GET['ctt_notes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['ctt_notes'] ) ) : array();
		$errors   = isset( $_GET['ctt_errors'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['ctt_errors'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		self::print_restored_notice( $restored, $notes );

		if ( $deleted ) {
			/* translators: %d: number of terms. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d term deleted for good.', '%d terms deleted for good.', $deleted, 'category-tag-trash' ), $deleted ) ) );
		}

		if ( $saved ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Settings saved.', 'category-tag-trash' ) );
		}

		foreach ( $errors as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
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

		echo '<div class="notice notice-success is-dismissible ctt-restored"><p>';
		/* translators: %s: list of restored term names. */
		printf( esc_html__( 'Restored %s.', 'category-tag-trash' ), wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ) );
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

		check_admin_referer( 'bulk-category_tag_trash_items' );
		$this->run( $action, $item_ids, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * Handles row actions, Undo links, Empty trash and the retention setting.
	 */
	public function handle_action(): void {
		$task  = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';
		$items = isset( $_REQUEST['items'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['items'] ) ) : '';
		$back  = admin_url( 'tools.php?page=' . self::SLUG );

		if ( 'settings' === $task ) {
			check_admin_referer( self::ACTION . '_settings' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to change this setting.', 'category-tag-trash' ), 403 );
			}
			$this->trash->settings()->set_retention_days( isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30 );
			wp_safe_redirect( add_query_arg( 'ctt_saved', '1', $back ) );
			exit;
		}

		if ( 'empty' === $task ) {
			check_admin_referer( self::ACTION . '_empty' );
			// Site admins empty everything, the rest in the background. Others only what they may manage.
			$result = $this->purger->empty_trash( 200, current_user_can( 'manage_options' ) ? null : array( $this->trash, 'user_can_manage' ) );
			$args   = array( 'ctt_deleted' => $result['deleted'] );
			if ( $result['remaining'] && current_user_can( 'manage_options' ) ) {
				$args['ctt_notes'] = array(
					rawurlencode(
						sprintf(
							/* translators: %s: number of trashed terms. */
							_n( 'The last %s term is being deleted in the background.', 'The other %s terms are being deleted in the background.', $result['remaining'], 'category-tag-trash' ),
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
				'ctt_restored' => implode( ',', $result['restored'] ),
				'ctt_notes'    => array_map( 'rawurlencode', $result['notes'] ),
				'ctt_errors'   => array_map( 'rawurlencode', $result['errors'] ),
			);
		} elseif ( 'delete' === $task ) {
			$deleted = 0;
			foreach ( $item_ids as $item_id ) {
				$snapshot = $this->trash->get( $item_id );
				if ( $snapshot && $this->trash->user_can_manage( $snapshot ) ) {
					$deleted += (int) $this->trash->delete( $item_id );
				}
			}
			$args = array( 'ctt_deleted' => $deleted );
		}

		wp_safe_redirect( add_query_arg( array_filter( $args ), $back ) );
		exit;
	}
}
