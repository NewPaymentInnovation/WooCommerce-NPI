<?php
/**
 * Template: Hosted payment iframe.
 *
 * @package PaymentModule
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'paymentmodule_field_to_html' ) ) {
	/**
	 * Convert a field name and value to hidden input HTML.
	 *
	 * @param string $name  The field name.
	 * @param mixed  $value The field value (string or array).
	 * @return string
	 */
	function paymentmodule_field_to_html( $name, $value ) {
		$ret = '';

		if ( is_array( $value ) ) {
			foreach ( $value as $n => $v ) {
				$ret .= paymentmodule_field_to_html( $name . '[' . $n . ']', $v );
			}
		} else {
			// Convert all applicable characters or non-printable characters to HTML entities.
			$value = preg_replace_callback(
				'/[\x00-\x1f]/',
				function ( $matches ) {
					return '&#' . ord( $matches[0] ) . ';';
				},
				htmlentities( $value, ENT_COMPAT, 'UTF-8', true )
			);
			$ret   = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}

		return $ret;
	}
}
?>
<p><?php echo esc_html( $args['title'] ); ?></p>
<form method="POST" target="hosted_iframe" name="hosted_form_request" id="hosted_form_request" action="<?php echo esc_url( $args['gateway_url'] ); ?>">
	<?php
	foreach ( $args['request_fields'] as $request_field => $value ) {
		echo paymentmodule_field_to_html( $request_field, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	}
	?>
</form>
<iframe name="hosted_iframe" id="hosted_iframe" allow="payment *" style="width: 100%; max-width: 600px; height: 1500px; margin: 1px 10px; display: block; border: none;"></iframe>
