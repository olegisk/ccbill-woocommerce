<?php
/** @var string $action */
/** @var array $fields */
/** @var WC_Order $order */
/** @var WC_Payment_Gateway $gateway */
/** @var string $loader */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly
?>
<div class="ccbill-overlay">
    <img alt="Loader" src="<?php echo esc_attr( $loader ); ?>">
</div>

<form action="<?php echo esc_url( $action ); ?>" method="GET" id="ccbill_form" style="display: none;">
	<?php foreach ( $fields as $key => $value ): ?>
        <input type="hidden" name="<?php echo esc_html( $key ); ?>"
               value="<?php echo esc_html( is_array( $value ) ? json_encode( $value ) : $value ); ?>">
	<?php endforeach; ?>
</form>

<script>
    window.onload = function () {
        document.getElementById( 'ccbill_form' ).submit();
    };
</script>
