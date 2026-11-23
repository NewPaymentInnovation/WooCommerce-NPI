<?php
/**
 * Payment Method Base Class.
 *
 * Abstract base class for payment method implementations.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway\PaymentMethods
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway\PaymentMethods;

use PaymentNetwork\PaymentModule\Includes\Gateway\Transaction;

/**
 * Payment_Method_Base Class.
 *
 * Provides common functionality for payment method implementations including
 * request building, gateway communication, and transaction processing.
 */
abstract class Payment_Method_Base {

	/**
	 * Payment method options.
	 *
	 * @var array
	 */
	protected array $options;

	/**
	 * Current transaction.
	 *
	 * @var Transaction
	 */
	protected Transaction $transaction;

	/**
	 * Get the request field mappings for this payment method.
	 *
	 * Each key is a transaction type string; the value is an associative array
	 * of field name to mapping token (e.g. 'required', '{SALE}', 'options(merchantID)').
	 *
	 * @return array Request field mappings keyed by transaction type name.
	 */
	abstract protected function get_request_maps_definition(): array;

	/**
	 * Constructor.
	 *
	 * @param array $options Payment method options.
	 * @throws \Exception If required payment method options are missing.
	 */
	public function __construct( array $options ) {
		$required_options = array(
			'merchantID',
			'merchant_secret',
			'gateway_hostname',
		);

		foreach ( $required_options as $required_option ) {
			if ( empty( $options[ $required_option ] ?? '' ) ) {
				throw new \Exception( esc_html( "Required payment method option missing: {$required_option}" ) );
			}
		}

		$this->options = $options;
	}

	/**
	 * Get merchant ID.
	 *
	 * @return string Merchant ID.
	 */
	public function get_merchant_id(): string {
		return (string) ( $this->options['merchantID'] ?? '' );
	}

	/**
	 * Get request field mappings.
	 *
	 * @return array Request mappings.
	 */
	public function get_request_maps(): array {
		return $this->get_request_maps_definition();
	}

	/**
	 * Process a transaction through the appropriate payment method handler.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 * @throws \Exception If transaction type is missing or handler not found.
	 */
	public function process( Transaction $transaction ): Transaction {
		$this->transaction = $transaction;

		try {
			if ( ! property_exists( $transaction, 'transaction_type' ) ) {
				throw new \Exception( esc_html( 'Missing payment type property' ) );
			}

			$transaction_type = (string) ( $transaction->transaction_type ?? '' );
			if ( '' === $transaction_type ) {
				throw new \Exception( esc_html( 'Missing payment type value' ) );
			}

			$function = $this->transaction_type_to_method_name( $transaction_type );

			// Check if method exists.
			if ( ! method_exists( $this, $function ) ) {
				throw new \Exception( esc_html( 'Payment type function does not exist' ) );
			}

			return $this->{$function}( $transaction );
		} catch ( \Exception $th ) {
			throw new \Exception( esc_html( $th->getMessage() ) );
		}
	}


	/**
	 * Build a request from a transaction object.
	 *
	 * @param Transaction $transaction The transaction object.
	 * @return array The signed request fields.
	 * @throws \Exception If transaction type mapping or required fields are missing.
	 */
	public function build_request( Transaction $transaction ): array {
		$request_fields   = array();
		$transaction_type = (string) ( $transaction->transaction_type ?? '' );

		if ( ! array_key_exists( $transaction_type, $this->get_request_maps_definition() ) ) {
			throw new \Exception( esc_html( 'Payment type has no request mapping for this payment type' ) );
		}

		$transaction_request_mapping = $this->get_request_maps_definition()[ $transaction_type ];

		// For each request mapping, explode the mapping value.
		foreach ( $transaction_request_mapping as $key => $value ) {

			foreach ( explode( '|', $value ) as $v ) {

				// If the request mapping has a static value use it.
				if (
					0 === strpos( $v, '{' )
					&& '}' === substr( $v, -1 )
					&& empty( $this->transaction->fields[ $key ] ?? '' )
				) {
					$request_fields[ $key ] = substr( $v, 1, -1 );
					// Break out the loop.
					break;
				}

				// If the request mapping is required check the payment has the required property
				// and it's not empty.
				if ( 'required' === $v ) {

					if ( ! isset( $this->transaction->fields[ $key ] ) ) {
						throw new \Exception( esc_html( "Payment property {$key} missing and required" ) );
					}

					if ( empty( $this->transaction->fields[ $key ] ) ) {
						throw new \Exception( esc_html( "The key {$key} requires a value" ) );
					}

					$request_fields[ $key ] = $this->transaction->fields[ $key ];
					// Break out the loop.
					break;
				}

				if (
					0 === strpos( $v, 'valid(' )
					&& ')' === substr( $v, -1 )
					&& isset( $this->transaction->fields[ $key ] )
				) {

					$valid        = false;
					$valid_values = substr( $v, 6, -1 );

					foreach ( explode( ',', $valid_values ) as $valid_value ) {
						if ( (string) $this->transaction->fields[ $key ] === (string) $valid_value ) {
							$valid = true;
						}
					}

					if ( ! $valid ) {
						throw new \Exception( esc_html( "The key {$key} is not a valid value" ) );
					}

					$request_fields[ $key ] = $this->transaction->fields[ $key ];
					// Break out the loop.
					break;
				}

				// If request mapping is option value.
				if ( 0 === strpos( $v, 'options(' ) && ')' === substr( $v, -1 ) ) {

					$option = substr( $v, 8, -1 );

					if ( array_key_exists( $option, $this->options ) && ! empty( $this->options[ $option ] ) ) {
						$request_fields[ $key ] = $this->options[ $option ];
					} else {
						throw new \Exception( esc_html( "Option {$option} not set" ) );
					}
					// Break out the loop.
					break;
				}
			}
		}

		$request_fields = array_merge( $this->transaction->fields, $request_fields );

		// Sign the request.
		$request_fields = $this->transaction->sign_request( $request_fields, $this->options['merchant_secret'] );

		return $request_fields;
	}

