<?php
/**
 * Forminator Stripe Connect OAuth (thin client → handler `provider` endpoint).
 *
 * Mirrors the HubSpot integration: the customer site never holds the platform
 * Stripe credentials. It sends the admin to the handler's `provider` endpoint
 * (action=connect), which redirects to Stripe, brings the code back through an
 * intermediate page, and exchanges it for the connected-account keys.
 *
 * @package Forminator
 * @since   1.56.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_Stripe_Connect
 *
 * @since 1.56.0
 */
class Forminator_Stripe_Connect {

	/**
	 * Handler `provider` endpoint path (relative to API base).
	 */
	const HANDLER_ENDPOINT = 'api/forminator/v1/provider';

	const CALLBACK_QUERY_MODE    = 'forminator_stripe_mode';
	const CALLBACK_QUERY_ERROR   = 'forminator_stripe_error';
	const CALLBACK_QUERY_SUCCESS = 'forminator_stripe_connected';
	const NONCE_ACTION           = 'forminator_stripe_oauth';

	/**
	 * Singleton instance.
	 *
	 * @var Forminator_Stripe_Connect|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Forminator_Stripe_Connect
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_handle_redirect' ) );
	}

	/**
	 * Whether the site supports SSL (required for live OAuth).
	 *
	 * @return bool
	 */
	public static function site_has_ssl() {
		if ( is_ssl() ) {
			return true;
		}
		if ( function_exists( 'wp_is_using_https' ) && wp_is_using_https() ) {
			return true;
		}
		return 0 === strpos( (string) home_url(), 'https://' );
	}

	/**
	 * Stripe payments settings admin URL.
	 *
	 * @return string
	 */
	public static function get_settings_page_url() {
		return admin_url( 'admin.php?page=forminator-settings&section=payments' );
	}

	/**
	 * Get the handler `provider` endpoint URL.
	 *
	 * @since 1.56.0
	 *
	 * @return string Full handler URL.
	 */
	public static function get_handler_url() {
		return forminator_get_server_url( self::HANDLER_ENDPOINT );
	}

	/**
	 * Build the handler connect URL the admin is redirected to.
	 *
	 * @param string $mode       Stripe mode: live or test.
	 * @param string $return_url Optional admin URL to return to after OAuth.
	 *
	 * @return string|WP_Error
	 */
	public function get_oauth_url( $mode = 'live', $return_url = '' ) {
		$mode = Forminator_Gateway_Stripe::normalize_oauth_mode( $mode );

		if ( 'live' === $mode && ! self::site_has_ssl() ) {
			return new WP_Error(
				'forminator_stripe_oauth_requires_ssl',
				esc_html__( 'Live mode requires a valid SSL certificate. Please enable SSL on your site to connect a live Stripe account.', 'forminator' )
			);
		}

		if ( empty( $return_url ) ) {
			$return_url = self::get_settings_page_url();
		}

		// Carry the mode back so the redirect handler stores the right keys.
		$return_url = add_query_arg( self::CALLBACK_QUERY_MODE, $mode, $return_url );

		// state = wpnonce|urlencoded(return_url). Encoding the return URL keeps its
		// own query string intact through the round trip (same as HubSpot does).
		$state = wp_create_nonce( self::NONCE_ACTION ) . '|' . rawurlencode( $return_url );

		return add_query_arg(
			array(
				'provider' => 'stripe',
				'action'   => 'connect',
				'mode'     => $mode,
				'state'    => $state,
			),
			self::get_handler_url()
		);
	}

	/**
	 * Handle the redirect back from the handler's intermediate page: verify the
	 * nonce, exchange the authorization code, and store the keys locally.
	 */
	public function maybe_handle_redirect() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';
		if ( 'stripe' !== $provider ) {
			return;
		}

		if ( ! current_user_can( forminator_get_admin_cap() ) ) {
			return;
		}

		$mode = $this->get_redirect_mode();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_with_notice( 'error', __( 'Invalid security token. Please try connecting again.', 'forminator' ), $mode );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		if ( '' !== $error ) {
			$this->redirect_with_notice( 'error', $this->humanize_error( $error ), $mode );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect_with_notice( 'error', __( 'Stripe did not return an authorization code.', 'forminator' ), $mode );
			return;
		}

		$result = $this->exchange_code( $code, $mode );
		if ( is_wp_error( $result ) ) {
			forminator_maybe_log( __METHOD__, $result->get_error_message() );
			$this->redirect_with_notice( 'error', $result->get_error_message(), $mode );
			return;
		}

