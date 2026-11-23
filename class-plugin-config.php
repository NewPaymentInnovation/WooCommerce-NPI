<?php
/**
 * Plugin config bootstrap file.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule;

/**
 * Plugin configuration singleton.
 *
 * Reads the plugin file header and wp_options to provide
 * global access to plugin settings.
 */
class Plugin_Config {

	/**
	 * Whether the config has been initialised.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * The plugin ID (derived from the plugin name).
	 *
	 * @var string
	 */
	private static string $plugin_id = '';

	/**
	 * The plugin folder name (used as the theme override prefix for wc_get_template).
	 *
	 * @var string
	 */
	private static string $plugin_folder = '';

	/**
	 * The plugin display title.
	 *
	 * @var string
	 */
	private static string $plugin_title = '';

	/**
	 * The gateway hostname.
	 *
	 * @var string
	 */
	private static string $gateway_host_name = '';

	/**
	 * The plugin version.
	 *
	 * @var string
	 */
	private static string $plugin_version = '';

	/**
	 * The plugin options from wp_options.
	 *
	 * @var array
	 */
	private static array $plugin_options = array();

	/**
	 * Initialise plugin config settings.
	 *
	 * @param string $plugin Path to the main plugin file.
	 * @return void
	 */
	public static function init( $plugin ): void {

		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		// Plugin header meta data keys.
		$plugin_header_keys = array(
			'plugin_name'          => 'Plugin Name',
			'plugin_description'   => 'Description',
			'gateway_hostname'     => 'Gateway Hostname',
			'test_merchant_id'     => 'Test Merchant ID',
			'test_merchant_secret' => 'Test Merchant Secret',
			'version'              => 'Version',
		);

		// Get plugin header meta data.
		$plugin_header_data = get_file_data(
			$plugin,
			$plugin_header_keys,
			'plugin'
		);

		self::set_plugin_id( $plugin_header_data['plugin_name'] );
		self::set_plugin_title( $plugin_header_data['plugin_name'] );
		self::set_plugin_version( $plugin_header_data['version'] );
		self::$plugin_folder = basename( plugin_dir_path( $plugin ) );

		self::$plugin_options = get_option( self::$plugin_id . '_settings', array() );

		if ( ! is_array( self::$plugin_options ) ) {
			self::$plugin_options = array();
		}

		self::set_gateway_host_name( $plugin_header_data['gateway_hostname'] );
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string
	 */
	public static function get_plugin_version(): string {
		return self::$plugin_version;
	}

	/**
	 * Set the plugin version.
	 *
	 * @param string $version The plugin version.
	 * @return void
	 */
	private static function set_plugin_version( $version ): void {
		self::$plugin_version = (string) $version;
	}

	/**
	 * Return the merchant ID.
	 *
	 * @return string
	 */
	public static function get_merchant_id(): string {
		return (string) ( self::$plugin_options['merchant_id'] ?? '' );
	}

	/**
	 * Return the merchant secret.
	 *
	 * @return string
	 */
	public static function get_merchant_secret(): string {
		return (string) ( self::$plugin_options['merchant_secret'] ?? '' );
	}

	/**
	 * Return the country code.
	 *
	 * @return string
	 */
	public static function get_country_code(): string {
		return (string) ( self::$plugin_options['country_code'] ?? '' );
	}

	/**
	 * Return the gateway hostname.
	 *
	 * @return string
	 */
	public static function get_gateway_host_name(): string {
		return (string) ( self::$plugin_options['gateway_hostname'] ?? self::$gateway_host_name );
	}

	/**
	 * Set the plugin gateway hostname.
	 *
	 * @param string $host_name The gateway hostname.
	 * @return void
	 */
	private static function set_gateway_host_name( $host_name ): void {
		self::$gateway_host_name = (string) $host_name;
	}

	/**
	 * Set the plugin title.
	 *
	 * @param string $title The plugin title.
	 * @return void
	 */
	private static function set_plugin_title( $title ): void {
		self::$plugin_title = (string) $title;
	}

	/**
	 * Set the plugin ID.
	 *
	 * Formats the plugin name to a slug. e.g. "Payment Plugin" becomes "payment_plugin".
	 *
	 * @param string $plugin_id The raw plugin name.
	 * @return void
	 */
	private static function set_plugin_id( $plugin_id ): void {
		$plugin_id       = trim( $plugin_id );
		$plugin_id       = str_replace( ' ', '', $plugin_id );
		$plugin_id       = preg_replace( '/(?<=[a-zA-Z])(?=[A-Z])/', '_', $plugin_id );
		self::$plugin_id = strtolower( $plugin_id );
	}

	/**
	 * Check if the plugin is enabled.
	 *
	 * @return bool
	 */
	public static function is_plugin_enabled(): bool {

		if ( ! empty( self::$plugin_options['plugin_enabled'] ) ) {
			return ( 'on' === self::$plugin_options['plugin_enabled'] || 'yes' === self::$plugin_options['plugin_enabled'] );
		}

		return false;
	}

	/**
	 * Return the plugin title.
	 *
	 * @return string
	 */
	public static function get_plugin_title(): string {
		return self::$plugin_title;
	}

	/**
	 * Check if debug is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled(): bool {

		if ( ! empty( self::$plugin_options['debug_enabled'] ) ) {
			return ( 'on' === self::$plugin_options['debug_enabled'] || 'yes' === self::$plugin_options['debug_enabled'] );
		}

		return false;
	}

	/**
	 * Return the debug verbose levels.
	 *
	 * @return array
	 */
	public static function get_debug_verbose(): array {

		if ( ! empty( self::$plugin_options['debug_verbose'] ) && is_array( self::$plugin_options['debug_verbose'] ) ) {
			return self::$plugin_options['debug_verbose'];
		}

		return array();
	}

	/**
	 * Return the plugin ID.
	 *
	 * @return string
	 */
	public static function get_plugin_id(): string {
		return self::$plugin_id;
	}

	/**
	 * Return the plugin folder name with a trailing slash, for use as the
	 * theme-override prefix in wc_get_template() calls.
	 *
	 * @return string e.g. 'woocommerce-payment-module/'
	 */
	public static function get_template_path(): string {
		return self::$plugin_folder . '/';
	}
}
