<?php
/**
 * Site Health test for background jobs that stopped.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash\Health;

use AryanX\Axle\CategoryTagTrash\Merger;
use AryanX\Axle\CategoryTagTrash\OldAddresses;
use AryanX\Axle\CategoryTagTrash\Redirects;
use AryanX\Axle\CategoryTagTrash\Restorer;
use AryanX\Axle\CategoryTagTrash\Service;
use AryanX\Axle\CategoryTagTrash\Trash;

defined( 'ABSPATH' ) || exit;

/**
 * Warns when a merge or a restore was handed to WP-Cron and nothing is going to finish it.
 */
final class SiteHealth implements Service {

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Merging terms.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Visits to the old addresses of trashed terms.
	 *
	 * @var OldAddresses
	 */
	private OldAddresses $old_addresses;

	/**
	 * Sets up the test.
	 *
	 * @param Trash        $trash         Trashed terms.
	 * @param Merger       $merger        Merging terms.
	 * @param OldAddresses $old_addresses Visits to the old addresses of trashed terms.
	 */
	public function __construct( Trash $trash, Merger $merger, OldAddresses $old_addresses ) {
		$this->trash         = $trash;
		$this->merger        = $merger;
		$this->old_addresses = $old_addresses;
	}

	/**
	 * Adds the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Registers the direct test.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests = (array) $tests;

		$tests['direct']['axle_category_tag_trash_jobs'] = array(
			'label' => __( 'Axle Category & Tag Trash background jobs', 'axle-category-tag-trash' ),
			'test'  => array( $this, 'run' ),
		);

		$tests['direct']['axle_category_tag_trash_redirects'] = array(
			'label' => __( 'Axle Category & Tag Trash redirects', 'axle-category-tag-trash' ),
			'test'  => array( $this, 'run_redirects' ),
		);

		$tests['direct']['axle_category_tag_trash_old_visits'] = array(
			'label' => __( 'Axle Category & Tag Trash old addresses', 'axle-category-tag-trash' ),
			'test'  => array( $this, 'run_old_visits' ),
		);

		return $tests;
	}

	/**
	 * Background merges and restores that nothing is scheduled to finish.
	 *
	 * @return array<int, array{kind: string, name: string}>
	 */
	public function stuck_jobs(): array {
		$stuck = array();

		foreach ( $this->merger->running() as $job_id => $job ) {
			if ( ! wp_next_scheduled( Merger::HOOK, array( $job_id ) ) && ! $this->is_locked( $job_id ) ) {
				$stuck[ $job_id ] = array(
					'kind' => 'merge',
					'name' => (string) $job['source_name'],
				);
			}
		}

		$restoring = get_posts(
			array(
				'post_type'   => Trash::POST_TYPE,
				'post_status' => Trash::STATUS_RESTORING,
				'numberposts' => 50,
				'fields'      => 'ids',
			)
		);
		foreach ( $restoring as $item_id ) {
			if ( ! wp_next_scheduled( Restorer::HOOK, array( (int) $item_id ) ) && ! $this->is_locked( (int) $item_id ) ) {
				$snapshot                = $this->trash->get( (int) $item_id );
				$stuck[ (int) $item_id ] = array(
					'kind' => 'restore',
					'name' => $snapshot ? $snapshot->name : (string) $item_id,
				);
			}
		}

		return $stuck;
	}

	/**
	 * Whether a job is running right now.
	 *
	 * @param int $job_id Job or trash item ID.
	 * @return bool
	 */
	private function is_locked( int $job_id ): bool {
		return false !== get_option( 'axle_category_tag_trash_lock_' . $job_id, false );
	}

