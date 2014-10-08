<?php

class EM_Gateway_Paypal_Chained extends EM_Gateway {
	//change these properties below if creating a new gateway, not advised to change this for PayPal
	var $gateway = 'paypal_chained';
	var $title = 'PayPal Chained Payments';
	var $status = 4;
	var $status_txt = 'Awaiting PayPal Payment';
	var $button_enabled = true;
	var $payment_return = true;
	var $count_pending_spaces = false;
	var $supports_multiple_bookings = true;
	var $payKey;

	/**
	 * Sets up gateaway and adds relevant actions/filters
	 */
	function __construct() {
		//Booking Interception
		if( $this->is_active() && absint(get_option('em_'.$this->gateway.'_booking_timeout')) > 0 ){
				$this->count_pending_spaces = true;
		}
		parent::__construct();
		$this->status_txt = __('Awaiting PayPal Payment','em-pro');
		if($this->is_active()) {
			add_action('em_gateway_js', array(&$this,'em_gateway_js'));
			//Gateway-Specific
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_filter('em_bookings_table_booking_actions_4', array(&$this,'bookings_table_actions'),1,2);
			add_filter('em_my_bookings_booking_actions', array(&$this,'em_my_bookings_booking_actions'),1,2);
			//set up cron
			$timestamp = wp_next_scheduled('emp_paypal_cron');
			if( absint(get_option('em_paypal_booking_timeout')) > 0 && !$timestamp ){
				$result = wp_schedule_event(time(),'em_minute','emp_paypal_cron');
			}elseif( !$timestamp ){
				wp_unschedule_event($timestamp, 'emp_paypal_cron');
			}
		}else{
			//unschedule the cron
			wp_clear_scheduled_hook('emp_paypal_cron');
		}
	}

	/*
	 * --------------------------------------------------
	 * Booking Interception - functions that modify booking object behaviour
	 * --------------------------------------------------
	 */

	/**
	 * Intercepts return data after a booking has been made and adds paypal vars, modifies feedback message.
	 * @param array $return
	 * @param EM_Booking $EM_Booking
	 * @return array
	 */
	function booking_form_feedback( $return, $EM_Booking = false ){
		//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
		if( is_object($EM_Booking) && $this->uses_gateway($EM_Booking) ){
			if( !empty($return['result']) && $EM_Booking->get_price() > 0 && $EM_Booking->booking_status == $this->status ){
				$return['message'] = get_option('em_paypal_booking_feedback');
				$paypal_url = $this->get_paypal_url();
				$paypal_vars = $this->get_paypal_vars($EM_Booking);
				$paypal_return = array('paypal_url'=>$paypal_url, 'paypal_vars'=>$paypal_vars);
				$return = array_merge($return, $paypal_return);
			}else{
				//returning a free message
				$return['message'] = get_option('em_paypal_booking_feedback_free');
			}
		}
		return $return;
	}

	/**
	 * Called if AJAX isn't being used, i.e. a javascript script failed and forms are being reloaded instead.
	 * @param string $feedback
	 * @return string
	 */
	function booking_form_feedback_fallback( $feedback ){
		global $EM_Booking;
		if( is_object($EM_Booking) ){
			$feedback .= "<br />" . __('To finalize your booking, please click the following button to proceed to PayPal.','em-pro'). $this->em_my_bookings_booking_actions('',$EM_Booking);
		}
		return $feedback;
	}

	/**
	 * Triggered by the em_booking_add_yourgateway action, hooked in EM_Gateway. Overrides EM_Gateway to account for non-ajax bookings (i.e. broken JS on site).
	 * @param EM_Event $EM_Event
	 * @param EM_Booking $EM_Booking
	 * @param boolean $post_validation
	 */
	function booking_add($EM_Event, $EM_Booking, $post_validation = false){
		parent::booking_add($EM_Event, $EM_Booking, $post_validation);
		if( !defined('DOING_AJAX') ){ //we aren't doing ajax here, so we should provide a way to edit the $EM_Notices ojbect.
			add_action('option_dbem_booking_feedback', array(&$this, 'booking_form_feedback_fallback'));
		}

    if( get_option('dbem_multiple_bookings') ){
        add_filter('em_multiple_booking_save', array(&$this, 'em_booking_save'),1,2);
    }else{
        add_filter('em_booking_save', array(&$this, 'em_booking_save'),1,2);
    }
	}

