<?php
/**
 * Order refund trait.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Traits;

use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method;
use PaymentNetwork\PaymentModule\Includes\Traits\Logger;

trait Order_Refund {

	use Logger;

	/**
	 * Process Refund
	 *
	 * Refunds a settled transactions or cancels
	 * one not yet settled.
	 *
	 * @param Payment_Method $payment_method Gateway payment method used to process the refund.
	 * @param int|null       $order_id       Order ID being refunded.
	 * @param float|null     $amount         Refund amount in major currency units.
	 * @param string|null    $reason         Refund reason provided by the merchant.
	 */
	public function refund_order_payment( Payment_Method $payment_method, ?int $order_id, ?float $amount = null, ?string $reason = '' ) {
		// Get the transaction XREF from the order ID and the amount.
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log_error( 'Refund failed: order not found.', array( 'order_id' => $order_id ) );
			return new \WP_Error( 'error', __( 'Refund failed.', 'woocommerce' ) );
		}

		$transaction_xref = $order->get_transaction_id();

		$this->log_debug(
			'Starting refund request.',
			array(
				'order_id'         => $order_id,
				'xref'             => $transaction_xref,
				'amount_requested' => $amount,
				'reason'           => $reason,
			)
		);

		// Check the order can be refunded.
		if ( ! $this->can_refund_order( $order ) ) {
			$this->log_warning(
				'Refund denied: order is not refundable.',
				array(
					'order_id' => $order_id,
					'xref'     => $transaction_xref,
				)
			);
			return new \WP_Error( 'error', __( 'Refund failed.', 'woocommerce' ) );
		}

		$query_transaction = new Transaction(
			array(
				'transaction_type' => 'QUERY',
				'xref'             => $transaction_xref,
			)
		);

		$query_transaction = $payment_method->process( $query_transaction );

		$this->log_debug(
			'Processed refund query transaction.',
			array(
				'order_id'      => $order_id,
				'xref'          => $transaction_xref,
				'state'         => $query_transaction->fields['state'] ?? null,
				'response_code' => $query_transaction->fields['responseCode'] ?? null,
			)
		);

		if ( empty( $query_transaction->fields['state'] ) ) {
			$this->log_error(
				'Refund failed: missing transaction state from query.',
				array(
					'order_id' => $order_id,
					'xref'     => $transaction_xref,
				)
			);
			return new \WP_Error( 'error', "Could not get the transaction state for {$transaction_xref}" );
		}

		if ( 65558 === (int) $query_transaction->fields['responseCode'] || 65559 === (int) $query_transaction->fields['responseCode'] ) {
			$this->log_error(
				'Refund failed: gateway blocked request (IP blocked).',
				array(
					'order_id' => $order_id,
					'xref'     => $transaction_xref,
				)
			);
			return new \WP_Error( 'error', 'IP blocked' );
		}

		// Convert amount to smallest currency unit using the currency exponent.
		$currency_exponent = (int) ( $query_transaction->fields['currencyExponent'] ?? 2 );
		$amount_to_refund  = (int) round( $amount * pow( 10, $currency_exponent ) );

		$this->log_debug(
			'Calculated refund amount in minor units.',
			array(
				'order_id'          => $order_id,
				'xref'              => $transaction_xref,
				'currency_exponent' => $currency_exponent,
				'amount_to_refund'  => $amount_to_refund,
				'amount_received'   => $query_transaction->amountReceived ?? null,
			)
		);

		// Build the refund request based on the transaction state.
		$refund_transaction = new Transaction(
			array(
				'xref' => $transaction_xref,
			)
		);

		switch ( $query_transaction->status() ) {
			case 'approved':
			case 'captured':
			case 'verified':
				// If amount to refund is equal to the total amount captured/approved then action is cancel.
				if ( $query_transaction->amountReceived === $amount_to_refund || ( $query_transaction->amountReceived - $amount_to_refund <= 0 ) ) {
					$refund_transaction->transaction_type = 'CANCEL';
				} else {
					$refund_transaction->transaction_type = 'CAPTURE';
					$refund_transaction->amount           = ( $query_transaction->amountReceived - $amount_to_refund );
				}
				$this->log_debug(
					'Refund action selected for approved/captured/verified transaction.',
					array(
						'order_id'         => $order_id,
						'xref'             => $transaction_xref,
						'query_state'      => $query_transaction->status(),
						'transaction_type' => $refund_transaction->transaction_type,
						'amount'           => $refund_transaction->amount ?? null,
					)
				);
				break;

			case 'accepted':
				$refund_transaction->transaction_type = 'REFUND_SALE';
				$refund_transaction->amount           = $amount_to_refund;
				$this->log_debug(
					'Refund action selected for accepted transaction.',
					array(
						'order_id'         => $order_id,
						'xref'             => $transaction_xref,
						'query_state'      => $query_transaction->status(),
						'transaction_type' => $refund_transaction->transaction_type,
						'amount'           => $refund_transaction->amount,
					)
				);
				break;

			default:
				$this->log_warning(
					'Refund rejected: transaction is not in a refundable state.',
					array(
						'order_id'    => $order_id,
						'xref'        => $transaction_xref,
						'query_state' => $query_transaction->status(),
					)
				);
				return new \WP_Error( 'error', "Transaction {$transaction_xref} is not in a refundable state." );
		}

		// Process the refund transaction.
		$refund_response = $payment_method->process( $refund_transaction );

		$this->log_debug(
			'Processed refund transaction.',
			array(
				'order_id'         => $order_id,
				'xref'             => $transaction_xref,
				'transaction_type' => $refund_transaction->transaction_type ?? null,
				'amount'           => $refund_transaction->amount ?? null,
				'response_code'    => $refund_response->responseCode ?? null,
				'state'            => $refund_response->state ?? null,
				'action'           => $refund_response->action ?? null,
			)
		);

		// Handle the refund response.
		if ( $refund_response->responseCode ) {
			$this->log_error(
				'Refund failed: gateway returned non-zero response code.',
				array(
					'order_id'      => $order_id,
					'xref'          => $transaction_xref,
					'response_code' => $refund_response->responseCode,
				)
			);
			return new \WP_Error( 'error', "Could not refund {$transaction_xref}." );
		}

		$state = $refund_response->state ?? null;

		if ( '0' !== (string) $refund_response->responseCode ) {
			$order_message = 'Refund Unsuccessful<br/><br/>';
			if ( 'canceled' !== $state ) {
				$order_message .= 'Amount Attempted: ' . number_format( $amount_to_refund / pow( 10, $refund_response->currencyExponent ), $refund_response->currencyExponent ) . '<br/><br/>';
			}
			$order->add_order_note( $order_message );
			$this->log_error(
				'Refund unsuccessful after response validation.',
				array(
					'order_id'      => $order_id,
					'xref'          => $transaction_xref,
					'response_code' => $refund_response->responseCode,
					'state'         => $state,
				)
			);
			return new \WP_Error( 'error', "Refund unsuccessful for {$transaction_xref}." );
		}

		$order_message = 'Refund Successful<br/><br/>';
		$order_message .= 'XREF: ' . ( $refund_response->xref ?? 'N/A' ) . '<br/><br/>';
		$order_message .= 'Response Code: ' . ( $refund_response->responseCode ?? 'N/A' ) . '<br/><br/>';
		if ( 'canceled' !== $state ) {
			$order_message .= 'Amount Refunded: ' . number_format( $amount_to_refund / pow( 10, $refund_response->currencyExponent ), $refund_response->currencyExponent ) . '<br/><br/>';
		}
		$order->add_order_note( $order_message );

		$this->log_info(
			'Refund completed successfully.',
			array(
				'order_id'      => $order_id,
				'xref'          => $transaction_xref,
				'response_code' => $refund_response->responseCode,
				'state'         => $state,
				'amount'        => $amount_to_refund,
			)
		);

		return true;
	}
}
