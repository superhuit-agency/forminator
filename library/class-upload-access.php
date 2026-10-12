<?php
/**
 * Temporary upload access tokens.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class Forminator_Upload_Access
 *
 * Creates short-lived opaque tokens for AJAX temp uploads so the client never
 * receives a public file path/URL. Tokens are resolved server-side on submit.
 */
class Forminator_Upload_Access {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'forminator_upload_access_';

	/**
	 * Default token lifetime in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Create an access token for a temporary upload.
	 *
	 * @param int    $form_id   Form ID.
	 * @param string $file_name Stored file name in the temp directory.
	 * @param string $file_path Absolute path to the temp file.
	 * @return string Token string, or empty string on failure.
	 */
	public static function create_token( $form_id, $file_name, $file_path ) {
		$form_id   = absint( $form_id );
		$file_name = sanitize_file_name( $file_name );
		$file_path = wp_normalize_path( (string) $file_path );

		if ( $form_id < 1 || '' === $file_name || '' === $file_path ) {
			return '';
		}

		self::protect_temp_directory();

		$token = wp_generate_password( 32, false, false );

		$data = array(
			'form_id'   => $form_id,
			'file_name' => $file_name,
			'file_path' => $file_path,
			'created'   => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $token, $data, self::get_ttl() );

		return $token;
	}

	/**
	 * Resolve a token to its upload metadata.
	 *
	 * @param string $token   Access token.
	 * @param int    $form_id Optional form ID that must match the token.
	 * @return array|false {
	 *     @type int    $form_id
	 *     @type string $file_name
	 *     @type string $file_path
	 *     @type int    $created
	 * }
	 */
	public static function resolve( $token, $form_id = 0 ) {
		$token = self::sanitize_token( $token );
		if ( '' === $token ) {
			return false;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( empty( $data ) || ! is_array( $data ) || empty( $data['file_name'] ) ) {
			return false;
		}

		$form_id = absint( $form_id );
		if ( $form_id > 0 && absint( $data['form_id'] ) !== $form_id ) {
			return false;
		}

		$data['file_name'] = sanitize_file_name( $data['file_name'] );
		$data['file_path'] = isset( $data['file_path'] ) ? wp_normalize_path( (string) $data['file_path'] ) : '';

		return $data;
	}

	/**
	 * Resolve a client access token to a temp file name.
	 *
	 * @param array $upload  Upload payload from the client.
	 * @param int   $form_id Form ID.
	 * @return string Sanitized temp file name, or empty string.
	 */
	public static function resolve_temp_file_name( $upload, $form_id ) {
		if ( empty( $upload ) || ! is_array( $upload ) || empty( $upload['access_token'] ) ) {
			return '';
		}

		$resolved = self::resolve( $upload['access_token'], $form_id );
		if ( empty( $resolved['file_name'] ) ) {
			return '';
		}

		return $resolved['file_name'];
	}

	/**
	 * Revoke a token after the temp file is moved or deleted.
	 *
	 * @param string $token Access token.
	 * @return void
	 */
	public static function revoke( $token ) {
		$token = self::sanitize_token( $token );
		if ( '' === $token ) {
			return;
		}

		delete_transient( self::TRANSIENT_PREFIX . $token );
	}

	/**
	 * Revoke the token carried on an upload payload, if any.
	 *
	 * @param array $upload Upload payload.
	 * @return void
	 */
	public static function revoke_from_upload( $upload ) {
		if ( empty( $upload ) || ! is_array( $upload ) || empty( $upload['access_token'] ) ) {
			return;
		}

		self::revoke( $upload['access_token'] );
	}

	/**
	 * Ensure the temp directory denies direct HTTP access (Apache).
	 *
	 * @return void
	 */
	public static function protect_temp_directory() {
		$temp_path = forminator_upload_root_temp();
		if ( is_wp_error( $temp_path ) ) {
			return;
		}

		if ( ! is_dir( $temp_path ) ) {
			wp_mkdir_p( $temp_path );
		}

		if ( ! is_dir( $temp_path ) || ! wp_is_writable( $temp_path ) ) {
			return;
		}

		Forminator_Field::add_index_file( $temp_path );

		$htaccess_file = trailingslashit( $temp_path ) . '.htaccess';
		if ( file_exists( $htaccess_file ) ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$rules = '<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>';

		/**
		 * Filter Apache rules that block direct access to temporary uploads.
		 *
		 * @since 1.58.0
		 *
		 * @param string $rules Apache rules.
		 */
		$rules = apply_filters( 'forminator_upload_temp_htaccess_rules', $rules );
		if ( ! empty( $rules ) ) {
			insert_with_markers( $htaccess_file, 'Forminator', $rules );
		}
	}

	/**
	 * Token lifetime.
	 *
	 * @return int
	 */
	public static function get_ttl() {
		/**
		 * Filter temporary upload access token lifetime.
		 *
		 * @since 1.58.0
		 *
		 * @param int $ttl Lifetime in seconds.
		 */
		return (int) apply_filters( 'forminator_upload_access_token_ttl', self::DEFAULT_TTL );
	}

	/**
	 * Sanitize a token string.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function sanitize_token( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( '' === $token ) {
			return '';
		}

		// Same format as draft/entry tokens: wp_generate_password( 32, false, false ).
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return '';
		}

		return $token;
	}
}
