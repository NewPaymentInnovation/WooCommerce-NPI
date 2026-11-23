<?php
/**
 * Template: Hosted 3DS callback form.
 *
 * @package PaymentModule
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="POST" target="threedsiframe" name="three-ds-form" id="three-ds-form" action="<?php echo esc_url( $args['acs_url'] ); ?>">
	<?php
	foreach ( $args['three_ds_request'] as $request_field => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $request_field ) . '" value="' . esc_attr( $value ) . '">';
	}

	if ( ! empty( $args['auto_submit'] ) ) {
		echo '<script>document.getElementById("three-ds-form").submit();</script>';
	}
	?>
</form>
