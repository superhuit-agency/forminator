/**********
 * Common functions
 *
 ***********/
class forminatorFrontUtils {

	constructor() {}

	field_is_checkbox($element) {
		var is_checkbox = false;
		$element.each(function () {
			if (jQuery(this).attr('type') === 'checkbox') {
				is_checkbox = true;
				//break
				return false;
			}
		});

		return is_checkbox;
	}

	field_is_radio($element) {
		var is_radio = false;
		$element.each(function () {
			if (jQuery(this).attr('type') === 'radio') {
				is_radio = true;
				//break
				return false;
			}
		});

		return is_radio;
	}

	field_is_select($element) {
		return $element.is('select');
	}

	field_has_inputMask( $element ) {
		var hasMask = false;

		$element.each(function () {
			if ( undefined !== jQuery( this ).attr( 'data-inputmask' ) ) {
				hasMask = true;
				//break
				return false;
			}
		});

		return hasMask;
	}

	get_field_value( $element ) {
		var value       = 0;
		var calculation = 0;
		var checked     = null;

		if (this.field_is_radio($element)) {
			checked = $element.filter(":checked");
			if (checked.length) {
				calculation = checked.data('calculation');
				if (calculation !== undefined) {
					value = Number(calculation);
				}
			}
		} else if (this.field_is_checkbox($element)) {
			$element.each(function () {
				if (jQuery(this).is(':checked')) {
					calculation = jQuery(this).data('calculation');
					if (calculation !== undefined) {
						value += Number(calculation);
					}
				}
			});

		} else if (this.field_is_select($element)) {
			checked = $element.find("option").filter(':selected');
			if (checked.length) {
				calculation = checked.data('calculation');
				if (calculation !== undefined) {
					value = Number(calculation);
				}
			}
		} else if ( this.field_has_inputMask( $element ) ) {
			value = parseFloat( $element.inputmask('unmaskedvalue').replace(',','.') );
		} else if ( $element.length ) {
			var number = $element.val();
			value = parseFloat( number.replace(',','.') );
		}

		return isNaN(value) ? 0 : value;
	}

	show_hide_custom_input( selector, field_type ) {
		if( ! selector ) {
			return;
		}
		let $elements = null;
		if( field_type === 'select2' || field_type === 'select' ) {
			$elements = jQuery( selector );
		} else {
			$elements = jQuery( selector ).closest( '.forminator-field' ).find( 'input[type="checkbox"]:checked, input[type="radio"]:checked' );
		}

		if( ! $elements.length ) {
			// If no elements found, hide all custom inputs.
			jQuery( selector ).closest( '.forminator-field' ).find( '.forminator-custom-input' ).hide();
			return;
		}

		$elements.each( function() {
			if( jQuery( this ).val() && jQuery( this ).val().includes( 'custom_option' ) ) {
				// Display custom option input.
				jQuery( this ).closest( '.forminator-field' ).find( '.forminator-custom-input' ).show();
			} else {
				// Hide custom option input.
				jQuery( this ).closest( '.forminator-field' ).find( '.forminator-custom-input' ).hide();
			}
		});
	}

	// Add error message.
	add_error_message($description, $column, errorMarkup) {
		// If the description is empty or description placement is above input.
		if ( 0 === $description.length || $description.next().length > 0 ) {
			// Append the error markup to the column.
			$column.append( errorMarkup );
		} else {
			// Otherwise, insert the error markup before the description.
			jQuery( errorMarkup ).insertBefore( $description );
		}
	}

	// Get the dial code (without "+") for a phone field, or "" if it doesn't apply.
	get_phone_dial_code( $element ) {
		if ( $element.data( 'national_mode' ) === 'enabled' ) {
			return '';
		}
		if ( typeof window.intlTelInput === 'undefined' ) {
			return '';
		}
		var iti = window.intlTelInput.getInstance( $element[0] );
		return ( iti && iti.getSelectedCountryData().dialCode ) || '';
	}

