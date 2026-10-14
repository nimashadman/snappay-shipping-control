( function ( $ ) {
	'use strict';

	$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
}( jQuery ) );
