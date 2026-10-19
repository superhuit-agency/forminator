<?php
/**
 * The Forminator_CForm_Front_Action class.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Front ajax for custom forms
 *
 * @since 1.0
 */
class Forminator_CForm_Front_Action extends Forminator_Front_Action {

	/**
	 * Module slug
	 *
	 * @var string
	 */
	protected static $module_slug = 'form';

	/**
	 * Submit errors
	 *
	 * @var array
	 */
	public static $submit_errors = array();

	/**
	 * Hidden fields
	 *
	 * @var array
	 */
	public static $hidden_fields = array();

	/**
	 * Not calculable fields
	 *
	 * @var array
	 */
	public static $not_calculable = array();

	/**
	 * Fields that shoud be replaced to zero if it's hidden
	 *
	 * @var array
	 */
	public static $replace_to_zero = array();

	/**
	 * Entry type
	 *
	 * @var string
	 */
	public static $entry_type = 'custom-forms';

	/**
	 * Registration object
	 *
	 * @var string
	 */
	public static $registration = null;

	/**
	 * Plugin instance
	 *
	 * @var null
	 */
	private static $instance = null;

	/**
	 * Flag if form has upload field
	 *
	 * @var false
	 */
	private static $has_upload = false;

	/**
	 * Flag if form has payment fields
	 *
	 * @var false
	 */
	private static $has_payment = false;

	/**
	 * Is stripe payment
	 *
	 * @var false
	 */
	private static $is_stripe_payment = false;

	/**
	 * Is paypal payment
	 *
	 * @var false
	 */
	private static $is_paypal_payment = false;

	/**
	 * Is processing webhook
	 *
	 * @var bool
	 */
	private static $is_processing_webhook = false;

	/**
	 * Is fields visibility checked
	 *
	 * @var bool
	 */
	public static $is_fields_visibility_checked = false;

	/**
	 * Forminator_CForm_Front_Action constructor
	 */
	public function __construct() {
		parent::__construct();

		// Save entries.
		if ( ! empty( self::$entry_type ) ) {
			add_action( 'wp_ajax_forminator_pp_create_order', array( $this, 'create_paypal_order' ) );
			add_action( 'wp_ajax_nopriv_forminator_pp_create_order', array( $this, 'create_paypal_order' ) );

			add_action( 'wp_ajax_forminator_multiple_file_upload', array( $this, 'multiple_file_upload' ) );
			add_action( 'wp_ajax_nopriv_forminator_multiple_file_upload', array( $this, 'multiple_file_upload' ) );

			add_action( 'wp_ajax_forminator_check_stripe_checkout_session_status', array( $this, 'check_stripe_checkout_session_status' ) );
			add_action( 'wp_ajax_nopriv_forminator_check_stripe_checkout_session_status', array( $this, 'check_stripe_checkout_session_status' ) );
		}

		add_action( 'wp_ajax_forminator_email_draft_link', array( $this, 'submit_email_draft_link' ) );
		add_action( 'wp_ajax_nopriv_forminator_email_draft_link', array( $this, 'submit_email_draft_link' ) );
	}

	/**
	 * Return the plugin instance
	 *
	 * @since 1.0
	 * @return Forminator_CForm_Front_Action
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prepare price to pass to PayPal
	 *
	 * @param array $amount Amount array.
	 * @return array
	 */
	private static function prepare_pp_price( $amount ) {
		$integer_currencies = array(
			'HUF',
			'JPY',
			'TWD',
		);
		if ( isset( $amount['currency_code'] ) && in_array( $amount['currency_code'], $integer_currencies, true ) ) {
			$amount['value'] = number_format( (float) $amount['value'], 0, '.', '' );
		} else {
			$amount['value'] = number_format( (float) $amount['value'], 2, '.', '' );
		}

		return $amount;
	}


	/**
	 * Create PayPal order
	 *
	 * @since 1.14.3
	 */
	public function create_paypal_order() {
		$body = trim( file_get_contents( 'php://input' ) );
		$data = json_decode( $body, true );

		$form_id = $data['form_id'] ?? '';
		if ( ! isset( $data['nonce'] ) || ! wp_verify_nonce( $data['nonce'], 'forminator_submit_form' . $form_id ) ) {
			wp_send_json_error( new WP_Error( 'invalid_code' ) );
		}

		// Verify the PayPal field is visible (not hidden by visibility conditions).
		$parsed_fields = array();
		if ( ! empty( $data['form_fields'] ) ) {
			wp_parse_str( $data['form_fields'], $parsed_fields );
			$parsed_fields = self::make_nice_group_suffixes( $parsed_fields );
		}
		self::$module_id     = $form_id;
		self::$module_object = Forminator_Base_Form_Model::get_model( $form_id );
		self::$prepared_data = $parsed_fields;
		self::check_fields_visibility();
		if ( empty( self::$info['paypal_field'] ) ) {
			wp_send_json_error( esc_html__( 'Error: PayPal field doesn\'t exist in your form!', 'forminator' ) );
		}

		// A v2 invisible widget is never executed on the PayPal flow, so it has no token to
		// verify. That combination stays as it was, the rest are checked before the order.
		if ( 'v2_invisible' !== ( self::$info['captcha_settings']['captcha_type'] ?? '' ) ) {
			$captcha_error = self::verify_payment_captcha();
			if ( '' !== $captcha_error ) {
				wp_send_json_error( esc_html( $captcha_error ) );
			}
		}

		$paypal_field   = self::$info['paypal_field'];
		$field_object   = Forminator_Core::get_field_object( 'paypal' );
		$payment_amount = $field_object->get_payment_amount( $paypal_field );

		// Check if payment amount is bigger than zero.
		if ( floatval( $payment_amount ) <= 0 ) {
			wp_send_json_error( esc_html__( 'The payment total must be greater than 0.', 'forminator' ) );
		}

		// The order is built from the saved field settings only. The request body is never
		// merged into it, so the payer can't add purchase units, a payee, a payment source,
		// an application context or a different intent.
		$purchase_unit = array(
			'amount' => self::prepare_pp_price(
				array(
					'value'         => $payment_amount,
					'currency_code' => Forminator_Field::get_property( 'currency', $paypal_field, 'USD' ),
				)
			),
		);

		$payment_description = $field_object->get_payment_description( $paypal_field );
		if ( ! empty( $payment_description ) ) {
			$purchase_unit['description'] = $payment_description;
		}

		$request = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array( $purchase_unit ),
		);

		if ( 'enable' !== Forminator_Field::get_property( 'shipping_address', $paypal_field, 'disable' ) ) {
			$request['application_context'] = array(
				'shipping_preference' => 'NO_SHIPPING',
			);
		}

		// Billing details are the only part of the payload that comes from the payer, and only through an allowlist.
		$payer = self::sanitize_pp_payer( $data['form_data']['payer'] ?? null );
		if ( null !== $payer ) {
			$request['payer'] = $payer;
		}

		$request = $this->get_temporary_country_code( $request );
		$request = $this->get_state_code( $request );

		$request = apply_filters( 'forminator_paypal_create_order_request', $request, $data );

		if ( isset( $request['payer'] ) && empty( array_filter( $request['payer'] ) ) ) {
			unset( $request['payer'] );
		}

		// The mode picks the API credentials, so it's taken from the saved settings and not from the request.
		$mode = 'live' === Forminator_Field::get_property( 'mode', $paypal_field, 'sandbox' ) ? 'live' : 'sandbox';

		$paypal = new Forminator_PayPal_Express();
		$order  = $paypal->create_order( $request, $mode );

		if ( is_wp_error( $order ) || empty( $order->id ) ) {
			wp_send_json_error( esc_html__( 'Cannot create a new order on PayPal. If the error persists, please contact us for further assistance.', 'forminator' ) );
		}

		$response = array(
			'order_id' => $order->id,
		);

