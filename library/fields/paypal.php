<?php
/**
 * The Forminator_PayPal class.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_PayPal
 *
 * @since 1.7
 */
class Forminator_PayPal extends Forminator_Field {

	/**
	 * Option prefix of an order binding.
	 *
	 * @since 1.58.4
	 */
	public const ORDER_OPTION_PREFIX = 'forminator_paypal_order_';

	/**
	 * Option key listing order bindings for cleanup.
	 *
	 * @since 1.58.4
	 */
	public const ORDER_CLEANUP_OPTION_KEY = 'forminator_paypal_order_cleanup';

	/**
	 * Currencies PayPal takes without decimals.
	 *
	 * @since 1.58.4
	 */
	public const ZERO_DECIMAL_CURRENCIES = array( 'HUF', 'JPY', 'TWD' );

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
	public $slug = 'paypal';

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'paypal';

	/**
	 * Position
	 *
	 * @var int
	 */
	public $position = 24;

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
	public $icon = 'sui-icon forminator-icon-paypal';

	/**
	 * Is calculable
	 *
	 * @var bool
	 */
	public $is_connected = false;

	/**
	 * Forminator_PayPal constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->name = esc_html__( 'PayPal', 'forminator' );
	}

	/**
	 * Field defaults
	 *
	 * @return array
	 */
	public function defaults() {

		$default_currency = 'USD';
		try {
			$paypal           = new Forminator_PayPal_Express();
			$default_currency = $paypal->get_default_currency();
		} catch ( Forminator_Gateway_Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage() );
		}

		return array(
			'mode'                => 'sandbox',
			'currency'            => $default_currency,
			'amount_type'         => 'fixed',
			'label'               => 'checkout',
			'color'               => 'gold',
			'shape'               => 'rect',
			'layout'              => 'vertical',
			'tagline'             => 'true',
			'shipping_address'    => 'disable',
			'locale'              => 'en_US',
			'debug_mode'          => 'disable',
			'height'              => '40',
			'product_description' => '',
			'options'             => array(),
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

		$element_name    = self::get_property( 'element_id', $field );
		$field_id        = $element_name . '-field';
		$mode            = self::get_property( 'mode', $field, 'sandbox' );
		$currency        = self::get_property( 'currency', $field, $this->get_default_currency() );
		$amount_type     = self::get_property( 'amount_type', $field, 'fixed' );
		$amount          = self::get_property( 'amount', $field, '0' );
		$amount_variable = self::get_property( 'variable', $field, '' );
		$logo            = self::get_property( 'logo', $field, '' );
		$company_name    = esc_html( self::get_property( 'company_name', $field, '' ) );
		$customer_email  = self::get_property( 'customer_email', $field, '' );
		$checkout_label  = esc_html( self::get_property( 'checkout_label', $field, '' ) );
		$collect_address = esc_html( self::get_property( 'collect_address', $field, 'none', 'string' ) );
		$verify_zip      = esc_html( self::get_property( 'verify_zip', $field, false, 'bool' ) );
		$language        = self::get_property( 'language', $field, 'en' );

		$attr = array(
			'type'              => 'hidden',
			'name'              => $element_name,
			'id'                => 'forminator-' . $field_id . '_' . Forminator_CForm_Front::$uid,
			'class'             => 'forminator-paypal-input',
			'data-is-payment'   => 'true',
			'data-payment-type' => $this->type,
			'data-currency'     => esc_html( strtolower( $currency ) ),
			'data-amount-type'  => esc_html( $amount_type ),
			'data-amount'       => ( 'fixed' === $amount_type ? esc_html( $amount ) : $amount_variable ),
			'data-label'        => esc_html( $checkout_label ),
			'data-locale'       => esc_html( $language ),
		);

		if ( ! empty( $logo ) ) {
			$attr['data-image'] = esc_url( $logo );
		}

		if ( ! empty( $company_name ) ) {
			$attr['data-name'] = esc_html( $company_name );
		}

		if ( ! empty( $customer_email ) ) {
			$attr['data-email'] = esc_html( $customer_email );
		}

		if ( 'billing' === $collect_address || 'billing_shipping' === $collect_address ) {
			$attr['data-billing-address'] = 'true';
		}

		if ( 'billing_shipping' === $collect_address ) {
			$attr['data-shipping-address'] = 'true';
		}

		if ( $verify_zip ) {
			$attr['data-zip-code'] = 'true';
		}

		$html = self::create_input( $attr );

		return apply_filters( 'forminator_field_paypal_markup', $html, $attr, $field );
	}


