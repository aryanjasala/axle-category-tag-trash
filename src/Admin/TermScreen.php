<?php
/**
 * Undo notices and the trash link on Categories, Tags and other term screens.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Admin;

use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Service;
use AryanX\Axle\CategoryTagTrash\Snapshotter;
use AryanX\Axle\CategoryTagTrash\Trash;
use const AryanX\Axle\CategoryTagTrash\PLUGIN_FILE;
use const AryanX\Axle\CategoryTagTrash\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Offers Undo right after a term is deleted.
 *
 * Single deletes on term screens happen over ajax, so a small script shows
 * the Undo notice. Bulk deletes reload the page, so the notice comes from a
 * short-lived per-user list of what was just deleted.
 */
final class TermScreen implements Service {

	private const AJAX_UNDO = 'axle_category_tag_trash_undo';

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
	 * Sets up the term screen additions.
	 *
	 * @param Trash    $trash    Trashed terms.
	 * @param Restorer $restorer Restore logic.
	 */
	public function __construct( Trash $trash, Restorer $restorer ) {
		$this->trash    = $trash;
		$this->restorer = $restorer;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_table_notes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'bulk_undo_notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_UNDO, array( $this, 'ajax_undo' ) );
	}

	/**
	 * Adds the Axle Category & Tag Trash link under each supported term list.
	 */
	public function add_table_notes(): void {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $taxonomy ) {
			if ( $this->trash->supports( $taxonomy ) ) {
				add_action( "after-{$taxonomy}-table", array( $this, 'table_note' ) );
			}
		}
	}

	/**
	 * Prints a link to Axle Category & Tag Trash below the terms table.
	 *
	 * @param string $taxonomy Taxonomy of the screen.
	 */
	public function table_note( string $taxonomy ): void {
		$count = $this->trash->count_by_taxonomy()[ $taxonomy ] ?? 0;

		printf(
			'<p class="axle-category-tag-trash-note description"><span class="dashicons dashicons-trash" aria-hidden="true"></span> %s <a href="%s">%s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Anything deleted here stays in Axle Category & Tag Trash for %d day.', 'Anything deleted here stays in Axle Category & Tag Trash for %d days.', $this->trash->settings()->retention_days(), 'axle-category-tag-trash' ),
					$this->trash->settings()->retention_days()
				)
			),
			esc_url( add_query_arg( 'taxonomy', $taxonomy, admin_url( 'tools.php?page=' . TrashPage::SLUG ) ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of trashed terms. */
					_n( 'View Axle Category & Tag Trash (%d)', 'View Axle Category & Tag Trash (%d)', $count, 'axle-category-tag-trash' ),
					$count
				)
			)
		);
	}

	/**
	 * Loads the Undo script on term list screens.
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( 'edit-tags.php' !== $hook_suffix || ! $screen || ! $this->trash->supports( (string) $screen->taxonomy ) ) {
			return;
		}

		wp_enqueue_script( 'axle-category-tag-trash-term-screen', plugins_url( 'assets/term-screen.js', PLUGIN_FILE ), array( 'jquery', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'axle-category-tag-trash-term-screen',
			'axleCategoryTagTrashScreen',
			array(
				'action' => self::AJAX_UNDO,
				'nonce'  => wp_create_nonce( self::AJAX_UNDO ),
				'i18n'   => array(
					/* translators: %s: term name. */
					'moved'     => __( '"%s" moved to Axle Category & Tag Trash.', 'axle-category-tag-trash' ),
					'undo'      => __( 'Undo', 'axle-category-tag-trash' ),
					'restoring' => __( 'Restoring…', 'axle-category-tag-trash' ),
					/* translators: %s: term name. */
					'restored'  => __( '"%s" restored.', 'axle-category-tag-trash' ),
					'failed'    => __( 'Could not restore the term.', 'axle-category-tag-trash' ),
				),
			)
		);
		wp_enqueue_style( 'axle-category-tag-trash-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Restores the term deleted over ajax a moment ago.
	 */
	public function ajax_undo(): void {
		check_ajax_referer( self::AJAX_UNDO );

		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$item_id  = $this->trash->find_latest_for_term( $term_id, $taxonomy );
		$snapshot = $item_id ? $this->trash->get( $item_id ) : null;

		if ( ! $snapshot ) {
			wp_send_json_error( array( 'message' => __( 'That term is no longer in Axle Category & Tag Trash.', 'axle-category-tag-trash' ) ), 404 );
		}

		if ( ! $this->trash->user_can_manage( $snapshot ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to restore this term.', 'axle-category-tag-trash' ) ), 403 );
		}

		$result = $this->restorer->restore( $item_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'term_id' => $result['term_id'],
				'name'    => $snapshot->name,
				'notes'   => $result['notes'],
			)
		);
	}

	/**
	 * After a bulk delete, offers to undo all of it.
	 */
	public function bulk_undo_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}

		$user_id  = get_current_user_id();
		$item_ids = array_filter( array_map( 'intval', (array) get_transient( Snapshotter::undo_key( $user_id ) ) ) );
		delete_transient( Snapshotter::undo_key( $user_id ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$restored = isset( $_GET['actt_restored'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['actt_restored'] ) ) ) ) ) : array();
		$notes    = isset( $_GET['actt_notes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['actt_notes'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		TrashPage::print_restored_notice( $restored, $notes );

		if ( ! $item_ids ) {
			return;
		}

		$back = add_query_arg( 'taxonomy', $screen->taxonomy, admin_url( 'edit-tags.php' ) );
		if ( 'post' !== $screen->post_type ) {
			$back = add_query_arg( 'post_type', $screen->post_type, $back );
		}

		printf(
			'<div class="notice notice-success is-dismissible axle-category-tag-trash-undo"><p>%s <a href="%s" class="button button-small">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of terms. */
					_n( '%d term moved to Axle Category & Tag Trash.', '%d terms moved to Axle Category & Tag Trash.', count( $item_ids ), 'axle-category-tag-trash' ),
					count( $item_ids )
				)
			),
			esc_url( TrashPage::action_url( 'restore', $item_ids, $back ) ),
			esc_html__( 'Undo', 'axle-category-tag-trash' )
		);
	}
}