		$this->redirect_with_notice( 'success', '', $mode );
	}

	/**
	 * Exchange the authorization code for connected-account keys and store them.
	 *
	 * @param string $code Authorization code from Stripe.
	 * @param string $mode Stripe mode: live or test.
	 *
	 * @return true|WP_Error
	 */
	public function exchange_code( $code, $mode = 'live' ) {
		$mode = Forminator_Gateway_Stripe::normalize_oauth_mode( $mode );

		$response = $this->handler_request(
			array(
				'action' => 'get_access_token',
				'code'   => $code,
				'mode'   => $mode,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response->error ) ) {
			return new WP_Error( 'forminator_stripe_oauth_failed', $this->humanize_error( (string) $response->error ) );
		}

		if ( empty( $response->publishableKey ) || empty( $response->secretKey ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return new WP_Error(
				'forminator_stripe_oauth_invalid_keys',
				__( 'Stripe Connect handler did not return valid API keys.', 'forminator' )
			);
		}

		$stripe_user_id = isset( $response->stripeUserId ) ? (string) $response->stripeUserId : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$secret_key     = (string) $response->secretKey; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$save_mode      = ! empty( $response->mode ) ? Forminator_Gateway_Stripe::normalize_oauth_mode( (string) $response->mode ) : $mode;

		$account_name = Forminator_Gateway_Stripe::resolve_connected_account_name( $stripe_user_id, $secret_key );

		Forminator_Gateway_Stripe::store_oauth_credentials(
			$save_mode,
			$response->publishableKey, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$secret_key,
			$stripe_user_id,
			$account_name
		);

		return true;
	}

	/**
	 * Disconnect a mode by clearing this site's local credentials only.
	 *
	 * We deliberately do NOT deauthorize the account on the Stripe platform.
	 * The same connected account can be shared across many sites through the
	 * same handler (platform), and an OAuth deauthorize revokes the platform's
	 * access to that account globally - which would break every other site
	 * still using it. Removing the local credentials disconnects only this
	 * site; platform access can still be revoked from the Stripe Dashboard.
	 *
	 * @param string $mode Stripe mode: live or test.
	 *
	 * @return void
	 */
	public function disconnect( $mode ) {
		$mode = Forminator_Gateway_Stripe::normalize_oauth_mode( $mode );

		Forminator_Gateway_Stripe::disconnect_oauth( $mode );
	}

	/**
	 * Read Stripe mode from the redirect query args.
	 *
	 * @return string
	 */
	protected function get_redirect_mode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mode only; nonce verified in maybe_handle_redirect().
		$mode = isset( $_GET[ self::CALLBACK_QUERY_MODE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CALLBACK_QUERY_MODE ] ) ) : 'live';

		return Forminator_Gateway_Stripe::normalize_oauth_mode( $mode );
	}

	/**
	 * Redirect to settings with a clean URL plus success or error query args.
	 *
	 * Error messages are sanitized once here before being added to the query string.
	 *
	 * @param string $status  success or error.
	 * @param string $message Optional error message.
	 * @param string $mode    Stripe mode: live or test.
	 */
	protected function redirect_with_notice( $status, $message = '', $mode = 'live' ) {
		$mode = Forminator_Gateway_Stripe::normalize_oauth_mode( $mode );

		$redirect_url = remove_query_arg(
			array(
				self::CALLBACK_QUERY_MODE,
				self::CALLBACK_QUERY_ERROR,
				self::CALLBACK_QUERY_SUCCESS,
				'provider',
				'action',
				'code',
				'error',
				'error_description',
				'wpnonce',
				'domain',
			),
			self::get_settings_page_url()
		);

		if ( 'success' === $status ) {
			$redirect_url = add_query_arg(
				array(
					self::CALLBACK_QUERY_SUCCESS => '1',
					self::CALLBACK_QUERY_MODE    => $mode,
				),
				$redirect_url
			);
		} else {
			$error_param = '' !== $message ? $message : $status;

			$redirect_url = add_query_arg(
				array(
					self::CALLBACK_QUERY_ERROR => sanitize_text_field( $error_param ),
					self::CALLBACK_QUERY_MODE  => $mode,
				),
				$redirect_url
			);
		}

		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * GET a Stripe action from the handler `provider` endpoint.
	 *
	 * @param array<string, string> $args Query args (action + payload).
	 *
	 * @return object|WP_Error
	 */
	protected function handler_request( array $args ) {
		$args = array_merge( array( 'provider' => 'stripe' ), $args );

		$response = wp_remote_get(
			add_query_arg( $args, self::get_handler_url() ),
			array(
				'timeout' => 60,
				'headers' => array(
					'User-Agent' => 'Forminator/' . ( defined( 'FORMINATOR_VERSION' ) ? FORMINATOR_VERSION : '1' ) . '; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			forminator_maybe_log( __METHOD__, $response->get_error_message() );
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_object( $decoded ) && isset( $decoded->message )
				? (string) $decoded->message
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Stripe Connect handler returned HTTP %d.', 'forminator' ), $code );
			return new WP_Error( 'forminator_stripe_connect_handler_error', $message, array( 'status' => $code ) );
		}

		if ( ! is_object( $decoded ) ) {
			return new WP_Error(
				'forminator_stripe_connect_invalid_response',
				__( 'Unexpected response from the Stripe Connect handler.', 'forminator' )
			);
		}

		return $decoded;
	}

	/**
	 * Map handler error slugs to readable messages.
	 *
	 * @param string $error Error slug or message from the handler.
	 *
	 * @return string
	 */
	protected function humanize_error( $error ) {
		$map = array(
			'failed_request'      => __( 'Stripe Connect request failed. Please try again.', 'forminator' ),
			'mode_not_configured' => __( 'Stripe Connect isn’t available right now.', 'forminator' ),
			'not_configured'      => __( 'Stripe Connect is not configured for this mode.', 'forminator' ),
			'invalid_keys'        => __( 'Stripe Connect handler did not return valid API keys.', 'forminator' ),
			'access_denied'       => __( 'Stripe authorization was cancelled.', 'forminator' ),
		);

		return isset( $map[ $error ] ) ? $map[ $error ] : $error;
	}
}