  /**
   * Hook into booking save action. If save successful up till now
   * generate the PayPal Pay Key ready for use later. Key generated here instead of
   * in get PayPal Vars so we can throw an error in case of PayKey generation failure.
   *
   * @param bool $result
   * @param EM_Booking $EM_Booking
   */
  function em_booking_save( $result, $EM_Booking ){

  	if( $result ) {

			// Get Adaptive Payments Pay Key
			require_once('lib/angelleye/PayPal/PayPal.php');
			require_once('lib/angelleye/PayPal/Adaptive.php');

			// Create PayPal object.
			$PayPalConfig = array(
				'DeveloperAccountEmail' => get_option('em_'. $this->gateway . "_email" ),
				//'DeviceID' => $device_id,
				'IPAddress' => $_SERVER['REMOTE_ADDR'],
				'APISubject' => '', // If making calls on behalf a third party, their PayPal email address or account ID goes here.
				//'PrintHeaders' => $print_headers,
				'LogResults' => true,                                                  
				'LogPath' => $_SERVER['DOCUMENT_ROOT'].'/logs/',
			);

			// Differing values for Sandbox or Live
			if( get_option('em_'. $this->gateway . "_status" ) == 'test') {
				$PayPalConfig['Sandbox']       = true;
				$PayPalConfig['ApplicationID'] = 'APP-80W284485P519543T';
				$PayPalConfig['APIUsername']   = get_option('em_'. $this->gateway . "_api_sb_username");
				$PayPalConfig['APIPassword']   = get_option('em_'. $this->gateway . "_api_sb_password");
				$PayPalConfig['APISignature']  = get_option('em_'. $this->gateway . "_api_sb_signature");
			}else{
				$PayPalConfig['Sandbox']       = false;
				$PayPalConfig['ApplicationID'] = get_option('em_'. $this->gateway . "_app_id");
				$PayPalConfig['APIUsername']   = get_option('em_'. $this->gateway . "_api_username");
				$PayPalConfig['APIPassword']   = get_option('em_'. $this->gateway . "_api_password");
				$PayPalConfig['APISignature']  = get_option('em_'. $this->gateway . "_api_signature");
			}

			$PayPal = new angelleye\PayPal\Adaptive($PayPalConfig);

			if( $this->get_payment_return_url() ) {
				$return_url = $this->get_payment_return_url();
			}else{
				$return_url = get_permalink(get_option("dbem_my_bookings_page")).'?thanks=1';
			}

			// Optional
			/*
			$ClientDetailsFields = array(
				'CustomerID' => '', 								// Your ID for the sender  127 char max.
				'CustomerType' => '', 								// Your ID of the type of customer.  127 char max.
				'GeoLocation' => '', 								// Sender's geographic location
				'Model' => '', 										// A sub-identification of the application.  127 char max.
				'PartnerName' => ''									// Your organization's name or ID
			);
			*/

			// Funding constraints require advanced permissions levels.
			//$FundingTypes = array('ECHECK', 'BALANCE', 'CREDITCARD');

			$Receivers = array();
			$Receiver = array(
				'Amount' => $EM_Booking->get_price(false, false, true),
				'Email' => 'usb_1329725429_biz@angelleye.com', 												// Receiver's email address. 127 char max.
				'InvoiceID' => '', 											// The invoice number for the payment.  127 char max.
				'PaymentType' => '', 										// Transaction type.  Values are:  GOODS, SERVICE, PERSONAL, CASHADVANCE, DIGITALGOODS
				'PaymentSubType' => '', 									// The transaction subtype for the payment.
				'Phone' => array('CountryCode' => '', 'PhoneNumber' => '', 'Extension' => ''), // Receiver's phone number.   Numbers only.
				'Primary' => 'true'												// Whether this receiver is the primary receiver.  Values are boolean:  TRUE, FALSE
			);
			array_push($Receivers,$Receiver);
/*
			$Receiver = array(
				'Amount' => '50.00', 											// Required.  Amount to be paid to the receiver.
				'Email' => 'sandbo_1215254764_biz@angelleye.com', 												// Receiver's email address. 127 char max.
				'InvoiceID' => '', 											// The invoice number for the payment.  127 char max.
				'PaymentType' => '', 										// Transaction type.  Values are:  GOODS, SERVICE, PERSONAL, CASHADVANCE, DIGITALGOODS
				'PaymentSubType' => '', 									// The transaction subtype for the payment.
				'Phone' => array('CountryCode' => '', 'PhoneNumber' => '', 'Extension' => ''), // Receiver's phone number.   Numbers only.
				'Primary' => 'false'												// Whether this receiver is the primary receiver.  Values are boolean:  TRUE, FALSE
			);
			array_push($Receivers,$Receiver);
*/
			// This is the big one. Hook into this to add or modify Receivers. Without multiple receivers Chained payment request will fail.
			$Receivers = apply_filters('em_gateway_paypal_chained_receivers', $Receivers, $EM_Booking, $this);

			//if( count( $Receivers ) > 1

			// Prepare request arrays, only creating chained payment settings if more than one receiver
			$PayRequestFields = array(
				'ActionType'   => 'PAY_PRIMARY', // Required.  Whether the request pays the receiver or whether the request is set up to create a payment request, but not fulfill the payment until the ExecutePayment is called.  Values are:  PAY, CREATE, PAY_PRIMARY
				'CancelURL'    => '<![CDATA['.get_option('em_'. $this->gateway . "_cancel_return" ).']]>',
				'CurrencyCode' => get_option('dbem_bookings_currency', 'USD'),
				'FeesPayer'    => 'PRIMARYRECEIVER', // The payer of the fees.  Values are:  SENDER, PRIMARYRECEIVER, EACHRECEIVER, SECONDARYONLY
				'IPNNotificationURL' => '<![CDATA['.$this->get_payment_return_url().']]>',
				//'Memo' => '', // A note associated with the payment (text, not HTML).  1000 char max
				//'Pin' => '', // The sener's personal id number, which was specified when the sender signed up for the preapproval
				//'PreapprovalKey' => '',	// The key associated with a preapproval for this payment.  The preapproval is required if this is a preapproved payment.
				'ReturnURL'    => '<![CDATA['.$return_url.']]>', 	// Required. The URL to which the sener's browser is redirected after approvaing a payment on paypal.com.  1024 char max.
				//'ReverseAllParallelPaymentsOnError' => FALSE,	// Whether to reverse paralel payments if an error occurs with a payment.  Values are:  TRUE, FALSE
				'SenderEmail'  => '',
				'TrackingID'   => $EM_Booking->booking_id,
			);
//error_log( print_r( $PayRequestFields ) );


			// Optional
			/*
			$SenderIdentifierFields = array(
				'UseCredentials' => ''						// If TRUE, use credentials to identify the sender.  Default is false.
			);
			*/

			// Optional
			/*
			$AccountIdentifierFields = array(
				'Email' => '', 								// Sender's email address.  127 char max.
				'Phone' => array('CountryCode' => '', 'PhoneNumber' => '', 'Extension' => '')								// Sender's phone number.  Numbers only.
			);
			*/


			$PayPalRequestData = array(
				'PayRequestFields' => $PayRequestFields,
				//'ClientDetailsFields' => $ClientDetailsFields,
				//'FundingTypes' => $FundingTypes,
				'Receivers' => $Receivers,
				//'SenderIdentifierFields' => $SenderIdentifierFields,
				//'AccountIdentifierFields' => $AccountIdentifierFields
			);

			// Add hook for Request Data
			$PayPalRequestData = apply_filters('em_gateway_paypal_chained_paypal_request_data', $PayPalRequestData, $EM_Booking, $this);

//error_log( print_r( $PayPalRequestData, true ) );

			// Pass data into class for processing with PayPal and load the response array into $PayPalResult
			$PayPalResult = $PayPal->Pay($PayPalRequestData);

//error_log( print_r( $PayPalResult, true ) );

			if( $PayPalResult['Ack'] == 'Success') {
				$this->payKey = $PayPalResult['PayKey'];
			}else{
				$EM_Booking->add_error('PayPal Error. Chained Payment Pay Key Generation failure: '.$PayPalResult['Errors'][0]['Message']);

        // If user was created as part of booking save, then remove.
        if( !is_user_logged_in() && get_option('dbem_bookings_anonymous') && !get_option('dbem_bookings_registration_disable') && !empty($EM_Booking->person_id) ){
          //delete the user we just created, only if in last 2 minutes
          $EM_Person = $EM_Booking->get_person();
          if( strtotime($EM_Person->user_registered) >= (current_time('timestamp')-120) ){
            include_once(ABSPATH.'/wp-admin/includes/user.php');
            wp_delete_user($EM_Person->ID);
          }
        }
        //Delete this booking from db
        $EM_Booking->delete();
				return false;
			}
  	}

  	return $result;
  }

