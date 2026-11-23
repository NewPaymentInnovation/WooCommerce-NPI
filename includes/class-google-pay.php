<?php
/**
 * Google Pay Payment Gateway.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes;

use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Google_Pay as Google_Pay_Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Payment_Process;
use PaymentNetwork\PaymentModule\Includes\Traits\Order_Refund;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;
use PaymentNetwork\PaymentModule\Includes\Traits\Three_DS_Callback;
use PaymentNetwork\PaymentModule\Includes\Traits\Subscription_Process;

/**
 * Google Pay Payment Gateway.
 *
 * Processes Google Pay transactions with support for
 * 3DS authentication, subscriptions, and refunds.
 */
class Google_Pay extends \WC_Payment_Gateway {

	/**
	 * Use Payment_Process, Logger, Three_DS_Callback, Order_Refund, and Subscription_Process traits.
	 */
	use Payment_Process;
	use Order_Refund;
	use Logger;
	use Three_DS_Callback;
	use Subscription_Process;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields         = true;
		$this->id                 = Plugin_Config::get_plugin_id() . '_google_pay';
		$this->method_title       = Plugin_Config::get_plugin_title() . ' - Google Pay';
		$this->method_description = 'Process transactions using GooglePay integration on ' . Plugin_Config::get_plugin_title();
		$this->icon               = plugins_url( '/', __DIR__ ) . 'assets/images/icon-google-pay.svg?v=' . Plugin_Config::get_plugin_version();

		$this->supports = array(
			'subscriptions',
			'products',
			'refunds',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_admin',
		);

		$this->init_form_fields();

		// Initialise settings.
		$this->init_settings();
		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->title       = $this->get_option( 'title', 'Google Pay' );
		$this->description = $this->get_option( 'description', 'Pay securely using Google Pay.' );