		wp_send_json_success( $response );
	}

	/**
	 * Build the PayPal payer object from an allowlist of billing details.
	 *
	 * Only the properties Forminator maps from the form fields are kept, anything else
	 * sent with the request is dropped.
	 *
	 * @since 1.58.0
	 *
	 * @param mixed $payer Payer data from the request body.
	 *
	 * @return array|null Sanitized payer, or null when no payer was sent.
	 */
	private static function sanitize_pp_payer( $payer ) {
		if ( ! is_array( $payer ) ) {
			return null;
		}

		$sanitized = array();

		if ( isset( $payer['name'] ) && is_array( $payer['name'] ) ) {
			$name = self::sanitize_pp_strings( $payer['name'], array( 'given_name', 'surname' ) );
			if ( ! empty( $name ) ) {
				$sanitized['name'] = $name;
			}
		}

		if ( isset( $payer['email_address'] ) && is_scalar( $payer['email_address'] ) ) {
			$email = sanitize_email( (string) $payer['email_address'] );
			if ( is_email( $email ) ) {
				$sanitized['email_address'] = $email;
			}
		}

		// An empty address is kept so the temporary country code can still be added to it.
		if ( isset( $payer['address'] ) && is_array( $payer['address'] ) ) {
			$address = self::sanitize_pp_strings(
				$payer['address'],
				array(
					'address_line_1',
					'address_line_2',
					'admin_area_1',
					'admin_area_2',
					'postal_code',
					'country_code',
				)
			);

			// The Address field only ever submits an ISO 3166-1 alpha-2 code, PayPal rejects anything else.
			if ( isset( $address['country_code'] ) ) {
				$country_code = strtoupper( $address['country_code'] );

				if ( preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
					$address['country_code'] = $country_code;
				} else {
					unset( $address['country_code'] );
				}
			}

			$sanitized['address'] = $address;
		}

		return $sanitized;
	}

	/**
	 * Keep the allowed keys of an array as sanitized non empty strings.
	 *
	 * @since 1.58.0
	 *
	 * @param array $values Values to filter.
	 * @param array $allowed_keys Keys to keep.
	 *
	 * @return array
	 */
	private static function sanitize_pp_strings( $values, $allowed_keys ) {
		$sanitized = array();

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_scalar( $values[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $values[ $key ] );
			if ( '' !== $value ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Reset the module settings and fields with the preview data if the request is coming from preview mode.
	 *
	 * @return void
	 */
	private function reset_model_with_preview_data_if_needed() {
		$is_preview = filter_input( INPUT_POST, 'is_preview', FILTER_VALIDATE_BOOLEAN );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is already verified before this function is called.
		$action = isset( $_POST['action'] ) ? Forminator_Core::sanitize_text_field( 'action' ) : '';
		// Check if the request is for updating payment amount in preview mode and user has permission to edit the form.
		if ( 'forminator_update_payment_amount' === $action && $is_preview && current_user_can( forminator_get_permission( 'forminator-cform' ) ) ) {
			$preview_data = $_POST['preview_data'] ?? array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce is already verified before this function is called.
			if ( ! empty( $preview_data ) ) {
				if ( ! is_array( $preview_data ) ) {
					$preview_data = json_decode( wp_unslash( $preview_data ), true );
				}
				$preview_data = Forminator_Core::sanitize_array( $preview_data, 'preview_data', true );

				// Reset the module settings and fields only if the preview data is not empty.
				if ( empty( $preview_data ) ) {
					return;
				}

				if ( ! empty( $preview_data['settings'] ) ) {
					// Reset the module settings using the preview data.
					static::$module_object->settings = $preview_data['settings'];
				}

				// Reset fields to prevent duplicate fields when updating from preview data.
				static::$module_object->fields = array();
				$view                          = Forminator_CForm_Front::get_instance();
				static::$module_object         = $view->set_form_model_data( static::$module_object, $preview_data );
				self::$module_settings         = static::$module_object->settings;
			}
		}
	}

	/**
	 * Update payment amount
	 *
	 * @since 1.7.3
	 */
	public function update_payment_amount() {
		$form_id = Forminator_Core::sanitize_text_field( 'form_id' );

		if ( empty( $form_id ) || ! $this->validate_ajax( 'forminator_submit_form' . $form_id, 'POST', 'forminator_nonce' ) ) {
			wp_send_json_error( esc_html__( 'Invalid nonce. Please refresh your browser.', 'forminator' ) );
		}

		$this->init_properties();

		// Reset the module settings and fields with the preview data if the request is coming from the preview mode.
		$this->reset_model_with_preview_data_if_needed();

		self::check_fields_visibility();

		// A Stripe field hidden by visibility conditions has no payment amount to update or validate.
		if ( self::is_in_hidden_fields( 'stripe-' ) ) {
			wp_send_json_success( array( 'paymentFieldHidden' => true ) );
		}

		$first_intent = ! empty( self::$prepared_data['stripe_first_payment_intent'] );
		$is_intent    = ! empty( self::$prepared_data['stripe-intent'] );
		$validate     = ! empty( self::$prepared_data['stripe_validate_submission'] );

		if ( ! $first_intent && empty( self::$info['stripe_field'] ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Error: Stripe field doesn\'t exist in your form!', 'forminator' ),
					'errors'  => array(),
				)
			);
		}

		// Stripe calls this on page load and on field changes, before a token exists. Those
		// updates take no payment, so one is only demanded when the submission is validated.
		$captcha_error = self::verify_payment_captcha( $validate );
		if ( '' !== $captcha_error ) {
			wp_send_json_error(
				array(
					'message' => esc_html( $captcha_error ),
					'errors'  => array(),
					// Without this Checkout reads it as a stale session and rebuilds itself.
					'notice'  => 'error',
				)
			);
		}

		$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );

		if ( $forminator_stripe_field instanceof Forminator_Stripe ) {
			$is_checkout_session = $forminator_stripe_field->is_checkout_session( self::$info['stripe_field'] );
			$is_subscription     = ! empty( $forminator_stripe_field->payment_plan['payment_method'] )
				&& 'subscription' === $forminator_stripe_field->payment_plan['payment_method'];

			if ( $validate && $is_checkout_session ) {
				$this->submit_form_with_pending_status();
			}

			// Subscription stripe-intent must always reach update_paymentIntent() — amount is returned for wallet preview remounts (variable price/qty).
			if ( ! $is_checkout_session && ! $first_intent && $is_intent && ! $is_subscription && isset( self::$prepared_data['paymentPlan'] ) &&
				( empty( $forminator_stripe_field->payment_plan )
					|| self::$prepared_data['paymentPlan'] === $forminator_stripe_field->payment_plan_hash )
			) {
				// No need to update paymentIntent if the plan is not set or it's the same plan.
				// Do not reset the payment plan if it's already set.
				$new_plan_hash = empty( $forminator_stripe_field->payment_plan_hash ) && ! empty( self::$prepared_data['paymentPlan'] )
					? self::$prepared_data['paymentPlan'] : $forminator_stripe_field->payment_plan_hash;
				wp_send_json_success( array( 'paymentPlan' => $new_plan_hash ) );
			}

			$forminator_stripe_field->update_paymentIntent( self::$prepared_data, self::$info['stripe_field'] );
		}

		wp_send_json_success();
	}

	/**
	 * Check whether a stored Stripe Checkout Session can still be recovered.
	 *
	 * @since 1.56
	 *
	 * @return void
	 */
	public function check_stripe_checkout_session_status() {
		$form_id    = Forminator_Core::sanitize_text_field( 'form_id' );
		$payment_id = Forminator_Core::sanitize_text_field( 'paymentid' );

		if ( empty( $form_id ) || ! $this->validate_ajax( 'forminator_submit_form' . $form_id, 'POST', 'forminator_nonce' ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Invalid nonce. Please refresh your browser.', 'forminator' ),
					'recoverable' => false,
				)
			);
		}

		if ( empty( $payment_id ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Stripe Checkout Session ID does not exist.', 'forminator' ),
					'recoverable' => false,
				)
			);
		}

		$this->init_properties();
		self::check_fields_visibility();

		if ( empty( self::$info['stripe_field'] ) ) {
			foreach ( self::get_fields() as $field ) {
				$field_settings = $field->to_formatted_array();
				if ( 'stripe-ocs' === Forminator_Field::get_property( 'type', $field_settings ) ) {
					self::$info['stripe_field'] = $field_settings;
					break;
				}
			}
		}

		if ( empty( self::$info['stripe_field'] ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Error: Stripe field doesn\'t exist in your form!', 'forminator' ),
					'recoverable' => false,
				)
			);
		}

		$stripe_field = Forminator_Core::get_field_object( 'stripe' );

		if ( ! $stripe_field instanceof Forminator_Stripe || ! $stripe_field->is_checkout_session( self::$info['stripe_field'] ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Stripe Checkout is not enabled for this form.', 'forminator' ),
					'recoverable' => false,
				)
			);
		}

		// Only the browser that started the payment may resume it.
		$session_data = Forminator_Stripe::get_pending_checkout_session_data( $payment_id );
		if ( is_array( $session_data ) && ! Forminator_Stripe::is_payment_owner( $session_data['owner'] ?? '' ) ) {
			wp_send_json_error(
				array(
					'message'     => self::get_foreign_payment_message(),
					'recoverable' => false,
				)
			);
		}

		self::$prepared_data['paymentid'] = $payment_id;
		$session                          = $stripe_field->get_checkout_session( self::$info['stripe_field'] );

		if ( ! Forminator_Stripe::has_pending_checkout_session( $payment_id ) && Forminator_Stripe::can_restore_pending_checkout_session( $payment_id ) ) {
			$session_form_id = is_object( $session ) && ! empty( $session->client_reference_id ) ? (string) $session->client_reference_id : '';
			$payment_status  = is_object( $session ) && ! empty( $session->payment_status ) ? (string) $session->payment_status : '';

			// For payment methods like Cash App, the payment might redirect to another tab, where the user can complete the payment. In that case, we can restore the session and show a success message to the user.
			if ( ! is_wp_error( $session ) && $session_form_id === (string) $form_id
				&& ( 'paid' === $payment_status || 'no_payment_required' === $payment_status ) ) {
				wp_send_json_success(
					array(
						'recoverable' => true,
					)
				);
			}

			// If local pending state was lost, trust only a Stripe session that still belongs to this form and is recoverable.
			if ( is_wp_error( $session ) || $session_form_id !== (string) $form_id || ! Forminator_Stripe::is_recoverable_checkout_session( $session ) ) {
				wp_send_json_error(
					array(
						'message'     => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
						'recoverable' => false,
					)
				);
			}

			Forminator_Stripe::restore_pending_checkout_session( $payment_id );
		}

		if ( is_wp_error( $session ) || ! Forminator_Stripe::is_recoverable_checkout_session( $session, true ) ) {
			Forminator_Stripe::remove_pending_checkout_session( $payment_id );

			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Payment failed. Please try again.', 'forminator' ),
					'recoverable' => false,
				)
			);
		}

		wp_send_json_success(
			array(
				'recoverable' => true,
				'paymentid'   => $session->id,
			)
		);
	}

	/**
	 * Submit the form with a pending status for Stripe Checkout sessions.
	 * This is used to validate the form submission and store the entry in a pending state before redirecting to Stripe Checkout.
	 *
	 * @return void
	 */
	private function submit_form_with_pending_status() {
		// Only the browser that started the session may submit it.
		$session_data = Forminator_Stripe::get_pending_checkout_session_data( self::$prepared_data['paymentid'] ?? '' );
		if ( ! Forminator_Stripe::is_payment_owner( $session_data['owner'] ?? '' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
					'errors'  => array(),
				)
			);
		}

		$response = $this->handle_form( false, true );
		if ( is_array( $response ) && isset( $response['success'] ) && $response['success'] ) {
			// If the form is a login form, return the full response to the front end to handle the login process.
			if ( isset( self::$module_settings['form-type'] ) && 'login' === self::$module_settings['form-type'] ) {
				wp_send_json_success(
					$response
				);
			} else {
				wp_send_json_success(
					array(
						'message'  => $response['message'],
						'entry_id' => $response['entry_id'],
					)
				);
			}
		}
		wp_send_json_error( $response );
	}

	/**
	 * Add a temporary country code based on currency to prevent errors.
	 * Set country code to Austria if currency is Euro,
	 * else remove the last letter from currency code to get temporary country code.
	 *
	 * This only prevents error from Paypal in case the country is not enabled/required in the Address field.
	 * Users will be able to choose the country again in Paypal window or credit card form.
	 *
	 * @param array $request PayPal create order request.
	 *
	 * @return array
	 */
	private function get_temporary_country_code( $request ) {
		if ( isset( $request['payer']['address'] ) ) {
			if (
				empty( $request['payer']['address'] ) ||
				(
					isset( $request['payer']['address']['country_code'] ) &&
					empty( $request['payer']['address']['country_code'] )
				)
			) {
				$currency = $request['purchase_units'][0]['amount']['currency_code'];
				if ( 'EUR' === $currency ) {
					$country_code = 'AT';
				} else {
					$country_code = substr( $currency, 0, -1 );
				}

				$request['payer']['address']['country_code'] = $country_code;
			}
		}

		return $request;
	}

	/**
	 * Get state code
	 *
	 * @param array $request PayPal create order request.
	 *
	 * @return array
	 */
	private function get_state_code( $request ) {
		if ( ! empty( $request['payer']['address']['admin_area_1'] ) ) {
			$request['payer']['address']['admin_area_1'] = forminator_get_state_code( $request['payer']['address']['admin_area_1'] );
		}
		return $request;
	}

	/**
	 * Get default currency
	 *
	 * @return string
	 */
	private function get_default_currency() {
		try {
			$stripe = new Forminator_Gateway_Stripe();

			return $stripe->get_default_currency();

		} catch ( Forminator_Gateway_Exception $e ) {
			return 'USD';
		}
	}

	/**
	 * Check reCaptcha
	 *
	 * @return string|null
	 * @throws Exception When there is an error.
	 */
	private static function check_captcha() {
		// PayPal's token is spent by submit time and was verified when the order was created.
		// Everything else is checked here, from the token the front end kept.
		if ( self::$is_draft || self::$is_abandoned || ! empty( self::$info['paypal_field'] ) ) {
			return;
		}

		$has_stripe = ! empty( self::$info['stripe_field'] ) || self::is_in_hidden_fields( 'stripe-' );

		// Stripe verified this token at payment setup, and a token is only accepted once.
		$error = $has_stripe ? self::verify_payment_captcha() : self::validate_captcha_response();

		if ( '' !== $error ) {
			throw new Exception( esc_html( $error ) );
		}
	}

	/**
	 * Read the captcha token the visitor submitted.
	 *
	 * @since 1.58.0
	 *
	 * @return string
	 */
	private static function get_captcha_user_response() {
		$captcha_user_response = '';

		// The last key is Forminator's own copy, kept for payment forms because the
		// provider's field is reset before the payment request is sent.
		foreach ( array( 'g-recaptcha-response', 'h-captcha-response', 'forminator-turnstile-response', 'forminator-captcha-token' ) as $key ) {
			if ( ! empty( self::$prepared_data[ $key ] ) && is_string( self::$prepared_data[ $key ] ) ) {
				$captcha_user_response = self::$prepared_data[ $key ];
				break;
			}
		}

		/**
		 * Filter captcha user response, default is from `g-recaptcha-response`
		 *
		 * @since 1.5.3
		 *
		 * @param string $captcha_user_response
		 * @param int $form_id
		 * @param array $submitted_data
		 *
		 * @return string captcha user response
		 */
		return apply_filters( 'forminator_captcha_user_response', $captcha_user_response, self::$module_id, self::$prepared_data );
	}

	/**
	 * Verify the submitted captcha token against the provider.
	 *
	 * @since 1.58.0
	 *
	 * @return string Empty when the form has no captcha or the token is good, the error message otherwise.
	 */
	private static function validate_captcha_response() {
		$field_captcha_obj = Forminator_Core::get_field_object( 'captcha' );

		if ( empty( self::$info['captcha_settings'] ) || ! $field_captcha_obj ) {
			return '';
		}

		// Ignore CAPTCHA re-check (only for the v2 checkbox option) during two-factor authentication.
		if ( isset( self::$prepared_data['g-recaptcha-response'] )
			&& isset( self::$module_settings['form-type'] ) && 'login' === self::$module_settings['form-type']
			&& ! empty( self::$prepared_data['auth_method'] )
			&& 'v2_checkbox' === self::$info['captcha_settings']['captcha_type'] ) {
			return '';
		}

		$field_id = Forminator_Field::get_property( 'element_id', self::$info['captcha_settings'] );

		$field_captcha_obj->validate_entry( self::$info['captcha_settings'], self::get_captcha_user_response() );
		$valid_response = $field_captcha_obj->is_valid_entry();

		if ( is_array( $valid_response ) && ! empty( $valid_response[ $field_id ] ) ) {
			return $valid_response[ $field_id ];
		}

		return '';
	}

	/**
	 * Verify the captcha before a payment is set up.
	 *
	 * Verified while the token is fresh and before any money moves. The verdict is cached
	 * because a token is single use and these endpoints run more than once per submission.
	 *
	 * @since 1.58.0
	 *
	 * @param bool $require_token Whether a missing token should fail. Early amount updates
	 *                            run before one exists and take no payment.
	 *
	 * @return string Empty when the form has no captcha or the token is good, the error message otherwise.
	 */
	private static function verify_payment_captcha( $require_token = true ) {
		if ( empty( self::$info['captcha_settings'] ) ) {
			return '';
		}

		$token = self::get_captcha_user_response();

		if ( ! is_string( $token ) || '' === $token ) {
			return $require_token ? __( 'Captcha verification failed. Please try again.', 'forminator' ) : '';
		}

		$cache_key = 'forminator_captcha_' . md5( self::$module_id . '|' . $token );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '1' === $cached ? '' : (string) $cached;
		}

		$error = self::validate_captcha_response();

		// Long enough to cover a 3D Secure detour before the submit.
		set_transient( $cache_key, '' === $error ? '1' : $error, 5 * MINUTE_IN_SECONDS );

		return $error;
	}

	/**
	 * Check if field is in $hidden_fields array
	 *
	 * @param string $field Field slug excluding the iterator.
	 *
	 * @return bool
	 */
	private static function is_in_hidden_fields( $field ) {
		foreach ( self::$hidden_fields as $val ) {
			if ( false !== strpos( $val, $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Handle login if it's login form
	 *
	 * @param object $entry Entry.
	 * @return boolean
	 * @throws Exception When there is an error.
	 */
	private static function maybe_login( $entry ) {
		if ( ! isset( self::$module_settings['form-type'] ) || 'login' !== self::$module_settings['form-type'] ) {
			return;
		}
		// Check who can login.
		if ( is_user_logged_in() ) {
			return;
		}

		$forminator_user_login = new Forminator_CForm_Front_User_Login();
		$login_user            = $forminator_user_login->process_login( self::$module_object, $entry, self::$info['field_data_array'] );
		if ( is_wp_error( $login_user['user'] ) ) {
			$messages = $login_user['user']->get_error_messages();
			throw new Exception(
				wp_kses(
					implode( '<br>', $messages ),
					array(
						'strong' => array(),
						'br'     => array(),
					)
				)
			);
		}

		if ( ! empty( $login_user['authentication'] ) && 'invalid' === $login_user['authentication'] ) {
			self::$response_attrs['authentication'] = 'invalid';
			throw new Exception( esc_html__( 'Whoops, the passcode you entered was incorrect or expired.', 'forminator' ) );
		}

		if ( isset( $login_user['user']->ID ) ) {
			self::$response_attrs['user_id'] = $login_user['user']->ID;
		}
		if ( isset( $login_user['authentication'] ) ) {
			self::$response_attrs['authentication'] = $login_user['authentication'];
		}
		if ( isset( $login_user['auth_token'] ) ) {
			self::$response_attrs['auth_token'] = $login_user['auth_token'];
		}
		if ( isset( $login_user['username'] ) ) {
			self::$response_attrs['username'] = $login_user['username'];
		}
		if ( isset( $login_user['auth_method'] ) ) {
			self::$response_attrs['auth_method'] = $login_user['auth_method'];
		}
		if ( isset( $login_user['auth_nav'] ) ) {
			self::$response_attrs['auth_nav'] = $login_user['auth_nav'];
		}
		if ( isset( $login_user['lost_url'] ) ) {
			self::$response_attrs['lost_url'] = $login_user['lost_url'];
		}

		self::remove_password();
		return true;
	}

	/**
	 * Handle registration form validation separately
	 *
	 * @since 1.17.2
	 *
	 * @return \WP_Error|boolean
	 * @throws Exception When there is an error.
	 */
	private static function validate_registration() {
		if ( self::$is_draft || self::$is_spam || self::$is_abandoned ) {
			return;
		}

		if ( isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type'] ) {
			$activation_method = forminator_get_property( self::$module_settings, 'activation-method' );
			if ( 'manual' !== $activation_method && ! forminator_can_register_user( self::$module_settings ) ) {
				return; // Validation is not required if registration is disabled and activation is not manual.
			}

			// Check who can register new users.
			if ( ! is_user_logged_in() ) {
				$can_creat_user = true;
			} elseif ( isset( self::$module_settings['hide-registration-form'] )
					&& '' === self::$module_settings['hide-registration-form']
			) {
				$can_creat_user = true;
			} else {
				$can_creat_user = false;
			}

			if ( ! $can_creat_user ) {
				return;
			}

			self::$registration = new Forminator_CForm_Front_User_Registration();
			$registration_error = self::$registration->process_validation( self::$module_object, self::$info['field_data_array'] );
			if ( true !== $registration_error ) {
				throw new Exception( esc_html( $registration_error ) );
			}

			$custom_error = apply_filters( 'forminator_custom_registration_form_errors', $registration_error, self::$module_id, self::$info['field_data_array'] );
			if ( true !== $custom_error ) {
				throw new Exception( esc_html( $custom_error ) );
			}

			return true;
		}
	}

	/**
	 * Handle registration if it's registration form.
	 *
	 * @param object $entry Entry.
	 *
	 * @return \WP_Error|boolean
	 * @throws Exception When there is an error.
	 */
	private static function maybe_registration( $entry ) {
		if ( self::$is_draft || self::$is_spam ) {
			return;
		}

		if ( isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type'] ) {
			$activation_method = forminator_get_property( self::$module_settings, 'activation-method' );
			// Do not register new users if registration is disabled and activation is not manual.
			if ( 'manual' !== $activation_method && ! forminator_can_register_user( self::$module_settings ) ) {
				return;
			}
			// Check who can register new users.
			if ( ! is_user_logged_in() ) {
				$can_creat_user = true;
			} elseif ( isset( self::$module_settings['hide-registration-form'] )
					&& '' === self::$module_settings['hide-registration-form']
			) {
				$can_creat_user = true;
			} else {
				$can_creat_user = false;
			}

			if ( ! $can_creat_user ) {
				return;
			}

			$new_user_data = self::$registration->process_registration( self::$module_object, $entry );

			if ( ! is_array( $new_user_data ) ) {
				throw new Exception( esc_html( $new_user_data ) );
			}

			// Do not send emails later.
			self::$module_object->notifications = array();

			return true;
		}
	}

	/**
	 * Prepare fields info
	 */
	private static function prepare_fields_info() {
		self::check_fields_visibility();
		self::$is_leads    = isset( self::$module_settings['form-type'] ) && 'leads' === self::$module_settings['form-type'];
		self::$has_payment = empty( self::$info['stripe_field'] ) && empty( self::$info['paypal_field'] ) ? false : true;

		$fields = self::get_fields();
		foreach ( $fields as $field_index => $field ) {
			self::set_field_data_array( $field_index, $field );
		}

		if ( ! self::$is_draft && ! self::$is_abandoned && ! empty( self::$info['stripe_field'] ) ) {
			$stripe_field = Forminator_Core::get_field_object( 'stripe' );
			if ( $stripe_field instanceof Forminator_Stripe ) {
				$stripe_field->validate( self::$info['stripe_field'], array() );
			}
		}

		// Validate User Registration first before any payments.
		$registration = self::validate_registration();
		if ( is_wp_error( $registration ) ) {
			return self::return_error( $registration->get_error_message() );
		}

		self::check_errors();
		self::filter_field_data_array();

		if ( self::$has_payment ) {
			if ( ! empty( self::$info['paypal_field'] ) && ! empty( self::$info['stripe_field'] ) ) {
				if ( ! empty( self::$prepared_data['payment_transaction_id'] ) ) {
					self::$is_paypal_payment = true;
				} else {
					self::$is_stripe_payment = true;
				}
			} elseif ( ! empty( self::$info['paypal_field'] ) ) {
				self::$is_paypal_payment = true;
			} else {
				self::$is_stripe_payment = true;
			}
		}
	}

	/**
	 * Set field data array
	 *
	 * @param int    $field_index Int key.
	 * @param object $field Forminator_Form_Field_Model.
	 * @return null
	 */
	private static function set_field_data_array( $field_index, $field ) {
		$field_array = $field->to_formatted_array();
		$field_type  = $field_array['type'];
		$element_id  = Forminator_Field::get_property( 'element_id', $field_array );

		if ( self::$is_draft ) {
			if ( in_array( $field_type, array( 'hidden', 'stripe', 'stripe-ocs', 'paypal', 'signature', 'upload' ), true ) ) {
				return;
			}

			if ( 'group' === $field_type ) {
				self::$info['field_data_array'][] = array(
					'name'  => $element_id . '-copies',
					'value' => ! empty( self::$prepared_data[ $element_id . '-copies' ] )
						? count( self::$prepared_data[ $element_id . '-copies' ] ) + 1 : 1,
				);
			}
		}

		// if certain field types - go to next field.
		if ( in_array( $field_type, array( 'stripe', 'stripe-ocs', 'paypal', 'calculation', 'group' ), true ) ) {
			return;
		}

		if ( ! empty( $field->parent_group ) && in_array( $field->parent_group, self::$hidden_fields, true ) ) {
			return;
		}

		$cloned_suffixes = ! empty( $field->parent_group ) && ! empty( self::$prepared_data[ $field->parent_group . '-copies' ] )
				? self::$prepared_data[ $field->parent_group . '-copies' ] : array();

		$all_suffixes = array_map(
			function ( $str ) {
				return '-' . $str;
			},
			$cloned_suffixes
		);
		array_unshift( $all_suffixes, '' );

		foreach ( $all_suffixes as $original_suffix => $suffix ) {
			$field_id = $element_id . $suffix;

			// skip if conditionally hidden.
			if ( ! $field_id || in_array( $field_id, self::$hidden_fields, true ) ) {
				continue;
			}

			$clonned_field               = $field_array;
			$clonned_field['element_id'] = $field_id;
			if ( $original_suffix ) {
				$clonned_field['original_id'] = $element_id . '-' . $original_suffix;
			}
			self::maybe_handle_custom_option( $clonned_field );

			self::set_field_data( $field_id, $clonned_field, $field_index, $clonned_field['original_id'] ?? null );
		}
	}

	/**
	 * Set custom value for radio/select/checkbox fields
	 *
	 * @param mixed $field_array Field settings.
	 * @return void
	 */
	private static function maybe_handle_custom_option( $field_array ) {
		$field_type = $field_array['type'];
		$element_id = $field_array['element_id'];
		if ( in_array( $field_type, array( 'radio', 'select', 'checkbox' ), true ) && isset( self::$prepared_data[ $element_id ] ) ) {
			$selected_values      = is_array( self::$prepared_data[ $element_id ] ) ? self::$prepared_data[ $element_id ] : array( self::$prepared_data[ $element_id ] );
			$enable_custom_option = Forminator_Field::get_property( 'enable_custom_option', $field_array, false );
			if ( $enable_custom_option && ! empty( $selected_values ) ) {
				if ( in_array( 'custom_option', $selected_values, true ) ) {
					self::$info['field_data_array'][] = array(
						'name'  => 'custom-' . $element_id,
						'value' => isset( self::$prepared_data[ 'custom-' . $element_id ] ) ? self::$prepared_data[ 'custom-' . $element_id ] : '',
					);
				}
			}
		}
	}

	/**
	 * Set field data
	 *
	 * @param string      $field_id Field slug.
	 * @param array       $field_array Field settings.
	 * @param int         $field_index Field index.
	 * @param string|null $original_id Original field ID.
	 *
	 * @return null
	 */
	private static function set_field_data( $field_id, $field_array, $field_index, $original_id = null ) {
		$field_type     = $field_array['type'];
		$form_field_obj = Forminator_Core::get_field_object( $field_type );
		// Skip if field object is not found.
		if ( empty( $form_field_obj ) ) {
			return;
		}
		if ( isset( self::$prepared_data[ $field_id ] ) ) {
			$field_data = self::$prepared_data[ $field_id ];
		} else {
			$field_data = array();
		}

		// Strip client-supplied `return`; only the filter below may set it.
		if ( is_array( $field_data ) ) {
			unset( $field_data['return'] );
		}

		/**
		 * Filter handle specific field types
		 *
		 * @since 1.13
		 *
		 * @param array  $field_data Field data
		 * @param object $form_field_obj Form field object
		 * @param array  $field_array field settings
		 *
		 * @return array $field_data Set `return` element of the array as true for returning
		 */
		$field_data = apply_filters( 'forminator_handle_specific_field_types', $field_data, $form_field_obj, $field_array );

		if ( ! empty( $field_data['return'] ) ) {
			unset( $field_data['return'] );

			self::$info['field_data_array'][] = $field_data;
			return;
		}

		// Skip sanitization for webhook processing, as the data is already trusted.
		if ( ! self::$is_processing_webhook ) {
			/**
			 * Sanitize data
			 *
			 * @since 1.0.2
			 *
			 * @param array $field
			 * @param array|string $data - the data to be sanitized.
			 */
			$field_data = $form_field_obj->sanitize( $field_array, $field_data );
		}

		// Legitimate file_path is added in process_uploads(); clearing request values here is intentional.
		if ( 'upload' === $field_type && ! empty( $field_data['file']['file_path'] ) ) {
			$field_data = array();
			unset( self::$prepared_data[ $field_id ] );
		}

		if ( ! self::$is_draft && ! self::$is_abandoned && ! self::$is_processing_webhook ) {
			$field_data = $form_field_obj->validate_entry( $field_array, $field_data );
		}
		$form_field_obj->is_valid_entry();

		// Keep validation errors for the current field and its multi-input subfields.
		foreach ( $form_field_obj->validation_message as $validation_id => $validation_message ) {
			if ( $field_id !== $validation_id && 0 !== strpos( $validation_id, $field_id . '-' ) ) {
				continue;
			}

			// Preserve the subfield suffix when the stored field ID differs from the rendered field ID.
			$error_id = $validation_id;
			if ( $original_id ) {
				$error_id = $original_id . substr( $validation_id, strlen( $field_id ) );
			}

			self::$submit_errors[] = array( $error_id => $validation_message );
		}

		if ( ! self::is_empty_field( $field_array, $field_data ) ) {
			self::$info['field_data_array'][] = array(
				'name'           => $field_id,
				'value'          => $field_data,
				'field_type'     => $field_type,
				'key'            => $field_index,
				'field_array'    => $field_array,
				'form_field_obj' => $form_field_obj,
			);
		}
	}

	/**
	 * Check field has empty value.
	 *
	 * @param array $field Field.
	 * @param mixed $field_data Field value.
	 *
	 * @return bool
	 */
	private static function is_empty_field( $field, $field_data ) {
		$is_empty = true;
		if ( ! empty( $field['type'] ) && 'postdata' === $field['type'] ) {
			// Check if any post data field has a value.
			if ( ! empty( $field_data ) && is_array( $field_data ) ) {
				foreach ( $field_data as $value ) {
					if ( '' !== $value ) {
						return false;
					}
				}
			}
		} elseif ( ! empty( $field['type'] ) && 'date' === $field['type'] && ! empty( $field_data ) && is_array( $field_data ) ) {
			// Dropdown dates default the year in the UI, so year-only values should still be treated as empty.
			$day   = $field_data['day'] ?? '';
			$month = $field_data['month'] ?? '';
			if ( '' !== $day || '' !== $month ) {
				$is_empty = false;
			}
		} elseif ( ! empty( $field_data ) || '0' === $field_data ) {
			$is_empty = false;
		}
		return $is_empty;
	}

	/**
	 * Stop submission process if it has errors
	 *
	 * @throws Exception When there is an error.
	 */
	private static function check_errors() {
		/**
		 * Filter submission errors
		 *
		 * @since 1.0.2
		 *
		 * @param array $submit_errors - the submission errors.
		 * @param int $form_id - the form id.
		 *
		 * @return array $submit_errors
		 */
		self::$submit_errors = apply_filters( 'forminator_custom_form_submit_errors', self::$submit_errors, self::$module_id, self::$info['field_data_array'] );
		if ( ! empty( self::$submit_errors ) ) {
			throw new Exception( esc_html( self::get_invalid_form_message() ) );
		}
	}

	/**
	 * Filter field_data_array property
	 *
	 * @throws Exception When there is an error.
	 */
	private static function filter_field_data_array() {
		if ( empty( self::$info['field_data_array'] ) ) {
			if ( self::$is_draft ) {
				throw new Exception( esc_html__( 'The form is empty and cannot be saved as a draft. Please fill out at least one form field and try again.', 'forminator' ) );
			}

			throw new Exception( esc_html__( 'At least one field must be filled out to submit the form.', 'forminator' ) );
		}

		if ( isset( self::$prepared_data['product-shipping'] ) && intval( self::$prepared_data['product-shipping'] > 0 ) ) {
			self::$info['field_data_array'][] = array(
				'name'  => 'product_shipping',
				'value' => self::$prepared_data['product-shipping'],
			);
		}
		self::$info['field_data_array'][] = array(
			'name'  => '_forminator_user_ip',
			'value' => Forminator_Geo::get_user_ip(),
		);
		if ( ! self::$is_draft && ! self::$is_abandoned
				&& ! empty( self::$module_settings['logged-users'] )
				&& ! empty( self::$module_settings['limit-per-user'] ) ) {
			self::$info['field_data_array'][] = array(
				'name'  => '_user_id',
				'value' => get_current_user_id(),
			);
		}

		// Add draft_page if present (based on form's pagination index).
		if ( isset( self::$prepared_data['draft_page'] ) ) {
			self::$info['field_data_array'][] = array(
				'name'  => 'draft_page',
				'value' => self::$prepared_data['draft_page'],
			);
		}

		// Store page_id for drafts so the draft link can be reconstructed from admin.
		if ( self::$is_draft && isset( self::$prepared_data['page_id'] ) ) {
			self::$info['field_data_array'][] = array(
				'name'  => '_draft_page_id',
				'value' => absint( self::$prepared_data['page_id'] ),
			);
		}

		/**
		 * Filter saved data before persisted into the database
		 *
		 * @since 1.0.2
		 *
		 * @param array $field_data_array - the entry data.
		 * @param int $form_id - the form id.
		 *
		 * @return array $field_data_array
		 */
		self::$info['field_data_array'] = apply_filters( 'forminator_custom_form_submit_field_data', self::$info['field_data_array'], self::$module_id );
	}

	/**
	 * Subscriptions payment intent
	 *
	 * @since 1.38
	 *
	 * @return null|object
	 */
	public static function subscription_payment_intent() {
		if ( ! forminator_is_stripe_addon_compatible() ) {
			return;
		}
		try {
			self::prepare_fields_info();
			$stripe_addon = Forminator_Stripe_Subscription::get_instance();
			$field_object = Forminator_Core::get_field_object( 'stripe' );

			if ( ! $field_object ) {
				return;
			}

			$field = self::$info['stripe_field'];

			$payment_plan = $field_object->get_payment_plan( $field );
			$amount_type  = $payment_plan['subscription_amount_type'] ?? 'fixed';
			$amount       = $payment_plan['subscription_amount'] ?? 0.0;

			if ( 'fixed' === $amount_type && empty( $amount ) ) {
				return; // Payment amount should be greater than 0.
			}

			return $stripe_addon->create_payment_intent( $field_object, self::$module_object, self::$prepared_data, $field, $payment_plan );
		} catch ( Exception $e ) {
			return;
		}
	}

	/**
	 * Handle stripe single payment
	 *
	 * @since 1.15
	 *
	 * @param object $field_object Field object.
	 * @param array  $field Field data.
	 * @param object $entry Entry.
	 * @param array  $payment_plan Entry.
	 *
	 * @return array|WP_ERROR
	 * @throws Exception When there is an error.
	 */
	private static function handle_stripe_subscription( $field_object, $field, $entry, $payment_plan ) {
		if ( forminator_is_stripe_addon_compatible() ) {
			try {
				$stripe_addon = Forminator_Stripe_Subscription::get_instance();
				$amount_type  = isset( $payment_plan['subscription_amount_type'] ) ? $payment_plan['subscription_amount_type'] : 'fixed';
				$amount       = isset( $payment_plan['subscription_amount'] ) ? $payment_plan['subscription_amount'] : 0.0;

				if ( 'fixed' === $amount_type && empty( $amount ) ) {
					throw new Exception( esc_html__( 'Payment amount should be greater than 0.', 'forminator' ) );
				}

				$entry_data = $stripe_addon->handle_subscription( $field_object, self::$module_object, self::$prepared_data, $field, $entry, $payment_plan );

				$stripe_entry_data = array(
					'name'  => $field['element_id'],
					'value' => $entry_data,
				);

				/**
				 * Filter stripe entry data that might be stored/used later
				 *
				 * @since 1.7
				 *
				 * @param array $calculation_entry_data
				 * @param Forminator_Form_Model $module_object
				 * @param array $field field_properties.
				 * @param array $field_data_array
				 *
				 * @return array
				 */
				$stripe_entry_data = apply_filters( 'forminator_custom_form_stripe_entry_data', $stripe_entry_data, self::$module_object, $field, self::$info['field_data_array'] );

				forminator_maybe_log( __METHOD__, $stripe_entry_data['value'] );
				if ( is_wp_error( $stripe_entry_data['value'] ) ) {
					throw new Exception( $stripe_entry_data['value']->get_error_message() );
				}
				if ( ! empty( $stripe_entry_data['value']['error'] ) ) {
					throw new Exception( $stripe_entry_data['value']['error'] );
				}

				return $stripe_entry_data;

			} catch ( Exception $e ) {
				return new WP_Error( 'forminator_stripe_error', $e->getMessage() );
			}
		} else {
			return new WP_Error(
				'forminator_stripe_error',
				esc_html(
					apply_filters(
						'forminator_payment_require_stripe_subscription_addon_error_message',
						esc_html__( 'Forminator Stripe Subscription Add-on is required to submit this form.', 'forminator' )
					)
				)
			);
		}
	}

	/**
	 * Handle stripe single payment
	 *
	 * @since 1.15
	 *
	 * @param array  $field_object Field.
	 * @param array  $field Field data.
	 * @param object $entry Entry.
	 * @param string $mode Stripe payment mode.
	 *
	 * @return array|WP_ERROR
	 * @throws Exception When there is an error.
	 */
	private static function handle_stripe_single( $field_object, $field, $entry, $mode ) {
		$entry_data = $field_object->process_to_entry_data( $field, $entry );

		$stripe_entry_data = array(
			'name'  => $field['element_id'],
			'value' => $entry_data,
		);

		/**
		 * Filter stripe entry data that might be stored/used later
		 *
		 * @since 1.7
		 *
		 * @param array $calculation_entry_data
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field_properties.
		 * @param array $field_data_array
		 *
		 * @return array
		 */
		$stripe_entry_data = apply_filters( 'forminator_custom_form_stripe_entry_data', $stripe_entry_data, self::$module_object, $field, self::$info['field_data_array'] );
		forminator_maybe_log( __METHOD__, $stripe_entry_data['value'] );
		if ( is_wp_error( $stripe_entry_data['value'] ) ) {
			$error_data = $stripe_entry_data['value']->get_error_data();
			if ( is_array( $error_data ) ) {
				self::$response_attrs = array_merge( self::$response_attrs, $error_data );
			}
			throw new Exception( esc_html( $stripe_entry_data['value']->get_error_message() ) );
		}

		/**
		 * Fires after charge stripe
		 *
		 * @since 1.7
		 *
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field properties.
		 * @param array $stripe_entry_data
		 * @param array $submitted_data
		 * @param array $field_data_array
		 */
		do_action( 'forminator_custom_form_after_stripe_charge', self::$module_object, $field, $stripe_entry_data, self::$prepared_data, self::$info['field_data_array'] );

		if ( $field_object->is_checkout_session( $field ) ) {
			return $stripe_entry_data;
		}

		// Try to get Payment Intent from submitted date.
		try {
			$intent = $field_object->get_paymentIntent( $field );

			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			// Don't process confirm if status is requires_capture as it confirmed already.
			if ( 'requires_confirmation' === $intent->status ) {
				$intent = $intent->confirm(
					array(
						'return_url' => Forminator_Stripe::get_return_url(),
					)
				);
			}

			// If we have 3D security on the card return for verification.
			if ( 'requires_action' === $intent->status || 'requires_confirmation' === $intent->status || 'requires_payment_method' === $intent->status ) {
				$error_data = self::handle_failed_stripe_response( $intent, $entry );

				self::$response_attrs           = array_merge( self::$response_attrs, $error_data );
				self::$response_attrs['secret'] = $intent->client_secret;
				return new WP_Error( 'forminator_stripe_error', esc_html( $error_data['message'] ) );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'forminator_stripe_error', $e->getMessage() );
		}

		// The confirmed intent must have succeeded for the expected amount.
		$payment_state = $field_object->validate_intent_payment( $intent, $field, true );
		if ( is_wp_error( $payment_state ) ) {
			return $payment_state;
		}

		$result                     = array(
			'status'           => 'COMPLETED',
			'transaction_id'   => $intent->id,
			'transaction_link' => $field_object::get_transanction_link( $mode, $intent->id ),
		);
		$stripe_entry_data['value'] = array_merge( $stripe_entry_data['value'], $result );

		return $stripe_entry_data;
	}

	/**
	 * Handle stripe failed response
	 *
	 * @param object $result Stripe result.
	 * @param object $entry Entry object.
	 *
	 * @return array
	 */
	public static function handle_failed_stripe_response( $result, $entry ) {
		$error_data = array(
			'message' => __( 'This payment requires additional authentication! Please follow the instructions.', 'forminator' ),
		);

		if ( ! empty( $result->next_action->redirect_to_url->url ) && 0 !== strpos( $result->next_action->redirect_to_url->url, 'https://hooks.stripe.com' ) ) {
			$error_data['redirect_to_url'] = $result->next_action->redirect_to_url->url;
		} elseif ( ! empty( $result->next_action->alipay_handle_redirect->url ) ) {
			$error_data['redirect_to_url'] = $result->next_action->alipay_handle_redirect->url;
		} elseif ( ! empty( $result->next_action->wechat_pay_display_qr_code->data ) ) {
			$error_data['redirect_to_url'] = $result->next_action->wechat_pay_display_qr_code->data;
		} elseif ( ! empty( $result->next_action->cashapp_handle_redirect_or_display_qr_code->mobile_auth_url ) ) {
			$error_data['redirect_to_url'] = $result->next_action->cashapp_handle_redirect_or_display_qr_code->mobile_auth_url;
		} else {
			$error_data['stripe3d'] = true;

			if ( 'requires_payment_method' !== $result->status ) {
				// Most likely it's Blik payment method if the status is requires_payment_method - not 3D Secure.
				$error_data['message'] = __( 'This payment requires 3D Secure authentication! Please follow the instructions.', 'forminator' );
			}
		}

		return apply_filters( 'forminator_stripe_next_action_after_payment_confirmation', $error_data, $result );
	}

	/**
	 * Handle stripe payments
	 *
	 * @param object $entry Entry.
	 * @return array|void
	 */
	private static function handle_stripe( $entry ) {
		if ( self::$is_draft || self::$is_spam ) {
			return;
		}

		if ( ! self::$info['stripe_field'] || ! self::$is_stripe_payment ) {
			return;
		}

		$stripe_mode = isset( self::$info['stripe_field']['mode'] ) ? self::$info['stripe_field']['mode'] : 'test';
		if ( ! forminator_is_stripe_mode_ready( $stripe_mode ) ) {
			return;
		}

		$stripe_field_data = self::stripe_field_to_entry_data_array( $entry );

		if ( ! empty( $stripe_field_data ) && self::is_duplicate_stripe_submission( $entry, $stripe_field_data ) ) {
			$response = self::get_response( $entry );
			$entry->delete();

			return $response;
		}

		$field_object = Forminator_Core::get_field_object( 'stripe' );
		if ( ! empty( $stripe_field_data['value'] ) && is_object( $field_object ) && $field_object->is_checkout_session( self::$info['stripe_field'] ) ) {
			return $stripe_field_data['value'];
		}
	}

	/**
	 * Check if the current Stripe payment already has a submission.
	 *
	 * @since 1.56
	 *
	 * @param Forminator_Form_Entry_Model $entry Entry.
	 * @param array                       $stripe_field_data Stripe field data.
	 * @return bool
	 */
	private static function is_duplicate_stripe_submission( $entry, $stripe_field_data ) {
		if ( empty( self::$info['stripe_field']['element_id'] ) || empty( $entry->entry_id ) ) {
			return false;
		}

		$identifier_key   = 'transaction_id';
		$identifier_value = $stripe_field_data['value']['transaction_id'] ?? '';

		// Trialing subscriptions do not always have an initial transaction ID, so use the subscription ID instead.
		if ( ( empty( $identifier_value ) || 'None' === $identifier_value ) && ! empty( $stripe_field_data['value']['subscription_id'] ) ) {
			$identifier_key   = 'subscription_id';
			$identifier_value = $stripe_field_data['value']['subscription_id'];
		}

		if ( empty( $identifier_value ) || 'None' === $identifier_value ) {
			return false;
		}

		$duplicate_entry_id = Forminator_Form_Entry_Model::get_entry_id_by_payment_identifier(
			self::$module_id,
			self::$info['stripe_field']['element_id'],
			$identifier_key,
			(string) $identifier_value,
			$entry->entry_id
		);

		return ! empty( $duplicate_entry_id );
	}

	/**
	 * Handle paypal
	 *
	 * @param object $entry Entry.
	 * @return array
	 * @throws Exception When there is an error.
	 */
	private static function handle_paypal( $entry ) {
		if ( self::$is_draft || self::$is_spam ) {
			return;
		}

		if ( ! self::$is_paypal_payment ) {
			return;
		}

		if ( self::$module_object->is_payment_require_ssl() && ! is_ssl() ) {
			throw new Exception(
				esc_html(
					apply_filters(
						'forminator_payment_require_ssl_error_message',
						esc_html__( 'SSL required to submit this form, please check your URL.', 'forminator' )
					)
				)
			);
		}

		self::paypal_field_to_entry_data_array( $entry );
	}

	/**
	 * Validate form submission
	 *
	 * @return void
	 */
	public function validate_form_submission() {
		self::can_submit();
		self::prepare_fields_info();
		self::check_submit_visibility();
		self::check_captcha();
	}

	/**
	 * Post submission process
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 *
	 * @return void
	 */
	private function post_submission_process( $entry ) {
		if ( ! self::$is_draft && ! self::$is_abandoned ) {
			$addon_data = array();
			// Process integrated addons and attach their data to the entry.
			$addon_data = self::attach_addons_add_entry_fields( $addon_data, $entry );
			$entry->set_fields( $addon_data );
		}
		self::maybe_create_post( $entry );
		self::attach_addons_after_entry_saved( $entry );
		self::maybe_registration( $entry );
		if ( ! self::$is_draft && ! empty( self::$previous_draft_id ) ) {
			$entry->delete_previous_draft( self::$previous_draft_id );
		}
		self::send_email( $entry );
	}

	/**
	 * Initialize properties to process webhooks
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @return bool
	 */
	private function init_properties_to_process_webhooks( $entry ) {
		self::$is_processing_webhook = true;
		static::$module_object       = Forminator_Base_Form_Model::get_model( $entry->form_id );
		if ( ! static::$module_object ) {
			forminator_maybe_log( __METHOD__, esc_html__( 'Error: Module object is corrupted!', 'forminator' ), $entry );
			return false;
		}

		self::$is_leads = false;
		if ( 'quizzes' === $entry->entry_type ) {
			$quiz_settings = static::$module_object->settings;
			if ( $quiz_settings['hasLeads'] && ! empty( $quiz_settings['leadsId'] ) ) {
				self::$is_leads        = true;
				static::$module_object = Forminator_Base_Form_Model::get_model( $quiz_settings['leadsId'] );
				if ( ! static::$module_object ) {
					forminator_maybe_log( __METHOD__, esc_html__( 'Error: Module object is corrupted!', 'forminator' ), $entry );
					return false;
				}
			} else {
				forminator_maybe_log( __METHOD__, esc_html__( 'Error: Invalid module', 'forminator' ), $entry );
				return false;
			}
		}
		// Prepare data from entry.
		self::$prepared_data   = recreate_prepared_data( static::$module_object, $entry );
		self::$module_id       = isset( self::$prepared_data['form_id'] ) ? self::$prepared_data['form_id'] : false;
		self::$module_settings = method_exists( static::$module_object, 'get_form_settings' )
			? static::$module_object->get_form_settings() : static::$module_object->settings;

		// Restore the draft ID kept on the pending entry, so the draft is deleted once the payment is completed.
		self::$previous_draft_id = $entry->get_meta( '_previous_draft_id', null );
		unset( self::$prepared_data['_previous_draft_id'], self::$prepared_data['_submitter_user_id'] );

		// If the entry is pending, we need to re-prepare the fields info to handle post submission process.
		$fields = self::get_fields();
		foreach ( $fields as $field_index => $field ) {
			$field_settings = $field->to_formatted_array();
			$field_type     = Forminator_Field::get_property( 'type', $field_settings );
			if ( 'stripe-ocs' === $field_type ) {
				self::$info['stripe_field'] = $field_settings;
			} else {
				self::set_field_data_array( $field_index, $field );
			}
		}
		return true;
	}

	/**
	 * Process pending stripe payment
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @return void
	 * @throws Exception When there is an error.
	 */
	public function process_pending_stripe_payment( $entry ) {
		if ( empty( $entry->entry_id ) || 'pending' !== $entry->status ) {
			return;
		}
		if ( empty( $entry->meta_data ) || empty( $entry->meta_data['_stripe_checkout_session_id'] ) ) {
			return;
		}
		$session_id = $entry->meta_data['_stripe_checkout_session_id']['value'] ?? '';
		if ( empty( $session_id ) ) {
			return;
		}
		$initialized = $this->init_properties_to_process_webhooks( $entry );
		if ( ! $initialized ) {
			return;
		}
		$webhook = Forminator_Handle_Webhooks::get_instance();
		// Lock the webhook processing for this entry to prevent concurrent processing.
		$locked = $webhook->lock_webhook_processing( $entry );
		if ( true === $locked ) {
			// Already processing the webhook for this entry, so we should not process it again.
			return;
		}
		try {
			$stripe_field = Forminator_Core::get_field_object( 'stripe' );
			$session      = $stripe_field->get_checkout_session_by_id( $session_id, self::$info['stripe_field'] );
			if ( empty( $session ) || is_wp_error( $session ) ) {
				if ( is_wp_error( $session ) ) {
					throw new Exception( $session->get_error_message() );
				}
				throw new Exception( esc_html__( 'Failed to load Stripe session.', 'forminator' ) );
			}
			$event_data = array(
				'payment_intent' => null,
				'session'        => $session,
				'is_session'     => false,
				'setup_intent'   => null,
			);

			if ( 'paid' === $session->payment_status || 'no_payment_required' === $session->payment_status ) {
				$event_data['payment_intent'] = $event_data['session']->payment_intent ?? null;
				$event_data['is_session']     = true;
				// Payment is successful.
				$this->process_successful_checkout( $entry, $event_data );
			} elseif ( 'expired' === $session->status ) {
				$event_data['is_session'] = true;
				// Payment expired.
				$this->process_failed_checkout( $entry, $event_data );
			} elseif ( ! empty( $session->payment_intent ) ) {
				$payment_intent = $stripe_field->get_stripe_payment_intent( self::$info['stripe_field'], $session->payment_intent );
				if ( empty( $payment_intent ) || is_wp_error( $payment_intent ) ) {
					if ( is_wp_error( $payment_intent ) ) {
						throw new Exception( $payment_intent->get_error_message() );
					}
					throw new Exception( esc_html__( 'Failed to load Stripe payment intent.', 'forminator' ) );
				}
				$event_data['payment_intent'] = $payment_intent;
				// Process the payment intent status and determine the next steps (success, processing, or failure).
				$this->process_payment_intent_status( $entry, $event_data );
			} elseif ( 'unpaid' === $session->payment_status
				&& 'subscription' === $session->mode
				&& ! empty( $session->customer ) ) {
				// For subscriptions with failed payments, we need to check if there is a payment or setup intent for the session and process it accordingly.
				$intents = $stripe_field->get_subscription_intents( self::$info['stripe_field'], $session );
				if ( is_wp_error( $intents ) ) {
					throw new Exception( $intents->get_error_message() );
				}
				$payment_intent = $intents['payment_intent'];
				if ( ! empty( $payment_intent ) ) {
					$event_data['payment_intent'] = $payment_intent;
					// Process the payment intent status and determine the next steps (success, processing, or failure).
					$this->process_payment_intent_status( $entry, $event_data );
				} else {
					// If the payment intent is not available, check for a setup intent.
					$setup_intent = $intents['setup_intent'];
					if ( ! empty( $setup_intent ) &&
						( 'canceled' === $setup_intent->status
						|| ( 'requires_payment_method' === $setup_intent->status && ! empty( $setup_intent->last_setup_error ) ) ) ) {
						$event_data['setup_intent'] = $setup_intent;
						$this->process_failed_checkout( $entry, $event_data );
					}
				}
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'message' => $e->getMessage(),
					'entry'   => $entry->entry_id,
				),
			);
		} finally {
			// Unlock the webhook processing for this entry after processing is complete.
			$webhook->unlock_webhook_processing( $entry );
		}
	}

	/**
	 * Process checkout based on payment intent status
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param array                       $event_data Stripe webhook event data.
	 * @return void
	 */
	private function process_payment_intent_status( $entry, $event_data ) {
		$payment_intent = $event_data['payment_intent'];
		if ( 'succeeded' === $payment_intent->status ) {
			$this->process_successful_checkout( $entry, $event_data );
		} elseif ( 'processing' === $payment_intent->status ) {
			$this->handle_processing_checkout_payment( $entry, $event_data );
		} elseif ( 'canceled' === $payment_intent->status ||
			( 'requires_payment_method' === $payment_intent->status && ! empty( $payment_intent->last_payment_error ) ) ) {
			$this->process_failed_checkout( $entry, $event_data );
		}
	}

	/**
	 * Update entry status
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param object                      $event Stripe webhook event.
	 * @param object                      $session Stripe session object.
	 * @return bool
	 */
	public function handle_stripe_webhook( $entry, $event, $session ) {
		if ( empty( $event->type ) ) {
			return false;
		}
		$initialized = $this->init_properties_to_process_webhooks( $entry );
		if ( ! $initialized ) {
			return false;
		}
		$event_data = array(
			'payment_intent' => null,
			'session'        => $session,
			'is_session'     => false,
			'setup_intent'   => null,
		);
		$status     = false;
		switch ( $event->type ) {
			case 'payment_intent.processing':
				$event_data['payment_intent'] = $event->data->object;
				$status                       = $this->handle_processing_checkout_payment( $entry, $event_data );
				break;
			case 'payment_intent.succeeded':
				$event_data['payment_intent'] = $event->data->object;
				$status                       = $this->process_successful_checkout( $entry, $event_data );
				break;
			case 'payment_intent.payment_failed':
				$event_data['payment_intent'] = $event->data->object;
				$status                       = $this->process_failed_checkout( $entry, $event_data );
				break;
			case 'setup_intent.setup_failed':
				$event_data['setup_intent'] = $event->data->object;
				$status                     = $this->process_failed_checkout( $entry, $event_data );
				break;
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$event_data['is_session']     = true;
				$event_data['session']        = $event->data->object;
				$event_data['payment_intent'] = $event_data['session']->payment_intent ?? null;
				// Payment is successful.
				$status = $this->process_successful_checkout( $entry, $event_data );
				break;
			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				$event_data['is_session'] = true;
				$event_data['session']    = $event->data->object;
				// Payment failed or expired.
				$status = $this->process_failed_checkout( $entry, $event_data );
				break;
			default:
				// Unexpected event type.
				$status = false;
		}
		return $status;
	}

	/**
	 * Update stripe data in entry meta.
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param array                       $stripe_data Stripe data.
	 */
	private function update_stripe_meta( $entry, $stripe_data ) {
		if ( empty( self::$info['stripe_field'] ) || empty( $entry->meta_data ) || empty( $stripe_data ) ) {
			return false;
		}
		$element_id   = self::$info['stripe_field']['element_id'];
		$meta_id      = $entry->meta_data[ $element_id ]['id'];
		$date_updated = date_i18n( 'Y-m-d H:i:s' );

		// Update stripe data in entry meta.
		$entry->update_meta( $meta_id, $element_id, $stripe_data, $date_updated );

		// Update stripe data in prepared data for further processing.
		self::$prepared_data[ $element_id ] = $stripe_data;
	}

	/**
	 * Handle processing checkout payment
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param array                       $event_data Stripe webhook event data.
	 * @return bool
	 */
	private function handle_processing_checkout_payment( $entry, $event_data ) {
		try {
			$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );
			$stripe_data             = $forminator_stripe_field->process_pending_transaction( self::$info['stripe_field'], $entry, $event_data );
			if ( ! empty( $stripe_data ) ) {
				// Update stripe data in entry meta.
				$this->update_stripe_meta( $entry, $stripe_data );
				return true;
			}
		} catch ( Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage(), $entry );
		}
		return false;
	}

	/**
	 * Process successful checkout
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param array                       $event_data Stripe webhook event data.
	 * @param bool                        $is_immediate Whether the processing is immediate or not.
	 * @return bool
	 */
	private function process_successful_checkout( $entry, $event_data, $is_immediate = false ) {
		try {
			$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );
			$stripe_data             = $forminator_stripe_field->process_successful_transaction( self::$info['stripe_field'], $entry, self::$module_object, $event_data );
			if ( is_wp_error( $stripe_data ) ) {
				forminator_maybe_log( __METHOD__, $stripe_data->get_error_message(), $entry );
				return false;
			}
			if ( ! empty( $stripe_data ) ) {
				// Update stripe data in entry meta.
				$this->update_stripe_meta( $entry, $stripe_data );
				$session = $event_data['session'];
				if ( false === $event_data['is_session'] || ( 'paid' === $session->payment_status || 'no_payment_required' === $session->payment_status ) ) {
					if ( self::$is_leads ) {

						// Update the entry status to active.
						$entry->update_status( 'active' );

						// Process integrated add-ons and emails for quizzes.
						$form_action = new Forminator_Quiz_Front_Action();
						$form_action->run_post_submission_process( $entry );

						// Remove the submitter from entry meta, as integrations have been processed.
						$entry->remove_field( '_submitter_user_id' );

						return true;
					}

					if ( isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type'] ) {
						if ( ! self::$registration ) {
							self::$registration = new Forminator_CForm_Front_User_Registration();
						}
						if ( ! empty( self::$prepared_data['_user_registration_data'] ) && is_array( self::$prepared_data['_user_registration_data'] ) ) {
							if ( isset( self::$prepared_data['_user_registration_data']['user_pass'] ) ) {
								// Decrypt the user password before processing the registration.
								$decrypted_password = Forminator_CForm_Front_User_Registration::openssl_decrypt( self::$prepared_data['_user_registration_data']['user_pass'] );
								if ( false !== $decrypted_password ) {
									self::$prepared_data['_user_registration_data']['user_pass'] = $decrypted_password;
									// Set the user registration data to the registration object for further processing.
									self::$registration->set_user_registration_data( self::$prepared_data['_user_registration_data'] );
								} else {
									forminator_maybe_log(
										__METHOD__,
										array(
											'message'  => esc_html__( 'Failed to decrypt user password.', 'forminator' ),
											'entry_id' => $entry->entry_id,
										),
									);
									return false;
								}
							} else {
								// Set the user registration data to the registration object for further processing.
								self::$registration->set_user_registration_data( self::$prepared_data['_user_registration_data'] );
							}
						}
						$user_auto_login = $entry->get_meta( '_user_registration_automatic_login', false );
						if ( $is_immediate && ! empty( $user_auto_login ) ) {
							self::$registration->set_auto_login( true );
						}
					}

					// Update the entry status to active.
					$entry->update_status( 'active' );

					// Update hidden fields with submission ID if they exist in the prepared data.
					$this->maybe_update_hidden_fields( $entry );

					// Process integrated addons, create WP posts, user registration and emails.
					$this->post_submission_process( $entry );

					// Remove the user registration data from entry fields, as it's already processed and won't be needed anymore.
					if ( isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type'] ) {
						$entry->remove_field( '_user_registration_data' );
						$entry->remove_field( '_user_registration_automatic_login' );
					}

					// Remove the user login data from entry fields, as it is no longer needed after successful processing.
					if ( isset( self::$module_settings['form-type'] ) && 'login' === self::$module_settings['form-type'] ) {
						$entry->remove_field( '_user_login_data' );
					}

					// Remove the hidden submission ID fields from entry meta, as they are no longer needed after successful processing.
					if ( self::is_custom_sequence_enabled() ) {
						$entry->remove_field( '_hidden_submission_id_fields' );
					}

					// Remove the Stripe checkout session ID from entry meta, as it's no longer needed after successful processing.
					$entry->remove_field( '_stripe_checkout_session_id' );

					// Remove the draft ID from entry meta, as it's no longer needed after successful processing.
					$entry->remove_field( '_previous_draft_id' );

					// Remove the submitter from entry meta, as integrations have been processed.
					$entry->remove_field( '_submitter_user_id' );

					// If the form is set to prevent storing entries, delete the entry after processing.
					if ( self::prevent_store() ) {
						$key = 'forminator_entry_object_temporary_storage_' . $entry->entry_id;
						set_transient( $key, $entry, DAY_IN_SECONDS );
						$entry->delete();
					}
				}
				return true;
			}
		} catch ( Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage(), $entry );
		}
		return false;
	}

	/**
	 * Update hidden fields with submission ID if they exist in the prepared data.
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 */
	private function maybe_update_hidden_fields( $entry ) {
		if ( ! empty( self::$prepared_data['_hidden_submission_id_fields'] ) && is_array( self::$prepared_data['_hidden_submission_id_fields'] )
			&& ! empty( $entry->meta_data ) ) {
			$meta_data = $entry->meta_data;
			foreach ( self::$prepared_data['_hidden_submission_id_fields'] as $field_id ) {
				if ( ! empty( $meta_data[ $field_id ] ) && ! empty( $meta_data[ $field_id ]['id'] ) ) {
					$meta_id       = $meta_data[ $field_id ]['id'];
					$submission_id = forminator_get_submission_id( null, $entry );
					// Update the hidden field with custom sequence submission ID in entry meta.
					$entry->update_meta( $meta_id, $field_id, $submission_id );
					// Update the prepared data with the custom sequence submission ID for the hidden field.
					self::$prepared_data[ $field_id ] = $submission_id;
				}
			}
		}
	}

	/**
	 * Process failed checkout session
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param array                       $event_data Stripe webhook event data.
	 * @return bool
	 */
	private function process_failed_checkout( $entry, $event_data ) {
		try {
			$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );
			$stripe_data             = $forminator_stripe_field->process_failed_transaction( self::$info['stripe_field'], $entry, self::$module_object, $event_data );
			if ( is_wp_error( $stripe_data ) ) {
				forminator_maybe_log( __METHOD__, $stripe_data->get_error_message(), $entry );
				return false;
			}
			if ( ! empty( $stripe_data ) ) {
				// Update stripe data in entry meta.
				$this->update_stripe_meta( $entry, $stripe_data );
			}
			// Update the entry status to failed.
			$entry->update_status( 'failed' );

			// Remove the user registration data from entry fields, as the payment failed and it is no longer needed.
			if ( isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type'] ) {
				$entry->remove_field( '_user_registration_data' );
				$entry->remove_field( '_user_registration_automatic_login' );
			}

			// Remove the user login data from entry fields, as the payment failed and it is no longer needed.
			if ( isset( self::$module_settings['form-type'] ) && 'login' === self::$module_settings['form-type'] ) {
				$entry->remove_field( '_user_login_data' );
			}

			// Remove the hidden submission ID fields from entry meta, as the payment failed and they are no longer needed.
			if ( self::is_custom_sequence_enabled() ) {
				$entry->remove_field( '_hidden_submission_id_fields' );
			}

			// Remove the Stripe checkout session ID from entry meta, as the payment failed and it is no longer needed.
			$entry->remove_field( '_stripe_checkout_session_id' );

			// Remove the draft ID from entry meta, as the payment failed and the draft is kept for another attempt.
			$entry->remove_field( '_previous_draft_id' );

			// Remove the submitter from entry meta, as the payment failed.
			$entry->remove_field( '_submitter_user_id' );

			// Process the failed email notification if it's set in the form settings.
			$this->process_failed_email( $entry );
			return true;
		} catch ( Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage(), $entry );
			return false;
		}
	}

	/**
	 * Update payment status for pending entry
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @param string                      $session_id Stripe session ID.
	 * @return null|WP_Error
	 */
	private function update_payment_status_for_pending_entry( $entry, $session_id ) {
		$initialized = $this->init_properties_to_process_webhooks( $entry );
		// Reset the payment ID in prepared data as it may reset on `init_properties_to_process_webhooks` call.
		self::$prepared_data['paymentid'] = $session_id;
		if ( ! $initialized ) {
			return;
		}
		$stripe_field = Forminator_Core::get_field_object( 'stripe' );
		$session      = $stripe_field->get_checkout_session_by_id( $session_id, self::$info['stripe_field'] );
		if ( ! empty( $session ) && ! is_wp_error( $session ) && 'complete' === $session->status
			&& ( 'paid' === $session->payment_status || 'no_payment_required' === $session->payment_status ) ) {
			$webhook = Forminator_Handle_Webhooks::get_instance();

			// Lock the webhook processing for this entry to prevent concurrent processing.
			$locked = $webhook->lock_webhook_processing( $entry );
			if ( true === $locked ) {
				// Already processing the webhook for this entry, so we should not process it again.
				return;
			}

			$data = array(
				'payment_intent' => $session->payment_intent ?? null,
				'session'        => $session,
				'is_session'     => true,
				'setup_intent'   => null,
			);
			try {
				$result = $this->process_successful_checkout( $entry, $data, true );
				// If the form is a login form and the payment was successful, attempt to log in the user.
				if ( true === $result && isset( self::$module_settings['form-type'] ) && 'login' === self::$module_settings['form-type'] ) {
					if ( ! empty( self::$prepared_data['_user_login_data'] ) && is_array( self::$prepared_data['_user_login_data'] ) ) {
						if ( isset( self::$prepared_data['_user_login_data']['user_password'] ) ) {
							$user_data = self::$prepared_data['_user_login_data'];
							// Decrypt the user password before login.
							$decrypted_password = Forminator_CForm_Front_User_Registration::openssl_decrypt( $user_data['user_password'] );
							if ( false !== $decrypted_password ) {
								$user_data['user_password'] = $decrypted_password;
								$sign_on                    = wp_signon( $user_data );
								if ( is_wp_error( $sign_on ) ) {
									return $sign_on;
								}
							} else {
								return new WP_Error( 'failed_decrypt_password', esc_html__( 'Failed to decrypt user password.', 'forminator' ) );
							}
						}
					}
				}
			} finally {
				// Unlock the webhook processing for this entry after processing is complete.
				$webhook->unlock_webhook_processing( $entry );
			}
		}
	}

	/**
	 * Check if we should check the payment status on submission for pending entries.
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 * @return bool
	 */
	private function should_check_payment_status_on_submission( $entry ) {
		$check_payment_status = false;
		$fields               = self::get_fields();
		$stripe_field         = null;
		foreach ( $fields as $field ) {
			$field_settings = $field->to_formatted_array();
			$field_type     = Forminator_Field::get_property( 'type', $field_settings );
			if ( 'stripe-ocs' === $field_type ) {
				$stripe_field = $field_settings;
				break;
			}
		}
		if ( ! empty( $stripe_field ) ) {
			$stripe_field_obj = Forminator_Core::get_field_object( 'stripe' );
			$stripe_meta      = $stripe_field_obj->get_stripe_meta( $entry, $stripe_field );
			if ( ! empty( $stripe_meta ) && ! empty( $stripe_meta['mode'] ) ) {
				$has_active_webhook = Forminator_Gateway_Stripe::is_webhook_active( $stripe_meta['mode'] );
				if ( ! $has_active_webhook ) {
					$check_payment_status = true;
				}
			}
		}
		// For login and registration forms with automatic login enabled, we should check the payment status on submission to ensure that the user is logged in only after a successful payment.
		if ( self::allow_only_immediate_payments( self::$module_settings ) ) {
			$check_payment_status = true;
		}
		return apply_filters( 'forminator_check_payment_status_on_successful_submission', $check_payment_status, $entry );
	}

	/**
	 * Check if the form is a login or registration form with automatic login enabled.
	 * Allow only immediate payments for these forms to ensure that the user is logged in only after a successful payment.
	 *
	 * @param array $module_settings The module settings.
	 * @return bool
	 */
	public static function allow_only_immediate_payments( $module_settings ) {
		if ( ! isset( $module_settings['form-type'] ) ) {
			return false;
		}
		if ( 'login' === $module_settings['form-type'] ) {
			return true;
		}
		if ( 'registration' === $module_settings['form-type']
			&& isset( $module_settings['automatic-login'] ) && ! empty( $module_settings['automatic-login'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Process the failed email notification if it's set in the form settings.
	 *
	 * @param Forminator_Form_Entry_Model $entry Form Entry.
	 */
	public function process_failed_email( $entry ) {
		$form = clone self::$module_object;
		if ( 'quizzes' === $entry->entry_type ) {
			// For quizzes, we need to get the quiz form instead of the leads form.
			$form = Forminator_Base_Form_Model::get_model( $entry->form_id );
		}
		if ( empty( $form ) || empty( $form->notifications ) ) {
			return;
		}
		$failed_notification = $this->get_stripe_payment_failed_notification( $form->notifications );
		if ( $failed_notification ) {
			unset( $form->notifications );
			$form->notifications[] = $failed_notification;
			if ( 'custom-forms' === $entry->entry_type ) {
				$forminator_mail_sender = new Forminator_CForm_Front_Mail();
				$forminator_mail_sender->process_mail( $form, $entry );
			} elseif ( self::$is_leads && 'quizzes' === $entry->entry_type ) {
				$forminator_mail_sender = new Forminator_Quiz_Front_Mail();
				$forminator_mail_sender->process_mail( $form, $entry );
			}
		}
	}

	/**
	 * Get the stripe payment failed notification from the form notifications.
	 *
	 * @param array $notifications The form notifications.
	 * @return array|false The stripe payment failed notification if found, false otherwise.
	 */
	private function get_stripe_payment_failed_notification( $notifications ) {
		foreach ( $notifications as $notification ) {
			if ( isset( $notification['type'] ) && 'stripe-payment-failed' === $notification['type']
				&& isset( $notification['enabled'] ) && 'true' === $notification['enabled'] ) {
				return $notification;
			}
		}

		return false;
	}

	/**
	 * Get the stripe field settings from the form fields.
	 *
	 * @return array|null The stripe field settings if found, null otherwise.
	 */
	private function get_stripe_field_settings() {
		$stripe_settings = ! empty( self::$info['stripe_field'] ) ? self::$info['stripe_field'] : null;
		if ( empty( $stripe_settings ) ) {
			foreach ( self::get_fields() as $field ) {
				$field_settings = $field->to_formatted_array();
				if ( 'stripe-ocs' === Forminator_Field::get_property( 'type', $field_settings ) ) {
					$stripe_settings = $field_settings;
					break;
				}
			}
		}
		return $stripe_settings;
	}

	/**
	 * Get response for pending entry
	 *
	 * @return array
	 */
	private function get_response_for_pending_entry() {
		$response = array( 'return' => false );
		if ( ! empty( self::$prepared_data['paymentid'] ) ) {
			$session_id             = self::$prepared_data['paymentid'];
			$transient_response_key = 'forminator_payment_completed_response_' . $session_id;
			$session_data           = Forminator_Stripe::get_pending_checkout_session_data( $session_id );
			// If the session data is empty or the entry ID is not set, then the entry has not been created yet.
			if ( empty( $session_data ) || empty( $session_data['entry_id'] ) ) {
				// Check if the payment has been completed and the response is stored in a transient.
				if ( empty( $session_data ) ) {
					$payment_response = get_transient( $transient_response_key );
					if ( ! empty( $payment_response ) && isset( $payment_response['return'] ) && isset( $payment_response['data'] ) ) {
						// The stored response is only for the browser that paid.
						if ( ! Forminator_Stripe::is_payment_owner( $payment_response['owner'] ?? '' ) ) {
							return self::get_foreign_payment_response();
						}
						// Delete the transient after retrieving the response, as it is no longer needed.
						delete_transient( $transient_response_key );
						return $payment_response;
					}
				}
				return $response;
			}

			// Only the browser that started the payment may complete it or sign in with it.
			if ( ! Forminator_Stripe::is_payment_owner( $session_data['owner'] ?? '' ) ) {
				return self::get_foreign_payment_response();
			}

			// Processing swaps the module, keep the submitted form.
			$current_form_id = (int) self::$module_object->id;

			$transient_key = null;
			$has_error     = false;
			$error_message = '';
			$entry         = new Forminator_Form_Entry_Model( $session_data['entry_id'] );
			// If the entry is not found in the database, check if it's stored in a transient.
			// This is necessary because the entry may have been deleted after the payment success handled through the webhook.
			if ( empty( $entry->entry_id ) && self::prevent_store() ) {
				// If the entry is not found in the database, check if it's stored in a transient.
				$transient_key = 'forminator_entry_object_temporary_storage_' . $session_data['entry_id'];
				$entry         = get_transient( $transient_key );
			}
			if ( $entry instanceof Forminator_Form_Entry_Model && ! empty( $entry->entry_id ) && in_array( $entry->entry_type, array( 'custom-forms', 'quizzes' ), true ) ) {
				$form_id = $entry->form_id;
				if ( 'quizzes' === $entry->entry_type ) {
					$quiz_form = Forminator_Base_Form_Model::get_model( $entry->form_id );
					if ( ! empty( $quiz_form->settings['hasLeads'] ) && $quiz_form->settings['leadsId'] ) {
						// For quizzes, we need to check against the leads form ID instead of the quiz form ID.
						$form_id = $quiz_form->settings['leadsId'];
					}
				}

				// Only process entries of the current form.
				if ( $current_form_id === (int) $form_id ) {
					// Apply stored payment description once the PaymentIntent exists (after confirm).
					$stripe_settings = $this->get_stripe_field_settings();
					if ( ! empty( $stripe_settings ) ) {
						$stripe_field_obj = Forminator_Core::get_field_object( 'stripe' );
						if ( $stripe_field_obj instanceof Forminator_Stripe ) {
							$stripe_field_obj->apply_stored_checkout_payment_description(
								$stripe_settings,
								$entry,
								$session_id
							);
						}
					}

					if ( 'pending' === $entry->status && $this->should_check_payment_status_on_submission( $entry ) ) {
						// Update the payment status.
						$update_status = $this->update_payment_status_for_pending_entry( $entry, $session_id );
						if ( ! empty( $update_status ) && is_wp_error( $update_status ) ) {
							$has_error     = true;
							$error_message = $update_status->get_error_message();
						}
					}

					// We should return the response to the front end as the entry is found and belongs to the current form.
					$response['return'] = true;

					// If the entry status is already marked as failed, we should return a failed response to the frontend, as the status may have already been updated through the webhook.
					if ( 'failed' === $entry->status ) {
						$has_error     = true;
						$error_message = esc_html__( 'Payment failed, please try again!', 'forminator' );
					}
					if ( $has_error ) {
						// Clear the submit errors to prevent them from being displayed on the frontend, as form is already submitted.
						self::$submit_errors = array();
						$response['data']    = self::return_error( $error_message );
					} else {
						// Recreate the prepared data for the entry, as it may have been lost after redirecting to the payment gateway and back to the form.
						// This is necessary to ensure that the submission behavior is consistent with the behavior conditions set in the form settings.
						self::$prepared_data = recreate_prepared_data( static::$module_object, $entry );
						if ( empty( self::$info['stripe_field'] ) && ! empty( $stripe_settings ) ) {
							// If the stripe field settings are not set in self::$info, we should set them to ensure that the submission behavior is consistent with the form settings.
							self::$info['stripe_field'] = $stripe_settings;
						}
						$response['data'] = self::get_response( $entry );
					}
					if ( 'quizzes' === $entry->entry_type && 'failed' !== $entry->status ) {
						$quiz_entry = $entry->get_meta( 'entry', null );
						if ( empty( $quiz_entry ) ) {
							// Set the entry_id so that the frontend can initiate the quiz.
							$response['data']['entry_id'] = $entry->entry_id;
							$entry_token                  = $entry->get_meta( 'entry_token', null );
							if ( ! empty( $entry_token ) ) {
								// Set the entry_token so that the frontend can initiate the quiz.
								$response['data']['entry_token'] = $entry_token;
							}
						}
					}
					if ( ! empty( $transient_key ) ) {
						// If the entry was retrieved from a transient, delete the transient after returning the response.
						delete_transient( $transient_key );
					}

					// Store the response in a transient for 1 hour, so if the payment method redirects to another tab, we can retrieve the response from the transient and return it to the frontend.
					// For payment methods like Cash App, the payment might redirect to another tab where the user can complete the payment.
					set_transient( $transient_response_key, $response + array( 'owner' => $session_data['owner'] ), HOUR_IN_SECONDS );

					// Remove the pending checkout session from the local tracking (options table), as it has been processed and is no longer needed.
					Forminator_Stripe::remove_pending_checkout_session( $session_id );
					return $response;
				}
			}
		}
		return $response;
	}

	/**
	 * Message for a Stripe payment resumed in a browser that did not start it.
	 *
	 * @return string
	 */
	private static function get_foreign_payment_message() {
		return esc_html__( 'This payment was started in a different browser. If it was completed, it will be processed shortly.', 'forminator' );
	}

	/**
	 * Response for a Stripe payment resumed in a browser that did not start it.
	 *
	 * The webhook or status cron still completes the payment.
	 *
	 * @return array
	 */
	private static function get_foreign_payment_response() {
		return array(
			'return' => true,
			'data'   => self::return_error( self::get_foreign_payment_message() ),
		);
	}

	/**
	 * Handle form
	 *
	 * @since 1.0
	 * @since 1.1 change superglobal POST to `get_post_data`
	 * @since 1.5.1 utilize `_post_data` which already defined on submit
	 *
	 * @param bool $preview Is preview.
	 * @param bool $is_pending Is pending.
	 *
	 * @return array|bool
	 */
	public function handle_form( $preview = false, $is_pending = false ) {
		if ( ! self::$module_object ) {
			return false;
		}

		if ( ! $preview && ! $is_pending ) {
			$response = $this->get_response_for_pending_entry();
			if ( true === $response['return'] ) {
				return $response['data'];
			}
		}

		try {
			$this->validate_form_submission();

			$passed = self::is_honeypot();
			if ( ! $passed ) {
				// show success but dont save form.
				return self::return_success();
			}

			$entry = self::get_entry();

			self::maybe_log_legacy_stripe_deprecation();

			if ( self::is_spam() ) {
				$entry->is_spam = 1;
				self::$is_spam  = true;
			}

			// If preview, skip integrations.
			if ( ! $preview ) {
				// Never let an abandonment ping mutate a user-saved draft.
				if ( self::$is_abandoned && ! self::$is_draft && ! empty( self::$previous_draft_id ) ) {
					return self::return_success();
				}

				self::attach_addons_on_form_submit();

				$entry->draft_id = $this->set_entry_draft_id();

				if ( self::$is_spam ) {
					$entry->status = 'spam';
				} elseif ( true === $is_pending ) {
					$entry->status = 'pending';
				} elseif ( self::$is_abandoned ) {
					$entry->status = 'abandoned';
					self::check_abandonment_required_fields();
				} elseif ( self::$is_draft && ! empty( $entry->draft_id ) ) {
					$entry->status = 'draft';
				} else {
					$entry->status = 'active';
				}

				self::maybe_login( $entry );
				if ( $is_pending && ! empty( self::$response_attrs['authentication'] ) ) {
					// To handle Defender 2FA, we should not create a pending entry if the user is not authenticated yet.
					if ( in_array( self::$response_attrs['authentication'], array( 'invalid', 'show' ), true ) ) {
						return self::return_success();
					}
				}

				if ( ( $is_pending && ! self::$is_spam ) || self::$is_draft || self::$is_abandoned || ! self::prevent_store() ) {
					$previous_draft = self::$is_draft || self::$is_abandoned ? self::$previous_draft_id : null;
					$entry->save( null, null, $previous_draft );
				}
			}

			if ( self::$is_abandoned ) {
				self::save_entry_fields( $entry );
				return self::return_success();
			}

			// Delete submission if payment or file upload fails.
			$delete_submission = true;

			self::process_uploads( 'upload' );

			$stripe_response = self::handle_stripe( $entry );
			if ( ! empty( $stripe_response ) && false === $is_pending ) {
				if ( ! empty( self::$previous_draft_id ) ) {
					$entry->delete_previous_draft( self::$previous_draft_id );
				}

				return $stripe_response;
			}
			self::handle_paypal( $entry );
			unset( $delete_submission );

			self::process_uploads( 'transfer' );

			self::save_entry_fields( $entry );

			if ( 'pending' !== $entry->status ) {
				$this->post_submission_process( $entry );
			}

			$response             = self::get_response( $entry );
			$response['entry_id'] = absint( $entry->entry_id );

			// If is lead, and storing in database is disabled, then store the $entry object temporarily in transient.
			if ( self::$is_leads && self::prevent_store() && 0 === $response['entry_id'] ) {
				$random_id                 = uniqid( $entry->form_id . '_' );
				$entry->entry_id           = $random_id;
				$response['lead_entry_id'] = $random_id;
				$key                       = 'forminator_lead_object_temporary_storage_' . $random_id;
				set_transient( $key, $entry, DAY_IN_SECONDS );
			}
			do_action( 'forminator_after_handle_form', $entry );

			// If the entry is pending and there is a Stripe response, include the entry info in the pending checkout session for later processing.
			if ( true === $is_pending && $stripe_response && ! empty( self::$prepared_data['paymentid'] ) ) {
				Forminator_Stripe::update_pending_checkout_session( self::$prepared_data['paymentid'], $entry );
			}
		} catch ( Exception $e ) {
			if ( ! empty( $delete_submission ) && ! empty( $entry->entry_id ) ) {
				$entry->delete();
			}
			return self::return_error( $e->getMessage() );
		}

		return $response;
	}

	/**
	 * Log a deprecation warning when the submitted form uses the legacy Stripe (Card Element) field.
	 *
	 * Fires once per submission and only for the old `stripe` field, never for `stripe-ocs`.
	 *
	 * @since 1.57.0
	 *
	 * @return void
	 */
	private static function maybe_log_legacy_stripe_deprecation() {
		$stripe_field = self::$info['stripe_field'] ?? array();
		if ( empty( $stripe_field ) || 'stripe' !== ( $stripe_field['type'] ?? '' ) ) {
			return;
		}

		forminator_maybe_log(
			__METHOD__,
			sprintf(
				'The legacy Stripe (Card Element) field `%1$s` on form #%2$d is deprecated and will be removed in a future Forminator release. Please rebuild this form using the new Stripe field to avoid failed payments.',
				$stripe_field['element_id'] ?? '',
				self::$module_id
			)
		);
	}

	/**
	 * Check abandonment required fields
	 *
	 * @throws Exception When there is an error.
	 */
	private static function check_abandonment_required_fields() {
		$required_fields = self::$module_settings['abandonment_required_fields'] ?? array();
		if ( empty( $required_fields ) ) {
			return true;
		}
		foreach ( $required_fields as $field_id ) {
			$field_value = filter_input( INPUT_POST, $field_id );
			if ( empty( $field_value ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: Field name */
						esc_html__( 'Field %s is required', 'forminator' ),
						esc_html( $field_id )
					)
				);
			}
		}
		return true;
	}

	/**
	 * Send email
	 *
	 * @param object $entry Entry.
	 */
	private static function send_email( $entry ) {
		if ( ! self::$is_leads && ! self::$is_draft && ! self::$is_spam ) {
			$forminator_mail_sender = new Forminator_CForm_Front_Mail();
			$forminator_mail_sender->process_mail( self::$module_object, $entry );
		}
	}

	/**
	 * Save entry fields
	 *
	 * @param object $entry Entry object.
	 * @throws Exception When there is an error.
	 */
	private static function save_entry_fields( $entry ) {
		self::remove_password();
		forminator_custom_sequence_assign_custom_id( $entry, self::$module_id );
		self::handle_hidden_fields_after_entry_save( $entry, self::$module_id );

		/**
		 * Action called before setting fields to database
		 *
		 * @since 1.0.2
		 *
		 * @param Forminator_Form_Entry_Model $entry - the entry model.
		 * @param int $form_id - the form id.
		 * @param array $field_data_array - the entry data.
		 */
		do_action( 'forminator_custom_form_submit_before_set_fields', $entry, self::$module_id, self::$info['field_data_array'] );

		// ADDON add_entry_fields.
		// @since 1.2 Add field_data_array to param.
		$added_data_array = self::$info['field_data_array'];
		if ( ! self::$is_draft && ! self::$is_abandoned ) {
			// Preserve raw choice values before labels replace them.
			$choice_values = array();
			foreach ( $added_data_array as $key => $field_item ) {
				if ( empty( $field_item['name'] ) ) {
					continue;
				}
				$slug = $field_item['name'];
				if ( 0 === strpos( $slug, 'select-' )
					|| 0 === strpos( $slug, 'radio-' )
					|| 0 === strpos( $slug, 'checkbox-' )
				) {
					$choice_values[ $slug ] = $field_item['value'];
				}

				// Remove postdata fields from the entry, as they are handled in the maybe_create_post method, and avoid resetting the postdata fields when the entry is updated.
				if ( 'pending' !== $entry->status && 0 === strpos( $slug, 'postdata-' ) ) {
					unset( $added_data_array[ $key ] );
				}
			}
			if ( $choice_values ) {
				$added_data_array[] = array(
					'name'  => '_forminator_choice_values',
					'value' => $choice_values,
				);
			}

			$added_data_array = self::replace_values_to_labels( $added_data_array, $entry );
		} else {
			// remove IP for drafts.
			$ip_key = array_search( '_forminator_user_ip', array_column( $added_data_array, 'name' ), true );
			if ( false !== $ip_key ) {
				unset( $added_data_array[ $ip_key ] );
			}
		}

		if ( self::$is_leads ) {
			self::$response_attrs['entry_id'] = $entry->entry_id;

			// Bind the pending entry to this visitor so nobody else can complete it.
			$entry_token                         = wp_generate_password( 32, false, false );
			self::$response_attrs['entry_token'] = $entry_token;

			$added_data_array[] = array(
				'name'  => 'entry_token',
				'value' => $entry_token,
			);

			$added_data_array[] = array(
				'name'  => 'skip_form',
				'value' => '0',
			);
		}

		// Add user registration data to entry fields if the entry is pending and the form type is registration.
		if ( 'pending' === $entry->status && isset( self::$module_settings['form-type'] ) && 'registration' === self::$module_settings['form-type']
			&& self::$registration && method_exists( self::$registration, 'get_user_registration_data' ) ) {
			$user_data = self::$registration->get_user_registration_data();
			if ( ! empty( $user_data ) ) {
				if ( isset( $user_data['user_pass'] ) ) {
					// Encrypt the password before saving it to the entry fields.
					$user_data['user_pass'] = Forminator_CForm_Front_User_Registration::openssl_encrypt( $user_data['user_pass'] );
					if ( false === $user_data['user_pass'] ) {
						// If encryption fails, delete the entry and throw an exception to prevent saving the entry with unencrypted password.
						$entry->delete();
						throw new Exception( esc_html__( 'Failed to encrypt the password.', 'forminator' ) );
					}
				}
				$added_data_array[] = array(
					'name'  => '_user_registration_data',
					'value' => $user_data,
				);
				// Add automatic login flag to entry fields if the form type is registration and automatic login is enabled.
				if ( isset( self::$module_settings['automatic-login'] ) && ! empty( self::$module_settings['automatic-login'] ) ) {
					$added_data_array[] = array(
						'name'  => '_user_registration_automatic_login',
						'value' => 1,
					);
				}
			}
		}

		// Keep the draft ID on the pending entry, so the draft can be deleted once the payment is completed.
		if ( 'pending' === $entry->status && ! empty( self::$previous_draft_id ) ) {
			$added_data_array[] = array(
				'name'  => '_previous_draft_id',
				'value' => self::$previous_draft_id,
			);
		}

		// Keep the submitter, 0 for guests, as integrations run without them once the payment completes.
		if ( 'pending' === $entry->status ) {
			$added_data_array[] = array(
				'name'  => '_submitter_user_id',
				'value' => get_current_user_id(),
			);
		}

		$entry->set_fields( $added_data_array );
	}

	/**
	 * Prepare submitted data to sending to addons
	 *
	 * @param array $current_entry_fields Entry fields.
	 * @return array
	 */
	protected static function get_prepared_submitted_data_for_addons( $current_entry_fields ) {
		$data = self::get_submitted_data();

		foreach ( wp_list_pluck( $current_entry_fields, 'name' ) as $element_id ) {
			$data[ $element_id ] = Forminator_Integration_Form_Hooks::prepare_field_value_for_addon( $element_id, $current_entry_fields, $data );
		}

		$data = Forminator_Integration_Form_Hooks::prepare_stripe_subscription_id_for_addon( $data, $current_entry_fields );

		// Remove technical info.
		$data = array_filter(
			$data,
			function ( $key ) {
				return 0 !== strpos( $key, 'group-' ) || '-copies' !== substr( $key, -7 );
			},
			ARRAY_FILTER_USE_KEY
		);

		return $data;
	}

	/**
	 * Get post data fields and replace placeholders in Custom Fields
	 *
	 * @param object|null $entry Form entry object.
	 * @return array
	 */
	private static function get_post_data_fields( $entry = null ) {
		// Get saved postdata fields data.
		$postdata_fields = self::get_specific_field_data( 'postdata' );
		if ( empty( $postdata_fields ) ) {
			return;
		}

		// Replace placeholders in Custom Fields.
		foreach ( $postdata_fields as $field_key => $field ) {
			if ( empty( $field['field_array']['options'] ) || ! is_array( $field['field_array']['options'] ) ) {
				continue;
			}
			$custom_fields = wp_list_pluck( $field['field_array']['options'], 'value' );
			foreach ( $custom_fields as $cf_key => $cf_value ) {
				if ( strpos( $cf_value, '{calculation-' ) !== false ) {
					$postdata_fields[ $field_key ]['value']['post-custom'][ $cf_key ]['value'] = forminator_replace_form_data( $cf_value, self::$module_object );
				} elseif ( 'submission_id' === ( self::$prepared_data[ trim( $cf_value, ' {}' ) ] ?? '' ) ) {
					// Evaluated before entry save; re-resolve once the entry ID is available.
					$postdata_fields[ $field_key ]['value']['post-custom'][ $cf_key ]['value'] = forminator_get_submission_id( null, $entry );
				}
			}
		}

		return $postdata_fields;
	}

	/**
	 * Create post for lead form.
	 *
	 * @param Forminator_Form_Entry_Model $entry Form entry object.
	 * @param int                         $lead_id Lead form ID.
	 * @return void
	 */
	public static function maybe_create_post_for_lead_form( $entry, $lead_id = 0 ) {
		if ( $lead_id ) {
			self::$module_object = Forminator_Base_Form_Model::get_model( $lead_id );
			self::$prepared_data = recreate_prepared_data( self::$module_object, $entry );
			// Set the entry ID in the prepared data so that the form fields can be correctly processed for addon.
			self::$prepared_data['entry_id'] = $entry->entry_id;
			// If the entry is pending, we need to re-prepare the fields info to handle post submission process.
			$fields = self::get_fields();
			foreach ( $fields as $field_index => $field ) {
				self::set_field_data_array( $field_index, $field );
			}

			self::maybe_create_post( $entry );
		}
	}

	/**
	 * Maybe create post
	 *
	 * @param Forminator_Form_Entry_Model|null $entry Form entry object.
	 * @throws Exception When there is an error.
	 */
	private static function maybe_create_post( $entry = null ) {
		if ( self::$is_draft || self::$is_spam ) {
			return;
		}

		// Get saved postdata fields data and replace upload tags with uploaded data.
		$postdata_fields = self::get_post_data_fields( $entry );
		if ( empty( $postdata_fields ) ) {
			return;
		}
		$postdata_return = self::create_post_from_postdata( $postdata_fields );

		if ( isset( $postdata_return['type'] ) && 'error' === $postdata_return['type'] ) {
			throw new Exception( esc_html( $postdata_return['value'] ) );
		}

		foreach ( $postdata_return as $postdata ) {
			if ( 'success' === $postdata['type'] ) {
				$post_field_data = array();
				foreach ( self::$info['field_data_array'] as $field_key => $field_datum ) {
					if ( $field_datum['name'] === $postdata['field_id'] ) {
						self::$info['field_data_array'][ $field_key ] = array(
							'name'  => $postdata['field_id'],
							'value' => $postdata['field_data'],
						);
						$post_field_data[ $field_key ]                = self::$info['field_data_array'][ $field_key ];
					}
				}
				$entry->reset_fields( $post_field_data );
			} else {
				throw new Exception( esc_html( $postdata['value'] ) );
			}
		}
	}

	/**
	 * Get submission response
	 *
	 * @param object $entry Form entry object.
	 * @return mixed
	 */
	private static function get_response( $entry ) {
		if ( self::$is_draft ) {
			return self::get_draft_response( $entry );
		}

		self::set_behaviour_settings( $entry );
		$response = self::return_success();

		if ( empty( self::$module_settings['enable-ajax'] ) ) {
			$is_ajax_enabled = false;
		} else {
			$is_ajax_enabled = filter_var( self::$module_settings['enable-ajax'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( $is_ajax_enabled ) {
			// Hide select options that already are reached limit.
			$response['select_field'] = self::get_limited_select_values();
		}

		$response = self::handle_product_fields( $response, $entry );

		return $response;
	}

	/**
	 * Get draft response
	 *
	 * @param object $entry Form entry object.
	 * @return type
	 */
	private static function get_draft_response( $entry ) {
		$setting = self::$module_settings;
		// Will be used to auto-fill the email field in send draft link form.
		$first_email = self::get_first_email( self::$prepared_data );
		if ( ! is_null( $first_email ) ) {
			self::$response_attrs['first_email'] = $first_email;
		}

		self::$response_attrs['draft_id']           = $entry->draft_id;
		self::$response_attrs['page_id']            = self::$prepared_data['page_id'];
		self::$response_attrs['enable_email_link']  = isset( $setting['sc_email_link'] ) ? filter_var( $setting['sc_email_link'], FILTER_VALIDATE_BOOLEAN ) : true;
		self::$response_attrs['email_label']        = isset( $setting['sc_email_input_label'] ) ? $setting['sc_email_input_label'] : __( 'Send draft link to', 'forminator' );
		self::$response_attrs['email_placeholder']  = isset( $setting['sc_email_placeholder'] ) ? $setting['sc_email_placeholder'] : __( 'E.g., johndoe@gmail.com', 'forminator' );
		self::$response_attrs['email_button_label'] = isset( $setting['sc_email_button_label'] ) ? $setting['sc_email_button_label'] : __( 'Send draft link', 'forminator' );
		self::$response_attrs['retention_period']   = isset( $setting['sc_draft_retention'] ) ? $setting['sc_draft_retention'] : 30;

		return self::return_success( $setting['sc_message'] );
	}

	/**
	 * Handle product fields
	 *
	 * @param array  $response Response.
	 * @param object $entry Form entry object.
	 */
	private static function handle_product_fields( $response, $entry ) {
		$product_fields = self::get_specific_field_data( 'product' );
		if ( ! empty( $product_fields ) ) {
			// Process purchase.
			$page_id  = self::$prepared_data['page_id']; // use page id to get permalink for redirect.
			$shipping = isset( self::$prepared_data['product-shipping'] ) ? self::$prepared_data['product-shipping'] : 0;

			/**
			 * Process purchase
			 *
			 * @since 1.0.0
			 *
			 * @param array $response - the response array.
			 * @param array $product_fields - the product fields.
			 * @param int $entry_id - the entry id ( reference for callback).
			 * @param int $page_id - the page id. Used to generate a return url.
			 * @param int $shipping - the shipping cost.
			 */
			$response = apply_filters( 'forminator_cform_process_purchase', $response, $product_fields, self::$info['field_data_array'], $entry->entry_id, $page_id, $shipping );
		}

		return $response;
	}

	/**
	 * Get product field data
	 *
	 * @param string $type Field type.
	 * @return array
	 * @throws Exception When there is an error.
	 */
	private static function get_specific_field_data( $type ) {
		$product_fields = array();
		foreach ( self::$info['field_data_array'] as $data ) {
			if ( ! isset( $data['field_type'] ) || $type !== $data['field_type'] ) {
				continue;
			}
			$product_fields[] = $data;
		}

		return $product_fields;
	}

	/**
	 * Get fields
	 *
	 * @throws Exception When there is an error.
	 */
	private static function get_fields() {
		$fields = self::$module_object->get_real_fields();

		if ( ! $fields ) {
			throw new Exception( esc_html__( 'At least one field must be filled out to submit the form.', 'forminator' ) );
		}

		return $fields;
	}

	/**
	 * Check if submission is possible.
	 *
	 * @throws Exception When there is an error.
	 */
	private static function can_submit() {
		$form_submit = self::$module_object->form_can_submit();
		if ( ! $form_submit['can_submit'] ) {
			throw new Exception( esc_html( $form_submit['error'] ) );
		}
	}

	/**
	 * Check submit button visibility condition after fields data is prepared.
	 *
	 * @since 1.52.0
	 *
	 * @throws Exception When submit button is hidden by conditions.
	 */
	private static function check_submit_visibility() {
		$form_settings = self::$module_settings;

		if ( empty( $form_settings['submitData'] ) ) {
			return;
		}

		if ( true === Forminator_Field::is_hidden( $form_settings['submitData'] ) && false === self::$module_object->has_active_paypal() ) {
			$invalid_form_message = esc_html__( 'Error: Your form is not valid, please fix the errors!', 'forminator' );
			if ( ! empty( $form_settings['submitData']['custom-invalid-form-message'] ) ) {
				$invalid_form_message = $form_settings['submitData']['custom-invalid-form-message'];
			}
			throw new Exception( esc_html( $invalid_form_message ) );
		}
	}

	/**
	 * Honeypot check
	 *
	 * @return boolean
	 */
	private static function is_honeypot() {
		if ( isset( self::$module_settings['honeypot'] ) && filter_var( self::$module_settings['honeypot'], FILTER_VALIDATE_BOOLEAN ) ) {
			$total_fields = count( self::$module_object->get_real_fields() ) + 1;
			if ( ! empty( self::$prepared_data[ "input_$total_fields" ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Set behavior settings
	 *
	 * @param object $entry Entry.
	 */
	private static function set_behaviour_settings( $entry ) {
		$all_behaviours   = array( 'behaviour-thankyou', 'behaviour-hide', 'behaviour-redirect' );
		$behavior_options = self::get_relevant_behavior_options();
		if ( ! isset( $behavior_options['submission-behaviour'] ) || ! in_array( $behavior_options['submission-behaviour'], $all_behaviours, true ) ) {
			return;
		}

		$custom_form                   = self::$module_object;
		self::$response_attrs['behav'] = self::get_submission_behaviour( $behavior_options );
		if ( 'behaviour-redirect' === $behavior_options['submission-behaviour'] && ! empty( $behavior_options['redirect-url'] ) ) {
			self::$response_attrs['redirect'] = true;
			$url_encode                       = true;
			if ( '{' === substr( $behavior_options['redirect-url'], 0, 1 ) && '}' === substr( $behavior_options['redirect-url'], -1 ) ) {
				$url_encode = false;
			}
			// replace form data vars with value.
			$redirect_url = forminator_replace_form_data( $behavior_options['redirect-url'], $custom_form, $entry, false, $url_encode );
			$redirect_url = html_entity_decode( $redirect_url );
			$tab_value    = isset( $behavior_options['newtab'] ) ? $behavior_options['newtab'] : 'sametab';
			$newtab       = forminator_replace_form_data( $tab_value, $custom_form, $entry );
			// replace misc data vars with value.
			$redirect_url                   = forminator_replace_variables( $redirect_url, self::$module_id );
			$newtab                         = forminator_replace_variables( $newtab, self::$module_id );
			self::$response_attrs['url']    = esc_url_raw( $redirect_url );
			self::$response_attrs['newtab'] = esc_html( $newtab );
		}

		$thankyou_message = self::get_thankyou_message( $custom_form, $behavior_options, $entry );

		if ( ( ! isset( $tab_value ) || 'newtab_thankyou' === $tab_value ) && ! empty( $thankyou_message ) ) {
			self::$response_attrs['message'] = $thankyou_message;
			if ( ! empty( $behavior_options['autoclose'] ) ) {
				self::$response_attrs['fadeout']      = $behavior_options['autoclose'];
				self::$response_attrs['fadeout_time'] = ! empty( $behavior_options['autoclose-time'] )
						? $behavior_options['autoclose-time'] * 1000 : 0;
			}
		}
	}

	/**
	 * Get "Thank you" message
	 *
	 * @param object $custom_form Custom form.
	 * @param array  $behavior_options Releavant behavior options.
	 * @param object $entry Entry object.
	 * @return type
	 */
	private static function get_thankyou_message( $custom_form, $behavior_options, $entry ) {

		if ( ! empty( $custom_form->settings['activation-method'] )
			&& isset( $behavior_options[ $custom_form->settings['activation-method'] . '-thankyou-message' ] )
		) {
			$thankyou_message = $behavior_options[ $custom_form->settings['activation-method'] . '-thankyou-message' ];
		} elseif ( isset( $behavior_options['thankyou-message'] ) ) {
			$thankyou_message = $behavior_options['thankyou-message'];
		} else {
			$thankyou_message = '';
		}

		/**
		 * Filter thankyou message
		 *
		 * @since 1.11
		 *
		 * @param string $thankyou_message "Thank you" message.
		 * @param array $submitted_data
		 * @param Forminator_Form_Model $custom_form
		 *
		 * @return string
		 */
		$thankyou_message = apply_filters( 'forminator_custom_form_thankyou_message', $thankyou_message, $custom_form );
		// replace form data vars with value.
		$thankyou_message = forminator_replace_form_data( $thankyou_message, $custom_form, $entry, true );
		// replace misc data vars with value.
		$message = forminator_replace_variables( $thankyou_message, self::$module_id );

		return $message;
	}

	/**
	 * Get submission behavior
	 *
	 * @param array $behavior_options Behavior settings.
	 * @return string
	 */
	private static function get_submission_behaviour( $behavior_options ) {
		$submission_behaviour = 'behaviour-thankyou';

		if ( isset( $behavior_options['submission-behaviour'] ) ) {
			$submission_behaviour = $behavior_options['submission-behaviour'];
		}

		// If Stripe field exist & submit is AJAX we fall back to hide to force page reload when form submitted.
		if ( ! empty( self::$info['stripe_field'] ) && self::$module_object->is_ajax_submit() ) {
			$submission_behaviour = 'behaviour-hide';
		}

		$submission_behaviour = apply_filters( 'forminator_custom_form_get_submission_behaviour', $submission_behaviour, self::$module_id, $behavior_options );

		return $submission_behaviour;
	}

	/**
	 * Get the relevant behavior which will be applied according conditions
	 *
	 * @return array|false Return the relevant behavior or false if no behavior found.
	 */
	private static function get_relevant_behavior_options() {
		$behavior_array = self::$module_object->get_behavior_array();

		foreach ( $behavior_array as $behavior ) {
			if ( empty( $behavior['conditions'] ) ) {
				// If this behavior doesn't have any conditions - return it.
				return $behavior;
			}
			$condition_rule      = isset( $behavior['condition_rule'] ) ? $behavior['condition_rule'] : 'all';
			$condition_fulfilled = 0;

			foreach ( $behavior['conditions'] as $condition ) {
				$is_matched = Forminator_Field::is_condition_matched( $condition );
				if ( $is_matched ) {
					if ( 'any' === $condition_rule ) {
						// If this behavior is matched the conditions - return it. No need to check others.
						return $behavior;
					}
					++$condition_fulfilled;
				}
			}
			if ( 'all' === $condition_rule && count( $behavior['conditions'] ) === $condition_fulfilled ) {
				// Return this behavior if all conditions are matched.
				return $behavior;
			}
		}

		// If all behaviors aren't matched - return false.
		return false;
	}

	/**
	 * Get select values according limit.
	 *
	 * @return array
	 */
	private static function get_limited_select_values() {
		$result  = array();
		$form_id = self::$module_id;
		if ( self::$is_draft || empty( self::$info['select_field_value'] ) ) {
			return $result;
		}

		if ( isset( self::$prepared_data['lead_quiz'] ) ) {
			$form_id = self::$prepared_data['lead_quiz'];
		}

		foreach ( self::$info['select_field_value'] as $select_name => $options ) {
			$select_value = array();
			foreach ( $options as $option ) {
				if ( Forminator_Form_Entry_Model::is_option_limit_reached( $form_id, $select_name, $option['type'], $option ) ) {
					$select_value[] = $option;
				}
			}
			if ( ! empty( $select_value ) ) {
				$result[ $select_name ] = $select_value;
			}
		}
		return $result;
	}

	/**
	 * Remove a password field.
	 */
	private static function remove_password() {
		foreach ( self::$info['field_data_array'] as $key => $field_arr ) {
			if ( false !== stripos( $field_arr['name'], 'password-' ) ) {
				unset( self::$info['field_data_array'][ $key ] );
				break;
			}
		}
	}

	/**
	 * Replace values to labels for radios, selectboxes and checkboxes
	 *
	 * @param array $data Data.
	 * @param array $entry Entry.
	 * @return array
	 */
	private static function replace_values_to_labels( $data, $entry ) {
		foreach ( $data as $key => $value ) {
			if ( empty( $value['name'] ) ) {
				continue;
			}
			$slug = $value['name'];
			if ( 0 === strpos( $slug, 'custom-' ) ) {
				continue;
			}
			if ( strpos( $slug, 'radio' ) !== false
					|| strpos( $slug, 'select' ) !== false
					|| strpos( $slug, 'checkbox' ) !== false
					) {
				$data[ $key ]['value'] = forminator_replace_form_data( '{' . $slug . '}', self::$module_object, $entry, true );
			}
			if ( strpos( $slug, 'name' ) !== false ) {
				$full_name = $value['value'] ?? array();
				if ( isset( $full_name['prefix'] ) ) {
					$full_name['prefix']   = forminator_translate_name_prefix( $full_name['prefix'], self::$module_object );
					$data[ $key ]['value'] = $full_name;
				}
			}
		}

		return $data;
	}

	/**
	 * Multiple File upload for ajax multi-upload
	 */
	public function multiple_file_upload() {
		$this->init_properties();

		if ( ! isset( self::$prepared_data['nonce'] ) || ! wp_verify_nonce( self::$prepared_data['nonce'], 'forminator_submit_form' . self::$module_id ) ) {
			wp_send_json_error( new WP_Error( 'invalid_code' ) );
		}

		if ( empty( self::$prepared_data['element_id'] ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => esc_html__( 'Upload field ID is missing.', 'forminator' ),
				)
			);
		}

		$element_id  = sanitize_text_field( self::$prepared_data['element_id'] );
		$field_array = self::$module_object->get_field( $element_id, true );

		if ( empty( $field_array ) || empty( $field_array['type'] ) || 'upload' !== $field_array['type'] ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => esc_html__( 'Upload field doesn\'t exist in your form!', 'forminator' ),
				)
			);
		}

		$upload_field_obj = Forminator_Core::get_field_object( 'upload' );
		$response         = $upload_field_obj->handle_file_upload( self::$module_id, $field_array, self::$prepared_data, 'upload' );

		if ( ! $response['success'] || isset( $response['errors'] ) ) {
			wp_send_json_error( $response );
		}

		// Do not return filesystem path, public URL, or stored file name.
		// Client keeps only an opaque access token resolved server-side on submit.
		unset( $response['file_path'], $response['file_url'], $response['file_name'] );
		if ( empty( $response['access_token'] ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'message' => esc_html__( 'Error saving form. Upload error.', 'forminator' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'success'      => true,
				'message'      => '',
				'access_token' => $response['access_token'],
			)
		);
	}

	/**
	 * Response message
	 *
	 * @since 1.0
	 * @since 1.1 change superglobal POST to `get_post_data`
	 * @since 1.5.1 utilize `_post_data` which already defined on submit
	 *
	 * @param int $form_id Form Id.
	 * @param int $render_id Render Id.
	 */
	public function form_response_message( $form_id, $render_id ) {
		$post_render_id = isset( self::$prepared_data['render_id'] ) ? sanitize_text_field( self::$prepared_data['render_id'] ) : 0;
		$response       = self::$response;

		// only show to related form.
		if ( ! empty( $response ) && is_array( $response ) && (int) $form_id === (int) self::$module_id && (int) $render_id === (int) $post_render_id ) {
			$label_class = $response['success'] ? 'forminator-success' : 'forminator-error';
			?>
			<div class="forminator-response-message forminator-show <?php echo esc_attr( $label_class ); ?>"
				tabindex="-1">
				<label class="forminator-label--<?php echo esc_attr( $label_class ); ?>"><?php echo wp_kses_post( $response['message'] ); ?></label>
				<?php
				if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
					?>
					<ul class="forminator-screen-reader-only">
						<?php
						foreach ( $response['errors'] as $key => $error ) {
							foreach ( $error as $id => $value ) {
								?>
								<li><?php echo esc_html( $value ); ?></li>
								<?php
							}
						}
						?>
					</ul>
					<?php
				}
				?>
			</div>
			<?php

			if ( isset( $response['success'] ) && $response['success'] && isset( $response['behav'] ) && ( 'behaviour-hide' === $response['behav'] || ( isset( $response['newtab'] ) && 'newtab_hide' === $response['newtab'] ) ) ) {
				$selector = '#forminator-module-' . $form_id . '[data-forminator-render="' . $render_id . '"]';
				?>
				<script type="text/javascript">var ForminatorFormHider =
					<?php
					echo wp_json_encode(
						array(
							'selector' => $selector,
						)
					);
					?>
				</script>
				<?php
			}
			if ( isset( $response['success'] ) && $response['success'] && isset( $response['behav'] ) && 'behaviour-redirect' === $response['behav'] && isset( $response['newtab'] ) && ( 'newtab_hide' === $response['newtab'] || 'newtab_thankyou' === $response['newtab'] ) ) {
				$url = $response['url'];
				?>
				<script type="text/javascript">var ForminatorFormNewTabRedirect =
					<?php
					echo wp_json_encode(
						array(
							'url' => $url,
						)
					);
					?>
				</script>
				<?php
			}
		}
	}

	/**
	 * Get invalid form message
	 *
	 * @since 1.0
	 *
	 * @return mixed
	 */
	private static function get_invalid_form_message() {
		$invalid_form_message = esc_html__( 'Error: Your form is not valid, please fix the errors!', 'forminator' );
		if ( ! empty( self::$module_settings['submitData']['custom-invalid-form-message'] ) ) {
			$invalid_form_message = self::$module_settings['submitData']['custom-invalid-form-message'];
		}

		return apply_filters( 'forminator_custom_form_invalid_form_message', $invalid_form_message, self::$module_id );
	}

	/**
	 * Executor On form submit for attached addons
	 *
	 * @see   Forminator_Integration_Form_Hooks::on_module_submit()
	 * @since 1.1
	 *
	 * @return bool true on success|string error message from addon otherwise
	 * @throws Exception When there is an error.
	 */
	private static function attach_addons_on_form_submit() {
		if ( self::$is_draft || self::$is_spam || self::$is_abandoned ) {
			return;
		}

		// find is_form_connected.
		$connected_addons = forminator_get_addons_instance_connected_with_module( self::$module_id, 'form' );

		foreach ( $connected_addons as $connected_addon ) {
			try {
				$form_hooks = $connected_addon->get_addon_hooks( self::$module_id, 'form' );
				if ( ! $form_hooks instanceof Forminator_Integration_Form_Hooks ) {
					continue;
				}
				$addon_return = $form_hooks->on_module_submit( self::$prepared_data );
			} catch ( Exception $e ) {
				forminator_addon_maybe_log( $connected_addon->get_slug(), 'failed to attach_addons_on_form_submit', $e->getMessage() );
			}
			if ( true !== $addon_return ) {
				throw new Exception( esc_html( $addon_return ) );
			}
		}

		return true;
	}

	/**
	 * Process stripe charge
	 *
	 * @since 1.7
	 * @param object $entry Entry.
	 *
	 * @return array|void
	 * @throws Exception When there is an error.
	 */
	private static function stripe_field_to_entry_data_array( $entry ) {
		$field_object = Forminator_Core::get_field_object( 'stripe' );

		if ( ! $field_object ) {
			return;
		}

		$field = self::$info['stripe_field'];

		/**
		 * Fires before process stripe
		 *
		 * @since 1.7
		 *
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field properties.
		 * @param array $submitted_data
		 * @param array $field_data_array
		 */
		do_action( 'forminator_custom_form_before_stripe_charge', self::$module_object, $field, self::$prepared_data, self::$info['field_data_array'] );

		$payment_plan = $field_object->get_payment_plan( $field );
		if ( 'single' === $payment_plan['payment_method'] ) {
			$mode            = isset( $field['mode'] ) ? $field['mode'] : 'sandbox';
			$plan_data_array = self::handle_stripe_single( $field_object, $field, $entry, $mode );
		} else {
			$plan_data_array = self::handle_stripe_subscription( $field_object, $field, $entry, $payment_plan );
		}

		if ( is_wp_error( $plan_data_array ) ) {
			throw new Exception( esc_html( $plan_data_array->get_error_message() ) );
		}

		if ( ! empty( $plan_data_array ) ) {
			self::$info['field_data_array'][] = $plan_data_array;

			return $plan_data_array;
		}
	}

	/**
	 * Process PayPal charge
	 *
	 * @since 1.7
	 * @param object $entry Entry.
	 *
	 * @return array
	 * @throws Exception When there is an error.
	 */
	private static function paypal_field_to_entry_data_array( $entry ) {
		$field_object = Forminator_Core::get_field_object( 'paypal' );

		if ( ! $field_object ) {
			return;
		}

		$field = self::$info['paypal_field'];

		/**
		 * Fires before process paypal
		 *
		 * @since 1.7
		 *
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field properties.
		 * @param array $submitted_data
		 * @param array $field_data_array
		 */
		do_action( 'forminator_custom_form_before_paypal_charge', self::$module_object, $field, self::$prepared_data, self::$info['field_data_array'] );

		$entry_data        = $field_object->process_to_entry_data( $field );
		$paypal_entry_data = array(
			'name'  => $field['element_id'],
			'value' => $entry_data,
		);

		/**
		 * Filter paypal entry data that might be stored/used later
		 *
		 * @since 1.7
		 *
		 * @param array $calculation_entry_data
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field_properties.
		 * @param array $field_data_array
		 *
		 * @return array
		 */
		$paypal_entry_data = apply_filters( 'forminator_custom_form_paypal_entry_data', $paypal_entry_data, self::$module_object, $field, self::$info['field_data_array'] );

		if ( empty( $paypal_entry_data ) ) {
			return;
		}
		forminator_maybe_log( __METHOD__, $paypal_entry_data['value'] );
		if ( ! empty( $paypal_entry_data['value']['error'] ) ) {
			throw new Exception( esc_html( $paypal_entry_data['value']['error'] ) );
		}

		/**
		 * Fires after charge paypal
		 *
		 * @since 1.7
		 *
		 * @param Forminator_Form_Model $module_object
		 * @param array $field field properties.
		 * @param array $paypal_entry_data
		 * @param array $submitted_data
		 * @param array $field_data_array
		 */
		do_action( 'forminator_custom_form_after_paypal_charge', self::$module_object, $field, $paypal_entry_data, self::$prepared_data, self::$info['field_data_array'] );

		$paypal  = new Forminator_PayPal_Express();
		$mode    = isset( $field['mode'] ) ? $field['mode'] : 'sandbox';
		$capture = $paypal->capture_order( $entry_data['transaction_id'], $mode );

		if ( ! isset( $capture->status ) || 'COMPLETED' !== $capture->status ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'transaction_id' => $entry_data['transaction_id'],
					'mode'           => $mode,
					'order_status'   => isset( $capture->status ) ? $capture->status : '',
					'capture_result' => $capture,
				)
			);

			throw new Exception( esc_html__( 'Payment failed, please try again!', 'forminator' ) );
		}
		$paypal_entry_data['value']['status'] = 'COMPLETED';

		if ( isset( $capture->purchase_units[0]->payments->captures[0]->id ) ) {
			$transaction_id = $capture->purchase_units[0]->payments->captures[0]->id;

			$paypal_entry_data['value']['transaction_id']   = $transaction_id;
			$paypal_entry_data['value']['transaction_link'] = $field_object::get_transanction_link( $mode, $transaction_id );
		}

		self::$info['field_data_array'][] = $paypal_entry_data;
	}

	/**
	 * Prepare hidden and visible fields
	 */
	public static function check_fields_visibility() {
		// Check if visibility is already checked to avoid unnecessary processing.
		if ( true === self::$is_fields_visibility_checked ) {
			return;
		}
		$fields         = self::$module_object->get_fields();
		$visible_fields = array(); // Visible fields with calculable values.
		$unspecified    = array(); // it's not clear these fields are hidden or visible.
		$field_slugs    = wp_list_pluck( $fields, 'slug' );

		do { // We do it recursevely because sometimes fields on which visibility depends are placed in the array after dependent fields.
			$previous_unspecified = $unspecified;
			$unspecified          = array();

			foreach ( $fields as $field ) {
				$field_settings = $field->to_formatted_array();
				$field_id       = Forminator_Field::get_property( 'element_id', $field_settings );
				$group_suffix   = '';
				$i              = 1;
				$group_copies   = ! empty( self::$prepared_data[ $field->parent_group . '-copies' ] )
						? self::$prepared_data[ $field->parent_group . '-copies' ]
						: array();

				do { // Do it recursevely for repeated fields.
					if ( $i > 1 ) {
						$group_suffix = '-' . $i;
					}

					// Skip if this field is already checked.
					if ( in_array( $field_id . $group_suffix, array_keys( $visible_fields ), true )
							|| in_array( $field_id . $group_suffix, self::$hidden_fields, true ) ) {
						continue;
					}

					// Skip if the parent group is hidden.
					if ( ! empty( $field->parent_group ) && in_array( $field->parent_group, self::$hidden_fields, true ) ) {
						self::update_hidden_fields_array( $field_id, $group_suffix, $field_settings );
						continue;
					}

					$conditions   = Forminator_Field::get_field_conditions( $field_settings, $group_suffix );
					$field_type   = Forminator_Field::get_property( 'type', $field_settings );
					$field_object = Forminator_Core::get_field_object( $field_type );

					// Skip if field object is not found.
					if ( empty( $field_object ) ) {
						continue;
					}

					// if it's stripe field and there is stripe OCS field - skip it.
					if ( 'stripe' === $field_type && in_array( 'stripe-ocs-1', $field_slugs, true ) ) {
						continue;
					}

					if ( 'stripe-ocs' === $field_type && 'true' === filter_input( INPUT_POST, 'stripe-intent' ) ) {
						// Do not skip saving 'stripe_field' - even if they unspecified some code uses them.
						self::$info['stripe_field'] = $field_settings;
					}

					if ( $conditions ) {
						$dependent_fields = wp_list_pluck( $conditions, 'element_id' );
						$depends          = self::dependencies_not_ready( $dependent_fields, $visible_fields );
						if ( $depends ) {
							$unspecified[ $field_id . $group_suffix ] = $conditions;
							continue;
						} else {
							$is_hidden = Forminator_Field::is_hidden( $field_settings, array( 'conditions' => $conditions ), $group_suffix );
							if ( $is_hidden ) {
								self::update_hidden_fields_array( $field_id, $group_suffix, $field_settings );
								continue;
							}
						}
					}

					$submitted_field_data = self::$prepared_data[ $field_id . $group_suffix ] ?? null;
					$calculable_value     = $field_object::get_calculable_value( $submitted_field_data, $field_settings );
					if ( 'calculation' !== $field_type ) {
						if ( in_array( $field_type, array( 'stripe', 'stripe-ocs', 'paypal' ), true ) ) {
							$dependent_fields = $field_object->get_amount_dependent_fields( $field_settings );
							$depends          = self::dependencies_not_ready( $dependent_fields, $visible_fields );
							if ( $depends ) {
								$unspecified[ $field_id ] = true;
								continue;
							}
							$amount = $field_object->get_payment_amount( $field_settings );

							$visible_fields[ $field_id ] = $amount;
							if ( ! empty( self::$prepared_data[ $field_id ] ) ) {
								self::$prepared_data['payment_transaction_id'] = self::$prepared_data[ $field_id ];
							}
							self::$prepared_data[ $field_id ] = $amount;
							// Save 'stripe_field' and 'paypal_field'.
							$payment_key                = str_replace( '-ocs', '', $field_type ) . '_field';
							self::$info[ $payment_key ] = $field_settings;
						} else {
							$not_calculable                              = $calculable_value === $field_object::FIELD_NOT_CALCULABLE;
							$visible_fields[ $field_id . $group_suffix ] = $not_calculable ? $submitted_field_data : $calculable_value;
							if ( $not_calculable ) {
								self::$not_calculable[] = $field_id . $group_suffix;
							}

							$visible_fields = self::prepare_subfields( $visible_fields, $field_settings, $field_object, $group_suffix );

							$method = 'handle_' . $field_type . '_field';
							if ( method_exists( self::class, $method ) ) {
								if ( ! $group_suffix ) {
									$dependent_fields = self::$method( $field_settings );
								} else {
									$cloned_field_settings                = $field_settings;
									$cloned_field_settings['element_id'] .= $group_suffix;
									$dependent_fields                     = self::$method( $cloned_field_settings );
								}
								if ( is_array( $dependent_fields ) && $dependent_fields ) {
									$depends = self::dependencies_not_ready( $dependent_fields, $visible_fields );
									if ( $depends ) {
										$unspecified[ $field_id . $group_suffix ] = true;
										unset( $visible_fields[ $field_id . $group_suffix ], self::$prepared_data[ $field_id . $group_suffix ] );
									}
								}
							}
						}
						continue;
					} elseif ( $field->parent_group ) {
						$grouped_fields   = self::$module_object->get_grouped_fields_slugs( $field->parent_group );
						$calculable_value = $field_object::get_calculable_repeater_value( $submitted_field_data, $field_settings, $group_suffix, $grouped_fields );
					}
					// handle calculation field.
					$formula           = $calculable_value;
					$formula           = self::maybe_replace_groupped_fields( $formula );
					$fields_in_formula = self::calculator_pull_fields( $formula );
					$depends           = self::dependencies_not_ready( $fields_in_formula[1], $visible_fields );
					if ( $depends ) {
						$unspecified[ $field_id . $group_suffix ] = $formula;
						continue;
					}
					$result = self::calculate_formula( $formula, $visible_fields, $field_settings );

					$visible_fields[ $field_id . $group_suffix ] = $result;

					// Store result of calculation field.
					self::$prepared_data[ $field_id . $group_suffix ] = $result;

					$calculation_entry_data = array(
						'name'  => $field_id . $group_suffix,
						'value' => $result,
					);

					self::$info['field_data_array'][] = $calculation_entry_data;
				} while ( ! empty( $field->parent_group )
					&& in_array( ( ++$i ), $group_copies, true )
				);
			}
		} while ( $unspecified && $previous_unspecified !== $unspecified );

		if ( $unspecified ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Forminator] Unspecified fields ' . wp_json_encode( array_keys( $unspecified ) ) );
			self::$hidden_fields = array_merge( self::$hidden_fields, array_keys( $unspecified ) );

			// An unresolved payment blocks the submission instead of being skipped. Drafts take no payment.
			if ( ! self::$is_draft && ! self::$is_abandoned ) {
				foreach ( preg_grep( '/^(stripe|stripe-ocs|paypal)-\d+$/', array_keys( $unspecified ) ) as $field_id ) {
					self::$submit_errors[] = array( $field_id => esc_html__( 'Error! Invalid payment amount!', 'forminator' ) );
				}
			}
		}

		/**
		 * Filter Handled submitted data on Custom Form
		 *
		 * @param array $prepared_data
		 * @param Forminator_Form_Model $module_object
		 *
		 * @return array
		 */
		self::$prepared_data = apply_filters( 'forminator_prepared_data', self::$prepared_data, self::$module_object );

		// Set a flag to indicate that field visibility has been processed, so we don't repeat this unnecessarily.
		self::$is_fields_visibility_checked = true;
	}

	/**
	 * Add $field_id and its subfields to $hidden_fields array
	 *
	 * @param string $field_id Field slug.
	 * @param string $group_prefix Group prefix.
	 * @param array  $field_settings Field settings.
	 */
	private static function update_hidden_fields_array( $field_id, $group_prefix, $field_settings ) {
		$full_id               = $field_id . $group_prefix;
		self::$hidden_fields[] = $full_id;
		$to_zero               = ! empty( $field_settings['hidden_behavior'] ) && 'zero' === $field_settings['hidden_behavior'];
		if ( $to_zero ) {
			self::$replace_to_zero[] = $full_id;
		}
		if ( 'group-' === substr( $field_id, 0, 6 ) ) {
			$group_fields = self::$module_object->get_grouped_fields( $field_id );
			foreach ( $group_fields as $field ) {
				$subfield_settings = $field->to_formatted_array();
				self::update_hidden_fields_array( $field->slug, '', $subfield_settings );
			}
			return;
		}
		if ( 'page-break' === substr( $field_id, 0, 10 ) ) {
			$page_break_fields = self::$module_object->get_page_fields( $field_id );
			foreach ( $page_break_fields as $field ) {
				self::update_hidden_fields_array( $field->slug, '', $field->to_formatted_array() );
			}
			return;
		}
		unset( self::$prepared_data[ $full_id ] );
		$field_suffix = Forminator_Form_Entry_Model::field_suffix();
		foreach ( $field_suffix as $suffix ) {
			$mod_field_id          = $field_id . '-' . $suffix . $group_prefix;
			self::$hidden_fields[] = $mod_field_id;
			unset( self::$prepared_data[ $mod_field_id ] );
			if ( $to_zero ) {
				self::$replace_to_zero[] = $mod_field_id;
			}
		}
	}

	/**
	 * Check if all dependent fields are already defined
	 *
	 * @param array $dependent_fields Dependent fields.
	 * @param array $visible_fields Visible fields.
	 * @return boolean
	 */
	private static function dependencies_not_ready( $dependent_fields, $visible_fields ) {
		$unspecified_depend_fields = array_diff( $dependent_fields, array_keys( $visible_fields ), self::$hidden_fields );
		if ( $unspecified_depend_fields ) {
			return true;
		}

		return false;
	}

	/**
	 * Prepare subfields
	 *
	 * @param array  $visible_fields Visible fields.
	 * @param array  $field_settings Field settings.
	 * @param object $field_object Field object.
	 * @param string $group_prefix Group prefix.
	 */
	private static function prepare_subfields( $visible_fields, $field_settings, $field_object, $group_prefix = '' ) {
		$base_id  = Forminator_Field::get_property( 'element_id', $field_settings );
		$field_id = $base_id . $group_prefix;
		if ( isset( self::$prepared_data[ $field_id ] ) ) {
			return $visible_fields;
		}
		$field_type   = Forminator_Field::get_property( 'type', $field_settings );
		$field_suffix = Forminator_Form_Entry_Model::field_suffix();

		foreach ( $field_suffix as $suffix ) {
			$mod_field_id = $base_id . '-' . $suffix . $group_prefix;
			if ( isset( self::$prepared_data[ $mod_field_id ] ) ) {
				// Add subfield to $visible_fields array.
				$visible_fields[ $mod_field_id ]             = self::$prepared_data[ $mod_field_id ];
				self::$prepared_data[ $field_id ][ $suffix ] = self::$prepared_data[ $mod_field_id ];
			} elseif (
				isset( $_FILES[ $mod_field_id ] ) && // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'postdata' === $field_type &&
				'post-image' === $suffix &&
				! self::$is_draft && ! self::$is_abandoned
			) {
				$post_image = $field_object->upload_post_image( $field_settings, $mod_field_id );
				if ( is_array( $post_image ) && $post_image['attachment_id'] > 0 ) {
					self::$prepared_data[ $field_id ]['post-image'] = $post_image;
					self::$prepared_data[ $mod_field_id ]           = $post_image;
				} else {
					self::$prepared_data[ $field_id ]['post-image'] = '';
					self::$prepared_data[ $mod_field_id ]           = '';
				}
			}
		}

		return $visible_fields;
	}

	/**
	 * Maybe replace groupped fields field like {calculation-1-*}, {number-2-*}... to real fields {calculation-1}, {number-1-2}
	 *
	 * @param string $formula Formula.
	 * @return string
	 */
	private static function maybe_replace_groupped_fields( $formula ) {
		$pattern = '/\{((?:calculation|number|slider|currency|radio|select|checkbox)\-\d+(?:-min|-max)?)\-\*\}/';
		preg_match_all( $pattern, $formula, $matches );

		foreach ( $matches[1] as $main_field ) {
			$copied_fields = array();
			// If there is the main field - add it to the array.
			if ( isset( self::$prepared_data[ $main_field ] ) ) {
				$copied_fields = array( $main_field );
			}
			// If there are any field copy - add it to the array. Only copy keys end in their number.
			foreach ( array_keys( self::$prepared_data ) as $key ) {
				if ( preg_match( '/^' . preg_quote( $main_field, '/' ) . '-\d+$/', $key ) ) {
					$copied_fields[] = $key;
				}
			}
			$value = 0;
			if ( 'calculation-' === substr( $main_field, 0, 12 ) ) {
				$value = '{' . $main_field . '}';
			}
			if ( $copied_fields ) {
				$value = '({' . implode( '}+{', $copied_fields ) . '})';
			}
			$formula = str_replace( '{' . $main_field . '-*}', $value, $formula );
		}
		return $formula;
	}

	/**
	 * Pull fields from formula
	 *
	 * @param string $formula Formula.
	 * @return array
	 */
	public static function calculator_pull_fields( $formula ) {
		$field_types             = Forminator_Core::get_field_types();
		$increment_field_pattern = sprintf( '(%s)-\d+', implode( '|', $field_types ) );
		$pattern                 = '/\{((' . $increment_field_pattern . ')(\-[A-Za-z-_]+)?(\-[A-Za-z0-9-_]+)?)\}/';
		preg_match_all( $pattern, $formula, $matches );

		return $matches;
	}

	/**
	 * Calculate formula.
	 *
	 * @param string $formula Formula.
	 * @param array  $visible_fields Not hidden field values.
	 * @param array  $field_settings Field settings.
	 * @return mixed
	 */
	public static function calculate_formula( $formula, $visible_fields, $field_settings ) {
		$formula           = self::maybe_replace_groupped_fields( $formula ); // todo: remove it, cuz it was already replaced.
		$fields_in_formula = self::calculator_pull_fields( $formula );

		// later usage for str_replace.
		$full_matches      = $fields_in_formula[0];
		$converted_formula = $formula;
		foreach ( $fields_in_formula[1] as $key => $field_id ) {
			if ( ! isset( $full_matches[ $key ] ) ) {
				continue;
			}

			if ( in_array( $field_id, self::$hidden_fields, true ) || in_array( $field_id, self::$not_calculable, true ) ) {
				// skip validation, hidden values = 0 or 1.
				$value = self::replace_to( $field_id, $converted_formula );
			} else {
				$value = $visible_fields[ $field_id ];
			}

			// Replace only the first occurence.
			$find_str          = $full_matches[ $key ];
			$replace_with      = '(' . ( $value ) . ')';
			$converted_formula = implode( $replace_with, explode( $find_str, $converted_formula, 2 ) );
		}

		$precision  = Forminator_Calculation::get_calculable_precision( $field_settings );
		$calculator = new Forminator_Calculator( $converted_formula );
		$calculator->set_is_throwable( true );

		try {
			$calculated_value = $calculator->calculate();
			// Check if the calculated value is in scientific notation.
			if ( self::is_in_scientific_notation( floatval( $calculated_value ) ) ) {
				// Format the value using number_format instead of the round method, as it may lead to a loss of precision for scientific notation.
				$result = Forminator_Calculation::get_calculable_number_format( $field_settings, $calculated_value );
			} else {
				$result = round( floatval( $calculated_value ), $precision );
			}
		} catch ( Forminator_Calculator_Exception $e ) {
			$result = round( 0.0, $precision );
		}

		return $result;
	}

	/**
	 * Check if value is in scientific notation
	 *
	 * @param float $value Value.
	 * @return boolean
	 */
	private static function is_in_scientific_notation( $value ) {
		// Check if the string contains 'e'.
		if ( is_numeric( $value ) && false !== strpos( strtolower( (string) $value ), 'e' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Handle captcha field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_captcha_field( $field_settings ) {
		self::$info['captcha_settings'] = $field_settings;
	}

	/**
	 * Handle postdata field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_postdata_field( $field_settings ) {
		$field_id      = Forminator_Field::get_property( 'element_id', $field_settings );
		$post_type     = Forminator_Field::get_property( 'post_type', $field_settings, 'post' );
		$category_list = forminator_post_categories( $post_type );
		if ( ! empty( $category_list ) ) {
			foreach ( $category_list as $category ) {
				$mod_field_id = $field_id . '-' . $category['value'];
				if ( isset( self::$prepared_data[ $mod_field_id ] ) ) {
					self::$prepared_data[ $field_id ][ $category['value'] ] = self::$prepared_data[ $mod_field_id ];
				}
			}
		}
		// Never trust client-supplied post-custom; rebuild from field settings only.
		if ( isset( self::$prepared_data[ $field_id ]['post-custom'] ) ) {
			unset( self::$prepared_data[ $field_id ]['post-custom'] );
		}

		$custom_vars = Forminator_Field::get_property( 'post_custom_fields', $field_settings );
		$custom_meta = Forminator_Field::get_property( 'options', $field_settings );
		if ( empty( $custom_vars ) || empty( $custom_meta ) || self::$is_draft || self::$is_abandoned ) {
			return;
		}
		$dependent_fields = array();
		foreach ( $custom_meta as $meta ) {
			$value = ! empty( $meta['value'] ) ? trim( $meta['value'] ) : '';
			$label = $meta['label'];

			if ( strpos( $value, '{' ) !== false && strpos( $value, '{upload' ) === false ) {
				$prepared_value  = forminator_replace_form_data( $value, self::$module_object );
				$replaced_fields = self::get_replaced_fields( $value, $prepared_value );
				if ( $replaced_fields ) {
					$dependent_fields = array_merge( $dependent_fields, $replaced_fields );
				}
				$value = forminator_replace_variables( $prepared_value, self::$module_id );
			} elseif ( isset( self::$prepared_data[ $value ] ) ) {
				$value = self::$prepared_data[ $value ];
			}

			// Store data that will be used later by upload fields.
			if ( strpos( $value, '{upload' ) !== false ) {
				self::$info['upload_in_customfield'][] = array(
					'postdata_id' => $field_id,
					'upload_id'   => trim( $value, '{}' ),
					'uploads'     => '',
				);
			}

			self::$prepared_data[ $field_id ]['post-custom'][] = array(
				'key'   => $label,
				'value' => $value,
			);
		}

		return array_unique( $dependent_fields );
	}

	/**
	 * Handle date field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_date_field( $field_settings ) {
		$file_type = Forminator_Field::get_property( 'field_type', $field_settings );
		if ( 'picker' !== $file_type ) {
			$field_id    = Forminator_Field::get_property( 'element_id', $field_settings );
			$date_format = Forminator_Field::get_property( 'date_format', $field_settings );

			self::$prepared_data[ $field_id ]['format'] = datepicker_default_format( $date_format );
		}
	}

	/**
	 * Handle Time field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_time_field( $field_settings ) {
		$time_type = Forminator_Field::get_property( 'time_type', $field_settings, 'twelve' );

		if ( 'twelve' === $time_type ) {
			$field_id = Forminator_Field::get_property( 'element_id', $field_settings );
			$time     = self::$prepared_data[ $field_id ];

			if ( empty( $time['hours'] ) && empty( $time['minutes'] ) ) {
				unset( self::$prepared_data[ $field_id ] );
			}
		}
	}

	/**
	 * Handle url field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_url_field( $field_settings ) {
		$form_field_obj = Forminator_Core::get_field_object( 'url' );
		$field_id       = Forminator_Field::get_property( 'element_id', $field_settings );

		self::$prepared_data[ $field_id ] = $form_field_obj->add_scheme_url( self::$prepared_data[ $field_id ] );
	}

	/**
	 * Handle upload field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_upload_field( $field_settings ) {
		$field_id = Forminator_Field::get_property( 'element_id', $field_settings );
		// Initialize upload field data as an empty array to prevent setting data from POST data.
		self::$prepared_data[ $field_id ] = array();
		if ( self::$is_draft || self::$is_abandoned ) {
			return;
		}

		$file_type     = Forminator_Field::get_property( 'file-type', $field_settings, 'single' );
		$upload_method = Forminator_Field::get_property( 'upload-method', $field_settings, 'ajax' );

		$form_upload_data = isset( self::$prepared_data['forminator-multifile-hidden'] )
			? self::$prepared_data['forminator-multifile-hidden']
			: array();

		if ( 'multiple' === $file_type && 'ajax' === $upload_method ) {
			$upload_data = isset( $form_upload_data[ $field_id ] ) ? $form_upload_data[ $field_id ] : array();
		} else {
			$upload_data = isset( $_FILES[ $field_id ] ) ? $_FILES[ $field_id ] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

			if ( self::is_empty_native_upload( $upload_data ) ) {
				return;
			}
		}

		if ( ! empty( $upload_data ) ) {
			self::$has_upload                         = true;
			self::$prepared_data[ $field_id ]['file'] = $upload_data;
		}
	}

	/**
	 * Check if the native upload payload contains no selected files.
	 *
	 * @since 1.54.0
	 *
	 * @param mixed $upload_data Upload data.
	 * @return bool
	 */
	private static function is_empty_native_upload( $upload_data ) {

		if ( empty( $upload_data ) || ! is_array( $upload_data ) ) {
			return true;
		}

		if ( ! isset( $upload_data['error'] ) ) {
			return false;
		}

		foreach ( (array) $upload_data['error'] as $error ) {
			if ( UPLOAD_ERR_NO_FILE !== (int) $error ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Handle rating field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_rating_field( $field_settings ) {
		$max_rating                       = Forminator_Field::get_property( 'max_rating', $field_settings, 5 );
		$field_id                         = Forminator_Field::get_property( 'element_id', $field_settings );
		$rating_value                     = self::$prepared_data[ $field_id ] ?? 0;
		self::$prepared_data[ $field_id ] = $rating_value . '/' . $max_rating;
	}

	/**
	 * Upload or transfer uploads
	 * For single and multiple-on-submit uploads,
	 * we upload directly when form doesnt have any payment fields.
	 * If form has payment fields, uploads go to forminator_temp folder first
	 * so that when there is an error, the uploads in the forminator_temp folder
	 * can be cleared after 24hrs.
	 *
	 * @param string $mode - upload/transfer.
	 */
	private static function process_uploads( $mode ) {
		if ( self::$is_draft || ! self::$has_upload ) {
			return;
		}

		$fields = self::$info['field_data_array'];
		foreach ( $fields as $key => $field ) {
			if (
				! isset( $field['field_type'] ) ||
				'upload' !== $field['field_type']
			) {
				continue;
			}

			$field_id       = $field['name'];
			$field_settings = $field['field_array'];
			$file_type      = Forminator_Field::get_property( 'file-type', $field_settings, 'single' );
			$upload_method  = Forminator_Field::get_property( 'upload-method', $field_settings, 'ajax' );
			$form_field_obj = Forminator_Core::get_field_object( 'upload' );

			if ( 'upload' === $mode ) {

				if ( 'multiple' === $file_type && 'ajax' === $upload_method ) {
					continue;
				} elseif ( 'multiple' === $file_type && 'submission' === $upload_method ) {
					$form_upload_data = isset( $_FILES[ $field_id ] ) ? $_FILES[ $field_id ] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
					$upload_data      = $form_field_obj->handle_submission_multifile_upload( self::$module_id, $field_settings, $form_upload_data, self::$has_payment );
				} elseif ( 'single' === $file_type ) {
					$upload_data = $form_field_obj->handle_file_upload(
						self::$module_id,
						$field_settings,
						array(),
						self::$has_payment ? 'upload' : 'submit'
					);
				}
			} elseif ( 'transfer' === $mode ) {
				$form_upload_data = $field['value'];

				if ( 'multiple' === $file_type && 'ajax' === $upload_method ) {
					$upload_data = $form_field_obj->handle_ajax_multifile_upload( self::$module_id, $form_upload_data, $field_settings );
				} elseif (
					self::$has_payment &&
					( 'single' === $file_type || ( 'multiple' === $file_type && 'ajax' !== $upload_method ) )
				) {
					$upload_data = $form_field_obj->transfer_upload( self::$module_id, $form_upload_data, $field_settings );
				} elseif ( ! self::$has_payment && ! empty( $form_upload_data['file'] ) ) {
					$upload_data = $form_upload_data['file'];
					if ( isset( $upload_data['file_path'] ) && ! forminator_attachment_path_is_allowed( $upload_data['file_path'] ) ) {
						$upload_data = array( 'success' => false );
					}
				}
			}

			if ( isset( $upload_data['success'] ) && $upload_data['success'] ) {
				self::$prepared_data[ $field_id ]['file']                = $upload_data;
				self::$info['field_data_array'][ $key ]['value']['file'] = $upload_data;

				if (
					( 'single' === $file_type && ! self::$has_payment ) ||
					'transfer' === $mode
				) {
					// If upload is successful, add the upload data to custom field if tag is present.
					if ( ! empty( self::$info['upload_in_customfield'] ) ) {
						$file_url = $upload_data['file_url'];
						if ( 'multiple' === $file_type ) {
							$file_url = implode( ', ', $upload_data['file_url'] );
						}

						foreach ( self::$info['upload_in_customfield'] as $cf_key => $cf ) {
							if ( $field_id === $cf['upload_id'] ) {
								self::$info['upload_in_customfield'][ $cf_key ]['uploads'] = $file_url;
							}
						}
					}
				}
			} elseif ( isset( $upload_data['success'] ) && false === $upload_data['success'] ) {
				$error = isset( $upload_data['message'] ) ? $upload_data['message'] : self::get_invalid_form_message();

				self::$submit_errors[][ $field_id ] = $error;
			} else {
				// no file uploaded for this field_id.
				self::$prepared_data[ $field_id ] = '';
			}
		}

		self::check_errors();
	}

	/**
	 * Handle select field
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_select_field( $field_settings ) {
		$is_limit = Forminator_Field::get_property( 'limit_status', $field_settings );
		if ( self::$is_draft || self::$is_abandoned || 'enable' !== $is_limit ) {
			return;
		}
		$field_id     = Forminator_Field::get_property( 'element_id', $field_settings );
		$options      = Forminator_Field::get_property( 'options', $field_settings );
		$value_type   = Forminator_Field::get_property( 'value_type', $field_settings );
		$select_array = (array) ( self::$prepared_data[ $field_id ] ?? array() );
		$select_array = array_map( 'forminator_normalize_choice_option_value', $select_array );
		foreach ( $options as $o => $option ) {
			$option_value = forminator_normalize_choice_option_value( $option['value'] );
			if ( in_array( $option_value, $select_array, true ) ) {
				self::$info['select_field_value'][ $field_id ][ $o ] = array(
					'limit' => $option['limit'],
					'value' => $option_value,
					'label' => $option['label'],
					'type'  => $value_type,
				);
			}
		}
	}

	/**
	 * Resolve trusted embed hidden value by default key.
	 *
	 * @param string $default_value Hidden default value key.
	 * @return mixed|null
	 */
	private static function get_embed_hidden_value( $default_value ) {
		$page_id = forminator_get_current_post_id();
		if ( $page_id <= 0 ) {
			return null;
		}

		switch ( $default_value ) {
			case 'embed_id':
				return $page_id;

			case 'embed_title':
				return forminator_get_post_data( 'post_title', $page_id );

			case 'embed_url':
				return get_permalink( $page_id );

			default:
				return null;
		}
	}

	/**
	 * Apply updated values to hidden-type fields after submission
	 *
	 * @param array $field_settings Field settings.
	 */
	private static function handle_hidden_field( $field_settings ) {
		if ( ! empty( $field_settings['element_id'] ) && ! empty( $field_settings['default_value'] ) ) {
			$exclude_key = array( 'query', 'refer_url' );
			if ( 'submission_time' === $field_settings['default_value'] ) {
				self::$prepared_data[ $field_settings['element_id'] ] = date_i18n( 'g:i:s a, T', forminator_local_timestamp(), true );
			} elseif ( ! in_array( $field_settings['default_value'], $exclude_key, true ) ) {
				$form_field_obj = Forminator_Core::get_field_object( 'hidden' );
				$value          = $form_field_obj->get_value( $field_settings );

				$embed_value = self::get_embed_hidden_value( $field_settings['default_value'] );
				if ( null !== $embed_value ) {
					$value = $embed_value;
				}

				self::$prepared_data[ $field_settings['element_id'] ] = esc_html( $value );
			}
		}
	}

	/**
	 * Apply updated values to hidden-type fields after entry is saved
	 *
	 * @param array $entry Entry.
	 * @param int   $module_id Module Id.
	 */
	private static function handle_hidden_fields_after_entry_save( $entry, $module_id = null ) {
		$field_with_submission_id = array();
		$custom_sequence_enabled  = self::is_custom_sequence_enabled();
		foreach ( self::$info['field_data_array'] as $key => $field ) {
			if ( 0 === strpos( $field['name'], 'hidden-' ) ) {
				switch ( $field['field_array']['default_value'] ) {
					case 'custom_value':
						self::$info['field_data_array'][ $key ]['value'] = esc_html( $field['field_array']['custom_value'] );
						break;

					case 'embed_id':
					case 'embed_title':
					case 'embed_url':
						$embed_value = self::get_embed_hidden_value( $field['field_array']['default_value'] );
						if ( null !== $embed_value ) {
							self::$info['field_data_array'][ $key ]['value'] = $embed_value;
						}
						break;

					default:
						self::$info['field_data_array'][ $key ]['value'] = trim(
							forminator_replace_variables(
								'{' . $field['value'] . '}',
								self::$module_id,
								$entry
							),
							'{}'
						);
						break;
				}

				if ( 'submission_id' === $field['value'] ) {
					self::$info['field_data_array'][ $key ]['value'] = forminator_get_submission_id( null, $entry );
					if ( $custom_sequence_enabled && ! empty( $entry->status ) && 'pending' === $entry->status ) {
						$field_with_submission_id[] = $field['name'];
					}
				}
			}
		}
		// Add metadata for hidden submission id fields if custom sequence is enabled.
		// This will be used to update the hidden submission id fields after the entry status is updated to active.
		if ( $custom_sequence_enabled && ! empty( $field_with_submission_id ) ) {
			self::$info['field_data_array'][] = array(
				'name'  => '_hidden_submission_id_fields',
				'value' => $field_with_submission_id,
			);
		}
	}

	/**
	 * Check if custom sequence is enabled for the form.
	 *
	 * @return bool
	 */
	private static function is_custom_sequence_enabled() {
		$settings                = self::$module_settings;
		$custom_sequence_enabled = isset( $settings['custom_sequence_enabled'] ) ? filter_var( $settings['custom_sequence_enabled'], FILTER_VALIDATE_BOOLEAN ) : false;

		return $custom_sequence_enabled;
	}

	/**
	 * Get replaved fields by forminator_replace_form_data method
	 *
	 * @param string $old_value Old value.
	 * @param string $new_value New value.
	 * @return array
	 */
	private static function get_replaced_fields( $old_value, $new_value ) {
		$replaced_fields = array();
		if ( $old_value === $new_value ) {
			return $replaced_fields;
		}
		$pattern = '/{([^}]+)}/';
		preg_match_all( $pattern, $old_value, $matches1 );
		preg_match_all( $pattern, $new_value, $matches2 );
		$replaced_fields = array_diff( $matches1[1], $matches2[1] );

		return $replaced_fields;
	}

	/**
	 * Returns what the current field should be replaced to (0 or 1)
	 *
	 * @param string $field_id Field id.
	 * @param string $formula Formula.
	 * @return int 0|1
	 */
	public static function replace_to( $field_id, $formula ) {
		$replace = 0;
		if ( in_array( $field_id, self::$replace_to_zero, true ) ) {
			return $replace;
		}
		$quoted_operand = preg_quote( '{' . $field_id . '}', '/' );
		$pattern        = '/([\\+\\-\\*\\/]?)[^\\+\\-\\*\\/\\(]*' . $quoted_operand
				. '[^\\)\\+\\-\\*\\/]*([\\+\\-\\*\\/]?)/';

		$matches = array();
		if ( preg_match( $pattern, $formula, $matches ) ) {
			// if operand in multiplication or division set value = 1.
			if ( '*' === $matches[1] || '/' === $matches[1] || '*' === $matches[2] || '/' === $matches[2] ) {
				$replace = 1;
			}
		}

		return $replace;
	}

	/**
	 * Create new post from postdata field
	 * Add upload file urls to postdata custom fields if necessary
	 *
	 * @param array $postdata_fields Fields with postdata type.
	 * @return array
	 */
	public static function create_post_from_postdata( $postdata_fields ) {
		$postdata_result = array();
		foreach ( $postdata_fields as $postdata_field ) {
			$field_id       = $postdata_field['name'];
			$field_data     = $postdata_field['value'];
			$field_array    = $postdata_field['field_array'];
			$form_field_obj = $postdata_field['form_field_obj'];

			// check if field_data of post values not empty (happen when postdata is not required).
			$filtered   = array_filter( $field_data );
			$post_value = $field_data;
			if ( ! empty( $filtered ) ) {
				if ( isset( $filtered['post-custom'] ) ) {
					foreach ( $filtered['post-custom'] as $custom_field_index => $custom_field ) {
						if ( preg_match( '/\{upload-(\d+)\}/', $custom_field['value'] ) ) {
							$upload_id = trim( $custom_field['value'], '{}' );

							foreach ( self::$info['upload_in_customfield'] as $cf_data ) {
								if ( $upload_id === $cf_data['upload_id'] && $field_id === $cf_data['postdata_id'] ) {
									$field_data['post-custom'][ $custom_field_index ]['value'] = $cf_data['uploads'];
								}
							}
						}
					}
				}

				$post_id = $form_field_obj->save_post( $field_array, $field_data );
				if ( $post_id ) {
					$field_data = array(
						'postdata' => $post_id,
						'value'    => $field_data,
					);
				} else {
					return array(
						'type'     => 'error',
						'field_id' => $field_id,
						'value'    => esc_html__( 'There was an error saving the post data. Please try again', 'forminator' ),
					);
				}
			} else {
				$field_data = array(
					'postdata' => null,
					'value'    => $post_value,
				);
			}

			$postdata_result[] = array(
				'type'       => 'success',
				'field_id'   => $field_id,
				'field_data' => $field_data,
			);
		}

		return $postdata_result;
	}

	/**
	 * Retrieve the backup code if lost phone.
	 *
	 * @return void
	 */
	public function fallback_email() {
		$defender_data    = forminator_defender_compatibility();
		$two_fa_component = new $defender_data['two_fa_component']();
		$post_data        = $this->get_post_data();
		$token            = isset( $post_data['token'] ) ? $post_data['token'] : '';
		$ret              = $two_fa_component->send_otp_to_email( $token );
		if ( false === $ret ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Please try again.', 'forminator' ),
				)
			);
		}

		if ( is_wp_error( $ret ) ) {
			wp_send_json_error(
				array(
					'message' => $ret->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Your code has been sent to your email.', 'forminator' ),
			)
		);
	}

	/**
	 * Get the first email in submitted data
	 *
	 * @param array $submitted_data Submitted data.
	 *
	 * @since 1.17.0
	 */
	private static function get_first_email( $submitted_data ) {
		foreach ( $submitted_data as $key => $val ) {
			if ( false !== strpos( $key, 'email-' ) && ! empty( $val ) ) {
				return $val;
			}
		}

		return null;
	}

	/**
	 * Build a draft link URL.
	 *
	 * @param string $draft_id Draft ID.
	 * @param int    $page_id  WordPress page/post ID where the form is embedded.
	 *
	 * @since 1.54.0
	 *
	 * @return string The draft link URL.
	 */
	public static function get_draft_link( $draft_id, $page_id ) {
		return esc_url( add_query_arg( 'draft', $draft_id, get_permalink( $page_id ) ) );
	}

	/**
	 * Create draft ID
	 *
	 * @param int $form_id Form Id.
	 *
	 * @since 1.17.0
	 */
	public function create_draft_id( $form_id ) {
		// Must guarantee alphanumeric.
		$draft_id  = wp_rand( 0, 9 );
		$draft_id .= substr( str_shuffle( 'abcdefghijklmnopqrstvwxyz' ), 0, 1 );
		$draft_id .= substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );

		return apply_filters( 'forminator_create_draft_id', $draft_id, $form_id );
	}

	/**
	 * Set the Draft ID
	 */
	public function set_entry_draft_id() {
		// 1st draft save.
		if ( self::$is_draft && is_null( self::$previous_draft_id ) ) {
			return $this->create_draft_id( self::$module_id );

			// Succeeding draft saves.
		} elseif ( self::$is_draft && ! is_null( self::$previous_draft_id ) ) {
			return self::$previous_draft_id;
		}
	}

	/**
	 * Get draft notification from form notifications
	 *
	 * @param array $notifications Notification.
	 * @param array $data Data.
	 *
	 * @since 1.17.0
	 */
	public function get_draft_notification( $notifications, $data ) {
		foreach ( $notifications as $key => $notif ) {
			if ( false !== array_search( 'save_draft', $notif, true ) ) {
				$notifications[ $key ]['recipients']   = str_replace( '{save_and_continue_email}', $data['email-1'], $notif['recipients'] );
				$email_msg                             = $notif['email-editor'];
				$email_msg                             = str_replace( '{form_link}', $data['draft_link'], $email_msg );
				$email_msg                             = str_replace( '{retention_period}', $data['retention_period'], $email_msg );
				$notifications[ $key ]['email-editor'] = $email_msg;

				return $notifications[ $key ];
			}
		}

		return false;
	}

	/**
	 * Draft link email submission
	 *
	 * Builds the draft link and retention period server-side from the stored draft.
	 * Recipient comes from the send-draft form (same pattern as admin resend).
	 * A one-time send token is consumed on use and rotated after success.
	 *
	 * @since 1.17.0
	 */
	public function submit_email_draft_link() {
		$draft_id = Forminator_Core::sanitize_text_field( 'draft_id' );
		$nonce    = 'forminator_nonce_email_draft_link_' . $draft_id;
		if ( ! check_ajax_referer( $nonce, $nonce, false ) ) {
			wp_send_json_error( esc_html__( 'Invalid request, you are not allowed to do that action.', 'forminator' ) );
		}

		$email = Forminator_Core::sanitize_text_field( 'email-1' );
		$token = Forminator_Core::sanitize_text_field( 'draft_email_token' );

		$draft_entry = new Forminator_Form_Entry_Model( $draft_id );
		if ( empty( $draft_entry->entry_id ) || empty( $draft_entry->form_id ) || empty( $draft_entry->draft_id ) ) {
			wp_send_json_error( esc_html__( 'Draft entry was not found.', 'forminator' ) );
		}

		$form_id           = (int) $draft_entry->form_id;
		$submitted_form_id = absint( Forminator_Core::sanitize_text_field( 'form_id' ) );
		if ( $submitted_form_id && $submitted_form_id !== $form_id ) {
			wp_send_json_error( esc_html__( 'Invalid request, you are not allowed to do that action.', 'forminator' ) );
		}

		if ( empty( $email ) ) {
			wp_send_json_error(
				array(
					'field'   => 'email-1',
					'message' => apply_filters(
						'forminator_draft_email_required',
						esc_html__( 'Email is required.', 'forminator' ),
						$form_id
					),
				)
			);
		} elseif ( ! is_email( $email ) ) {
			wp_send_json_error(
				array(
					'field'   => 'email-1',
					'message' => apply_filters(
						'forminator_draft_email_invalid',
						esc_html__( 'Please enter a valid email.', 'forminator' ),
						$form_id
					),
				)
			);
		}

		$stored_token = get_transient( self::get_draft_email_token_key( $draft_entry->draft_id ) );
		if ( empty( $token ) || empty( $stored_token ) || ! hash_equals( (string) $stored_token, $token ) ) {
			wp_send_json_error( esc_html__( 'Invalid request, you are not allowed to do that action.', 'forminator' ) );
		}

		// Consume immediately so the same pair cannot be replayed in parallel.
		delete_transient( self::get_draft_email_token_key( $draft_entry->draft_id ) );

		$custom_form = Forminator_Form_Model::model()->load( $form_id );
		if ( ! is_object( $custom_form ) ) {
			// Restore token so a legitimate retry is still possible after a hard failure.
			set_transient( self::get_draft_email_token_key( $draft_entry->draft_id ), $token, DAY_IN_SECONDS );
			wp_send_json_error(
				array(
					'message' => apply_filters(
						'forminator_draft_invalid_form_id',
						esc_html__( 'Invalid form ID.', 'forminator' ),
						$form_id
					),
				)
			);
		}

		$settings          = $custom_form->settings;
		$save_and_continue = isset( $settings['use_save_and_continue'] ) ? filter_var( $settings['use_save_and_continue'], FILTER_VALIDATE_BOOLEAN ) : false;
		$enable_email_link = isset( $settings['sc_email_link'] ) ? filter_var( $settings['sc_email_link'], FILTER_VALIDATE_BOOLEAN ) : true;
		if ( ! $save_and_continue || ! $enable_email_link ) {
			set_transient( self::get_draft_email_token_key( $draft_entry->draft_id ), $token, DAY_IN_SECONDS );
			wp_send_json_error( esc_html__( 'Invalid request, you are not allowed to do that action.', 'forminator' ) );
		}

		$draft_page_id = absint( $draft_entry->get_meta( '_draft_page_id', 0 ) );
		if ( empty( $draft_page_id ) ) {
			set_transient( self::get_draft_email_token_key( $draft_entry->draft_id ), $token, DAY_IN_SECONDS );
			wp_send_json_error( esc_html__( 'Could not determine the page for this draft. The draft link cannot be reconstructed.', 'forminator' ) );
		}

		$draft_link = self::get_draft_link( $draft_entry->draft_id, $draft_page_id );
		$retention  = isset( $settings['sc_draft_retention'] ) ? $settings['sc_draft_retention'] : 30;

		$submitted_data = array(
			'email-1'          => $email,
			'draft_link'       => $draft_link,
			'retention_period' => $retention,
			'action'           => 'forminator_email_draft_link',
		);

		$draft_notifications = $this->get_draft_notification( $custom_form->notifications, $submitted_data );
		if ( empty( $draft_notifications ) ) {
			set_transient( self::get_draft_email_token_key( $draft_entry->draft_id ), $token, DAY_IN_SECONDS );
			wp_send_json_error( esc_html__( 'No draft email notification is configured for this form.', 'forminator' ) );
		}

		unset( $custom_form->notifications );
		$custom_form->notifications[] = $draft_notifications;
		$forminator_mail_sender       = new Forminator_CForm_Front_Mail();
		$mail_sent                    = $forminator_mail_sender->process_mail( $custom_form, $draft_entry, $submitted_data );
		$response                     = array(
			'draft_mail_sent' => $mail_sent,
		);

		if ( $mail_sent ) {
			// Issue a fresh token for optional "Change email" without replaying the old pair.
			$response['draft_email_token']  = self::issue_draft_email_token( $draft_entry->draft_id );
			$response['draft_mail_message'] = sprintf(
				'<p>%s</p><a href="#" class="draft-resend-mail">%s</a>',
				esc_html__( 'We\'ve sent the resume form link to your email address. Please check your spam folder if you can\'t find the link in your inbox.', 'forminator' ),
				esc_html__( 'Change email', 'forminator' )
			);
		} else {
			// Keep the same token so "Resend link" can retry.
			set_transient( self::get_draft_email_token_key( $draft_entry->draft_id ), $token, DAY_IN_SECONDS );
			$response['draft_mail_message'] = sprintf(
				'<p>%s</p><a href="#" class="draft-resend-mail">%s</a>',
				esc_html__( 'We couldn\'t send the form resume link to your email at this time. Click on the link below to resend it or manually copy and save the link in a safe place.', 'forminator' ),
				esc_html__( 'Resend link', 'forminator' )
			);
		}

		wp_send_json_success( $response );
	}

	/**
	 * Transient key for the one-time draft email send token.
	 *
	 * @param string $draft_id Draft ID.
	 *
	 * @since 1.57.3
	 *
	 * @return string
	 */
	public static function get_draft_email_token_key( $draft_id ) {
		return 'forminator_draft_email_token_' . $draft_id;
	}

	/**
	 * Issue (or replace) a one-time token used to send the draft resume email.
	 *
	 * @param string $draft_id Draft ID.
	 *
	 * @since 1.57.3
	 *
	 * @return string
	 */
	public static function issue_draft_email_token( $draft_id ) {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::get_draft_email_token_key( $draft_id ), $token, DAY_IN_SECONDS );

		return $token;
	}
}
