<?php
/**
 * Forminator Handle Webhooks
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Handle forminator webhooks
 *
 * @since  1.56
 * @access public
 */
class Forminator_Handle_Webhooks {

	/**
	 * Plugin instance
	 *
	 * @var null|Forminator_Handle_Webhooks
	 */
	private static $instance = null;

	/**
	 * Return the plugin instance
	 *
	 * @return Forminator_Handle_Webhooks
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Forminator_Handle_Webhooks constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_webhook_api' ) );
	}

	/**
	 * Register the webhook API route
	 */
	public function register_webhook_api() {
		register_rest_route(
			'forminator/v1',
			'/webhook/stripe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_stripe_webhook_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle the webhook request from Stripe
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 * @throws Exception If the request is invalid or processing fails.
	 */
	public function handle_stripe_webhook_request( $request ) {
		$live_mode        = $request->get_param( 'livemode' );
		$response_message = esc_html__( 'Invalid request.', 'forminator' );
		$success          = false;
		$retry_webhook    = false;
		if ( is_null( $live_mode ) ) {
			return $this->get_response(
				array(
					'message' => $response_message,
					'success' => $success,
				)
			);
		}
		$mode            = true === $live_mode ? 'live' : 'test';
		$option          = get_option( Forminator_Gateway_Stripe::get_webhook_option_name( $mode ) );
		$endpoint_secret = $option['secret'] ?? '';
		if ( ! empty( $endpoint_secret ) && ! empty( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) {
			$signature  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) );
			$payload    = $request->get_body();
			$session_id = null;
			$locked     = null;
			try {
				// Validate the webhook signature and construct the event object.
				$event = \Forminator\Stripe\Webhook::constructEvent(
					$payload,
					$signature,
					$endpoint_secret
				);

				if ( ! in_array( $event->type, Forminator_Gateway_Stripe::ENABLED_WEBHOOK_EVENTS, true ) ) {
					throw new Exception( esc_html__( 'Webhook event type not supported.', 'forminator' ) );
				}

				switch ( $event->type ) {
					case 'payment_intent.processing':
					case 'payment_intent.succeeded':
					case 'payment_intent.payment_failed':
						$payment_intent          = $event->data->object;
						$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );
						// Get the Stripe session ID associated with the payment intent.
						$session = $forminator_stripe_field->get_checkout_session_by_payment_intent( $payment_intent->id, $mode );
						if ( ! empty( $session ) ) {
							$session_id = $session->id;
						}
						break;
					case 'setup_intent.setup_failed':
						// Handle failed setup for subscriptions with trials that do not have a payment intent.
						$setup_intent = $event->data->object;
						$customer_id  = $setup_intent->customer ?? '';
						if ( ! empty( $customer_id ) ) {
							$forminator_stripe_field = Forminator_Core::get_field_object( 'stripe' );
							// Get the Stripe session ID associated with the customer id.
							$session = $forminator_stripe_field->get_checkout_session_by_customer( $customer_id, $mode );
							if ( ! empty( $session ) ) {
								$session_id = $session->id;
							}
						}
						break;
					default:
						$session    = $event->data->object;
						$session_id = $session->id;
						break;
				}
				if ( empty( $session_id ) ) {
					throw new Exception( esc_html__( 'Session ID not found in the event data.', 'forminator' ) );
				}

				// Get the entry ID associated with the Stripe session ID.
				$entry_id = Forminator_Form_Entry_Model::get_entry_id_by_stripe_session_id( $session_id );
				if ( empty( $entry_id ) ) {
					throw new Exception( esc_html__( 'Entry not found for the given session ID.', 'forminator' ) );
				}

				$entry = new Forminator_Form_Entry_Model( $entry_id );
				if ( empty( $entry ) || empty( $entry->entry_id ) || ! in_array( $entry->entry_type, array( 'custom-forms', 'quizzes' ), true ) ) {
					throw new Exception( esc_html__( 'Entry not found or invalid entry type.', 'forminator' ) );
				}

				$user_data       = $entry->get_meta( '_user_login_data', false );
				$user_auto_login = $entry->get_meta( '_user_registration_automatic_login', false );
				if ( ( ! empty( $user_data ) || ! empty( $user_auto_login ) ) && in_array( $event->type, array( 'payment_intent.succeeded', 'checkout.session.completed' ), true ) ) {
					// If the entry is associated with a login form or registration with auto-login, we do not need to process the webhook for successful payment events. We can skip the processing and return a success response.
					// The success status can be handled on the form page, as it requires the user to log in.
					return $this->get_response(
						array(
							'message' => esc_html__( 'Webhook received successfully.', 'forminator' ),
							'success' => true,
						)
					);
				}

				// Lock the webhook processing for this entry to prevent concurrent processing.
				$locked = $this->lock_webhook_processing( $entry );
				if ( true === $locked ) {
					$retry_webhook = true;
					throw new Exception( esc_html__( 'Webhook processing is already in progress for this entry.', 'forminator' ) );
				}

				$form_action = Forminator_CForm_Front_Action::get_instance();

				// Handle the Stripe webhook for the entry.
				$result = $form_action->handle_stripe_webhook( $entry, $event, $session );
				if ( false === $result ) {
					$retry_webhook = true;
					throw new Exception( esc_html__( 'Failed during webhook processing.', 'forminator' ) );
				}
				$response_message = esc_html__( 'Webhook received successfully.', 'forminator' );
				$success          = true;
			} catch ( \Forminator\Stripe\Exception\SignatureVerificationException $e ) {
				$response_message = esc_html__( 'Webhook error while validating signature.', 'forminator' );
				forminator_maybe_log(
					__METHOD__,
					array(
						'message'   => $e->getMessage(),
						'signature' => $signature,
						'payload'   => $payload,
					),
				);
			} catch ( Exception $e ) {
				$response_message = $e->getMessage();
				forminator_maybe_log(
					__METHOD__,
					array(
						'message' => $e->getMessage(),
						'payload' => $payload,
					),
				);
			} finally {
				// Unlock the webhook processing only if the entry is valid and the lock has been acquired.
				if ( ! empty( $entry ) && ! empty( $entry->entry_id ) && false === $locked ) {
					// Unlock the webhook processing for this entry after processing is complete.
					$this->unlock_webhook_processing( $entry );
				}
			}
		}
		// If the webhook processing is locked, we want to return a 400 response to Stripe so that it retries the webhook later.
		// For other errors, we return a 200 response to Stripe to prevent it from retrying the webhook.
		$webhook_response_status = $retry_webhook ? false : true;
		return $this->get_response(
			array(
				'message' => $response_message,
				'success' => $success,
			),
			$webhook_response_status
		);
	}

