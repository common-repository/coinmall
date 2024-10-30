<?php
/**
 * Plugin Name: CoinMall
 * Plugin URI: https://www.coinmall.com/
 * Description:  Provides a CoinMall.com Payment Gateway for WooCommerce.
 * Author: CoinMall - The Next Generation Marketplace
 * Author URI: https://www.coinmall.com/
 * Version: 1.0.0
 */

/**
 * CoinMall.com Gateway
 *
 * Provides a CoinMall.com Payment Gateway.
 *
 * @class 		WC_Coinmall
 * @extends		WC_Gateway_Coinmall
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		CoinMall.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
add_action( 'plugins_loaded', 'load_coinmall_plugin', 0 );
function load_coinmall_plugin() {
    include_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'CoinMall-woocommerce.php');
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    add_filter( 'woocommerce_payment_gateways', 'add_coinmall_gateway');
    add_filter('cron_schedules','coinmall_cron_schedules');
    add_action('woocommerce_api_wc_gateway_coinmall_cron','coinmall_cron');
    add_action('woocommerce_checkout_process','check_coinmall_checkout_fields');
    add_action('woocommerce_api_wc_gateway_coinmall',array('WC_Payment_Gateway','check_ipn_request_is_valid'));
}
function add_coinmall_gateway($methods) {
  if (!in_array('WC_Gateway_Coinmall', $methods)) {
        $methods[] = 'WC_Gateway_Coinmall';
    }
    return $methods;
}
function get_available_coinmall_currencies() {
    return ['BTC'=>'Bitcoin','ZEC'=>'ZCash'];
}
function check_coinmall_checkout_fields() {
    if(empty($_POST['crypto_currency']) || !in_array($_POST['crypto_currency'],array_keys(get_available_coinmall_currencies()))) {
       wc_add_notice( __( '<b>Please select currency</b>.' ), 'error' );
    }
}
function enable_coinmall_cron_jobs() {
    if (! wp_get_schedule ( 'woocommerce_api_wc_gateway_coinmall_cron' )) {
        $sc = wp_schedule_event( time(), 'every_10sec', 'woocommerce_api_wc_gateway_coinmall_cron' );
    }
}
function disable_coinmall_cron_jobs() {
     wp_clear_scheduled_hook( 'woocommerce_api_wc_gateway_coinmall_cron' );
}
function coinmall_cron_schedules($schedules){
    if(!isset($schedules["10sec"])){
        $schedules["every_10sec"] = array(
            'interval' => 10,
            'display' => __('Once every 10 seconds'));
    }
    return $schedules;
}

function coinmall_cron() {
    $woo = new WC_Gateway_Coinmall();
    $args = ['cmd'=>'getPurchases','key'=>$woo->api_key];
    $args['cmd'] = 'getPurchases';
    $hmac =  hash_hmac("sha256", http_build_query($args), $woo->api_secret);
    $purchases = wp_remote_post($woo->api_url,array('body'=>$args,'headers'=>array(
        'CoinMall-API-hmac'=>$hmac
    )));
    $purchases = json_decode($purchases['body'],true);
    foreach ($purchases as $purchase) {
        if ($purchase['paid'] >= $purchase['amount']) {
            $order = $woo->get_wc_order($purchase['invoice']);
            if ($order->get_status() != 'completed') {
                update_post_meta( $order->get_id(), 'CoinMall Payment Status', 'Completed');
                update_post_meta( $order->get_id(), 'CoinMall Amount Paid', $purchase['paid']);
                $order->payment_complete();
                $order->update_status('completed');
            }
        }
    }
}

register_activation_hook(__FILE__, 'enable_coinmall_cron_jobs');
register_deactivation_hook(__FILE__, 'disable_coinmall_cron_jobs' );
