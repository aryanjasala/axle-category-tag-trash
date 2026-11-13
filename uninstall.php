<?php
/**
 * Deletes everything in Axle Category & Tag Trash, its settings and its schedules, on every site.
 *
 * @package CategoryTagTrash
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Category & Tag Trash data from the current site.
 */
function axle_category_tag_trash_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'axle_category_tag_trash_purge' );
	wp_clear_scheduled_hook( 'axle_category_tag_trash_empty' );
	delete_option( 'axle_category_tag_trash_settings' );
	delete_option( 'axle_category_tag_trash_redirects' );
	delete_option( 'axle_category_tag_trash_redirect_hits' );
	delete_metadata( 'term', 0, 'axle_category_tag_trash_redirect_to', '', true );

	do {
		$axle_category_tag_trash_items = get_posts(
			array(
				'post_type'   => 'axle_ctt_item',
				'post_status' => 'any',
				'numberposts' => 100,
				'fields'      => 'ids',
			)
		);

		foreach ( $axle_category_tag_trash_items as $axle_category_tag_trash_item ) {
			wp_clear_scheduled_hook( 'axle_category_tag_trash_restore_batch', array( $axle_category_tag_trash_item ) );
			wp_clear_scheduled_hook( 'axle_category_tag_trash_merge_batch', array( $axle_category_tag_trash_item ) );
			wp_delete_post( $axle_category_tag_trash_item, true );
		}
	} while ( $axle_category_tag_trash_items );

	// Locks left behind by an interrupted background restore.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'axle_category_tag_trash_lock_' ) . '%' ) );

	// Undo links for removed redirects, and their expiry times.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_category_tag_trash_redirect_undo_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_category_tag_trash_redirect_undo_' ) . '%' ) );
}

if ( is_multisite() ) {
	$axle_category_tag_trash_offset = 0;
	do {
		$axle_category_tag_trash_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_category_tag_trash_offset,
			)
		);

		foreach ( $axle_category_tag_trash_sites as $axle_category_tag_trash_site ) {
			switch_to_blog( $axle_category_tag_trash_site );
			axle_category_tag_trash_uninstall_site();
			restore_current_blog();
		}

		$axle_category_tag_trash_offset += 100;
	} while ( $axle_category_tag_trash_sites );
} else {
	axle_category_tag_trash_uninstall_site();
}