	is_hidden( $element_id ) {
		const $column_field = $element_id.closest('.forminator-col'),
			$group_field = $element_id.closest('.forminator-field-group'),
			$pagination_field = $element_id.closest('.forminator-pagination'),
			$address_field = $element_id.closest('.forminator-field-address'),
			$name_field = $element_id.closest('.forminator-field-name'),
			$row_field = $column_field.closest('.forminator-row'),
			$time_field  = $element_id.closest('.forminator-field-time')
		;

		if( $column_field.hasClass("forminator-hidden-calculator") ) {
			// If it's a calculation field with isHidden option - it means it's not hidden by visibility conditions. Always return false.
			return false;
		}

		if( $row_field.hasClass("forminator-hidden") || $column_field.hasClass("forminator-hidden") ) {
			return true;
		}

		if( ( $group_field.length > 0 && $group_field.hasClass( 'forminator-hidden' ) )
			|| ( $address_field.length > 0 && $address_field.hasClass( 'forminator-hidden' ) )
			|| ( $name_field.length > 0 && $name_field.hasClass( 'forminator-hidden' ) ) ) {
			return true;
		}

		if( $pagination_field.length > 0 && $pagination_field.hasClass( 'forminator-page-hidden' ) ) {
			return true;
		}

		if( $time_field.length > 0  && $time_field.hasClass( 'forminator-hidden' ) ) {
			return true;
		}

		return false;
	}

	handle_authentication_error( data, self, formData ) {
		if ( typeof data !== 'undefined' &&
			typeof data.data !== 'undefined' &&
			typeof data.data.authentication !== 'undefined' &&
		   ( 'show' === data.data.authentication || 'invalid' === data.data.authentication ) ) {
		   var moduleId  = self.$el.attr( 'id' ),
			   authId    = moduleId + '-authentication',
			   authField = jQuery( '#' + authId ),
			   authInput = jQuery( '#' + authId + '-input' ),
			   authToken = jQuery( '#' + authId + '-token' )
		   ;
		   authField.find('.forminator-authentication-notice').removeClass('error');
		   authField.find('.lost-device-url').attr('href', data.data.lost_url);
		   self.$el.find( 'button' ).removeAttr( 'disabled' );
		   if( 'show' === data.data.authentication ) {
			   if (
				   'undefined' !== typeof window.webauthn &&
				   'undefined' !== typeof data.data.username
			   ) {
				   window.webauthn.username = data.data.username;
			   }

			   self.$el.find('.forminator-authentication-nav').html('').append( data.data.auth_nav );
			   self.$el.find('.forminator-authentication-box').hide();
			   if ( 'fallback-email' === data.data.auth_method ) {
				   self.$el.find('.wpdef-2fa-email-resend input').click();
				   self.$el.find('.notification').hide();
			   }
			   self.$el.find( '#forminator-2fa-' + data.data.auth_method ).show();
			   self.$el.find( '#forminator-2fa-' + data.data.auth_method + ' .option-row' ).attr( 'tabindex', '0' ).attr( 'role', 'button' );
			   self.$el.find('.forminator-authentication-box input').attr( 'disabled', true );
			   self.$el.find( '#forminator-2fa-' + data.data.auth_method + ' input' ).attr( 'disabled', false );
			   self.$el.find('.forminator-2fa-link').show();
			   self.$el.find('#forminator-2fa-link-' + data.data.auth_method).hide();
			   authInput.removeAttr( 'disabled' ).val(data.data.auth_method);
			   authToken.val( data.data.auth_token );
			   FUI.openAuthentication( authId, moduleId, authId + '-input' );
		   }
		   if ( 'invalid' === data.data.authentication ) {
			   authField.find('.forminator-authentication-notice').addClass('error');
			   authField.find('.forminator-authentication-notice').html('<p>' + data.data.message + '</p>');
			   self.$el.trigger('forminator:form:submit:failed', [ formData, data.data.message ] );
		   }

		   return false;
	   }
	   return true;
	}
}

if (window['forminatorUtils'] === undefined) {
	window.forminatorUtils = function () {
		return new forminatorFrontUtils();
	}
}