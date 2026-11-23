<?php
/**
 * Apple Pay Payment Method.
 *
 * Handles Apple Pay transaction processing and merchant validation.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway\PaymentMethods
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method_Base;
use PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods\Payment_Method as Payment_Method_Interface;

/**
 * Apple Pay Payment Method Class.
 *
 * Processes Apple Pay transactions and validates merchant sessions.
 */
class Apple_Pay extends Payment_Method_Base implements Payment_Method_Interface {

	/**
	 * Get request field mappings for transaction types.
	 *
	 * @return array Request field mappings.
	 */
	protected function get_request_maps_definition(): array {
		return array(
			'ECOM_SALE'   => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{SALE}',
				'amount'          => 'required',
				'type'            => '{1}|valid(1)',
				'paymentToken'    => 'required',
				'paymentMethod'   => '{applepay}',
				'customerName'    => 'required',
				'customerAddress' => 'required',
				'rtAgreementType' => 'valid(cardonfile,recurring)',
				'countryCode'     => 'required',
			),
			'ECOM_VERIFY' => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{VERIFY}',
				'type'            => '{1}',
				'amount'          => '{0}',
				'paymentToken'    => 'required',
				'paymentMethod'   => '{applepay}',
				'rtAgreementType' => 'valid(recurring,cardonfile)',
			),
			'CA'          => array(
				'merchantID'      => 'options(merchantID)',
				'action'          => '{SALE}',
				'type'            => '{9}',
				'xref'            => 'optional',
				'amount'          => 'required',
				'rtAgreementType' => 'required|valid(recurring)',
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
		);
	}

	/**
	 * Validate Apple Pay merchant session.
	 *
	 * @param array $options Options containing validationURL, merchant_display_name, and domainName.
	 * @return array The validated merchant session response.
	 * @throws \Exception On validation failure.
	 */
	public function validateMerchantSession( array $options ): array {
		try {
			$request = array(
				'merchantID'    => $this->get_merchant_id(),
				'process'       => 'applepay.validateMerchant',
				'validationURL' => $options['validationURL'],
				'displayName'   => $options['merchant_display_name'],
				'domainName'    => $options['domainName'],
			);

			$this->transaction = new \PaymentNetwork\PaymentModule\Includes\Gateway\Transaction( array() );

			$request  = $this->transaction->sign_request( $request, $this->options['merchant_secret'] );
			$response = $this->send_gateway_request(
				$request,
				array(
					'path'         => 'hosted',
					'raw_response' => true,
				)
			);

			return json_decode( $response, true );
		} catch ( \Exception $e ) {
			throw $e;
		}
	}
}