	/*
	 * --------------------------------------------------
	 * Booking UI - modifications to booking pages and tables containing paypal bookings
	 * --------------------------------------------------
	 */

	/**
	 * Instead of a simple status string, a resume payment button is added to the status message so user can resume booking from their my-bookings page.
	 * @param string $message
	 * @param EM_Booking $EM_Booking
	 * @return string
	 */
	function em_my_bookings_booking_actions( $message, $EM_Booking){
			global $wpdb;
		if($this->uses_gateway($EM_Booking) && $EM_Booking->booking_status == $this->status){
				//first make sure there's no pending payments
				$pending_payments = $wpdb->get_var('SELECT COUNT(*) FROM '.EM_TRANSACTIONS_TABLE. " WHERE booking_id='{$EM_Booking->booking_id}' AND transaction_gateway='{$this->gateway}' AND transaction_status='Pending'");
				if( $pending_payments == 0 ){
				//user owes money!
				$paypal_vars = $this->get_paypal_vars($EM_Booking);
				$form = '<form action="'.$this->get_paypal_url().'" method="post">';
				foreach($paypal_vars as $key=>$value){
					$form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
				}
				$form .= '<input type="submit" value="'.__('Resume Payment','em-pro').'">';
				$form .= '</form>';
				$message .= $form;
				}
		}
		return $message;
	}

