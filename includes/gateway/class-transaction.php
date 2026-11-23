<?php
/**
 * Gateway Transaction Model.
 *
 * Handles transaction data, field management, and request/response signing.
 *
 * @package PaymentModule
 * @subpackage Includes\Gateway
 */

namespace PaymentNetwork\PaymentModule\Includes\Gateway;

/**
 * Transaction Class.
 *
 * Manages transaction data with dynamic property handling and cryptographic signing.
 */
class Transaction {

	/**
	 * Transaction state.
	 *
	 * @var string
	 */
	protected string $transaction_state = '';

	/**
	 * Transaction type.
	 *
	 * @var string
	 */
	protected string $transaction_type = '';

	/**
	 * Transaction fields.
	 *
	 * @var array
	 */
	protected array $fields;

	/**
	 * Constructor.
	 *
	 * @param array $fields Transaction field values.
	 */
	public function __construct( array $fields ) {
		foreach ( $fields as $field_name => $value ) {
			$this->{$field_name} = $value;
		}
	}

	/**
	 * Parse gateway response and merge fields.
	 *
	 * @param array $gateway_response Gateway response fields.
	 * @return bool True on successful parsing.
	 */
	public function parse_gateway_response( array $gateway_response ): bool {
		// For each field, set transaction prop with value.
		foreach ( $gateway_response as $field_name => $value ) {
			$this->{$field_name} = $value;
		}

		$this->set_status( (string) ( $this->state ?? 'finished' ) );

		return true;
	}

	/**
	 * Set the transaction status.
	 *
	 * @param string|null $transaction_states The state to set.
	 * @return void
	 */
	public function set_status( ?string $transaction_states ): void {
		if ( ! empty( $transaction_states ) ) {
			$this->transaction_state = $transaction_states;
		}
	}

	/**
	 * Get the current transaction status.
	 *
	 * @return string|false The current state, or false if not yet set.
	 */
	public function status() {
		return ! empty( $this->transaction_state ) ? $this->transaction_state : false;
	}

	/**
	 * Magic getter for dynamic property access.
	 *
	 * @param string $property Property name.
	 * @return mixed Property value or null.
	 */
	public function __get( $property ) {
		if ( property_exists( $this, $property ) ) {
			return $this->{$property};
		} else {
			return $this->fields[ $property ] ?? null;
		}
	}

	/**
	 * Magic setter for dynamic property assignment.
	 *
	 * @param string $property Property name.
	 * @param mixed  $value    Property value.
	 */
	public function __set( $property, $value ) {
		if ( property_exists( $this, $property ) ) {
			$this->{$property} = $value;
		} else {
			$this->fields[ $property ] = $value;
		}
	}

	/**
	 * Verify request signature using merchant secret.
	 *
	 * @param Transaction|array $transaction       Transaction object or array.
	 * @param string            $merchant_secret    Merchant secret for signature verification.
	 * @return bool True if signature is valid.
	 * @throws \Exception If signature verification fails.
	 */
	public function verify_signature( $transaction, string $merchant_secret ): bool {
		$transaction_data = $transaction instanceof self
			? ( $transaction->fields ?? array() )
			: $transaction;

		if ( ! is_array( $transaction_data ) ) {
			return false;
		}

		$provided_signature = $transaction_data['signature'] ?? null;
		if ( ! is_string( $provided_signature ) || '' === $provided_signature ) {
			return false;
		}

		// Gateways sometimes include trailing whitespace/newlines on raw responses.
		$provided_signature = trim( $provided_signature );
		if ( '' === $provided_signature ) {
			return false;
		}

		// Store the provided signature for debugging/inspection.
		$this->signature = $provided_signature;

		unset( $transaction_data['signature'] );

		$signed_data        = $this->sign_request( $transaction_data, $merchant_secret );
		$expected_signature = $signed_data['signature'] ?? null;

		$verified = is_string( $expected_signature ) && hash_equals( $expected_signature, $provided_signature );

		if ( $verified ) {
			return true;
		}

		$provided_prefix = substr( $provided_signature, 0, 12 );
		$expected_prefix = is_string( $expected_signature ) ? substr( $expected_signature, 0, 12 ) : 'missing';

		throw new \Exception( 'Signature verification failed. Provided signature prefix: ' . esc_html( $provided_prefix ) . '. Expected signature prefix: ' . esc_html( $expected_prefix ) . '.' );
	}

	/**
	 * Sign request data using merchant secret.
	 *
	 * @param array  $data             Data to sign.
	 * @param string $merchant_secret   Merchant secret for signing.
	 * @return array Data with signature added.
	 */
	public function sign_request( array $data, string $merchant_secret ): array {
		// Sort the data in ascending ASCII key order.
		ksort( $data );

		// Convert to a URL encoded string.
		$url_encoded_string = http_build_query( $data, '', '&' );

		// Normalize all line endings (CRLF|NLCR|NL|CR) to just NL (%0A).
		$url_encoded_string = preg_replace( '/%0D%0A|%0A%0D|%0D/i', '%0A', $url_encoded_string );

		// Hash the string and secret together.
		$signature = hash( 'SHA512', $url_encoded_string . $merchant_secret );

		$data['signature'] = $signature;

		return $data;
	}
}
