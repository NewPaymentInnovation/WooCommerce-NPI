<?php
/**
 * Card Payment Method.
 *
 * Handles card payment transaction processing.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway\PaymentMethods
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method_Base;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method as Payment_Method_Interface;

/**
 * Card Payment Method Class.
 *
 * Processes card transactions including sales, refunds, and 3DS continuations.
 */
class Card extends Payment_Method_Base implements Payment_Method_Interface {

	/**
	 * Get request field mappings for various card transaction types.
	 *
	 * @return array Request field mappings.
	 */
	protected function get_request_maps_definition(): array {
		return array(
			'ECOM_SALE'             => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{SALE}',
				'amount'          => 'required',
				'type'            => '{1}|valid(1)',
				'remoteAddress'   => 'required',
				'customerName'    => 'required',
				'customerAddress' => 'required',
				'rtAgreementType' => 'valid(cardonfile,recurring)',
				'countryCode'     => 'required',
				'saveCard'        => 'valid(Y,N)',
			),
			'ECOM_VERIFY'           => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{VERIFY}',
				'type'            => '{1}',
				'amount'          => '{0}',
				'paymentToken'    => 'optional',
				'rtAgreementType' => 'valid(recurring,cardonfile)',
			),
			'THREE_DS_CONTINUATION' => array(
				'threeDSRef'      => 'required',
				'threeDSResponse' => 'required',
			),
			'CA'                    => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{SALE}',
				'type'            => '{9}',
				'xref'            => 'optional',
				'amount'          => 'required',
				'rtAgreementType' => 'required|valid(recurring)',
			),
			'REFUND_SALE'           => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{REFUND_SALE}',
				'type'       => '{2}',
				'amount'     => 'required',
			),
			'QUERY'                 => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{QUERY}',
				'xref'       => 'required',
			),
			'CAPTURE'               => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{CAPTURE}',
				'xref'       => 'required',
				'amount'     => 'required',
			),
			'CANCEL'                => array(
				'merchantID' => 'options(merchantID)',
				'action'     => '{CANCEL}',
				'xref'       => 'required',
			),
		);
	}
}