	/**
	 * Outputs extra custom content e.g. the PayPal logo by default.
	 */
	function booking_form(){
		echo get_option('em_'.$this->gateway.'_form');
	}

	/**
	 * Outputs some JavaScript during the em_gateway_js action, which is run inside a script html tag, located in gateways/gateway.paypal.js
	 */
	function em_gateway_js(){
		include(dirname(__FILE__).'/gateway.paypal-chained-payments.js');
	}

	/**
	 * Adds relevant actions to booking shown in the bookings table
	 * @param EM_Booking $EM_Booking
	 */
	function bookings_table_actions( $actions, $EM_Booking ){
		return array(
			'approve' => '<a class="em-bookings-approve em-bookings-approve-offline" href="'.em_add_get_params($_SERVER['REQUEST_URI'], array('action'=>'bookings_approve', 'booking_id'=>$EM_Booking->booking_id)).'">'.__('Approve','dbem').'</a>',
			'delete' => '<span class="trash"><a class="em-bookings-delete" href="'.em_add_get_params($_SERVER['REQUEST_URI'], array('action'=>'bookings_delete', 'booking_id'=>$EM_Booking->booking_id)).'">'.__('Delete','dbem').'</a></span>',
			'edit' => '<a class="em-bookings-edit" href="'.em_add_get_params($EM_Booking->get_event()->get_bookings_url(), array('booking_id'=>$EM_Booking->booking_id, 'em_ajax'=>null, 'em_obj'=>null)).'">'.__('Edit/View','dbem').'</a>',
		);
	}

	/*
	 * --------------------------------------------------
	 * PayPal Functions - functions specific to paypal payments
	 * --------------------------------------------------
	 */

	/**
	 * Retreive the paypal vars needed to send to the gatway to proceed with payment
	 * @param EM_Booking $EM_Booking
	 */
	function get_paypal_vars($EM_Booking){
		global $wp_rewrite, $EM_Notices;

		$paypal_vars = array(
			'cmd' => '_ap-payment',
			'paykey' => $this->payKey,
		);

		return apply_filters('em_gateway_paypal_chained_get_paypal_vars', $paypal_vars, $EM_Booking, $this);


                            

/*

		$notify_url = $this->get_payment_return_url();
		$paypal_vars = array(
			'business' => get_option('em_'. $this->gateway . "_email" ),
			'cmd' => '_cart',
			'upload' => 1,
			'currency_code' => get_option('dbem_bookings_currency', 'USD'),
			'notify_url' =>$notify_url,
			'custom' => $EM_Booking->booking_id.':'.$EM_Booking->event_id,
			'charset' => 'UTF-8'
		);
		if( get_option('em_'. $this->gateway . "_lc" ) ){
				$paypal_vars['lc'] = get_option('em_'. $this->gateway . "_lc" );
		}
		//address fields`and name/email fields to prefill on checkout page (if available)
		$paypal_vars['email'] = $EM_Booking->get_person()->user_email;
		$paypal_vars['first_name'] = $EM_Booking->get_person()->first_name;
		$paypal_vars['last_name'] = $EM_Booking->get_person()->last_name;
		if( EM_Gateways::get_customer_field('address', $EM_Booking) != '' ) $paypal_vars['address1'] = EM_Gateways::get_customer_field('address', $EM_Booking);
		if( EM_Gateways::get_customer_field('address_2', $EM_Booking) != '' ) $paypal_vars['address2'] = EM_Gateways::get_customer_field('address_2', $EM_Booking);
		if( EM_Gateways::get_customer_field('city', $EM_Booking) != '' ) $paypal_vars['city'] = EM_Gateways::get_customer_field('city', $EM_Booking);
		if( EM_Gateways::get_customer_field('state', $EM_Booking) != '' ) $paypal_vars['state'] = EM_Gateways::get_customer_field('state', $EM_Booking);
		if( EM_Gateways::get_customer_field('zip', $EM_Booking) != '' ) $paypal_vars['zip'] = EM_Gateways::get_customer_field('zip', $EM_Booking);
		if( EM_Gateways::get_customer_field('country', $EM_Booking) != '' ) $paypal_vars['country'] = EM_Gateways::get_customer_field('country', $EM_Booking);

		//tax is added regardless of whether included in ticket price, otherwise we can't calculate post/pre tax discounts
		if( $EM_Booking->get_price_taxes() > 0 ){
			$paypal_vars['tax_cart'] = round($EM_Booking->get_price_taxes(), 2);
		}
		if( get_option('em_'. $this->gateway . "_return" ) != "" ){
			$paypal_vars['return'] = get_option('em_'. $this->gateway . "_return" );
		}
		if( get_option('em_'. $this->gateway . "_cancel_return" ) != "" ){
			$paypal_vars['cancel_return'] = get_option('em_'. $this->gateway . "_cancel_return" );
		}
		if( get_option('em_'. $this->gateway . "_format_logo" ) !== false ){
			$paypal_vars['cpp_logo_image'] = get_option('em_'. $this->gateway . "_format_logo" );
		}
		if( get_option('em_'. $this->gateway . "_border_color" ) !== false ){
			$paypal_vars['cpp_cart_border_color'] = get_option('em_'. $this->gateway . "_format_border" );
		}
		$count = 1;
		foreach( $EM_Booking->get_tickets_bookings()->tickets_bookings as $EM_Ticket_Booking ){
				//divide price by spaces for per-ticket price
				//we divide this way rather than by $EM_Ticket because that can be changed by user in future, yet $EM_Ticket_Booking will change if booking itself is saved.
				$price = $EM_Ticket_Booking->get_price() / $EM_Ticket_Booking->get_spaces();
			if( $price > 0 ){
				$paypal_vars['item_name_'.$count] = wp_kses_data($EM_Ticket_Booking->get_ticket()->name);
				$paypal_vars['quantity_'.$count] = $EM_Ticket_Booking->get_spaces();
				$paypal_vars['amount_'.$count] = round($price,2);
				$count++;
			}
		}
		//calculate discounts, if any:
		$discount = $EM_Booking->get_price_discounts_amount('pre') + $EM_Booking->get_price_discounts_amount('post');
		if( $discount > 0 ){
			$paypal_vars['discount_amount_cart'] = $discount;
		}
		return apply_filters('em_gateway_paypal_get_paypal_vars', $paypal_vars, $EM_Booking, $this);







*/




                                    
	}

