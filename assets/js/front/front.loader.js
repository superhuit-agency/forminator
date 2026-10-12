// the semi-colon before function invocation is a safety net against concatenated
// scripts and/or other plugins which may not be closed properly.
;// noinspection JSUnusedLocalSymbols
(function ($, window, document, undefined) {

	"use strict";

	// undefined is used here as the undefined global variable in ECMAScript 3 is
	// mutable (ie. it can be changed by someone else). undefined isn't really being
	// passed in so we can ensure the value of it is truly undefined. In ES5, undefined
	// can no longer be modified.

	// window and document are passed through as local variables rather than global
	// as this (slightly) quickens the resolution process and can be more efficiently
	// minified (especially when both are regularly referenced in your plugin).

	// PayPal JS SDK, shared by every form on the page.
	var PAYPAL_SDK_URL = 'https://www.paypal.com/sdk/js';

	// Create the defaults once
	var pluginName = "forminatorLoader",
	    defaults   = {
		    action: '',
		    type: '',
		    id: '',
		    render_id: '',
		    is_preview: '',
		    instant_preview: '',
			is_block_editor: '',
		    preview_data: [],
			 nonce: false,
		    last_submit_data: {},
		    extra: {},
	    };

	// The actual plugin constructor
	function ForminatorLoader(element, options) {
		this.element = element;
		this.$el     = $(this.element);

		// jQuery has an extend method which merges the contents of two or
		// more objects, storing the result in the first object. The first object
		// is generally empty as we don't want to alter the default options for
		// future instances of the plugin
		this.settings  = $.extend({}, defaults, options);
		this._defaults = defaults;
		this._name     = pluginName;

		this.frontInitCalled = false;
		this.scriptsQue      = [];
		this.frontOptions    = null;
		this.leadFrontOptions    = null;

		this.init();
	}

	// Avoid Plugin.prototype conflicts
	$.extend(ForminatorLoader.prototype, {
		init: function () {
			// Blocked URL param keys that must never be forwarded from the URL into
			// the AJAX POST body. PHP normalises spaces and dots in POST key names to
			// underscores, so both "is preview" (space) and "is.preview" (dot) would
			// collide with is_preview and enable unauthenticated preview injection.
			// Prefix checks also catch array-style keys such as preview_data[settings][...].
			var BLOCKED_PARAMS = [
				'is_preview',
				'instant_preview'
			];
			var BLOCKED_PARAM_PREFIXES = [
				'preview_data',
				'lead_preview_data'
			];

			var isBlockedParam = function( key ) {
				var normalised = String( key ).replace( /[\s.]/g, '_' ).toLowerCase();
				if ( BLOCKED_PARAMS.indexOf( normalised ) !== -1 ) {
					return true;
				}
				for ( var i = 0; i < BLOCKED_PARAM_PREFIXES.length; i++ ) {
					if ( normalised.indexOf( BLOCKED_PARAM_PREFIXES[ i ] ) === 0 ) {
						return true;
					}
				}
				return false;
			};

			var param = {
				action:           this.settings.action,
				type:             this.settings.type,
				id:               this.settings.id,
				render_id:        this.settings.render_id,
				is_preview:       this.settings.is_preview,
				instant_preview:  this.settings.instant_preview,
				preview_data:     JSON.stringify(this.settings.preview_data),
				last_submit_data: this.settings.last_submit_data,
				extra:            this.settings.extra,
				nonce:            this.settings.nonce
			};

			// Forward URL query params so PHP's $_REQUEST contains them for
			// pre-populate and other server-side features. Blocked keys are stripped
			// to prevent preview-injection via parameter-name collisions.
			try {
				var urlParams = new URLSearchParams(decodeURI(window.location.search));
				urlParams.forEach( function( value, key ) {
					var normalised = key.replace( /[\s.]/g, '_' );
					if ( ! isBlockedParam( key ) && ! isBlockedParam( normalised ) ) {
						param[ normalised ] = value;
					}
				} );
			} catch (e) {}

			var saved_render_id = param.render_id || null;
			if ( null !== saved_render_id && '' !== saved_render_id ) {
				param.saved_render_id = saved_render_id;
			}

			if ( 'undefined' !== typeof this.settings.has_lead ) {
				param.has_lead         = this.settings.has_lead;
				param.leads_id         = this.settings.leads_id;
			}
			if ( 'undefined' !== typeof this.settings.is_block_editor ) {
				param.is_block_editor  = this.settings.is_block_editor;
			}

			this.load_ajax(param);
			this.handleDiviPopup();

		},
		load_ajax: function (param) {
			var self = this;
			$.ajax({
					type: 'POST',
					url: window.ForminatorFront.ajaxUrl,
					data: param,
					cache: false,
					beforeSend: function () {
						$(document).trigger('before.load.forminator', param.id);
					},
					success: function (data) {
						if (data.success) {
							var response = data.data;

							$(document).trigger('response.success.load.forminator', param.id, data);

							if (!response.is_ajax_load) {
								//not load ajax
								return false;
							}

							var pagination_config = [];

							if(typeof response.pagination_config === "undefined" && typeof response.options.pagination_config !== "undefined") {
								pagination_config = response.options.pagination_config;
							}

							// response.pagination_config
							if (pagination_config) {
								window.Forminator_Cform_Paginations           = window.Forminator_Cform_Paginations || [];
								window.Forminator_Cform_Paginations[param.id] = pagination_config;
							}

							self.frontOptions = response.options || null;

							// Solution for form Preview
							if (typeof window.Forminator_Cform_Paginations === "undefined" && self.frontOptions.pagination_config) {
								window.Forminator_Cform_Paginations           = window.Forminator_Cform_Paginations || [];
								window.Forminator_Cform_Paginations[param.id] = self.frontOptions.pagination_config;
							}

							if( 'undefined' !== typeof response.lead_options ) {

								self.leadFrontOptions = response.lead_options || null;

								if ( ( typeof window.Forminator_Cform_Paginations === "undefined" || typeof window.Forminator_Cform_Paginations[param.leads_id] === "undefined" )
									&& self.leadFrontOptions.pagination_config ) {
									window.Forminator_Cform_Paginations           = window.Forminator_Cform_Paginations || [];
									window.Forminator_Cform_Paginations[param.leads_id] = self.leadFrontOptions.pagination_config;
								}

							}

							//response.html
							if (response.html) {
								var style  = response.style || null;
								var script = response.script || null;
								self.render_html(response.html, style, script);
							}

							//response.styles
							if (response.styles) {
								self.maybe_append_styles(response.styles);
							}

							if (response.scripts) {
								self.maybe_append_scripts(response.scripts);
							}

							if (!response.scripts && self.frontOptions) {
								// when no additional scripts, direct execute
								self.init_front();
							}


						} else {
							$(document).trigger('response.error.load.forminator', param.id, data);
						}

					},
					error: function () {
						$(document).trigger('request.error.load.forminator', param.id);
					},
				}
			).always(function () {
				$(document).trigger('after.load.forminator', param.id);
			});
		},

		render_html: function (html, style, script) {
			var id              = this.settings.id,
			    render_id       = this.settings.render_id,
			    // save message
			    message         = '',
			    wrapper_message = null;

			// Try to find message in current DOM, if not found, try to find in new HTML
			wrapper_message = this.$el.find('.forminator-response-message');
			if (wrapper_message.length && $.trim(wrapper_message.text()) !== '') {
				message = wrapper_message.get(0).outerHTML;
			} else {
				wrapper_message = $(html).find('.forminator-response-message');
				if (wrapper_message.length && $.trim(wrapper_message.text()) !== '') {
					message = wrapper_message.get(0).outerHTML;
				}
			}

			wrapper_message = this.$el.find('.forminator-poll-response-message');
			if (wrapper_message.length) {
				message = wrapper_message.get(0).outerHTML;
			}

			if ( this.$el.parent().hasClass( 'forminator-guttenberg' ) ) {
				this.$el.parent()
				    .html(html);
			} else {
				this.$el
			    .replaceWith(html);
			}

			// Show form only after initialized ForminatorFront to avoid showing hidden fields.
			let $element = $('#forminator-module-' + id + '[data-forminator-render=' + render_id + ']');
			$element.hide();
			if ( ! this.$el.parent().has( '#forminator-instant-preview' ) ) {
				$element.hide();
			}

			if (message) {
				$('#forminator-module-' + id + '[data-forminator-render=' + render_id + '] .forminator-response-message')
					.replaceWith(message);
				$('#forminator-module-' + id + '[data-forminator-render=' + render_id + '] .forminator-poll-response-message')
					.replaceWith(message);
			}

			//response.style
			if (style) {
				if ($('style#forminator-module-styles-' + id).length) {
					$('style#forminator-module-styles-' + id).remove();
				}
				$('body').append(style);
			}

			if (script) {
				$('body').append(script);

			}
		},

		maybe_append_styles: function (styles) {
			for (var style_id in styles) {
				if (styles.hasOwnProperty(style_id)) {
					// already loaded?
					if (!$('link#' + style_id).length) {
						var link = $('<link>');
						link.attr('rel', 'stylesheet');
						link.attr('id', style_id);
						link.attr('type', 'text/css');
						link.attr('media', 'all');
						link.attr('href', styles[style_id].src);
						$('head').append(link);
					}
				}
			}
		},

		maybe_append_scripts: function (scripts) {
			var self           = this,
				scripts_to_load = [],
				hasHustle       = $( 'body' ).find( '.hustle-ui' ).length
			;

			for (var script_id in scripts) {
				if (scripts.hasOwnProperty(script_id)) {
					var load_on = scripts[script_id].on;
					var load_of = scripts[script_id].load;
					// already loaded?
					if ('window' === load_on) {
						if ( window[load_of] && 'forminator-google-recaptcha' !== script_id && 0 === hasHustle ) {
							continue;
						}
					} else if ('$' === load_on) {
						if ($.fn[load_of]) {
							continue;
						}
					}

					var script = {};
					script.src = scripts[script_id].src;
					script.async = scripts[ script_id ].async ?? true;

					// PayPal is shared between all forms on the page, only one of them loads it.
					if ( self.is_paypal_sdk( script.src ) ) {
						if ( self.reserve_paypal_sdk( script_id ) ) {
							scripts_to_load.push(script);
						}

						continue;
					}

					scripts_to_load.push(script);
					this.scriptsQue.push(script_id);
				}
			}


			if (!this.scriptsQue.length) {
				this.init_front();
				return;
			}

			for (var script_id_to_load in scripts_to_load) {
				if (scripts_to_load.hasOwnProperty(script_id_to_load)) {
					this.load_script(scripts_to_load[script_id_to_load]);
				}
			}

		},

		/**
		 * Check whether the script url is the PayPal JS SDK.
		 *
		 * @param {string} src Script url.
		 *
		 * @return {boolean}
		 */
		is_paypal_sdk: function (src) {
			return 'string' === typeof src && 0 === src.indexOf( PAYPAL_SDK_URL );
		},

		/**
		 * Get the window holding the PayPal SDK. The block editor renders its canvas in an
		 * iframe, so the SDK lives in the top document and is shared from there.
		 *
		 * @return {Window}
		 */
		paypal_host: function () {
			try {
				if ( window.parent !== window && window.parent.document ) {
					return window.parent;
				}
			} catch ( e ) {
				// Cross-origin parent, stay in the current window.
			}

			return window;
		},

		/**
		 * Get the shared state of the single PayPal SDK load.
		 *
		 * @return {Object}
		 */
		paypal_registry: function () {
			var host = this.paypal_host();

			if ( ! host.forminatorPayPalSdk ) {
				host.forminatorPayPalSdk = { state: 'idle', callbacks: [] };
			}

			var registry = host.forminatorPayPalSdk;

			// Adopt an SDK enqueued by PHP or injected before this loader ran.
			if ( 'idle' === registry.state ) {
				var existing = $( host.document ).find( 'script[src^="' + PAYPAL_SDK_URL + '"]' );

				if ( host.paypal ) {
					registry.state = 'loaded';
				} else if ( existing.length ) {
					registry.state = 'loading';
					this.watch_paypal_script( existing.get( 0 ) );
				}
			}

			return registry;
		},

		/**
		 * Reserve the shared PayPal SDK for this form.
		 *
		 * The SDK can only be evaluated once per document, a second evaluation makes zoid
		 * destroy the buttons already rendered by the first one. Forms that do not get the
		 * reservation wait for the single load instead of injecting their own copy.
		 *
		 * @param {string} script_id Script handle.
		 *
		 * @return {boolean} True when this form has to load the SDK itself.
		 */
		reserve_paypal_sdk: function (script_id) {
			var self     = this,
			    registry = self.paypal_registry();

			if ( 'loaded' === registry.state ) {
				self.adopt_paypal();

				return false;
			}

			self.scriptsQue.push(script_id);
			registry.callbacks.push( function () {
				self.adopt_paypal();
				self.script_on_load();
			} );

			// Another form is already loading it.
			if ( 'loading' === registry.state ) {
				return false;
			}

			registry.state = 'loading';

			return true;
		},

		/**
		 * Release the forms waiting on the shared SDK once it settles.
		 *
		 * @param {Element} element SDK script tag.
		 */
		watch_paypal_script: function (element) {
			var self = this;

			if ( ! element || element.forminatorPayPalWatched ) {
				return;
			}
			element.forminatorPayPalWatched = true;

			var done = function ( event ) {
				var registry  = self.paypal_registry(),
				    callbacks = registry.callbacks,
				    failed    = !! event && 'error' === event.type;

				if ( failed ) {
					// Drop the tag too, a retry would otherwise adopt it and wait for a load that never comes.
					registry.state = 'idle';

					if ( element.parentNode ) {
						element.parentNode.removeChild( element );
					}
				} else {
					registry.state = 'loaded';
				}

				registry.callbacks = [];

				for ( var i = 0; i < callbacks.length; i++ ) {
					callbacks[i]();
				}
			};

			// It may have finished loading before we got a chance to watch it.
			if ( self.paypal_host().paypal ) {
				done();

				return;
			}

			element.addEventListener( 'load', done );
			// Release the waiting forms on failure too, so they never hang.
			element.addEventListener( 'error', done );
		},

		/**
		 * Expose the shared SDK to the current window when it was loaded in the parent.
		 */
		adopt_paypal: function () {
			var host = this.paypal_host();

			if ( host !== window && host.paypal && ! window.paypal ) {
				window.paypal = host.paypal;
			}
		},

		load_script: function (script_props) {
			var self      = this;
			var is_paypal = self.is_paypal_sdk( script_props.src );
			// PayPal goes into the document holding the shared SDK, the top one when the form
			// is rendered inside an iframe such as the block editor canvas.
			var doc       = is_paypal ? self.paypal_host().document : document;
			var loaded    = $( doc ).find( 'script[src="' + script_props.src + '"]' );

			// Check if script is already loaded or not.
			if ( loaded.length ) {
				if ( is_paypal ) {
					self.watch_paypal_script( loaded.get( 0 ) );
				} else {
					self.script_on_load();
				}

				return;
			}

			var script = doc.createElement('script');
			var body   = doc.getElementsByTagName('body')[0];

			script.type  = 'text/javascript';
			script.src   = script_props.src;
			script.async = script_props.async;
			script.defer = true;

			if ( is_paypal ) {
				// Every waiting form is released at once when the shared SDK settles.
				self.watch_paypal_script( script );
			} else {
				script.onload = function () {
					self.script_on_load();
				};
			}

			body.appendChild(script);
		},

		script_on_load: function () {
			this.scriptsQue.pop();

			if (!this.scriptsQue.length) {
				this.init_front();
			}
		},

		init_front: function () {
			if (this.frontInitCalled) {
				return;
			}

			this.frontInitCalled = true;
			var id               = this.settings.id;
			var render_id        = this.settings.render_id;
			var options          = this.frontOptions || null;
			var lead_options     = this.leadFrontOptions || null;
			var $module          = $( '#forminator-module-' + id + '[data-forminator-render="' + render_id + '"]' );

			// Ensures SUI Select2 dropdownParent behaves correctly within modal context.
			if ( this.settings.is_preview ) {
				var $dialog = $module.closest( '.sui-box' );
				if ( $dialog.length ) {
					$dialog.addClass( 'sui-dialog-content' );
				}
			}

			if (options) {
				options.is_preview = this.settings.is_preview;
				options.preview_data = this.settings.preview_data;
				$module.forminatorFront(options);
			}
			if ( 'undefined' !== typeof this.settings.has_lead && lead_options) {
				var leads_id = this.settings.leads_id;
				$('#forminator-module-' + leads_id + '[data-forminator-render="' + render_id + '"]')
					.forminatorFront(lead_options);
			}

			this.init_window_vars();

		},

		init_window_vars: function () {
			// RELOAD type
			if (typeof ForminatorValidationErrors !== 'undefined') {
				var forminatorFrontSubmit = jQuery(ForminatorValidationErrors.selector).data('forminatorFrontSubmit');
				if (typeof forminatorFrontSubmit !== 'undefined') {
					forminatorFrontSubmit.show_messages(ForminatorValidationErrors.errors);
				}
			}

			if (typeof ForminatorFormHider !== 'undefined') {
				var forminatorFront = jQuery(ForminatorFormHider.selector).data('forminatorFront');
				if (typeof forminatorFront !== 'undefined') {
					forminatorFront.hide();
				}
			}
		},

		handleDiviPopup: function () {
			var self = this;

			if ( 'undefined' !== typeof DiviArea ) {
				DiviArea.addAction( 'show_area', function( area ) {
					var $form = area.find( '#' + self.element.id );

					if ( 0 !== $form.length ) {
						self.frontInitCalled = false;
						self.init_front();
						var captchaRenderers = [
							forminator_render_captcha,
							forminator_render_hcaptcha,
							forminator_render_turnstile
						];
						for ( var i = 0; i < captchaRenderers.length; i++ ) {
							if ( 'function' === typeof captchaRenderers[ i ] ) {
								captchaRenderers[ i ]();
							}
						}
					}
				});
			}
		},
	});

	// A really lightweight plugin wrapper around the constructor,
	// preventing against multiple instantiations
	$.fn[pluginName] = function (options) {
		return this.each(function () {
			if (!$.data(this, pluginName)) {
				$.data(this, pluginName, new ForminatorLoader(this, options));
			}
		});
	};


})(jQuery, window, document);