	/**
	 * Field back-end validation
	 *
	 * @param array        $field Field.
	 * @param array|string $data Data.
	 */
	public function validate( $field, $data ) {
		$id = self::get_property( 'element_id', $field );
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

		return apply_filters( 'forminator_field_paypal_sanitize', $data, $field, $original_data );
	}

	/**
	 * Is available
	 *
	 * @since 1.7
	 * @inheritdoc
	 * @param mixed $field Field.
	 */
	public function is_available( $field ) {
		$mode = self::get_property( 'mode', $field, 'sandbox' );
		try {
			$paypal = new Forminator_PayPal_Express();

			if ( 'sandbox' !== $mode ) {
				$paypal->set_live( true );
			}

			if ( $paypal->is_ready() ) {
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
	 * @param bool $live Is live?.
	 *
	 * @return bool|string
	 */
	private function get_publishable_key( $live = false ) {
		try {
			$paypal = new Forminator_PayPal_Express();

			if ( $live ) {
				return $paypal->get_live_id();
			}

			return $paypal->get_sandbox_id();
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
			$paypal = new Forminator_PayPal_Express();

			return $paypal->get_default_currency();

		} catch ( Forminator_Gateway_Exception $e ) {
			return 'USD';
		}
	}

	/**
	 * Process entry data
	 *
	 * @param array $field Field.
	 *
	 * @return array
	 */
	public function process_to_entry_data( $field ) {
		$entry_data = array(
			'mode'             => '',
			'status'           => '',
			'amount'           => '',
			'currency'         => '',
			'transaction_id'   => '',
			'transaction_link' => '',
		);

		$element_id = self::get_property( 'element_id', $field );
		$mode       = self::get_property( 'mode', $field, 'sandbox' );
		$currency   = self::get_property( 'currency', $field, $this->get_default_currency() );

		$entry_data['mode']     = $mode;
		$entry_data['currency'] = $currency;
		$charge_amount          = $this->get_payment_amount( $field );
		$charge_amount          = number_format( $charge_amount, 2, '.', '' );
		$transaction_id         = Forminator_CForm_Front_Action::$prepared_data['payment_transaction_id'] ?? '';

		// Accept only an order created for this form, browser and total.
		$binding_error = self::get_order_binding_error( $transaction_id, $mode, $charge_amount, $currency );
		if ( '' !== $binding_error ) {
			return self::get_binding_error_entry_data( $binding_error, $transaction_id, $mode );
		}

		$paypal = new Forminator_PayPal_Express();
		$order  = $paypal->get_order( $transaction_id, $mode );

		// Validate intent.
		if ( ! isset( $order->intent ) || 'CAPTURE' !== $order->intent ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'validation'     => 'intent',
					'transaction_id' => $transaction_id,
					'mode'           => $mode,
					'order'          => $order,
				)
			);

			return array(
				'error' => esc_html__( 'Error! Something went wrong during checkout and payment couldn\'t be approved.', 'forminator' ),
			);
		}

		// Validate status.
		if ( ! isset( $order->status ) || 'APPROVED' !== $order->status ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'validation'     => 'status',
					'transaction_id' => $transaction_id,
					'mode'           => $mode,
					'order'          => $order,
				)
			);

			return array(
				'error' => esc_html__( 'Error! Something went wrong during checkout and payment couldn\'t be approved.', 'forminator' ),
			);
		}

