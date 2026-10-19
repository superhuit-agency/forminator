<?php
/**
 * Forminator Fields
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_Fields
 *
 * @since 1.0
 */
class Forminator_Fields {
	/**
	 * Store fields objects
	 *
	 * @var array
	 */
	public $fields = array();

	/**
	 * Forminator_Fields constructor.
	 *
	 * @since 1.0
	 */
	public function __construct() {

		$this->load_forminator_autofill_providers();
		$this->maybe_load_external_autofill_providers();

		$loader = new Forminator_Loader();

		$fields = $loader->load_files(
			'library/fields',
			array(
				'stripe.php' => array(
					'php' => '5.6.0',
				),
			)
		);

		/**
		 * Filters the form fields
		 */
		$this->fields = apply_filters( 'forminator_fields', $fields );

		add_action( 'init', array( &$this, 'schedule_forminator_daily_cron' ) );

		add_action( 'forminator_daily_cron', array( &$this, 'cron_init' ) );

		add_action( 'forminator_update_version', array( &$this, 'upgrade_actions' ), 10, 2 );

		if ( ! Forminator_Gateway_Stripe::has_valid_webhook( true ) ) {
			add_action( 'init', array( $this, 'schedule_forminator_stripe_cron' ) );
			add_action( 'forminator_update_stripe_payment_status_cron', array( $this, 'update_stripe_payment_status_cron' ) );
		} else {
			add_action(
				'wp_loaded',
				function () {
					if ( function_exists( 'as_unschedule_action' ) ) {
						as_unschedule_action( 'forminator_update_stripe_payment_status_cron', array(), 'forminator' );
						delete_transient( 'forminator_update_stripe_payment_status_cron' );
					}
				}
			);
		}
	}

	/**
	 * Retrieve fields objects
	 *
	 * @since 1.0
	 * @return array
	 */
	public function get_fields() {
		return $this->fields;
	}

	/**
	 * Load autofill providers requirements
	 *
	 * @since 1.0.5
	 */
	public function load_forminator_autofill_providers() {
		include_once forminator_plugin_dir() . 'library/class-autofill-loader.php';
		$required_files = array(
			// load contracts.
			forminator_plugin_dir() . 'library/field-autofill-providers/contracts/class-autofill-provider-interface.php',
			forminator_plugin_dir() . 'library/field-autofill-providers/contracts/class-autofill-provider-abstract.php',
			// load Forminator provider autoload.
			forminator_plugin_dir() . 'library/field-autofill-providers/autoload.php',
		);

		$required_files_exists = true;
		foreach ( $required_files as $required_file ) {
			if ( ! file_exists( $required_file ) ) {
				$required_files_exists = false;
				break;
			}
		}

		if ( $required_files_exists ) {
			foreach ( $required_files as $required_file ) {
				/* @noinspection PhpIncludeInspection */
				include_once $required_file;
			}
		}
	}

	/**
	 * Load member's autofill provider
	 *
	 * @since 1.0.5
	 */
	public function maybe_load_external_autofill_providers() {
		// See samples/forminator-simple-autofill-plugin for example how to use it.
		do_action( 'forminator_register_autofill_provider' );
	}

	/**
	 * Set up the schedule delete file
	 *
	 * @since 1.13
	 * @since 1.27 Change from WP cron to Action Scheduler
	 */
	public function schedule_forminator_daily_cron() {
		forminator_set_recurring_action( 'forminator_daily_cron', DAY_IN_SECONDS );
	}

	/**
	 * Set up the schedule update Stripe payment status cron
	 *
	 * @since 1.58
	 */
	public function schedule_forminator_stripe_cron() {
		forminator_set_recurring_action( 'forminator_update_stripe_payment_status_cron', HOUR_IN_SECONDS );
	}

	/**
	 * Forminator Cron
	 *
	 * @since 1.13
	 */
	public function cron_init() {
		$this->schedule_delete_temp_files();
		$this->cleanup_stripe_checkout_sessions();
		// Check if the Stripe webhook is valid and update the option accordingly.
		Forminator_Gateway_Stripe::has_valid_webhook();
	}

	/**
	 * Cleanup abandoned Stripe Checkout Session markers.
	 *
	 * @since 1.56
	 *
	 * @return void
	 */
	public function cleanup_stripe_checkout_sessions() {
		$stripe_field = Forminator_Core::get_field_object( 'stripe' );

		if ( is_object( $stripe_field ) && method_exists( $stripe_field, 'cleanup_checkout_sessions' ) ) {
			$stripe_field->cleanup_checkout_sessions();
		}
	}

