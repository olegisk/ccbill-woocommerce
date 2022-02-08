<?php
/** @var WC_Payment_Gateway $gateway */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly
?>

<h2><?php esc_html( $gateway->get_method_title() ); ?></h2>
<?php wp_kses_post( wpautop( $gateway->get_method_description() ) ); ?>
<p>
	<?php
	echo sprintf(
		__( 'Please go to <a href="%s" target="_blank">%s</a> to configure merchant to accept payments.', 'ccbill' ),
		'Admin Portal',
		'https://admin.ccbill.com/loginMM.cgi'
	);
	?>
    <br>
    <strong>Approval URL:</strong>
    <?php echo esc_url( add_query_arg(
            'Action',
            'CheckoutSuccess',
            WC()->api_request_url( get_class( $gateway ) )
    ) ); ?>
    <br>
    <strong>Denial URL:</strong>
    <?php echo esc_url( add_query_arg(
            'Action',
            'CheckoutFailure',
            WC()->api_request_url( get_class( $gateway ) )
    ) ); ?>
    <br>
    <strong>Background Post</strong>
    <br>
    <strong>Approval Post URL:</strong>
    <?php echo esc_url( add_query_arg(
            'Action',
            'Approval_Post',
            WC()->api_request_url( get_class( $gateway ) )
    ) ); ?>
    <br>
    <strong>Denial Post URL:</strong>
    <?php echo esc_url( add_query_arg(
            'Action',
            'Denial_Post',
            WC()->api_request_url( get_class( $gateway ) )
    ) ); ?>
    <br>
    <strong>WebHook URL:</strong>
    <?php echo esc_url( WC()->api_request_url( get_class( $gateway ) ) ); ?>
</p>

<table class="form-table">
	<?php $gateway->generate_settings_html( $gateway->get_form_fields(), true ); ?>
</table>
