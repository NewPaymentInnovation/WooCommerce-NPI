<?php
/**
 * Subscription renewal processing trait.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Traits;

use PaymentNetwork\PaymentModule\Plugin_Config;
use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;

/**
 * Handles scheduled subscription renewal payments for gateways that use this trait.
 *
 * @property string $id Gateway ID (provided by the consuming gateway class).
 */
trait Subscription_Process {

	use Logger;

	/**
	 * Process a scheduled subscription renewal payment.
	 *
	 * Finds the saved card token from the parent subscription order
	 * and sends a Continuous Authority (CA) transaction to the gateway.
	 *
	 * @param Payment_Method $payment_method   The payment method instance.
	 * @param float          $amount_to_charge Renewal amount.
	 * @param \WC_Order      $renewal_order    The renewal order.
	 * @return void
	 */
	public function process_subscription_payment( Payment_Method $payment_method, float $amount_to_charge, \WC_Order $renewal_order ): void {
		$this->log_info(
			'Subscription renewal started.',
			array(
				'gateway_id'       => $this->id ?? '',
				'order_id'         => $renewal_order->get_id(),
				'order_number'     => $renewal_order->get_order_number(),
				'amount_to_charge' => $amount_to_charge,
				'currency'         => $renewal_order->get_currency(),
			)
		);

		// If the renewal order is already paid, do nothing.
		if ( $renewal_order->is_paid() ) {
			$this->log_debug( 'Subscription renewal skipped: order already paid.', array( 'order_id' => $renewal_order->get_id() ) );
			return;
		}

		// Ensure WooCommerce Subscriptions is active.
		if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$this->log_error( 'Subscription renewal failed: WooCommerce Subscriptions is not active.', array( 'order_id' => $renewal_order->get_id() ) );
			$renewal_order->update_status( 'failed', __( 'Subscription renewal failed: WooCommerce Subscriptions is not active.', 'woocommerce-payment-module' ) );
			return;
		}

		// Get the subscriptions linked to this renewal order.
		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$this->log_debug(
			'Subscription renewal: linked subscriptions loaded.',
			array(
				'order_id'           => $renewal_order->get_id(),
				'subscription_count' => count( $subscriptions ),
			)
		);

		if ( empty( $subscriptions ) ) {
			$this->log_warning( 'Subscription renewal failed: no linked subscription found.', array( 'order_id' => $renewal_order->get_id() ) );
			$renewal_order->update_status( 'failed', __( 'Subscription renewal failed: no linked subscription found.', 'woocommerce-payment-module' ) );
			return;
		}

		// Use the first linked subscription (renewals typically map 1:1, but WCS allows grouped renewals).
		$subscription       = reset( $subscriptions );
		$parent_xref        = $subscription->get_transaction_id();
		$transaction_unique = uniqid( 'sub-renewal-', true );

		$this->log_debug(
			'Subscription renewal: selected subscription and generated transaction unique.',
			array(
				'order_id'           => $renewal_order->get_id(),
				'subscription_id'    => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
				'parent_xref'        => ! empty( $parent_xref ) ? $parent_xref : 'MISSING',
				'transaction_unique' => $transaction_unique,
			)
		);

		if ( empty( $parent_xref ) ) {
			$this->log_error(
				'Subscription renewal failed: subscription has no stored xref. The original payment may not have been recorded correctly.',
				array(
					'order_id'        => $renewal_order->get_id(),
					'subscription_id' => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
				)
			);
			$renewal_order->update_status( 'failed', __( 'Subscription renewal failed: no original transaction reference found.', 'woocommerce-payment-module' ) );
			$subscription->add_order_note(
				sprintf(
					/* translators: %s: renewal order number. */
					__( 'Renewal order #%s failed: no original transaction reference (xref) is stored on this subscription. Please check that the initial payment completed successfully.', 'woocommerce-payment-module' ),
					$renewal_order->get_order_number()
				)
			);
			return;
		}

		// Convert amount to smallest currency unit.
		$currency_exponent = apply_filters( 'wc_payment_module_currency_exponent', 2, $renewal_order->get_currency() );
		$amount_minor      = (int) round( $amount_to_charge * ( 10 ** $currency_exponent ) );

		$this->log_debug(
			'Subscription renewal amount converted to minor units.',
			array(
				'order_id'          => $renewal_order->get_id(),
				'amount_minor'      => $amount_minor,
				'currency_exponent' => $currency_exponent,
			)
		);

		// Build the CA (Continuous Authority) transaction.
		$transaction = new Transaction(
			array(
				'transaction_type'  => 'CA',
				'xref'              => $parent_xref,
				'amount'            => $amount_minor,
				'rtAgreementType'   => 'recurring',
				'countryCode'       => Plugin_Config::get_country_code(),
				'currencyCode'      => $renewal_order->get_currency(),
				'transactionUnique' => $transaction_unique,
				'orderRef'          => $renewal_order->get_id(),
			)
		);

