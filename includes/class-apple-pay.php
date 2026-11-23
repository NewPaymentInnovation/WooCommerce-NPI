<?php
/**
 * Apple Pay Payment Gateway.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes;

use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Apple_Pay as Apple_Pay_Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Payment_Process;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;
use PaymentNetwork\PaymentModule\Includes\Traits\Order_Refund;
use PaymentNetwork\PaymentModule\Includes\Traits\Subscription_Process;

/**
 * Apple Pay Payment Gateway.
 *
 * Processes Apple Pay transactions with support for
 * 3DS authentication, subscriptions, and refunds.
 */
class Apple_Pay extends \WC_Payment_Gateway {

	/**
	 * Use Payment_Process, Logger, Order_Refund, and Subscription_Process traits.
	 */
	use Payment_Process;
	use Logger;
	use Order_Refund;
	use Subscription_Process;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields         = true;
		$this->id                 = Plugin_Config::get_plugin_id() . '_apple_pay';
		$this->method_title       = Plugin_Config::get_plugin_title() . ' - Apple Pay';
		$this->method_description = 'Process transactions using Apple Pay integration on ' . Plugin_Config::get_plugin_title();
		$this->icon               = plugins_url( '/', __DIR__ ) . 'assets/images/icon-apple-pay.svg?v=' . Plugin_Config::get_plugin_version();

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
		$this->title       = $this->get_option( 'title', 'Apple Pay' );
		$this->description = $this->get_option( 'description', 'Pay securely using Apple Pay.' );

