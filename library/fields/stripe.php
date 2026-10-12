<?php
/**
 * The Forminator_Stripe class.
 * It uses Stripe Card Element to process payment.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_Stripe
 *
 * @since 1.7
 */
class Forminator_Stripe extends Forminator_Field {

	/**
	 * Stripe API version required for Checkout Sessions with Payment Element.
	 *
	 * @since 1.56
	 *
	 * @var string
	 */
	public const CHECKOUT_SESSION_STRIPE_VERSION = '2026-04-22.dahlia';

	/**
	 * Option key prefix for individual pending Checkout Session IDs.
	 *
	 * @since 1.56
	 *
	 * @var string
	 */
	public const CHECKOUT_SESSION_OPTION_PREFIX = 'forminator_stripe_checkout_session_';

	/**
	 * Option key for pending Checkout Session IDs tracked for cleanup.
	 *
	 * @since 1.56
	 *
	 * @var string
	 */
	public const CHECKOUT_SESSION_CLEANUP_OPTION_KEY = 'forminator_stripe_checkout_session_cleanup';

	/**
	 * Option key prefix for Checkout Session IDs that already produced an entry.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const CONSUMED_CHECKOUT_SESSION_OPTION_PREFIX = 'forminator_stripe_consumed_checkout_session_';

	/**
	 * Option key for consumed Checkout Session IDs tracked for cleanup.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const CONSUMED_CHECKOUT_SESSION_CLEANUP_OPTION_KEY = 'forminator_stripe_consumed_checkout_session_cleanup';

	/**
	 * Option key prefix for individual pending PaymentIntent IDs.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_OPTION_PREFIX = 'forminator_stripe_payment_intent_';

	/**
	 * Option key for pending PaymentIntent IDs tracked for cleanup.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_CLEANUP_OPTION_KEY = 'forminator_stripe_payment_intent_cleanup';

	/**
	 * Option key prefix for PaymentIntent IDs that already produced an entry.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const CONSUMED_PAYMENT_INTENT_OPTION_PREFIX = 'forminator_stripe_consumed_payment_intent_';

	/**
	 * Option key for consumed PaymentIntent IDs tracked for cleanup.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const CONSUMED_PAYMENT_INTENT_CLEANUP_OPTION_KEY = 'forminator_stripe_consumed_payment_intent_cleanup';

	/**
	 * Option key prefix marking a PaymentIntent whose claim is held by a request.
	 *
	 * @since 1.57.2
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_CLAIM_HELD_OPTION_PREFIX = 'forminator_stripe_payment_intent_claim_held_';

	/**
	 * Cookie tying a Stripe payment to the browser that started it.
	 *
	 * @var string
	 */
	public const PAYMENT_OWNER_COOKIE = 'forminator_stripe_payment_owner';

	/**
	 * Transient prefix for per-browser display PaymentIntents.
	 *
	 * @var string
	 */
	private const DISPLAY_INTENT_TRANSIENT_PREFIX = 'forminator_stripe_display_intent_';

	/**
	 * PaymentIntent statuses a submission can still recover from.
	 *
	 * @since 1.58.0
	 *
	 * @var array
	 */
	private const PENDING_INTENT_STATUSES = array(
		'requires_payment_method',
		'requires_confirmation',
		'requires_action',
		'processing',
	);

	/**
	 * Checkout Sessions claimed by this request, keyed by Checkout Session ID.
	 *
	 * The value tells whether the entry reached storage, which decides if the session is
	 * recorded as consumed or handed back for a retry when the submission ends.
	 *
	 * @since 1.57.2
	 *
	 * @var array
	 */
	private static $claimed_checkout_sessions = array();

	/**
	 * PaymentIntents claimed by this request, keyed by PaymentIntent ID.
	 *
	 * The value tells whether the entry reached storage, which decides if the PaymentIntent
	 * is recorded as consumed or handed back for a retry when the submission ends.
	 *
	 * @since 1.57.2
	 *
	 * @var array
	 */
	private static $claimed_payment_intents = array();

	/**
	 * Payment owner token of the current browser.
	 *
	 * @var string
	 */
	private static $payment_owner_token = '';

	/**
	 * Whether the payment owner cookie was sent in this request.
	 *
	 * @var bool
	 */
	private static $payment_owner_cookie_sent = false;

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'stripe';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'stripe';

	/**
	 * Position
	 *
	 * @var int
	 */
	public $position = 23;

	/**
	 * Options
	 *
	 * @var array
	 */
	public $options = array();


	/**
	 * Icon
	 *
	 * @var string
	 */
	public $icon = 'sui-icon forminator-icon-stripe';

	/**
	 * Is connected
	 *
	 * @var bool
	 */
	public $is_connected = false;

	/**
	 * Mode
	 *
	 * @var string
	 */
	public $mode = 'test';

	/**
	 * Payment plan
	 *
	 * @var array
	 */
	public $payment_plan = array();

	/**
	 * Payment plan hash
	 *
	 * @var string
	 */
	public string $payment_plan_hash = '';

	/**
	 * Forminator_Stripe constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->name = esc_html__( 'Stripe', 'forminator' );

		try {
			$stripe = new Forminator_Gateway_Stripe();
			if ( $stripe->is_test_ready() || $stripe->is_live_ready() ) {
				$this->is_connected = true;
			}
		} catch ( Forminator_Gateway_Exception $e ) {
			$this->is_connected = false;
		}
	}

	/**
	 * Field defaults
	 *
	 * @return array
	 */
	public function defaults() {

		$default_currency = 'USD';
		try {
			$stripe           = new Forminator_Gateway_Stripe();
			$default_currency = $stripe->get_default_currency();
		} catch ( Forminator_Gateway_Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage() );
		}