		add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_google_pay_settings_admin_script' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ), 10 );
		add_action( "woocommerce_api_wc_{$this->id}_three_ds_callback", array( $this, 'three_ds_callback' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_express_google_pay_button' ), 90 );
		add_action( 'wp_head', array( $this, 'hide_google_pay_gateway_row_css' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

		if ( is_admin() ) {
			$this->adjust_refund_method_title();
		}
	}

	/**
	 * Initialise Gateway Settings.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                   => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-payment-module' ),
				'label'       => __( 'Enable Google Pay', 'woocommerce-payment-module' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable Google Pay payments on the checkout page.', 'woocommerce-payment-module' ),
				'default'     => 'no',
			),
			'test_mode'                 => array(
				'title'       => __( 'Test Mode', 'woocommerce-payment-module' ),
				'label'       => __( 'Enable Test Mode', 'woocommerce-payment-module' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable test mode to use Google Pay in a sandbox environment.', 'woocommerce-payment-module' ),
				'default'     => 'no',
			),
			'title'                     => array(
				'title'       => __( 'Title', 'woocommerce-payment-module' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-payment-module' ),
				'default'     => __( 'Google Pay', 'woocommerce-payment-module' ),
			),
			'merchant_id'               => array(
				'title'       => __( 'Merchant ID', 'woocommerce-payment-module' ),
				'type'        => 'text',
				'description' => __( 'Your Google Pay Merchant ID.', 'woocommerce-payment-module' ),
				'default'     => '',
			),
			'merchant_name'             => array(
				'title'       => __( 'Merchant Name', 'woocommerce-payment-module' ),
				'type'        => 'text',
				'description' => __( 'Your Google Pay Merchant Name.', 'woocommerce-payment-module' ),
				'default'     => '',
			),
			'allowed_card_networks'     => array(
				'title'       => __( 'Allowed Card Networks', 'woocommerce-payment-module' ),
				'type'        => 'multiselect',
				'description' => __( 'Select the card networks you want to accept.', 'woocommerce-payment-module' ),
				'options'     => array(
					'AMEX'       => 'American Express',
					'DISCOVER'   => 'Discover',
					'JCB'        => 'JCB',
					'MASTERCARD' => 'Mastercard',
					'VISA'       => 'Visa',
				),
				'default'     => array( 'AMEX', 'DISCOVER', 'JCB', 'MASTERCARD', 'VISA' ),
			),
			'gateway_merchant_id'       => array(
				'title'       => __( 'Gateway Merchant ID', 'woocommerce-payment-module' ),
				'type'        => 'text',
				'description' => __( 'Your payment gateway merchant ID.', 'woocommerce-payment-module' ),
				'default'     => '',
			),
			'gateway'                   => array(
				'title'       => __( 'Gateway', 'woocommerce-payment-module' ),
				'type'        => 'text',
				'description' => __( 'The payment gateway you are using for processing Google Pay transactions (e.g., "example").', 'woocommerce-payment-module' ),
				'default'     => '',
			),
			'custom_transaction_fields' => array(
				'title'       => __( 'Custom Transaction Fields', 'woocommerce-payment-module' ),
				'type'        => 'textarea',
				'description' => __( 'Add request field names and values in the table below. Each one is sent with every transaction request.', 'woocommerce-payment-module' ),
				'default'     => '[]',
				'css'         => 'width:100%;height:150px;font-family:monospace;font-size:12px;',
			),
		);
	}

	/**
	 * Adjust the method title for refunds to match the plugin title.
	 */
	public function adjust_refund_method_title() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return;
		}

		if ( in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			$this->method_title = Plugin_Config::get_plugin_title();
		}
	}

	/**
	 * Enqueue payment scripts.
	 */
	public function enqueue_payment_scripts(): void {
		if ( ! is_checkout() && ! is_checkout_pay_page() && ! is_cart() ) {
			$this->log_debug( 'Google Pay enqueue_payment_scripts skipped: not checkout/cart context.' );
			return;
		}

		wp_enqueue_script(
			'google_pay_sdk_javascript',
			'https://pay.google.com/gp/p/js/pay.js',
			array(),
			Plugin_Config::get_plugin_version(),
			false,
		);

		$this->log_debug( 'Google Pay SDK script enqueued.' );
	}

	/**
	 * Output HTML for the payment fields on the checkout page.
	 */
	public function payment_fields(): void {
		$allowed_card_networks = $this->get_option( 'allowed_card_networks', array( 'AMEX', 'DISCOVER', 'JCB', 'MASTERCARD', 'VISA' ) );
		if ( ! is_array( $allowed_card_networks ) ) {
			$allowed_card_networks = array( 'AMEX', 'DISCOVER', 'JCB', 'MASTERCARD', 'VISA' );
		}

		$this->log_debug(
			'Google Pay payment fields preparing script localization.',
			array(
				'allowed_card_networks' => $allowed_card_networks,
				'environment'           => 'yes' === $this->get_option( 'test_mode', 'no' ) ? 'TEST' : 'PRODUCTION',
			)
		);

		wp_enqueue_script(
			'google_pay_classic_checkout_js',
			plugins_url( '/', __DIR__ ) . 'assets/js/classic/google-pay-classic.js',
			array( 'jquery', 'google_pay_sdk_javascript' ),
			Plugin_Config::get_plugin_version(),
			true
		);

		// Get billing fields to determine if phone number is required.
		$billing_fields = WC()->checkout()->get_checkout_fields( 'billing' );

		wp_localize_script(
			'google_pay_classic_checkout_js',
			'localizeGoogleClassicVars',
			array(
				'billingPhoneRequired' => ! empty( $billing_fields['billing_phone']['required'] ),
				'gatewayId'            => $this->id,
				'merchantId'           => (string) $this->get_option( 'merchant_id', '' ),
				'merchantName'         => (string) $this->get_option( 'merchant_name', (string) Plugin_Config::get_plugin_title() ),
				'gateway'              => (string) $this->get_option( 'gateway', '' ),
				'gatewayMerchantId'    => (string) $this->get_option( 'gateway_merchant_id', '' ),
				'allowedCardNetworks'  => $allowed_card_networks,
				'storeApiNonce'        => wp_create_nonce( 'wc_store_api' ),
				'environment'          => 'yes' === $this->get_option( 'test_mode', 'no' ) ? 'TEST' : 'PRODUCTION',
				'storeApiEndpoint'     => rest_url( 'wc/store/v1' ),
			)
		);

		// Output the Google Pay button container.
		echo '<div id="google-pay-button-container"></div>';

		$this->log_debug( 'Google Pay payment fields rendered.' );
	}

	/**
	 * Render the express Google Pay button above the checkout form.
	 */
	public function render_express_google_pay_button(): void {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			$this->log_debug( 'Google Pay express button skipped: not checkout context.' );
			return;
		}

		echo '<div class="pm-express-googlepay"><div id="google-pay-button-container" style="height: 55px;"></div></div>';
		$this->log_debug( 'Google Pay express button rendered.' );
	}