		$this->log_info(
			'Subscription renewal transaction prepared.',
			array(
				'order_id'           => $renewal_order->get_id(),
				'subscription_id'    => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
				'parent_xref'        => $parent_xref,
				'transaction_unique' => $transaction_unique,
			)
		);

		try {
			$transaction = $payment_method->process( $transaction );
		} catch ( \Exception $e ) {
			$this->log_error(
				sprintf( 'Subscription renewal exception for order #%s: %s', $renewal_order->get_id(), $e->getMessage() ),
				array(
					'order_id'           => $renewal_order->get_id(),
					'transaction_unique' => $transaction_unique,
				)
			);
			$renewal_order->update_status(
				'failed',
				sprintf(
					/* translators: %s: exception message. */
					__( 'Subscription renewal failed: %s', 'woocommerce-payment-module' ),
					$e->getMessage()
				)
			);
			$subscription->add_order_note(
				sprintf(
					/* translators: %1$s: renewal order number, %2$s: error message. */
					__( 'Renewal order #%1$s failed: %2$s', 'woocommerce-payment-module' ),
					$renewal_order->get_order_number(),
					$e->getMessage()
				)
			);
			return;
		}

		// Order notes data.
		$response_code    = $transaction->responseCode ?? '';
		$response_message = $transaction->responseMessage ?? '';
		$tx_unique        = $transaction->transactionUnique ?? '';
		$xref             = $transaction->xref ?? '';

		$this->log_debug(
			'Subscription renewal gateway response received.',
			array(
				'order_id'           => $renewal_order->get_id(),
				'transaction_status' => $transaction->status(),
				'response_code'      => $response_code,
				'transaction_unique' => $tx_unique,
				'xref_present'       => '' !== (string) $xref,
			)
		);

		// Handle the transaction outcome.
		if ( 'captured' === $transaction->status() || 'accepted' === $transaction->status() ) {

			$order_notes  = ucwords( $this->method_title ) . " - Subscription renewal payment successful.\n";
			$order_notes .= 'Response Code: ' . esc_html( $response_code ) . "\n";
			$order_notes .= 'Response Message: ' . esc_html( $response_message ) . "\n";
			$order_notes .= 'Transaction Unique: ' . esc_html( $tx_unique );

			$renewal_order->add_order_note( $order_notes );
			$renewal_order->payment_complete( $xref );

			if ( $xref ) {
				$subscription->set_transaction_id( $xref );
				$subscription->save();
				$this->log_debug(
					'Subscription transaction ID updated after successful renewal.',
					array(
						'order_id'        => $renewal_order->get_id(),
						'subscription_id' => method_exists( $subscription, 'get_id' ) ? $subscription->get_id() : 0,
						'xref'            => $xref,
					)
				);
			}

			$this->log_info(
				'Subscription renewal payment successful.',
				array(
					'order_id'           => $renewal_order->get_id(),
					'transaction_unique' => $tx_unique,
					'xref'               => $xref,
				)
			);

			$subscription->add_order_note(
				sprintf(
					/* translators: %1$s: renewal order number, %2$s: gateway xref. */
					__( 'Renewal order #%1$s paid successfully (xref: %2$s).', 'woocommerce-payment-module' ),
					$renewal_order->get_order_number(),
					$xref
				)
			);

		} else {
			$this->log_warning(
				sprintf( 'Subscription renewal declined for order #%s: %s %s', $renewal_order->get_id(), $response_code, $response_message ),
				array(
					'order_id'           => $renewal_order->get_id(),
					'transaction_status' => $transaction->status(),
					'response_code'      => $response_code,
					'transaction_unique' => $tx_unique,
					'xref_present'       => '' !== (string) $xref,
				)
			);

			$order_notes  = ucwords( $this->method_title ) . " - Subscription renewal payment failed.\n";
			$order_notes .= 'Response Code: ' . esc_html( $response_code ) . "\n";
			$order_notes .= 'Response Message: ' . esc_html( $response_message ) . "\n";
			$order_notes .= 'Transaction Unique: ' . esc_html( $tx_unique );

			$renewal_order->set_transaction_id( $xref );
			$renewal_order->update_status( 'failed', $order_notes );

			$subscription->add_order_note(
				sprintf(
					/* translators: %1$s: renewal order number, %2$s: response code, %3$s: response message. */
					__( 'Renewal order #%1$s failed. Response Code: %2$s, Response Message: %3$s', 'woocommerce-payment-module' ),
					$renewal_order->get_order_number(),
					$response_code,
					$response_message
				)
			);

		}
	}
}