	/**
	 * Resolve a transaction type string to the local handler method name.
	 *
	 * @param string $transaction_type Transaction type key.
	 * @return string
	 */
	private function transaction_type_to_method_name( string $transaction_type ): string {
		switch ( $transaction_type ) {
			case 'ECOM_SALE':
				return 'ecom_sale';
			case 'ECOM_VERIFY':
				return 'ecom_verify';
			case 'THREE_DS_CONTINUATION':
				return 'three_ds_continuation';
			case 'REFUND_SALE':
				return 'refund_sale';
			case 'QUERY':
				return 'query';
			case 'CANCEL':
				return 'cancel';
			case 'CAPTURE':
				return 'capture';
			case 'CA':
				return 'ca';
			default:
				return strtolower( $transaction_type );
		}
	}

	/**
	 * Send a request to the gateway.
	 *
	 * @param array $request_fields The request fields.
	 * @param array $options        Optional request and response options.
	 * @return array|string Gateway response.
	 * @throws \Exception If the request fails.
	 */
	public function send_gateway_request( array $request_fields, array $options = array() ) {
		$path = isset( $options['path'] ) ? trim( (string) $options['path'], '/' ) : 'direct';
		$url  = 'https://' . $this->options['gateway_hostname'] . '/' . $path . '/';

		$response = wp_remote_post(
			$url,
			array(
				'body'        => $request_fields,
				'timeout'     => isset( $options['timeout'] ) ? (int) $options['timeout'] : 30,
				'redirection' => isset( $options['redirection'] ) ? (int) $options['redirection'] : 5,
				'sslverify'   => isset( $options['sslverify'] ) ? (bool) $options['sslverify'] : true,
				'headers'     => isset( $options['headers'] ) && is_array( $options['headers'] ) ? $options['headers'] : array(),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \Exception( 'Gateway request failed: ' . $response->get_error_message() );
		}

		$ret = wp_remote_retrieve_body( $response );

		if ( $options['raw_response'] ?? false ) {
			return $ret;
		}

		parse_str( $ret, $gateway_response );

		return $gateway_response;
	}



	/**
	 * Execute a standard gateway transaction: build, send, parse, and verify.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	private function execute_transaction( Transaction $transaction ): Transaction {
		$gateway_request  = $this->build_request( $transaction );
		$gateway_response = $this->send_gateway_request( $gateway_request );
		$transaction->parse_gateway_response( $gateway_response );
		$transaction->verify_signature( $gateway_response, $this->options['merchant_secret'] );

		return $transaction;
	}

	/**
	 * Handle ECOM SALE transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function ecom_sale( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle 3DS CONTINUATION transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function three_ds_continuation( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle ECOM VERIFY transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function ecom_verify( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle REFUND SALE transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function refund_sale( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle QUERY transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function query( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle CAPTURE transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function capture( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle CANCEL transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function cancel( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}

	/**
	 * Handle CA (Continuous Authority/Recurring) transaction.
	 *
	 * @param Transaction $transaction The transaction to process.
	 * @return Transaction The processed transaction.
	 */
	public function ca( Transaction $transaction ): Transaction {
		return $this->execute_transaction( $transaction );
	}
}
