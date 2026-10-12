<?php
/**
 * The Forminator_Gateway_Stripe class.
 *
 * @package Forminator
 */

// Include class-exception.php.
require_once __DIR__ . '/class-exception.php';

/**
 * Wrapper Stripe
 * Class Forminator_Gateway_Stripe
 *
 * @since 1.7
 */
class Forminator_Gateway_Stripe {

	/**
	 * Stripe Test Pub key
	 *
	 * @var string
	 */
	protected $test_key = '';

	/**
	 * Stripe Test Sec key
	 *
	 * @var string
	 */
	protected $test_secret = '';

	/**
	 * Stripe Test Secret key encrypted
	 *
	 * @var string
	 */
	protected $test_secret_encrypted = '';

	/**
	 * Stripe Live Pub key
	 *
	 * @var string
	 */
	protected $live_key = '';

	/**
	 * Stripe Live Sec key
	 *
	 * @var string
	 */
	protected $live_secret = '';

	/**
	 * Stripe Live Secret key encrypted
	 *
	 * @var string
	 */
	protected $live_secret_encrypted = '';

	/**
	 * Live Mode flag
	 *
	 * @var bool
	 */
	protected $is_live = false;

	/**
	 * Default Currency for Stripe
	 *
	 * @var string
	 */
	protected $default_currency = 'USD';

	/**
	 * Option name for stored Stripe credentials and OAuth metadata.
	 *
	 * @since 1.56.0
	 */
	private const SETTINGS_OPTION = 'forminator_stripe_configuration';

	/**
	 * Webhook events that Forminator is interested in.
	 *
	 * @since 1.56.0
	 */
	public const ENABLED_WEBHOOK_EVENTS = array(
		'checkout.session.async_payment_failed',
		'checkout.session.async_payment_succeeded',
		'checkout.session.completed',
		'checkout.session.expired',
		'payment_intent.processing',
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'setup_intent.setup_failed',
	);

	/**
	 * Forminator_Gateway_Stripe constructor.
	 *
	 * @throws Forminator_Gateway_Exception When there is a Gateway error.
	 */
	public function __construct() {

		if ( ! self::is_available() ) {
			throw new Forminator_Gateway_Exception( esc_html__( 'Stripe not available, please check your WordPress installation for PHP Version and plugin conflicts.', 'forminator' ) );
		}

		$config = self::get_stored_settings();
		if ( ! empty( $config['is_salty'] ) && defined( 'FORMINATOR_ENCRYPTION_KEY' ) ) {
			// Re-encrypt settings after setting FORMINATOR_ENCRYPTION_KEY constant.
			self::reencrypt_settings( $config );
			$config = self::get_stored_settings();
		} elseif ( ( ! empty( $config['test_secret'] ) && empty( $config['test_secret_encrypted'] ) )
				|| ( ! empty( $config['live_secret'] ) && empty( $config['live_secret_encrypted'] ) ) ) {
			// Encrypt secret keys.
			self::store_settings( $config );
			$config = self::get_stored_settings();
		}

		$this->test_key         = isset( $config['test_key'] ) ? $config['test_key'] : '';
		$this->test_secret      = isset( $config['test_secret'] ) ? $config['test_secret'] : '';
		$this->default_currency = isset( $config['default_currency'] ) ? $config['default_currency'] : 'USD';

		if ( empty( $this->test_key ) && defined( 'FORMINATOR_STRIPE_TEST_KEY' ) ) {
			$this->test_key = FORMINATOR_STRIPE_TEST_KEY;
		}

		if ( empty( $this->test_secret ) && defined( 'FORMINATOR_STRIPE_TEST_SECRET' ) ) {
			$this->test_secret = FORMINATOR_STRIPE_TEST_SECRET;
		} else {
			$this->test_secret_encrypted = isset( $config['test_secret_encrypted'] ) ? $config['test_secret_encrypted'] : '';
		}

		$this->live_key    = isset( $config['live_key'] ) ? $config['live_key'] : '';
		$this->live_secret = isset( $config['live_secret'] ) ? $config['live_secret'] : '';

		if ( empty( $this->live_key ) && defined( 'FORMINATOR_STRIPE_LIVE_KEY' ) ) {
			$this->live_key = FORMINATOR_STRIPE_LIVE_KEY;
		}

		if ( empty( $this->live_secret ) && defined( 'FORMINATOR_STRIPE_LIVE_SECRET' ) ) {
			$this->live_secret = FORMINATOR_STRIPE_LIVE_SECRET;
		} else {
			$this->live_secret_encrypted = isset( $config['live_secret_encrypted'] ) ? $config['live_secret_encrypted'] : '';
		}

		/**
		 * Filter CA bundle path to be used on Stripe HTTP Request
		 * Default is WP Core ca bundle path `ABSPATH . WPINC . '/certificates/ca-bundle.crt'`
		 *
		 * @param string
		 *
		 * @return string
		 */
		$stripe_ca_bundle_path = apply_filters( 'forminator_payments_stripe_ca_bundle_path', ABSPATH . WPINC . '/certificates/ca-bundle.crt' );

		\Forminator\Stripe\Stripe::setCABundlePath( $stripe_ca_bundle_path );
	}

