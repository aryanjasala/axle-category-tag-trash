<?php
/**
 * Plugin-only test stubs.
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * A term built from an array of fields.
	 */
	#[\AllowDynamicProperties]
	class WP_Term {
		/** @var int */
		public $term_id = 0;
		/** @var string */
		public $name = '';
		/** @var string */
		public $slug = '';
		/** @var int */
		public $term_taxonomy_id = 0;
		/** @var string */
		public $taxonomy = 'category';
		/** @var string */
		public $description = '';
		/** @var int */
		public $parent = 0;
		/** @var int */
		public $count = 0;

		public function __construct( $fields = array() ) {
			foreach ( (array) $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
