<?php
/**
 * Plugin Name: NPI Gateway
 * Plugin URI: https://example.com/
 * Version: 4.1.3
 * Author: Open Payment Network
 * Author URI: https://example.com/
 * License: MIT License
 * Gateway Hostname: gateway.example.com
 * Description: Provides the NPI Gateway for WooCommerce.
 *
 * @package PaymentModule
 */

/***************************************
 * Please do not edit below this line *
 ***************************************/

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the autoloader.
require_once plugin_dir_path( __FILE__ ) . 'auto-loader.php';

use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Admin\Plugin_Settings;
use PaymentNetwork\PaymentModule\Includes\Blocks\Card_Block;
use PaymentNetwork\PaymentModule\Includes\Blocks\Apple_Pay_Block;
use PaymentNetwork\PaymentModule\Includes\Blocks\Google_Pay_Block;
use PaymentNetwork\PaymentModule\Includes\Blocks\Hosted_Block;
use PaymentNetwork\PaymentModule\Includes\Card;
use PaymentNetwork\PaymentModule\Includes\Apple_Pay;
use PaymentNetwork\PaymentModule\Includes\Helpers\Apple_Pay_Shipping_Quote;
use PaymentNetwork\PaymentModule\Includes\Google_Pay;
use PaymentNetwork\PaymentModule\Includes\Hosted;

add_action( 'plugins_loaded', 'pm_plugin_init' );

/**
 * Plugin initialization.
 */
function pm_plugin_init(): void {

	// Check if WC Class exists.
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	Plugin_Config::init( __FILE__ );

	// If WordPress admin interface then add plugin settings options.
	if ( is_admin() ) {
		$plugin_settings = new Plugin_Settings();
		$plugin_settings->add_options_page();
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pm_add_wc_payment_plugin_actions' );
	}

	// if the plugin is not enabled, do not proceed with loading the plugin.
	if ( ! Plugin_Config::is_plugin_enabled() ) {
		return;
	}

	add_filter( 'woocommerce_payment_gateways', 'pm_add_payment_methods' );

	add_action( 'wp_ajax_add_new_saved_card', 'pm_add_new_saved_card' );

	add_action( 'wp_ajax_apple_pay_merchant_validation', 'pm_apple_pay_merchant_validation' );
	add_action( 'wp_ajax_nopriv_apple_pay_merchant_validation', 'pm_apple_pay_merchant_validation' );

	add_filter( 'rest_request_before_callbacks', 'pm_apple_pay_shipping_quote_before_callbacks', 10, 3 );
	add_filter( 'rest_request_after_callbacks', 'pm_apple_pay_shipping_quote_after_callbacks', 10, 3 );

	add_action( 'before_woocommerce_init', 'pm_declare_cart_checkout_blocks_compatibility' );
	add_action( 'woocommerce_blocks_loaded', 'pm_direct_checkout_block' );
}

/**
 * Handle add-new-saved-card AJAX requests.
 *
 * @return void
 */
function pm_add_new_saved_card(): void {
	( new Card() )->add_new_saved_card();
}

/**
 * Handle Apple Pay merchant validation AJAX requests.
 *
 * @return void
 */
function pm_apple_pay_merchant_validation(): void {
	( new Apple_Pay() )->merchant_validation();
}

/**
 * Allow incomplete postcodes while calculating an Apple Pay shipping quote.
 *
 * @param mixed            $response Existing response.
 * @param array            $handler Route handler.
 * @param \WP_REST_Request $request REST request.
 * @return mixed
 */
function pm_apple_pay_shipping_quote_before_callbacks( $response, $handler, $request ) {
	return Apple_Pay_Shipping_Quote::before_callbacks( $response, $handler, $request );
}

/**
 * Restore normal postcode validation after an Apple Pay shipping quote.
 *
 * @param mixed            $response REST response.
 * @param array            $handler Route handler.
 * @param \WP_REST_Request $request REST request.
 * @return mixed
 */
function pm_apple_pay_shipping_quote_after_callbacks( $response, $handler, $request ) {
	return Apple_Pay_Shipping_Quote::after_callbacks( $response, $handler, $request );
}

/**
 * Register payment blocks.
 *
 * @return void
 */
function pm_direct_checkout_block(): void {
	// Check if the required class exists.
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	// Hook registration to blocks payment method registry.
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new Apple_Pay_Block() );
			$payment_method_registry->register( new Google_Pay_Block() );
			$payment_method_registry->register( new Card_Block() );
			$payment_method_registry->register( new Hosted_Block() );
		}
	);
}

/**
 * Add actions to plugin.
 *
 * @param array $actions Plugin action links.
 * @return array
 */
function pm_add_wc_payment_plugin_actions( $actions ): array {
	// Add settings link to plugin actions.
	$actions = array_merge(
		array(
			'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin_Config::get_plugin_id() ) ) . '">' . __( 'Settings', 'general' ) . '</a>',
		),
		$actions
	);

	// Return actions.
	return $actions;
}

/**
 * Add payment gateways.
 *
 * @param array $methods Gateway class list.
 * @return array
 */
function pm_add_payment_methods( $methods ): array {

	$methods[] = Card::class;
	$methods[] = Apple_Pay::class;
	$methods[] = Google_Pay::class;
	$methods[] = Hosted::class;

	return $methods;
}

/**
 * Declare compatibility with cart and checkout blocks feature.
 */
function pm_declare_cart_checkout_blocks_compatibility(): void {
	// Check if the required class exists.
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		// Declare compatibility for cart and checkout blocks.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