	/**
	 * Site Health test result.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$stuck = $this->stuck_jobs();

		$result = array(
			'label'       => __( 'Axle Category & Tag Trash has no stuck jobs', 'axle-category-tag-trash' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Terms', 'axle-category-tag-trash' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every merge and restore that was handed to WP-Cron is either running or finished.', 'axle-category-tag-trash' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_category_tag_trash_jobs',
		);

		if ( ! $stuck ) {
			return $result;
		}

		$names = array_map(
			static function ( array $job ): string {
				return $job['name'];
			},
			array_values( $stuck )
		);

		$result['status']      = 'recommended';
		$result['label']       = __( 'Axle Category & Tag Trash has jobs that stopped', 'axle-category-tag-trash' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: %s: list of term names. */
				__( 'These terms were being merged or restored in the background, and nothing is scheduled to finish them: %s.', 'axle-category-tag-trash' ),
				implode( ', ', $names )
			)
		) . '</p><p>' . esc_html__( 'WP-Cron may be switched off or blocked. Run the job from the command line to finish it.', 'axle-category-tag-trash' ) . '</p>';
		$result['actions'] = '<p><code>wp axle-category-tag-trash merge</code> &middot; <code>wp axle-category-tag-trash restore</code></p>';

		return $result;
	}

	/**
	 * Site Health test for redirects whose target term was deleted.
	 *
	 * @return array<string, mixed>
	 */
	public function run_redirects(): array {
		$broken = Redirects::enabled() ? Redirects::count_broken() : 0;

		$result = array(
			'label'       => __( 'Every redirect from an old term address leads to a term', 'axle-category-tag-trash' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Terms', 'axle-category-tag-trash' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Old category, tag and term addresses that redirect all lead to a term that still exists.', 'axle-category-tag-trash' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_category_tag_trash_redirects',
		);

		if ( ! $broken ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Some redirects from old term addresses lead nowhere', 'axle-category-tag-trash' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of redirects. */
				_n( '%s redirect points at a term that was deleted, so its old address shows a 404.', '%s redirects point at terms that were deleted, so their old addresses show a 404.', $broken, 'axle-category-tag-trash' ),
				number_format_i18n( $broken )
			)
		) . '</p><p>' . esc_html__( 'Restore the term from the trash and the redirect works again, or remove the redirect on the Redirects tab.', 'axle-category-tag-trash' ) . '</p>';
		$result['actions'] = '<p><a href="' . esc_url( admin_url( 'tools.php?page=axle-category-tag-trash&view=redirects' ) ) . '">' . esc_html__( 'Open the Redirects tab', 'axle-category-tag-trash' ) . '</a></p>';

		return $result;
	}

	/**
	 * Fewest visits in a week that make an old address worth a look.
	 *
	 * @return int
	 */
	public static function visit_threshold(): int {
		/**
		 * Filters how many visits in the last seven days to the old address of a trashed term make Site Health recommend a redirect.
		 *
		 * @param int $visits Default 10.
		 */
		return max( 1, (int) apply_filters( 'axle_category_tag_trash_old_visits_threshold', 10 ) );
	}

	/**
	 * Site Health test for trashed terms whose old address keeps getting visits.
	 *
	 * Only taxonomies the current user may change redirects of are counted, so the test says nothing about the rest.
	 *
	 * @return array<string, mixed>
	 */
	public function run_old_visits(): array {
		$rows = OldAddresses::enabled() ? $this->old_addresses->visited( self::visit_threshold() ) : array();

		$result = array(
			'label'       => __( 'No deleted term has an old address that keeps getting visits', 'axle-category-tag-trash' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Terms', 'axle-category-tag-trash' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Visitors are not landing on the old addresses of deleted categories, tags or terms, or those addresses already redirect.', 'axle-category-tag-trash' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_category_tag_trash_old_visits',
		);

		if ( ! $rows ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Old addresses of deleted terms keep getting visits', 'axle-category-tag-trash' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of old addresses, 2: number of visits that makes an address count. */
				_n( '%1$s deleted term has an old address with at least %2$s visits in the last seven days. Those visitors see a 404.', '%1$s deleted terms have old addresses with at least %2$s visits in the last seven days each. Those visitors see a 404.', count( $rows ), 'axle-category-tag-trash' ),
				number_format_i18n( count( $rows ) ),
				number_format_i18n( self::visit_threshold() )
			)
		) . '</p><p>' . esc_html__( 'Open the trash and use "Redirect its old address" on the term, or restore the term.', 'axle-category-tag-trash' ) . '</p>';
		$result['actions'] = '<p><a href="' . esc_url( admin_url( 'tools.php?page=axle-category-tag-trash' ) ) . '">' . esc_html__( 'Open the trash', 'axle-category-tag-trash' ) . '</a></p>';

		return $result;
	}
}