	/**
	 * gets paypal gateway url (sandbox or live mode)
	 * @returns string
	 */
	function get_paypal_url(){
		return ( get_option('em_'. $this->gateway . "_status" ) == 'test') ? 'https://www.sandbox.paypal.com/webscr':'https://www.paypal.com/webscr';
	}

	function say_thanks(){
		if( !empty($_REQUEST['thanks']) ){
			echo "<div class='em-booking-message em-booking-message-success'>".get_option('em_'.$this->gateway.'_booking_feedback_thanks').'</div>';
		}
	}

	/**
	 * Runs when PayPal sends IPNs to the return URL provided during bookings and EM setup. Bookings are updated and transactions are recorded accordingly.
	 */
	function handle_payment_return() {
		// PayPal IPN handling code
		if ((isset($_POST['payment_status']) || isset($_POST['txn_type'])) && isset($_POST['custom'])) {

				//Verify IPN request
			if (get_option( 'em_'. $this->gateway . "_status" ) == 'live') {
				$domain = 'https://www.paypal.com/cgi-bin/webscr';
			} else {
				$domain = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
			}

			$req = 'cmd=_notify-validate';
			if (!isset($_POST)) $_POST = $HTTP_POST_VARS;
			foreach ($_POST as $k => $v) {
				$req .= '&' . $k . '=' . urlencode(stripslashes($v));
			}

			@set_time_limit(60);

			//add a CA certificate so that SSL requests always go through
			add_action('http_api_curl','EM_Gateway_Paypal::payment_return_local_ca_curl',10,1);
			//using WP's HTTP class
			$ipn_verification_result = wp_remote_get($domain.'?'.$req, array('httpversion', '1.1'));
			remove_action('http_api_curl','EM_Gateway_Paypal::payment_return_local_ca_curl',10,1);

			if ( !is_wp_error($ipn_verification_result) && $ipn_verification_result['body'] == 'VERIFIED' ) {
				//log ipn request if needed, then move on
				EM_Pro::log( $_POST['payment_status']." successfully received for {$_POST['mc_gross']} {$_POST['mc_currency']} (TXN ID {$_POST['txn_id']}) - Custom Info: {$_POST['custom']}", 'paypal');
			}else{
					//log error if needed, send error header and exit
				EM_Pro::log( array('IPN Verification Error', 'WP_Error'=> $ipn_verification_result, '$_POST'=> $_POST, '$req'=>$domain.'?'.$req), 'paypal' );
					header('HTTP/1.0 502 Bad Gateway');
					exit;
			}
			//if we get past this, then the IPN went ok

			// handle cases that the system must ignore
			$new_status = false;
			//Common variables
			$amount = $_POST['mc_gross'];
			$currency = $_POST['mc_currency'];
			$timestamp = date('Y-m-d H:i:s', strtotime($_POST['payment_date']));
			$custom_values = explode(':',$_POST['custom']);
			$booking_id = $custom_values[0];
			$event_id = !empty($custom_values[1]) ? $custom_values[1]:0;
			$EM_Booking = em_get_booking($booking_id);
			if( !empty($EM_Booking->booking_id) && count($custom_values) == 2 ){
				//booking exists
				$EM_Booking->manage_override = true; //since we're overriding the booking ourselves.
				$user_id = $EM_Booking->person_id;

				// process PayPal response
				switch ($_POST['payment_status']) {
					case 'Partially-Refunded':
						break;

					case 'Completed':
					case 'Processed':
						// case: successful payment
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], '');

						if( $_POST['mc_gross'] >= $EM_Booking->get_price() && (!get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval')) ){
							$EM_Booking->approve(true, true); //approve and ignore spaces
						}else{
							//TODO do something if pp payment not enough
							$EM_Booking->set_status(0); //Set back to normal "pending"
						}
						do_action('em_payment_processed', $EM_Booking, $this);
						break;

					case 'Reversed':
						// case: charge back
						$note = 'Last transaction has been reversed. Reason: Payment has been reversed (charge back)';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

						//We need to cancel their booking.
						$EM_Booking->cancel();
						do_action('em_payment_reversed', $EM_Booking, $this);

						break;

					case 'Refunded':
						// case: refund
						$note = 'Last transaction has been reversed. Reason: Payment has been refunded';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);
						if( $EM_Booking->get_price() >= $amount ){
							$EM_Booking->cancel();
						}else{
							$EM_Booking->set_status(0); //Set back to normal "pending"
						}
						do_action('em_payment_refunded', $EM_Booking, $this);
						break;

					case 'Denied':
						// case: denied
						$note = 'Last transaction has been reversed. Reason: Payment Denied';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

						$EM_Booking->cancel();
						do_action('em_payment_denied', $EM_Booking, $this);
						break;

					case 'In-Progress':
					case 'Pending':
						// case: payment is pending
						$pending_str = array(
							'address' => 'Customer did not include a confirmed shipping address',
							'authorization' => 'Funds not captured yet',
							'echeck' => 'eCheck that has not cleared yet',
							'intl' => 'Payment waiting for aproval by service provider',
							'multi-currency' => 'Payment waiting for service provider to handle multi-currency process',
							'unilateral' => 'Customer did not register or confirm his/her email yet',
							'upgrade' => 'Waiting for service provider to upgrade the PayPal account',
							'verify' => 'Waiting for service provider to verify his/her PayPal account',
							'paymentreview' => 'Paypal is currently reviewing the payment and will approve or reject within 24 hours',
							'*' => ''
							);
						$reason = @$_POST['pending_reason'];
						$note = 'Last transaction is pending. Reason: ' . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);

						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $_POST['txn_id'], $_POST['payment_status'], $note);

