<?php
/**
 * Plugin Name:       Axle Category & Tag Trash
 * Plugin URI:        https://wordpress.org/plugins/axle-category-tag-trash/
 * Description:       Deleted categories, tags and custom terms go to a trash instead of disappearing. Undo a delete in one click, or restore later with the term's posts, child terms and menu links.
 * Version:           3.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-category-tag-trash
 *
 * @package AryanX\Axle\CategoryTagTrash
 */

namespace AryanX\Axle\CategoryTagTrash;

defined( 'ABSPATH' ) || exit;

const VERSION     = '3.1.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\CategoryTagTrash\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Purger::class, 'schedule' ) );
register_deactivation_hook( __FILE__, array( Purger::class, 'unschedule' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
