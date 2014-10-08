<?php
/*
Plugin Name: Events Manager Pro - PayPal chained payments
Version: 1.0
Plugin URI: http://www.andyplace.co.uk
Description: PayPal payment gateway allowing chained payments
Author: Andy Place
Author URI: http://wp-events-plugin.com
*/

class EM_Pro_PayPal_Chained {

  function EM_Pro_PayPal_Chained() {
    global $wpdb;
    //Set when to run the plugin : after EM is loaded.
    add_action( 'plugins_loaded', array(&$this,'init'), 100 );
  }

  function init() {

    // @TODO: Disable if Events Manager Pro plugin not active. Add flash notice.

    //add-ons
    include('add-ons/gateways/gateway.paypal-chained-payments.php');
  }
}

// Start plugin
global $EM_Pro_PayPal_Chained;
$EM_Pro_PayPal_Chained = new EM_Pro_PayPal_Chained();