		return array(
			'field_label'              => esc_html__( 'Credit / Debit Card', 'forminator' ),
			'mode'                     => 'test',
			'currency'                 => $default_currency,
			'amount_type'              => 'fixed',
			'logo'                     => '',
			'company_name'             => '',
			'product_description'      => '',
			'customer_email'           => '',
			'receipt'                  => 'false',
			'billing'                  => 'false',
			'payment_api'              => 'checkout_session',
			'verify_zip'               => 'false',
			'card_icon'                => 'true',
			'language'                 => 'auto',
			'options'                  => array(),
			'base_class'               => 'StripeElement',
			'complete_class'           => 'StripeElement--complete',
			'empty_class'              => 'StripeElement--empty',
			'focused_class'            => 'StripeElement--focus',
			'invalid_class'            => 'StripeElement--invalid',
			'autofilled_class'         => 'StripeElement--webkit-autofill',
			'subscription_amount_type' => 'fixed',
			'quantity_type'            => 'fixed',
			'payments'                 => array(
				array(
					'plan_name'                => esc_html__( 'Plan 1', 'forminator' ),
					'payment_method'           => 'single',
					'amount_type'              => 'fixed',
					'amount'                   => '',
					'subscription_amount_type' => 'fixed',
					'quantity_type'            => 'fixed',
					'quantity'                 => '1',
					'bill_input'               => '1',
				),
			),
		);
	}

	/**
	 * Field front-end markup
	 *
	 * @param array                  $field Field.
	 * @param Forminator_Render_Form $views_obj Forminator_Render_Form object.
	 *
	 * @return mixed
	 */
	public function markup( $field, $views_obj ) {
		$settings            = $views_obj->model->settings;
		$this->field         = $field;
		$this->form_settings = $settings;
		$is_ocs              = 'stripe-ocs' === $field['type'];

		// Don't render stripe field if there is stripe-ocs field in the form.
		if ( ! $is_ocs && $views_obj->has_field_type( 'stripe-ocs' ) ) {
			return '';
		}

		$id                     = self::get_property( 'element_id', $field );
		$description            = self::get_property( 'description', $field, '' );
		$descr_position         = self::get_description_position( $field, $settings );
		$label                  = esc_html( self::get_property( 'field_label', $field, '' ) );
		$mode                   = self::get_property( 'mode', $field, 'test' );
		$currency               = self::get_property( 'currency', $field, $this->get_default_currency() );
		$card_icon              = self::get_property( 'card_icon', $field, true );
		$verify_zip             = self::get_property( 'verify_zip', $field, false );
		$zip_field              = self::get_property( 'zip_field', $field, '' );
		$language               = self::get_property( 'language', $field, 'auto' );
		$base_class             = self::get_property( 'base_class', $field, 'StripeElement' );
		$complete_class         = self::get_property( 'complete_class', $field, 'StripeElement--complete' );
		$empty_class            = self::get_property( 'empty_class', $field, 'StripeElement--empty' );
		$focused_class          = self::get_property( 'focused_class', $field, 'StripeElement--focus' );
		$invalid_class          = self::get_property( 'invalid_class', $field, 'StripeElement--invalid' );
		$autofilled_class       = self::get_property( 'autofilled_class', $field, 'StripeElement--webkit-autofill' );
		$billing                = self::get_property( 'billing', $field, false );
		$billing_name           = self::get_property( 'billing_name', $field, '' );
		$billing_email          = self::get_property( 'billing_email', $field, '' );
		$billing_address        = self::get_property( 'billing_address', $field, '' );
		$receipt                = self::get_property( 'receipt', $field, false );
		$customer_email         = self::get_property( 'customer_email', $field, '' );
		$checkout_email_enabled = self::get_property( 'checkout_email_enabled', $field, '' );
		$checkout_email         = self::get_property( 'checkout_email', $field, '' );
		$checkout_phone_enabled = self::get_property( 'checkout_phone_enabled', $field, '' );
		$checkout_phone         = self::get_property( 'checkout_phone', $field, '' );
		$uniqid                 = Forminator_CForm_Front::$uid;
		$id_prefix              = $is_ocs ? 'payment-element' : 'card-element';
		$full_id                = $id_prefix . '-' . $uniqid;
		$form_style             = $settings['form-style'] ?? 'default';
		$prefix                 = 'basic' === $form_style ? 'basic-' : '';

		$customer_email         = forminator_clear_field_id( $customer_email );
		$checkout_email_enabled = filter_var( $checkout_email_enabled, FILTER_VALIDATE_BOOLEAN );
		$checkout_phone_enabled = filter_var( $checkout_phone_enabled, FILTER_VALIDATE_BOOLEAN );
		$current_checkout_map   = $checkout_email;

		if ( $this->is_checkout_session( $field ) ) {
			$form_fields = $views_obj->model->get_fields();
			$form_id     = $views_obj->model->id;

			// Saved Checkout mappings can outlive the underlying form field, so resolve only active mappings here.
			$checkout_email = $checkout_email_enabled
				? forminator_get_active_mapped_field_id( $checkout_email, $form_fields, $form_id )
				: '';
			$checkout_phone = $checkout_phone_enabled
				? forminator_get_active_mapped_field_id( $checkout_phone, $form_fields, $form_id )
				: '';

			$checkout_billing_mappings = $this->get_checkout_session_billing_mappings( $field, $views_obj->model, $form_id );
			$billing_name              = $checkout_billing_mappings['billing_name'];
			$billing_address           = $checkout_billing_mappings['billing_address'];
		} else {
			$checkout_email = '';
			$checkout_phone = '';
		}
		$should_render_contact = $this->is_checkout_session( $field ) && empty( $checkout_email );

		if ( $this->is_checkout_session( $field ) && ! empty( $checkout_email ) ) {
			$checkout_email_field = $views_obj->model->get_field( $checkout_email, true );

			if ( ! empty( self::get_property( 'conditions', $checkout_email_field, array() ) ) ) {
				$should_render_contact = true;
			}
		}
		forminator_maybe_log(
			__METHOD__,
			array(
				'action'                => 'checkout_contact_element_render_check',
				'checkout_email'        => $checkout_email,
				'current_map'           => $current_checkout_map,
				'should_render_contact' => $should_render_contact,
			)
		);
		$custom_fonts = false;
		$payment_api  = self::get_payment_api( $field );
		// Generate payment intent object.
		$this->mode = $mode;

		if ( isset( $settings[ $prefix . 'form-font-family' ] ) && 'custom' === $settings[ $prefix . 'form-font-family' ] ) {
			$custom_fonts = true;
		}

		if ( ! isset( $settings['form-substyle'] ) ) {
			$settings['form-substyle'] = 'default';
		}

		$data_font_family = 'inherit';
		$data_font_size   = '16px';
		$data_font_weight = '400';

		if ( ! empty( $settings[ $prefix . 'form-font-family' ] ) ) {
			$field_font_family = $this->get_form_setting( $prefix . 'cform-input-font-family', $settings, $data_font_family );
			$data_font_size    = $this->get_form_setting( $prefix . 'cform-input-font-size', $settings, '16' ) . 'px';
			$data_font_weight  = $this->get_form_setting( $prefix . 'cform-input-font-weight', $settings, $data_font_weight );

			if ( 'custom' === $field_font_family ) {
				$data_font_family = $this->get_form_setting( $prefix . 'cform-input-custom-family', $settings, $data_font_family );
			} else {
				$data_font_family = $field_font_family;
			}
		}

		$data_placeholder      = '#888888';
		$data_font_color       = '#000000';
		$data_font_color_focus = '#000000';
		$data_font_color_error = '#000000';
		$data_icon_color       = '#777771';
		$data_icon_color_hover = '#097BAA';
		$data_icon_color_focus = '#097BAA';
		$data_icon_color_error = '#E51919';

		if ( ! empty( $settings[ $prefix . 'cform-color-settings' ] ) ) {
			$data_placeholder      = $this->get_form_setting( $prefix . 'input-placeholder', $settings, $data_placeholder );
			$data_font_color       = $this->get_form_setting( $prefix . 'input-color', $settings, $data_font_color );
			$data_font_color_focus = $this->get_form_setting( $prefix . 'input-color', $settings, $data_font_color_focus );
			$data_font_color_error = $this->get_form_setting( $prefix . 'input-color', $settings, $data_font_color_error );
			$data_icon_color       = $this->get_form_setting( $prefix . 'input-icon', $settings, $data_icon_color );
			$data_icon_color_hover = $this->get_form_setting( $prefix . 'input-icon-hover', $settings, $data_icon_color_hover );
			$data_icon_color_focus = $this->get_form_setting( $prefix . 'input-icon-focus', $settings, $data_icon_color_focus );
			$data_icon_color_error = $this->get_form_setting( $prefix . 'label-validation-color', $settings, $data_icon_color_error );
		}

		$attr = array_merge(
			$this->build_common_element_attributes( $uniqid, $currency, $mode, $is_ocs ),
			array(
				'data-payment-api'      => $payment_api,
				'data-card-icon'        => filter_var( $card_icon, FILTER_VALIDATE_BOOLEAN ),
				'data-veify-zip'        => filter_var( $verify_zip, FILTER_VALIDATE_BOOLEAN ),
				'data-zip-field'        => esc_html( $zip_field ),
				'data-language'         => esc_html( $language ),
				'data-base-class'       => esc_html( $base_class ),
				'data-complete-class'   => esc_html( $complete_class ),
				'data-empty-class'      => esc_html( $empty_class ),
				'data-focused-class'    => esc_html( $focused_class ),
				'data-invalid-class'    => esc_html( $invalid_class ),
				'data-autofilled-class' => esc_html( $autofilled_class ),
				'data-billing'          => filter_var( $billing, FILTER_VALIDATE_BOOLEAN ),
				'data-billing-name'     => esc_html( $billing_name ),
				'data-billing-email'    => esc_html( $billing_email ),
				'data-billing-address'  => esc_html( $billing_address ),
				'data-receipt'          => filter_var( $receipt, FILTER_VALIDATE_BOOLEAN ),
				'data-receipt-email'    => esc_html( $customer_email ),
				'data-custom-fonts'     => $custom_fonts,
				'data-placeholder'      => $data_placeholder,
				'data-font-color'       => $data_font_color,
				'data-font-color-focus' => $data_font_color_focus,
				'data-font-color-error' => $data_font_color_error,
				'data-font-size'        => $data_font_size,
				'data-font-family'      => $data_font_family,
				'data-font-weight'      => $data_font_weight,
				'data-icon-color'       => $data_icon_color,
				'data-icon-color-hover' => $data_icon_color_hover,
				'data-icon-color-focus' => $data_icon_color_focus,
				'data-icon-color-error' => $data_icon_color_error,
			)
		);

		if ( $is_ocs ) {
			$elements_options  = array(
				'loader'                => 'always',
				'locale'                => $language,
				'paymentMethodCreation' => 'manual',
			);
			$variables         = array(
				'fontWeightNormal'      => $data_font_weight,
				'fontSizeBase'          => $data_font_size,
				'iconColor'             => $data_icon_color,
				'iconHoverColor'        => $data_icon_color_hover,
				'iconCardErrorColor'    => $data_icon_color_error,
				'iconCardCvcErrorColor' => $data_icon_color_error,
				'colorTextPlaceholder'  => $data_placeholder,
			);
			$custom_appearance = self::get_property( 'custom_appearance', $field, false );
			if ( $custom_appearance ) {
				$spacing = self::get_property( 'spacing_unit', $field, '' );
				if ( $spacing ) {
					$variables['spacingUnit'] = $spacing . 'px';
				}
				$border_radius = self::get_property( 'border_radius', $field, '' );
				if ( $border_radius ) {
					$variables['borderRadius'] = $border_radius . 'px';
				}
				$variables['colorPrimary']    = self::get_property( 'primary_color', $field, '' );
				$variables['colorBackground'] = self::get_property( 'background_color', $field, '' );
				$variables['colorText']       = self::get_property( 'text_color', $field, '' );
				$variables['colorDanger']     = self::get_property( 'error', $field, '' );
			}
			// Remove empty values.
			$variables = array_filter( $variables );

			$appearance = array(
				'theme'     => self::get_property( 'theme', $field, 'stripe' ),
				'variables' => $variables,
			);
			if ( $custom_fonts && $data_font_family ) {
				$appearance['variables']['fontFamily'] = $data_font_family;
				$elements_options['fonts'][]           = array(
					'family' => $data_font_family,
					'cssSrc' => 'https://fonts.bunny.net/css?family=' . $data_font_family,
				);
			}
			$elements_options['appearance'] = $appearance;

			$dynamic_methods = self::get_property( 'automatic_payment_methods', $field, 'true' );
			// Checkout Sessions manages payment method availability from the session configuration.
			if ( 'checkout_session' !== $payment_api && 'false' === $dynamic_methods ) {
				$elements_options['paymentMethodTypes'] = array( 'card' );
			}

			/**
			 * Filter Stripe OCS Elements options
			 *
			 * @since 1.38
			 *
			 * @param array $elements_options Elements options.
			 * @param array $field Field.
			 */
			$elements_options          = apply_filters( 'forminator_field_stripe_ocs_elements_options', $elements_options, $field );
			$checkout_elements_options = $elements_options;
			unset(
				$checkout_elements_options['paymentMethodTypes'],
				$checkout_elements_options['paymentMethodCreation'],
				$checkout_elements_options['locale']
			);

			$payment_options = array(
				'layout' => self::get_layout( $field ),
			);

			if ( 'checkout_session' !== $payment_api && 'false' === $dynamic_methods ) {
				$payment_options['wallets'] = array(
					'applePay'  => 'never',
					'googlePay' => 'never',
				);
			}
			/**
			 * Filter Stripe OCS Payment options
			 *
			 * @since 1.38
			 *
			 * @param array $payment_options Payment options.
			 * @param array $field Field.
			 */
			$payment_options = apply_filters( 'forminator_field_stripe_ocs_elements_options', $payment_options, $field );
			$billing_phone   = self::get_property( 'billing_phone', $field, '' );

			$attr = array_merge(
				$this->build_common_element_attributes( $uniqid, $currency, $mode, $is_ocs ),
				array(
					'data-elements-options'          => wp_json_encode( $elements_options, JSON_PRETTY_PRINT ),
					'data-checkout-elements-options' => wp_json_encode( $checkout_elements_options, JSON_PRETTY_PRINT ),
					'data-payment-options'           => wp_json_encode( $payment_options, JSON_PRETTY_PRINT ),
					'data-payment-api'               => $payment_api,
					'data-adaptive-pricing'          => self::is_adaptive_pricing_configured( $field ),
					'data-dynamic-methods'           => $dynamic_methods,
					'data-checkout-email'            => esc_html( $checkout_email ),
					'data-checkout-phone'            => esc_html( $checkout_phone ),
					'data-checkout-required-message' => esc_html__( 'This field is required to complete your payment.', 'forminator' ),
					'data-receipt'                   => filter_var( $receipt, FILTER_VALIDATE_BOOLEAN ),
					'data-receipt-email'             => esc_html( $customer_email ),
					'data-billing'                   => filter_var( $billing, FILTER_VALIDATE_BOOLEAN ),
					'data-billing-name'              => esc_html( $billing_name ),
					'data-billing-email'             => esc_html( $billing_email ),
					'data-billing-phone'             => esc_html( $billing_phone ),
					'data-billing-address'           => esc_html( $billing_address ),
					'data-return-url'                => esc_url( self::get_return_url() ),
				)
			);

			if ( $this->is_checkout_session( $field ) && forminator_show_stripe_developer_widget( $mode ) ) {
				$attr['data-show-stripe-developer-widget'] = 'true';
			}
		}

		if ( ! empty( $description ) ) {
			$attr['aria-describedby'] = esc_attr( $full_id . '-description' );
		}

		/**
		 * Filter Stripe field attributes.
		 *
		 * @since 1.56
		 *
		 * @param array $attr   Stripe field attributes.
		 * @param array $field  Field settings.
		 * @param bool  $is_ocs Whether Stripe is using the Optimized Checkout Suite.
		 */
		$attr = apply_filters( 'forminator_field_stripe_attributes', $attr, $field, $is_ocs );

		$attributes = self::implode_attr( $attr );

		$html = '<div class="forminator-field">';

		$html .= self::get_field_label( $label, $id . '-field', true );

		if ( 'above' === $descr_position ) {
			$html .= self::get_description( $description, $full_id, $descr_position );
		}

		if ( 'material' === $settings['form-substyle'] ) {
			$classes = 'forminator-input--wrap forminator-input--stripe';

			if ( empty( $label ) ) {
				$classes .= ' forminator--no_label';
			}

			$html .= '<div class="' . $classes . '">';
		}

		if ( $is_ocs && self::is_adaptive_pricing_configured( $field ) ) {
			$html .= sprintf(
				'<div id="%s" class="forminator-stripe-payment-element"></div>',
				esc_attr( 'payment-currency-selector-element-' . $uniqid )
			);
		}

		$html .= sprintf( '<div id="%s" %s class="forminator-stripe-element%s"></div>', $full_id, $attributes, ( $is_ocs ? ' forminator-stripe-payment-element' : '' ) );

		if ( $should_render_contact ) {
			$html .= sprintf(
				'<div id="%s" class="forminator-stripe-contact-element forminator-stripe-payment-element"></div>',
				esc_attr( 'payment-contact-element-' . $uniqid )
			);
		}

		$html .= sprintf( '<input type="hidden" name="paymentid" value="%s" id="forminator-stripe-paymentid"/>', '' );
		$html .= sprintf( '<input type="hidden" name="paymentmethod" value="%s" id="forminator-stripe-paymentmethod"/>', '' );
		$html .= sprintf( '<input type="hidden" name="subscriptionid" value="%s" id="forminator-stripe-subscriptionid"/>', '' );

		if ( 'material' === $settings['form-substyle'] ) {
			$html .= '</div>';
		}

		$html .= '<span class="forminator-card-message"><span class="forminator-error-message" aria-hidden="true"></span></span>';

		if ( 'above' !== $descr_position ) {
			$html .= self::get_description( $description, $full_id, $descr_position );
		}

		$html .= '</div>';

		return apply_filters( 'forminator_field_stripe_markup', $html, $attr, $field );
	}

	/**
	 * Get layout Stripe settings
	 *
	 * @param array $field Field.
	 * @return array
	 */
	private static function get_layout( $field ) {
		$layout = self::get_property( 'layout', $field, 'tabs' );
		if ( 'accordion+radio' === $layout ) {
			$radios = 'always';
			$layout = 'accordion';
		}
		$data = array(
			'type'             => $layout,
			'defaultCollapsed' => false,
		);

		if ( 'accordion' === $layout ) {
			$data['spacedAccordionItems'] = false;
			$data['radios']               = ! empty( $radios ) ? $radios : 'never';
		}
		return $data;
	}

	/**
	 * Generate Payment Intent object
	 *
	 * @since 1.7.3
	 *
	 * @param int|float $amount Amount.
	 * @param array     $field Field.
	 *
	 * @return mixed
	 */
	public function generate_paymentIntent( $amount, $field ) {
		if ( $this->is_checkout_session( $field ) ) {
			return $this->generate_checkout_session( $amount, $field );
		}

		$context         = $this->build_payment_request_context( $field );
		$metadata_data   = $this->build_metadata_context( $field );
		$currency        = $context['currency'];
		$description     = $context['description'];
		$company         = $context['company'];
		$key             = $context['key'];
		$metadata_object = $metadata_data['metadata'];

		\Forminator\Stripe\Stripe::setApiKey( $key );

		Forminator_Gateway_Stripe::set_stripe_app_info();

		// Default options.
		$options = array(
			'amount'   => $this->calculate_amount( $amount, $currency ),
			'currency' => $currency,
			'confirm'  => false,
		);

		$dynamic_methods = self::get_property( 'automatic_payment_methods', $field, 'true' );
		if ( 'false' === $dynamic_methods ) {
			$options['payment_method_types'] = array( 'card' );
		} else {
			$options['automatic_payment_methods'] = array(
				'enabled' => true,
			);
			$options['payment_method_options']    = array(
				'wechat_pay' => array(
					'client' => 'web', // Specify the client type.
				),
			);
		}

		if ( ! empty( Forminator_CForm_Front_Action::$prepared_data['paymentmethod'] ) ) {
			$options['payment_method'] = Forminator_CForm_Front_Action::$prepared_data['paymentmethod'];
		}

		// Check if metadata is not empty and add it to the options.
		if ( ! empty( $metadata_object ) ) {
			$options['metadata'] = $metadata_object;
		}

		// Check if statement_description is not empty and add it to the options.
		if ( ! empty( $company ) ) {
			$options['statement_descriptor_suffix'] = $company;
		}

		// Check if description is not empty and add it to the options.
		if ( ! empty( $description ) ) {
			$options['description'] = $description;
		}

		$options = apply_filters( 'forminator_stripe_payment_intent_options', $options, $field );

		try {
			// Create Payment Intent object.
			$intent = \Forminator\Stripe\PaymentIntent::create( $options );
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'PaymentIntent::create',
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'options'       => $options,
				)
			);

			$response = array(
				'message'     => $e->getMessage(),
				'errors'      => array(),
				'paymentPlan' => $this->payment_plan_hash,
			);

			wp_send_json_error( $response );
		}

		return $intent;
	}
	/**
	 * Check if the field uses Checkout Sessions
	 *
	 * @since 1.56
	 *
	 * @param array $field Field settings.
	 * @return bool
	 */
	public function is_checkout_session( $field ): bool {
		return 'stripe-ocs' === self::get_property( 'type', $field, '' )
			&& 'checkout_session' === self::get_payment_api( $field );
	}

	/**
	 * Get the configured Stripe payment API.
	 *
	 * @since 1.56
	 *
	 * @param array $field Field settings.
	 * @return string
	 */
	private static function get_payment_api( $field ): string {
		$payment_api = self::get_property( 'payment_api', $field, 'checkout_session' );

		// Force use of Checkout Sessions for the Stripe OCS field, regardless of the configured payment API.
		if ( 'stripe-ocs' === self::get_property( 'type', $field, '' ) ) {
			$payment_api = 'checkout_session';
		}

		return apply_filters( 'forminator_stripe_payment_api', $payment_api, $field );
	}

	/**
	 * Build shared Stripe metadata payload.
	 *
	 * @since 1.56
	 *
	 * @param array $field Field settings.
	 * @return array{form_id:string,metadata:array<string,string>}
	 */
	private function build_metadata_context( $field ): array {
		$metadata        = self::get_property( 'options', $field, '' );
		$metadata        = is_array( $metadata ) ? $metadata : array();
		$metadata_object = array();

		foreach ( $metadata as $meta ) {
			$label = trim( $meta['label'] );
			$value = $meta['value'] ?? '';

			// Stripe metadata entries cannot have both empty keys and values.
			if ( '' === $label && '' === $value ) {
				continue;
			}
			if ( '' === $label ) {
				$label = $value;
			}

			$metadata_object[ $label ] = forminator_replace_form_data( '{' . $value . '}', Forminator_Front_Action::$module_object );
		}

		$form_id = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';
		if ( '' !== $form_id ) {
			$metadata_object['forminator_form_id'] = $form_id;
		}

		return array(
			'form_id'  => $form_id,
			'metadata' => $metadata_object,
		);
	}

	/**
	 * Get the Forminator form ID stored in Stripe metadata.
	 *
	 * @since 1.56
	 *
	 * @param mixed $metadata Stripe metadata object.
	 * @return string
	 */
	public static function get_form_id_from_stripe_metadata( $metadata ): string {
		if ( is_object( $metadata ) && isset( $metadata->forminator_form_id ) ) {
			return (string) $metadata->forminator_form_id;
		}

		if ( is_array( $metadata ) && isset( $metadata['forminator_form_id'] ) ) {
			return (string) $metadata['forminator_form_id'];
		}

		return '';
	}

	/**
	 * Check that a PaymentIntent belongs to the form being submitted
	 *
	 * Forminator stamps the form ID into the intent metadata on creation, so a
	 * client supplied ID from another form is rejected before it is read or updated.
	 *
	 * @since 1.58
	 *
	 * @param object $intent Stripe PaymentIntent object.
	 *
	 * @return bool
	 */
	private static function is_payment_intent_of_current_form( $intent ): bool {
		$expected_form_id = ! empty( Forminator_Front_Action::$module_object->id )
			? (string) Forminator_Front_Action::$module_object->id
			: '';

		if ( '' === $expected_form_id ) {
			return false;
		}

		$intent_metadata = isset( $intent->metadata ) ? $intent->metadata : null;

		return self::get_form_id_from_stripe_metadata( $intent_metadata ) === $expected_form_id;
	}

	/**
	 * Prepare shared Stripe API context values.
	 *
	 * @since 1.56
	 *
	 * @param array $field Field settings.
	 * @return array{currency:string,mode:string,description:string,company:string,key:string}
	 */
	private function build_payment_request_context( $field ): array {
		$currency    = self::get_property( 'currency', $field, $this->get_default_currency() );
		$mode        = self::get_property( 'mode', $field, 'test' );
		$description = self::get_property( 'product_description', $field, '' );
		if ( ! empty( $description ) ) {
			$description = forminator_replace_form_data( $description, Forminator_Front_Action::$module_object );
		}
		$company = self::get_statement_descriptor_suffix( $field, Forminator_Front_Action::$module_object );
		$key     = $this->get_secret_key( 'test' !== $mode );

		return array(
			'currency'    => $currency,
			'mode'        => $mode,
			'description' => $description,
			'company'     => $company,
			'key'         => $key,
		);
	}

	/**
	 * Get statement descriptor suffix within Stripe's length limit.
	 *
	 * @since 1.56
	 *
	 * @param array       $field Field settings.
	 * @param object|null $custom_form Custom form object.
	 * @return string
	 */
	public static function get_statement_descriptor_suffix( $field, $custom_form = null ): string {
		$company = self::get_property( 'company_name', $field, '' );

		if ( ! empty( $company ) && $custom_form ) {
			$company = forminator_replace_form_data( $company, $custom_form );
		}

		if ( mb_strlen( $company ) > 22 ) {
			$company = mb_substr( $company, 0, 19 ) . '...';
		}

		return $company;
	}

	/**
	 * Build shared Stripe element data attributes.
	 *
	 * @since 1.56
	 *
	 * @param string $uniqid Unique field id.
	 * @param string $currency Currency.
	 * @param string $mode Mode.
	 * @param bool   $is_ocs Whether this is the OCS field variant.
	 * @return array<string,mixed>
	 */
	private function build_common_element_attributes( $uniqid, $currency, $mode, $is_ocs ): array {
		return array(
			'data-field-id'     => $uniqid,
			'data-is-payment'   => 'true',
			'data-payment-type' => $this->type,
			'data-is-ocs'       => $is_ocs,
			'data-secret'       => '',
			'data-paymentid'    => '',
			'data-currency'     => strtolower( $currency ),
			'data-key'          => esc_html( $this->get_publishable_key( 'test' !== $mode ) ),
		);
	}

	/**
	 * Resolve Checkout Session billing mappings that are safe to expose to the frontend.
	 *
	 * Stripe Checkout billing-name mapping should only be passed through when the
	 * paired billing address mapping is still active and its Country subfield is enabled.
	 *
	 * @since 1.56
	 *
	 * @param array                 $field Field settings.
	 * @param Forminator_Form_Model $form_model Form model.
	 * @param int                   $form_id Form ID.
	 * @return array{billing_name:string,billing_address:string}
	 */
	private function get_checkout_session_billing_mappings( $field, Forminator_Form_Model $form_model, $form_id ): array {
		$billing = filter_var( self::get_property( 'billing', $field, false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $billing ) {
			return array(
				'billing_name'    => '',
				'billing_address' => '',
			);
		}

		$billing_name    = self::get_property( 'billing_name', $field, '' );
		$billing_address = self::get_property( 'billing_address', $field, '' );
		$form_fields     = $form_model->get_fields();

		$billing_name              = forminator_get_active_mapped_field_id( $billing_name, $form_fields, $form_id );
		$billing_address           = forminator_get_active_mapped_field_id( $billing_address, $form_fields, $form_id );
		$has_valid_billing_address = ! empty( $billing_address ) && $this->address_field_has_country_subfield( $billing_address, $form_model );

		if ( ! $has_valid_billing_address ) {
			$billing_name    = '';
			$billing_address = '';
		}

		return array(
			'billing_name'    => $billing_name,
			'billing_address' => $billing_address,
		);
	}

	/**
	 * Check whether an address field has its Country subfield enabled.
	 *
	 * @since 1.56
	 *
	 * @param string                $field_id Address field ID.
	 * @param Forminator_Form_Model $form_model Form model.
	 * @return bool
	 */
	private function address_field_has_country_subfield( $field_id, Forminator_Form_Model $form_model ): bool {
		$field = $form_model->get_field( $field_id, false );

		return $field && filter_var( $field->__get( 'address_country' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Generate Checkout Session object
	 *
	 * @since 1.56
	 *
	 * @param int|float $amount Amount.
	 * @param array     $field Field.
	 * @param bool      $send_json_error Whether to send a JSON error or return a WP_Error on failure.
	 *
	 * @return mixed|WP_Error
	 */
	private function generate_checkout_session( $amount, $field, $send_json_error = true ) {
		$context         = $this->build_payment_request_context( $field );
		$metadata_data   = $this->build_metadata_context( $field );
		$currency        = $context['currency'];
		$description     = $context['description'];
		$company         = $context['company'];
		$key             = $context['key'];
		$language        = self::get_property( 'language', $field, 'auto' );
		$form_id         = $metadata_data['form_id'];
		$plan_name       = $this->payment_plan['plan_name'] ?? esc_html__( 'Plan 1', 'forminator' );
		$quantity        = 1;
		$dynamic_methods = self::get_property( 'automatic_payment_methods', $field, 'true' );

		\Forminator\Stripe\Stripe::setApiKey( $key );

		Forminator_Gateway_Stripe::set_stripe_app_info();

		$options = array(
			'line_items'          => $this->build_checkout_session_line_items( $amount, $currency, $plan_name, $quantity ),
			'mode'                => 'payment',
			'ui_mode'             => 'elements',
			'locale'              => $language,
			'return_url'          => self::get_return_url( true ),
			'client_reference_id' => $form_id,
		);

		if ( 'false' === $dynamic_methods ) {
			$options['payment_method_types'] = array( 'card' );
		} else {
			$excluded_payment_method_types = self::get_checkout_session_excluded_payment_methods( $form_id );
			if ( ! empty( $excluded_payment_method_types ) && is_array( $excluded_payment_method_types ) ) {
				$options['excluded_payment_method_types'] = $excluded_payment_method_types;
			}
		}

		// Enable Stripe phone collection only when the saved mapping still points to an active Phone field.
		if ( $this->is_checkout_phone_collection_enabled( $field ) ) {
			$options['phone_number_collection'] = array(
				'enabled' => true,
			);
		}

		if ( self::is_adaptive_pricing_configured( $field ) ) {
			$options['adaptive_pricing'] = array(
				'enabled' => true,
			);
		}

		if ( ! empty( $description ) ) {

			// Set the PaymentIntent description because Stripe does not copy it from the product.
			$options['payment_intent_data']['description'] = $description;
		}

		if ( ! empty( $company ) ) {
			$options['payment_intent_data']['statement_descriptor_suffix'] = $company;
		}

		try {
			$options = apply_filters( 'forminator_stripe_checkout_session_options', $options, $field );

			$session = \Forminator\Stripe\Checkout\Session::create(
				$options,
				self::get_checkout_session_request_options()
			);

			self::set_pending_checkout_session( $session->id );
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'          => 'Checkout\\Session::create',
					'error_class'     => get_class( $e ),
					'error_message'   => $e->getMessage(),
					'error_code'      => $e->getCode(),
					'options'         => $options,
					'request_options' => isset( $request_options ) ? $request_options : self::get_checkout_session_request_options(),
				)
			);

			$is_preview  = filter_input( INPUT_POST, 'is_preview', FILTER_VALIDATE_BOOLEAN );
			$stripe_code = $e instanceof \Forminator\Stripe\Exception\ApiErrorException ? $e->getStripeCode() : '';
			$message     = \Forminator\Stripe\ErrorObject::CODE_AMOUNT_TOO_SMALL === $stripe_code
				? esc_html__( 'Unable to process checkout. Amount is below the minimum payment allowed.', 'forminator' )
				: (
					$is_preview
						? esc_html( $e->getMessage() )
						: esc_html__( 'Unable to initialize checkout. Please try again.', 'forminator' )
				);

			$response = array(
				'message'         => $message,
				'errors'          => array(),
				'paymentPlan'     => $this->payment_plan_hash,
				'exception_class' => get_class( $e ),
				'error_message'   => $e->getMessage(),
				'stripe_code'     => $stripe_code,
			);

			if ( ! $send_json_error ) {
				return new WP_Error( 'forminator_stripe_error', $e->getMessage(), $response );
			}

			wp_send_json_error( $response );
		}

		return $session;
	}

	/**
	 * Build line items for Checkout Session requests.
	 *
	 * @since 1.56
	 *
	 * @param int|float $amount Charge amount.
	 * @param string    $currency Currency.
	 * @param string    $plan_name Product name.
	 * @param int       $quantity Quantity.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_checkout_session_line_items( $amount, $currency, $plan_name, $quantity ): array {
		return array(
			array(
				'price_data' => array(
					'currency'     => strtolower( $currency ),
					'product_data' => array(
						'name' => $plan_name,
					),
					'unit_amount'  => $this->calculate_amount( $amount, $currency ),
				),
				'quantity'   => max( 1, $quantity ),
			),
		);
	}

	/**
	 * Calculate Stripe amount
	 *
	 * @since 1.11
	 *
	 * @param int|float $amount Amount.
	 * @param string    $currency Currency.
	 *
	 * @return int
	 */
	public function calculate_amount( $amount, $currency ) {
		$zero_decimal_currencies = self::get_zero_decimal_currencies();

		// Anything but a finite number that fits an integer amount counts as 0, which is never charged.
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || abs( (float) $amount ) >= PHP_INT_MAX / 1000 ) {
			$amount = 0;
		}

		// Check if currency is zero decimal, then return the rounded whole-number amount.
		if ( in_array( $currency, $zero_decimal_currencies, true ) ) {
			return (int) round( (float) $amount );
		}

		// If JOD, amount needs to have 3 decimals and multiplied to 1000.
		if ( 'JOD' === $currency ) {
			$amount = number_format( $amount, 3, '.', '' );
			return (int) round( (float) $amount * 1000 );
		}

		$amount = number_format( $amount, 2, '.', '' );

		// Currency has decimals, multiply by 100.
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Get a payment-mode Checkout Session amount that is only used to render Checkout Elements.
	 *
	 * @since 1.56
	 *
	 * @param int|float $amount   Amount.
	 * @param string    $currency Currency.
	 * @param array     $field    Field.
	 * @return int|float
	 */
	private function get_checkout_session_render_amount( $amount, $currency, $field ) {
		$currency                = strtoupper( (string) $currency );
		$minimum_amount          = in_array( $currency, self::get_zero_decimal_currencies(), true ) ? 50 : 5;
		$currency_minimum_amount = array(
			'BIF' => 2000,
			'CLP' => 600,
			'CZK' => 20,
			'DJF' => 120,
			'GNF' => 6000,
			'HKD' => 10,
			'HUF' => 200,
			'JPY' => 100,
			'KMF' => 400,
			'KRW' => 1000,
			'MGA' => 3000,
			'MXN' => 20,
			'PYG' => 4000,
			'RWF' => 1000,
			'THB' => 20,
			'UGX' => 2500,
			'VND' => 20000,
			'VUV' => 100,
			'XAF' => 400,
			'XOF' => 400,
			'XPF' => 7000,
		);

		if ( 'JOD' === $currency ) {
			$minimum_amount = 1;
		} elseif ( isset( $currency_minimum_amount[ $currency ] ) ) {
			$minimum_amount = $currency_minimum_amount[ $currency ];
		}

		$amount = max( (float) $amount, $minimum_amount );

		return apply_filters( 'forminator_stripe_default_payment_intent_amount', $amount, $field );
	}

	/**
	 * Return currencies without decimal
	 *
	 * @since 1.11
	 *
	 * @return array
	 */
	public static function get_zero_decimal_currencies() {
		return array(
			'MGA',
			'BIF',
			'CLP',
			'PYG',
			'DJF',
			'RWF',
			'GNF',
			'UGX',
			'VND',
			'JPY',
			'VUV',
			'XAF',
			'KMF',
			'XOF',
			'KRW',
			'XPF',
		);
	}

	/**
	 * Update amount
	 *
	 * @since 1.7.3
	 *
	 * @param array $submitted_data Submitted data.
	 * @param array $field Field.
	 * @throws Exception When there is an error.
	 */
	public function update_paymentIntent( $submitted_data, $field ) {
		if ( $this->is_checkout_session( $field ) ) {
			$this->update_checkout_session( $submitted_data, $field );
			return;
		}

		$mode      = self::get_property( 'mode', $field, 'test' );
		$currency  = self::get_property( 'currency', $field, $this->get_default_currency() );
		$is_multi  = self::get_property( 'automatic_payment_methods', $field, 'true' );
		$is_intent = ! empty( $submitted_data['stripe-intent'] );

		if ( ! empty( $this->payment_plan['payment_method'] ) && 'subscription' === $this->payment_plan['payment_method'] ) {
			$response_data = array(
				'paymentid'     => 'subscription',
				'paymentsecret' => 'subscription',
				'paymentPlan'   => $this->payment_plan_hash,
			);

			// Wallet preview: set amount for Apple/Google Pay preview from local price × qty (no Stripe API).
			if ( $is_intent && forminator_is_stripe_addon_compatible() ) {
				try {
					$field_object = Forminator_Core::get_field_object( 'stripe' );
					$payment_plan = $field_object->get_payment_plan( $field );
					$amount       = $this->get_subscription_amount( $payment_plan, $field_object, Forminator_Front_Action::$prepared_data, $field );
					if ( $amount > 0 ) {
						$response_data['amount'] = $this->calculate_amount( $amount, $currency );
					}
				} catch ( Exception $e ) {
					forminator_maybe_log( __METHOD__, $e->getMessage() );
				}
			}
			wp_send_json_success( $response_data );
		}

		// apply merge tags to payment description.
		$product_description = isset( $field['product_description'] ) ? $field['product_description'] : '';
		if ( ! empty( $product_description ) ) {
			$product_description          = forminator_replace_form_data( $product_description, Forminator_Front_Action::$module_object );
			$field['product_description'] = $product_description;
		}

		// Get Stripe key.
		$key = $this->get_secret_key( 'test' !== $mode );

		// Set Stripe key.
		\Forminator\Stripe\Stripe::setApiKey( $key );

		Forminator_Gateway_Stripe::set_stripe_app_info();

		$field_id = Forminator_Field::get_property( 'element_id', $field );
		// Hidden or unresolved fields are never charged.
		$amount = in_array( $field_id, Forminator_CForm_Front_Action::$hidden_fields, true ) ? 0 : $this->get_payment_amount( $field );
		$id     = $submitted_data['paymentid'];
		if ( ! $amount && ! empty( $submitted_data['stripe_first_payment_intent'] ) ) {
			// If amount is empty, set it to 1 for payment intent. Anyway, it will be updated during actual payment.
			$amount = 1;
			// Filter amount. It can be used to modify amount before creating payment intent for low-value currency
			// to achieve minimum Stripe charge amount .5 euro. Use $field['currency'] to get currency code.
			$amount = apply_filters( 'forminator_stripe_default_payment_intent_amount', $amount, $field );
		}
		$payment_method     = filter_var( $field['automatic_payment_methods'], FILTER_VALIDATE_BOOLEAN ) ? 'dynamic' : 'card';
		$payment_intent_key = $mode . '_' . $currency . '_' . $amount . '_' . substr( $key, -5 ) . '_' . $payment_method;
		// Only reuse an intent this browser created.
		if ( ! empty( $id ) && ! self::is_payment_intent_owner( $id ) ) {
			$id = '';
		}
		// A client supplied ID is untrusted, an empty one comes from our own cache.
		$is_client_intent_id = ! empty( $id );
		// Check if we already have payment ID, if not generate new one.
		if ( ! $is_client_intent_id ) {
			$generate_new = ! $is_intent;
			$id           = $this->get_payment_intent_id( $amount, $field, $payment_intent_key, $generate_new, ! $is_intent );
		}

		$is_foreign_intent = false;

		try {
			// Retrieve PI object.
			$intent = \Forminator\Stripe\PaymentIntent::retrieve( $id );
			// Never expose or modify an intent belonging to another form.
			$is_foreign_intent = ! self::is_payment_intent_of_current_form( $intent );
			if ( $is_foreign_intent ) {
				throw new Exception( esc_html__( 'Payment Intent ID is not valid', 'forminator' ) );
			}
			if ( 'succeeded' === $intent->status ) {
				// throw error if payment intent already succeeded.
				throw new Exception( esc_html__( 'Payment already succeeded.', 'forminator' ) );
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'            => 'PaymentIntent::retrieve',
					'error_class'       => get_class( $e ),
					'error_message'     => $e->getMessage(),
					'error_code'        => $e->getCode(),
					'payment_intent_id' => $id,
				)
			);

			// A client supplied foreign ID fails closed, a stale cached one is regenerated below.
			if ( $is_foreign_intent && $is_client_intent_id ) {
				wp_send_json_error(
					array(
						'message'     => esc_html__( 'Payment Intent ID is not valid', 'forminator' ),
						'errors'      => array(),
						'paymentPlan' => $this->payment_plan_hash,
					)
				);
			}

			$id = $this->get_payment_intent_id( $amount, $field, $payment_intent_key, true, ! $is_intent );

			$intent = \Forminator\Stripe\PaymentIntent::retrieve( $id );
		}

		$metadata_data = $this->build_metadata_context( $field );
		$metadata      = $metadata_data['metadata'];

		// Throw error if payment ID is empty.
		if ( empty( $id ) ) {
			$response = array(
				'paymentPlan' => $this->payment_plan_hash,
				'message'     => esc_html__( 'Your Payment ID is empty, please reload the page and try again!', 'forminator' ),
				'errors'      => array(),
			);

			wp_send_json_error( $response );
		}

		if ( $is_intent ) {
			wp_send_json_success(
				array(
					'paymentid'     => $id,
					'paymentsecret' => $intent->client_secret,
					'paymentPlan'   => $this->payment_plan_hash,
				)
			);
		} elseif ( 'succeeded' === $intent->status ) {
			// Check if the PaymentIntent already succeeded and continue.
			wp_send_json_success(
				array(
					'paymentid'     => $id,
					'paymentsecret' => $intent->client_secret,
				)
			);
		} else {
			try {
				// Check payment method.
				if ( ! empty( $submitted_data['payment_method_type'] ) && in_array( $submitted_data['payment_method_type'], self::get_unsupported_payment_methods(), true ) ) {
					throw new Exception( esc_html__( 'The selected Payment Method is not supported.', 'forminator' ) );
				}
				// Check payment amount, in the units Stripe charges.
				if ( 1 > $this->calculate_amount( $amount, $currency ) ) {
					throw new Exception( esc_html__( 'Payment amount should be greater than 0.', 'forminator' ) );
				}

				// Check payment ID.
				if ( empty( $id ) ) {
					throw new Exception( esc_html__( 'Your Payment ID is empty!', 'forminator' ) );
				}

				// Check payment method.
				if ( empty( $submitted_data['payment_method'] ) ) {
					throw new Exception( esc_html__( 'Your Payment Method is empty!', 'forminator' ) );
				}

				$options = array(
					'amount' => $this->calculate_amount( $amount, $currency ),
				);

				if ( 'blik' !== $submitted_data['payment_method_type'] ) {
					$options['payment_method'] = $submitted_data['payment_method'];
				}

				// Update receipt email if set on front-end.
				if ( isset( $submitted_data['receipt_email'] ) && ! empty( $submitted_data['receipt_email'] ) ) {
					$options['receipt_email'] = $submitted_data['receipt_email'];
				}

				if ( ! empty( $metadata ) ) {
					$options['metadata'] = $metadata;
				}

				// Update Payment Intent amount.
				\Forminator\Stripe\PaymentIntent::update(
					$id,
					$options
				);

				// Return success.
				wp_send_json_success(
					array(
						'paymentid'     => $id,
						'paymentsecret' => $intent->client_secret,
						'paymentPlan'   => $this->payment_plan_hash,
					)
				);

			} catch ( Exception $e ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'            => 'PaymentIntent::update',
						'error_class'       => get_class( $e ),
						'error_message'     => $e->getMessage(),
						'error_code'        => $e->getCode(),
						'payment_intent_id' => $id,
						'options'           => isset( $options ) ? $options : array(),
					)
				);

				$response = array(
					'message'     => $e->getMessage(),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				);

				wp_send_json_error( $response );
			}
		}
	}

	/**
	 * Create Checkout Session for front-end updates
	 *
	 * @since 1.56
	 *
	 * @param array $submitted_data Submitted data.
	 * @param array $field Field.
	 */
	private function update_checkout_session( $submitted_data, $field ) {
		$payment_id = $submitted_data['paymentid'] ?? '';

		if ( ! empty( $this->payment_plan['payment_method'] ) && 'subscription' === $this->payment_plan['payment_method'] ) {
			if ( ! forminator_is_stripe_addon_compatible() ) {
				wp_send_json_error(
					array(
						'message'     => esc_html(
							apply_filters(
								'forminator_payment_require_stripe_subscription_addon_error_message',
								esc_html__( 'Forminator Stripe Subscription Add-on is required to submit this form.', 'forminator' )
							)
						),
						'errors'      => array(),
						'paymentPlan' => $this->payment_plan_hash,
					)
				);
			}

			$stripe_addon = Forminator_Stripe_Subscription::get_instance();
			$response     = $stripe_addon->update_checkout_session( $this, Forminator_Front_Action::$module_object, $submitted_data, $field, $this->payment_plan );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message'     => $response->get_error_message(),
						'errors'      => array(),
						'paymentPlan' => $this->payment_plan_hash,
					)
				);
			}

			wp_send_json_success(
				array(
					'paymentid'                      => $response['paymentid'] ?? '',
					'paymentsecret'                  => $response['paymentsecret'] ?? '',
					'paymentPlan'                    => $this->payment_plan_hash,
					'paymentApi'                     => 'checkout_session',
					'checkoutPhoneCollectionEnabled' => $this->is_checkout_phone_collection_enabled( $field ),
				)
			);
		}

		$field_id = Forminator_Field::get_property( 'element_id', $field );
		// Hidden or unresolved fields are never charged.
		$amount = in_array( $field_id, Forminator_CForm_Front_Action::$hidden_fields, true ) ? 0 : $this->get_payment_amount( $field );

		if ( empty( $submitted_data['stripe-intent'] ) && ! empty( $payment_id ) ) {
			$this->retrieve_checkout_session_response( $payment_id, $field );
		}

		$allow_default_amount = empty( $payment_id ) || ! empty( $submitted_data['stripe_first_payment_intent'] );
		$this->create_checkout_session_response( $amount, $field, $allow_default_amount );
	}

	/**
	 * Create a Checkout Session response for front-end updates.
	 *
	 * @since 1.56
	 *
	 * @param int|float $amount Amount.
	 * @param array     $field Field.
	 * @param bool      $allow_default_amount Allow default amount for initial field render.
	 */
	private function create_checkout_session_response( $amount, $field, $allow_default_amount = false ) {
		$validation_amount        = null;
		$force_mount_stripe_field = false;
		$currency                 = self::get_property( 'currency', $field, $this->get_default_currency() );

		if ( 1 > $this->calculate_amount( $amount, $currency ) && $allow_default_amount ) {
			// Initial render still needs a Checkout Session; mark the fallback so submit can rebuild with real pricing.
			$amount                   = $this->get_checkout_session_render_amount( 1, $currency, $field );
			$validation_amount        = $this->calculate_amount( $amount, $currency );
			$force_mount_stripe_field = true;
		}

		if ( 1 > $this->calculate_amount( $amount, $currency ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Payment amount should be greater than 0.', 'forminator' ),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				)
			);
		}

		$checkout_phone_collection_enabled = $this->is_checkout_phone_collection_enabled( $field );
		$session                           = $this->generate_checkout_session( $amount, $field, ! $allow_default_amount );

		if (
			is_wp_error( $session )
			&& \Forminator\Stripe\Exception\InvalidRequestException::class === ( $session->get_error_data()['exception_class'] ?? '' )
			&& \Forminator\Stripe\ErrorObject::CODE_AMOUNT_TOO_SMALL === ( $session->get_error_data()['stripe_code'] ?? '' )
		) {
			$amount                   = $this->get_checkout_session_render_amount( $amount, $currency, $field );
			$validation_amount        = $this->calculate_amount( $amount, $currency );
			$force_mount_stripe_field = true;
			$session                  = $this->generate_checkout_session( $amount, $field );
		}

		if ( is_wp_error( $session ) ) {
			wp_send_json_error( $session->get_error_data() );
		}

		// Block Checkout confirmation when the newly created session no longer matches the current form state.
		$valid = $this->validate_checkout_session( $session, $field, false, $validation_amount );

		if ( is_wp_error( $valid ) ) {
			wp_send_json_error(
				array(
					'message'     => $valid->get_error_message(),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				)
			);
		}

		wp_send_json_success(
			array(
				'paymentid'                      => $session->id,
				'paymentsecret'                  => $session->client_secret,
				'paymentPlan'                    => $this->payment_plan_hash,
				'paymentApi'                     => 'checkout_session',
				'checkoutPhoneCollectionEnabled' => $checkout_phone_collection_enabled,
				'forceMountStripeField'          => $force_mount_stripe_field,
			)
		);
	}

	/**
	 * Retrieve a Checkout Session and send the front-end response.
	 *
	 * @since 1.56
	 *
	 * @param string $payment_id Checkout Session ID.
	 * @param array  $field Field.
	 */
	private function retrieve_checkout_session_response( $payment_id, $field ) {
		// Only the submitting browser may resume the session.
		$session_data = self::get_pending_checkout_session_data( $payment_id );
		$return_flow  = ! empty( $session_data ) && ! empty( $session_data['entry_id'] ) && self::is_payment_owner( $session_data['owner'] ?? '' );

		$session = $this->get_checkout_session_by_id( $payment_id, $field );

		if ( is_wp_error( $session ) ) {
			wp_send_json_error(
				array(
					'message'     => $session->get_error_message(),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				)
			);
		}

		$form_id_meta     = isset( $session->client_reference_id ) ? (string) $session->client_reference_id : '';
		$expected_form_id = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';

		// If we are returning from a redirect flow and form ID matches, return the session details to the front-end.
		if ( $return_flow && $form_id_meta === $expected_form_id ) {
			wp_send_json_success(
				array(
					'paymentid'                      => $session->id,
					'paymentsecret'                  => $session->client_secret ?? '',
					'paymentPlan'                    => $this->payment_plan_hash,
					'paymentApi'                     => 'checkout_session',
					'checkoutPhoneCollectionEnabled' => $this->is_checkout_phone_collection_enabled( $field ),
				)
			);
		}

		wp_send_json_error(
			array(
				'message'     => esc_html__( 'Checkout Session is not valid', 'forminator' ),
				'errors'      => array(),
				'paymentPlan' => $this->payment_plan_hash,
			)
		);
	}

	/**
	 * Check whether the current Checkout Session request should enable Stripe phone collection.
	 *
	 * @since 1.56
	 *
	 * @param array $field Field.
	 * @return bool
	 */
	private function is_checkout_phone_collection_enabled( $field ) {
		$form_id                = Forminator_Front_Action::$module_id;
		$checkout_phone_enabled = filter_var( self::get_property( 'checkout_phone_enabled', $field, false ), FILTER_VALIDATE_BOOLEAN );
		$custom_form            = Forminator_Base_Form_Model::get_model( $form_id );
		$checkout_phone         = $custom_form instanceof Forminator_Form_Model
			? forminator_get_active_mapped_field_id( self::get_property( 'checkout_phone', $field, '' ), $custom_form->get_fields(), $form_id )
			: '';

		return $checkout_phone_enabled
			&& ! empty( $checkout_phone )
			&& ! in_array( $checkout_phone, Forminator_CForm_Front_Action::$hidden_fields, true );
	}

	/**
	 * Validate Checkout Session details against the current form state.
	 *
	 * @since 1.56
	 *
	 * @param mixed    $session Checkout Session object.
	 * @param array    $field Field.
	 * @param bool     $require_paid Whether the session must already be paid.
	 * @param int|null $expected_amount_override Optional amount to validate against instead of recalculating from the form state.
	 * @return true|WP_Error
	 */
	private function validate_checkout_session( $session, $field, $require_paid = false, $expected_amount_override = null ) {
		$charge_amount     = $this->get_payment_amount( $field );
		$currency          = self::get_property( 'currency', $field, $this->get_default_currency() );
		$expected_amount   = null !== $expected_amount_override
			? (int) $expected_amount_override
			: $this->calculate_amount( $charge_amount, $currency );
		$session_amount    = isset( $session->amount_total ) ? (int) $session->amount_total : 0;
		$session_currency  = isset( $session->currency ) ? strtoupper( (string) $session->currency ) : '';
		$expected_currency = strtoupper( (string) $currency );
		$form_id_meta      = isset( $session->client_reference_id ) ? (string) $session->client_reference_id : '';
		$expected_form_id  = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';
		$payment_status    = $session->payment_status ?? '';

		if (
			( $form_id_meta !== $expected_form_id )
			|| ! self::does_checkout_session_match_expected_pricing( $session, $expected_amount, $expected_currency )
		) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'            => 'checkout_session_validation_failed',
					'error_message'     => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
					'payment_id'        => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
					'form_id'           => $expected_form_id,
					'field_id'          => self::get_property( 'element_id', $field, '' ),
					'require_paid'      => $require_paid,
					'session_id'        => $session->id ?? '',
					'client_reference'  => $form_id_meta,
					'expected_form_id'  => $expected_form_id,
					'session_amount'    => $session_amount,
					'expected_amount'   => $expected_amount,
					'session_currency'  => $session_currency,
					'expected_currency' => $expected_currency,
					'payment_status'    => $payment_status,
				)
			);

			return new WP_Error( 'forminator_stripe_error', esc_html__( 'Checkout Session ID is not valid', 'forminator' ) );
		}

		if ( $require_paid && 'paid' !== $payment_status ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'         => 'checkout_session_payment_pending',
					'error_message'  => esc_html__( 'Payment failed. Please try again.', 'forminator' ),
					'payment_id'     => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
					'form_id'        => $expected_form_id,
					'field_id'       => self::get_property( 'element_id', $field, '' ),
					'session_id'     => $session->id ?? '',
					'payment_status' => $payment_status,
				)
			);

			return new WP_Error(
				'forminator_stripe_checkout_session_pending',
				esc_html__( 'Payment failed. Please try again.', 'forminator' )
			);
		}

		return true;
	}

	/**
	 * Copy Checkout Session metadata/description to the PaymentIntent and Charge.
	 *
	 * Modern Checkout creates the PaymentIntent only at confirm, copying description from
	 * Session create (often with empty merge tags). Prefer the description stored on the
	 * pending entry; also update the Charge because Dashboard uses the Charge description.
	 *
	 * @since 1.56
	 *
	 * @param string                           $payment_intent Stripe payment intent ID.
	 * @param array                            $field Field.
	 * @param Forminator_Form_Entry_Model|null $entry Optional entry with stored description.
	 */
	private function sync_checkout_session_payment_intent_metadata( $payment_intent, $field, $entry = null ) {
		if ( empty( $payment_intent ) ) {
			return;
		}

		$template = trim( (string) self::get_property( 'product_description', $field, '' ) );
		if ( '' === $template || false === strpos( $template, '{' ) ) {
			return;
		}

		$mode = self::get_property( 'mode', $field, 'test' );
		$key  = $this->get_secret_key( 'test' !== $mode );

		\Forminator\Stripe\Stripe::setApiKey( $key );
		Forminator_Gateway_Stripe::set_stripe_app_info();

		$metadata_data = $this->build_metadata_context( $field );
		$options       = array();

		if ( ! empty( $metadata_data['metadata'] ) ) {
			$options['metadata'] = $metadata_data['metadata'];
		}

		$description = '';
		if ( $entry instanceof Forminator_Form_Entry_Model ) {
			$stored = $entry->get_meta( '_stripe_checkout_payment_description', '' );
			if ( is_string( $stored ) ) {
				$description = trim( $stored );
			}
		}
		if ( '' === $description ) {
			$description = trim(
				(string) forminator_replace_form_data(
					$template,
					Forminator_Front_Action::$module_object
				)
			);
		}
		if ( '' !== $description ) {
			$options['description'] = substr( $description, 0, 1000 );
		}

		if ( empty( $options ) ) {
			return;
		}

		try {
			\Forminator\Stripe\PaymentIntent::update( $payment_intent, $options );

			if ( empty( $options['description'] ) ) {
				return;
			}

			$intent    = \Forminator\Stripe\PaymentIntent::retrieve(
				$payment_intent,
				array( 'expand' => array( 'latest_charge' ) )
			);
			$charge_id = '';
			if ( ! empty( $intent->latest_charge ) ) {
				$charge_id = is_object( $intent->latest_charge )
					? (string) ( $intent->latest_charge->id ?? '' )
					: (string) $intent->latest_charge;
			}
			if ( '' !== $charge_id ) {
				\Forminator\Stripe\Charge::update(
					$charge_id,
					array( 'description' => $options['description'] )
				);
				forminator_maybe_log(
					__METHOD__,
					array(
						'payment_intent_id' => $payment_intent,
						'charge_id'         => $charge_id,
						'description'       => $options['description'],
					)
				);
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'            => 'PaymentIntent::update',
					'error_class'       => get_class( $e ),
					'error_message'     => $e->getMessage(),
					'error_code'        => $e->getCode(),
					'payment_intent_id' => $payment_intent,
					'options'           => $options,
				)
			);
		}
	}

	/**
	 * Store resolved payment description on the pending entry (form data is available here).
	 *
	 * @since 1.58
	 *
	 * @param Forminator_Form_Entry_Model|null $entry Entry.
	 * @param array                            $field Field.
	 * @return void
	 */
	private function store_checkout_payment_description( $entry, $field ) {
		if ( ! $entry instanceof Forminator_Form_Entry_Model || empty( $entry->entry_id ) ) {
			return;
		}

		$template = self::get_property( 'product_description', $field, '' );
		if ( '' === trim( (string) $template ) ) {
			return;
		}

		$description = trim( (string) forminator_replace_form_data( $template, Forminator_Front_Action::$module_object ) );

		if ( '' !== $description ) {
			$entry->add_meta( '_stripe_checkout_payment_description', substr( $description, 0, 1000 ) );
			forminator_maybe_log(
				__METHOD__,
				array(
					'entry_id'    => $entry->entry_id,
					'description' => $description,
				)
			);
		}
	}

	/**
	 * Apply stored payment description to the Session PaymentIntent/Charge after confirm.
	 *
	 * @since 1.58
	 *
	 * @param array                            $field Field.
	 * @param Forminator_Form_Entry_Model|null $entry Entry.
	 * @param string                           $session_id Checkout Session ID.
	 * @return void
	 */
	public function apply_stored_checkout_payment_description( $field, $entry, $session_id ) {
		if ( empty( $session_id ) || ! $entry instanceof Forminator_Form_Entry_Model ) {
			return;
		}

		$session = $this->get_checkout_session_by_id( $session_id, $field );
		if ( is_wp_error( $session ) || ! is_object( $session ) ) {
			return;
		}

		$payment_intent_id = self::get_payment_intent( $session->payment_intent ?? '' );
		if ( ! empty( $payment_intent_id ) ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'payment_intent_id' => $payment_intent_id,
					'session_id'        => $session_id,
				)
			);
			$this->sync_checkout_session_payment_intent_metadata( $payment_intent_id, $field, $entry );
		}
	}

	/**
	 * Get payment intent ID
	 *
	 * Display intents are cached per browser, payment intents are always new and owned by the payer.
	 *
	 * @param int|float $amount Amount.
	 * @param array     $field Field.
	 * @param string    $payment_intent_key Payment intent key.
	 * @param bool      $force Use saved payment intents or not.
	 * @param bool      $for_payment Whether the intent will take the payment.
	 *
	 * @return string
	 */
	private function get_payment_intent_id( $amount, $field, $payment_intent_key, $force = false, $for_payment = false ): string {
		/**
		 * Filter to force payment intent generation
		 *
		 * @param bool  $force Force payment intent generation.
		 * @param array $field Field.
		 */
		$force     = apply_filters( 'forminator_stripe_force_payment_intent', $force, $field );
		$cache_key = self::DISPLAY_INTENT_TRANSIENT_PREFIX . md5( $payment_intent_key . self::get_payment_owner( true ) );
		$cached_id = $for_payment ? '' : get_transient( $cache_key );

		if ( ! $force && ! empty( $cached_id ) && ! self::is_consumed_payment_intent( $cached_id ) ) {
			return $cached_id;
		}

		$payment_intent = $this->generate_paymentIntent( $amount, $field );

		$id = $payment_intent->id;

		if ( $for_payment ) {
			// Only on generation, so a reused ID cannot re-arm a claimed marker.
			self::set_pending_payment_intent( $id );
		} else {
			set_transient( $cache_key, $id, DAY_IN_SECONDS );
		}

		return $id;
	}

	/**
	 * Get unsupported payment methods
	 *
	 * @param bool $is_checkout_session Whether the payment methods are for a Checkout Session.
	 * @param bool $only_immediate_confirmation Whether to exclude payment methods that require delayed confirmation.
	 * @return array
	 */
	public static function get_unsupported_payment_methods( $is_checkout_session = false, $only_immediate_confirmation = false ) {
		if ( true === $is_checkout_session ) {
			// Checkout Sessions support all dynamic payment methods.
			$unsupported_payment_methods = array();

			if ( true === $only_immediate_confirmation ) {
				$unsupported_payment_methods = array(
					'sepa_debit',
					'multibanco',
					'boleto',
					'sofort',
					'us_bank_account',
					'bancontact',
				);
			}
		} else {
			// All Stripe dynamic payment methods without immediate confirmation.
			$unsupported_payment_methods = array(
				'sepa_debit',
				'multibanco',
				'boleto',
				'ach_credit_transfer',
				'ach_debit',
				'sofort',
				'funded',
			);
		}
		return apply_filters(
			'forminator_stripe_unsupported_payment_methods',
			$unsupported_payment_methods
		);
	}

	/**
	 * Get unsupported payment methods that are valid for Checkout Sessions.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	public static function get_checkout_session_excluded_payment_methods( $form_id ) {
		$custom_form                = Forminator_Base_Form_Model::get_model( $form_id );
		$settings                   = ( $custom_form instanceof Forminator_Base_Form_Model ) ? $custom_form->settings : array();
		$allow_only_instant_payment = Forminator_CForm_Front_Action::allow_only_immediate_payments( $settings );
		return self::get_unsupported_payment_methods( true, $allow_only_instant_payment );
	}

	/**
	 * Check whether adaptive pricing should be enabled for a field.
	 *
	 * @since 1.56
	 *
	 * @param array $field Field settings.
	 * @return bool
	 */
	public static function is_adaptive_pricing_configured( $field ) {
		return 'checkout_session' === self::get_payment_api( $field )
			&& filter_var( self::get_property( 'adaptive_pricing', $field, false ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Validate Checkout Session totals against the expected source pricing.
	 *
	 * @since 1.56
	 *
	 * @param object $session Checkout Session object.
	 * @param int    $expected_amount Expected amount in the Checkout Session's minor unit.
	 * @param string $expected_currency Expected Checkout Session currency.
	 * @return bool
	 */
	public static function does_checkout_session_match_expected_pricing( $session, $expected_amount, $expected_currency ) {
		if ( ! is_object( $session ) ) {
			return false;
		}

		if ( ! isset( $session->amount_total, $session->currency ) ) {
			return false;
		}

		$expected_currency = strtoupper( (string) $expected_currency );

		return (int) $session->amount_total === $expected_amount
			&& strtoupper( (string) $session->currency ) === $expected_currency;
	}

	/**
	 * Get form setting
	 *
	 * @since 1.9
	 *
	 * @param int   $id Id.
	 * @param array $settings Settings.
	 * @param mixed $fallback Fallback method.
	 *
	 * @return mixed
	 */
	public function get_form_setting( $id, $settings, $fallback ) {
		// Check if user settings exist.
		if ( isset( $settings[ $id ] ) ) {
			return $settings[ $id ];
		}

		// Return fallback.
		return $fallback;
	}

	/**
	 * Field back-end validation
	 *
	 * @param array        $field Field.
	 * @param array|string $data Data.
	 */
	public function validate( $field, $data ) {
		if ( $this->is_checkout_session( $field ) ) {
			$this->payment_plan = $this->get_payment_plan( $field );
			$is_subscription    = ! empty( $this->payment_plan['payment_method'] ) && 'subscription' === $this->payment_plan['payment_method'];
			$amount             = $this->get_payment_amount( $field );

			if ( $is_subscription ) {
				$amount = self::get_property( 'subscription_amount', $this->payment_plan, 0.0 );

				if ( forminator_is_stripe_addon_compatible() ) {
					$amount = $this->get_subscription_amount( $this->payment_plan, $this, Forminator_CForm_Front_Action::$prepared_data, $field );
				}
			}

			// Check the amount Stripe charges, e.g. 0.001 rounds to 0.
			if ( 1 > $this->calculate_amount( $amount, self::get_property( 'currency', $field, $this->get_default_currency() ) ) ) {
				$dependent_fields = self::get_plan_dependent_fields( $this->payment_plan );
				$field_id         = ! empty( $dependent_fields ) ? reset( $dependent_fields ) : Forminator_Field::get_property( 'element_id', $field );

				Forminator_CForm_Front_Action::$submit_errors[] = array(
					$field_id => esc_html__( 'Payment amount should be greater than 0.', 'forminator' ),
				);
			}
		}

		$checkout_email_enabled = filter_var( self::get_property( 'checkout_email_enabled', $field, false ), FILTER_VALIDATE_BOOLEAN );

		if ( $checkout_email_enabled ) {
			$this->validate_checkout_session_required_field( $field, 'checkout_email' );
		}

		$checkout_phone_enabled = filter_var( self::get_property( 'checkout_phone_enabled', $field, false ), FILTER_VALIDATE_BOOLEAN );
		if ( $checkout_phone_enabled ) {
			$this->validate_checkout_session_required_field( $field, 'checkout_phone' );
		}

		if ( Forminator_Front_Action::$module_object instanceof Forminator_Form_Model ) {
			$checkout_billing_mappings = $this->get_checkout_session_billing_mappings(
				$field,
				Forminator_Front_Action::$module_object,
				Forminator_Front_Action::$module_id
			);

			if ( ! empty( $checkout_billing_mappings['billing_address'] ) ) {
				$this->validate_checkout_session_required_field( $field, 'billing_address', '-country' );
			}
		}
	}

	/**
	 * Validate a mapped Checkout Session required field.
	 *
	 * @since 1.56
	 *
	 * @param array  $field        Field.
	 * @param string $property     Field property.
	 * @param string $field_suffix Optional mapped subfield suffix.
	 */
	public function validate_checkout_session_required_field( $field, $property, $field_suffix = '' ) {
		$field_id = self::get_property( $property, $field, '' );

		if ( ! $this->is_checkout_session( $field ) ) {
			return;
		}

		// Old saved mappings should not block submission once the mapped field is gone from the form.
		$field_id = forminator_get_active_mapped_field_id(
			$field_id,
			Forminator_Front_Action::$module_object->get_fields(),
			Forminator_Front_Action::$module_id
		);

		if ( empty( $field_id ) ) {
			return;
		}

		// Billing country is submitted as an address subfield, so allow callers to validate a derived field like billing_address-country.
		if ( ! empty( $field_suffix ) ) {
			$field_id .= $field_suffix;
		}

		if ( in_array( $field_id, Forminator_CForm_Front_Action::$hidden_fields, true ) ) {
			return;
		}

		$submitted_data  = Forminator_CForm_Front_Action::$prepared_data;
		$submitted_value = $submitted_data[ $field_id ] ?? '';
		if ( is_array( $submitted_value ) ) {
			$submitted_value = reset( $submitted_value );
		}

		if ( '' !== trim( (string) $submitted_value ) ) {
			return;
		}

		Forminator_CForm_Front_Action::$submit_errors[] = array(
			$field_id => esc_html__( 'This field is required to complete your payment.', 'forminator' ),
		);
	}

	/**
	 * Sanitize data
	 *
	 * @param array        $field Field.
	 * @param array|string $data - the data to be sanitized.
	 *
	 * @return array|string $data - the data after sanitization
	 */
	public function sanitize( $field, $data ) {
		$original_data = $data;
		// Sanitize.
		$data = forminator_sanitize_field( $data );

		return apply_filters( 'forminator_field_stripe_sanitize', $data, $field, $original_data );
	}

	/**
	 * Is available
	 *
	 * @since 1.7
	 * @inheritdoc
	 * @param array $field Field.
	 */
	public function is_available( $field ) {
		$mode = self::get_property( 'mode', $field, 'test' );
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( 'test' !== $mode ) {
				$stripe->set_live( true );
			}

			if ( $stripe->is_ready() ) {
				return true;
			}
		} catch ( Forminator_Gateway_Exception $e ) {
			return false;
		}
	}

	/**
	 * Get publishable key
	 *
	 * @since 1.7
	 *
	 * @param bool $live Live?.
	 *
	 * @return bool|string
	 */
	private function get_publishable_key( $live = false ) {
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( $live ) {
				return $stripe->get_live_key();
			}

			return $stripe->get_test_key();
		} catch ( Forminator_Gateway_Exception $e ) {
			return false;
		}
	}

	/**
	 * Get publishable key
	 *
	 * @since 1.7
	 *
	 * @param bool $live Live?.
	 *
	 * @return bool|string
	 */
	private function get_secret_key( $live = false ) {
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( $live ) {
				return $stripe->get_live_secret( true );
			}

			return $stripe->get_test_secret( true );
		} catch ( Forminator_Gateway_Exception $e ) {
			return false;
		}
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
	 * Validate that a PaymentIntent paid for the current submission.
	 *
	 * Intents are confirmed during the submission, so without $require_paid only the states
	 * that can no longer pay are refused.
	 *
	 * @since 1.58.0
	 *
	 * @param mixed $intent       PaymentIntent object.
	 * @param array $field        Field settings.
	 * @param bool  $require_paid Whether the intent must already have been paid.
	 *
	 * @return true|WP_Error
	 */
	public function validate_intent_payment( $intent, $field, $require_paid = false ) {
		$status           = isset( $intent->status ) ? (string) $intent->status : '';
		$intent_id        = $intent->id ?? '';
		$expected_form_id = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';

		if ( 'succeeded' !== $status ) {
			if ( ! $require_paid && in_array( $status, self::PENDING_INTENT_STATUSES, true ) ) {
				return true;
			}

			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'payment_intent_not_paid',
					'error_message' => esc_html__( 'Payment failed, please try again!', 'forminator' ),
					'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
					'form_id'       => $expected_form_id,
					'field_id'      => self::get_property( 'element_id', $field, '' ),
					'require_paid'  => $require_paid,
					'intent_id'     => $intent_id,
					'intent_status' => $status,
				)
			);

			return new WP_Error(
				'forminator_stripe_payment_not_completed',
				esc_html__( 'Payment failed, please try again!', 'forminator' )
			);
		}

		// Stripe has to have received the amount the form asked for.
		$currency        = self::get_property( 'currency', $field, $this->get_default_currency() );
		$expected_amount = $this->calculate_amount( $this->get_payment_amount( $field ), $currency );
		$received_amount = isset( $intent->amount_received ) ? (int) $intent->amount_received : 0;

		if ( $received_amount !== (int) $expected_amount ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'          => 'payment_intent_amount_mismatch',
					'error_message'   => esc_html__( 'Error! Invalid payment amount!', 'forminator' ),
					'payment_id'      => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
					'form_id'         => $expected_form_id,
					'field_id'        => self::get_property( 'element_id', $field, '' ),
					'intent_id'       => $intent_id,
					'expected_amount' => $expected_amount,
					'received_amount' => $received_amount,
				)
			);

			return new WP_Error( 'forminator_stripe_error', esc_html__( 'Error! Invalid payment amount!', 'forminator' ) );
		}

		return true;
	}

	/**
	 * Get stripe meta
	 *
	 * @param Forminator_Form_Entry_Model $entry Entry.
	 * @param array                       $field Field.
	 *
	 * @return array|false
	 */
	public function get_stripe_meta( $entry, $field ) {
		if ( empty( $entry->meta_data ) ) {
			return false;
		}
		$meta_data  = $entry->meta_data;
		$element_id = $field['element_id'] ?? '';
		if ( ! empty( $element_id ) && ! empty( $meta_data[ $element_id ] ) && ! empty( $meta_data[ $element_id ]['value'] ) ) {
			return $meta_data[ $element_id ]['value'];
		}
		return false;
	}

	/**
	 * Check if the entry is a subscription entry.
	 *
	 * @param array $stripe_meta Stripe meta data.
	 *
	 * @return bool
	 */
	private function is_subscription_entry( $stripe_meta ) {
		return isset( $stripe_meta['payment_method'] ) && 'subscription' === $stripe_meta['payment_method'];
	}

	/**
	 * Get checkout session by customer ID.
	 *
	 * @since 1.58
	 *
	 * @param string $customer_id customer ID.
	 * @param string $mode Mode (test or live).
	 *
	 * @return object|false Checkout session object or false if not found.
	 */
	public function get_checkout_session_by_customer( $customer_id, $mode ) {
		$key = $this->get_secret_key( 'test' !== $mode );

		\Forminator\Stripe\Stripe::setApiKey( $key );
		Forminator_Gateway_Stripe::set_stripe_app_info();

		try {
			$session = \Forminator\Stripe\Checkout\Session::all(
				array(
					'customer' => $customer_id,
					'limit'    => 1,
				)
			);
			if ( ! empty( $session->data ) && ! empty( $session->data[0] ) ) {
				return $session->data[0];
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'Checkout\\Session::all',
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'customer'      => $customer_id,
				)
			);
		}
		return false;
	}

	/**
	 * Get checkout session by payment intent ID.
	 *
	 * @since 1.58
	 *
	 * @param string $payment_intent_id Payment intent ID.
	 * @param string $mode Mode (test or live).
	 *
	 * @return object|false Checkout session object or false if not found.
	 */
	public function get_checkout_session_by_payment_intent( $payment_intent_id, $mode ) {
		$key = $this->get_secret_key( 'test' !== $mode );

		\Forminator\Stripe\Stripe::setApiKey( $key );
		Forminator_Gateway_Stripe::set_stripe_app_info();

		try {
			$session = \Forminator\Stripe\Checkout\Session::all(
				array(
					'payment_intent' => $payment_intent_id,
					'limit'          => 1,
				)
			);
			if ( ! empty( $session->data ) && ! empty( $session->data[0] ) ) {
				return $session->data[0];
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'         => 'Checkout\\Session::all',
					'error_class'    => get_class( $e ),
					'error_message'  => $e->getMessage(),
					'error_code'     => $e->getCode(),
					'payment_intent' => $payment_intent_id,
				)
			);
		}
		return false;
	}

	/**
	 * Set processing transaction info on received webhook event for asynchronous payments.
	 *
	 * @since 1.58
	 *
	 * @param array                       $field Stripe field.
	 * @param Forminator_Form_Entry_Model $entry Form entry model.
	 * @param array                       $event_data Stripe webhook event data.
	 *
	 * @return array|false Transaction info or false if not available.
	 */
	public function process_pending_transaction( $field, $entry, $event_data ) {
		$stripe_meta = $this->get_stripe_meta( $entry, $field );
		if ( empty( $stripe_meta ) ) {
			return false;
		}
		$payment_intent_id = self::get_payment_intent( $event_data['payment_intent'] );
		// Always sync so merge-tag descriptions stored at pending-submit reach the Charge/PI.
		$this->sync_checkout_session_payment_intent_metadata( $payment_intent_id, $field, $entry );

		$mode                            = $stripe_meta['mode'] ?? self::get_property( 'mode', $field, 'test' );
		$stripe_meta['transaction_id']   = $payment_intent_id;
		$stripe_meta['transaction_link'] = self::get_transanction_link( $mode, $payment_intent_id );
		$stripe_meta['status']           = 'PROCESSING';
		return $stripe_meta;
	}

	/**
	 * Process transaction info after successful payment.
	 *
	 * @since 1.58
	 *
	 * @param array                       $field Stripe field.
	 * @param Forminator_Form_Entry_Model $entry Form entry model.
	 * @param object                      $custom_form Custom form object.
	 * @param array                       $event_data Stripe webhook event data.
	 *
	 * @return array|false Transaction info or false if not available.
	 */
	public function process_successful_transaction( $field, $entry, $custom_form, $event_data ) {
		$stripe_meta = $this->get_stripe_meta( $entry, $field );
		if ( empty( $stripe_meta ) ) {
			return false;
		}
		if ( $this->is_subscription_entry( $stripe_meta ) ) {
			// For recurring payments.
			if ( class_exists( 'Forminator_Stripe_Subscription' ) ) {
				$stripe_addon = Forminator_Stripe_Subscription::get_instance();
				return $stripe_addon->process_subscription_successful_transaction( $event_data, $stripe_meta, $field, $custom_form );
			}
		} elseif ( ! empty( $event_data['payment_intent'] ) ) {
			// For one-time payments.
			$payment_intent_id = self::get_payment_intent( $event_data['payment_intent'] );
			// Always sync so merge-tag descriptions stored at pending-submit reach the Charge/PI.
			$this->sync_checkout_session_payment_intent_metadata( $payment_intent_id, $field, $entry );

			$mode                            = $stripe_meta['mode'] ?? self::get_property( 'mode', $field, 'test' );
			$stripe_meta['transaction_id']   = $payment_intent_id;
			$stripe_meta['transaction_link'] = self::get_transanction_link( $mode, $payment_intent_id );
			if ( true === $event_data['is_session'] ) {
				$session = $event_data['session'];
				if ( 'paid' === $session->payment_status || 'no_payment_required' === $session->payment_status ) {
					$stripe_meta['status'] = 'COMPLETED';
				} else {
					// For asynchronous payments, wait for the webhook[checkout.session.async_payment_succeeded] to update the status.
					$stripe_meta['status'] = 'UNPAID';
				}
			} else {
				$stripe_meta['status'] = 'COMPLETED';
			}
			return $stripe_meta;
		}
		return false;
	}

	/**
	 * Process failed transaction info after successful payment.
	 *
	 * @since 1.58
	 *
	 * @param array                       $field Stripe field.
	 * @param Forminator_Form_Entry_Model $entry Form entry model.
	 * @param object                      $custom_form Custom form object.
	 * @param array                       $event_data Stripe webhook event data.
	 *
	 * @return array|false Transaction info or false if not available.
	 */
	public function process_failed_transaction( $field, $entry, $custom_form, $event_data ) {
		$stripe_meta = $this->get_stripe_meta( $entry, $field );
		if ( empty( $stripe_meta ) ) {
			return false;
		}
		if ( $this->is_subscription_entry( $stripe_meta ) ) {
			// For recurring payments.
			if ( class_exists( 'Forminator_Stripe_Subscription' ) ) {
				$stripe_addon = Forminator_Stripe_Subscription::get_instance();
				return $stripe_addon->process_subscription_failed_transaction( $event_data, $stripe_meta, $field, $custom_form );
			}
		} elseif ( ! empty( $event_data['payment_intent'] ) ) {
			// For one-time payments.
			$payment_intent_id = self::get_payment_intent( $event_data['payment_intent'] );
			// Always sync so merge-tag descriptions stored at pending-submit reach the Charge/PI.
			$this->sync_checkout_session_payment_intent_metadata( $payment_intent_id, $field, $entry );
			$stripe_meta['transaction_id']   = $payment_intent_id;
			$mode                            = $stripe_meta['mode'] ?? self::get_property( 'mode', $field, 'test' );
			$stripe_meta['transaction_link'] = self::get_transanction_link( $mode, $payment_intent_id );
		}
		$stripe_meta['status'] = 'FAILED';
		return $stripe_meta;
	}

	/**
	 * Get payment intent ID from payment intent object or string.
	 *
	 * @since 1.58
	 *
	 * @param object $payment_intent Checkout session object.
	 * @return string Payment intent ID
	 */
	public static function get_payment_intent( $payment_intent ) {
		if ( is_object( $payment_intent ) ) {
			$payment_intent = $payment_intent->id ?? '';
		}
		return $payment_intent;
	}

	/**
	 * Process to entry data
	 *
	 * @param array                            $field Field.
	 * @param Forminator_Form_Entry_Model|null $entry Entry model.
	 *
	 * @return array|WP_Error
	 * @throws Exception When there is an error.
	 */
	public function process_to_entry_data( $field, $entry = null ) {
		if ( $this->is_checkout_session( $field ) ) {
			return $this->process_checkout_session_to_entry_data( $field, $entry );
		}

		$entry_data = array(
			'mode'             => '',
			'product_name'     => '',
			'payment_type'     => '',
			'amount'           => '',
			'quantity'         => '',
			'currency'         => '',
			'transaction_id'   => '',
			'transaction_link' => '',
		);

		$mode     = self::get_property( 'mode', $field, 'test' );
		$currency = self::get_property( 'currency', $field, $this->get_default_currency() );

		try {
			// Get Payment intent.
			$intent = $this->get_paymentIntent( $field );

			if ( is_wp_error( $intent ) ) {
				throw new Exception( $intent->get_error_message() );
			} elseif ( ! is_object( $intent ) ) {
				// Make sure Payment Intent is object.
				throw new Exception( esc_html__( 'Payment Intent object is not valid Payment object.', 'forminator' ) );
			}

			// Check if the PaymentIntent is set or empty.
			if ( empty( $intent->id ) ) {
				throw new Exception( esc_html__( 'Payment Intent ID is not valid!', 'forminator' ) );
			}
			// Check if the PaymentIntent is valid.
			if ( ! self::is_valid_payment_intent( $intent->id ) ) {
				return new WP_Error(
					'forminator_stripe_payment_intent_already_handled',
					esc_html__( 'Payment Intent ID is not valid', 'forminator' )
				);
			}

			$charge_amount     = $this->get_payment_amount( $field );
			$expected_amount   = $this->calculate_amount( $charge_amount, $currency );
			$intent_amount     = isset( $intent->amount ) ? (int) $intent->amount : 0;
			$intent_currency   = isset( $intent->currency ) ? strtoupper( (string) $intent->currency ) : '';
			$expected_currency = strtoupper( (string) $currency );

			$form_id_meta = isset( $intent->metadata->forminator_form_id ) ? (string) $intent->metadata->forminator_form_id : '';

			$expected_form_id = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';

			if (
				( $form_id_meta !== $expected_form_id )
				|| ( $expected_amount !== $intent_amount )
				|| ( $expected_currency !== $intent_currency )
			) {
				return new WP_Error( 'forminator_stripe_error', esc_html__( 'Payment Intent ID is not valid', 'forminator' ) );
			}

			// An unconfirmed intent is checked again after confirmation.
			$payment_state = $this->validate_intent_payment( $intent, $field );
			if ( is_wp_error( $payment_state ) ) {
				return $payment_state;
			}

			$entry_data['mode']     = $mode;
			$entry_data['currency'] = $currency;
			$entry_data['amount']   = $charge_amount;
			if ( ! empty( $this->payment_plan ) ) {
				$entry_data['product_name'] = $this->payment_plan['plan_name'];
				$entry_data['payment_type'] = $this->payment_method( $this->payment_plan['payment_method'] );
				$entry_data['quantity']     = $this->payment_plan['quantity'];
			}

			$entry_data['transaction_link'] = self::get_transanction_link( $mode, $intent->id );
			$entry_data['transaction_id']   = $intent->id;
		} catch ( Exception $e ) {
			$entry_data['error'] = $e->getMessage();
		}

		/**
		 * Filter stripe entry data that will be stored
		 *
		 * @since 1.7
		 *
		 * @param array                        $entry_data Entry data.
		 * @param array                        $field Field properties.
		 * @param Forminator_Form_Model $module_object Forminator_Form_Model.
		 * @param array                        $submitted_data Submitted data.
		 * @param array                        $field_data_array current entry meta.
		 *
		 * @return array
		 */
		$entry_data = apply_filters( 'forminator_field_stripe_process_to_entry_data', $entry_data, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data, Forminator_CForm_Front_Action::$info['field_data_array'] );

		return $entry_data;
	}

	/**
	 * Process Checkout Session entry data
	 *
	 * @since 1.56
	 *
	 * @param array                            $field Field.
	 * @param Forminator_Form_Entry_Model|null $entry Entry model.
	 *
	 * @throws Exception When the Checkout Session cannot be retrieved or validated.
	 * @return array|WP_Error
	 */
	private function process_checkout_session_to_entry_data( $field, $entry = null ) {
		// Check if the entry is valid and pending.
		if ( empty( $entry ) || 'pending' !== $entry->status ) {
			throw new Exception( esc_html__( 'Entry object is not valid.', 'forminator' ) );
		}

		$entry_data = array(
			'mode'             => '',
			'product_name'     => '',
			'payment_type'     => '',
			'amount'           => '',
			'quantity'         => '',
			'currency'         => '',
			'transaction_id'   => '',
			'transaction_link' => '',
		);

		$mode     = self::get_property( 'mode', $field, 'test' );
		$currency = self::get_property( 'currency', $field, $this->get_default_currency() );

		try {
			$session = $this->get_checkout_session( $field );

			if ( is_wp_error( $session ) ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'        => 'checkout_session_entry_retrieve_failed',
						'error_message' => $session->get_error_message(),
						'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'       => ! empty( Forminator_Front_Action::$module_object->id ) ? Forminator_Front_Action::$module_object->id : '',
						'field_id'      => self::get_property( 'element_id', $field, '' ),
					)
				);
				throw new Exception( $session->get_error_message() );
			} elseif ( ! is_object( $session ) ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'        => 'checkout_session_entry_invalid_object',
						'error_message' => esc_html__( 'Checkout Session object is not valid.', 'forminator' ),
						'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'       => ! empty( Forminator_Front_Action::$module_object->id ) ? Forminator_Front_Action::$module_object->id : '',
						'field_id'      => self::get_property( 'element_id', $field, '' ),
						'session'       => $session,
					)
				);
				throw new Exception( esc_html__( 'Checkout Session object is not valid.', 'forminator' ) );
			}

			if ( empty( $session->id ) ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'        => 'checkout_session_entry_missing_id',
						'error_message' => esc_html__( 'Checkout Session ID is not valid!', 'forminator' ),
						'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'       => ! empty( Forminator_Front_Action::$module_object->id ) ? Forminator_Front_Action::$module_object->id : '',
						'field_id'      => self::get_property( 'element_id', $field, '' ),
						'session'       => $session,
					)
				);
				throw new Exception( esc_html__( 'Checkout Session ID is not valid!', 'forminator' ) );
			}

			$charge_amount     = $this->get_payment_amount( $field );
			$expected_amount   = $this->calculate_amount( $charge_amount, $currency );
			$expected_currency = strtoupper( (string) $currency );
			$form_id_meta      = isset( $session->client_reference_id ) ? (string) $session->client_reference_id : '';
			$expected_form_id  = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';

			if (
				( $form_id_meta !== $expected_form_id )
				|| ! self::does_checkout_session_match_expected_pricing( $session, $expected_amount, $expected_currency )

			) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'            => 'checkout_session_entry_validation_failed',
						'error_message'     => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
						'payment_id'        => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'           => $expected_form_id,
						'field_id'          => self::get_property( 'element_id', $field, '' ),
						'session_id'        => $session->id,
						'client_reference'  => $form_id_meta,
						'expected_form_id'  => $expected_form_id,
						'expected_amount'   => $expected_amount,
						'expected_currency' => $expected_currency,
						'payment_status'    => $session->payment_status ?? '',
					)
				);

				return new WP_Error( 'forminator_stripe_error', esc_html__( 'Checkout Session ID is not valid', 'forminator' ) );
			}

			// If the entry is still pending, return the session data for later processing.
			if ( 'unpaid' === ( $session->payment_status ?? '' ) ) {
				// Claim the Checkout Session before the entry is created so one payment can
				// only ever produce one entry, even for concurrent submissions.
				if ( ! self::is_valid_checkout_session( $session->id ) ) {
					forminator_maybe_log(
						__METHOD__,
						array(
							'action'        => 'checkout_session_entry_already_handled',
							'error_message' => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
							'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
							'form_id'       => $expected_form_id,
							'field_id'      => self::get_property( 'element_id', $field, '' ),
							'session_id'    => $session->id,
						)
					);

					return new WP_Error(
						'forminator_stripe_checkout_session_already_handled',
						esc_html__( 'Checkout Session ID is not valid', 'forminator' )
					);
				}

				$entry_data = array(
					'status'         => $session->payment_status ?? '',
					'mode'           => $mode,
					'currency'       => $currency,
					'amount'         => $charge_amount,
					'transaction_id' => '',
				);
				if ( ! empty( $this->payment_plan ) ) {
					$entry_data['product_name'] = $this->payment_plan['plan_name'];
					$entry_data['payment_type'] = $this->payment_method( $this->payment_plan['payment_method'] );
					$entry_data['quantity']     = $this->payment_plan['quantity'];
				}

				// Resolve merge tags while form data is available; applied to PI/Charge after confirm.
				$this->store_checkout_payment_description( $entry, $field );

				// Add the Checkout Session ID to the entry meta so that it can be used in the webhook handler.
				$entry->add_meta( '_stripe_checkout_session_id', $session->id );

				// If the session ID already exists, we should not process it again.
				// This is a safeguard against duplicate entries being created for the same session ID.
				if ( $entry->is_stripe_checkout_session_id_duplicate( $session->id, $entry->entry_id ) ) {
					forminator_maybe_log(
						__METHOD__,
						array(
							'action'     => 'is_stripe_checkout_session_id_duplicate',
							'session_id' => $session->id,
							'error'      => esc_html__( 'Duplicate entry.', 'forminator' ),
						)
					);
					// If the session ID already exists, we should not process it again.
					return new WP_Error( 'forminator_stripe_duplicate_entry', esc_html__( 'Checkout Session ID is not valid', 'forminator' ) );
				}

				return apply_filters( 'forminator_field_stripe_process_to_entry_data', $entry_data, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data, Forminator_CForm_Front_Action::$info['field_data_array'] );
			} else {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'         => 'checkout_session_entry_validation_failed',
						'error_message'  => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
						'payment_id'     => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'        => $expected_form_id,
						'field_id'       => self::get_property( 'element_id', $field, '' ),
						'session_id'     => $session->id,
						'payment_status' => $session->payment_status ?? '',
					)
				);
				return new WP_Error( 'forminator_stripe_error', esc_html__( 'Checkout Session ID is not valid', 'forminator' ) );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'forminator_stripe_error', esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Get the payment owner of the current browser.
	 *
	 * Hash of a random token in an HttpOnly cookie, which survives Stripe redirects but not a shared link.
	 *
	 * @param bool $issue Issue the cookie when missing, refresh it otherwise.
	 * @return string Empty when the browser has no token.
	 */
	public static function get_payment_owner( $issue = false ): string {
		if ( '' === self::$payment_owner_token ) {
			$token = isset( $_COOKIE[ self::PAYMENT_OWNER_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::PAYMENT_OWNER_COOKIE ] ) ) : '';
			if ( preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
				self::$payment_owner_token = $token;
			}
		}

		if ( $issue && ! self::$payment_owner_cookie_sent ) {
			if ( '' === self::$payment_owner_token ) {
				self::$payment_owner_token = wp_generate_password( 32, false, false );
			}

			// Refresh so it outlives the markers using it.
			if ( ! headers_sent() ) {
				self::$payment_owner_cookie_sent = true;
				setcookie(
					self::PAYMENT_OWNER_COOKIE,
					self::$payment_owner_token,
					array(
						'expires'  => time() + DAY_IN_SECONDS,
						'path'     => COOKIEPATH,
						'domain'   => (string) COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}

		return '' === self::$payment_owner_token ? '' : hash( 'sha256', self::$payment_owner_token );
	}

	/**
	 * Check that the current browser started the payment.
	 *
	 * @param mixed $owner Stored payment owner.
	 * @return bool
	 */
	public static function is_payment_owner( $owner ): bool {
		$current = self::get_payment_owner();

		return is_string( $owner ) && '' !== $owner && '' !== $current && hash_equals( $owner, $current );
	}

	/**
	 * Check if payment intent is valid
	 *
	 * @param string $intent_id Payment Intent ID.
	 *
	 * @return bool
	 */
	private static function is_valid_payment_intent( $intent_id ): bool {
		if ( empty( $intent_id ) || self::is_consumed_payment_intent( $intent_id ) ) {
			return false;
		}

		if ( ! self::claim_pending_payment_intent( $intent_id ) ) {
			return false;
		}

		self::$claimed_payment_intents[ $intent_id ] = false;

		// The entry and its payment meta are on their way to storage, the payment is spent.
		add_action(
			'forminator_custom_form_submit_before_set_fields',
			function () use ( $intent_id ) {
				if ( isset( self::$claimed_payment_intents[ $intent_id ] ) ) {
					self::$claimed_payment_intents[ $intent_id ] = true;
				}
			}
		);

		add_action(
			'forminator_after_handle_form',
			function () use ( $intent_id ) {
				self::settle_payment_intent( $intent_id );
			}
		);

		// Covers submissions that end before forminator_after_handle_form is reached.
		add_action(
			'shutdown',
			function () use ( $intent_id ) {
				self::settle_payment_intent( $intent_id );
			}
		);

		return true;
	}

	/**
	 * Settle a PaymentIntent claimed by this request.
	 *
	 * The PaymentIntent is only recorded as consumed once its entry reached storage. When
	 * the submission failed before that, the pending marker is handed back so the customer
	 * can retry the payment they already made.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return void
	 */
	private static function settle_payment_intent( $intent_id ) {
		if ( ! isset( self::$claimed_payment_intents[ $intent_id ] ) ) {
			return;
		}

		$entry_stored = self::$claimed_payment_intents[ $intent_id ];
		unset( self::$claimed_payment_intents[ $intent_id ] );

		if ( ! $entry_stored ) {
			delete_option( self::get_payment_intent_claim_held_option_key( $intent_id ) );
			self::set_pending_payment_intent( $intent_id, true );

			return;
		}

		self::mark_payment_intent_consumed( $intent_id );
		self::remove_pending_payment_intent( $intent_id );
	}

	/**
	 * Claim the pending PaymentIntent marker for the current request.
	 *
	 * The delete_option() call only reports success for the request that actually removed
	 * the row, so concurrent submissions of the same PaymentIntent cannot all claim it.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return bool
	 */
	private static function claim_pending_payment_intent( $intent_id ): bool {
		if ( empty( $intent_id ) || ! self::is_payment_intent_owner( $intent_id ) ) {
			return false;
		}

		if ( ! delete_option( self::get_payment_intent_option_key( $intent_id ) ) ) {
			return false;
		}

		return self::hold_payment_intent_claim( $intent_id );
	}

	/**
	 * Check that the current browser created the pending PaymentIntent.
	 *
	 * Display intents and markers without an owner never match.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return bool
	 */
	private static function is_payment_intent_owner( $intent_id ): bool {
		$marker = get_option( self::get_payment_intent_option_key( $intent_id ), false );

		return is_array( $marker ) && self::is_payment_owner( $marker['owner'] ?? '' );
	}

	/**
	 * Take the single use lock for a PaymentIntent claim.
	 *
	 * The unique index on option_name makes this insert fail for anyone racing us, so only
	 * one request can ever hold the claim for a given PaymentIntent.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return bool
	 */
	private static function hold_payment_intent_claim( $intent_id ): bool {
		global $wpdb;

		$option_name = self::get_payment_intent_claim_held_option_key( $intent_id );

		// Losing the race is expected, so keep the duplicate key error out of the log.
		$suppressed = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must fail on a duplicate key, which the options API cannot express.
		$inserted = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => (string) time(),
				'autoload'     => 'off',
			),
			array( '%s', '%s', '%s' )
		);

		$wpdb->suppress_errors( $suppressed );

		if ( ! $inserted ) {
			return false;
		}

		wp_cache_delete( $option_name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $option_name ] ) ) {
			unset( $notoptions[ $option_name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return true;
	}

	/**
	 * Build the option key marking a held PaymentIntent claim.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return string
	 */
	private static function get_payment_intent_claim_held_option_key( $intent_id ): string {
		return self::PAYMENT_INTENT_CLAIM_HELD_OPTION_PREFIX . md5( $intent_id );
	}

	/**
	 * Store a pending PaymentIntent marker.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @param bool   $force     Re-arm even while a claim is held, used by settle failure.
	 * @return void
	 */
	private static function set_pending_payment_intent( $intent_id, $force = false ) {
		if ( empty( $intent_id ) || self::is_consumed_payment_intent( $intent_id ) ) {
			return;
		}

		// Never re-arm an intent a request is still holding, only settle failure may force it.
		if ( ! $force && false !== get_option( self::get_payment_intent_claim_held_option_key( $intent_id ), false ) ) {
			return;
		}

		update_option(
			self::get_payment_intent_option_key( $intent_id ),
			array(
				'created_at' => time(),
				// Only the paying browser may claim or update it.
				'owner'      => self::get_payment_owner( true ),
			),
			false
		);

		$payment_intents               = get_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, array() );
		$payment_intents               = is_array( $payment_intents ) ? $payment_intents : array();
		$payment_intents[ $intent_id ] = time();
		update_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, $payment_intents, false );
	}

	/**
	 * Remove a PaymentIntent from local tracking once it produced an entry.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return void
	 */
	private static function remove_pending_payment_intent( $intent_id ) {
		if ( empty( $intent_id ) ) {
			return;
		}

		delete_option( self::get_payment_intent_option_key( $intent_id ) );
		delete_option( self::get_payment_intent_claim_held_option_key( $intent_id ) );

		$payment_intents = get_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, array() );
		if ( is_array( $payment_intents ) && isset( $payment_intents[ $intent_id ] ) ) {
			unset( $payment_intents[ $intent_id ] );
			update_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, $payment_intents, false );
		}
	}

	/**
	 * Check whether a PaymentIntent ID already produced an entry.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return bool
	 */
	private static function is_consumed_payment_intent( $intent_id ): bool {
		if ( empty( $intent_id ) ) {
			return false;
		}

		return false !== get_option( self::get_consumed_payment_intent_option_key( $intent_id ), false );
	}

	/**
	 * Record a PaymentIntent ID as consumed so it can never be replayed.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return void
	 */
	private static function mark_payment_intent_consumed( $intent_id ) {
		if ( empty( $intent_id ) ) {
			return;
		}

		update_option(
			self::get_consumed_payment_intent_option_key( $intent_id ),
			array(
				'consumed_at' => time(),
			),
			false
		);

		$consumed_intents               = get_option( self::CONSUMED_PAYMENT_INTENT_CLEANUP_OPTION_KEY, array() );
		$consumed_intents               = is_array( $consumed_intents ) ? $consumed_intents : array();
		$consumed_intents[ $intent_id ] = time();
		update_option( self::CONSUMED_PAYMENT_INTENT_CLEANUP_OPTION_KEY, $consumed_intents, false );
	}

	/**
	 * Build the option key for a pending PaymentIntent ID.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return string
	 */
	private static function get_payment_intent_option_key( $intent_id ): string {
		return self::PAYMENT_INTENT_OPTION_PREFIX . md5( $intent_id );
	}

	/**
	 * Build the option key for a consumed PaymentIntent ID.
	 *
	 * @since 1.57.2
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return string
	 */
	private static function get_consumed_payment_intent_option_key( $intent_id ): string {
		return self::CONSUMED_PAYMENT_INTENT_OPTION_PREFIX . md5( $intent_id );
	}

	/**
	 * Check if checkout session is valid
	 *
	 * The pending marker is claimed here, before the entry is created, so two concurrent
	 * submissions of the same Checkout Session cannot both be accepted. The session is
	 * recorded as consumed later, in settle_checkout_session(), once the entry reaches
	 * storage.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	public static function is_valid_checkout_session( $session_id ): bool {
		if ( empty( $session_id ) || self::is_consumed_checkout_session( $session_id ) ) {
			return false;
		}

		if ( ! self::is_claimable_checkout_session( $session_id ) ) {
			return false;
		}

		self::$claimed_checkout_sessions[ $session_id ] = false;

		// The entry and its payment meta are on their way to storage, the payment is spent.
		add_action(
			'forminator_custom_form_submit_before_set_fields',
			function () use ( $session_id ) {
				if ( isset( self::$claimed_checkout_sessions[ $session_id ] ) ) {
					self::$claimed_checkout_sessions[ $session_id ] = true;
				}
			}
		);

		add_action(
			'forminator_after_handle_form',
			function () use ( $session_id ) {
				self::settle_checkout_session( $session_id );
			}
		);

		// Covers submissions that end before forminator_after_handle_form is reached.
		add_action(
			'shutdown',
			function () use ( $session_id ) {
				self::settle_checkout_session( $session_id );
			}
		);

		return true;
	}

	/**
	 * Settle a Checkout Session claimed by this request.
	 *
	 * The session is only recorded as consumed once its entry reached storage. When the
	 * submission failed before that, the pending marker is handed back so the customer can
	 * retry the payment they already made.
	 *
	 * @since 1.57.2
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return void
	 */
	private static function settle_checkout_session( $session_id ) {
		if ( ! isset( self::$claimed_checkout_sessions[ $session_id ] ) ) {
			return;
		}

		$entry_stored = self::$claimed_checkout_sessions[ $session_id ];
		unset( self::$claimed_checkout_sessions[ $session_id ] );

		if ( ! $entry_stored ) {
			self::restore_pending_checkout_session( $session_id );

			return;
		}

		self::mark_checkout_session_consumed( $session_id );
	}

	/**
	 * Check whether a Checkout Session may still be claimed for entry creation.
	 *
	 * Unlike is_valid_checkout_session() this never consumes the marker, so a payment
	 * that has not settled yet can still be retried.
	 *
	 * @since 1.57.2
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	private static function is_claimable_checkout_session( $session_id ): bool {
		return self::has_pending_checkout_session( $session_id ) && ! self::is_consumed_checkout_session( $session_id );
	}

	/**
	 * Check whether a Checkout Session ID already produced an entry.
	 *
	 * @since 1.57.2
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	public static function is_consumed_checkout_session( $session_id ): bool {
		if ( empty( $session_id ) ) {
			return false;
		}

		return false !== get_option( self::get_consumed_checkout_session_option_key( $session_id ), false );
	}

	/**
	 * Record a Checkout Session ID as consumed so it can never be replayed.
	 *
	 * @since 1.57.2
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return void
	 */
	private static function mark_checkout_session_consumed( $session_id ) {
		if ( empty( $session_id ) ) {
			return;
		}

		update_option(
			self::get_consumed_checkout_session_option_key( $session_id ),
			array(
				'consumed_at' => time(),
			),
			false
		);

		$consumed_sessions                = get_option( self::CONSUMED_CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );
		$consumed_sessions                = is_array( $consumed_sessions ) ? $consumed_sessions : array();
		$consumed_sessions[ $session_id ] = time();
		update_option( self::CONSUMED_CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $consumed_sessions, false );
	}

	/**
	 * Build the option key for a consumed Checkout Session ID.
	 *
	 * @since 1.57.2
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return string
	 */
	private static function get_consumed_checkout_session_option_key( $session_id ): string {
		return self::CONSUMED_CHECKOUT_SESSION_OPTION_PREFIX . md5( $session_id );
	}

	/**
	 * Check whether a pending Checkout Session ID is still tracked locally.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	public static function has_pending_checkout_session( $session_id ): bool {
		return self::has_pending_checkout_session_option( $session_id );
	}

	/**
	 * Store an individual pending Checkout Session marker.
	 *
	 * Each Checkout Session uses its own option so multiple forms cannot
	 * overwrite each other's pending markers on the same page.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return void
	 */
	private static function set_pending_checkout_session( $session_id ) {
		if ( empty( $session_id ) ) {
			return;
		}

		update_option(
			self::get_checkout_session_option_key( $session_id ),
			array(
				'created_at' => time(),
				// Bind the creating browser, so no other can claim it.
				'owner'      => self::get_payment_owner( true ),
			),
			false
		);

		$checkout_sessions                = get_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );
		$checkout_sessions                = is_array( $checkout_sessions ) ? $checkout_sessions : array();
		$checkout_sessions[ $session_id ] = time();
		update_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $checkout_sessions, false );
	}

	/**
	 * Update a pending Checkout Session option with the entry ID and form ID.
	 *
	 * @since 1.58
	 *
	 * @param string                      $session_id Checkout Session ID.
	 * @param Forminator_Form_Entry_Model $entry Form entry model.
	 * @return void
	 */
	public static function update_pending_checkout_session( $session_id, $entry ) {
		if ( empty( $session_id ) ) {
			return;
		}

		$option_data = get_option( self::get_checkout_session_option_key( $session_id ), false );
		if ( ! is_array( $option_data ) ) {
			return;
		}

		// Only the owning browser may attach an entry.
		if ( ! self::is_payment_owner( $option_data['owner'] ?? '' ) ) {
			return;
		}

		$option_data['owner']      = self::get_payment_owner( true );
		$option_data['entry_id']   = $entry->entry_id;
		$option_data['form_id']    = $entry->form_id;
		$option_data['updated_at'] = time();

		update_option(
			self::get_checkout_session_option_key( $session_id ),
			$option_data,
			false
		);
	}

	/**
	 * Get pending Checkout Session data by session ID.
	 *
	 * @since 1.58
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return array|false Option data or false if not found.
	 */
	public static function get_pending_checkout_session_data( $session_id ) {
		if ( empty( $session_id ) ) {
			return false;
		}

		return get_option( self::get_checkout_session_option_key( $session_id ), false );
	}

	/**
	 * Check whether a pending Checkout Session can be restored.
	 *
	 * @since 1.58
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	public static function can_restore_pending_checkout_session( $session_id ) {
		$session_data = self::get_pending_checkout_session_data( $session_id );
		if ( empty( $session_data['entry_id'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Restore a missing pending Checkout Session marker after Stripe validates the returned session.
	 *
	 * This covers redirect methods that return after local pending state was lost,
	 * while still allowing the caller to verify the session belongs to the form first.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return void
	 */
	public static function restore_pending_checkout_session( $session_id ) {
		if ( empty( $session_id ) || self::is_consumed_checkout_session( $session_id ) ) {
			return;
		}

		self::set_pending_checkout_session( $session_id );
	}

	/**
	 * Check whether an individual pending Checkout Session marker exists.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	private static function has_pending_checkout_session_option( $session_id ): bool {
		if ( empty( $session_id ) ) {
			return false;
		}
		$session_option = get_option( self::get_checkout_session_option_key( $session_id ), false );

		// If the option is missing or already has an entry ID or form ID, it is no longer considered pending.
		if ( false === $session_option || isset( $session_option['entry_id'] ) || isset( $session_option['form_id'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the option key for a Checkout Session ID.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return string
	 */
	private static function get_checkout_session_option_key( $session_id ): string {
		return self::CHECKOUT_SESSION_OPTION_PREFIX . md5( $session_id );
	}

	/**
	 * Remove a pending Checkout Session ID from local tracking.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return void
	 */
	public static function remove_pending_checkout_session( $session_id ) {
		if ( empty( $session_id ) ) {
			return;
		}

		delete_option( self::get_checkout_session_option_key( $session_id ) );

		$checkout_sessions = get_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );
		if ( is_array( $checkout_sessions ) && isset( $checkout_sessions[ $session_id ] ) ) {
			unset( $checkout_sessions[ $session_id ] );
			update_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $checkout_sessions, false );
		}
	}

	/**
	 * Remove abandoned pending Checkout Session markers.
	 *
	 * @since 1.56
	 *
	 * @return void
	 */
	public function cleanup_checkout_sessions() {
		self::cleanup_consumed_checkout_sessions();
		self::cleanup_payment_intents();

		$checkout_sessions = get_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );

		if ( empty( $checkout_sessions ) || ! is_array( $checkout_sessions ) ) {
			return;
		}

		/**
		 * Filter how long abandoned Stripe Checkout Session markers are retained.
		 *
		 * @since 1.56
		 *
		 * @param int $retention Retention time in seconds.
		 */
		$retention = apply_filters( 'forminator_stripe_checkout_session_retention', DAY_IN_SECONDS );

		foreach ( $checkout_sessions as $session_id => $created_at ) {
			if ( ! is_numeric( $created_at ) || time() - (int) $created_at > $retention ) {
				unset( $checkout_sessions[ $session_id ] );
				delete_option( self::get_checkout_session_option_key( $session_id ) );
			}
		}

		update_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $checkout_sessions, false );
	}

	/**
	 * Remove abandoned pending PaymentIntent markers and expired consumed PaymentIntent IDs.
	 *
	 * @since 1.57.2
	 *
	 * @return void
	 */
	private static function cleanup_payment_intents() {
		// Old intent cache shared between browsers.
		delete_option( 'forminator_stripe_payment_intents' );

		$payment_intents = get_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, array() );

		if ( ! empty( $payment_intents ) && is_array( $payment_intents ) ) {
			/**
			 * Filter how long abandoned Stripe PaymentIntent markers are retained.
			 *
			 * @since 1.57.2
			 *
			 * @param int $retention Retention time in seconds.
			 */
			$pending_retention = apply_filters( 'forminator_stripe_payment_intent_retention', DAY_IN_SECONDS );

			foreach ( $payment_intents as $intent_id => $created_at ) {
				if ( ! is_numeric( $created_at ) || time() - (int) $created_at > $pending_retention ) {
					unset( $payment_intents[ $intent_id ] );
					delete_option( self::get_payment_intent_option_key( $intent_id ) );
					// A request that died mid claim leaves its claim-held flag behind.
					delete_option( self::get_payment_intent_claim_held_option_key( $intent_id ) );
				}
			}

			update_option( self::PAYMENT_INTENT_CLEANUP_OPTION_KEY, $payment_intents, false );
		}

		$consumed_intents = get_option( self::CONSUMED_PAYMENT_INTENT_CLEANUP_OPTION_KEY, array() );

		if ( empty( $consumed_intents ) || ! is_array( $consumed_intents ) ) {
			return;
		}

		/**
		 * Filter how long consumed Stripe PaymentIntent IDs are retained.
		 *
		 * @since 1.57.2
		 *
		 * @param int $retention Retention time in seconds.
		 */
		$consumed_retention = apply_filters( 'forminator_stripe_consumed_payment_intent_retention', 6 * MONTH_IN_SECONDS );

		foreach ( $consumed_intents as $intent_id => $consumed_at ) {
			if ( ! is_numeric( $consumed_at ) || time() - (int) $consumed_at > $consumed_retention ) {
				unset( $consumed_intents[ $intent_id ] );
				delete_option( self::get_consumed_payment_intent_option_key( $intent_id ) );
			}
		}

		update_option( self::CONSUMED_PAYMENT_INTENT_CLEANUP_OPTION_KEY, $consumed_intents, false );
	}

	/**
	 * Remove expired entries from the consumed Checkout Session ledger.
	 *
	 * Consumed IDs are retained far longer than pending markers because a completed
	 * Checkout Session stays replayable on Stripe's side.
	 *
	 * @since 1.57.2
	 *
	 * @return void
	 */
	private static function cleanup_consumed_checkout_sessions() {
		$consumed_sessions = get_option( self::CONSUMED_CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );

		if ( empty( $consumed_sessions ) || ! is_array( $consumed_sessions ) ) {
			return;
		}

		/**
		 * Filter how long consumed Stripe Checkout Session IDs are retained.
		 *
		 * @since 1.57.2
		 *
		 * @param int $retention Retention time in seconds.
		 */
		$retention = apply_filters( 'forminator_stripe_consumed_checkout_session_retention', 6 * MONTH_IN_SECONDS );

		foreach ( $consumed_sessions as $session_id => $consumed_at ) {
			if ( ! is_numeric( $consumed_at ) || time() - (int) $consumed_at > $retention ) {
				unset( $consumed_sessions[ $session_id ] );
				delete_option( self::get_consumed_checkout_session_option_key( $session_id ) );
			}
		}

		update_option( self::CONSUMED_CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $consumed_sessions, false );
	}

	/**
	 * Determine whether a Checkout Session can still be recovered for retry.
	 *
	 * @since 1.56
	 *
	 * @param mixed $session Checkout Session object.
	 * @param bool  $allow_completed_paid Whether to allow a completed paid session that is still tracked locally.
	 * @return bool
	 */
	public static function is_recoverable_checkout_session( $session, bool $allow_completed_paid = false ): bool {
		if ( ! is_object( $session ) || empty( $session->id ) ) {
			return false;
		}

		$session_status = isset( $session->status ) ? (string) $session->status : '';
		$payment_status = isset( $session->payment_status ) ? (string) $session->payment_status : '';

		if ( 'expired' === $session_status ) {
			return false;
		}

		if ( 'complete' === $session_status ) {
			// The asynchronous payment may have complete session status in an unpaid payment status.
			if ( $allow_completed_paid && 'unpaid' === $payment_status ) {
				return true;
			}
			return $allow_completed_paid && 'paid' === $payment_status;
		}

		if ( 'unpaid' === $payment_status ) {
			return false;
		}

		return true;
	}

	/**
	 * Make linkify transaction_id
	 *
	 * @param string $transaction_id Transaction Id.
	 * @param array  $meta_value Meta value.
	 *
	 * @return string
	 */
	public static function linkify_transaction_id( $transaction_id, $meta_value ) {
		$transaction_link = esc_html( $transaction_id );
		if ( isset( $meta_value['transaction_link'] ) && ! empty( $meta_value['transaction_link'] ) ) {
			$url              = $meta_value['transaction_link'];
			$transaction_link = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $transaction_id ) . '">' . esc_html( $transaction_id ) . '</a>';
		}

		/**
		 * Filter link to Stripe transaction id
		 *
		 * @since 1.7
		 *
		 * @param string $transaction_link
		 * @param string $transaction_id
		 * @param array  $meta_value
		 *
		 * @return string
		 */
		$transaction_link = apply_filters( 'forminator_field_stripe_linkify_transaction_id', $transaction_link, $transaction_id, $meta_value );

		return $transaction_link;
	}

	/**
	 * Retrieve PaymentIntent object
	 *
	 * @param array $field Field.
	 *
	 * @return mixed object|string
	 * @throws Exception When there is an error.
	 */
	public function get_paymentIntent( $field ) {
		if ( $this->is_checkout_session( $field ) ) {
			return $this->get_checkout_session( $field );
		}

		$mode     = self::get_property( 'mode', $field, 'test' );
		$currency = self::get_property( 'currency', $field, $this->get_default_currency() );

		// Check Stripe key.
		$key = $this->get_secret_key( 'test' !== $mode );

		// Set Stripe key.
		\Forminator\Stripe\Stripe::setApiKey( $key );

		Forminator_Gateway_Stripe::set_stripe_app_info();

		try {
			// Makue sure payment ID exist.
			if ( empty( Forminator_CForm_Front_Action::$prepared_data['paymentid'] ) ) {
				throw new Exception( esc_html__( 'Stripe Payment ID does not exist.', 'forminator' ) );
			}

			// Check payment amount.
			$intent = \Forminator\Stripe\PaymentIntent::retrieve( Forminator_CForm_Front_Action::$prepared_data['paymentid'] );

			// Never confirm or return the secret of an intent belonging to another form.
			if ( ! self::is_payment_intent_of_current_form( $intent ) ) {
				throw new Exception( esc_html__( 'Payment Intent ID is not valid', 'forminator' ) );
			}

			return $intent;
		} catch ( Exception $e ) {
			return $this->get_error( $e );
		}
	}

	/**
	 * Retrieve Checkout Session object
	 *
	 * @since 1.56
	 *
	 * @param array $field Field.
	 * @return mixed
	 */
	public function get_checkout_session( $field ) {
		return $this->get_checkout_session_by_id( Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '', $field );
	}

	/**
	 * Retrieve Checkout Session object by id.
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @param array  $field Field.
	 * @throws Exception When the Checkout Session cannot be retrieved.
	 * @return mixed
	 */
	public function get_checkout_session_by_id( $session_id, $field ) {
		$mode = self::get_property( 'mode', $field, 'test' );
		$key  = $this->get_secret_key( 'test' !== $mode );

		\Forminator\Stripe\Stripe::setApiKey( $key );
		Forminator_Gateway_Stripe::set_stripe_app_info();

		try {
			if ( empty( $session_id ) ) {
				throw new Exception( esc_html__( 'Stripe Checkout Session ID does not exist.', 'forminator' ) );
			}

			$session = \Forminator\Stripe\Checkout\Session::retrieve(
				$session_id,
				self::get_checkout_session_request_options()
			);

			return $session;
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'          => 'Checkout\\Session::retrieve',
					'error_class'     => get_class( $e ),
					'error_message'   => $e->getMessage(),
					'error_code'      => $e->getCode(),
					'session_id'      => $session_id,
					'request_options' => self::get_checkout_session_request_options(),
				)
			);

			return $this->get_error( $e );
		}
	}

	/**
	 * Request options for Checkout Session API calls.
	 *
	 * @since 1.56
	 *
	 * @return array
	 */
	public static function get_checkout_session_request_options(): array {
		return array(
			'stripe_version' => self::CHECKOUT_SESSION_STRIPE_VERSION,
		);
	}

	/**
	 * Retrieve PaymentMethod object
	 *
	 * @since 1.15
	 *
	 * @param array $field Field.
	 * @param array $submitted_data Submitted data.
	 *
	 * @return mixed object|string
	 * @throws Exception When there is an error.
	 */
	public function get_paymentMethod( $field, $submitted_data ) {
		$mode     = self::get_property( 'mode', $field, 'test' );
		$currency = self::get_property( 'currency', $field, $this->get_default_currency() );

		// Check Stripe key.
		$key = $this->get_secret_key( 'test' !== $mode );

		// Set Stripe key.
		\Forminator\Stripe\Stripe::setApiKey( $key );

		Forminator_Gateway_Stripe::set_stripe_app_info();

		try {
			// Makue sure payment ID exist.
			if ( ! isset( $submitted_data['paymentid'] ) ) {
				throw new Exception( esc_html__( 'Stripe Payment ID does not exist.', 'forminator' ) );
			}

			// Check payment amount.
			$intent = \Forminator\Stripe\PaymentMethod::retrieve( $submitted_data['paymentmethod'] );

			return $intent;
		} catch ( Exception $e ) {
			return $this->get_error( $e );
		}
	}

	/**
	 * Get Stripe return URL to pass it in API calls
	 *
	 * @param bool $is_checkout_session Whether the return URL is for a checkout session.
	 * @return string
	 */
	public static function get_return_url( $is_checkout_session = false ) {
		$return_url = forminator_get_current_url();

		if ( empty( $return_url ) && ! empty( Forminator_CForm_Front_Action::$prepared_data['current_url'] ) ) {
			$return_url = esc_url_raw( wp_unslash( Forminator_CForm_Front_Action::$prepared_data['current_url'] ) );
		}

		if ( empty( $return_url ) && ! empty( Forminator_CForm_Front_Action::$prepared_data['page_id'] ) ) {
			$page_id = absint( Forminator_CForm_Front_Action::$prepared_data['page_id'] );
			if ( $page_id > 0 ) {
				$return_url = get_permalink( $page_id );
			}
		}

		if ( empty( $return_url ) ) {
			$return_url = home_url( '/' );
		}

		if ( true === $is_checkout_session ) {
			// Add the Stripe Checkout Session ID to the return URL for checkout sessions.
			// This allows the form to retrieve the session ID after the user is redirected back from Stripe.
			$return_url = add_query_arg( 'stripe_session_id', '{CHECKOUT_SESSION_ID}', $return_url );
		}

		return apply_filters( 'forminator_stripe_return_url', $return_url );
	}

	/**
	 * Confirm paymentIntent
	 *
	 * @param mixed $intent Payment Intent.
	 *
	 * @since 1.14.9
	 *
	 * @return object|WP_Error
	 */
	public function confirm_paymentIntent( $intent ) {
		try {
			return $intent->confirm( array( 'return_url' => self::get_return_url() ) );
		} catch ( Exception $e ) {
			return $this->get_error( $e );
		}
	}

	/**
	 * Get the exception error and return WP_Error
	 *
	 * @param mixed $e Exception.
	 *
	 * @since 1.14.9
	 *
	 * @return WP_Error
	 */
	private function get_error( $e ) {
		$code = $e->getCode();

		if ( is_int( $code ) ) {
			$code = ( 0 === $code ) ? 'zero' : $code;

			return new WP_Error( $code, $e->getMessage() );
		} else {
			return new WP_Error( $e->getError()->code, $e->getMessage() );
		}
	}

	/**
	 * Get ALL fields that payment amount depends on
	 *
	 * @param array $field_settings Field settings.
	 * @return array
	 */
	public function get_amount_dependent_fields_all( $field_settings ) {
		$depend_field = self::get_conditions_dependent_fields( $field_settings );

		$plans = self::get_property( 'payments', $field_settings, array() );
		foreach ( $plans as $plan ) {
			$plan_depends = self::get_plan_dependent_fields( $plan );
			$depend_field = array_merge( $depend_field, $plan_depends );
		}

		return array_values( array_unique( $depend_field ) );
	}

	/**
	 * Get form field IDs used by one or more merge-tag values.
	 *
	 * @since 1.56
	 *
	 * @param mixed $values Merge-tag value or metadata config.
	 * @return array
	 */
	public function get_merge_tag_dependent_fields( $values ) {
		$depend_field = array();

		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$value = $value['value'] ?? '';
			}

			if ( empty( $value ) || ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;
			if ( false === strpos( $value, '{' ) ) {
				$depend_field[] = forminator_clear_field_id( $value );
				continue;
			}

			if ( preg_match_all( '/\{([^}]+)\}/', $value, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					$depend_field[] = forminator_clear_field_id( $match );
				}
			}
		}

		return array_values( array_unique( array_filter( $depend_field ) ) );
	}

	/**
	 * Get the fields that an amount depends on
	 *
	 * @param array $field_settings Field settings.
	 * @return array
	 */
	public function get_amount_dependent_fields( $field_settings ) {
		$this->payment_plan = $this->get_payment_plan( $field_settings );
		$plan               = $this->payment_plan;

		$amount = $this->get_payment_amount( $field_settings );

		// Subscription plans use subscription_amount_* keys; include live total so paymentPlan hash changes when variable price/qty changes.
		if ( ! empty( $plan['payment_method'] ) && 'subscription' === $plan['payment_method'] && forminator_is_stripe_addon_compatible() ) {
			$amount = $this->get_subscription_amount( $plan, $this, Forminator_CForm_Front_Action::$prepared_data, $field_settings );
		}

		$this->payment_plan_hash = md5( wp_json_encode( $plan ) . $amount );

		$conditions_depends = self::get_conditions_dependent_fields( $field_settings );
		$plan_depends       = self::get_plan_dependent_fields( $plan );
		$depend_field       = array_merge( $conditions_depends, $plan_depends );

		return array_unique( $depend_field );
	}

	/**
	 * Get subscription amount including quantity.
	 *
	 * @param array            $payment_plan Payment plan.
	 * @param Forminator_Field $field_object Field object.
	 * @param array            $prepared_data Prepared submission data.
	 * @param array            $field_settings Field settings.
	 *
	 * @return float|int
	 */
	private function get_subscription_amount( $payment_plan, $field_object, $prepared_data, $field_settings ) {
		$stripe_addon = Forminator_Stripe_Subscription::get_instance();
		$price        = $stripe_addon->calculate_price( $payment_plan, Forminator_Front_Action::$module_object, $field_object, $prepared_data, $field_settings );
		$quantity     = $stripe_addon->get_quantity( $payment_plan, Forminator_Front_Action::$module_object, $field_object, $prepared_data, $field_settings );

		if ( $quantity < 1 ) {
			$quantity = 1;
		}

		return $price * $quantity;
	}

	/**
	 * Get the fields that conditions based on
	 *
	 * @param array $field_settings Field settings.
	 *
	 * @return array
	 */
	private static function get_conditions_dependent_fields( $field_settings ) {
		$depend_field = array();

		$all_conditions = self::get_property( 'conditions', $field_settings, array() );

		$payments = self::get_property( 'payments', $field_settings, array() );

		foreach ( $payments as $payment ) {
			$conditions = $payment['conditions'] ?? array();
			if ( empty( $conditions ) || ! is_array( $conditions ) ) {
				continue;
			}
			$all_conditions = array_merge( $all_conditions, $conditions );
		}

		foreach ( $all_conditions as $condition ) {
			if ( ! empty( $condition['element_id'] ) ) {
				$depend_field[] = $condition['element_id'];
			}
		}

		return $depend_field;
	}

	/**
	 * Get the fields that a plan depends on
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private static function get_plan_dependent_fields( $plan ) {
		$depend_field = array();
		if ( empty( $plan['payment_method'] ) ) {
			return $depend_field;
		}

		if ( 'single' === $plan['payment_method']
				&& ! empty( $plan['amount_type'] )
				&& 'variable' === $plan['amount_type']
				&& ! empty( $plan['variable'] ) ) {
			$depend_field[] = $plan['variable'];
		}

		if ( 'subscription' === $plan['payment_method']
				&& ! empty( $plan['subscription_amount_type'] )
				&& 'variable' === $plan['subscription_amount_type']
				&& ! empty( $plan['subscription_variable'] ) ) {
			$depend_field[] = $plan['subscription_variable'];
		}

		if ( 'subscription' === $plan['payment_method']
				&& ! empty( $plan['quantity_type'] )
				&& 'variable' === $plan['quantity_type']
				&& ! empty( $plan['variable_quantity'] ) ) {
			$depend_field[] = $plan['variable_quantity'];
		}

		return $depend_field;
	}

	/**
	 * Get payment amount
	 *
	 * @since 1.7
	 *
	 * @param array $field Field.
	 *
	 * @return double
	 */
	public function get_payment_amount( $field ) {
		$payment_amount  = 0.0;
		$amount_type     = self::get_property( 'amount_type', $field, 'fixed' );
		$amount          = self::get_property( 'amount', $field, '0' );
		$amount_variable = self::get_property( 'variable', $field, '' );
		$submitted_data  = Forminator_CForm_Front_Action::$prepared_data;

		if ( ! empty( $this->payment_plan ) ) {
			$amount_type     = isset( $this->payment_plan['amount_type'] ) ? $this->payment_plan['amount_type'] : $amount_type;
			$amount          = isset( $this->payment_plan['amount'] ) ? $this->payment_plan['amount'] : $amount;
			$amount_variable = isset( $this->payment_plan['variable'] ) ? $this->payment_plan['variable'] : $amount_variable;
		}

		if ( 'fixed' === $amount_type ) {
			$payment_amount = $amount;
		} else {
			$amount_var = $amount_variable;
			$form_field = Forminator_Front_Action::$module_object->get_field( $amount_var, false );
			if ( $form_field ) {
				$form_field = $form_field->to_formatted_array();
				if ( isset( $form_field['type'] ) ) {
					if ( 'calculation' === $form_field['type'] ) {

						// Calculation field get the amount from pseudo_submit_data.
						if ( isset( Forminator_CForm_Front_Action::$prepared_data[ $amount_var ] ) ) {
							$payment_amount = Forminator_CForm_Front_Action::$prepared_data[ $amount_var ];
						}
					} elseif ( 'currency' === $form_field['type'] ) {
						// Currency field get the amount from submitted_data.
						$field_id = $form_field['element_id'];
						if ( isset( $submitted_data[ $field_id ] ) ) {
							$payment_amount = self::forminator_replace_number( $form_field, $submitted_data[ $field_id ] );
						}
					} else {
						$field_object = Forminator_Core::get_field_object( $form_field['type'] );
						if ( $field_object ) {
							$submitted_field_data = $submitted_data[ $amount_var ] ?? null;
							$payment_amount       = $field_object::get_calculable_value( $submitted_field_data, $form_field );
						}
					}
				}
			}
		}

		if ( ! is_numeric( $payment_amount ) ) {
			$payment_amount = 0.0;
		}

		/**
		 * Filter payment amount of stripe
		 *
		 * @since 1.7
		 *
		 * @param double                       $payment_amount
		 * @param array                        $field field settings.
		 * @param Forminator_Form_Model $module_object
		 * @param array                        $prepared_data
		 */
		$payment_amount = apply_filters( 'forminator_field_stripe_payment_amount', $payment_amount, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data );

		return $payment_amount;
	}

	/**
	 * Get Payment plan
	 *
	 * @param array $field Field.
	 *
	 * @return array
	 */
	public function get_payment_plan( $field ) {
		$payments = self::get_property( 'payments', $field, array() );

		if ( ! empty( $payments ) ) {
			foreach ( $payments as $payment_settings ) {
				$payment_settings['condition_rule']   = ! empty( $payment_settings['condition_rule'] ) ? $payment_settings['condition_rule'] : 'all';
				$payment_settings['condition_action'] = 'show';
				if ( ! Forminator_Field::is_hidden( $field, $payment_settings ) ) {
					return $payment_settings;
				}
			}
		}

		return array();
	}

	/**
	 * Get transaction link
	 *
	 * @param string $mode Payment mode.
	 * @param string $transaction_id Transaction id.
	 * @return string
	 */
	public static function get_transanction_link( $mode, $transaction_id ) {
		if ( 'test' === $mode ) {
			$link_base = 'https://dashboard.stripe.com/test/payments/';
		} else {
			$link_base = 'https://dashboard.stripe.com/payments/';
		}
		$transaction_link = $link_base . rawurlencode( $transaction_id );

		return $transaction_link;
	}

	/**
	 * Payment method
	 *
	 * @param string $method Payment method.
	 *
	 * @return string|void
	 */
	public function payment_method( $method ) {
		switch ( $method ) {
			case 'single':
				$method = esc_html__( 'One Time', 'forminator' );
				break;
			case 'subscription':
				$method = esc_html__( 'Subscription', 'forminator' );
				break;
			default:
				$method = '';
		}

		return $method;
	}

	/**
	 * Get Stripe PaymentIntent object by payment ID.
	 *
	 * @since 1.58
	 *
	 * @param array  $field Stripe field.
	 * @param string $payment_id Stripe PaymentIntent ID.
	 * @return mixed  PaymentIntent object or WP_Error on failure.
	 */
	public function get_stripe_payment_intent( $field, $payment_id ) {
		$this->setup_stripe_app_info( $field );
		try {
			// Check payment amount.
			$intent = \Forminator\Stripe\PaymentIntent::retrieve( $payment_id );

			return $intent;
		} catch ( Exception $e ) {
			return $this->get_error( $e );
		}
	}

	/**
	 * Get PaymentIntent for a given customer.
	 *
	 * @since 1.58
	 *
	 * @param array  $field Stripe field.
	 * @param string $customer_id Stripe Customer ID.
	 * @return mixed  PaymentIntent object or false if not found.
	 */
	public function get_payment_intent_by_customer( $field, $customer_id ) {
		$this->setup_stripe_app_info( $field );
		try {
			$payment_intent = \Forminator\Stripe\PaymentIntent::all(
				array(
					'customer' => $customer_id,
					'limit'    => 1,
				)
			);
			if ( ! empty( $payment_intent->data ) && ! empty( $payment_intent->data[0] ) ) {
				return $payment_intent->data[0];
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'Stripe\PaymentIntent::all',
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'customer'      => $customer_id,
				)
			);
		}
		return false;
	}

	/**
	 * Get SetupIntent for a given customer.
	 *
	 * @since 1.58
	 *
	 * @param array  $field Stripe field.
	 * @param string $customer_id Stripe Customer ID.
	 * @return mixed  SetupIntent object or false if not found.
	 */
	public function get_setup_intent_by_customer( $field, $customer_id ) {
		$this->setup_stripe_app_info( $field );
		try {
			$setup_intent = \Forminator\Stripe\SetupIntent::all(
				array(
					'customer' => $customer_id,
					'limit'    => 1,
				)
			);
			if ( ! empty( $setup_intent->data ) && ! empty( $setup_intent->data[0] ) ) {
				return $setup_intent->data[0];
			}
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'Stripe\SetupIntent::all',
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'customer'      => $customer_id,
				)
			);
		}
		return false;
	}

	/**
	 * Get the PaymentIntent and SetupIntent to check for a subscription Checkout Session.
	 *
	 * Uses the session's own subscription, as a customer can hold other payments. Before it exists
	 * nothing is paid, so the customer's paid intents are ignored.
	 *
	 * @param array  $field   Stripe field.
	 * @param object $session Checkout Session object.
	 * @return array{payment_intent:mixed,setup_intent:mixed}|WP_Error
	 */
	public function get_subscription_intents( $field, $session ) {
		if ( empty( $session->subscription ) ) {
			$payment_intent = $this->get_payment_intent_by_customer( $field, $session->customer );
			if ( empty( $payment_intent ) ) {
				return array(
					'payment_intent' => false,
					'setup_intent'   => $this->get_setup_intent_by_customer( $field, $session->customer ),
				);
			}

			return array(
				'payment_intent' => in_array( $payment_intent->status, array( 'succeeded', 'processing' ), true ) ? false : $payment_intent,
				'setup_intent'   => false,
			);
		}

		$this->setup_stripe_app_info( $field );

		$subscription_id = is_object( $session->subscription ) ? $session->subscription->id : $session->subscription;

		try {
			$subscription = \Forminator\Stripe\Subscription::retrieve(
				array(
					'id'     => $subscription_id,
					'expand' => array( 'pending_setup_intent' ),
				)
			);
			$invoice_id   = is_object( $subscription->latest_invoice ) ? $subscription->latest_invoice->id : (string) $subscription->latest_invoice;

			$payment_intent_id = '';
			if ( '' !== $invoice_id ) {
				$payments = \Forminator\Stripe\InvoicePayment::all( array( 'invoice' => $invoice_id ) );
				foreach ( $payments->data as $payment ) {
					$intent_id = self::get_payment_intent( $payment->payment->payment_intent ?? '' );
					if ( empty( $intent_id ) ) {
						continue;
					}
					// The default payment collects the invoice.
					if ( ! empty( $payment->is_default ) ) {
						$payment_intent_id = $intent_id;
						break;
					}
					if ( '' === $payment_intent_id ) {
						$payment_intent_id = $intent_id;
					}
				}
			}

			$payment_intent = '' !== $payment_intent_id ? \Forminator\Stripe\PaymentIntent::retrieve( $payment_intent_id ) : false;

			return array(
				'payment_intent' => $payment_intent,
				'setup_intent'   => empty( $payment_intent ) && is_object( $subscription->pending_setup_intent ) ? $subscription->pending_setup_intent : false,
			);
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'action'        => 'Subscription::retrieve',
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
					'subscription'  => $subscription_id,
				)
			);

			return $this->get_error( $e );
		}
	}

	/**
	 * Setup Stripe API key and app info for API calls.
	 *
	 * @since 1.58
	 *
	 * @param array $field Stripe field.
	 * @return void
	 */
	private function setup_stripe_app_info( $field ) {
		$mode = self::get_property( 'mode', $field, 'test' );

		$key = $this->get_secret_key( 'test' !== $mode );

		\Forminator\Stripe\Stripe::setApiKey( $key );
		Forminator_Gateway_Stripe::set_stripe_app_info();
	}
}
