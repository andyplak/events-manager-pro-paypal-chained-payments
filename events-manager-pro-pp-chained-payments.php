<?php
/*
Plugin Name: Events Manager Pro - PayPal chained payments
Version: 1.1.4
Plugin URI: http://www.andyplace.co.uk
Description: PayPal payment gateway allowing chained payments
Author: Andy Place
Author URI: http://wp-events-plugin.com
*/

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

class EM_Pro_PayPal_Chained {

  function __construct() {
    global $wpdb;

    // Some rewite pre-requesits
    add_action('init', array(&$this,'rewrite_init') );

    //Set when to run the plugin : after EM is loaded.
    add_action( 'plugins_loaded', array(&$this,'init'), 100 );
  }

  /**
   * Add special rewrite rule for paypal IPN handler url
   */
  function rewrite_init() {
    global $wp, $wp_rewrite;
    $wp->add_query_var('action');
    $wp->add_query_var('em_payment_gateway');
    $wp_rewrite->add_rule('^pp-chained-ipn$', '/wp-admin/admin-ajax.php?action=em_payment&em_payment_gateway=paypal_chained', 'top');
  }

  function init() {
    if( is_plugin_active('events-manager/events-manager.php') && is_plugin_active('events-manager-pro/events-manager-pro.php') ) {
      include('add-ons/gateways/gateway.paypal-chained-payments.php');
    }else{
      add_action( 'admin_notices', array(&$this,'not_activated_error_notice') );
    }
  }

  function not_activated_error_notice() {
    $class = "error";
    $message = __('Please ensure both Events Manager and Events Manager Pro are enabled for the PayPal Chained Payments Gateway to work.', 'em-pro-mrcash');
    echo '<div class="'.$class.'"> <p>'.$message.'</p></div>';
  }
}

// Start plugin
global $EM_Pro_PayPal_Chained;
$EM_Pro_PayPal_Chained = new EM_Pro_PayPal_Chained();