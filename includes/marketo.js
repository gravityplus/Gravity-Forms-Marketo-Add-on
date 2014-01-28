/* Gravity Forms Marketo Add-On
 * 
 * @since 1.3.7.1
 *
 * Author: Katz Web Services, Inc.
 */
 
(function( $ ) {

	$(document).ready(function() {
	
		if( Munchkin !== undefined ) {
			Munchkin.init( marketo_vars.munchkin_id );
		}
	
	});// document ready end

	
 
}(jQuery));
