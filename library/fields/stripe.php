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
	 * Option key for uploaded files associated with Checkout Sessions.
	 *
	 * @since 1.56
	 *
	 * @var string
	 */
	public const CHECKOUT_SESSION_UPLOADED_FILES = 'forminator_uploaded_files_on_checkout_sessions';

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
		$prefix                 = 'basic' === $settings['form-style'] ? 'basic-' : '';

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
			$radios = true;
			$layout = 'accordion';
		}
		$data = array(
			'type'             => $layout,
			'defaultCollapsed' => false,
		);

		if ( 'accordion' === $layout ) {
			$data['spacedAccordionItems'] = false;
			$data['radios']               = ! empty( $radios );
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
			'return_url'          => self::get_return_url(),
			'client_reference_id' => $form_id,
		);

		if ( 'false' === $dynamic_methods ) {
			$options['payment_method_types'] = array( 'card' );
		} else {
			$options['excluded_payment_method_types'] = self::get_checkout_session_excluded_payment_methods();
		}

		$checkout_phone_enabled = filter_var( self::get_property( 'checkout_phone_enabled', $field, false ), FILTER_VALIDATE_BOOLEAN );
		$custom_form            = Forminator_Base_Form_Model::get_model( $form_id );
		$checkout_phone         = $custom_form instanceof Forminator_Form_Model
			? forminator_get_active_mapped_field_id( self::get_property( 'checkout_phone', $field, '' ), $custom_form->get_fields(), $form_id )
			: '';
		// Enable Stripe phone collection only when the saved mapping still points to an active Phone field.
		if (
			$checkout_phone_enabled
			&& ! empty( $checkout_phone )
		) {
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

			$stripe_code = $e instanceof \Forminator\Stripe\Exception\ApiErrorException ? $e->getStripeCode() : '';
			$message     = \Forminator\Stripe\ErrorObject::CODE_AMOUNT_TOO_SMALL === $stripe_code
				? esc_html__( 'Unable to process checkout. Amount is below the minimum payment allowed.', 'forminator' )
				: esc_html__( 'Unable to initialize checkout. Please try again.', 'forminator' );

			$response = array(
				'message'         => $message,
				'errors'          => array(),
				'paymentPlan'     => $this->payment_plan_hash,
				'exception_class' => get_class( $e ),
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
			if ( $is_intent && class_exists( 'Forminator_Stripe_Subscription' ) ) {
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
		$amount   = $submitted_data[ $field_id ] ?? 0;
		$id       = $submitted_data['paymentid'];
		if ( ! $amount && ! empty( $submitted_data['stripe_first_payment_intent'] ) ) {
			// If amount is empty, set it to 1 for payment intent. Anyway, it will be updated during actual payment.
			$amount = 1;
			// Filter amount. It can be used to modify amount before creating payment intent for low-value currency
			// to achieve minimum Stripe charge amount .5 euro. Use $field['currency'] to get currency code.
			$amount = apply_filters( 'forminator_stripe_default_payment_intent_amount', $amount, $field );
		}
		$payment_method     = filter_var( $field['automatic_payment_methods'], FILTER_VALIDATE_BOOLEAN ) ? 'dynamic' : 'card';
		$payment_intent_key = $mode . '_' . $currency . '_' . $amount . '_' . substr( $key, -5 ) . '_' . $payment_method;
		// Check if we already have payment ID, if not generate new one.
		if ( empty( $id ) ) {
			$generate_new = ! $is_intent;
			$id           = $this->get_payment_intent_id( $amount, $field, $payment_intent_key, $generate_new );
		}

		try {
			// Retrieve PI object.
			$intent = \Forminator\Stripe\PaymentIntent::retrieve( $id );
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

			$id = $this->get_payment_intent_id( $amount, $field, $payment_intent_key, true );

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
				// Check payment amount.
				if ( 0 > $amount ) {
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
			if ( ! class_exists( 'Forminator_Stripe_Subscription' ) ) {
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

			if ( ! method_exists( $stripe_addon, 'update_checkout_session' ) ) {
				wp_send_json_error(
					array(
						'message'     => esc_html(
							apply_filters(
								'forminator_payment_update_stripe_subscription_addon_error_message',
								esc_html__( 'Please update the Forminator Stripe Subscription Add-on to the latest version to submit this form.', 'forminator' )
							)
						),
						'errors'      => array(),
						'paymentPlan' => $this->payment_plan_hash,
					)
				);
			}

			$response = $stripe_addon->update_checkout_session( $this, Forminator_Front_Action::$module_object, $submitted_data, $field, $this->payment_plan );

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
					'paymentid'     => $response['paymentid'] ?? '',
					'paymentsecret' => $response['paymentsecret'] ?? '',
					'paymentPlan'   => $this->payment_plan_hash,
					'paymentApi'    => 'checkout_session',
				)
			);
		}

		$field_id = Forminator_Field::get_property( 'element_id', $field );
		$amount   = $submitted_data[ $field_id ] ?? 0;

		if ( empty( $submitted_data['stripe-intent'] ) && ! empty( $payment_id ) ) {
			$this->retrieve_checkout_session_response( $payment_id, $field );
		}

		$this->create_checkout_session_response( $amount, $field, empty( $payment_id ) );
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

		if ( 0 >= (float) $amount && $allow_default_amount ) {
			// Initial render still needs a Checkout Session; mark the fallback so submit can rebuild with real pricing.
			$amount                   = $this->get_checkout_session_render_amount( 1, $currency, $field );
			$validation_amount        = $this->calculate_amount( $amount, $currency );
			$force_mount_stripe_field = true;
		}

		if ( 0 >= (float) $amount ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Payment amount should be greater than 0.', 'forminator' ),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				)
			);
		}

		$session = $this->generate_checkout_session( $amount, $field, ! $allow_default_amount );

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
				'paymentid'             => $session->id,
				'paymentsecret'         => $session->client_secret,
				'paymentPlan'           => $this->payment_plan_hash,
				'paymentApi'            => 'checkout_session',
				'forceMountStripeField' => $force_mount_stripe_field,
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
		if ( 0 >= (float) $this->get_payment_amount( $field ) ) {
			wp_send_json_error(
				array(
					'message'     => esc_html__( 'Payment amount should be greater than 0.', 'forminator' ),
					'errors'      => array(),
					'paymentPlan' => $this->payment_plan_hash,
				)
			);
		}

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

		$valid = $this->validate_checkout_session( $session, $field );

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
				'paymentid'     => $session->id,
				'paymentsecret' => $session->client_secret ?? '',
				'paymentPlan'   => $this->payment_plan_hash,
				'paymentApi'    => 'checkout_session',
			)
		);
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
	 * Copy current Checkout Session metadata to the generated PaymentIntent.
	 *
	 * @since 1.56
	 *
	 * @param mixed $session Checkout Session object.
	 * @param array $field Field.
	 */
	private function sync_checkout_session_payment_intent_metadata( $session, $field ) {
		$payment_intent = $session->payment_intent ?? '';
		if ( is_object( $payment_intent ) ) {
			$payment_intent = $payment_intent->id ?? '';
		}

		if ( empty( $payment_intent ) ) {
			return;
		}

		$metadata_data = $this->build_metadata_context( $field );
		$context       = $this->build_payment_request_context( $field );
		$options       = array();

		if ( ! empty( $metadata_data['metadata'] ) ) {
			$options['metadata'] = $metadata_data['metadata'];
		}

		if ( '' !== trim( (string) self::get_property( 'product_description', $field, '' ) ) ) {
			$options['description'] = (string) $context['description'];
		}

		if ( empty( $options ) ) {
			return;
		}

		try {
			\Forminator\Stripe\PaymentIntent::update(
				$payment_intent,
				$options
			);
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
	 * Get payment intent ID
	 *
	 * @param int|float $amount Amount.
	 * @param array     $field Field.
	 * @param string    $payment_intent_key Payment intent key.
	 * @param bool      $force Use saved payment intents or not.
	 *
	 * @return string
	 */
	private function get_payment_intent_id( $amount, $field, $payment_intent_key, $force = false ): string {
		$saved_payment_intents = get_option( 'forminator_stripe_payment_intents', array() );

		/**
		 * Filter to force payment intent generation
		 *
		 * @param bool  $force Force payment intent generation.
		 * @param array $field Field.
		 */
		$force = apply_filters( 'forminator_stripe_force_payment_intent', $force, $field );
		if ( ! $force && ! empty( $saved_payment_intents[ $payment_intent_key ] ) ) {
			$id = $saved_payment_intents[ $payment_intent_key ];
		} else {
			$payment_intent = $this->generate_paymentIntent( $amount, $field );

			$id = $payment_intent->id;

			$saved_payment_intents[ $payment_intent_key ] = $id;
			update_option( 'forminator_stripe_payment_intents', $saved_payment_intents );
		}

		return $id;
	}

	/**
	 * Get unsupported payment methods
	 *
	 * @return array
	 */
	public static function get_unsupported_payment_methods() {
		return apply_filters(
			'forminator_stripe_unsupported_payment_methods',
			// All Stripe dynamic payment methods without immediate confirmation.
			array(
				'sepa_debit',
				'multibanco',
				'boleto',
				'ach_credit_transfer',
				'ach_debit',
				'sofort',
				'funded',
			)
		);
	}

	/**
	 * Get unsupported payment methods that are valid for Checkout Sessions.
	 *
	 * Checkout Sessions does not accept some legacy Payment Intent method IDs
	 * in excluded_payment_method_types, so only remove those known invalid
	 * values from the shared unsupported list.
	 *
	 * @return array
	 */
	public static function get_checkout_session_excluded_payment_methods() {
		$checkout_unsupported_methods = array(
			'ach_credit_transfer',
			'ach_debit',
			'funded',
		);

		return array_values( array_diff( self::get_unsupported_payment_methods(), $checkout_unsupported_methods ) );
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
		$payments = self::get_property( 'payments', $field, array() );

		foreach ( $payments as $payment ) {
			// Adaptive Pricing is only safe when Stripe receives a fixed source amount.
			if ( ! empty( self::get_plan_dependent_fields( $payment ) ) ) {
				return false;
			}
		}

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

				if ( class_exists( 'Forminator_Stripe_Subscription' ) ) {
					$amount = $this->get_subscription_amount( $this->payment_plan, $this, Forminator_CForm_Front_Action::$prepared_data, $field );
				}
			}

			if ( 0 >= (float) $amount ) {
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
	 * Process to entry data
	 *
	 * @param array $field Field.
	 *
	 * @return array|WP_Error
	 * @throws Exception When there is an error.
	 */
	public function process_to_entry_data( $field ) {
		if ( $this->is_checkout_session( $field ) ) {
			return $this->process_checkout_session_to_entry_data( $field );
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
	 * @param array $field Field.
	 * @throws Exception When the Checkout Session cannot be retrieved or validated.
	 * @return array|WP_Error
	 */
	private function process_checkout_session_to_entry_data( $field ) {
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

			if ( ! self::is_valid_checkout_session( $session->id ) ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'        => 'checkout_session_entry_already_handled',
						'error_message' => esc_html__( 'Checkout Session ID is not valid', 'forminator' ),
						'payment_id'    => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'       => ! empty( Forminator_Front_Action::$module_object->id ) ? Forminator_Front_Action::$module_object->id : '',
						'field_id'      => self::get_property( 'element_id', $field, '' ),
						'session_id'    => $session->id,
					)
				);

				return new WP_Error(
					'forminator_stripe_checkout_session_already_handled',
					esc_html__( 'Checkout Session ID is not valid', 'forminator' )
				);
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

			if ( 'paid' !== ( $session->payment_status ?? '' ) ) {
				forminator_maybe_log(
					__METHOD__,
					array(
						'action'         => 'checkout_session_entry_payment_pending',
						'error_message'  => esc_html__( 'Payment failed. Please try again.', 'forminator' ),
						'payment_id'     => Forminator_CForm_Front_Action::$prepared_data['paymentid'] ?? '',
						'form_id'        => $expected_form_id,
						'field_id'       => self::get_property( 'element_id', $field, '' ),
						'session_id'     => $session->id,
						'payment_status' => $session->payment_status ?? '',
					)
				);

				return new WP_Error(
					'forminator_stripe_checkout_session_pending',
					esc_html__( 'Payment failed. Please try again.', 'forminator' )
				);
			}

			$entry_data['status'] = 'COMPLETED';

			$payment_intent = $session->payment_intent ?? '';
			if ( is_object( $payment_intent ) ) {
				$payment_intent = $payment_intent->id ?? '';
			}
			$this->sync_checkout_session_payment_intent_metadata( $session, $field );

			$entry_data['mode']     = $mode;
			$entry_data['currency'] = $currency;
			$entry_data['amount']   = $charge_amount;
			if ( ! empty( $this->payment_plan ) ) {
				$entry_data['product_name'] = $this->payment_plan['plan_name'];
				$entry_data['payment_type'] = $this->payment_method( $this->payment_plan['payment_method'] );
				$entry_data['quantity']     = $this->payment_plan['quantity'];
			}

			$entry_data['transaction_id'] = $payment_intent ? $payment_intent : $session->id;
			if ( $payment_intent ) {
				$entry_data['transaction_link'] = self::get_transanction_link( $mode, $payment_intent );
			}
		} catch ( Exception $e ) {
			$entry_data['error'] = $e->getMessage();
		}

		return apply_filters( 'forminator_field_stripe_process_to_entry_data', $entry_data, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data, Forminator_CForm_Front_Action::$info['field_data_array'] );
	}

	/**
	 * Check if payment intent is valid
	 *
	 * @param string $intent_id Payment Intent ID.
	 *
	 * @return bool
	 */
	private static function is_valid_payment_intent( $intent_id ): bool {
		$payment_intents = get_option( 'forminator_stripe_payment_intents', array() );

		if ( is_array( $payment_intents ) && in_array( $intent_id, $payment_intents, true ) ) {
			// Remove payment intent after handling it.
			add_action(
				'forminator_after_handle_form',
				function () use ( $intent_id ) {
					$option_key      = 'forminator_stripe_payment_intents';
					$payment_intents = get_option( $option_key, array() );
					$payment_intents = array_diff( $payment_intents, array( $intent_id ) );
					update_option( $option_key, $payment_intents );
				}
			);

			return true;
		}
		return false;
	}

	/**
	 * Check if checkout session is valid
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @return bool
	 */
	public static function is_valid_checkout_session( $session_id ): bool {
		if ( self::has_pending_checkout_session( $session_id ) ) {
			add_action(
				'forminator_after_handle_form',
				function () use ( $session_id ) {
					self::remove_pending_checkout_session( $session_id );
				}
			);

			return true;
		}

		return false;
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
			),
			false
		);

		$checkout_sessions                = get_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, array() );
		$checkout_sessions                = is_array( $checkout_sessions ) ? $checkout_sessions : array();
		$checkout_sessions[ $session_id ] = time();
		update_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $checkout_sessions, false );
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
		if ( empty( $session_id ) ) {
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

		return false !== get_option( self::get_checkout_session_option_key( $session_id ), false );
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

		// Also remove any uploaded files data associated with this Checkout Session.
		self::remove_checkout_session_uploaded_files_data( $session_id );
	}

	/**
	 * Remove abandoned pending Checkout Session markers.
	 *
	 * @since 1.56
	 *
	 * @return void
	 */
	public function cleanup_checkout_sessions() {
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

		$expired_sessions = array();
		foreach ( $checkout_sessions as $session_id => $created_at ) {
			if ( ! is_numeric( $created_at ) || time() - (int) $created_at > $retention ) {
				$expired_sessions[] = $session_id;
				unset( $checkout_sessions[ $session_id ] );
				delete_option( self::get_checkout_session_option_key( $session_id ) );
			}
		}

		update_option( self::CHECKOUT_SESSION_CLEANUP_OPTION_KEY, $checkout_sessions, false );

		if ( ! empty( $expired_sessions ) ) {
			self::remove_checkout_session_uploaded_files_data( $expired_sessions );
		}
	}

	/**
	 * Determine whether a Checkout Session can still be recovered for retry.
	 *
	 * @since 1.56
	 *
	 * @param mixed $session Checkout Session object.
	 * @return bool
	 */
	public static function is_recoverable_checkout_session( $session ): bool {
		if ( ! is_object( $session ) || empty( $session->id ) ) {
			return false;
		}

		$session_status = isset( $session->status ) ? (string) $session->status : '';
		$payment_status = isset( $session->payment_status ) ? (string) $session->payment_status : '';

		if ( 'paid' === $payment_status ) {
			return true;
		}

		if ( 'expired' === $session_status || 'complete' === $session_status ) {
			return false;
		}

		if ( 'unpaid' === $payment_status ) {
			return false;
		}

		return 'paid' !== $payment_status;
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
		$transaction_link = $transaction_id;
		if ( isset( $meta_value['transaction_link'] ) && ! empty( $meta_value['transaction_link'] ) ) {
			$url              = $meta_value['transaction_link'];
			$transaction_link = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" title="' . $transaction_id . '">' . $transaction_id . '</a>';
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
	private function get_checkout_session_by_id( $session_id, $field ) {
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
	 * @return string
	 */
	public static function get_return_url() {
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
		if ( ! empty( $plan['payment_method'] ) && 'subscription' === $plan['payment_method'] && class_exists( 'Forminator_Stripe_Subscription' ) ) {
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
	 * Check if the checkout session is valid for file upload
	 *
	 * @since 1.56
	 *
	 * @param string $session_id Checkout Session ID.
	 * @param array  $field Field.
	 * @return bool
	 */
	public function is_valid_session_for_file_upload( $session_id, $field ) {
		$session = $this->get_checkout_session_by_id( $session_id, $field );
		if ( is_wp_error( $session ) || empty( $session->id ) ) {
			return false;
		}

		$session_status = isset( $session->status ) ? (string) $session->status : '';
		$payment_status = isset( $session->payment_status ) ? (string) $session->payment_status : '';

		if ( 'expired' === $session_status || 'complete' === $session_status ) {
			return false;
		}

		if ( 'paid' !== $payment_status ) {
			return true;
		}
		return false;
	}

	/**
	 * Save uploaded files on checkout session
	 *
	 * @param string $session_id Stripe Checkout Session ID.
	 * @param array  $uploaded_files Uploaded files data.
	 * @param string $type Field type.
	 *
	 * @return void
	 */
	public function add_uploaded_files_on_checkout_session( $session_id, $uploaded_files, $type = 'upload' ) {
		$saved_files = get_option( self::CHECKOUT_SESSION_UPLOADED_FILES, array() );
		if ( ! is_array( $saved_files ) ) {
			$saved_files = array();
		}
		$saved_files[ $session_id ][ $type ] = $uploaded_files;

		update_option( self::CHECKOUT_SESSION_UPLOADED_FILES, $saved_files, false );
	}

	/**
	 * Get uploaded files on checkout session
	 *
	 * @param string $session_id Stripe Checkout Session ID.
	 * @param string $type Field type.
	 * @return array
	 */
	public function get_uploaded_files_on_checkout_session( $session_id, $type = 'upload' ) {
		$saved_files = get_option( self::CHECKOUT_SESSION_UPLOADED_FILES, array() );
		if ( ! empty( $saved_files[ $session_id ] ) && ! empty( $saved_files[ $session_id ][ $type ] ) ) {
			return $saved_files[ $session_id ][ $type ];
		}
		return array();
	}

	/**
	 * Remove uploaded files data associated with a Checkout Session ID.
	 *
	 * @since 1.56
	 *
	 * @param string|array $session_id Checkout Session ID.
	 * @return void
	 */
	public static function remove_checkout_session_uploaded_files_data( $session_id ) {
		$uploaded_files = get_option( self::CHECKOUT_SESSION_UPLOADED_FILES, array() );
		if ( is_array( $session_id ) ) {
			foreach ( $session_id as $id ) {
				if ( isset( $uploaded_files[ $id ] ) ) {
					self::maybe_delete_file( $uploaded_files[ $id ] );
					unset( $uploaded_files[ $id ] );
				}
			}
		} elseif ( isset( $uploaded_files[ $session_id ] ) ) {
			self::maybe_delete_file( $uploaded_files[ $session_id ] );
			unset( $uploaded_files[ $session_id ] );
		}
		update_option( self::CHECKOUT_SESSION_UPLOADED_FILES, $uploaded_files, false );
	}

	/**
	 * Delete uploaded files if they are not used as featured images in any post.
	 *
	 * @param array $data Uploaded files data.
	 * @return void
	 */
	private static function maybe_delete_file( $data ) {
		if ( empty( $data['postdata'] ) ) {
			return;
		}
		foreach ( $data['postdata'] as $postdata ) {
			if ( ! empty( $postdata['attachment_id'] ) ) {
				$attachment_id = $postdata['attachment_id'];
				// Check if the attachment is set as a featured image for any post before deleting it.
				$is_featured = self::is_attachment_featured( $attachment_id );
				if ( ! $is_featured ) {
					wp_delete_attachment( $attachment_id, true );
				}
			}
		}
	}

	/**
	 * Check if an attachment is set as a featured image for any post.
	 *
	 * @param int $attachment_id Attachment ID to check.
	 * @return bool
	 */
	private static function is_attachment_featured( $attachment_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %d", (int) $attachment_id )
		);
	}
}
