<?php
/**
 * Request-scoped support for redacted Apple Pay shipping postcodes.
 *
 * @package PaymentModule
 */

namespace PaymentNetwork\PaymentModule\Includes\Helpers;

class Apple_Pay_Shipping_Quote {

	/**
	 * Filters installed for each REST request.
	 *
	 * @var array
	 */
	private static $request_filters = array();

	/**
	 * Allow incomplete postcodes only while calculating an Apple Pay quote.
	 *
	 * @param mixed            $response Existing response.
	 * @param array            $handler Route handler.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public static function before_callbacks( $response, $handler, $request ) {
		$routes = array(
			'/wc/store/v1/cart'                      => 'GET',
			'/wc/store/v1/cart/update-customer'      => 'POST',
			'/wc/store/v1/cart/select-shipping-rate' => 'POST',
		);
		$route  = $request->get_route();

		if ( null !== $response || ! isset( $routes[ $route ] ) || $routes[ $route ] !== $request->get_method()
			|| '1' !== $request->get_header( 'X-Payment-Module-Apple-Pay-Quote' )
			|| isset( $request['billing_address'] ) ) {
			return $response;
		}

		$shipping        = $request['shipping_address'];
		$checking_format = false;
		$validate        = static function ( $valid, $postcode, $country ) use ( $shipping, &$checking_format ) {
			if ( ! $checking_format && is_array( $shipping )
				&& isset( $shipping['postcode'], $shipping['country'] )
				&& wc_strtoupper( $shipping['country'] ) === $country
				&& wc_normalize_postcode( $shipping['postcode'] ) === wc_normalize_postcode( $postcode ) ) {
				return true;
			}
			return $valid;
		};
		$format          = static function ( $formatted, $country ) use ( &$checking_format ) {
			// Full-postcode formatting can split a redacted prefix, e.g. SW1A into S W1A.
			$checking_format = true;
			try {
				return \WC_Validation::is_postcode( $formatted, $country ) ? $formatted : wc_normalize_postcode( $formatted );
			} finally {
				$checking_format = false;
			}
		};

		self::$request_filters[ spl_object_hash( $request ) ] = array( $validate, $format );
		add_filter( 'woocommerce_validate_postcode', $validate, 10, 3 );
		add_filter( 'woocommerce_format_postcode', $format, 10, 2 );

		return $response;
	}

	/**
	 * Restore normal validation before any subsequent REST request.
	 *
	 * @param mixed            $response REST response.
	 * @param array            $handler Route handler.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public static function after_callbacks( $response, $handler, $request ) {
		$key = spl_object_hash( $request );
		if ( isset( self::$request_filters[ $key ] ) ) {
			list( $validate, $format ) = self::$request_filters[ $key ];
			remove_filter( 'woocommerce_validate_postcode', $validate, 10 );
			remove_filter( 'woocommerce_format_postcode', $format, 10 );
			unset( self::$request_filters[ $key ] );
		}
		return $response;
	}
}
