=== Plugin Name ===
Contributors: socarrat
Tags: Events Manager, Events Manager Pro, PayPal, Chained Payments
Requires at least: 3.0.1
Tested up to: 4.0
Stable tag: 1.0

PayPal Payment Gateway for Events Manager with Chained Payments

== Description ==

Allows chained payments (funds split between different paypal accounts) to be made via PayPal for Events Manager bookings.

Currently by default only the first receiver is set up based on the paypal config settings per the Gateway Admin. To make a chained payment you will need to hook into the em_gateway_paypal_chained_receivers filter to add additional receivers. If you do not do this, PayPal will throw an error.

== Installation ==

Same as you would any other WordPress plugin