						do_action('em_payment_pending', $EM_Booking, $this);
						break;

					default:
						// case: various error cases
				}
			}else{
				if( is_numeric($event_id) && is_numeric($booking_id) && ($_POST['payment_status'] == 'Completed' || $_POST['payment_status'] == 'Processed') ){
					$message = apply_filters('em_gateway_paypal_bad_booking_email',"
A Payment has been received by PayPal for a non-existent booking.

Event Details : %event%

It may be that this user's booking has timed out yet they proceeded with payment at a later stage.

In some cases, it could be that other payments not related to Events Manager are triggering this error. If that's the case, you can prevent this from happening by changing the URL in your IPN settings to:

". get_home_url() ."

To refund this transaction, you must go to your PayPal account and search for this transaction:

Transaction ID : %transaction_id%
Email : %payer_email%

When viewing the transaction details, you should see an option to issue a refund.

If there is still space available, the user must book again.

Sincerely,
Events Manager
					", $booking_id, $event_id);
					$EM_Event = new EM_Event($event_id);
					$event_details = $EM_Event->name . " - " . date_i18n(get_option('date_format'), $EM_Event->start);
					$message  = str_replace(array('%transaction_id%','%payer_email%', '%event%'), array($_POST['txn_id'], $_POST['payer_email'], $event_details), $message);
					wp_mail(get_option('em_'. $this->gateway . "_email" ), __('Unprocessed payment needs refund'), $message);
				}else{
					//header('Status: 404 Not Found');
					echo 'Error: Bad IPN request, custom ID does not correspond with any pending booking.';
					//echo "<pre>"; print_r($_POST); echo "</pre>";
					exit;
				}
			}
			//fclose($log);
		} else {
			// Did not find expected POST variables. Possible access attempt from a non PayPal site.
			//header('Status: 404 Not Found');
			echo 'Error: Missing POST variables. Identification is not possible. If you are not PayPal and are visiting this page directly in your browser, this error does not indicate a problem, but simply means EM is correctly set up and ready to receive IPNs from PayPal only.';
			exit;
		}
	}

	/**
	 * Fixes SSL issues with wamp and outdated server installations combined with curl requests by forcing a custom pem file, generated from - http://curl.haxx.se/docs/caextract.html
	 * @param resource $handle
	 */
	public static function payment_return_local_ca_curl( $handle ){
			curl_setopt($handle, CURLOPT_CAINFO, dirname(__FILE__).DIRECTORY_SEPARATOR.'gateway.paypal.pem');
	}

	/*
	 * --------------------------------------------------
	 * Gateway Settings Functions
	 * --------------------------------------------------
	 */

	/**
	 * Outputs custom PayPal setting fields in the settings page
	 */
	function mysettings() {
		global $EM_options;
		?>
		<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('Success Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_booking_feedback" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('The message that is shown to a user when a booking is successful whilst being redirected to PayPal for payment.','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Success Free Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_booking_feedback_free" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_free" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be redirected to PayPal.','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Thank You Message', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_booking_feedback_thanks" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_thanks" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('If you choose to return users to the default Events Manager thank you page after a user has paid on PayPal, you can customize the thank you message here.','em-pro'); ?></em>
				</td>
			</tr>
		</tbody>
		</table>

		<h3><?php echo sprintf(__('%s Options','em-pro'),'PayPal'); ?></h3>
		<p><strong><?php _e('Important:','em-pro'); ?></strong> <?php echo __('In order to connect PayPal with your site, you need to enable IPN on your account.'); echo " ". sprintf(__('Your return url is %s','em-pro'),'<code>'.$this->get_payment_return_url().'</code>'); ?></p>
		<p><?php echo sprintf(__('Please visit the <a href="%s">documentation</a> for further instructions.','em-pro'), 'http://wp-events-plugin.com/documentation/'); ?></p>
		<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Email', 'em-pro') ?></th>
					<td><input type="email" name="paypal_chained_email" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_email" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Paypal Currency', 'em-pro') ?></th>
				<td><?php echo esc_html(get_option('dbem_bookings_currency','USD')); ?><br /><i><?php echo sprintf(__('Set your currency in the <a href="%s">settings</a> page.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options#bookings'); ?></i></td>
			</tr>

			<tr valign="top">
				<th scope="row"><?php _e('PayPal Language', 'em-pro') ?></th>
				<td>
					<select name="paypal_chained_lc">
						<option value=""><?php _e('Default','em-pro'); ?></option>
					<?php
					$ccodes = em_get_countries();
					$paypal_lc = get_option('em_'.$this->gateway.'_lc', 'US');
					foreach($ccodes as $key => $value){
						if( $paypal_lc == $key ){
							echo '<option value="'.$key.'" selected="selected">'.$value.'</option>';
						}else{
							echo '<option value="'.$key.'">'.$value.'</option>';
						}
					}
					?>
					</select>
					<br />
					<i><?php _e('PayPal allows you to select a default language users will see. This is also determined by PayPal which detects the locale of the users browser. The default would be US.','em-pro') ?></i>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Adaptive Payments Application ID', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_app_id" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_app_id" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Mode', 'em-pro') ?></th>
				<td>
					<select name="paypal_chained_status">
						<option value="live" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'live') echo 'selected="selected"'; ?>><?php _e('Live Site', 'em-pro') ?></option>
						<option value="test" <?php if (get_option('em_'. $this->gateway . "_status" ) == 'test') echo 'selected="selected"'; ?>><?php _e('Test Mode (Sandbox)', 'em-pro') ?></option>
					</select>
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal API Username', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_api_username" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_username" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal API Password', 'em-pro') ?></th>
					<td><input type="password" name="paypal_chained_api_password" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_password" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal API Signature', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_api_signature" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_signature" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Sandbox API Username', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_api_sb_username" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_sb_username" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Sandbox API Password', 'em-pro') ?></th>
					<td><input type="password" name="paypal_chained_api_sb_password" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_sb_password" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Sandbox API Signature', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_api_sb_signature" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_api_sb_signature" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Return URL', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_return" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('Once a payment is completed, users will be offered a link to this URL which confirms to the user that a payment is made. If you would to customize the thank you page, create a new page and add the link here. For automatic redirect, you need to turn auto-return on in your PayPal settings.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Cancel URL', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_cancel_return" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_cancel_return" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('Whilst paying on PayPal, if a user cancels, they will be redirected to this page.', 'em-pro'); ?></em>
				</td>
			</tr>
			<?php /*
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Page Logo', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_format_logo" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_format_logo" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('Add your logo to the PayPal payment page. It\'s highly recommended you link to a https:// address.', 'em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Border Color', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_format_border" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_format_border" )); ?>" style='width: 40em;' /><br />
					<em><?php _e('Provide a hex value color to change the color from the default blue to another color (e.g. #CCAAAA).','em-pro'); ?></em>
				</td>
			</tr>
			*/ ?>
			<tr valign="top">
				<th scope="row"><?php _e('Delete Bookings Pending Payment', 'em-pro') ?></th>
				<td>
					<input type="text" name="paypal_chained_booking_timeout" style="width:50px;" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_timeout" )); ?>" style='width: 40em;' /> <?php _e('minutes','em-pro'); ?><br />
					<em><?php _e('Once a booking is started and the user is taken to PayPal, Events Manager stores a booking record in the database to identify the incoming payment. These spaces may be considered reserved if you enable <em>Reserved unconfirmed spaces?</em> in your Events &gt; Settings page. If you would like these bookings to expire after x minutes, please enter a value above (note that bookings will be deleted, and any late payments will need to be refunded manually via PayPal).','em-pro'); ?></em>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
				<td>
					<input type="checkbox" name="paypal_chained_manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
					<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
					<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
				</td>
			</tr>
		</tbody>
		</table>
		<?php
	}

	/*
	 * Run when saving PayPal settings, saves the settings available in EM_Gateway_Paypal::mysettings()
	 */
	function update() {
		parent::update();
		$gateway_options = array(
			$this->gateway . "_email" => $_REQUEST[ $this->gateway.'_email' ],
			$this->gateway . "_app_id" => $_REQUEST[ $this->gateway.'_app_id' ],
			$this->gateway . "_site" => $_REQUEST[ $this->gateway.'_site' ],
			$this->gateway . "_api_username" => $_REQUEST[ $this->gateway.'_api_username' ],
			$this->gateway . "_api_password" => $_REQUEST[ $this->gateway.'_api_password' ],
			$this->gateway . "_api_signature" => $_REQUEST[ $this->gateway.'_api_signature' ],
			$this->gateway . "_api_sb_username" => $_REQUEST[ $this->gateway.'_api_sb_username' ],
			$this->gateway . "_api_sb_password" => $_REQUEST[ $this->gateway.'_api_sb_password' ],
			$this->gateway . "_api_sb_signature" => $_REQUEST[ $this->gateway.'_api_sb_signature' ],
			$this->gateway . "_currency" => $_REQUEST[ 'currency' ],
			$this->gateway . "_lc" => $_REQUEST[ $this->gateway.'_lc' ],
			$this->gateway . "_status" => $_REQUEST[ $this->gateway.'_status' ],
			$this->gateway . "_tax" => $_REQUEST[ $this->gateway.'_button' ],
			//$this->gateway . "_format_logo" => $_REQUEST[ $this->gateway.'_format_logo' ],
			//$this->gateway . "_format_border" => $_REQUEST[ $this->gateway.'_format_border' ],
			$this->gateway . "_manual_approval" => $_REQUEST[ $this->gateway.'_manual_approval' ],
			$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback' ]),
			$this->gateway . "_booking_feedback_free" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_free' ]),
			$this->gateway . "_booking_feedback_thanks" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_thanks' ]),
			$this->gateway . "_booking_timeout" => $_REQUEST[ $this->gateway.'_booking_timeout' ],
			$this->gateway . "_return" => $_REQUEST[ $this->gateway.'_return' ],
			$this->gateway . "_cancel_return" => $_REQUEST[ $this->gateway.'_cancel_return' ],
			$this->gateway . "_form" => $_REQUEST[ $this->gateway.'_form' ]
		);
		foreach($gateway_options as $key=>$option){
			update_option('em_'.$key, stripslashes($option));
		}
		//default action is to return true
		return true;

	}
}
EM_Gateways::register_gateway('paypal_chained', 'EM_Gateway_Paypal_Chained');

