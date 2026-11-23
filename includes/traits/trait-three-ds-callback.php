<?php
/**
 * ThreeDS callback trait.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Traits;

use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Three_DS as Three_DS_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;

/**
 * ThreeDSCallback trait.
 *
 * Handles the 3DS continuation callback from the payment gateway.
 */
trait Three_DS_Callback {

	use Logger;

	/**
	 * Process the 3DS callback.
	 */
	public function three_ds_callback() {
		$transaction_unique_raw = filter_input( INPUT_GET, 'transaction_unique', FILTER_UNSAFE_RAW );
		$transaction_unique     = is_string( $transaction_unique_raw ) ? wc_clean( wp_unslash( $transaction_unique_raw ) ) : '';
		$this->log_info( '3DS callback received.', array( 'transaction_unique' => $transaction_unique ) );

		$transaction_data = get_transient( $transaction_unique );

		if ( false === $transaction_data || ! isset( $transaction_data['transaction'] ) ) {
			$this->log_error( '3DS callback failed: missing or expired transaction transient.', array( 'transaction_unique' => $transaction_unique ) );
			wp_die( esc_html__( 'Payment session expired or invalid.', 'woocommerce-payment-module' ), '', array( 'response' => 400 ) );
		}

		$this->log_debug(
			'3DS callback transient loaded.',
			array(
				'transaction_unique' => $transaction_unique,
				'order_id'           => $transaction_data['order_id'] ?? null,
				'payment_type'       => $transaction_data['paymentType'] ?? null,
			)
		);

		$payment_method = new Three_DS_Method(
			array(
				'merchantID'       => Plugin_Config::get_merchant_id(),
				'merchant_secret'  => Plugin_Config::get_merchant_secret(),
				'gateway_hostname' => Plugin_Config::get_gateway_host_name(),
			)
		);

		// This endpoint is called by the gateway, so there is no WordPress nonce to verify.
		$three_ds_response = map_deep( wp_unslash( $_POST ), 'wc_clean' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$three_ds_request = new Transaction(
			array(
				'transaction_type' => 'THREE_DS_CONTINUATION',
				'threeDSRef'       => $transaction_data['transaction']->threeDSRef,
				'threeDSResponse'  => $three_ds_response,
			)
		);

		$this->log_debug(
			'3DS continuation request prepared.',
			array(
				'transaction_unique' => $transaction_unique,
				'three_ds_ref'       => $transaction_data['transaction']->threeDSRef ?? null,
			)
		);

		$transaction_data['transaction'] = $payment_method->process( $three_ds_request );

		$this->log_debug(
			'3DS continuation processed by gateway.',
			array(
				'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique,
				'status'             => $transaction_data['transaction']->status(),
				'response_code'      => $transaction_data['transaction']->responseCode ?? null,
				'state'              => $transaction_data['transaction']->state ?? null,
			)
		);

		// Update temp store the transaction data for use.
		set_transient( $transaction_data['transaction']->transactionUnique, $transaction_data, 460 );
		$this->log_debug( '3DS callback transient refreshed.', array( 'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique ) );

		// Get the payment status.
		switch ( $transaction_data['transaction']->status() ) {

			case 'declined':
			case 'rejected':
			case 'error':
			case 'finished':
			case 'referred':
				$this->log_warning(
					'3DS callback resulted in failed payment state.',
					array(
						'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique,
						'status'             => $transaction_data['transaction']->status(),
						'response_code'      => $transaction_data['transaction']->responseCode ?? null,
						'order_id'           => $transaction_data['order_id'] ?? null,
					)
				);

				$order = null;
				if ( 'saved_card_verification' !== $transaction_data['paymentType'] ) {
					$order = wc_get_order( $transaction_data['order_id'] );
					$this->process_payment_failed( $transaction_data['transaction'], $order );
				}

				wc_get_template(
					'postmessage.php',
					array(
						'message' => array(
							'result' => 'failure',
							'data'   => array(
								'transactionUnique' => $transaction_data['transaction']->transactionUnique,
								'paymentResult'     => $transaction_data['transaction']->state,
								'message'           => ( $transaction_data['transaction']->responseMessage ?? 'Unknown reason' ),
							),
						),
					),
					Plugin_Config::get_template_path(),
					plugin_dir_path( dirname( __DIR__ ) ) . 'templates/'
				);

				break;

			case 'captured':
			case 'verified':
				$this->log_info(
					'3DS callback resulted in successful payment state.',
					array(
						'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique,
						'status'             => $transaction_data['transaction']->status(),
						'order_id'           => $transaction_data['order_id'] ?? null,
					)
				);

				$order = null;
				if ( 'saved_card_verification' !== $transaction_data['paymentType'] ) {
					$order = wc_get_order( $transaction_data['order_id'] );
					$this->process_payment_success( $transaction_data['transaction'], $order );
				}

				wc_get_template(
					'postmessage.php',
					array(
						'message' => array(
							'result' => 'success',
							'data'   => array(
								'transactionUnique' => $transaction_data['transaction']->transactionUnique,
								'paymentResult'     => $transaction_data['transaction']->state,
								'redirect'          => $order ? $order->get_checkout_order_received_url() : false,
							),
						),
					),
					Plugin_Config::get_template_path(),
					plugin_dir_path( dirname( __DIR__ ) ) . 'templates/'
				);

				break;

			case 'received':
				$this->log_debug(
					'3DS callback in RECEIVED state.',
					array(
						'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique,
						'response_code'      => $transaction_data['transaction']->responseCode ?? null,
					)
				);

				switch ( $transaction_data['transaction']->responseCode ) {

					case 65802: // 3DS required, show 3DS form.
						$this->log_info( '3DS challenge required; rendering ACS form.', array( 'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique ) );

						wc_get_template(
							'3ds-form.php',
							array(
								'acs_url'          => $transaction_data['transaction']->threeDSURL,
								'three_ds_request' => $transaction_data['transaction']->threeDSRequest,
								'auto_submit'      => true,
							),
							Plugin_Config::get_template_path(),
							plugin_dir_path( dirname( __DIR__ ) ) . 'templates/'
						);

						break;
				}

				break;

			default:
				$this->log_error(
					'3DS callback reached unhandled transaction state.',
					array(
						'transaction_unique' => $transaction_data['transaction']->transactionUnique ?? $transaction_unique,
						'status'             => $transaction_data['transaction']->status(),
					)
				);
				die( 'error' );

		}

		// Must exit to avoid -1.
		$this->log_debug( '3DS callback completed; exiting request.' );
		exit();
	}
}
