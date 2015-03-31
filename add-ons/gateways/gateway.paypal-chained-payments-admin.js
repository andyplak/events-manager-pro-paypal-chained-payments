jQuery(document).ready(function($){

  if( $("select[name=paypal_chained_status]").val() == "live" ) {
    $('.sandbox-settings').hide();
  }else{
    $('.live-settings').hide();
  }

  $("select[name=paypal_chained_status]").change( function() {
    if( $(this).val() == "live" ) {
      $('.live-settings').show();
      $('.sandbox-settings').hide();
    }else{
      $('.sandbox-settings').show();
      $('.live-settings').hide();
    }
  });
});