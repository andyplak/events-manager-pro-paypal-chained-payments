//add paypal redirection
$(document).bind('em_booking_gateway_add_paypal_chained', function(event, response){

	// called by EM if return JSON contains gateway key, notifications messages are shown by now.
	if(response.result){
		var ppForm = $('<form action="'+response.paypal_url+'" method="get" id="em-paypal-redirect-form"></form>');
		$.each( response.paypal_vars, function(index,value){
			ppForm.append('<input type="hidden" name="'+index+'" value="'+value+'" />');
		});
		ppForm.append('<input id="em-paypal-chained-submit" type="submit" style="display:hidden" />');
		ppForm.appendTo('body').trigger('submit');
	}
});