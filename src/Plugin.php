<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

use AryanX\Axle\CategoryTagTrash\Admin\TermScreen;
use AryanX\Axle\CategoryTagTrash\Admin\TrashPage;
use AryanX\Axle\CategoryTagTrash\Cli\Command;
use AryanX\Axle\CategoryTagTrash\Health\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Trashed terms.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Puts terms back.
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
	 * Old term addresses that redirect.
	 *
	 * @var Redirects
	 */
	private Redirects $redirects;

	/**
	 * Visits to the old addresses of trashed terms.
	 *
	 * @var OldAddresses
	 */
	private OldAddresses $old_addresses;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->trash    = new Trash( new Settings() );
		$this->restorer = new Restorer( $this->trash );
		$this->purger   = new Purger( $this->trash );
		$this->merger   = new Merger( $this->trash );

		$this->redirects     = new Redirects();
		$this->old_addresses = new OldAddresses( $this->trash, $this->redirects );
	}

	/**
	 * Trashed terms, for other plugins.
	 *
	 * @return Trash
	 */
	public function trash(): Trash {
		return $this->trash;
	}

	/**
	 * Restore logic, for other plugins.
	 *
	 * @return Restorer
	 */
	public function restorer(): Restorer {
		return $this->restorer;
	}

	/**
	 * Merging logic, for other plugins.
	 *
	 * @return Merger
	 */
	public function merger(): Merger {
		return $this->merger;
	}

	/**
	 * Redirects, for other plugins.
	 *
	 * @return Redirects
	 */
	public function redirects(): Redirects {
		return $this->redirects;
	}

	/**
	 * Old addresses of trashed terms, for other plugins.
	 *
	 * @return OldAddresses
	 */
	public function old_addresses(): OldAddresses {
		return $this->old_addresses;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->trash,
			$this->restorer,
			new Snapshotter( $this->trash ),
			$this->purger,
			$this->merger,
			$this->redirects,
			new HitCounter(),
			$this->old_addresses,
			new TrashPage( $this->trash, $this->restorer, $this->purger, $this->merger, $this->redirects, $this->old_addresses ),
			new TermScreen( $this->trash, $this->restorer ),
			new Abilities( $this->trash, $this->restorer, $this->merger, $this->redirects, $this->old_addresses ),
			new SiteHealth( $this->trash, $this->merger, $this->old_addresses ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-category-tag-trash', new Command( $this->trash, $this->restorer, $this->purger, $this->merger, $this->redirects, $this->old_addresses ) );
		}
	}
}
