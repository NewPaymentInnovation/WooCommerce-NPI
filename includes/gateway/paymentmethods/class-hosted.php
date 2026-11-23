<?php
/**
 * Hosted Payment Method.
 *
 * Handles hosted payment page transaction processing.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway\PaymentMethods
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method_Base;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method as Payment_Method_Interface;

/**
 * Hosted Payment Method Class.
 *
 * Processes hosted payment page transactions and builds hosted request fields.
 */
class Hosted extends Payment_Method_Base implements Payment_Method_Interface {

	/**
	 * Get request field mappings for various hosted transaction types.
	 *
	 * @return array Request field mappings.
	 */
	protected function get_request_maps_definition(): array {
		return array(
			'ECOM_SALE'   => array(
				'merchantID'   => 'options(merchantID)',
				'action'       => '{SALE}',
				'amount'       => 'required',
				'redirectURL'  => 'required',
				'cardStore'    => 'optional',
				'currencyCode' => 'required',
			),
			'ECOM_VERIFY' => array(
				'merchantID'   => 'options(merchantID)',
				'action'       => '{VERIFY}',
				'type'         => '{1}',
				'amount'       => '{0}',
				'redirectURL'  => 'required',
				'cardStore'    => 'optional',
				'currencyCode' => 'required',
			),
			'REFUND_SALE' => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{REFUND_SALE}',
				'type'       => '{2}',
				'amount'     => 'required',
			),
			'QUERY'       => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{QUERY}',
				'xref'       => 'required',
			),
			'CAPTURE'     => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{CAPTURE}',
				'xref'       => 'required',
				'amount'     => 'required',
			),
			'CANCEL'      => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{CANCEL}',
				'xref'       => 'required',
			),
			'CA'          => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{SALE}',
				'type'            => '{9}',
				'xref'            => 'optional',
				'amount'          => 'required',
				'rtAgreementType' => 'required|valid(recurring)',
			),
		);
	}
	/**
	 * Override ECOM SALE action to return the request fields.
	 *
	 * @param Transaction $transaction The transaction object.
	 * @return Transaction The transaction with hosted request fields populated.
	 */
	public function ecom_sale( $transaction ): Transaction {
		$hosted_request_fields            = $this->build_request( $transaction );
		$transaction->hostedRequestFields = $hosted_request_fields;

		return $transaction;
	}

	/**
	 * Override ECOM VERIFY action to return the request fields.
	 *
	 * @param Transaction $transaction The transaction object.
	 * @return Transaction The transaction with hosted request fields populated.
	 */
	public function ecom_verify( $transaction ): Transaction {
		$hosted_request_fields            = $this->build_request( $transaction );
		$transaction->hostedRequestFields = $hosted_request_fields;

		return $transaction;
	}

	/**
	 * Process hosted payment response.
	 *
	 * @param Transaction $transaction The transaction object.
	 * @param array       $response    The gateway response.
	 * @return Transaction The parsed transaction.
	 */
	public function hostedResponse( Transaction $transaction, array $response ): Transaction {
		$transaction->verify_signature( $response, $this->options['merchant_secret'] );
		$transaction->parse_gateway_response( $response );
		return $transaction;
	}
}