	/**
	 * Lock the webhook processing for the given entry to prevent concurrent processing.
	 *
	 * @param Forminator_Form_Entry_Model $entry The entry object.
	 */
	public function lock_webhook_processing( $entry ) {
		if ( $this->is_webhook_processing_locked( $entry ) ) {
			// If the lock already exists, return early to prevent concurrent processing.
			return true;
		}
		$lock_time   = 2 * MINUTE_IN_SECONDS; // Lock for 2 minutes.
		$lock_time   = apply_filters( 'forminator_stripe_webhook_processing_lock_time', $lock_time );
		$expire_time = ( time() + $lock_time );

		$entry->add_meta( '_stripe_webhook_processing_lock', $expire_time );

		// This is a safeguard to ensure that we don't allow concurrent processing.
		$result = add_option( 'forminator_stripe_webhook_processing_lock_' . $entry->entry_id, $expire_time, '', 'no' );
		if ( ! $result ) {
			$expiration = get_option( 'forminator_stripe_webhook_processing_lock_' . $entry->entry_id, false );
			// If the lock does not exist, return true to block parallel processing.
			if ( false === $expiration ) {
				return true;
			}
			// If the lock is still active, return true.
			if ( time() <= (int) $expiration ) {
				return true;
			} else {
				// If the lock has expired, delete the lock option and try to acquire the lock again.
				delete_option( 'forminator_stripe_webhook_processing_lock_' . $entry->entry_id );
				$result = add_option( 'forminator_stripe_webhook_processing_lock_' . $entry->entry_id, $expire_time, '', 'no' );
				if ( ! $result ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Check if the webhook processing is locked for the given entry.
	 *
	 * @param Forminator_Form_Entry_Model $entry The entry object.
	 * @return bool True if the processing is already locked, false otherwise.
	 */
	public function is_webhook_processing_locked( $entry ) {
		$expiration = $entry->get_meta( '_stripe_webhook_processing_lock', false, true );

		// If the lock does not exist, return false.
		if ( false === $expiration ) {
			return false;
		}

		// If the lock is still active, return true.
		if ( time() <= (int) $expiration ) {
			return true;
		} else {
			// If the lock has expired, delete the lock meta to allow future processing attempts.
			$entry->delete_meta_by_key( '_stripe_webhook_processing_lock' );
		}
		return false;
	}

	/**
	 * Unlock the webhook processing for the given entry after processing is complete.
	 *
	 * @param Forminator_Form_Entry_Model $entry The entry object.
	 */
	public function unlock_webhook_processing( $entry ) {
		delete_option( 'forminator_stripe_webhook_processing_lock_' . $entry->entry_id );
		if ( ! empty( $entry->status ) && 'pending' === $entry->status ) {
			// If the entry is still pending, we can remove the lock meta to allow immediate future processing attempts.
			$entry->delete_meta_by_key( '_stripe_webhook_processing_lock' );
		}
	}

	/**
	 * Get response for the webhook request
	 *
	 * @param mixed $data Response data.
	 * @param bool  $success Whether the request was successful or not.
	 * @return WP_REST_Response
	 */
	public function get_response( $data, $success = true ) {
		$status_code = $success ? 200 : 400;
		return new WP_REST_Response( $data, $status_code );
	}
}
