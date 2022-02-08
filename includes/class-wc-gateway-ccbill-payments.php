<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WC_Gateway_CCBill_Payments extends WC_Payment_Gateway {
	public $form_name = '';
	public $client_accnum = '';
	public $client_subacc = '';
	public $client_subacc_recur = '';
	public $flex_form_name = '';
	public $is_flex_form = 'no';
	public $salt = '';
	public $debug = 'no';
	public $data_link_username = '';
	public $data_link_password = '';
	public $data_link_test = 'no';

	/**
	 * Init
	 */
	public function __construct() {
		$this->id           = 'ccbill';
		$this->has_fields   = false;
		$this->method_title = __( 'CCBill (Credit Card)', 'ccbill' );
		$this->icon         = apply_filters(
			'woocommerce_ccbill_icon',
			plugins_url( '/assets/images/ccbill.svg', dirname( __FILE__ ) )
		);

		// Subscription Support
		$this->supports = array(
			'products',
			//'refunds',
			'subscriptions',
			'gateway_scheduled_payments',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			//'subscription_amount_changes',
			//'subscription_date_changes',
			//'subscription_payment_method_change',
			//'subscription_payment_method_change_customer',
			//'subscription_payment_method_change_admin',
			//'multiple_subscriptions',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->title               = isset( $this->settings['title'] ) ? $this->settings['title'] : '';
		$this->form_name           = isset( $this->settings['formname'] ) ? $this->settings['formname'] : '';
		$this->client_accnum       = isset( $this->settings['email'] ) ? $this->settings['email'] : '';
		$this->client_subacc       = isset( $this->settings['subacc'] ) ? $this->settings['subacc'] : '';
		$this->client_subacc_recur = isset( $this->settings['subaccrecurring'] ) ? $this->settings['subaccrecurring'] : '';
		$this->flex_form_name      = isset( $this->settings['flex_formname'] ) ? $this->settings['flex_formname'] : '';
		$this->is_flex_form        = isset( $this->settings['is_flexform'] ) ? $this->settings['is_flexform'] : 'no';
		$this->salt                = isset( $this->settings['saltencryption'] ) ? $this->settings['saltencryption'] : '';
		$this->debug               = isset( $this->settings['debug'] ) ? $this->settings['debug'] : 'no';
		$this->data_link_username  = isset( $this->settings['data_link_username'] ) ? $this->settings['data_link_username'] : $this->data_link_username;
		$this->data_link_password  = isset( $this->settings['data_link_password'] ) ? $this->settings['data_link_password'] : $this->data_link_password;
		$this->data_link_test      = isset( $this->settings['data_link_test'] ) ? $this->settings['data_link_test'] : $this->data_link_test;

		add_action( 'woocommerce_receipt_' . $this->id, array(
			$this,
			'receipt_page'
		) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array(
			$this,
			'ipn_handler'
		) );

		// WC Subscriptions 2.0+
		add_action( 'woocommerce_payment_complete', array( &$this, 'add_subscription_id' ), 10, 1 );

		add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array(
			$this,
			'update_failing_payment_method'
		), 10, 2 );

		add_action( 'wcs_resubscribe_order_created', array( $this, 'remove_resubscribe_order_meta' ), 10 );

		// Allow store managers to manually set card id as the payment method on a subscription
		add_filter( 'woocommerce_subscription_payment_meta', array(
			$this,
			'add_subscription_payment_meta'
		), 10, 2 );

		add_filter( 'woocommerce_subscription_validate_payment_meta', array(
			$this,
			'validate_subscription_payment_meta'
		), 10, 2 );

		// Allow Subscriptions status change
		add_filter( 'woocommerce_can_subscription_be_updated_to_active', array( $this, 'can_subscription_be_updated_to_active'), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_on-hold', array( $this, 'can_subscription_be_updated_to_on_hold'), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', array( $this, 'can_subscription_be_updated_to_cancelled'), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_expired', array( $this, 'can_subscription_be_updated_to_expired'), 10, 2 );
		add_filter( 'woocommerce_can_subscription_be_updated_to_pending-cancel', array( $this, 'can_subscription_be_updated_to_pending_cancel'), 10, 2 );
		

		// Disable Subscription actions on "My Account" page
		add_filter( 'wcs_view_subscription_actions', array( $this, 'view_subscription_actions'), 10, 2 );

		// Cancel with Data Link System
		add_action( 'woocommerce_customer_changed_subscription_to_cancelled', array( $this, 'customer_cancel_subscription' ), 10, 1 );

		// Cancel CCBill Subscription on Next Renewal
		//add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'maybe_cancel_subscription' ), 0, 1 );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'ccbill' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CCBill', 'ccbill' ),
				'default' => 'yes'
			),
			'title' => array(
				'title'       => __( 'Title', 'ccbill' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'ccbill' ),
				'default'     => __( 'CCBill - Pay with Credit Card', 'ccbill' )
			),
			'email'           => array(
				'title'   => __( 'Account #', 'ccbill' ),
				'type'    => 'text',
				'default' => ''
			),
			'subacc'          => array(
				'title'   => __( 'Sub Acct # (Non Recurring)', 'ccbill' ),
				'type'    => 'text',
				'default' => ''
			),
			'subaccrecurring' => array(
				'title'   => __( 'Sub Acct # (Recurring)', 'ccbill' ),
				'type'    => 'text',
				'default' => ''
			),
			'formname'        => array(
				'title'       => __( 'Form Name', 'ccbill' ),
				'type'        => 'text',
				'description' => __( 'Name of the CCBill form you would like to show.', 'ccbill' ),
				'default'     => ''
			),
			'flex_formname' => array(
				'title' => __( 'Flex Form ID', 'woocommerce' ),
				'type' => 'text',
				'description' => __( 'Name of the CCBill form you would like to show.', 'ccbill' ),
				'default' => ''
			),
			'is_flexform' => array(
				'title'       => __( 'Flex Form', 'ccbill' ),
				'type'        => 'checkbox',
				'label'       => __( 'Check this box if the form name provided is a CCBill FlexForm', 'ccbill' ),
				'default'     => 'no',
				'desc_tip'    => true,
				'description' => __( 'Check this box if the form name provided is a CCBill FlexForm', 'ccbill' ),
			),
			'saltencryption'  => array(
				'title'       => __( 'Salt Encryption', 'ccbill' ),
				'type'        => 'text',
				'description' => __( 'CCBill Salt Encryption key, found in your CCBill Dashboard.', 'ccbill' ),
				'default'     => ''
			),
			'debug' => array(
				'title'   => __( 'Debug', 'ccbill' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'ccbill' ),
				'default' => 'no'
			),
			'data_link_username'  => array(
				'title'       => __( 'Username of Data Link', 'ccbill' ),
				'type'        => 'text',
				'description' => __( 'Username of Data Link Extract System.', 'ccbill' ),
				'default'     => $this->data_link_username
			),
			'data_link_password' => array(
				'title'       => __( 'Password of Data Link', 'ccbill' ),
				'type'        => 'text',
				'description' => __( 'Password of Data Link Extract System.', 'ccbill' ),
				'default'     => $this->data_link_password
			),
			'data_link_test' => array(
				'title'   => __( 'Test mode of Data Link', 'ccbill' ),
				'type'    => 'checkbox',
				'label'   => __( 'Test mode of Data Link Extract System.', 'ccbill' ),
				'default' => $this->data_link_test
			),
		);
	}

	/**
	 * Output the gateway settings screen
	 * @return void
	 */
	public function admin_options() {
		wc_get_template(
			'admin/admin-options.php',
			array(
				'gateway' => $this,
			),
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Process Payment
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( TRUE )
		);
	}

	/**
	 * Validate frontend fields.
	 *
	 * Validate payment fields on the frontend.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		return true;
	}

	/**
	 * Receipt Page
	 *
	 * @param int $order_id
	 *
	 * @return void
	 */
	public function receipt_page( $order_id ) {
		// @see https://www.ccbill.com/cs/manuals/CCBill_Dynamic_Pricing.pdf
		$order = wc_get_order( $order_id );

		// Reformat US/CA phone
		$billing_phone = $order->get_billing_phone();
		if ( in_array( $order->get_billing_country(), array( 'US', 'CA' ) ) ) {
			$billing_phone = str_replace( array(
				'(',
				'-',
				' ',
				')',
				'.'
			), '', $order->get_billing_phone() );
		}

		// Prepare fields
		$fields = array(
			'customer_fname' => $order->get_billing_first_name(),
			'customer_lname' => $order->get_billing_last_name(),
			'address1'       => $order->get_billing_address_1(),
			'email'          => $order->get_billing_email(),
			'city'           => $order->get_billing_city(),
			'state'          => $order->get_billing_state(),
			'zipcode'        => $order->get_billing_postcode(),
			'country'        => $order->get_billing_country(),
			'phone_number'   => $billing_phone,
			'wc_order_id'    => $order_id,
			'clientAccnum'   => $this->client_accnum,
		);

		// Check Subscription
		$is_subscription = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
		if ( $is_subscription ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
			$subscription = array_shift( $subscriptions );

			/** @var WC_Subscription $subscription */
			$subscription_interval = $subscription->get_billing_interval();
			$subscription_period   = $subscription->get_billing_period();

			// Get Subscription period in days
			// Calculate subscription length
			$start_timestamp        = $subscription->get_time( 'start' );
			$trial_end_timestamp    = $subscription->get_time( 'trial_end' );
			$next_payment_timestamp = $subscription->get_time( 'next_payment' );

			$is_synced_subscription = WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription->get_id() );
			if ( $is_synced_subscription ) {
				$length_from_timestamp = $next_payment_timestamp;
			} elseif ( $trial_end_timestamp > 0 ) {
				$length_from_timestamp = $trial_end_timestamp;
			} else {
				$length_from_timestamp = $start_timestamp;
			}

			$subscription_length = wcs_estimate_periods_between( $length_from_timestamp, $subscription->get_time( 'end' ), $subscription_period );
			$subscription_length = ( empty( $subscription_length ) ? 99 : $subscription_length );
			switch ( $subscription_period ) {
				case 'day':
					$subscription_interval *= 1;
					break;
				case 'week':
					$subscription_interval *= 7;
					break;
				case 'month':
					$subscription_interval *= 30;
					break;
				case 'year':
					$subscription_interval *= 365;
					break;
			}

			$amount   = $subscription->get_total();
			$currency = $this->get_currency_code( $subscription->get_currency() );
			$form_digest = md5( $amount . $subscription_interval . $amount . $subscription_interval . $subscription_length . $currency . $this->salt );
		} else {
			$amount                = $order->get_total();
			$currency              = $this->get_currency_code( $order->get_currency() );
			$subscription_interval = 99;
			$form_digest           = md5( $amount . $subscription_interval . $currency . $this->salt );
		}

		if ( 'yes' === $this->is_flex_form ) {
			// FlexForm
			$url = 'https://api.ccbill.com/wap-frontflex/flexforms/' . $this->flex_form_name;

			if ( $is_subscription ) {
				$fields = array_merge(
					$fields,
					array(
						'clientSubacc'        => $this->client_subacc_recur,
						'currencyCode'        => $currency,
						'formDigest'          => $form_digest,
						'recurringPrice'      => $amount,
						'recurringPeriod'     => $subscription_interval,
						'numRebills'          => $subscription_length,
						'initialPrice'        => $amount,
						'initialPeriod'       => $subscription_interval
					)
				);
			}

		} else {
			// JPOST
			$url = 'https://bill.ccbill.com/jpost/signup.cgi';

			$fields = array_merge(
				$fields,
				array(
					'clientSubacc'   => $this->client_subacc,
					'formPrice'      => $amount,
					'formPeriod'     => 99,
					'currencyCode'   => $currency,
					'formName'       => $this->form_name,
					'formDigest'     => $form_digest,
				)
			);

			if ( $is_subscription ) {
				$fields = array_merge(
					$fields,
					array(
						'clientSubacc'        => $this->client_subacc_recur,
						'formPrice'           => $amount,
						'formPeriod'          => $subscription_interval,
						'currencyCode'        => $currency,
						'formName'            => $this->form_name,
						'formDigest'          => $form_digest,
						'formRecurringPrice'  => $amount,
						'formRecurringPeriod' => $subscription_interval,
						'formRebills'         => $subscription_length,
						'initialPrice'        => $amount,
						'initialPeriod'       => $subscription_interval
					)
				);
			}
		}

		wc_get_template(
			'checkout/ccbill/form.php',
			array(
				'action' => $url,
				'fields'  => apply_filters( 'woocommerce_ccbill_form_fields', $fields, $order, $this ),
				'order'   => $order,
				'gateway' => $this,
				'loader'  => plugins_url( '/assets/images/ring-alt.gif', dirname( __FILE__ ) . '/../' )
			),
			'',
			dirname( __FILE__ ) . '/../templates/'
		);
	}

	/**
	 * Check for ccbill Response
	 * @return void
	 **/
	public function ipn_handler() {
		/**
		 * Approval url: http://localhost/?wc-api=WC_Gateway_CCBill_Payments&Action=CheckoutSuccess
		 * Denial url: http://localhost/?wc-api=WC_Gateway_CCBill_Payments&Action=CheckoutFailure
		 */
		// Customer Redirect: Approval URL/Denial URL
		if ( isset( $_GET['Action'] ) && in_array( $_GET['Action'], array( 'CheckoutSuccess', 'CheckoutFailure') ) ) {
			$this->log( sprintf( 'FrontEnd Request: %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
			$order_id = WC()->session->get( 'order_awaiting_payment' );
			if ( empty( $order_id ) ) {
				wp_die( 'CCBILL Request Failure' );
				return;
			}

			$order = wc_get_order( $order_id );

			if ( 'CheckoutSuccess' === $_GET['Action'] ) {
				// Wait for order status change
				set_time_limit( 0 );
				$times = 0;
				$default_status = apply_filters( 'woocommerce_default_order_status', 'pending' );
				do {
					$times ++;
					if ( $times > 3 ) {
						break;
					}
					sleep( 10 );

					clean_post_cache( $order->get_id() );
				} while ( $order->has_status( $default_status ) );

				// CheckoutSuccess
				wp_redirect( $this->get_return_url( $order ) );
				return;
			}

			// Checkout Failure
			wp_redirect( $order->get_cancel_order_url() );
			return;
		}

		// Check IP from CCBill Network
		// https://kb.ccbill.com/Webhooks+User+Guide#Webhooks_IP_Ranges
		$cidrs = array('64.38.240.0/24', '64.38.241.0/24', '64.38.212.0/24', '64.38.215.0/24');
		if ( ! self::ip_match( self::get_remote_address(), $cidrs ) ) {
			$this->log( sprintf( 'CCBill Network check failed. Request: %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
			return;
		}

		// "Background Post" for Approval/Denial Posts
		// @see https://kb.ccbill.com/tiki-index.php?page=Background+Post
		// @see https://www.ccbill.com/cs/manuals/CCBill_Background_Post_Users_Guide.pdf
		if ( isset( $_GET['Action'] ) && in_array( $_GET['Action'], array('Approval_Post', 'Denial_Post') ) ) {
			$this->log( sprintf( 'Background Post Request: %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
			$this->log( sprintf( 'POST DATA: %s', var_export( $_POST, true ) ) );

			if ( empty($_POST['wc_order_id']) || ! ( $order = wc_get_order( $_POST['wc_order_id'] ) ) ) {
				$this->log( 'Background Post failure: Empty or invalid wc_order_id' );
				@ob_clean();
				header( 'HTTP/1.1 400 OK' );
				exit();
			}

			$order = wc_get_order( $_POST['wc_order_id'] );
			$is_subscription = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );

			// Add metadata for order
			if ( ! empty( $_POST['payer_email'] ) ) {
				update_post_meta( $order->get_id(), 'Payer CCBill email', wc_clean( $_POST['payer_email'] ) );
			}
			if ( ! empty( $_POST['first_name'] ) ) {
				update_post_meta( $order->get_id(), 'Payer first name', wc_clean( $_POST['first_name'] ) );
			}
			if ( ! empty( $_POST['last_name'] ) ) {
				update_post_meta( $order->get_id(), 'Payer last name', wc_clean( $_POST['last_name'] ) );
			}
			if ( ! empty( $_POST['payment_type'] ) ) {
				update_post_meta( $order->get_id(), 'Payment type', wc_clean( $_POST['payment_type'] ) );
			}
			if ( ! empty( $_POST['cardType'] ) ) {
				update_post_meta( $order->get_id(), 'Card type', wc_clean( $_POST['cardType'] ) );
			}
			if ( ! empty( $_POST['last4'] ) ) {
				update_post_meta( $order->get_id(), 'Card last4', wc_clean( $_POST['last4'] ) );
			}

			switch ( $_GET['Action'] ) {
				case 'Approval_Post':
					// Payment success
					$order->payment_complete();
					$order->add_order_note( __( 'Payment completed by Background Post', 'ccbill' ) );

					if ( $is_subscription ) {
						// Get Subscriptions of order
						$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
						foreach ( $subscriptions as $subscription ) {
							/** @var WC_Subscription $subscription */
							// Store Subscription ID
							update_post_meta( $subscription->get_id(), 'Subscription ID', $_POST['subscription_id'] );

							// Add metadata
							if ( ! empty( $_POST['email'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer CCBill email', $_POST['email'] );
							}
							if ( ! empty( $_POST['customer_fname'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer first name', $_POST['customer_fname'] );
							}
							if ( ! empty( $_POST['customer_lname'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer last name', $_POST['customer_lname'] );
							}
							if ( ! empty( $_POST['cardType'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payment type', $_POST['cardType'] );
							}
							if ( ! empty( $_POST['last4'] ) ) {
								update_post_meta( $subscription->get_id(), 'Card last4', wc_clean( $_POST['last4'] ) );
							}

							$this->log( sprintf( 'Action Approval_Post. Subscription ID: %s', $subscription->get_id() ) );
						}
					}
					break;
				case 'Denial_Post':
					// Payment denied
					$reason = ! empty( $_POST['reasonForDecline'] ) ? $_POST['reasonForDecline'] : 'Unknown Reason';
					$order->update_status( 'failed', sprintf( __( 'Payment failed by Background Post. Denial ID: %s. Reason: %s.', 'ccbill' ), $_POST['denialId'], strtolower( $reason ) ) );

					if ( $is_subscription ) {
						// Get Subscriptions of order
						$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
						foreach ( $subscriptions as $subscription ) {
							/** @var WC_Subscription $subscription */
							// Store Denial ID
							update_post_meta( $subscription->get_id(), 'Denial ID', $_POST['denialId'] );

							$this->log( sprintf( 'Action Denial_Post. Subscription ID: %s', $subscription->get_id() ) );
						}
					}
					break;
				default:
					// no default
					break;
			}

			@ob_clean();
			header( 'HTTP/1.1 200 OK' );
			exit();
		}

		// WebHooks
		// @see https://kb.ccbill.com/Webhooks+User+Guide#Webhooks_Notifications
		// @see https://kb.ccbill.com/Webhooks
		if ( isset( $_GET['eventGroupType'] ) ) {
			$this->log( sprintf( 'WebHook Request: %s from %s', $_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR'] ) );
			$this->log( sprintf( 'POST DATA: %s', var_export( $_POST, true ) ) );

			// Check Account Number
			if ( $this->client_accnum !== $_POST['clientAccnum'] ) {
				$this->log( sprintf( 'Error: Requested Account # %s don\'t match to store settings: %s ', $_POST['clientAccnum'], $this->client_accnum ) );
				return;
			}

			// Process action by Event Type
			switch ( $_GET['eventType'] ) {
				case 'NewSaleSuccess':
					$order_id = $_POST['X-wc_order_id'];
					$order = wc_get_order( $order_id );
					if ( ! $order ) {
						$this->log( sprintf( 'Error: Order ID: %s don\'t registered in store ', $_POST['X-wc_order_id'] ) );
						return;
					}

					// Update transactionId
					if ( empty( $order->get_transaction_id() ) ) {
						update_post_meta( $order_id, '_transaction_id', $_POST['transactionId' ] );
					}

					// Mark as paid
					if ( ! $order->is_paid() ) {
						$order->payment_complete( $_POST['transactionId'] );
						$order->add_order_note( __( 'Payment completed by WebHook', 'ccbill' ) );
					}

					// @todo Add metadata for order

					try {
						$subscriptions = wcs_get_subscriptions_for_order(
							$order_id, array(
								'order_type' => array('parent', 'renewal')
							)
						);

						foreach ( $subscriptions as $subscription ) {
							/** @var WC_Subscription  $subscription */
							update_post_meta( $subscription->get_id(), 'Subscription ID', $_POST['subscriptionId'] );
							$subscription->add_order_note( $_POST['priceDescription'] );
							$subscription->update_dates( array(
								'start' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['timestamp'] ) ) ,
								'next_payment' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['nextRenewalDate'] ) ),
								'last_payment' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['timestamp'] ) ),
								'end' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['nextRenewalDate'] ) + 1 * 24 * 60 * 60 )
							) );

							if ( ! empty( $_POST['payer_email'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer CCBill email', wc_clean( $_POST['payer_email'] ) );
							}
							if ( ! empty( $_POST['transactionId'] ) ) {
								update_post_meta( $subscription->get_id(), 'Transaction ID', wc_clean( $_POST['transactionId'] ) );
							}
							if ( ! empty( $_POST['first_name'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer first name', wc_clean( $_POST['first_name'] ) );
							}
							if ( ! empty( $_POST['last_name'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer last name', wc_clean( $_POST['last_name'] ) );
							}
							if ( ! empty( $_POST['payment_type'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payment type', wc_clean( $_POST['payment_type'] ) );
							}
							if ( ! empty( $_POST['cardType'] ) ) {
								update_post_meta( $order->get_id(), 'Card type', wc_clean( $_POST['cardType'] ) );
							}
							if ( ! empty( $_POST['last4'] ) ) {
								update_post_meta( $order->get_id(), 'Card last4', wc_clean( $_POST['last4'] ) );
							}

							// Activate Subscription
							if ( $subscription->can_be_updated_to( 'active' ) ) {
								$subscription->update_status( 'active', __( 'Activated by WebHook.', 'ccbill' ) );
							}

							$this->log( sprintf( 'Action NewSaleSuccess success. Order ID: %s. WC Subscription ID: %s', $order->get_id(), $subscription->get_id() ) );
						}
					} catch ( Exception $e ) {
						$this->log( sprintf( '[FAILED] Action NewSaleSuccess. Order ID: %s. Error: %s', $order->get_id(), $e->getMessage() ) );
					}
					break;
				case 'NewSaleFailure':
					$order_id = $_POST['X-wc_order_id'];
					$order = wc_get_order( $order_id );
					if ( ! $order ) {
						$this->log( sprintf( 'Error: Order ID: %s don\'t registered in store ', $_POST['X-wc_order_id'] ) );
						return;
					}

					// Update transactionId
					if ( empty( $order->get_transaction_id() ) ) {
						update_post_meta( $order_id, '_transaction_id', $_POST['transactionId' ] );
					}

					// Mark as failed
					if ( ! $order->has_status( 'failed' ) || ! $order->has_status( 'cancelled' ) ) {
						$order->update_status( 'failed',
							sprintf(
								__( 'Payment failed by WebHook. Transaction ID: %s. Reason: %s.', 'ccbill' ),
								$_POST['transactionId'],
								$_POST['failureReason']
							)
						);
					}

					// @todo Add metadata for order

					try {
						$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => array('parent', 'renewal') ) );
						foreach ( $subscriptions as $subscription ) {
							/** @var WC_Subscription  $subscription */
							update_post_meta( $subscription->get_id(), 'Denial ID', $_POST['transactionId'] ); // @todo Not sure?

							$subscription->add_order_note($_POST['priceDescription']);

							if ( ! empty( $_POST['payer_email'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer CCBill address', wc_clean( $_POST['payer_email'] ) );
							}
							if ( ! empty( $_POST['txn_id'] ) ) {
								update_post_meta( $subscription->get_id(), 'Transaction ID', wc_clean( $_POST['txn_id'] ) );
							}
							if ( ! empty( $_POST['first_name'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer first name', wc_clean( $_POST['first_name'] ) );
							}
							if ( ! empty( $_POST['last_name'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payer last name', wc_clean( $_POST['last_name'] ) );
							}
							if ( ! empty( $_POST['payment_type'] ) ) {
								update_post_meta( $subscription->get_id(), 'Payment type', wc_clean( $_POST['payment_type'] ) );
							}
							if ( ! empty( $_POST['cardType'] ) ) {
								update_post_meta( $order->get_id(), 'Card type', wc_clean( $_POST['cardType'] ) );
							}
							if ( ! empty( $_POST['last4'] ) ) {
								update_post_meta( $order->get_id(), 'Card last4', wc_clean( $_POST['last4'] ) );
							}

							// Hold Subscription
							if ( $subscription->can_be_updated_to('on-hold') ) {
								$subscription->update_status( 'on-hold', sprintf( __( 'Reason: %s', 'ccbill' ), $_POST['failureReason'] ) );
							}

							$this->log( sprintf( 'Action NewSaleFailure success. Order ID: %s. WC Subscription ID: %s', $order->get_id(), $subscription->get_id() ) );
						}
					} catch (Exception $e) {
						$this->log( sprintf( '[FAILED] Action NewSaleFailure. Order ID: %s. Error: %s', $order->get_id(), $e->getMessage() ) );
					}
					break;
				case 'RenewalSuccess':
					/** @var WC_Subscription $subscription */
					$subscription = self::get_subscription( $_POST['subscriptionId'] );
					if ( ! $subscription ) {
						$this->log( sprintf( 'Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					try {
						// @see WC_Subscriptions_Manager::prepare_renewal()
						if ( $subscription->can_be_updated_to( 'on-hold' ) ) {
							// Always put the subscription on hold in case something goes wrong while trying to process renewal
							$subscription->update_status( 'on-hold', _x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );
						}

						// Generate a renewal order for payment gateways to use to record the payment (and determine how much is due)
						$renewal_order = wcs_create_renewal_order( $subscription );
						$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
						if ( is_callable( array( $renewal_order, 'save' ) ) ) {
							$renewal_order->save();
						}

						// Success
						$renewal_order->payment_complete( $_POST['transactionId'] );

						// Update Subscription Payment date
						$last_payment = $subscription->get_date('last_payment');
						$subscription->add_order_note( $_POST['priceDescription'] );
						$subscription->update_dates( array(
							'cancelled' => 0,
							'last_order_date_created' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $renewal_order->get_date_created() ) ),
							'next_payment' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $_POST['nextRenewalDate'] ) ),
							'last_payment' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $last_payment ) ),
							'end' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $_POST['nextRenewalDate'] ) + 1 * 24 * 60 * 60 )
						) );

						// Activate Subscription
						if ( $subscription->can_be_updated_to('active') ) {
							$subscription->update_status( 'active', __( 'Success Renewal by WebHook.', 'ccbill' ) ); // don't call payment_complete() because technically, no payment was received
						}

						/* $subscriptions = wcs_get_subscriptions_for_order($renewal_order, array( 'order_type' => array('parent', 'renewal') ));
						foreach ($subscriptions as $subscription) {
							$last_payment = $subscription->get_date('last_payment');


							$subscription->add_order_note($_POST['priceDescription']);
							$subscription->update_dates(array(
								'next_payment' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['nextRenewalDate'] ) ),
								'last_payment' => gmdate( 'Y-m-d H:i:s', strtotime( $last_payment ) ),
								'end' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['nextRenewalDate'] ) + 1 * 24 * 60 * 60 )
							));

							if ($subscription->can_be_updated_to('active')) {
								$subscription->update_status( 'active' );
							}
						} */

						$this->log( sprintf('Renewal success: WC Subscription ID: %s. Subscription ID: %s ', $subscription->get_id(), $_POST['subscriptionId'] ) );

						// Membership workaround
						$membership_subscriptions = wc_memberships()->get_integrations_instance()->get_subscriptions_instance();
						$user_memberships = $membership_subscriptions->get_memberships_from_subscription( $subscription->get_id() );
						if ( count( $user_memberships ) === 0 ) {
							$memberships = wc_memberships()->get_user_memberships_instance()->get_user_memberships( $subscription->get_user_id() );
							foreach ($memberships as $membership) {
								/** @var $membership WC_Memberships_User_Membership */
								update_post_meta( $membership->get_id(), '_subscription_id', $subscription->get_id() );
								$this->log( sprintf( 'Renewal success: Membership #%s has been assigned to Subscription #%s ', $membership->get_id(), $subscription->get_id() ) );
							}
						}

						// Trigger membership renewal
						$user_memberships = $membership_subscriptions->get_memberships_from_subscription( $subscription->get_id() );
						if ( count( $user_memberships ) > 0 ) {
							$membership_subscriptions->handle_subscription_status_change( $subscription, 'active', null );
							$membership_subscriptions->update_related_membership_dates( $subscription, 'end', $subscription->get_date( 'end' ) );
						}
					} catch (Exception $e) {
						$this->log( sprintf( '[FAILED] Renewal: WC Subscription ID: %s. Subscription ID: %s. Error: %s', $subscription->get_id(), $_POST['subscriptionId'], $e->getMessage() ) );
					}
					break;
				case 'RenewalFailure':
					/** @var WC_Subscription  $subscription */
					$subscription = self::get_subscription( $_POST['subscriptionId'] );
					if ( ! $subscription ) {
						$this->log( sprintf( 'Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					try {
						// @see WC_Subscriptions_Manager::prepare_renewal()
						if ( $subscription->can_be_updated_to( 'on-hold' ) ) {
							// Always put the subscription on hold in case something goes wrong while trying to process renewal
							$subscription->update_status(
								'on-hold',
								_x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' )
							);
						}

						// Generate a renewal order for payment gateways to use to record the payment (and determine how much is due)
						$renewal_order = wcs_create_renewal_order( $subscription );
						$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
						if ( is_callable( array( $renewal_order, 'save' ) ) ) {
							$renewal_order->save();
						}

						// Add Transaction Id
						update_post_meta( $renewal_order->get_id(), '_transaction_id', $_POST['transactionId'] );

						// Mark Subscription as Failed
						$renewal_order->add_order_note( sprintf( 'Payment failed. Reason: %s', $_POST['failureReason'] ) );
						$subscription->payment_failed( 'on-hold' );

						$this->log( sprintf( 'Renewal failure: WC Subscription ID: %s. Subscription ID: %s ', $subscription->get_id(), $_POST['subscriptionId'] ) );
					} catch ( Exception $e ) {
						$this->log( sprintf('[FAILED] Renewal failure: WC Subscription ID: %s. Subscription ID: %s. Error: %s ', $subscription->get_id(), $_POST['subscriptionId'], $e->getMessage() ) );
					}
					break;
				case 'Cancellation':
					/** @var WC_Subscription  $subscription */
					$subscription = self::get_subscription( $_POST['subscriptionId'] );
					if ( ! $subscription ) {
						$this->log( sprintf('Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					if ( $subscription->has_status( 'pending-cancel' ) ) {
						$this->log( sprintf('Pending Cancellation: WC Subscription ID: %s. Subscription ID: %s ', $subscription->get_id(), $_POST['subscriptionId'] ) );
						return;
					}

					if ( $subscription->can_be_updated_to('cancelled') ) {
						$this->fix_cancellation( $subscription );

						try {
							$subscription->cancel_order( sprintf( 'Cancellation. Reason: %s', $_POST['reason'] ) );
							// @todo Check reason: Satisfied Customer
							//if (false) {
							//	$subscription->update_status('cancelled', sprintf( 'Cancellation. Reason: %s', $_POST['reason'] ) );
							//} else {
								// With Pending Cancel
								//if ($subscription->has_status('pending-cancel')) {
								//	$subscription->add_order_note( sprintf( 'Cancellation. Reason: %s', $_POST['reason'] ) );
								//} else {
								//	$subscription->cancel_order( sprintf( 'Cancellation. Reason: %s', $_POST['reason'] ) );
								//}
							//}
							$this->log( sprintf('Cancellation: WC Subscription ID: %s. Subscription ID: %s. Reason: %s', $subscription->get_id(), $_POST['subscriptionId'], $_POST['reason'] ) );
						} catch (Exception $e) {
							$this->log( sprintf('[FAILED] Cancellation failure: WC Subscription ID: %s. Subscription ID: %s. Error: %s ', $subscription->get_id(), $_POST['subscriptionId'], $e->getMessage()) );
						}
					} else {
						$this->log( sprintf( '[FAILED] Cancellation: WC Subscription ID: %s. Subscription ID: %s. Reason: %s', $subscription->get_id(), $_POST['subscriptionId'], $_POST['reason'] ) );
					}
					break;
				case 'Expiration':
					/** @var WC_Subscription  $subscription */
					$subscription = self::get_subscription($_POST['subscriptionId']);
					if ( ! $subscription ) {
						$this->log( sprintf('Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					if ( $subscription->can_be_updated_to('expired') ) {
						$this->fix_cancellation( $subscription );

						try {
							// Workaround: The cancelled date must occur after the last payment date.
							$last_payment_date = $subscription->get_date( 'last_order_date_created' );
							$cancelled = $subscription->get_date( 'cancelled' );
							if (wcs_date_to_time($cancelled) < wcs_date_to_time($last_payment_date)) {
								$subscription->update_dates(array(
									'cancelled' => $last_payment_date
								));
							}

							$subscription->update_status( 'expired', sprintf('Expired. Reason: %s', 'Expired') );
							$this->log( sprintf( 'Expiration: WC Subscription ID: %s. Subscription ID: %s ', $subscription->get_id(), $_POST['subscriptionId'] ) );
						} catch (Exception $e) {
							$this->log( sprintf( '[FAILED] Expiration failure: WC Subscription ID: %s. Subscription ID: %s. Error: %s ', $subscription->get_id(), $_POST['subscriptionId'], $e->getMessage() ) );
						}

					} else {
						$this->log( sprintf('[FAILED] Expiration: WC Subscription ID: %s. Subscription ID: %s ', $subscription->get_id(), $_POST['subscriptionId'] ) );
					}
					break;
				case 'Void':
				case 'Chargeback':
				case 'Refund':
					/** @var WC_Subscription  $subscription */
					$subscription = self::get_subscription( $_POST['subscriptionId'] );
					if ( ! $subscription ) {
						$this->log( sprintf('Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					if ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
						$this->fix_cancellation( $subscription );
						$subscription->cancel_order( sprintf('Cancellation by Event. Reason: %s', $_POST['eventType']) );
					}

					$this->log( sprintf('Cancellation by Event: WC Subscription ID: %s. Subscription ID: %s. Event: %s', $subscription->get_id(), $_POST['subscriptionId'], $_POST['eventType'] ) );
					break;
				case 'BillingDateChange':
					/** @var WC_Subscription  $subscription */
					$subscription = self::get_subscription( $_POST['subscriptionId'] );
					if ( ! $subscription ) {
						$this->log( sprintf('Error: Subscription ID: %s don\'t registered in store ', $_POST['subscriptionId'] ) );
						return;
					}

					$this->fix_cancellation( $subscription );
					try {
						$subscription->update_dates(array(
							'next_payment' => gmdate( 'Y-m-d H:i:s', strtotime( $_POST['nextRenewalDate'] ) ),
							'end' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $_POST['nextRenewalDate'] ) + 1 * 24 * 60 * 60 )
						));
						$this->log( sprintf( 'BillingDateChange Success: WC Subscription ID: %s. Subscription ID: %s. Event: %s', $subscription->get_id(), $_POST['subscriptionId'], $_POST['eventType'] ) );
					} catch (Exception $e) {
						$this->log( sprintf( 'BillingDateChange Failed: WC Subscription ID: %s. Subscription ID: %s. Event: %s. Error: %s', $subscription->get_id(), $_POST['subscriptionId'], $_POST['eventType'], $e->getMessage() ) );
					}
					break;
				default:
					$this->log( sprintf( '[FAILED] Error: Unsupported action: %s. Subscription ID: %s. POST: %s ', $_GET['eventType'], $_POST['subscriptionId'], $_POST ) );
			}
		}
	}

	/**
	 * Clone CCBILL Subscription ID when Subscription created
	 *
	 * @param $order_id
	 */
	public function add_subscription_id( $order_id ) {
		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			$subscription_id = get_post_meta( $subscription->get_id(), 'Subscription ID', true );
			if ( empty( $subscription_id ) ) {
				$subscription_id = get_post_meta( $subscription->order->get_id(), 'Subscription ID', true );
				add_post_meta( $subscription->get_id(), 'Subscription ID', $subscription_id );
			}
		}
	}

	/**
	 * Update the card meta for a subscription after using Authorize.Net to complete a payment to make up for
	 * an automatic renewal payment which previously failed.
	 *
	 * @access public
	 *
	 * @param WC_Subscription $subscription  The subscription for which the failing payment method relates.
	 * @param WC_Order        $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {
		update_post_meta(
			$subscription->get_id(),
			'Subscription ID',
			get_post_meta( $renewal_order->get_id(), 'Subscription ID', true )
		);
	}

	/**
	 * Don't transfer customer meta to resubscribe orders.
	 *
	 * @access public
	 *
	 * @param int $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription
	 *
	 * @return void
	 */
	public function remove_resubscribe_order_meta( $resubscribe_order ) {
		delete_post_meta( wcs_get_objects_property( $resubscribe_order, 'id' ), 'Subscription ID' );
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	 *
	 * @since 2.4
	 *
	 * @param array           $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 *
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'Subscription ID' => array(
					'value' => get_post_meta( $subscription->get_id(), 'Subscription ID', true ),
					'label' => 'CCBIll Subscription ID',
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	 *
	 * @since 2.4
	 *
	 * @param string $payment_method_id The ID of the payment method to validate
	 * @param array  $payment_meta      associative array of meta data required for automatic payments
	 *
	 * @throws Exception
	 * @return array
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( $this->id === $payment_method_id ) {
			if ( ! isset( $payment_meta['post_meta']['Subscription ID']['value'] ) || empty( $payment_meta['post_meta']['Subscription ID']['value'] ) ) {
				throw new Exception( 'CCBIll Subscription ID is required.' );
			}
		}
	}

	/**
	 * Get Subscription by CCBill Subscription ID
	 * @param $subscription_id
	 *
	 * @return bool|\WC_Subscription
	 */
	public static function get_subscriptions_by_ccbill_id( $subscription_id ) {
		global $wpdb;

		$query = "
			SELECT post_id FROM {$wpdb->prefix}postmeta 
			LEFT JOIN {$wpdb->prefix}posts ON ({$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id)
			WHERE {$wpdb->prefix}posts.post_type = %s AND meta_key = %s AND meta_value = %s;";

		$sql = $wpdb->prepare( $query, 'shop_subscription', 'Subscription ID', $subscription_id );
		$order_id = $wpdb->get_var( $sql );
		if ( ! $order_id ) {
			return false;
		}

		return wcs_get_subscription( $order_id );
	}

	/**
	 * Get Order by Transaction ID
	 * @param $transaction_id
	 *
	 * @return bool|\WC_Order
	 */
	public static function get_order_by_transaction_id($transaction_id) {
		global $wpdb;

		$query = "
			SELECT post_id FROM {$wpdb->prefix}postmeta 
			WHERE meta_key = %s AND meta_value = %s;";
		$sql = $wpdb->prepare( $query, '_transaction_id', $transaction_id );
		$order_id = $wpdb->get_var( $sql );
		if ( ! $order_id ) {
			return false;
		}

		return wc_get_order( $order_id );
	}

	/**
	 * @param $subscription_id
	 *
	 * @return bool|WC_Subscription
	 */
	public static function get_subscription( $subscription_id ) {
		$subscription = self::get_subscriptions_by_ccbill_id( $_POST['subscriptionId'] );
		if ( $subscription ) {
			return $subscription;
		}

		// Failback for deprecated orders
		$order = self::get_order_by_transaction_id( $subscription_id );
		if ( $order ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order );
			if ( $subscriptions ) {
				foreach ( $subscriptions as $key => $subscription ) {
					/** @var $subscription WC_Subscription */
					$backup = get_post_meta( $subscription->get_id(), 'Subscription ID', true );
					if ( ! empty( $backup ) ) {
						update_post_meta( $subscription->get_id(), '_ccbill_transaction', $backup );
					}

					update_post_meta( $subscription->get_id(), 'Subscription ID', $_POST['subscriptionId'] );

					return $subscription;
				}
			}
		}

		return false;
	}

	/**
	 * @param $can_be_updated
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	public function can_subscription_be_updated_to_active( $can_be_updated, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $can_be_updated;
		}

		if ( $subscription->has_status( 'expired' ) || $subscription->has_status( 'cancelled' ) ) {
			$can_be_updated = true;
		}

		return $can_be_updated;
	}

	/**
	 * @param $can_be_updated
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	public function can_subscription_be_updated_to_on_hold( $can_be_updated, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $can_be_updated;
		}

		if ( ! $subscription->has_status( 'on-hold' ) ) {
			$can_be_updated = true;
		}

		return $can_be_updated;
	}

	/**
	 * @param $can_be_updated
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	public function can_subscription_be_updated_to_cancelled( $can_be_updated, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $can_be_updated;
		}

		if ( $subscription->has_status( 'cancelled' ) ) {
			return $can_be_updated;
		}

		return true;
	}

	/**
	 * @param $can_be_updated
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	public function can_subscription_be_updated_to_expired( $can_be_updated, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $can_be_updated;
		}

		if ( $subscription->has_status( 'expired' ) ) {
			return $can_be_updated;
		}

		return true;
	}

	/**
	 * @param $can_be_updated
	 * @param WC_Subscription $subscription
	 * @return bool
	 */
	public function can_subscription_be_updated_to_pending_cancel( $can_be_updated, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $can_be_updated;
		}

		if ( $subscription->has_status(  'pending-cancel' ) ) {
			return $can_be_updated;
		}

		return true;
	}

	/**
	 * Disable Subscription actions on "My Account" page
	 * @param array $actions
	 * @param WC_Subscription $subscription
	 *
	 * @return array
	 */
	public function view_subscription_actions( $actions, $subscription ) {
		if ( $subscription->get_payment_method() !== $this->id ) {
			return $actions;
		}

		unset( $actions['suspend'], $actions['reactivate'] );

		return $actions;
	}

	/**
	 * When a store manager or user cancels a subscription in the store, also cancel the subscription with CCBill.
	 * @param WC_Subscription $subscription
	 *
	 * @return void
	 */
	public function customer_cancel_subscription( $subscription ) {
		if ( $subscription->get_payment_method() === $this->id ) {
			$subscription->add_order_note( __( 'Customer wants to cancel subscription.', 'ccbill' ) );
			if ( ! $this->maybe_cancel_subscription( $subscription ) ) {
				$subscription->add_order_note( __( 'Failed to cancel subscription via CCBill.', 'ccbill' ) );
			}
		}
	}

	/**
	 * Cancel CCBill Subscription on Next Renewal
	 * @param $subscription_id
	 * @return bool
	 */
	public function maybe_cancel_subscription( $subscription_id ) {
		if ( empty( $this->data_link_username ) ) {
			return false;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription->get_payment_method() !== $this->id ) {
			return false;
		}

		$subscription_id = $subscription->get_id();

		// Cancel subscription
		// @see https://kb.ccbill.com/SMS
		// @see https://kb.ccbill.com/CCBill+API%3A+Cancel+Subscription
		$subscriptionId = get_post_meta( $subscription_id, 'Subscription ID', true );

		$params = array(
			'returnXML'      => 1,
			'clientAccnum'   => $this->client_accnum,
			'usingSubacc'    => $this->client_subacc_recur,
			'username'       => $this->data_link_username,
			'password'       => $this->data_link_password,
			'testMode'       => $this->data_link_test === 'yes' ? 1 : 0,
			'action'         => 'cancelSubscription',
			'subscriptionId' => $subscriptionId
		);

		$result = wp_remote_request( 'https://datalink.ccbill.com/utils/subscriptionManagement.cgi?' . http_build_query( $params, '', '&' ) );
		if ( $result instanceof WP_Error ) {
			$this->log( sprintf( 'Failed to cancel subscription %s (SubscriptionID %s) via CCBill DataLink: %s', $subscription_id, $subscriptionId, var_export( $result, true ) ) );

			return false;
		}

		if ( isset( $result['response']['code'] ) && 200 === $result['response']['code'] ) {
			/**
			 * 0	The requested action failed.
			 * -1	The arguments provided to authenticate the merchant were invalid or missing.
			 * -2	The subscription id provided was invalid or the subscription type is not supported by the requested action.
			 * -3	No record was found for the given subscription.
			 * -4	The given subscription was not for the account the merchant was authenticated on.
			 * -5	The arguments provided for the requested action were invalid or missing.
			 * -6	The requested action was invalid
			 * -7	There was an internal error or a database error and the requested action could not complete.
			 * -8	The IP Address the merchant was attempting to authenticate on was not in the valid range.
			 * -9	The merchant’s account has been deactivated for use on the Datalink system or the merchant is not permitted to perform the requested action
			 * -10	The merchant has not been set up to use the Datalink system.
			 * -11	Subscription is not eligible for a discount, recurring price less than $5.00.
			 * -12	The merchant has unsuccessfully logged into the system 3 or more times in the last hour. The merchant should wait an hour before attempting to login again and is advised to review the login information.
			 * -15	Merchant over refund threshold
			 * -16	Merchant over void threshold
			 * -23	Transaction limit reached
			 * -24	Purchase limit reached
			 */
			if ( mb_strpos( $result['body'], '<results>1</results>', 0, 'UTF-8' ) !== false ) {
				$subscription->add_order_note( __( 'Subscription cancelled with CCBill DataLink', 'ccbill' ) );
				$this->log( 'Subscription cancelled with CCBill DataLink: ' . $subscription_id );
				//$subscription->update_status( 'cancelled', 'Cancelled with CCBill DataLink' );
				// Status will be updated with WebHook

				return true;
			} else {
				$subscription->add_order_note( __( 'Failed to cancel subscription via CCBill DataLink', 'ccbill' ) );
				$this->log( sprintf('Failed to cancel subscription %s (SubscriptionID %s) via CCBill DataLink: %s', $subscription_id, $subscriptionId, $result['body'] ) );
			}
		}

		return false;
	}

	/**
	 * Debug Log
	 *
	 * @param $message
	 * @param $level
	 *
	 * @return void
	 */
	public function log( $message, $level  = WC_Log_Levels::NOTICE ) {
		// Is Enabled
		if ( 'yes' !== $this->debug ) {
			return;
		}

		// Get Logger instance
		$log = new WC_Logger();

		// Write message to log
		if ( ! is_string( $message ) ) {
			$message = var_export( $message, true );
		}

		$log->log( $level, $message, array( 'source' => $this->id, '_legacy' => true ) );
	}

	/**
	 * Get Currency Number Code ISO 4217
	 * @param $currency
	 *
	 * @return bool|mixed
	 */
	public function get_currency_code( $currency ) {
		$currencies = array(
			'USD' => '840',
			'EUR' => '978',
			'AUD' => '036',
			'CAD' => '124',
			'GBP' => '826',
			'JPY' => '392',
		);

		if ( isset( $currencies[ $currency ] ) ) {
			return $currencies[ $currency ];
		}

		return false;
	}

	/**
	 * Checks if a given IP address matches the specified CIDR subnet/s
	 * @see https://gist.github.com/tott/7684443#gistcomment-2108696
	 * @param string $ip The IP address to check
	 * @param mixed $cidrs The IP subnet (string) or subnets (array) in CIDR notation
	 * @param string $match optional If provided, will contain the first matched IP subnet
	 * @return boolean TRUE if the IP matches a given subnet or FALSE if it does not
	 */
	public static function ip_match( $ip, $cidrs, &$match = null ) {
		foreach( (array) $cidrs as $cidr ) {
			list( $subnet, $mask ) = explode( '/', $cidr );

			if ( ( ( ip2long( $ip ) & ( $mask = ~ ( ( 1 << (32 - $mask) ) - 1) ) ) == ( ip2long( $subnet ) & $mask) ) ) {
				$match = $cidr;

				return true;
			}
		}

		return false;
	}

	/**
	 * @param WC_Subscription $subscription
	 */
	public function fix_cancellation( $subscription )
	{
		// Workaround The cancelled date must occur after the last payment date.
		$last_payment = $subscription->get_date( 'last_payment' );
		$cancelled_date = $subscription->get_date( 'cancelled' );
		$end_date = $subscription->get_date( 'cancelled' );
		if ( $cancelled_date !== 0 && ( wcs_date_to_time( $last_payment ) > wcs_date_to_time( $cancelled_date ) ) ) {
			$subscription->update_dates( array(
				'last_payment' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $cancelled_date ) ),
			) );
		}

		if ( $end_date !== 0 && ( wcs_date_to_time( $last_payment ) > wcs_date_to_time( $end_date ) ) ) {
			$subscription->update_dates( array(
				'last_payment' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $end_date ) ),
			) );
		}

		if ( $cancelled_date !== 0 && ( wcs_date_to_time( $cancelled_date ) > wcs_date_to_time( $end_date ) ) ) {
			$subscription->update_dates( array(
				'cancelled' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $end_date ) ),
				'end' => gmdate( 'Y-m-d H:i:s', wcs_date_to_time( $end_date ) ),
			) );
		}

		// Workaround: The end date must occur after the cancellation date.
		// Workaround: The end date must occur after the last payment date.
	}
	/**
	 * Get Remove Address
	 * @return string
	 */
	public static function get_remote_address() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_SUCURI_CLIENTIP',
			'CLIENT_IP',
			'FORWARDED',
			'FORWARDED_FOR',
			'FORWARDED_FOR_IP',
			'HTTP_CLIENT_IP',
			'HTTP_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED_FOR_IP',
			'HTTP_PC_REMOTE_ADDR',
			'HTTP_PROXY_CONNECTION',
			'HTTP_VIA',
			'HTTP_X_FORWARDED',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED_FOR_IP',
			'HTTP_X_IMFORWARDS',
			'HTTP_XROXY_CONNECTION',
			'VIA',
			'X_FORWARDED',
			'X_FORWARDED_FOR'
		);

		$remote_address = false;
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$remote_address = $_SERVER[ $header ];
				break;
			}
		}

		if ( ! $remote_address ) {
			$remote_address = $_SERVER['REMOTE_ADDR'];
		}

		// Extract address from list
		if ( strpos( $remote_address, ',' ) !== false ) {
			$tmp            = explode( ',', $remote_address );
			$remote_address = trim( array_shift( $tmp ) );
		}

		// Remove port if exists (IPv4 only)
		$regEx = "/^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}$/";
		if ( preg_match( $regEx, $remote_address )
		     && ( $pos_temp = stripos( $remote_address, ':' ) ) !== false
		) {
			$remote_address = substr( $remote_address, 0, $pos_temp );
		}

		return $remote_address;
	}
}