	/**
	 * Set Stripe APP info
	 *
	 * @since 1.12
	 */
	public static function set_stripe_app_info() {
		// Send our plugin info over with the API request.
		\Forminator\Stripe\Stripe::setAppInfo(
			'WordPress Forminator',
			FORMINATOR_VERSION,
			FORMINATOR_PRO_URL,
			FORMINATOR_STRIPE_PARTNER_ID
		);

		// Send the API info over.
		\Forminator\Stripe\Stripe::setApiVersion( FORMINATOR_STRIPE_LIB_DATE );
	}

	/**
	 * Is Available
	 *
	 * @return bool
	 */
	public static function is_available() {
		return forminator_payment_lib_stripe_version_loaded();
	}

	/**
	 * Get test key
	 *
	 * @return string
	 */
	public function get_test_key() {
		return $this->test_key;
	}

	/**
	 * Get test secret
	 *
	 * @param bool $decrypted Get decrypted key.
	 * @return string
	 */
	public function get_test_secret( bool $decrypted = false ) {
		if ( $decrypted && ! empty( $this->test_secret_encrypted ) ) {
			return Forminator_Encryption::decrypt( $this->test_secret_encrypted );
		}
		return $this->test_secret;
	}

	/**
	 * Get live key
	 *
	 * @return string
	 */
	public function get_live_key() {
		return $this->live_key;
	}

	/**
	 * Get live secret
	 *
	 * @param bool $decrypted Get decrypted key.
	 * @return string
	 */
	public function get_live_secret( bool $decrypted = false ) {
		if ( $decrypted && ! empty( $this->live_secret_encrypted ) ) {
			return Forminator_Encryption::decrypt( $this->live_secret_encrypted );
		}
		return $this->live_secret;
	}

	/**
	 * Get currency
	 *
	 * @return string
	 */
	public function get_default_currency() {
		return $this->default_currency;
	}

	/**
	 * Is live
	 *
	 * @return bool
	 */
	public function is_live() {
		return $this->is_live;
	}

