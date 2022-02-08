<?php
/*
 * Plugin Name: CCBill Payment Gateway for WooCommerce
 * Plugin URI: #
 * Description: Provides a Payment Gateway through CCBill for WooCommerce Subscriptions
 * Author: Oleg Iskusnyh
 * Author URI: https://oleg.iskusnyh.pro
 * License: Apache License 2.0
 * License URI: http://www.apache.org/licenses/LICENSE-2.0
 * Version: 1.0.0
 * Text Domain: ccbill
 * Domain Path: /languages
 * WC requires at least: 5.5.1
 * WC tested up to: 6.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly


class WC_CCBill_Payments {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Actions
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array(
			$this,
			'plugin_action_links'
		) );

		add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_scripts' ) );
		add_filter( 'woocommerce_payment_gateways', array(
			$this,
			'register_gateway'
		) );
	}

	/**
	 * Add relevant links to plugins page
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ccbill' ) ) . '">' . __( 'Settings', 'ccbill' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Init localisations and files
	 */
	public function init() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Localization
		load_plugin_textdomain(
			'ccbill',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/i18n'
		);

		// Includes
		include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-ccbill-payments.php' );
	}

	/**
	 * Register the gateways for use
	 */
	public function register_gateway( $methods ) {
		$methods[] = 'WC_Gateway_CCBill_Payments';

		return $methods;
	}

	/**
	 * Add Scripts
	 */
	public function add_scripts() {
		wp_enqueue_style(
			'wc-gateway-ccbill',
			plugins_url( '/assets/css/style.css', __FILE__ ),
			array(), false, 'all' );
	}
}

new WC_CCBill_Payments();

