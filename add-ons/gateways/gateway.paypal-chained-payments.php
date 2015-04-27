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
		add_action('admin_enqueue_scripts', array(&$this,'gateway_admin_js'));

		if($this->is_active()) {
			add_action('em_gateway_js', array(&$this,'em_gateway_js'));
			//Gateway-Specific
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_filter('em_bookings_table_booking_actions_4', array(&$this,'bookings_table_actions'),1,2);
			//add_filter('em_my_bookings_booking_actions', array(&$this,'em_my_bookings_booking_actions'),1,2);

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

			$PayPalResult = $this->paypal_pre_approval( $EM_Booking );

			if( $PayPalResult['Ack'] == 'Success') {
				$this->payKey = $PayPalResult['PayKey'];
			}else{
				$EM_Booking->add_error('PayPal Error. Chained Payment Pay Key Generation failure: '.$PayPalResult['Errors'][0]['Message'].' ('.$PayPalResult['Errors'][0]['ErrorID'].')');

				// If user was created as part of booking save, then remove.
				if( !is_user_logged_in() && get_option('dbem_bookings_anonymous') && !get_option('dbem_bookings_registration_disable') && !empty($EM_Booking->person_id) ){

					//delete the user we just created, only if in last 2 minutes
					$EM_Person = $EM_Booking->get_person();
					if( strtotime($EM_Person->user_registered) >= (current_time('timestamp', 1)-120) ){
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

				$pay_pal_result = $this->paypal_pre_approval( $EM_Booking );

				if( $pay_pal_result['Ack'] == 'Success') {
					$this->payKey = $pay_pal_result['PayKey'];
					$paypal_vars = $this->get_paypal_vars($EM_Booking);
					$form = '<form action="'.$this->get_paypal_url().'" method="post">';
					foreach($paypal_vars as $key=>$value){
						$form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
					}
					$form .= '<input type="submit" value="'.__('Resume Payment','em-pro').'">';
					$form .= '</form>';
					$message .= $form;
				}else{
					$message.='Unable to create PayPal Pay Button: '.$pay_pal_result['Errors'][0]['Message'];
				}
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
	}


	/**
	 * Perform PayPal Pre Approval request and return the result.
	 * Prepare all config vars for PayPal Request
	 * @params $EM_Booking
	 * @returns array $PayPalResponse
	 */
	function paypal_pre_approval( $EM_Booking ) {
		// Get Adaptive Payments Pay Key
		require_once('lib/angelleye/PayPal/PayPal.php');
		require_once('lib/angelleye/PayPal/Adaptive.php');

		// Create PayPal object.
		$PayPalConfig = array(
			//'DeviceID' => $device_id,
			'IPAddress' => $_SERVER['REMOTE_ADDR'],
			'APISubject' => '', // If making calls on behalf a third party, their PayPal email address or account ID goes here.
			//'PrintHeaders' => $print_headers,
			'LogResults' => false,
			'LogPath' => $_SERVER['DOCUMENT_ROOT'].'/logs/',
		);

		// Differing values for Sandbox or Live
		if( get_option('em_'. $this->gateway . "_status" ) == 'test') {
			$PayPalConfig['DeveloperAccountEmail'] = get_option('em_'. $this->gateway . "_dev_email" );
			$PayPalConfig['LogResults']    = true;
			$PayPalConfig['Sandbox']       = true;
			$PayPalConfig['ApplicationID'] = 'APP-80W284485P519543T';
			$PayPalConfig['APIUsername']   = get_option('em_'. $this->gateway . "_api_sb_username");
			$PayPalConfig['APIPassword']   = get_option('em_'. $this->gateway . "_api_sb_password");
			$PayPalConfig['APISignature']  = get_option('em_'. $this->gateway . "_api_sb_signature");
			$pp_account_email = get_option('em_'. $this->gateway . "_sb_email");
		}else{
			$PayPalConfig['Sandbox']       = false;
			$PayPalConfig['ApplicationID'] = get_option('em_'. $this->gateway . "_app_id");
			$PayPalConfig['APIUsername']   = get_option('em_'. $this->gateway . "_api_username");
			$PayPalConfig['APIPassword']   = get_option('em_'. $this->gateway . "_api_password");
			$PayPalConfig['APISignature']  = get_option('em_'. $this->gateway . "_api_signature");
			$pp_account_email = get_option('em_'. $this->gateway . "_email");
		}

		$PayPal = new angelleye\PayPal\Adaptive($PayPalConfig);

		if( get_option('em_'. $this->gateway . "_return") ) {
			$return_url = get_option('em_'. $this->gateway . "_return");
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
			'Email' => $pp_account_email,
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

		// Filter to allow fees payer setting to be modified on booking by booking basis
		$fees_payer = apply_filters('em_gateway_paypal_chained_fees_payer', get_option('em_'. $this->gateway . "_fees_payer"), $Receivers, $EM_Booking, $this);

		// Prepare request arrays, only creating chained payment settings if more than one receiver
		$PayRequestFields = array(
			'ActionType'   => 'PAY', // Required.  Whether the request pays the receiver or whether the request is set up to create a payment request, but not fulfill the payment until the ExecutePayment is called.  Values are:  PAY, CREATE, PAY_PRIMARY
			'CancelURL'    => '<![CDATA['.get_option('em_'. $this->gateway . "_cancel_return" ).']]>',
			'CurrencyCode' => get_option('dbem_bookings_currency', 'USD'),
			'FeesPayer'    => $fees_payer,
			'IPNNotificationURL' => '<![CDATA['.$this->get_payment_return_url().']]>',
			'Memo' => '<![CDATA[Booking for '.$EM_Booking->get_event()->event_name.']]>',
			//'Pin' => '', // The sener's personal id number, which was specified when the sender signed up for the preapproval
			//'PreapprovalKey' => '',	// The key associated with a preapproval for this payment.  The preapproval is required if this is a preapproved payment.
			'ReturnURL'    => '<![CDATA['.$return_url.']]>', 	// Required. The URL to which the sener's browser is redirected after approvaing a payment on paypal.com.  1024 char max.
			//'ReverseAllParallelPaymentsOnError' => FALSE,	// Whether to reverse paralel payments if an error occurs with a payment.  Values are:  TRUE, FALSE
			'SenderEmail'  => '',
			'TrackingID'   => $EM_Booking->booking_id,
		);


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

		return $PayPalResult;
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

	/*
	 * Overide parent return url with value for rewrite rule defined in EM_Pro_PayPal_Chained.php
	 * This is to prevent apache's mod_security blocking a POST request with GET vars in the url.
	 */
	function get_payment_return_url() {
		return get_site_url()."/pp-chained-ipn";
	}

	/**
	 * Runs when PayPal sends IPNs to the return URL provided during bookings and EM setup.
	 * Bookings are updated and transactions are recorded accordingly.
	 */
	function handle_payment_return() {

		// Read POST data
		// reading posted data directly from $_POST causes serialization issues with
		// array data in POST. Reading raw POST data from input stream instead.
		$raw_post_data = file_get_contents('php://input');
		$post = $this->decodePayPalIPN( $raw_post_data );

		// PayPal IPN handling code
		if ((isset($post['status']) || isset($post['transaction_type'])) && isset($post['tracking_id'])) {

			//Verify IPN request
			if (get_option( 'em_'. $this->gateway . "_status" ) == 'live') {
				$domain = 'https://www.paypal.com/cgi-bin/webscr';
			} else {
				$domain = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
			}

			$req = 'cmd=_notify-validate&'.$raw_post_data;

			@set_time_limit(60);

			//add a CA certificate so that SSL requests always go through
			add_action('http_api_curl','EM_Gateway_Paypal_Chained::payment_return_local_ca_curl',10,1);
			//using WP's HTTP class
			$ipn_verification_result = wp_remote_get($domain.'?'.$req, array('httpversion', '1.1'));
			remove_action('http_api_curl','EM_Gateway_Paypal_Chained::payment_return_local_ca_curl',10,1);

			if ( !is_wp_error($ipn_verification_result) && $ipn_verification_result['body'] == 'VERIFIED' ) {
				//log ipn request if needed, then move on
				EM_Pro::log( $post['transaction_type']." successfully received for {$post['transaction'][0]['amount']} (TXN ID {$post['transaction'][0]['id']}) - Booking: {$post['tracking_id']}", 'paypal_chained');
			}else{
					//log error if needed, send error header and exit
				EM_Pro::log( array('IPN Verification Error', 'WP_Error'=> $ipn_verification_result, '$_POST'=> $post, '$req'=>$domain.'?'.$req), 'paypal_chained' );
				header('HTTP/1.0 502 Bad Gateway');
				exit;
			}
			//if we get past this, then the IPN went ok

			// handle cases that the system must ignore

			//Common variables
			$primary_transaction = null;

			// Locate primary transaction:
			foreach( $post['transaction'] as $transaction ) {
				if( $transaction['is_primary_receiver'] ) {
					$primary_transaction = $transaction;
					break;
				}
			}
			// We're interested in the primary receiver transaction as that is the main payment for the booking
			// Any subsequent receivers is just the money being distributed based on the the em_gateway_paypal_chained_receivers hook
			// As we don't know what they could be we won't try to save that information

			$currency_amount = explode(' ', $primary_transaction['amount']);
			$amount = $currency_amount[1];
			$currency = $currency_amount[0];

			$timestamp = date( 'Y-m-d H:i:s', strtotime( $post['payment_request_date'] ) );
			$booking_id = $post['tracking_id'];
			$EM_Booking = em_get_booking($booking_id);

			if( !empty($EM_Booking->booking_id) ){
				//booking exists
				$EM_Booking->manage_override = true; //since we're overriding the booking ourselves.
				$user_id = $EM_Booking->person_id;

				// process PayPal response
				switch ($primary_transaction['status']) {

					case 'Completed':
						// case: successful payment
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $primary_transaction['id'], $primary_transaction['status'], '');

						if( $amount >= $EM_Booking->get_price() && (!get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval')) ){
							$EM_Booking->approve(true, true); //approve and ignore spaces
						}else{
							//TODO do something if pp payment not enough
							$EM_Booking->set_status(0); //Set back to normal "pending"
						}
						do_action('em_payment_processed', $EM_Booking, $this);
						break;

					case 'Error':
						$note = 'The payment failed and all attempted transfers failed or all completed transfers were successfully reversed';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $primary_transaction['id'], $primary_transaction['status'], $note);

						$EM_Booking->cancel();
						do_action('em_payment_denied', $EM_Booking, $this);
						break;

					case 'Processing':
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
						$reason = @$primary_transaction['pending_reason'];
						$note = 'Last transaction is pending. Reason: ' . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);

						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $primary_transaction['id'], $primary_transaction['status'], $note);

						do_action('em_payment_pending', $EM_Booking, $this);
						break;


					case 'Reversed':
						// case: charge back
						$note = 'Last transaction has been reversed. Reason: Payment has been reversed (charge back)';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $primary_transaction['id'], $primary_transaction['status'], $note);

						//We need to cancel their booking.
						$EM_Booking->cancel();
						do_action('em_payment_reversed', $EM_Booking, $this);
						break;

					case 'Refunded':
						// case: refund
						$note = 'Last transaction has been reversed. Reason: Payment has been refunded';
						$this->record_transaction($EM_Booking, $amount, $currency, $timestamp, $primary_transaction['id'], $primary_transaction['status'], $note);
						if( $EM_Booking->get_price() >= $amount ){
							$EM_Booking->cancel();
						}else{
							$EM_Booking->set_status(0); //Set back to normal "pending"
						}
						do_action('em_payment_refunded', $EM_Booking, $this);
						break;


					default:
						// case: various error cases
						// https://developer.paypal.com/docs/classic/api/adaptive-payments/PaymentDetails_API_Operation/
				}
			}else{
				if( is_numeric($booking_id) && $primary_transaction['status'] == 'Completed' ) {
					$message = apply_filters('em_gateway_paypal_chained_bad_booking_email',"
A Payment has been received by PayPal for a non-existent booking.

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
					", $booking_id );
					$message  = str_replace(array('%transaction_id%','%payer_email%'), array($primary_transaction['id'], $post['sender_email']), $message);
					wp_mail(get_option('em_'. $this->gateway . "_email" ), __('Unprocessed payment needs refund'), $message);
				}else{
					//header('Status: 404 Not Found');
					$error = 'Error: Bad IPN request, custom ID does not correspond with any pending booking.';
					echo $error;
					error_log( $error );
					exit;
				}
			}
			//fclose($log);
		} else {
			// Did not find expected POST variables. Possible access attempt from a non PayPal site.
			//header('Status: 404 Not Found');
			echo 'Error: Missing POST variables. Identification is not possible. If you are not PayPal and are visiting this page directly in your browser, this error does not indicate a problem, but simply means EM is correctly set up and ready to receive IPNs from PayPal only.';
			error_log('PayPal Chained IPN error: Missing POST variables. Identification is not possible.');
			exit;
		}
	}

	/**
	 * Adaptive payments IPN post data comes in a format that PHP struggles with
	 * decodePayPalIPN tries to make sense of it.
	 * http://enjoysmile.com/blog/24/paypal-adaptive-payments-and-ipn-part-two/
	 */
	function decodePayPalIPN( $raw_post ) {
		if (empty($raw_post)) {
			return array();
		} // else:
		$post = array();
		$pairs = explode('&', $raw_post);
		foreach ($pairs as $pair) {
			list($key, $value) = explode('=', $pair, 2);
			$key = urldecode($key);
			$value = urldecode($value);
			// This is look for a key as simple as 'return_url' or as complex as 'somekey[x].property'
			preg_match('/(\w+)(?:\[(\d+)\])?(?:\.(\w+))?/', $key, $key_parts);
			switch (count($key_parts)) {
				case 4:
					// Original key format: somekey[x].property
					// Converting to $post[somekey][x][property]
					if (!isset($post[$key_parts[1]])) {
						$post[$key_parts[1]] = array($key_parts[2] => array($key_parts[3] => $value));
					} else if (!isset($post[$key_parts[1]][$key_parts[2]])) {
						$post[$key_parts[1]][$key_parts[2]] = array($key_parts[3] => $value);
					} else {
						$post[$key_parts[1]][$key_parts[2]][$key_parts[3]] = $value;
					}
					break;
				case 3:
					// Original key format: somekey[x]
					// Converting to $post[somkey][x]
					if (!isset($post[$key_parts[1]])) {
						$post[$key_parts[1]] = array();
					}
					$post[$key_parts[1]][$key_parts[2]] = $value;
					break;
				default:
					// No special format
					$post[$key] = $value;
					break;
			}//switch
		}//foreach

		return $post;
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

		<table class="form-table">
		<tbody>
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
		</tbody>
	</table>
	<h3 class="live-settings"><?php _e('Live Settings','em-pro'); ?></h3>
	<table class="form-table live-settings">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Email', 'em-pro') ?></th>
					<td><input type="email" name="paypal_chained_email" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_email" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Adaptive Payments Application ID', 'em-pro') ?></th>
					<td><input type="text" name="paypal_chained_app_id" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_app_id" )); ?>" />
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
		</tbody>
	</table>
	<h3 class="sandbox-settings"><?php _e('Sandbox Settings','em-pro'); ?></h3>
	<table class="form-table sandbox-settings">
		<tbody>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Developer Email', 'em-pro') ?></th>
					<td><input type="email" name="paypal_chained_dev_email" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_dev_email" )); ?>" />
					<br />
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('PayPal Sandbox Account Email', 'em-pro') ?></th>
					<td><input type="email" name="paypal_chained_sb_email" value="<?php esc_attr_e( get_option('em_'. $this->gateway . "_sb_email" )); ?>" />
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
		</tbody>
	</table>

	<h3><?php _e('Common Settings','em-pro'); ?></h3>
	<table class="form-table">
		<tbody>

			<tr valign="top">
				<th scope="row"><?php _e('Paypal Currency', 'em-pro') ?></th>
				<td><?php echo esc_html(get_option('dbem_bookings_currency','USD')); ?><br /><i><?php echo sprintf(__('Set your currency in the <a href="%s">settings</a> page.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options#bookings'); ?></i></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Fees Payer', 'em-pro') ?></th>
				<td>
					<select name="paypal_chained_fees_payer">
						<?php
							$fee_options = array(
								"SENDER" => "Sender",
								"PRIMARYRECEIVER" => "Primary Receiver",
								"EACHRECEIVER" => "Each Receiver",
								"SECONDARYONLY" => "Secondary Only",
							);
							$curr_fee_payer_val = get_option('em_'. $this->gateway . "_fees_payer" );
						?>
						<?php foreach( $fee_options as $val => $label ): ?>
							<option value="<?php echo $val ?>" <?php echo ( $val == $curr_fee_payer_val ? 'selected="selected"' :'') ?>>
								<?php echo $label ?>
							</option>
						<?php endforeach; ?>
					</select>
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
			$this->gateway . "_dev_email" => $_REQUEST[ $this->gateway.'_dev_email' ],
			$this->gateway . "_app_id" => $_REQUEST[ $this->gateway.'_app_id' ],
			$this->gateway . "_site" => $_REQUEST[ $this->gateway.'_site' ],
			$this->gateway . "_email" => $_REQUEST[ $this->gateway.'_email' ],
			$this->gateway . "_api_username" => $_REQUEST[ $this->gateway.'_api_username' ],
			$this->gateway . "_api_password" => $_REQUEST[ $this->gateway.'_api_password' ],
			$this->gateway . "_api_signature" => $_REQUEST[ $this->gateway.'_api_signature' ],
			$this->gateway . "_sb_email" => $_REQUEST[ $this->gateway.'_sb_email' ],
			$this->gateway . "_api_sb_username" => $_REQUEST[ $this->gateway.'_api_sb_username' ],
			$this->gateway . "_api_sb_password" => $_REQUEST[ $this->gateway.'_api_sb_password' ],
			$this->gateway . "_api_sb_signature" => $_REQUEST[ $this->gateway.'_api_sb_signature' ],
			$this->gateway . "_fees_payer" => $_REQUEST[ $this->gateway.'_fees_payer' ],
			$this->gateway . "_currency" => $_REQUEST[ 'currency' ],
			$this->gateway . "_status" => $_REQUEST[ $this->gateway.'_status' ],
			$this->gateway . "_manual_approval" => $_REQUEST[ $this->gateway.'_manual_approval' ],
			$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback' ]),
			$this->gateway . "_booking_feedback_free" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_free' ]),
			$this->gateway . "_booking_feedback_thanks" => wp_kses_data($_REQUEST[ $this->gateway.'_booking_feedback_thanks' ]),
			$this->gateway . "_booking_timeout" => $_REQUEST[ $this->gateway.'_booking_timeout' ],
			$this->gateway . "_return" => $_REQUEST[ $this->gateway.'_return' ],
			$this->gateway . "_cancel_return" => $_REQUEST[ $this->gateway.'_cancel_return' ],
		);
		foreach($gateway_options as $key=>$option){
			update_option('em_'.$key, stripslashes($option));
		}
		//default action is to return true
		return true;

	}

  /**
   * Load Custom js for gateway admin
   */
	function gateway_admin_js($hook) {
		if ( $hook == 'event_page_events-manager-gateways' ) {
			wp_enqueue_script( 'netbanx_gateway_admin', plugin_dir_url( __FILE__ ) . '/gateway.paypal-chained-payments-admin.js' );
		}
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