		// Validate amount.
		if ( ! isset( $order->purchase_units[0]->amount->value ) || self::format_amount( $charge_amount, $currency ) !== (string) $order->purchase_units[0]->amount->value ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'validation'     => 'amount',
					'transaction_id' => $transaction_id,
					'mode'           => $mode,
					'expected'       => $charge_amount,
					'order'          => $order,
				)
			);

			return array(
				'error' => esc_html__( 'Error! Invalid payment amount!', 'forminator' ),
			);
		}

		// Validate currency.
		if ( ! isset( $order->purchase_units[0]->amount->currency_code ) || $order->purchase_units[0]->amount->currency_code !== $currency ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'validation'     => 'currency',
					'transaction_id' => $transaction_id,
					'mode'           => $mode,
					'expected'       => $currency,
					'order'          => $order,
				)
			);

			return array(
				'error' => esc_html__( 'Error! Invalid currency code!', 'forminator' ),
			);
		}

		// Claim after PayPal confirmed the order, so a failed lookup can be retried.
		if ( ! self::claim_order_binding( $transaction_id ) ) {
			return self::get_binding_error_entry_data( 'already_claimed', $transaction_id, $mode );
		}

		$entry_data['amount']         = $charge_amount;
		$entry_data['status']         = $order->status;
		$entry_data['transaction_id'] = $transaction_id;

		$entry_data['transaction_link'] = self::get_transanction_link( $mode, $transaction_id );
		/**
		 * Filter PayPal entry data that will be stored
		 *
		 * @since 1.7
		 *
		 * @param array                        $entry_data
		 * @param array                        $field            field properties.
		 * @param Forminator_Form_Model $module_object
		 * @param array                        $submitted_data
		 * @param array                        $field_data_array current entry meta.
		 *
		 * @return array
		 */
		$entry_data = apply_filters( 'forminator_field_paypal_process_to_entry_data', $entry_data, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data, Forminator_CForm_Front_Action::$info['field_data_array'] );

		return $entry_data;
	}

	/**
	 * Format an amount the way it is sent to PayPal.
	 *
	 * @since 1.58.4
	 *
	 * @param mixed  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function format_amount( $amount, $currency ): string {
		$decimals = in_array( strtoupper( (string) $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;

		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Check a PayPal order ID before it is used in an API path.
	 *
	 * @since 1.58.4
	 *
	 * @param mixed $order_id Order ID.
	 * @return bool
	 */
	private static function is_valid_order_id( $order_id ): bool {
		return is_string( $order_id ) && 1 === preg_match( '/^[A-Za-z0-9-]{1,64}$/', $order_id );
	}

	/**
	 * Store what an order was created for.
	 *
	 * @since 1.58.4
	 *
	 * @param string $order_id Order ID.
	 * @param string $form_id  Form ID.
	 * @param string $mode     Payment mode.
	 * @param mixed  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return bool
	 */
	public static function store_order_binding( $order_id, $form_id, $mode, $amount, $currency ): bool {
		if ( ! self::is_valid_order_id( $order_id ) ) {
			return false;
		}

		update_option(
			self::get_order_option_key( $order_id ),
			array(
				'form_id'    => (string) $form_id,
				'mode'       => 'live' === $mode ? 'live' : 'sandbox',
				'amount'     => self::format_amount( $amount, $currency ),
				'currency'   => strtoupper( (string) $currency ),
				// Reuses the Stripe browser token.
				'owner'      => Forminator_Stripe::get_payment_owner( true ),
				'created_at' => time(),
			),
			false
		);

		$orders              = get_option( self::ORDER_CLEANUP_OPTION_KEY, array() );
		$orders              = is_array( $orders ) ? $orders : array();
		$orders[ $order_id ] = time();
		update_option( self::ORDER_CLEANUP_OPTION_KEY, $orders, false );

		return true;
	}

	/**
	 * Check that an order was created for this form, browser and total.
	 *
	 * @since 1.58.4
	 *
	 * @param mixed  $order_id Order ID.
	 * @param string $mode     Payment mode.
	 * @param mixed  $amount   Expected amount.
	 * @param string $currency Expected currency code.
	 * @return string Empty when the order matches, the reason otherwise.
	 */
	private static function get_order_binding_error( $order_id, $mode, $amount, $currency ): string {
		if ( ! self::is_valid_order_id( $order_id ) ) {
			return 'invalid_order_id';
		}

		$binding = get_option( self::get_order_option_key( $order_id ), false );
		if ( ! is_array( $binding ) ) {
			return 'unknown_order';
		}

		if ( ! Forminator_Stripe::is_payment_owner( $binding['owner'] ?? '' ) ) {
			return 'owner_mismatch';
		}

		$form_id = ! empty( Forminator_Front_Action::$module_object->id ) ? (string) Forminator_Front_Action::$module_object->id : '';
		if (
			( $binding['form_id'] ?? '' ) !== $form_id
			|| ( 'live' === $mode ? 'live' : 'sandbox' ) !== ( $binding['mode'] ?? '' )
			|| self::format_amount( $amount, $currency ) !== ( $binding['amount'] ?? '' )
			|| strtoupper( (string) $currency ) !== ( $binding['currency'] ?? '' )
		) {
			return 'binding_mismatch';
		}

		return '';
	}

	/**
	 * Claim an order once, only the request that deletes the option succeeds.
	 *
	 * @since 1.58.4
	 *
	 * @param string $order_id Order ID, checked by get_order_binding_error().
	 * @return bool
	 */
	private static function claim_order_binding( $order_id ): bool {
		if ( ! delete_option( self::get_order_option_key( $order_id ) ) ) {
			return false;
		}

		self::remove_order_from_cleanup( $order_id );

		return true;
	}

	/**
	 * Log a rejected order and return the entry data error.
	 *
	 * @since 1.58.4
	 *
	 * @param string $reason   Reason code.
	 * @param mixed  $order_id Order ID.
	 * @param string $mode     Payment mode.
	 * @return array
	 */
	private static function get_binding_error_entry_data( $reason, $order_id, $mode ): array {
		forminator_maybe_log(
			__METHOD__,
			array(
				'validation'     => 'binding',
				'reason'         => $reason,
				'transaction_id' => $order_id,
				'mode'           => $mode,
			)
		);

		return array(
			'error' => esc_html__( 'Error! Something went wrong during checkout and payment couldn\'t be approved.', 'forminator' ),
		);
	}

	/**
	 * Remove an order from the cleanup list.
	 *
	 * @since 1.58.4
	 *
	 * @param string $order_id Order ID.
	 * @return void
	 */
	private static function remove_order_from_cleanup( $order_id ) {
		$orders = get_option( self::ORDER_CLEANUP_OPTION_KEY, array() );
		if ( is_array( $orders ) && isset( $orders[ $order_id ] ) ) {
			unset( $orders[ $order_id ] );
			update_option( self::ORDER_CLEANUP_OPTION_KEY, $orders, false );
		}
	}

	/**
	 * Delete bindings of orders that were never submitted.
	 *
	 * @since 1.58.4
	 *
	 * @return void
	 */
	public function cleanup_order_bindings() {
		$orders = get_option( self::ORDER_CLEANUP_OPTION_KEY, array() );
		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return;
		}

		$expired_before = time() - DAY_IN_SECONDS;
		foreach ( $orders as $order_id => $created_at ) {
			if ( (int) $created_at < $expired_before ) {
				delete_option( self::get_order_option_key( (string) $order_id ) );
				unset( $orders[ $order_id ] );
			}
		}

		update_option( self::ORDER_CLEANUP_OPTION_KEY, $orders, false );
	}

	/**
	 * Build the option key of an order binding.
	 *
	 * @since 1.58.4
	 *
	 * @param string $order_id Order ID.
	 * @return string
	 */
	private static function get_order_option_key( $order_id ): string {
		return self::ORDER_OPTION_PREFIX . md5( (string) $order_id );
	}

	/**
	 * Get the capture if it completed for the expected total.
	 *
	 * Checks the capture itself, an order can be COMPLETED with a pending or declined capture.
	 *
	 * @since 1.58.4
	 *
	 * @param mixed $response Capture order response.
	 * @param array $field    Field.
	 * @return object|WP_Error
	 */
	public function get_completed_capture( $response, $field ) {
		// A failed request returns a WP_Error, the caller logs it.
		if ( is_wp_error( $response ) || ! is_object( $response ) ) {
			return new WP_Error( 'forminator_paypal_capture_failed', esc_html__( 'Payment failed, please try again!', 'forminator' ) );
		}

		$currency = self::get_property( 'currency', $field, $this->get_default_currency() );
		$amount   = $this->get_payment_amount( $field );
		$capture  = $response->purchase_units[0]->payments->captures[0] ?? null;

		if ( ! isset( $response->status ) || 'COMPLETED' !== $response->status || ! is_object( $capture ) || empty( $capture->id ) ) {
			return new WP_Error( 'forminator_paypal_capture_failed', esc_html__( 'Payment failed, please try again!', 'forminator' ) );
		}

		// The money may still arrive, so don't ask the payer to pay again.
		if ( 'PENDING' === ( $capture->status ?? '' ) ) {
			return new WP_Error( 'forminator_paypal_capture_pending', esc_html__( 'Your payment is still being processed by PayPal and couldn\'t be confirmed. Please contact us before trying again.', 'forminator' ) );
		}

		if (
			'COMPLETED' !== ( $capture->status ?? '' )
			|| (string) ( $capture->amount->value ?? '' ) !== self::format_amount( $amount, $currency )
			|| strtoupper( (string) ( $capture->amount->currency_code ?? '' ) ) !== strtoupper( (string) $currency )
		) {
			return new WP_Error( 'forminator_paypal_capture_failed', esc_html__( 'Payment failed, please try again!', 'forminator' ) );
		}

		return $capture;
	}

	/**
	 * Get transaction link
	 *
	 * @param string $mode Payment mode.
	 * @param string $transaction_id Transaction id.
	 * @return string
	 */
	public static function get_transanction_link( $mode, $transaction_id ) {
		if ( 'sandbox' === $mode ) {
			$link_base = 'https://www.sandbox.paypal.com/activity/payment/';
		} else {
			$link_base = 'https://www.paypal.com/activity/payment/';
		}
		$transaction_link = $link_base . rawurlencode( $transaction_id );

		return $transaction_link;
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
		 * Filter link to PayPal transaction id
		 *
		 * @since 1.7
		 *
		 * @param string $transaction_link
		 * @param string $transaction_id
		 * @param array  $meta_value
		 *
		 * @return string
		 */
		$transaction_link = apply_filters( 'forminator_field_paypal_linkify_transaction_id', $transaction_link, $transaction_id, $meta_value );

		return $transaction_link;
	}

	/**
	 * Get payment description with merge tags applied.
	 *
	 * PayPal limits purchase unit descriptions to 127 characters.
	 *
	 * @since 1.58.0
	 *
	 * @param array $field Field.
	 *
	 * @return string
	 */
	public function get_payment_description( $field ) {
		$description = self::get_property( 'product_description', $field, '' );
		if ( empty( $description ) ) {
			return '';
		}

		$description = forminator_replace_form_data( $description, Forminator_Front_Action::$module_object );
		$description = wp_strip_all_tags( $description );
		$description = trim( $description );

		if ( '' === $description ) {
			return '';
		}

		$description = mb_substr( $description, 0, 127 );

		/**
		 * Filter PayPal payment description before it is sent to PayPal.
		 *
		 * @since 1.58.0
		 *
		 * @param string                        $description   Payment description.
		 * @param array                         $field           PayPal field settings.
		 * @param Forminator_Form_Model|null    $module_object   Form model.
		 */
		return apply_filters( 'forminator_paypal_payment_description', $description, $field, Forminator_Front_Action::$module_object );
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
		$payment_amount = 0.0;
		$amount_type    = self::get_property( 'amount_type', $field, 'fixed' );
		$amount         = self::get_property( 'amount', $field, '0' );
		$amount_var     = self::get_property( 'variable', $field, '' );

		if ( 'fixed' === $amount_type ) {
			$payment_amount = $amount;
		} else {
			$form_field = Forminator_Front_Action::$module_object->get_field( $amount_var, false );
			if ( $form_field ) {
				$form_field = $form_field->to_formatted_array();
				if ( isset( $form_field['type'] ) ) {
					$field_id             = $form_field['element_id'];
					$submitted_field_data = isset( Forminator_CForm_Front_Action::$prepared_data[ $field_id ] )
							? Forminator_CForm_Front_Action::$prepared_data[ $field_id ]
							: null;
					if ( 'calculation' === $form_field['type'] ) {

						// Calculation field get the amount from pseudo_submit_data.
						if ( isset( Forminator_CForm_Front_Action::$prepared_data[ $amount_var ] ) ) {
							$payment_amount = Forminator_CForm_Front_Action::$prepared_data[ $amount_var ];
						}
					} elseif ( 'currency' === $form_field['type'] ) {
						// Currency field get the amount from submitted_data.
						if ( ! is_null( $submitted_field_data ) ) {
							$payment_amount = self::forminator_replace_number( $form_field, $submitted_field_data );
						}
					} else {
						$field_object = Forminator_Core::get_field_object( $form_field['type'] );
						if ( $field_object ) {
							$submitted_field_data = Forminator_CForm_Front_Action::$prepared_data[ $amount_var ] ?? null;
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
		 * Filter payment amount of PayPal
		 *
		 * @since 1.7
		 *
		 * @param double                       $payment_amount
		 * @param array                        $field field settings.
		 * @param Forminator_Form_Model $module_object
		 * @param array                        $prepared_data
		 */
		$payment_amount = apply_filters( 'forminator_field_paypal_payment_amount', $payment_amount, $field, Forminator_Front_Action::$module_object, Forminator_CForm_Front_Action::$prepared_data );

		return $payment_amount;
	}

	/**
	 * Get the fields that an amount depends on
	 *
	 * @param array $field_settings Field settings.
	 * @return array
	 */
	public function get_amount_dependent_fields( $field_settings ) {
		$depend_field = array();
		$amount_type  = self::get_property( 'amount_type', $field_settings, 'fixed' );
		$amount_var   = self::get_property( 'variable', $field_settings, '' );

		if ( 'variable' === $amount_type && ! empty( $amount_var ) ) {
			$depend_field[] = $amount_var;
		}

		return $depend_field;
	}
}
