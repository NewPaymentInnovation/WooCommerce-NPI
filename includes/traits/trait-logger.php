<?php
/**
 * Logger trait.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Traits;

use PaymentNetwork\PaymentModule\Plugin_Config;

/**
 * Logger trait.
 *
 * Provides PSR-3 style logging methods via WooCommerce logger.
 */
trait Logger {

	/**
	 * Convert a context value (array or object) to a JSON string.
	 *
	 * @param array|object $context Context data to serialize.
	 * @return string
	 */
	private function log_serialize_context( $context ): string {
		$encoded = wp_json_encode( $context );
		return false !== $encoded ? $encoded : print_r( $context, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
	}

	/**
	 * Log a message at the given level.
	 *
	 * @param string            $level   PSR-3 log level (emergency|alert|critical|error|warning|notice|info|debug).
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data to append as JSON.
	 */
	private function log( string $level, string $message, $context = null ): void {
		$valid_levels = array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' );

		if ( ! in_array( $level, $valid_levels, true ) ) {
			return;
		}

		if ( ! Plugin_Config::is_debug_enabled() ) {
			return;
		}

		$verbose = Plugin_Config::get_debug_verbose();

		if ( ! empty( $verbose ) && ! in_array( $level, $verbose, true ) ) {
			return;
		}

		if ( null !== $context ) {
			$message .= ' ' . $this->log_serialize_context( $context );
		}

		wc_get_logger()->{$level}( $message, array( 'source' => Plugin_Config::get_plugin_id() ) );
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_emergency( string $message, $context = null ): void {
		$this->log( 'emergency', $message, $context );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_alert( string $message, $context = null ): void {
		$this->log( 'alert', $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_critical( string $message, $context = null ): void {
		$this->log( 'critical', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_error( string $message, $context = null ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_warning( string $message, $context = null ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log a notice message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_notice( string $message, $context = null ): void {
		$this->log( 'notice', $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_info( string $message, $context = null ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string            $message Log message.
	 * @param array|object|null $context Optional context data.
	 */
	private function log_debug( string $message, $context = null ): void {
		$this->log( 'debug', $message, $context );
	}
}