/**
 * Deletes bookings pending payment that are more than x minutes old, defined by paypal options.
 */
function em_gateway_paypal_chained_booking_timeout(){
	global $wpdb;
	//Get a time from when to delete
	$minutes_to_subtract = absint(get_option('em_paypal_booking_timeout'));
	if( $minutes_to_subtract > 0 ){
		//get booking IDs without pending transactions
		$cut_off_time = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes_to_subtract * 60));
		$booking_ids = $wpdb->get_col('SELECT b.booking_id FROM '.EM_BOOKINGS_TABLE.' b LEFT JOIN '.EM_TRANSACTIONS_TABLE." t ON t.booking_id=b.booking_id  WHERE booking_date < '{$cut_off_time}' AND booking_status=4 AND transaction_id IS NULL" );
		if( count($booking_ids) > 0 ){
			//first delete ticket_bookings with expired bookings
			$sql = "DELETE FROM ".EM_TICKETS_BOOKINGS_TABLE." WHERE booking_id IN (".implode(',',$booking_ids).");";
			$wpdb->query($sql);
			//then delete the bookings themselves
			$sql = "DELETE FROM ".EM_BOOKINGS_TABLE." WHERE booking_id IN (".implode(',',$booking_ids).");";
			$wpdb->query($sql);
		}
	}
}
add_action('emp_paypal_cron', 'em_gateway_paypal_chained_booking_timeout');
?>