<?php
/**
 * Card Payment Gateway.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes;

use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WC_Customer;
use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Card as Card_Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Payment_Process;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;
use PaymentNetwork\PaymentModule\Includes\Traits\Three_DS_Callback;
use PaymentNetwork\PaymentModule\Includes\Traits\Order_Refund;
use PaymentNetwork\PaymentModule\Includes\Traits\Subscription_Process;

/**
 * Card Payment Gateway.
 *
 * Processes card transactions using Hosted Fields, with support for
 * tokenization, 3DS authentication, subscriptions, and refunds.
 */
class Card extends \WC_Payment_Gateway_CC {
	/**
	 * Use Payment_Process, Logger, Three_DS_Callback, Order_Refund, and Subscription_Process traits.
	 */
	use Payment_Process;
	use Logger;
	use Three_DS_Callback;
	use Order_Refund;
	use Subscription_Process;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Plugin_Config::get_plugin_id() . '_card';
		$this->icon               = plugins_url( '/', __DIR__ ) . 'assets/images/icon-card.svg?v=' . Plugin_Config::get_plugin_version();
		$this->method_title       = Plugin_Config::get_plugin_title() . ' - Card Payments (Hosted Fields)';
		$this->method_description = 'Process card transactions using Hosted Fields on ' . Plugin_Config::get_plugin_title();
		$this->has_fields         = true;

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
			'tokenization',
		);

		$this->init_form_fields();

		// Initialise settings.
		$this->init_settings();
		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->title       = $this->get_option( 'title', 'Card Payment' );
		$this->description = $this->get_option( 'description', 'Pay using your credit or debit card.' );

		add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_card_settings_admin_script' ) );

		// Action to add Hosted Fields javascript to credit card form.
		add_filter( 'woocommerce_credit_card_form_fields', array( $this, 'woocommerce_credit_card_form_fields' ), 10, 2 );
		add_action( 'woocommerce_credit_card_form_end', array( $this, 'woocommerce_credit_card_form_end' ), 10 );
		add_filter( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', array( $this, 'get_saved_method_option_html_filter' ), 10, 3 );

		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( "woocommerce_api_wc_{$this->id}_three_ds_callback", array( $this, 'three_ds_callback' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

		if ( is_admin() ) {
			$this->adjust_refund_method_title();
		}
	}

	/**
	 * Initialise Gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                   => array(
				'title'       => __( 'Enable/Disable' ),
				'label'       => __( 'Enable Card Payments (Hosted Fields)' ),
				'type'        => 'checkbox',
				'description' => __( 'Enabled Card Payments (Hosted Fields).' ),
				'default'     => 'no',
			),
			'title'                     => array(
				'title'       => __( 'Title' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.' ),
				'default'     => __( 'Card Payment' ),
			),
			'description'               => array(
				'title'       => __( 'Description' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.' ),
				'default'     => __( 'Pay securely using Hosted Fields' ),
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
	 * Function for `woocommerce_payment_gateway_get_saved_payment_method_option_html` filter-hook.
	 *
	 * @param string              $html    HTML for the saved payment methods.
	 * @param \WC_Payment_Token   $token   Token.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 * @return string
	 */
	public function get_saved_method_option_html_filter( $html, $token, $gateway ): string {
		// Only modify this plugin's saved method HTML.
		if ( ! $gateway instanceof \WC_Payment_Gateway || $gateway->id !== $this->id ) {
			return $html;
		}

		// Defensive check to ensure token is a WC_Payment_Token instance.
		if ( ! $token instanceof \WC_Payment_Token ) {
			return $html;
		}

		$gateway_id   = esc_attr( $this->id );
		$token_id     = esc_attr( $token->get_id() );
		$display_name = esc_html( $token->get_display_name() );
		$checked      = checked( $token->is_default(), true, false );

		$html = <<<HTML
		<li class="woocommerce-SavedPaymentMethods-token">
			<input id="wc-{$gateway_id}-payment-token-{$token_id}" type="radio" name="wc-{$gateway_id}-payment-token" value="{$token_id}" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" {$checked} />
			<label for="wc-{$gateway_id}-payment-token-{$token_id}">{$display_name}</label>
			<br>
			<label for="wc-{$gateway_id}-payment-token-cvv-{$token_id}" class="screen-reader-text">CVV for {$display_name}</label>
			<input id="wc-{$gateway_id}-payment-token-cvv-{$token_id}" name="wc-{$gateway_id}-payment-token-cvv-{$token_id}" class="input-text" type="text" inputmode="numeric" maxlength="4" pattern="\d{3,4}" autocomplete="cc-csc" placeholder="CVV" aria-label="CVV for {$display_name}" style="display:none; width:80px; padding:8px 12px; border:1px solid #8c8f94; border-radius:4px; font-size:14px; margin-top:6px;" />
		</li>
		HTML;

		return $html;
	}

	/**
	 * Add a new saved card.
	 */
	public function add_new_saved_card() {
		$this->log_info( 'Card add_new_saved_card started.' );
		$payment_data_raw = filter_input( INPUT_POST, 'paymentData', FILTER_UNSAFE_RAW );

		// Verify this request has the security nonce required.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $this->id ) ) {
			$this->log_warning( 'Card add_new_saved_card blocked: invalid nonce.' );
			return wp_send_json_error( array( 'error' ) );
		}

		if ( ! is_string( $payment_data_raw ) || '' === $payment_data_raw ) {
			$this->log_warning( 'Card add_new_saved_card blocked: missing paymentData.' );
			return wp_send_json_error( array( 'error' ) );
		}

		// If user is not logged in, get customer.
		if ( ! is_user_logged_in() ) {
			$this->log_warning( 'Card add_new_saved_card blocked: user not logged in.' );
			return wp_send_json_error( array( 'error' ) );
		}

		$customer               = new \WC_Customer( get_current_user_id() );
		$payment_data           = json_decode( wp_unslash( $payment_data_raw ), true );
		$device_information     = isset( $payment_data['deviceInformation'] ) && is_array( $payment_data['deviceInformation'] )
			? $payment_data['deviceInformation']
			: array();
		$device_accept_language = isset( $device_information['deviceAcceptLanguage'] )
			? ( is_array( $device_information['deviceAcceptLanguage'] ) ? ( $device_information['deviceAcceptLanguage'][0] ?? null ) : $device_information['deviceAcceptLanguage'] )
			: null;
		$unique_ref             = uniqid( 'wc-saved-card-verification-' );

		$transaction = new Transaction(
			array(
				'transaction_type'       => 'ECOM_VERIFY',
				'currencyCode'           => get_woocommerce_currency(),
				'countryCode'            => Plugin_Config::get_country_code(),
				'transactionUnique'      => $unique_ref,
				'customerCountryCode'    => $customer->get_billing_country(),
				'customerAddress'        => $customer->get_billing_address_1() . ' ' . $customer->get_billing_address_2(),
				'customerCounty'         => $customer->get_billing_state(),
				'customerTown'           => $customer->get_billing_city(),
				'customerPostCode'       => $customer->get_billing_postcode(),
				'customerEmail'          => $customer->get_email(),
				'amount'                 => 0,
				'customerName'           => "{$customer->get_first_name()} {$customer->get_last_name()}",
				'remoteAddress'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'merchantData'           => wp_json_encode(
					array(
						'paymentType' => $payment_data['paymentType'] ?? 'unknown',
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
				'rtAgreementType'        => 'cardonfile',
				'cardStore'              => 'Y',
				'paymentToken'           => $payment_data['paymentToken'] ?? null,
			)
		);

		$payment_method = new Card_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		// Send request to gateway.
		$transaction = $payment_method->process( $transaction );
		$this->log_debug(
			'Card add_new_saved_card gateway transaction processed.',
			array(
				'transaction_unique' => $unique_ref,
				'status'             => $transaction->status(),
				'response_code'      => $transaction->responseCode ?? null,
			)
		);

		// Transient data.
		$transient_data = array(
			'transaction' => $transaction,
			'paymentType' => 'saved_card_verification',
		);

		// Store the transaction data.
		set_transient( $unique_ref, $transient_data, 360 );

		switch ( $transaction->status() ) {

			case 'captured':
			case 'verified':
				$this->log_info( 'Card add_new_saved_card succeeded.', array( 'transaction_unique' => $unique_ref ) );
				$response = array(
					'result'        => 'success',
					'paymentResult' => 'captured',
				);

				wp_send_json_success( $response );

				break;

			case 'received':
				switch ( $transaction->responseCode ) {

					case 65802: // 3DS required, show 3DS form.
						$this->log_info( 'Card add_new_saved_card requires 3DS.', array( 'transaction_unique' => $unique_ref ) );
						$response = array(
							'result'          => 'success',
							'paymentResult'   => 'requires_action',
							'threeDSRequired' => true,
							'threeDSData'     => wp_json_encode(
								array(
									'acsURL'         => $transaction->threeDSURL,
									'threeDSRequest' => $transaction->threeDSRequest,
								)
							),
						);

						wp_send_json_success( $response );

						break;

					default:
						$this->log_warning(
							'Card add_new_saved_card failed in RECEIVED state.',
							array(
								'transaction_unique' => $unique_ref,
								'response_code'      => $transaction->responseCode ?? null,
								'message'            => $transaction->responseMessage ?? 'Unknown reason',
							)
						);
						return array(
							'result'  => 'failure',
							'message' => ( $transaction->responseMessage ?? 'Unknown reason' ),
						);
				}

				$json_response = array(
					'result'          => 'success',
					'messages'        => 'Process 3DS',
					'threeDSRequired' => true,
					'threeDSData'     => array(
						'acsURL'         => $transaction->acsUrl,
						'threeDSRequest' => $transaction->threeDSRequest,
					),
				);

				wp_send_json( $json_response );

				break;

			default:
				$this->log_warning(
					'Card add_new_saved_card ended with unhandled status.',
					array(
						'transaction_unique' => $unique_ref,
						'status'             => $transaction->status(),
					)
				);
				exit;
		}
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id The order ID.
	 * @return array
	 * @throws \InvalidArgumentException When required payment data is missing/invalid.
	 * @throws \Exception When payment fails in legacy checkout flow.
	 */
	public function process_payment( $order_id ) {
		$is_blocks_checkout = $this->is_blocks_checkout_request();
		$is_legacy_ajax     = isset( $_GET['ajax'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ajax'] ) );
		$this->log_info(
			'Card process_payment started.',
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
				$payment_data = json_decode( wp_unslash( $_POST['paymentdata'] ), true );
			} else {
				$this->log_error( 'Card process_payment failed: no payment data received.', array( 'order_id' => $order_id ) );
				throw new \InvalidArgumentException( 'No payment data received' );
			}

			// Check if this is a new card payment or a saved card payment.
			// Validate payment data based on payment type.
			$payment_type = $payment_data['paymentType'] ?? '';
			$this->log_debug(
				'Card process_payment payment type detected.',
				array(
					'order_id'     => $order_id,
					'payment_type' => $payment_type,
				)
			);

			if ( 'card_order_payment' === $payment_type ) {
				$payment_token = $payment_data['cardData']['paymentToken'] ?? '';
				if ( ! is_string( $payment_token ) || '' === trim( $payment_token ) ) {
					throw new \InvalidArgumentException( 'Card details are missing. Please try again.' );
				}
			} elseif ( 'saved_card_order_payment' === $payment_type ) {
				if ( ! is_user_logged_in() ) {
					throw new \InvalidArgumentException( 'Please sign in to use a saved card.' );
				}

				$selected_token_id = isset( $payment_data['selectedTokenId'] ) ? (int) $payment_data['selectedTokenId'] : 0;
				if ( $selected_token_id <= 0 ) {
					throw new \InvalidArgumentException( 'Please select a saved card.' );
				}

				$token = WC_Payment_Tokens::get( $selected_token_id );
				if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
					throw new \InvalidArgumentException( 'Invalid payment token.' );
				}

				$card_cvv = isset( $payment_data['cardCVV'] ) ? preg_replace( '/\D/', '', (string) $payment_data['cardCVV'] ) : '';
				if ( ! preg_match( '/^\d{3,4}$/', $card_cvv ) ) {
					throw new \InvalidArgumentException( 'Please enter a valid card security code.' );
				}
			} else {
				throw new \InvalidArgumentException( 'Invalid payment type.' );
			}

			$device_information     = isset( $payment_data['deviceInformation'] ) && is_array( $payment_data['deviceInformation'] )
				? $payment_data['deviceInformation']
				: array();
			$device_accept_language = isset( $device_information['deviceAcceptLanguage'] )
				? ( is_array( $device_information['deviceAcceptLanguage'] ) ? ( $device_information['deviceAcceptLanguage'][0] ?? null ) : $device_information['deviceAcceptLanguage'] )
				: null;

			// Generate a unique transaction reference.
			$unique_ref = 'wc-order-payment-' . uniqid();

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
							'paymentType' => 'card_order_payment',
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
				)
			);

			if ( 'card_order_payment' === $payment_data['paymentType'] ) {
				$transaction->paymentToken = $payment_data['cardData']['paymentToken'];

				// Check if the customer wants to save the card.
				if ( is_user_logged_in() && isset( $_POST['shouldsavepayment'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['shouldsavepayment'] ) ) ) {
					$transaction->cardStore       = 'Y';
					$transaction->rtAgreementType = 'cardonfile';
				}
			} elseif ( 'saved_card_order_payment' === $payment_data['paymentType'] && is_user_logged_in() ) {
				// Get WC token.
				$token = WC_Payment_Tokens::get( $payment_data['selectedTokenId'] );

				// Check if token is valid and belongs to the user.
				if ( ! $token || $token->get_user_id() !== get_current_user_id() ) {
					throw new \Exception( 'Invalid payment token' );
				}

				$transaction->cardID          = $token->get_token();
				$transaction->cardCVV         = $payment_data['cardCVV'];
				$transaction->rtAgreementType = 'cardonfile';
			}

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

			$payment_method = new Card_Payment_Method(
				array(
					'merchantID'       => Plugin_Config::get_merchant_id(),
					'merchant_secret'  => Plugin_Config::get_merchant_secret(),
					'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
				)
			);

			// Send request to gateway.
			$transaction = $payment_method->process( $transaction );
			$this->log_debug(
				'Card process_payment gateway transaction processed.',
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
				'paymentType' => $payment_data['paymentType'] ?? 'unknown',
			);

			// Store the transaction data.
			set_transient( $unique_ref, $transient_data, 360 );

			// Handle the outcome of the transaction and respond back to the checkout.
			switch ( $transaction->status() ) {
				case 'captured':
					$this->log_info(
						'Card process_payment succeeded.',
						array(
							'order_id'           => $order_id,
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
								'Card process_payment requires 3DS.',
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
								'messages'        => '<p>Processing 3DS...</p>',
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
								'Card process_payment failed in RECEIVED state.',
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
					$failure_message = $build_failure_message(
						$transaction->responseMessage,
						'Transaction ' . ( $transaction->state ?? 'failed' ) . '. Please try again.'
					);
					$this->log_warning(
						'Card process_payment declined/rejected.',
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
				'Card process_payment exception.',
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
			$this->log_debug( 'Card process_payment responding via legacy AJAX.', array( 'order_id' => $order_id ) );
			wp_send_json( $response );
		}

		return $response;
	}

	/**
	 * Check if the current request is a WooCommerce Blocks checkout request.
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
	 * Enqueue Hosted Fields JS and dependencies
	 * for blocks and old themes.
	 */
	public function payment_scripts() {
		if ( is_checkout() || is_checkout_pay_page() || is_order_received_page() || is_add_payment_method_page() ) {
			$this->log_debug( 'Card hosted fields script enqueued.' );
			wp_enqueue_script(
				'hosted_payment_fields_gateway_javascript',
				'https://' . Plugin_Config::get_gateway_host_name() . '/sdk/web/v1/js/hostedfields.min.js',
				array(),
				Plugin_Config::get_plugin_version(),
				true
			);
		} else {
			$this->log_debug( 'Card payment_scripts skipped: not a checkout/add-payment context.' );
		}
	}

	/**
	 * Replace card fields.
	 *
	 * Filters the HTML for the credit card fields used by non-block themes
	 * and replaces them with input fields required for Hosted Fields.
	 *
	 * @param array $cc_fields The original fields generated by WooCommerce.
	 * @param int   $plugin_id The ID of the payment plugin.
	 * @return array The new filtered credit card fields.
	 */
	public function woocommerce_credit_card_form_fields( $cc_fields, $plugin_id ): array {
		// Only modify this plugin's saved method HTML.
		if ( $plugin_id !== $this->id ) {
			return $cc_fields;
		}

		$hosted_fields = array(
			'card-number-field' => array(
				'type'             => 'hostedfield:cardNumber',
				'name'             => 'card-number',
				'style'            => 'border-radius: 8px; width:fit-content; height:58px; padding:10px 15px; border:1px solid #8c8f94; border-radius:4px;',
				'data-hostedfield' => esc_attr( wp_json_encode( array( 'placeholder' => 'Card number' ) ) ),
				'autocomplete'     => 'cc-number',
				'required'         => true,
			),
			'card-expiry-field' => array(
				'type'             => 'hostedfield:cardExpiryDate',
				'name'             => 'card-expiry-date',
				'style'            => 'border-radius: 8px; width:fit-content; height:58px; padding:10px 15px; border:1px solid #8c8f94; border-radius:4px;',
				'data-hostedfield' => esc_attr( wp_json_encode( array( 'placeholder' => 'MM/YY' ) ) ),
				'autocomplete'     => 'cc-exp',
				'required'         => true,
			),
			'card-cvc-field'    => array(
				'type'             => 'hostedfield:cardCVV',
				'name'             => 'card-cvv',
				'style'            => 'border-radius: 8px; width:fit-content; height:58px; padding:10px 15px; border:1px solid #8c8f94; border-radius:4px;',
				'data-hostedfield' => esc_attr(
					wp_json_encode(
						array(
							'placeholder'   => 'CVV',
							'submitOnEnter' => false,
						)
					)
				),
				'autocomplete'     => 'cc-csc',
				'required'         => true,
			),
		);

		// Replace each default WC CC field with input fields needed for hosted fields.
		foreach ( $cc_fields as $key => $cc_field ) {
			$field             = $hosted_fields[ $key ];
			$type              = $field['type'];
			$style             = isset( $field['style'] ) ? $field['style'] : '';
			$name              = $field['name'];
			$hosted_data       = $field['data-hostedfield'];
			$autocomplete      = $field['autocomplete'];
			$required          = $field['required'] ? 'required' : '';
			$cc_fields[ $key ] = <<<HTML
			<p class="form-row form-row-wide">
			<input
			class="input-text"
			id="{$key}"
			type="{$type}"
			style="{$style}"
			name="{$name}"
			data-hostedfield="{$hosted_data}"
			autocomplete="{$autocomplete}"
			{$required}
			/>
			</p>
			HTML;
		}

		// Return the credit card fields back to WC.
		return $cc_fields;
	}

	/**
	 * Function for hook woocommerce_credit_card_form_end.
	 *
	 * Enqueues JavaScript to non-block credit card form.
	 */
	public function woocommerce_credit_card_form_end(): void {
		$this->log_debug( 'Card credit card form end hook executed.' );
		wp_enqueue_style(
			'card-hosted-fields-css',
			plugins_url( '/', __DIR__ ) . 'assets/css/cardfields/style.css',
			array(),
			Plugin_Config::get_plugin_version()
		);

		if ( is_add_payment_method_page() ) {
			$this->log_debug( 'Card add-payment-method assets enqueued.' );

			wp_enqueue_script( 'jquery' );

			wp_enqueue_script(
				'add-payment-method-js',
				plugins_url( '/', __DIR__ ) . 'assets/js/classic/add-payment-method.js',
				array(),
				Plugin_Config::get_plugin_version(),
				true
			);

			wp_localize_script(
				'add-payment-method-js',
				'localizeVars',
				array(
					'ajaxurl'     => admin_url( 'admin-ajax.php' ),
					'merchantID'  => Plugin_Config::get_merchant_id(),
					'nonce'       => wp_create_nonce( $this->id ),
					'id'          => $this->id,
					'description' => $this->description,
				)
			);

		} elseif ( is_checkout() || is_checkout_pay_page() || is_order_received_page() ) {
			$this->log_debug( 'Card checkout assets enqueued.' );

			wp_enqueue_script(
				'card-classic',
				plugins_url( '/', __DIR__ ) . 'assets/js/classic/card-classic.js',
				array(),
				Plugin_Config::get_plugin_version(),
				true
			);

			wp_localize_script(
				'card-classic',
				'localizeCardVars',
				array(
					'ajaxurl'       => admin_url( 'admin-ajax.php' ),
					'storeApiNonce' => wp_create_nonce( 'wc_store_api' ),
					'merchantID'    => Plugin_Config::get_merchant_id(),
					'id'            => $this->id,
					'description'   => $this->description,
				)
			);

			wp_enqueue_script(
				'saved-token-cvv-js',
				plugins_url( '/', __DIR__ ) . 'assets/js/classic/saved-token-cvv.js',
				array( 'card-classic' ),
				Plugin_Config::get_plugin_version(),
				true
			);
		}
	}

	/**
	 * Enqueue admin script for custom transaction fields table on Card gateway settings.
	 *
	 * @return void
	 */
	public function enqueue_card_settings_admin_script(): void {
		if ( ! is_admin() ) {
			$this->log_debug( 'Card settings assets enqueue skipped: not admin context.' );
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			$this->log_debug(
				'Card settings assets enqueue skipped: wrong admin screen.',
				array( 'screen_id' => $screen ? $screen->id : null )
			);
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $this->id !== $section ) {
			$this->log_debug(
				'Card settings assets enqueue skipped: section mismatch.',
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

		$this->log_debug( 'Card settings admin assets enqueued.' );
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
				'Card custom transaction fields JSON validation failed.',
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
			'Card custom transaction fields validated.',
			array(
				'field_key'        => $key,
				'normalized_count' => count( $normalized_fields ),
			)
		);

		return wp_json_encode( $normalized_fields );
	}

	/**
	 * Process adding a card from My Account page
	 * after a verify transaction has been processed.
	 *
	 * @return array
	 */
	public function add_payment_method() {
		$this->log_info( 'Card add_payment_method started.' );
		try {
			// WooCommerce verifies the add-payment-method nonce before invoking this gateway callback.

			$transaction_unique = isset( $_POST['transactionUnique'] ) ? sanitize_text_field( wp_unslash( $_POST['transactionUnique'] ) ) : '';
			$transaction_data   = get_transient( wc_clean( $transaction_unique ) );
			$transaction        = $transaction_data['transaction'] ?? null;

			switch ( $transaction->status() ) {

				case 'verified':
					$this->log_info( 'Card add_payment_method verified transaction; saving token.' );
					$token = new WC_Payment_Token_CC();
					$token->set_gateway_id( $this->id );
					$token->set_token( $transaction->cardID );
					$token->set_card_type( $transaction->cardScheme );
					$last_4 = substr( preg_replace( '/\D/', '', (string) $transaction->cardNumberMask ), -4 );
					$token->set_last4( $last_4 );
					$token->set_expiry_month( $transaction->cardExpiryMonth );
					$token->set_expiry_year( "20{$transaction->cardExpiryYear}" );
					$token->set_user_id( get_current_user_id() );
					$token->save();

					return array(
						'result' => 'success',
					);

				default:
					$this->log_warning( 'Card add_payment_method failed: transaction not verified.' );
					return array(
						'result' => 'failure',
					);
			}
		} catch ( \Exception $th ) {
			$this->log_error(
				'Card add_payment_method exception.',
				array(
					'message' => $th->getMessage(),
					'code'    => $th->getCode(),
					'file'    => $th->getFile(),
					'line'    => $th->getLine(),
				)
			);
			return array(
				'result' => 'failure',
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
			'Card scheduled subscription payment started.',
			array(
				'amount'           => $amount_to_charge,
				'renewal_order_id' => is_object( $renewal_order ) && method_exists( $renewal_order, 'get_id' ) ? $renewal_order->get_id() : null,
			)
		);

		$payment_method = new Card_Payment_Method(
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
			'Card refund requested.',
			array(
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			)
		);

		$payment_method = new Card_Payment_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		$refund_result = $this->refund_order_payment( $payment_method, $order_id, $amount, $reason );

		if ( is_wp_error( $refund_result ) ) {
			$this->log_warning(
				'Card refund failed.',
				array(
					'order_id' => $order_id,
					'error'    => $refund_result->get_error_message(),
				)
			);
		} else {
			$this->log_info( 'Card refund completed.', array( 'order_id' => $order_id ) );
		}

		return $refund_result;
	}
}
