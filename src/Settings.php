<?php
/**
 * Saved settings.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

/**
 * How long trashed terms are kept.
 */
final class Settings {

	public const OPTION         = 'axle_category_tag_trash_settings';
	public const RETENTION_DAYS = array( 7, 30, 90, 365 );

	/**
	 * Retention periods offered on Tools > Axle Category & Tag Trash.
	 *
	 * @return int[] Days, shortest first.
	 */
	public static function retention_choices(): array {
		/**
		 * Filters the retention periods offered on Tools > Axle Category & Tag Trash.
		 *
		 * @param int[] $days Default 7, 30, 90 and 365.
		 */
		$days = array_values( array_unique( array_filter( array_map( 'absint', (array) apply_filters( 'axle_category_tag_trash_retention_choices', self::RETENTION_DAYS ) ) ) ) );
		sort( $days );

		return $days ? $days : self::RETENTION_DAYS;
	}
	private const DEFAULT_DAYS = 30;

	/**
	 * Days a trashed term is kept before it is deleted for good.
	 *
	 * @return int
	 */
	public function retention_days(): int {
		$saved = get_option( self::OPTION, array() );
		$days  = is_array( $saved ) && isset( $saved['retention_days'] ) ? (int) $saved['retention_days'] : self::DEFAULT_DAYS;

		/**
		 * Filters how many days trashed terms are kept.
		 *
		 * @param int $days Number of days.
		 */
		return max( 1, (int) apply_filters( 'axle_category_tag_trash_retention_days', $days ) );
	}

	/**
	 * Saves the retention period.
	 *
	 * @param int $days Number of days, one of RETENTION_DAYS.
	 */
	public function set_retention_days( int $days ): void {
		if ( in_array( $days, self::retention_choices(), true ) ) {
			update_option( self::OPTION, array( 'retention_days' => $days ), false );
		}
	}
}
