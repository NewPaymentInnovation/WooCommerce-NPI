<?php
/**
 * Hosted Payment Gateway.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes;

/**
 * Hosted Form Integration.
 *
 * Loads the correct payment form type based on gateway configuration.
 * Builds and processes hosted transactions for WooCommerce orders.
 * Connects callback handling to verify and complete payment responses.
 */
use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Hosted as Hosted_Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Payment_Process;
use PaymentNetwork\PaymentModule\Includes\Traits\Order_Refund;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;
use PaymentNetwork\PaymentModule\Includes\Traits\Subscription_Process;

/**
 * Hosted Payment Gateway.
 */
class Hosted extends \WC_Payment_Gateway {

	/**
	 * Use Payment_Process, Logger, Order_Refund, and Subscription_Process traits.
	 */
	use Payment_Process;
	use Order_Refund;
	use Logger;
	use Subscription_Process;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->has_fields         = false;
		$this->id                 = Plugin_Config::get_plugin_id() . '_hosted';
		$this->method_title       = Plugin_Config::get_plugin_title() . ' - Hosted Payment Form';
		$this->method_description = 'Process transactions using Hosted Payment Form integration on ' . Plugin_Config::get_plugin_title();
		$this->icon               = plugins_url( '/', __DIR__ ) . 'assets/images/icon-hosted.svg?v=' . Plugin_Config::get_plugin_version();

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
		$this->title       = $this->get_option( 'title', 'Payment Form' );
		$this->description = $this->get_option( 'description', 'Pay using our secure payment form.' );