		add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_apple_pay_settings_admin_script' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ), 10 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_express_apple_pay_button' ), 90 );
		add_action( 'wp_head', array( $this, 'hide_apple_pay_gateway_row_css' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

		if ( is_admin() ) {
			$this->adjust_refund_method_title();
		}
	}

	/**
	 * Initialise Gateway Settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                   => array(
				'title'       => __( 'Enable/Disable' ),
				'label'       => __( 'Enable Apple Pay' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable Apple Pay payment option.' ),
				'default'     => 'no',
			),
			'test_mode'                 => array(
				'title'       => __( 'Test Mode' ),
				'label'       => __( 'Enable Test Mode' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable test mode to use Apple Pay in a sandbox environment.' ),
				'default'     => 'no',
			),
			'title'                     => array(
				'title'       => __( 'Title' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.' ),
				'default'     => __( 'Apple Pay' ),
			),
			'display_name'              => array(
				'title'       => __( 'Merchant Display Name' ),
				'type'        => 'text',
				'description' => __( 'The display name that appears on the Apple Pay sheet during checkout.' ),
				'default'     => get_bloginfo( 'name' ),
			),
			'dvf_status'                => array(
				'title'       => __( 'Domain Verification File Status' ),
				'type'        => 'title',
				'description' => $this->get_dvf_status_html(),
			),
			'dvf_content'               => array(
				'title'       => __( 'Domain Verification File Content' ),
				'type'        => 'textarea',
				'description' => __( 'Paste the contents of your Apple Pay domain verification file. It will be saved to /.well-known/apple-developer-merchantid-domain-association on this server.' ),
				'default'     => '',
				'css'         => 'width:100%;height:120px;font-family:monospace;font-size:12px;',
				'placeholder' => __( 'Paste file content here...' ),
			),
			'custom_transaction_fields' => array(
				'title'       => __( 'Custom Transaction Fields' ),
				'type'        => 'textarea',
				'description' => __( 'Add request field names and values in the table below. Each one is sent with every transaction request.' ),
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
	public function enqueue_payment_scripts() {
		if ( ! is_checkout() && ! is_checkout_pay_page() && ! is_cart() ) {
			return;
		}

		wp_enqueue_script(
			'apple_pay_sdk_javascript',
			'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js',
			array(),
			Plugin_Config::get_plugin_version(),
			true
		);
	}

	/**
	 * Render payment fields.
	 */
	public function payment_fields() {
		wp_enqueue_script(
			'apple_pay_classic_checkout_js',
			plugins_url( '/', __DIR__ ) . 'assets/js/classic/apple-pay-classic.js',
			array( 'jquery', 'apple_pay_sdk_javascript' ),
			Plugin_Config::get_plugin_version(),
			true
		);

		wp_localize_script(
			'apple_pay_classic_checkout_js',
			'localizeAppleClassicVars',
			array(
				'gatewayId'            => $this->id,
				'supportedNetworks'    => array( 'amex', 'discover', 'eftpos', 'jcb', 'maestro', 'mastercard', 'visa' ),
				'merchantCapabilities' => array( 'supports3DS' ),
				'countryCode'          => WC()->countries->get_base_country(),
				'storeApiNonce'        => wp_create_nonce( 'wc_store_api' ),
				'nonce'                => wp_create_nonce( Plugin_Config::get_plugin_id() . '_apple_pay' ),
				'ajaxurl'              => admin_url( 'admin-ajax.php' ),
				'storeApiEndpoint'     => rest_url( 'wc/store/v1' ),
			)
		);

		echo '<div id="apple-pay-button-container"></div>';
	}

	/**
	 * Enqueue admin script for custom transaction fields table on Apple Pay gateway settings.
	 *
	 * @return void
	 */
	public function enqueue_apple_pay_settings_admin_script(): void {
		if ( ! is_admin() ) {
			$this->log_debug( 'Apple Pay settings assets enqueue skipped: not admin context.' );
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			$this->log_debug(
				'Apple Pay settings assets enqueue skipped: wrong admin screen.',
				array( 'screen_id' => $screen ? $screen->id : null )
			);
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $section !== $this->id ) {
			$this->log_debug(
				'Apple Pay settings assets enqueue skipped: section mismatch.',
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

		$this->log_debug( 'Apple Pay settings admin assets enqueued.' );
	}

	/**
	 * Validate custom transaction fields JSON format.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string Validated value.
	 */
	public function validate_custom_transaction_fields_field( $key, $value ): string {
		$raw_value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		if ( '' === $raw_value ) {
			return '[]';
		}

		$decoded = json_decode( $raw_value, true );
		if ( ! is_array( $decoded ) ) {
			$this->log_warning(
				'Apple Pay custom transaction fields JSON validation failed.',
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
			'Apple Pay custom transaction fields validated.',
			array(
				'field_key'        => $key,
				'normalized_count' => count( $normalized_fields ),
			)
		);

		return wp_json_encode( $normalized_fields );
	}

	/**
	 * Return an HTML status string indicating whether the DVF file is in place.
	 *
	 * @return string
	 */
	private function get_dvf_status_html(): string {
		$path = ABSPATH . '.well-known/apple-developer-merchantid-domain-association';
		if ( file_exists( $path ) ) {
			$url = home_url( '/.well-known/apple-developer-merchantid-domain-association' );
			return '<span style="color:#00a32a;">&#10003; File is present.</span> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View file' ) . '</a>';
		}
		return '<span style="color:#d63638;">&#10007; File not found at <code>' . esc_html( $path ) . '</code>.</span>';
	}

	/**
	 * Override process_admin_options to write the DVF file after settings are saved.
	 *
	 * @return bool
	 */
	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();

		$dvf_content = $this->get_option( 'dvf_content', '' );
		if ( ! empty( $dvf_content ) ) {
			$well_known_dir = ABSPATH . '.well-known';
			$dvf_path       = $well_known_dir . '/apple-developer-merchantid-domain-association';

			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_filesystem;
			if ( ! WP_Filesystem() ) {
				\WC_Admin_Settings::add_error(
					__( 'Apple Pay: Could not initialise the WordPress filesystem. Please check your filesystem configuration.' )
				);
				return $saved;
			}

			if ( ! $wp_filesystem->is_dir( $well_known_dir ) ) {
				$wp_filesystem->mkdir( $well_known_dir, FS_CHMOD_DIR );
			}

			if ( ! $wp_filesystem->put_contents( $dvf_path, $dvf_content, FS_CHMOD_FILE ) ) {
				\WC_Admin_Settings::add_error(
					__( 'Apple Pay: Could not write domain verification file. Check that the web server has write permission to the .well-known directory.' )
				);
			}
		}

		return $saved;
	}

	/**
	 * Render express Apple Pay button before the checkout form.
	 */
	public function render_express_apple_pay_button() {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}

		echo '<div class="pm-express-applepay"><div id="apple-pay-button-container" style="height: 60px;"></div></div>';
	}

	/**
	 * Output CSS to hide the Apple Pay gateway row.
	 */
	public function hide_apple_pay_gateway_row_css() {
		if ( ! is_checkout() && ! is_checkout_pay_page() ) {
			return;
		}

		echo '<style>
		li.wc_payment_method.payment_method_' . esc_attr( $this->id ) . ' { display:none !important; }
		</style>';
	}

	/**
	 * Handle merchant validation for Apple Pay.
	 *
	 * Receives the validationURL from the frontend, validates with Apple Pay servers,
	 * and returns the merchant session back to the frontend.
	 */
	public function merchant_validation() {
		// Validate nonce for security.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, Plugin_Config::get_plugin_id() . '_apple_pay' ) ) {
			$this->log_warning( 'Apple Pay merchant validation: invalid nonce.' );
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
			return;
		}

		$validation_url = isset( $_POST['validationURL'] ) ? esc_url_raw( wp_unslash( $_POST['validationURL'] ) ) : '';

		if ( empty( $validation_url ) ) {
			$this->log_warning( 'Apple Pay merchant validation: missing validation URL.' );
			wp_send_json_error( array( 'message' => 'Validation URL is required.' ) );
			return;
		}

		// Validate that the validation URL is from an Apple domain.
		$parsed_url = wp_parse_url( $validation_url );
		$host       = isset( $parsed_url['host'] ) ? strtolower( $parsed_url['host'] ) : '';

		// Whitelist of allowed Apple Pay validation domains.
		$allowed_domains = array(
			'apple-pay-gateway.apple.com',      // Production domain.
			'apple-pay-gateway-cert.apple.com', // Sandbox/test domain.
		);

		if ( ! in_array( $host, $allowed_domains, true ) ) {
			$this->log_warning( 'Apple Pay merchant validation: validation URL is not from an Apple domain: ' . $validation_url );
			wp_send_json_error( array( 'message' => 'Invalid validation URL domain.' ) );
			return;
		}

		$this->log_info( 'Apple Pay merchant validation: starting validation for URL: ' . $validation_url );

		try {
			$domain_name = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

			if ( empty( $domain_name ) && isset( $_SERVER['HTTP_HOST'] ) ) {
				$domain_name = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
				$domain_name = preg_replace( '/:\\d+$/', '', $domain_name );
			}

			if ( empty( $domain_name ) && isset( $_SERVER['SERVER_NAME'] ) ) {
				$domain_name = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
			}

			// Perform merchant validation with Apple Pay servers using the provided validationURL.
			$applepay_payment_method = new Apple_Pay_Payment_Method(
				array(
					'merchantID'       => Plugin_Config::get_merchant_id(),
					'merchant_secret'  => Plugin_Config::get_merchant_secret(),
					'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
				)
			);

			// For testing purposes, if test mode is enabled, override the
			// validationURL to point to Apple's sandbox endpoint.
			if ( 'yes' === ( $this->settings['test_mode'] ?? '' ) ) {
				$validation_url = 'https://apple-pay-gateway-cert.apple.com/paymentservices/startSession';
			}

			// Call the validateMerchantSession method to get the merchant session
			// from Apple Pay via the Gateway.
			$merchant_session = $applepay_payment_method->validateMerchantSession(
				array(
					'validationURL'         => $validation_url,
					'merchant_display_name' => $this->settings['display_name'] ?? 'Apple Pay',
					'domainName'            => (string) $domain_name,
				)
			);

			// Return the merchant session back to the frontend.
			if ( $merchant_session ) {
				$this->log_info( 'Apple Pay merchant validation: session obtained successfully.' );
				wp_send_json_success( array( 'merchantSession' => $merchant_session ) );
			} else {
				$this->log_error( 'Apple Pay merchant validation: gateway returned no session.' );
				wp_send_json_error( array( 'message' => 'Failed to validate merchant with Apple Pay.' ) );
			}
		} catch ( \Exception $e ) {
			$this->log_error(
				'Apple Pay merchant validation: exception — ' . $e->getMessage(),
				array(
					'code' => $e->getCode(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				)
			);
			wp_send_json_error( array( 'message' => 'Error during merchant validation: ' ) );
		}
	}

	/**
	 * Process the payment.
	 *
	 * Apple Pay always uses the WC Store API, so responses are always returned
	 * as arrays rather than thrown exceptions.
	 *
	 * @param int $order_id The order ID.
	 * @return array
	 * @throws \InvalidArgumentException When payment data is missing.
	 */
	public function process_payment( $order_id ) {
		$build_failure_message = static function ( $raw_message, $fallback = 'Payment failed. Please try again.' ) {
			$normalized = is_string( $raw_message ) ? trim( $raw_message ) : '';
			return '' !== $normalized ? $normalized : $fallback;
		};

		$this->log_info( 'Apple Pay process_payment: starting payment for order ' . $order_id . '.' );

		try {
			$order = wc_get_order( $order_id );

			if ( isset( $_POST['paymentdata'] ) ) {
				$payment_data = json_decode( wp_unslash( $_POST['paymentdata'] ), true );
			} else {
				$this->log_error( 'Apple Pay process_payment failed: no payment data received.', array( 'order_id' => $order_id ) );
				throw new \InvalidArgumentException( 'No payment data received' );
			}

			$unique_ref = uniqid( 'wc-order-payment-' );

			$transaction = new Transaction(
				array(
					'transaction_type'    => 0.0 === (float) $order->get_total() ? 'ECOM_VERIFY' : 'ECOM_SALE',
					'currencyCode'        => $order->get_currency(),
					'countryCode'         => $this->get_option( 'country_code', 'GB' ),
					'transactionUnique'   => $unique_ref,
					'customerCountryCode' => $order->get_billing_country(),
					'customerAddress'     => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
					'customerCounty'      => $order->get_billing_state(),
					'customerTown'        => $order->get_billing_city(),
					'customerPostCode'    => $order->get_billing_postcode(),
					'customerEmail'       => $order->get_billing_email(),
					'amount'              => $order->get_total(),
					'customerName'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
					'merchantData'        => wp_json_encode(
						array(
							'paymentType' => 'applepay_order_payment',
							'module'      => 'woocommerce',
							'version'     => Plugin_Config::get_plugin_version(),
						)
					),
					'paymentToken'        => wp_json_encode( $payment_data['token']['paymentData'] ),
				)
			);

			// If subscription, add subscription data to the transaction.
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

			$payment_method = new Apple_Pay_Payment_Method(
				array(
					'merchantID'       => Plugin_Config::get_merchant_id(),
					'merchant_secret'  => Plugin_Config::get_merchant_secret(),
					'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
				)
			);

			// Send request to gateway.
			$transaction = $payment_method->process( $transaction );

			set_transient(
				$unique_ref,
				array(
					'transaction' => $transaction,
					'order_id'    => $order_id,
					'paymentType' => 'applepay_order_payment',
				),
				360
			);

			$order = wc_get_order( $order_id );

			if ( 'captured' === $transaction->status() || 'verified' === $transaction->status() ) {
				$this->log_info( 'Apple Pay process_payment: order ' . $order_id . ' captured successfully. Ref: ' . $unique_ref );

				$this->process_payment_success( $transaction, $order );
				return array(
					'paymentResult' => 'captured',
					'message'       => ( $transaction->responseMessage ?? 'Unknown reason' ),
					'result'        => 'success',
					'reload'        => false,
					'redirect'      => $order->get_checkout_order_received_url(),
				);
			}

			$failure_message = $build_failure_message(
				$transaction->responseMessage,
				'Transaction ' . ( $transaction->state ?? 'failed' ) . '. Please try again.'
			);

			$this->process_payment_failed( $transaction, $order );

			$this->log_warning( 'Apple Pay process_payment: order ' . $order_id . ' not captured. State: ' . ( $transaction->state ?? 'unknown' ) . '. Message: ' . $failure_message );

			return array(
				'paymentResult' => ( $transaction->state ?? 'Unknown reason' ),
				'message'       => $failure_message,
				'result'        => 'failure',
				'reload'        => false,
				'messages'      => '<p>' . esc_html( $failure_message ) . '</p>',
			);
		} catch ( \Exception $th ) {
			$this->log_error(
				'Apple Pay process_payment: exception for order ' . $order_id . ' — ' . $th->getMessage(),
				array(
					'code' => $th->getCode(),
					'file' => $th->getFile(),
					'line' => $th->getLine(),
				)
			);
			$failure_message = $build_failure_message( $th->getMessage() );
			return array(
				'result'   => 'failure',
				'message'  => $failure_message,
				'messages' => '<p>' . esc_html( $failure_message ) . '</p>',
			);
		}
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
			'Apple Pay scheduled subscription payment started.',
			array(
				'amount'           => $amount_to_charge,
				'renewal_order_id' => is_object( $renewal_order ) && method_exists( $renewal_order, 'get_id' ) ? $renewal_order->get_id() : null,
			)
		);

		$payment_method = new Apple_Pay_Payment_Method(
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
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$this->log_info(
			'Apple Pay refund requested.',
			array(
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		$payment_method = new Apple_Pay_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		$refund_result = $this->refund_order_payment( $payment_method, $order_id, $amount, $reason );
		if ( is_wp_error( $refund_result ) ) {
			$this->log_warning(
				'Apple Pay refund failed.',
				array(
					'order_id' => $order_id,
					'error'    => $refund_result->get_error_message(),
				)
			);
		} else {
			$this->log_info( 'Apple Pay refund completed.', array( 'order_id' => $order_id ) );
		}

		return $refund_result;
	}
}
