<?php
/**
 * Autoloader for the PaymentNetwork\PaymentModule namespace.
 *
 * Maps PaymentNetwork\PaymentModule\* to WordPress-style prefixed files:
 *   class-*.php, trait-*.php, interface-*.php, enum-*.php
 *
 * @package PaymentModule
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( string $fully_qualified_class ): void {

		$prefix   = 'PaymentNetwork\\PaymentModule\\';
		$base_dir = __DIR__;

		// Bail if the class doesn't belong to our namespace.
		if ( 0 !== strpos( $fully_qualified_class, $prefix ) ) {
			return;
		}

		// Strip the namespace prefix to get the relative portion.
		$relative = substr( $fully_qualified_class, strlen( $prefix ) );

		// Split into folder segments + symbol name.
		$parts       = explode( '\\', $relative );
		$symbol_name = array_pop( $parts );

		// Convert symbol name (PascalCase or Snake_Case) to kebab-case.
		$file_base = strtolower( str_replace( '_', '-', $symbol_name ) );

		// Build the absolute folder path (each segment lowercased).
		$folder = $base_dir;
		foreach ( $parts as $part ) {
			$folder .= DIRECTORY_SEPARATOR . strtolower( $part );
		}

		// Try each WordPress file-type prefix in priority order.
		$type_prefixes = array( 'class', 'trait', 'interface', 'enum' );
		foreach ( $type_prefixes as $type_prefix ) {
			$file = $folder . DIRECTORY_SEPARATOR . $type_prefix . '-' . $file_base . '.php';

			// Guard against directory traversal.
			if ( false !== strpos( $file, '..' ) ) {
				return;
			}

			if ( is_file( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);
