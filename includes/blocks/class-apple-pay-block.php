<?php
/**
 * Apple Pay Blocks Integration.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Blocks;

use PaymentNetwork\PaymentModule\Plugin_Config;
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Apple Pay block payment method integration.
 */
final class Apple_Pay_Block extends AbstractPaymentMethodType {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name = Plugin_Config::get_plugin_id() . '_apple_pay';
	}

	/**
	 * Initialize payment method settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ]->supports : array();
	}

	/**
	 * Returns whether this payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return ( $this->settings['enabled'] ?? '' ) === 'yes' && Plugin_Config::is_plugin_enabled();
	}

	/**
	 * Returns an array of script handles to enqueue for this payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {

		wp_register_script(
			"{$this->name}-blocks-integration",
			plugins_url( 'assets/js/blocks/applepay-block.js', dirname( __DIR__, 2 ) . '/PaymentModule.php' ),
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
				'wc-blocks-data-store',
			),
			Plugin_Config::get_plugin_version(),
			true
		);

		// Localise the pluginID which is the name.
		// This is needed for the BlockJS to use in order
		// to then get the payment method data.
		wp_localize_script(
			"{$this->name}-blocks-integration",
			'localizeAppleBlockVars',
			array(
				'pluginID' => $this->name,
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( "{$this->name}-blocks-integration" );
		}

		return array( "{$this->name}-blocks-integration" );
	}

	/**
	 * Returns an array of key, value pairs of data made available to the payment method client side.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'name'              => $this->get_name(),
			'title'             => $this->settings['title'] ?? 'Apple Pay',
			'description'       => $this->settings['description'] ?? 'Pay securely using Apple Pay.',
			'supportedNetworks' => array( 'amex', 'discover', 'eftpos', 'jcb', 'maestro', 'mastercard', 'visa' ),
			'storeApiNonce'     => wp_create_nonce( 'wc_store_api' ),
			'storeApiUrl'       => rest_url( 'wc/store/v1' ),
			'supports'          => $this->get_supported_features(),
			'ajaxurl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( $this->name ),
			'countryCode'       => WC()->countries ? WC()->countries->get_base_country() : 'GB',
		);
	}
}
