<?php
/**
 * Payment Process Trait.
 *
 * This trait contains functions to process a payment result.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Traits;

use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;
use WC_Payment_Token_CC;

trait Payment_Process {

	use Logger;

	/**
	 * Process a successful captured payment.
	 *
	 * @param Transaction $transaction_response The gateway response transaction.
	 * @param \WC_Order   $order               The order to update.
	 * @param array|null  $options             Optional options.
	 * @return void
	 * @throws \Exception When payment success handling fails.
	 */
	public function process_payment_success( Transaction $transaction_response, \WC_Order $order, ?array $options = null ): void {
		try {

			$this->log_debug(
				'Processing successful payment.',
				array(
					'order_id'           => $order->get_id(),
					'order_total'        => $order->get_total(),
					'user_id'            => $order->get_user_id(),
					'transaction_unique' => $transaction_response->transactionUnique,
					'xref'               => $transaction_response->xref ?? null,
					'options'            => $options,
				)
			);

			// Guard against duplicate processing (e.g. replayed callbacks).
			if ( $order->is_paid() ) {
				$this->log_debug( 'Order already paid, skipping.', array( 'order_id' => $order->get_id() ) );
				return;
			}

			$user_ID = (int) $order->get_user_id();

			// Save card details as token if the user is logged in.
			if (
				$user_ID > 0 &&
				! empty( $transaction_response->fields['cardID'] ?? '' ) &&
				( $transaction_response->fields['cardStore'] ?? '' ) === 'Y'
			) {
				$this->log_debug(
					'Saving card token from successful payment.',
					array(
						'order_id' => $order->get_id(),
						'user_id'  => $user_ID,
					)
				);

				$token = new WC_Payment_Token_CC();
				$token->set_gateway_id( $this->id );
				$token->set_token( $transaction_response->cardID );
				$token->set_card_type( $transaction_response->cardScheme );
				$last4 = substr( preg_replace( '/\D/', '', (string) $transaction_response->cardNumberMask ), -4 );
				$token->set_last4( $last4 );
				$token->set_expiry_month( $transaction_response->cardExpiryMonth );
				$token->set_expiry_year( "20{$transaction_response->cardExpiryYear}" );
				$token->set_user_id( $user_ID );
				$token->save();
				$order->add_payment_token( $token );

				$this->log_debug(
					'Card token saved and linked to order.',
					array(
						'order_id'  => $order->get_id(),
						'user_id'   => $user_ID,
						'token_id'  => $token->get_id(),
						'card_type' => $transaction_response->cardScheme ?? null,
						'last4'     => $last4,
					)
				);
			} else {
				$this->log_debug(
					'Skipping card token save for successful payment.',
					array(
						'order_id'         => $order->get_id(),
						'user_id'          => $user_ID,
						'has_card_id'      => isset( $transaction_response->fields['cardID'] ) && ! empty( $transaction_response->fields['cardID'] ),
						'card_store_value' => $transaction_response->fields['cardStore'] ?? null,
					)
				);
			}

			$order_notes  = ucwords( $this->method_title ) . ' - Payment completed.<br>';
			$order_notes .= 'Response Code: ' . esc_html( $transaction_response->responseCode ) . '<br>';
			$order_notes .= 'Response Message: ' . esc_html( $transaction_response->responseMessage ) . '<br>';
			$order_notes .= 'Transaction Unique: ' . esc_html( $transaction_response->transactionUnique );

			$order->set_transaction_id( $transaction_response->xref );
			$order->add_order_note( $order_notes );
			$order->payment_complete();

			// Link the XREF to the subscription AFTER payment_complete() so that
			// any WCS internal saves triggered by the payment_complete hook (e.g.
			// activating the subscription) fire first and don't overwrite our value.
			if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				$subscriptions = wcs_get_subscriptions_for_order( $order );
				$this->log_debug(
					'Linking xref to related subscriptions.',
					array(
						'order_id'           => $order->get_id(),
						'subscription_count' => is_array( $subscriptions ) ? count( $subscriptions ) : 0,
						'xref'               => $transaction_response->xref ?? null,
					)
				);

				foreach ( $subscriptions as $subscription ) {
					$subscription->set_transaction_id( $transaction_response->xref );
					$subscription->save();
					$this->log_debug(
						'Linked xref to subscription.',
						array(
							'subscription_id' => $subscription->get_id(),
							'xref'            => $transaction_response->xref,
						)
					);
				}
			} else {
				$this->log_debug(
					'WooCommerce Subscriptions not available; skipping subscription xref linking.',
					array( 'order_id' => $order->get_id() )
				);
			}

			$this->log_debug(
				'Payment completed successfully.',
				array(
					'order_id' => $order->get_id(),
					'xref'     => $transaction_response->xref,
				)
			);

		} catch ( \Exception $th ) {
			$exception_message = sanitize_text_field( $th->getMessage() );
			$this->log_error(
				'Error processing successful payment.',
				array(
					'order_id' => $order->get_id(),
					'message'  => $exception_message,
					'file'     => $th->getFile(),
					'line'     => $th->getLine(),
				)
			);
			throw new \Exception( 'Error processing successful payment.' );
		}
	}

	/**
	 * Process a failed payment.
	 *
	 * @param Transaction $transaction_response The gateway response transaction.
	 * @param \WC_Order   $order               The order to update.
	 * @param array|null  $options             Optional options.
	 * @return void
	 * @throws \Exception When failed-payment handling fails.
	 */
	public function process_payment_failed( Transaction $transaction_response, \WC_Order $order, ?array $options = null ): void {
		try {

			$this->log_debug(
				'Processing failed payment.',
				array(
					'order_id'           => $order->get_id(),
					'transaction_unique' => $transaction_response->transactionUnique,
					'response_code'      => $transaction_response->responseCode,
					'response_message'   => $transaction_response->responseMessage,
					'xref'               => $transaction_response->xref ?? null,
					'options'            => $options,
				)
			);

			$order_notes  = ucwords( $this->method_title ) . ' - Payment failed.<br>';
			$order_notes .= 'Response Code: ' . esc_html( $transaction_response->responseCode ) . '<br>';
			$order_notes .= 'Response Message: ' . esc_html( $transaction_response->responseMessage ) . '<br>';
			$order_notes .= 'Transaction Unique: ' . esc_html( $transaction_response->transactionUnique );

			$order->set_transaction_id( $transaction_response->xref );
			$order->add_order_note( $order_notes );
			$order->update_status( 'failed' );

			$this->log_debug(
				'Order status set to failed.',
				array(
					'order_id' => $order->get_id(),
					'xref'     => $transaction_response->xref ?? null,
				)
			);

		} catch ( \Exception $th ) {
			$exception_message = sanitize_text_field( $th->getMessage() );
			$this->log_error(
				'Error processing failed payment.',
				array(
					'order_id' => $order->get_id(),
					'message'  => $exception_message,
					'file'     => $th->getFile(),
					'line'     => $th->getLine(),
				)
			);
			throw new \Exception( 'Error processing failed payment.' );
		}
	}
}
