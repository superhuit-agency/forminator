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
			$encrypted_key = $secret_key . '_encrypted';

			if (
				! empty( $settings[ $secret_key ] )
				&& empty( $settings[ $encrypted_key ] )
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
}
