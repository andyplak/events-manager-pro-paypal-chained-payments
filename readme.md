### PayPal Payment Gateway for Events Manager with Chained Payments

Contributors: socarrat

Tags: Events Manager, Events Manager Pro, PayPal, Chained Payments

Requires at least: 3.0.1

Tested up to: 4.0

Stable tag: 0.1

### Description

Allows chained payments (funds split between different paypal accounts) to be made via PayPal for Events Manager bookings.

Currently by default only the first receiver is set up based on the paypal config settings per the Gateway Admin. To make a chained payment you will need to hook into the em_gateway_paypal_chained_receivers filter to add additional receivers. If you do not do this, PayPal will throw an error.

Example of how to use em_gateway_paypal_chained_receivers hook:

    /**
     * Hook into chained payments plugin to modify Receivers array
     * Pull PP user account email from buddypress profile value for "PayPal Account Email Address"
     */
    function my_em_gateway_paypal_chained_receivers($Receivers, $EM_Booking, $this) {

      // Get paypal account email address for 2nd receiver somehow
      $pp_email = "somebody@test.com";

      // Calculate amount to be chained paid. In this case half the booking price
      $amount = $EM_Booking->get_price(false, false, true) / 2;

      // Configure the 2nd receiver
      $Receiver = array(
        'Amount' => $amount,
        'Email' => $pp_email,
        'InvoiceID' => '',
        'PaymentType' => '',
        'PaymentSubType' => '',
        'Phone' => array('CountryCode' => '', 'PhoneNumber' => '', 'Extension' => ''),
        'Primary' => 'false'
      );
      array_push($Receivers,$Receiver);

      return $Receivers;
    }
    add_filter('em_gateway_paypal_chained_receivers', 'my_em_gateway_paypal_chained_receivers', 10, 3);

There is no need to setup Instant Payment Notifications (IPNs) via your PayPal for Events Manager to detect payments being made. The notify_url is set in the PayPal payment request by this plugin, so PayPal will automatically attempt to send an IPN. It is, however, important that you do not disable IPNs for your PayPal account (turning them off / not configuring them is fine but disabling them will break things).

### Installation

Same as you would any other WordPress plugin