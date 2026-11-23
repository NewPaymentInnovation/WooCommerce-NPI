<?php
/**
 * Template: Post message to parent window.
 *
 * @package PaymentModule
 *
 * @var array $args Template arguments.
 */

defined( 'ABSPATH' ) || exit;
?>
<script>top.postMessage(<?php echo wp_json_encode( $args['message'] ); ?>, window.location.origin);</script>
