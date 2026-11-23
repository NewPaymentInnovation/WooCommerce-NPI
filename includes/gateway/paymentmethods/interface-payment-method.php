<?php
/**
 * Payment method interface.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;

/**
 * Payment_Method_Interface.
 *
 * Defines the contract for all payment method implementations.
 */
interface Payment_Method {

	/**
	 * Process a transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction
	 */
	public function process( Transaction $transaction ): Transaction;

	/**
	 * Build the gateway request fields for a transaction.
	 *
	 * @param Transaction $transaction The transaction to build the request for.
	 * @return array
	 */
	public function build_request( Transaction $transaction ): array;

	/**
	 * Get the request maps for this payment method.
	 *
	 * @return array
	 */
	public function get_request_maps(): array;
}