	/**
	 * Schedule delete temp file
	 *
	 * @since 1.13
	 */
	public function schedule_delete_temp_files() {
		$temp_path = forminator_upload_root_temp();
		if ( is_wp_error( $temp_path ) ) {
			return;
		}

		Forminator_Upload_Access::protect_temp_directory();

		$temp_path = $temp_path . '/';
		$handle    = @opendir( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $handle ) {
			// Check if the dir exist before opening it.
			if ( is_dir( $temp_path ) ) {
				$handle = opendir( $temp_path );
				if ( $handle ) {
					$ttl = Forminator_Upload_Access::get_ttl();
					while ( false !== ( $file = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- false positive
						if ( ! empty( $file ) && ! in_array( $file, array( '.', '..', 'index.php', '.htaccess' ), true ) ) {
							$temp_file = $temp_path . $file;
							$file_time = filemtime( $temp_file );
							if ( file_exists( $temp_file ) && ( time() - $file_time ) >= $ttl ) {
								wp_delete_file( $temp_file );
							}
						}
					}
					closedir( $handle );
				}
			}
		}
	}

	/**
	 * Upgrade actions
	 */
	public function upgrade_actions() {
		if ( ! forminator_create_index_file_disabled() ) {
			$this->add_security_files();
		}
		$this->delete_old_temp_dir();
		Forminator_Export::cleanup_leftover_public_export_files();
	}

	/**
	 * Delete old temp dir
	 *
	 * @return void|null
	 */
	public function delete_old_temp_dir() {
		$dir       = wp_upload_dir();
		$temp_path = trailingslashit( $dir['basedir'] ) . 'forminator_temp';

		if ( $dir['error'] || ! is_dir( $temp_path ) ) {

			return null;
		}

		// Ensure the WP_Filesystem is initialized.
		if ( ! function_exists( 'wp_filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		// Global $wp_filesystem should be available now.
		global $wp_filesystem;

		// remove `css` folder.
		if ( $wp_filesystem->is_dir( $temp_path ) ) {
			$wp_filesystem->rmdir( $temp_path );
		}
	}

	/**
	 * Adds index files to the upload root for security.
	 *
	 * @return void
	 */
	public function add_security_files() {

		$upload_root = forminator_upload_root();

		if ( is_wp_error( $upload_root ) || ! is_dir( $upload_root ) ) {
			return;
		}

		Forminator_Field::check_upload_root_index_file();

		Forminator_Field::add_index_file( $upload_root . 'css' );
		Forminator_Field::add_index_file( $upload_root . 'signatures' );
		Forminator_Upload_Access::protect_temp_directory();
	}

	/**
	 * Update Stripe payment status through cron job.
	 * This is used to update the payment status of Stripe payments that are pending.
	 *
	 * @since 1.58
	 */
	public function update_stripe_payment_status_cron() {
		$limit           = apply_filters( 'forminator_query_pending_entries_limit_per_request', 50 );
		$pending_entries = Forminator_Form_Entry_Model::get_stripe_payment_pending_entries( $limit );
		if ( ! empty( $pending_entries ) ) {
			$form_action = Forminator_CForm_Front_Action::get_instance();
			foreach ( $pending_entries as $pending_entry ) {
				try {
					$entry = new Forminator_Form_Entry_Model( $pending_entry->entry_id );

					$user_data       = $entry->get_meta( '_user_login_data', false );
					$user_auto_login = $entry->get_meta( '_user_registration_automatic_login', false );
					$session_id      = $entry->get_meta( '_stripe_checkout_session_id', false );
					// If the entry is associated with a login form or registration with auto-login, we do not need to process the payment status if the session is still active.
					if ( ( ! empty( $user_data ) || ! empty( $user_auto_login ) ) && ! empty( $session_id ) ) {
						$session_data = Forminator_Stripe::get_pending_checkout_session_data( $session_id );
						if ( ! empty( $session_data ) && ! empty( $session_data['updated_at'] ) ) {
							// If the session is still active, we hold off on processing the payment status so that the success status can be handled on the form page, as it requires the user to log in.
							$expiration = ( (int) $session_data['updated_at'] ) + ( 5 * MINUTE_IN_SECONDS );
							if ( time() <= (int) $expiration ) {
								continue;
							}
						}
					}

					$form_action->process_pending_stripe_payment( $entry );

					// Update the cron run time for the entry to avoid processing it again immediately.
					$this->update_cron_run_time( $entry );
				} catch ( Exception $e ) {
					forminator_maybe_log(
						__METHOD__,
						array(
							'message'  => $e->getMessage(),
							'entry_id' => $pending_entry->entry_id,
						),
					);
				}
			}
		}
	}

	/**
	 * Update the cron run time for the entry.
	 *
	 * @since 1.58
	 *
	 * @param Forminator_Form_Entry_Model $entry The entry object.
	 */
	private function update_cron_run_time( $entry ) {
		if ( 'pending' !== $entry->status ) {
			return;
		}

		$webhook = Forminator_Handle_Webhooks::get_instance();
		if ( $webhook->is_webhook_processing_locked( $entry ) ) {
			// If the webhook processing is locked, we don't want to update the cron run time.
			return;
		}

		if ( ! empty( $entry->meta_data ) ) {
			$meta_data           = $entry->meta_data;
			$current_timestamp   = strtotime( date_i18n( 'Y-m-d H:i:s' ) );
			$seconds_to_next_try = apply_filters( 'forminator_stripe_payment_status_check_next_try_seconds', HOUR_IN_SECONDS * 2 );
			$future_timestamp    = $current_timestamp + $seconds_to_next_try;
			$next_try            = date_i18n( 'Y-m-d H:i:s', $future_timestamp );
			if ( empty( $meta_data['_next_stripe_status_check'] ) || empty( $meta_data['_next_stripe_status_check']['id'] ) ) {
				$entry->add_meta( '_next_stripe_status_check', $next_try );
			} else {
				$meta_id = $meta_data['_next_stripe_status_check']['id'];
				$entry->update_meta( $meta_id, '_next_stripe_status_check', $next_try );
			}
		}
	}
}
