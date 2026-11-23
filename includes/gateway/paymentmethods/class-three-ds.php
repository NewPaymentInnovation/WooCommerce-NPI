<?php
/**
 * 3DS Payment Method.
 *
 * Handles 3D Secure payment continuation.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway\PaymentMethods
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method_Base;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method as Payment_Method_Interface;

/**
 * ThreeDS Payment Method Class.
 *
 * Processes 3D Secure continuation transactions.
 */
class Three_DS extends Payment_Method_Base implements Payment_Method_Interface {

	/**
	 * Get request field mappings for 3DS transaction types.
	 *
	 * @return array Request field mappings.
	 */
	protected function get_request_maps_definition(): array {
		return array(
			'THREE_DS_CONTINUATION' => array(
				'threeDSRef'      => 'required',
				'threeDSResponse' => 'required',
			),
		);
	}
}