	/**
	 * Read Stripe settings from the database.
	 *
	 * @since 1.56.0
	 *
	 * @return array
	 */
	private static function get_stored_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Store stripe settings
	 *
	 * @param array $settings Settings.
	 */
	public static function store_settings( array $settings ) {
		$settings = self::prepare_settings( $settings );
		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Store OAuth-issued credentials for a given mode.
	 *
	 * Only the keys for the requested mode are updated; the opposite mode's
	 * credentials (if any) are preserved. OAuth metadata records which mode was
	 * connected via this flow (used for the account label in the settings UI).
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode            'live' or 'test'.
	 * @param string $publishable_key Publishable key (pk_*).
	 * @param string $secret_key      Connected-account secret key (sk_*) from the OAuth access token.
	 * @param string $account_id      Optional. Connected Stripe account id.
	 * @param string $account_name    Optional. Connected Stripe account display name.
	 */
	public static function store_oauth_credentials( $mode, $publishable_key, $secret_key, $account_id = '', $account_name = '' ) {
		$settings = self::get_stored_settings();

		$mode   = self::normalize_oauth_mode( $mode );
		$prefix = self::mode_prefix( $mode );

		$settings[ $prefix . 'key' ]    = $publishable_key;
		$settings[ $prefix . 'secret' ] = $secret_key;

		$oauth             = isset( $settings['oauth'] ) && is_array( $settings['oauth'] ) ? $settings['oauth'] : array();
		$oauth[ $mode ]    = array(
			'account_id'   => (string) $account_id,
			'account_name' => (string) $account_name,
		);
		$settings['oauth'] = $oauth;

		self::store_settings( $settings );
	}

	/**
	 * Remove stored credentials for one mode (keys and OAuth metadata).
	 * Credentials for the opposite mode are preserved.
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode 'live' or 'test'.
	 */
	public static function disconnect_oauth( $mode ) {
		$settings = self::get_stored_settings();

		$mode   = self::normalize_oauth_mode( $mode );
		$prefix = self::mode_prefix( $mode );

		unset( $settings[ $prefix . 'key' ] );
		unset( $settings[ $prefix . 'secret' ] );
		unset( $settings[ $prefix . 'secret_encrypted' ] );

		if ( isset( $settings['oauth'][ $mode ] ) ) {
			unset( $settings['oauth'][ $mode ] );
		}

		if ( isset( $settings['oauth'] ) && empty( $settings['oauth'] ) ) {
			unset( $settings['oauth'] );
		}

		self::store_settings( $settings );
	}

	/**
	 * Whether the given mode is currently connected via OAuth.
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode 'live' or 'test'.
	 *
	 * @return bool
	 */
	public static function is_oauth_connected( $mode ) {
		$settings = self::get_stored_settings();
		$mode     = self::normalize_oauth_mode( $mode );

		return ! empty( $settings['oauth'][ $mode ] );
	}

	/**
	 * Whether the given mode has stored credentials, regardless of whether they
	 * were entered manually or issued via OAuth (both share the same option).
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode 'live' or 'test'.
	 *
	 * @return bool
	 */
	public static function is_mode_configured( $mode ) {
		$settings = self::get_stored_settings();
		$prefix   = self::mode_prefix( $mode );

		$has_key    = ! empty( $settings[ $prefix . 'key' ] );
		$has_secret = ! empty( $settings[ $prefix . 'secret' ] ) || ! empty( $settings[ $prefix . 'secret_encrypted' ] );

		return $has_key && $has_secret;
	}

	/**
	 * Whether Test or Live has stored API keys that were not issued via Stripe Connect.
	 *
	 * Modes are independent, so a manual connection in either mode is enough.
	 *
	 * @since 1.58.0
	 *
	 * @return bool
	 */
	public static function has_manual_api_connection() {
		foreach ( array( 'test', 'live' ) as $mode ) {
			if ( self::is_mode_configured( $mode ) && ! self::is_oauth_connected( $mode ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the webhook endpoint for the given mode is currently active.
	 *
	 * @since 1.58.0
	 *
	 * @param string $mode 'live' or 'test'.
	 *
	 * @return bool
	 */
	public static function is_webhook_active( $mode ) {
		$option_name = self::get_webhook_option_name( $mode );
		$webhook     = get_option( $option_name );
		if ( ! empty( $webhook ) && ! empty( $webhook['id'] ) ) {
			return self::check_webhook_endpoint_is_active( $mode, $webhook['id'] );
		}
		return false;
	}

	/**
	 * Whether the webhook endpoint can be created for the given mode.
	 *
	 * @since 1.58.0
	 *
	 * @param string $mode 'live' or 'test'.
	 *
	 * @return bool
	 */
	public static function allow_webhook_creation( $mode ) {
		$settings = self::get_stored_settings();
		$prefix   = self::mode_prefix( $mode );

		$has_key    = ! empty( $settings[ $prefix . 'key' ] ) && ! empty( $settings[ $prefix . 'secret' ] );
		$secret_key = ! empty( $settings[ $prefix . 'secret' ] ) ? $settings[ $prefix . 'secret' ] : '';

		return $has_key && strpos( $secret_key, 'sk_' ) === 0;
	}

	/**
	 * Check if the webhook endpoint is valid.
	 *
	 * @since 1.58.0
	 *
	 * @param bool $stored_flag Optional. If true, check the stored option instead of making an API call. Default false.
	 * @return bool True if the webhook endpoint is valid, false otherwise.
	 */
	public static function has_valid_webhook( $stored_flag = false ) {
		if ( true === $stored_flag ) {
			$has_valid_webhook = get_option( 'forminator_stripe_webhook_valid', 'invalid' );
			return 'valid' === $has_valid_webhook;
		}
		$status = true;
		if ( ( self::is_mode_configured( 'test' ) && false === self::is_webhook_active( 'test' ) )
			|| ( self::is_mode_configured( 'live' ) && false === self::is_webhook_active( 'live' ) ) ) {
			$status = false;
		}
		update_option( 'forminator_stripe_webhook_valid', $status ? 'valid' : 'invalid', true );
		return $status;
	}

	/**
	 * Check if any of the webhook endpoints (test or live) is valid.
	 *
	 * @since 1.58.0
	 *
	 * @return bool True if any of the webhook endpoints is valid, false otherwise.
	 */
	public static function has_any_valid_webhook() {
		if ( ( self::is_mode_configured( 'test' ) && true === self::is_webhook_active( 'test' ) )
			|| ( self::is_mode_configured( 'live' ) && true === self::is_webhook_active( 'live' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the webhook URL is reachable.
	 *
	 * @since 1.58.0
	 *
	 * @return bool True if the webhook URL is reachable, false otherwise.
	 */
	public static function is_webhook_reachable() {
		$cached_status = get_transient( 'forminator_stripe_webhook_reachable' );
		if ( false !== $cached_status ) {
			return 'enabled' === $cached_status;
		}
		$url      = self::get_webhook_url();
		$response = wp_remote_post( $url );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$status = 'enabled';
		} else {
			$status = 'disabled';
		}
		// Cache the status for 1 day to avoid frequent API calls.
		set_transient( 'forminator_stripe_webhook_reachable', $status, DAY_IN_SECONDS );
		return 'enabled' === $status;
	}

	/**
	 * Check if the webhook endpoint is active by retrieving its status from Stripe.
	 *
	 * @since 1.58.0
	 *
	 * @param string $mode 'live' or 'test'.
	 * @param string $webhook_id The ID of the webhook endpoint to check.
	 *
	 * @return bool True if the webhook endpoint is active, false otherwise.
	 */
	private static function check_webhook_endpoint_is_active( $mode, $webhook_id ) {
		$transient_key = 'forminator_stripe_webhook_endpoint_status_' . $mode . '_' . $webhook_id;
		$cached_status = get_transient( $transient_key );
		if ( false !== $cached_status ) {
			return 'enabled' === $cached_status;
		}
		try {
			$stripe = new self();
			$status = 'disabled';
			if ( 'test' !== $mode ) {
				$stripe->set_live( true );
			}

			$api_key = $stripe->is_live() ? $stripe->get_live_secret( true ) : $stripe->get_test_secret( true );
			\Forminator\Stripe\Stripe::setApiKey( $api_key );
			self::set_stripe_app_info();

			$webhook = \Forminator\Stripe\WebhookEndpoint::retrieve( $webhook_id );

			if ( ! empty( $webhook ) && 'enabled' === $webhook->status ) {
				$enabled_events = $webhook->enabled_events;
				if ( self::get_webhook_url() === $webhook->url && true === self::is_webhook_events_matched( $enabled_events ) ) {
					$status = 'enabled';
				}
			}
			// Cache the status for 1 day to avoid frequent API calls.
			set_transient( $transient_key, $status, DAY_IN_SECONDS );
			return 'enabled' === $status;
		} catch ( Exception $e ) {
			// Cache the status as 'disabled' for 1 day to avoid frequent API calls in case of errors.
			set_transient( $transient_key, 'disabled', DAY_IN_SECONDS );
			forminator_maybe_log(
				__METHOD__,
				array(
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				)
			);
			return false;
		}
	}

	/**
	 * Get the webhook URL for Stripe to send events to.
	 *
	 * @since 1.58.0
	 *
	 * @return string The webhook URL.
	 */
	private static function get_webhook_url() {
		return site_url( '/wp-json/forminator/v1/webhook/stripe' );
	}

	/**
	 * Check if the enabled events of the webhook match the required events.
	 *
	 * @since 1.58.0
	 *
	 * @param array $events The enabled events of the webhook.
	 *
	 * @return bool True if the events match, false otherwise.
	 */
	private static function is_webhook_events_matched( $events ) {
		if ( count( array_intersect( $events, self::ENABLED_WEBHOOK_EVENTS ) ) === count( self::ENABLED_WEBHOOK_EVENTS ) ) {
			foreach ( self::ENABLED_WEBHOOK_EVENTS as $event ) {
				if ( ! in_array( $event, $events, true ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Resolve a human-readable Stripe account name for a connected account.
	 *
	 * @since 1.56.0
	 *
	 * @param string $account_id Connected Stripe account id (acct_*).
	 * @param string $secret_key Secret/restricted key for the connected account.
	 *
	 * @return string
	 */
	public static function resolve_connected_account_name( $account_id, $secret_key ) {
		if ( empty( $account_id ) || empty( $secret_key ) ) {
			return '';
		}

		try {
			\Forminator\Stripe\Stripe::setApiKey( $secret_key );
			self::set_stripe_app_info();

			$account = \Forminator\Stripe\Account::retrieve( $account_id );

			if ( ! empty( $account->business_profile->name ) ) {
				return (string) $account->business_profile->name;
			}

			if ( ! empty( $account->settings->dashboard->display_name ) ) {
				return (string) $account->settings->dashboard->display_name;
			}

			if ( ! empty( $account->company->name ) ) {
				return (string) $account->company->name;
			}
		} catch ( Exception $e ) {
			forminator_maybe_log( __METHOD__, $e->getMessage() );
		}

		return '';
	}

	/**
	 * Retrieve the connected Stripe account display name for the given mode.
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode 'live' or 'test'.
	 *
	 * @return string
	 */
	public static function get_oauth_account_name( $mode ) {
		$settings = self::get_stored_settings();
		$mode     = self::normalize_oauth_mode( $mode );

		if ( empty( $settings['oauth'][ $mode ] ) ) {
			return '';
		}

		if ( ! empty( $settings['oauth'][ $mode ]['account_name'] ) ) {
			return (string) $settings['oauth'][ $mode ]['account_name'];
		}

		if ( ! empty( $settings['oauth'][ $mode ]['account_id'] ) ) {
			return (string) $settings['oauth'][ $mode ]['account_id'];
		}

		return '';
	}

	/**
	 * Normalize OAuth mode string to either live or test.
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode Raw mode value.
	 *
	 * @return string
	 */
	public static function normalize_oauth_mode( $mode ) {
		return 'live' === $mode ? 'live' : 'test';
	}

	/**
	 * Settings-array key prefix ('test_' or 'live_') for a Stripe mode.
	 *
	 * @since 1.56.0
	 *
	 * @param string $mode Raw mode value.
	 *
	 * @return string
	 */
	public static function mode_prefix( $mode ) {
		return 'test' === self::normalize_oauth_mode( $mode ) ? 'test_' : 'live_';
	}

	/**
	 * Store default currency
	 *
	 * @param string $currency Currency.
	 */
	public static function store_default_currency( string $currency ) {
		$settings                     = self::get_stored_settings();
		$settings['default_currency'] = $currency;
		update_option( self::SETTINGS_OPTION, $settings );
	}

	/**
	 * Encrypt secret keys
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function prepare_settings( array $settings ): array {
		$symbols_to_save = array( 8, 4 );
		$keys_to_encrypt = array();

		foreach ( array( 'test_secret', 'live_secret' ) as $secret_key ) {
			// Only encrypt the secret key if it is not empty and does not already contain masked characters.
			// This prevents re-encrypting an already encrypted key or encrypting a masked key.
			// We must encrypt the key even if _encrypted is already set because reencrypt_settings() leaves the unencrypted key in place while migrating the encrypted key based on the FORMINATOR_ENCRYPTION_KEY constant.
			if (
				! empty( $settings[ $secret_key ] )
				&& false === strpos( $settings[ $secret_key ], '**********' )
			) {
				$keys_to_encrypt[] = $secret_key;
			}
		}

		return Forminator_Encryption::encrypt_secret_keys(
			$keys_to_encrypt,
			$settings,
			$symbols_to_save
		);
	}

	/**
	 * Re-encrypt settings
	 *
	 * @param array $settings Settings.
	 */
	public static function reencrypt_settings( array $settings ) {
		foreach ( $settings as $key => $val ) {
			if ( in_array( $key, array( 'test_secret_encrypted', 'live_secret_encrypted' ), true ) ) {
				$k              = str_replace( '_encrypted', '', $key );
				$settings[ $k ] = Forminator_Encryption::decrypt( $val, true );
			}
		}

		self::store_settings( $settings );
	}

	/**
	 * Is live ready
	 *
	 * @return bool
	 */
	public function is_live_ready() {
		return ! empty( $this->live_key ) && ! empty( $this->live_secret );
	}

	/**
	 * Is test ready
	 *
	 * @return bool
	 */
	public function is_test_ready() {
		return ! empty( $this->test_key ) && ! empty( $this->test_secret );
	}

	/**
	 * Is ready
	 *
	 * @return bool
	 */
	public function is_ready() {
		if ( $this->is_live ) {
			return $this->is_live_ready();
		}

		return $this->is_test_ready();
	}

	/**
	 * Set live
	 *
	 * @param bool $live Live?.
	 */
	public function set_live( $live ) {
		$this->is_live = $live;
	}

	/**
	 * Charge
	 *
	 * @param mixed $data Data.
	 *
	 * @return \Forminator\Stripe\ApiResource
	 */
	public function charge( $data ) {
		$api_key = $this->is_live() ? $this->get_live_secret( true ) : $this->get_test_secret( true );
		\Forminator\Stripe\Stripe::setApiKey( $api_key );
		self::set_stripe_app_info();

		return \Forminator\Stripe\Charge::create( $data );
	}

	/**
	 * Retrieve ifo from token
	 *
	 * @param string $token Token.
	 *
	 * @return \Forminator\Stripe\StripeObject
	 */
	public function retrieve_info_from_token( $token ) {
		$api_key = $this->is_live() ? $this->get_live_secret( true ) : $this->get_test_secret( true );
		\Forminator\Stripe\Stripe::setApiKey( $api_key );
		self::set_stripe_app_info();

		return \Forminator\Stripe\Token::retrieve( $token );
	}

	/**
	 * Get the exception error and return WP_Error
	 *
	 * @param mixed $e Exception.
	 *
	 * @since 1.15
	 *
	 * @return WP_Error
	 */
	public function get_error( $e ) {
		$code = $e->getCode();

		if ( is_int( $code ) ) {
			$code = ( 0 === $code ) ? 'zero' : $code;

			return new WP_Error( $code, $e->getMessage() );
		} else {
			return new WP_Error( $e->getError()->code, $e->getError()->message );
		}
	}

	/**
	 * Maybe create webhook endpoints
	 *
	 * @return void
	 */
	public static function maybe_create_webhook_endpoint() {
		$modes = array( 'test', 'live' );
		foreach ( $modes as $mode ) {
			if ( self::allow_webhook_creation( $mode ) ) {
				$option_name = self::get_webhook_option_name( $mode );
				if ( ! get_option( $option_name ) ) {
					self::create_webhook_endpoint( $mode );
				}
			}
		}
	}

	/**
	 * Get webhook option name
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function get_webhook_option_name( $mode ) {
		return ( 'live' === $mode ) ? 'forminator_stripe_webhook_endpoint_live' : 'forminator_stripe_webhook_endpoint_test';
	}

	/**
	 * Create webhook endpoint
	 *
	 * @param string $mode Mode.
	 * @return mixed
	 */
	public static function create_webhook_endpoint( $mode ) {
		$option_name = self::get_webhook_option_name( $mode );
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( 'test' !== $mode ) {
				$stripe->set_live( true );
			}

			$api_key = $stripe->is_live() ? $stripe->get_live_secret( true ) : $stripe->get_test_secret( true );
			$url     = self::get_webhook_url();

			\Forminator\Stripe\Stripe::setApiKey( $api_key );
			self::set_stripe_app_info();

			// Create a new webhook endpoint with the specified URL and events.
			$webhook = \Forminator\Stripe\WebhookEndpoint::create(
				array(
					'url'            => $url,
					'enabled_events' => self::ENABLED_WEBHOOK_EVENTS,
					'api_version'    => FORMINATOR_STRIPE_LIB_DATE,
				)
			);

			if ( ! empty( $webhook->id ) ) {
				$data = array(
					'id'             => $webhook->id,
					'secret'         => $webhook->secret,
					'enabled_events' => $webhook->enabled_events,
				);
				update_option( $option_name, $data, false );

				// Deletes any previously configured webhooks with the same URL but different ID.
				self::delete_previously_created_webhooks( $mode, $webhook->id );
			}
			return true;
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				)
			);
			return $e->getMessage();
		}
	}

	/**
	 * Delete previously created webhooks with the same URL but different ID.
	 *
	 * @param string $mode Mode.
	 * @param string $webhook_id Webhook ID.
	 * @return bool
	 */
	private static function delete_previously_created_webhooks( $mode, $webhook_id ) {
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( 'test' !== $mode ) {
				$stripe->set_live( true );
			}

			$api_key = $stripe->is_live() ? $stripe->get_live_secret( true ) : $stripe->get_test_secret( true );
			$url     = self::get_webhook_url();

			\Forminator\Stripe\Stripe::setApiKey( $api_key );
			self::set_stripe_app_info();

			$webhooks = \Forminator\Stripe\WebhookEndpoint::all();

			if ( ! empty( $webhooks->data ) ) {
				foreach ( $webhooks->data as $webhook ) {
					if ( $webhook->id !== $webhook_id && $webhook->url === $url ) {
						$webhook->delete();
					}
				}
			}
			return true;
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				)
			);
			return false;
		}
	}

	/**
	 * Delete webhook endpoint
	 *
	 * @param string $mode Mode.
	 * @return bool
	 */
	public static function delete_webhook_endpoint( $mode ) {
		$option_name    = self::get_webhook_option_name( $mode );
		$webhook_option = get_option( $option_name, false );
		if ( empty( $webhook_option ) || empty( $webhook_option['id'] ) ) {
			return false;
		}
		$webhook_id = $webhook_option['id'];
		try {
			$stripe = new Forminator_Gateway_Stripe();

			if ( 'test' !== $mode ) {
				$stripe->set_live( true );
			}

			$api_key = $stripe->is_live() ? $stripe->get_live_secret( true ) : $stripe->get_test_secret( true );
			$url     = self::get_webhook_url();

			\Forminator\Stripe\Stripe::setApiKey( $api_key );
			self::set_stripe_app_info();

			delete_option( $option_name );
			$webhook = \Forminator\Stripe\WebhookEndpoint::retrieve( $webhook_id );
			// Delete the webhook only if it exists and matches the expected URL.
			if ( ! empty( $webhook ) && ! empty( $webhook->url ) && $webhook->url === $url ) {
				$webhook->delete();
			}
			return true;
		} catch ( Exception $e ) {
			forminator_maybe_log(
				__METHOD__,
				array(
					'error_class'   => get_class( $e ),
					'error_message' => $e->getMessage(),
					'error_code'    => $e->getCode(),
				)
			);
		}
		return false;
	}
}