	/**
	 * Hide the Google Pay gateway row in the payment methods list.
	 */
	public function hide_google_pay_gateway_row_css(): void {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			$this->log_debug( 'Google Pay gateway row CSS skip: not checkout context.' );
			return;
		}

		echo '<style>li.wc_payment_method.payment_method_' . esc_attr( $this->id ) . ' { display:none !important; }</style>';
		$this->log_debug( 'Google Pay gateway row CSS rendered.' );
	}

	/**
	 * Enqueue admin script for custom transaction fields table on Google Pay gateway settings.
	 *
	 * @return void
	 */
	public function enqueue_google_pay_settings_admin_script(): void {
		if ( ! is_admin() ) {
			$this->log_debug( 'Google Pay settings assets enqueue skipped: not admin context.' );
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			$this->log_debug(
				'Google Pay settings assets enqueue skipped: wrong admin screen.',
				array( 'screen_id' => $screen ? $screen->id : null )
			);
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $this->id !== $section ) {
			$this->log_debug(
				'Google Pay settings assets enqueue skipped: section mismatch.',
				array(
					'section'  => $section,
					'expected' => $this->id,
				)
			);
			return;
		}

		wp_enqueue_script(
			'wcpm-custom-transaction-fields-table',
			plugins_url( '/', __DIR__ ) . 'assets/js/admin/custom-transaction-fields-table.js',
			array(),
			Plugin_Config::get_plugin_version(),
			true
		);

		wp_enqueue_style(
			'wcpm-custom-transaction-fields-table',
			plugins_url( '/', __DIR__ ) . 'assets/css/admin/custom-transaction-fields-table.css',
			array(),
			Plugin_Config::get_plugin_version()
		);

		wp_localize_script(
			'wcpm-custom-transaction-fields-table',
			'localizeCustomTransactionFieldsVars',
			array(
				'fieldId' => 'woocommerce_' . $this->id . '_custom_transaction_fields',
			)
		);

		$this->log_debug( 'Google Pay settings admin assets enqueued.' );
	}

	/**
	 * Validate custom transaction fields JSON format.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string Validated value.
	 */
	public function validate_custom_transaction_fields_field( string $key, string $value ): string {
		$raw_value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		if ( '' === $raw_value ) {
			return '[]';
		}

		$decoded = json_decode( $raw_value, true );
		if ( ! is_array( $decoded ) ) {
			$this->log_warning(
				'Google Pay custom transaction fields JSON validation failed.',
				array( 'field_key' => $key )
			);
			$this->add_error( __( 'Custom Transaction Fields must be valid JSON.', 'woocommerce-payment-module' ) );
			return (string) $this->get_option( 'custom_transaction_fields', '[]' );
		}

		$normalized_fields = array();

		foreach ( $decoded as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? trim( $field['key'] ) : '';
			if ( '' === $field_key ) {
				continue;
			}

			$field_value         = $field['value'] ?? '';
			$normalized_fields[] = array(
				'key'   => $field_key,
				'value' => is_scalar( $field_value ) ? (string) $field_value : wp_json_encode( $field_value ),
			);
		}

		$this->log_debug(
			'Google Pay custom transaction fields validated.',
			array(
				'field_key'        => $key,
				'normalized_count' => count( $normalized_fields ),
			)
		);

		return wp_json_encode( $normalized_fields );
	}

	/**
	 * Process Google Pay payment through existing direct transaction flow.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 * @throws \InvalidArgumentException When payment data is missing or invalid.
	 * @throws \Exception When payment processing fails.
	 */
	public function process_payment( $order_id ): array {
		$is_blocks_checkout = $this->is_blocks_checkout_request();
		$is_legacy_ajax     = isset( $_GET['ajax'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ajax'] ) );
		$this->log_info(
			'Google Pay process_payment started.',
			array(
				'order_id'           => $order_id,
				'is_blocks_checkout' => $is_blocks_checkout,
				'is_legacy_ajax'     => $is_legacy_ajax,
			)
		);
		$build_failure_message = static function ( $raw_message, $fallback = 'Payment failed. Please try again.' ) {
			$normalized = is_string( $raw_message ) ? trim( $raw_message ) : '';

			return '' !== $normalized ? $normalized : $fallback;
		};

		try {
			$order = wc_get_order( $order_id );

			if ( isset( $_POST['paymentdata'] ) ) {
				$payment_data = json_decode( $_POST['paymentdata'], true );
			} else {
				$this->log_error( 'Google Pay process_payment failed: no payment data received.', array( 'order_id' => $order_id ) );
				throw new \InvalidArgumentException( 'No payment data received' );
			}

			$device_information     = isset( $payment_data['deviceInformation'] ) && is_array( $payment_data['deviceInformation'] )
				? $payment_data['deviceInformation']
				: array();
			$device_accept_language = isset( $device_information['deviceAcceptLanguage'] )
				? ( is_array( $device_information['deviceAcceptLanguage'] ) ? ( $device_information['deviceAcceptLanguage'][0] ?? null ) : $device_information['deviceAcceptLanguage'] )
				: null;

			$unique_ref = uniqid( 'wc-order-payment-' );

			$transaction = new Transaction(
				array(
					'transaction_type'       => 0.0 === (float) $order->get_total() ? 'ECOM_VERIFY' : 'ECOM_SALE',
					'currencyCode'           => $order->get_currency(),
					'countryCode'            => Plugin_Config::get_country_code(),
					'transactionUnique'      => $unique_ref,
					'customerCountryCode'    => $order->get_billing_country(),
					'customerAddress'        => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
					'customerCounty'         => $order->get_billing_state(),
					'customerTown'           => $order->get_billing_city(),
					'customerPostCode'       => $order->get_billing_postcode(),
					'customerEmail'          => $order->get_billing_email(),
					'amount'                 => $order->get_total(),
					'customerName'           => "{$order->get_billing_first_name()} {$order->get_billing_last_name()}",
					'remoteAddress'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'merchantData'           => wp_json_encode(
						array(
							'paymentType' => 'googlepay_order_payment',
							'module'      => 'woocommerce',
							'version'     => Plugin_Config::get_plugin_version(),
						)
					),
					'threeDSRedirectURL'     => add_query_arg(
						array(
							'wc-api'             => "wc_{$this->id}_three_ds_callback",
							'transaction_unique' => $unique_ref,
						),
						home_url( '/' )
					),
					'deviceChannel'          => isset( $device_information['deviceChannel'] ) ? sanitize_text_field( (string) $device_information['deviceChannel'] ) : 'browser',
					'deviceIdentity'         => isset( $device_information['deviceIdentity'] ) ? sanitize_text_field( (string) $device_information['deviceIdentity'] ) : ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ),
					'deviceTimeZone'         => isset( $device_information['deviceTimeZone'] ) ? sanitize_text_field( (string) $device_information['deviceTimeZone'] ) : '0',
					'deviceScreenResolution' => isset( $device_information['deviceScreenResolution'] ) ? sanitize_text_field( (string) $device_information['deviceScreenResolution'] ) : '1x1x1',
					'deviceAcceptContent'    => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : null,
					'deviceAcceptEncoding'   => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
					'deviceAcceptLanguage'   => null !== $device_accept_language ? sanitize_text_field( (string) $device_accept_language ) : ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : null ),
					'deviceAcceptCharset'    => isset( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_CHARSET'] ) ) : null,
					'paymentToken'           => $payment_data['googlePayData']['paymentMethodData']['tokenizationData']['token'],
					'paymentMethod'          => 'googlepay',
				)
			);

			// If Subscription product, add rtAgreementType.
			if ( class_exists( 'WC_Subscriptions' ) && wcs_order_contains_subscription( $order ) ) {
				$transaction->rtAgreementType = 'recurring';
			}

			// Apply custom transaction fields if configured.
			$custom_fields_json = $this->get_option( 'custom_transaction_fields', '[]' );
			if ( ! empty( $custom_fields_json ) && '[]' !== $custom_fields_json ) {
				$custom_fields = json_decode( $custom_fields_json, true );
				if ( is_array( $custom_fields ) ) {
					foreach ( $custom_fields as $field ) {
						if ( ! empty( $field['key'] ) ) {
							$transaction->{ $field['key'] } = $field['value'] ?? '';
						}
					}
				}
			}

			$payment_method = new Google_Pay_Payment_Method(
				array(
					'merchantID'       => Plugin_Config::get_merchant_id(),
					'merchant_secret'  => Plugin_Config::get_merchant_secret(),
					'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
				)
			);

			// Send request to gateway.
			$transaction = $payment_method->process( $transaction );
			$this->log_debug(
				'Google Pay gateway transaction processed.',
				array(
					'order_id'           => $order_id,
					'transaction_unique' => $unique_ref,
					'status'             => $transaction->status(),
					'response_code'      => $transaction->responseCode ?? null,
				)
			);

			// Transient data.
			$transient_data = array(
				'transaction' => $transaction,
				'order_id'    => $order_id,
				'paymentType' => 'googlepay_order_payment',
			);

			// Store the transaction data.
			set_transient( $unique_ref, $transient_data, 360 );

			// Handle the outcome of the transaction and respond back to the checkout.
			switch ( $transaction->status() ) {
				case 'captured':
				case 'verified':
					$this->log_info(
						'Google Pay payment succeeded.',
						array(
							'order_id'           => $order_id,
							'status'             => $transaction->status(),
							'transaction_unique' => $unique_ref,
						)
					);
					$order = wc_get_order( $order_id );
					$this->process_payment_success( $transaction, $order );
					$response = array(
						'paymentResult' => 'captured',
						'message'       => ( $transaction->responseMessage ?? 'Unknown reason' ),
						'result'        => 'success',
						'reload'        => false,
						'redirect'      => $order->get_checkout_order_received_url(),
					);
					break;

				case 'received':
					switch ( $transaction->responseCode ) {
						case 65802: // 3DS required, show 3DS form.
							$this->log_info(
								'Google Pay payment requires 3DS authentication.',
								array(
									'order_id'           => $order_id,
									'transaction_unique' => $unique_ref,
								)
							);
							$response = array(
								'result'          => 'success',
								'paymentResult'   => 'requires_action',
								'message'         => '3DS authentication required.',
								'reload'          => false,
								'redirect'        => false,
								'messages'        => '<p>Processing 3DS</p>',
								'threeDSRequired' => true,
								'threeDSData'     => wp_json_encode(
									array(
										'acsURL'         => $transaction->threeDSURL,
										'threeDSRequest' => $transaction->threeDSRequest,
									)
								),
								'payment_details' => array(
									array(
										'key'   => 'paymentResult',
										'value' => 'requires_action',
									),
									array(
										'key'   => 'threeDSData',
										'value' => wp_json_encode(
											array(
												'acsURL' => $transaction->threeDSURL,
												'threeDSRequest' => $transaction->threeDSRequest,
											)
										),
									),
								),
							);
							break;

						default:
							$failure_message = $build_failure_message( $transaction->responseMessage );
							$this->log_warning(
								'Google Pay payment failed in RECEIVED state.',
								array(
									'order_id'      => $order_id,
									'response_code' => $transaction->responseCode ?? null,
									'message'       => $failure_message,
								)
							);
							if ( ! $is_blocks_checkout ) {
								throw new \Exception( $failure_message );
							}
							$response = array(
								'result'   => 'failure',
								'message'  => $failure_message,
								'messages' => '<p>' . esc_html( $failure_message ) . '</p>',
							);
					}
					break;

				case 'declined':
				case 'rejected':
				case 'error':
				case 'finished':
				case 'referred':
					$failure_message = $build_failure_message(
						$transaction->responseMessage,
						'Transaction ' . ( $transaction->state ?? 'failed' ) . '. Please try again.'
					);
					$this->log_warning(
						'Google Pay payment declined/rejected.',
						array(
							'order_id'      => $order_id,
							'state'         => $transaction->state ?? null,
							'response_code' => $transaction->responseCode ?? null,
							'message'       => $failure_message,
						)
					);
					if ( ! $is_blocks_checkout ) {
						throw new \Exception( $failure_message );
					}
					$response = array(
						'paymentResult' => ( $transaction->state ?? 'Unknown reason' ),
						'message'       => $failure_message,
						'result'        => 'failure',
						'reload'        => false,
						'messages'      => '<p>' . esc_html( $failure_message ) . '</p>',
					);
					break;

				default:
					break;
			}
		} catch ( \Exception $th ) {
			$failure_message = $build_failure_message( $th->getMessage() );
			$this->log_error(
				'Google Pay process_payment exception.',
				array(
					'order_id' => $order_id,
					'message'  => $failure_message,
					'code'     => $th->getCode(),
					'file'     => $th->getFile(),
					'line'     => $th->getLine(),
				)
			);
			if ( ! $is_blocks_checkout ) {
				throw new \Exception( esc_html( $failure_message ) );
			}
			$response = array(
				'result'   => 'failure',
				'message'  => $failure_message,
				'messages' => '<p>' . esc_html( $failure_message ) . '</p>',
			);
		}

		if ( $is_legacy_ajax ) {
			$this->log_debug( 'Google Pay process_payment responding via legacy AJAX.', array( 'order_id' => $order_id ) );
			wp_send_json( $response );
		}

		return $response;
	}

	/**
	 * Check if the current request is a blocks checkout request.
	 *
	 * @return bool
	 */
	private function is_blocks_checkout_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return false !== strpos( $request_uri, '/wc/store/v1/checkout' );
	}

	/**
	 * Handle a scheduled subscription renewal payment.
	 *
	 * @param float     $amount_to_charge The renewal amount.
	 * @param \WC_Order $renewal_order    The renewal order.
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$this->log_info(
			'Google Pay scheduled subscription payment started.',
			array(
				'amount'           => $amount_to_charge,
				'renewal_order_id' => is_object( $renewal_order ) && method_exists( $renewal_order, 'get_id' ) ? $renewal_order->get_id() : null,
			)
		);

		$payment_method = new Google_Pay_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		$this->process_subscription_payment( $payment_method, $amount_to_charge, $renewal_order );
	}

	/**
	 * Process a refund request.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$this->log_info(
			'Google Pay refund requested.',
			array(
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		$payment_method = new Google_Pay_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		$refund_result = $this->refund_order_payment( $payment_method, $order_id, $amount, $reason );

		if ( is_wp_error( $refund_result ) ) {
			$this->log_warning(
				'Google Pay refund failed.',
				array(
					'order_id' => $order_id,
					'error'    => $refund_result->get_error_message(),
				)
			);
		} else {
			$this->log_info( 'Google Pay refund completed.', array( 'order_id' => $order_id ) );
		}

		return $refund_result;
	}
}