		add_action( "woocommerce_api_wc_{$this->id}_hosted_callback", array( $this, 'hosted_callback' ) );
		add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_hosted_settings_admin_script' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
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
				'label'       => __( 'Enable Hosted Payment Form' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable Hosted Payment Form payment option.' ),
				'default'     => false,
			),
			'title'                     => array(
				'title'       => __( 'Title' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.' ),
				'default'     => __( 'Hosted Payment Form' ),
			),
			'description'               => array(
				'title'       => __( 'Description' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.' ),
				'default'     => __( 'Pay securely using Hosted Payment Form.' ),
			),
			'type'                      => array(
				'title'       => __( 'Hosted Form version' ),
				'type'        => 'select',
				'options'     => array(
					'v1'    => 'Hosted Form v1',
					'modal' => 'Hosted Form v2 (Modal)',
					'chf'   => 'Custom Hosted Form / Others (Requires custom URL)',
				),
				'description' => __( 'Hosted Form version' ),
				'default'     => 'v1',
			),
			'chf_url'                   => array(
				'title'       => __( 'Custom Hosted Form URL' ),
				'type'        => 'text',
				'description' => __( 'URL for Custom Hosted Form. Only used if Hosted Form version is set to Custom Hosted Form.' ),
				'default'     => '',
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
	 * Output HTML for the payment fields on the checkout page.
	 * Enqueue payment scripts for classic checkout.
	 */
	public function payment_fields() {
		if ( 'modal' === $this->get_option( 'type', 'v1' ) ) {
			$this->log_debug( 'Hosted payment_fields rendering modal integration.' );
			wp_enqueue_script(
				'hosted_form_classic_checkout_js',
				plugins_url( '/', __DIR__ ) . 'assets/js/classic/hosted-classic.js',
				array( 'jquery' ),
				Plugin_Config::get_plugin_version(),
				true
			);

			wp_localize_script(
				'hosted_form_classic_checkout_js',
				'localizeHostedClassicVars',
				array(
					'type'          => $this->get_option( 'type', 'v1' ),
					'storeApiNonce' => wp_create_nonce( 'wc_store_api' ),
					'id'            => $this->id,
					'description'   => $this->description,
				)
			);
		}

		$this->log_debug( 'Hosted payment_fields rendered.' );

		echo '<div id="' . esc_attr( $this->id ) . '-payment-container">' . esc_html( $this->description ) . '</div>';
	}

	/**
	 * Output the receipt page with the hosted payment form.
	 *
	 * @param int $order Order ID.
	 */
	public function receipt_page( $order ) {
		$this->log_debug( 'Hosted receipt_page rendered.', array( 'order_id' => $order ) );
		$transaction_unique = isset( $_GET['transaction_unique'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_unique'] ) ) : '';
		$transaction_data   = get_transient( $transaction_unique );

		if ( ! $transaction_data ) {
			$this->log_warning( 'Hosted receipt_page failed: expired or missing transaction transient.', array( 'transaction_unique' => $transaction_unique ) );
			echo '<p>' . esc_html__( 'Payment expired', 'woocommerce' ) . '</p>';
			return;
		}

		wc_get_template(
			'hosted-iframe.php',
			array(
				'title'          => __( 'Thank you for your order, please complete the payment form below', 'woocommerce' ),
				'request_fields' => $transaction_data['transaction']->hostedRequestFields,
				'gateway_url'    => $this->get_option( 'chf_url', 'https://' . Plugin_Config::get_gateway_host_name() . '/paymentform/' ),
			),
			Plugin_Config::get_template_path(),
			plugin_dir_path( __DIR__ ) . 'templates/'
		);

		wp_enqueue_script(
			'hosted_form_order_payment.js',
			plugins_url( '/', __DIR__ ) . 'assets/js/classic/hosted-form-order-payment.js',
			array( 'jquery' ),
			Plugin_Config::get_plugin_version(),
			true
		);

		wp_localize_script(
			'hosted_form_order_payment.js',
			'hostedFormOrderPaymentVars',
			array(
				'checkoutUrl' => wc_get_checkout_url(),
			)
		);
	}

	/**
	 * Enqueue admin script for hosted gateway settings UI behavior.
	 */
	public function enqueue_hosted_settings_admin_script(): void {
		if ( ! is_admin() ) {
			$this->log_debug( 'Hosted settings assets enqueue skipped: not admin context.' );
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			$this->log_debug(
				'Hosted settings assets enqueue skipped: wrong admin screen.',
				array( 'screen_id' => $screen ? $screen->id : null )
			);
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $section !== $this->id ) {
			$this->log_debug(
				'Hosted settings assets enqueue skipped: section mismatch.',
				array(
					'section'  => $section,
					'expected' => $this->id,
				)
			);
			return;
		}

		wp_enqueue_script(
			'wcpm-hosted-admin-settings',
			plugins_url( '/', __DIR__ ) . 'assets/js/admin/hosted-settings.js',
			array( 'jquery' ),
			Plugin_Config::get_plugin_version(),
			true
		);

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
			'wcpm-hosted-admin-settings',
			'localizeHostedSettingsVars',
			array(
				'typeFieldId'   => 'woocommerce_' . $this->id . '_type',
				'chfUrlFieldId' => 'woocommerce_' . $this->id . '_chf_url',
			)
		);

		wp_localize_script(
			'wcpm-custom-transaction-fields-table',
			'localizeCustomTransactionFieldsVars',
			array(
				'fieldId' => 'woocommerce_' . $this->id . '_custom_transaction_fields',
			)
		);

		$this->log_debug( 'Hosted settings admin assets enqueued.' );
	}

	/**
	 * Validate Custom Hosted Form URL only when Hosted Form type is `chf`.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string Sanitized URL or empty string.
	 */
	public function validate_chf_url_field( $key, $value ): string {
		$type_field_key = 'woocommerce_' . $this->id . '_type';
		$posted_type    = isset( $_POST[ $type_field_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $type_field_key ] ) ) : '';

		if ( 'chf' !== $posted_type ) {
			return '';
		}

		$sanitized_url = esc_url_raw( (string) $value );
		if ( empty( $sanitized_url ) || ! filter_var( $sanitized_url, FILTER_VALIDATE_URL ) ) {
			$this->add_error( __( 'Custom Hosted Form URL is required and must be a valid URL when Hosted Form version is set to Custom Hosted Form.', 'general' ) );

			return (string) $this->get_option( 'chf_url', '' );
		}

		return $sanitized_url;
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
				'Hosted custom transaction fields JSON validation failed.',
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
			'Hosted custom transaction fields validated.',
			array(
				'field_key'        => $key,
				'normalized_count' => count( $normalized_fields ),
			)
		);

		return wp_json_encode( $normalized_fields );
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 * @throws \Exception When payment processing fails in non-block checkout flow.
	 */
	public function process_payment( $order_id ) {
		$is_blocks_checkout = $this->is_blocks_checkout_request();
		$is_legacy_ajax     = isset( $_GET['ajax'] ) && '1' === $_GET['ajax'];
		$this->log_info(
			'Hosted process_payment started.',
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
			$order       = wc_get_order( $order_id );
			$unique_ref  = uniqid( 'wc-order-payment-' );
			$server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';

			$transaction = new Transaction(
				array(
					'merchantID'              => Plugin_Config::get_merchant_id(),
					'transaction_type'        => 0.0 === (float) $order->get_total() ? 'ECOM_VERIFY' : 'ECOM_SALE',
					'currencyCode'            => $order->get_currency(),
					'countryCode'             => Plugin_Config::get_country_code(),
					'transactionUnique'       => $unique_ref,
					'customerCountryCode'     => $order->get_billing_country(),
					'customerAddress'         => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
					'customerCounty'          => $order->get_billing_state(),
					'customerTown'            => $order->get_billing_city(),
					'customerPostCode'        => $order->get_billing_postcode(),
					'customerEmail'           => $order->get_billing_email(),
					'amount'                  => $order->get_total(),
					'customerName'            => "{$order->get_billing_first_name()} {$order->get_billing_last_name()}",
					'merchantData'            => wp_json_encode(
						array(
							'paymentType' => 'hosted_order_payment',
							'module'      => 'woocommerce',
							'version'     => Plugin_Config::get_plugin_version(),
						)
					),
					'redirectURL'             => add_query_arg(
						array(
							'wc-api'             => "wc_{$this->id}_hosted_callback",
							'transaction_unique' => $unique_ref,
						),
						home_url( '/' )
					),
					'applePayCheckoutOptions' => wp_json_encode( array( 'domainName' => $server_name ) ),
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
							$this->log_debug(
								'Applied custom transaction field.',
								array(
									'field' => $field['key'],
									'value' => $field['value'] ?? '',
								)
							);
						}
					}
				}
			}

			$payment_method = new Hosted_Payment_Method(
				array(
					'merchantID'       => Plugin_Config::get_merchant_id(),
					'merchant_secret'  => Plugin_Config::get_merchant_secret(),
					'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
				)
			);

			// Send request to gateway.
			$transaction = $payment_method->process( $transaction );
			$this->log_debug(
				'Hosted process_payment gateway transaction processed.',
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
			);

			// Store the transaction data.
			set_transient( $unique_ref, $transient_data, 960 );

			if ( 'modal' === $this->get_option( 'type', 'v1' ) ) {
				$this->log_info(
					'Hosted process_payment prepared modal response.',
					array(
						'order_id'           => $order_id,
						'transaction_unique' => $unique_ref,
					)
				);
				$response = array(
					'result'          => 'success',
					'paymentResult'   => 'requires_action',
					'message'         => 'Sending payment details to open modal.',
					'reload'          => false,
					'redirect'        => false,
					'messages'        => '<p></p>',
					'hostedFormData'  => wp_json_encode(
						array(
							'hostedRequestFields' => $transaction->hostedRequestFields,
							'paymentFormURL'      => 'https://' . Plugin_Config::get_gateway_host_name() . '/hosted/modal/',
						)
					),
					'payment_details' => array(
						array(
							'key'   => 'paymentResult',
							'value' => 'requires_action',
						),
						array(
							'key'   => 'hostedFormData',
							'value' => wp_json_encode(
								array(
									'hostedRequestFields' => $transaction->hostedRequestFields,
									'paymentFormURL'      => 'https://' . Plugin_Config::get_gateway_host_name() . '/hosted/modal/',
								)
							),
						),
					),
				);
			} else {
				$this->log_info(
					'Hosted process_payment prepared redirect response.',
					array(
						'order_id'           => $order_id,
						'transaction_unique' => $unique_ref,
					)
				);
				$response = array(
					'result'        => 'success',
					'paymentResult' => 'redirect',
					'message'       => 'Redirecting to payment page.',
					'reload'        => false,
					'redirect'      => add_query_arg(
						array(
							'transaction_unique' => $unique_ref,
						),
						$order->get_checkout_payment_url( true ),
					),
					'messages'      => '<p>Redirecting to payment page...</p>',
				);
			}
		} catch ( \Exception $th ) {
			$failure_message = $build_failure_message( $th->getMessage() );
			$this->log_error(
				'Hosted process_payment exception.',
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
			$this->log_debug( 'Hosted process_payment responding via legacy AJAX.', array( 'order_id' => $order_id ) );
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

		return strpos( $request_uri, '/wc/store/v1/checkout' ) !== false;
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
			'Hosted scheduled subscription payment started.',
			array(
				'amount'           => $amount_to_charge,
				'renewal_order_id' => is_object( $renewal_order ) && method_exists( $renewal_order, 'get_id' ) ? $renewal_order->get_id() : null,
			)
		);

		$payment_method = new Hosted_Payment_Method(
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
			'Hosted refund requested.',
			array(
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		$payment_method = new Hosted_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		$refund_result = $this->refund_order_payment( $payment_method, $order_id, $amount, $reason );

		if ( is_wp_error( $refund_result ) ) {
			$this->log_warning(
				'Hosted refund failed.',
				array(
					'order_id' => $order_id,
					'error'    => $refund_result->get_error_message(),
				)
			);
		} else {
			$this->log_info( 'Hosted refund completed.', array( 'order_id' => $order_id ) );
		}

		return $refund_result;
	}

	/**
	 * Handle the hosted payment form callback from the gateway.
	 */
	public function hosted_callback() {
		$this->log_info( 'Hosted callback received.' );
		$transaction_unique = isset( $_GET['transaction_unique'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_unique'] ) ) : '';
		$transaction_data   = $transaction_unique ? get_transient( $transaction_unique ) : false;

		if ( ! is_array( $transaction_data ) || empty( $transaction_data['transaction'] ) || empty( $transaction_data['order_id'] ) ) {
			$this->log_error( sprintf( 'Hosted callback received with missing or expired transient. transaction_unique=%s', $transaction_unique ) );
			status_header( 400 );
			wp_die( esc_html__( 'Payment session expired or invalid.', 'woocommerce-payment-module' ), '', array( 'response' => 400 ) );
		}

		$this->log_debug(
			'Hosted callback transient loaded.',
			array(
				'order_id'           => $transaction_data['order_id'],
				'transaction_unique' => $transaction_unique,
			)
		);

		// Check if the transaction has already been processed to avoid
		// duplicate processing on multiple callbacks.
		$order = wc_get_order( $transaction_data['order_id'] );

		if ( $order ) {

			if ( $order->is_paid() ) {
				$this->log_warning( 'Hosted callback received for already completed order.', array( 'order_id' => $transaction_data['order_id'] ) );
				wp_send_json(
					array(
						'result' => 'success',
						'data'   => array(
							'redirect' => $this->get_return_url( $order ),
							'message'  => 'Order already completed.',
						),
					)
				);
			}
		} else {
			$this->log_error( sprintf( 'Hosted callback order not found. order_id=%s', $transaction_data['order_id'] ) );
			status_header( 404 );
			wp_die( esc_html__( 'Order not found.', 'woocommerce-payment-module' ), '', array( 'response' => 404 ) );
		}

		$payment_method = new Hosted_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		try {
			$transaction = $payment_method->hostedResponse( $transaction_data['transaction'], wp_unslash( $_POST ) );
		} catch ( \Exception $e ) {
			$this->log_error(
				'Hosted callback: hostedResponse() threw an exception — possible signature verification failure.',
				array(
					'order_id' => $transaction_data['order_id'],
					'message'  => $e->getMessage(),
					'code'     => $e->getCode(),
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
				)
			);

			wc_get_template(
				'postmessage.php',
				array(
					'message' => array(
						'result' => 'failure',
						'data'   => array(
							'redirect' => $this->get_return_url( $order ),
							'message'  => 'An error occurred while processing the payment response. Please contact support.',
						),
					),
				),
				Plugin_Config::get_template_path(),
				plugin_dir_path( __DIR__ ) . 'templates/'
			);

			exit();
		}

		$this->log_debug(
			'Hosted callback response parsed.',
			array(
				'order_id'      => $transaction_data['order_id'],
				'status'        => $transaction->status(),
				'response_code' => $transaction->responseCode ?? null,
			)
		);

		// Get the payment status.
		switch ( $transaction->status() ) {
			case 'declined':
			case 'rejected':
			case 'error':
			case 'finished':
			case 'referred':
				$this->log_warning(
					'Hosted callback payment failed.',
					array(
						'order_id' => $transaction_data['order_id'],
						'state'    => $transaction->state ?? null,
					)
				);
				$order = wc_get_order( $transaction_data['order_id'] );
				$this->process_payment_failed( $transaction_data['transaction'], $order );

				wc_get_template(
					'postmessage.php',
					array(
						'message' => array(
							'result' => 'failure',
							'data'   => array(
								'redirect'      => $this->get_return_url( $order ),
								'paymentResult' => $transaction_data['transaction']->state ?? 'finished',
								'message'       => $transaction->responseMessage ?? 'Payment failed. Please try again.',
							),
						),
					),
					Plugin_Config::get_template_path(),
					plugin_dir_path( __DIR__ ) . 'templates/'
				);
				break;

			case 'captured':
			case 'verified':
				$this->log_info(
					'Hosted callback payment succeeded.',
					array(
						'order_id' => $transaction_data['order_id'],
						'state'    => $transaction->state ?? null,
					)
				);
				$order = wc_get_order( $transaction_data['order_id'] );
				$this->process_payment_success( $transaction_data['transaction'], $order );

				wc_get_template(
					'postmessage.php',
					array(
						'message' => array(
							'result' => 'success',
							'data'   => array(
								'paymentResult' => $transaction_data['transaction']->state,
								'redirect'      => $order->get_checkout_order_received_url(),
							),
						),
					),
					Plugin_Config::get_template_path(),
					plugin_dir_path( __DIR__ ) . 'templates/'
				);
				break;

			default:
				$this->log_error(
					'Hosted callback reached unhandled transaction status.',
					array(
						'order_id' => $transaction_data['order_id'],
						'status'   => $transaction->status(),
					)
				);
				die( 'error' );
		}

		// Must exit to avoid -1.
		exit();
	}
}
