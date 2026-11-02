<?php
/**
 * The Forminator_Akismet class.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Forminator Akismet protection
 * Use the akismet api to check for possible spam posted
 *
 * Akismet API: http://akismet.com/development/api/
 */
class Forminator_Akismet extends Forminator_Spam_Protection {


	/**
	 * Plugin instance
	 *
	 * @var null|Forminator_Akismet
	 */
	private static $instance = null;

	/**
	 * Return the plugin instance
	 *
	 * @return Forminator_Akismet
	 *
	 * @since 1.0
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check if the plugin or setting is enabled
	 *
	 * @since 1.0
	 * @return bool
	 */
	public function is_enabled() {
		// Akismet v3.0+.
		if ( is_callable( array( 'Akismet', 'get_api_key' ) ) ) {
			return (bool) Akismet::get_api_key();
		}

		if ( function_exists( 'akismet_get_key' ) ) {
			return (bool) akismet_get_key();
		}

		return false;
	}

	/**
	 * Handle spam protection
	 *
	 * @see process_spam_protection
	 *
	 * @since 1.0
	 * @param bool   $is_spam - if the data is spam.
	 * @param array  $posted_params - the posted parameters.
	 * @param int    $form_id - the form id.
	 * @param string $form_type - the form type.
	 *
	 * @return bool $is_spam
	 */
	protected function handle_spam_protection( $is_spam, $posted_params, $form_id, $form_type ) {
		// Check Akismet integration.
		if ( ! self::is_protection_enabled( $form_id ) ) {
			return $is_spam;
		}

		$post_data        = array(
			'blog'         => get_option( 'home' ),
			'user_ip'      => Forminator_Geo::get_user_ip(),
			'user_agent'   => Forminator_Core::sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'referrer'     => Forminator_Core::sanitize_text_field( $_SERVER['HTTP_REFERER'] ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'comment_type' => $form_type,
			'content'      => '',
		);
		$has_akismet_data = false;
		foreach ( $posted_params as $param ) {
			if ( isset( $param['name'] ) && isset( $param['value'] ) ) {
				$has_akismet_data = true;
				if ( is_string( $param['value'] ) && filter_var( $param['value'], FILTER_VALIDATE_EMAIL ) ) {
					$post_data['comment_author_email'] = $param['value'];
				}

				$content_value = $this->get_akismet_content_value( $param['value'] );
				if ( '' !== $content_value ) {
					$post_data['content'] .= "\n\n" . $content_value;
				}
			}
		}

		if ( $has_akismet_data ) {
			if ( is_user_logged_in() ) {
				$current_user = wp_get_current_user();
				if ( ! empty( $current_user->user_firstname ) ) {
					$user_name = $current_user->user_firstname . ' ' . $current_user->user_lastname;
				} elseif ( ! empty( $current_user->display_name ) ) {
					$user_name = $current_user->display_name;
				} else {
					$user_name = $current_user->user_login;
				}

				$post_data['comment_author_email'] = $current_user->user_email;
				$post_data['comment_author']       = $user_name;
			}
			$post_data['content'] = trim( $post_data['content'] );

			$permalink = get_permalink();
			if ( false !== $permalink ) {
				$post_data['permalink'] = $permalink;
			}

			// Allowlist only: $_SERVER can hold credentials, cookies and server secrets.
			$server_keys = array(
				'HTTP_ACCEPT',
				'HTTP_ACCEPT_CHARSET',
				'HTTP_ACCEPT_ENCODING',
				'HTTP_ACCEPT_LANGUAGE',
				'HTTP_CONNECTION',
				'HTTP_HOST',
				'HTTP_REFERER',
				'HTTP_USER_AGENT',
				'REMOTE_ADDR',
				'REQUEST_METHOD',
				'REQUEST_URI',
				'SERVER_NAME',
				'SERVER_PROTOCOL',
			);

			foreach ( $server_keys as $key ) {
				if ( isset( $_SERVER[ $key ] ) ) {
					$post_data[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				}
			}

			$is_spam = $this->akismet_check( $post_data, $form_id );
		}

		return $is_spam;
	}

	/**
	 * Convert a field value into a string Akismet can use as comment content.
	 *
	 * Nested values such as File Upload payloads must not be passed to implode(),
	 * which triggers "Array to string conversion" warnings on PHP 8+.
	 *
	 * @since 1.58.3
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private function get_akismet_content_value( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		// Signature and upload fields store URLs under file.file_url.
		if ( ! empty( $value['file']['file_url'] ) ) {
			$file_url = $value['file']['file_url'];
			if ( is_array( $file_url ) ) {
				$file_url = array_filter( $file_url, 'is_scalar' );
				return implode( ', ', $file_url );
			}

			return is_scalar( $file_url ) ? (string) $file_url : '';
		}

		$parts = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$parts[] = (string) $item;
				continue;
			}

			if ( is_array( $item ) ) {
				$nested = $this->get_akismet_content_value( $item );
				if ( '' !== $nested ) {
					$parts[] = $nested;
				}
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Check if Akismet protection is enabled
	 *
	 * @param int $id Module ID.
	 * @return bool
	 */
	private static function is_protection_enabled( $id ) {
		$model    = Forminator_Base_Form_Model::get_model( $id );
		$settings = ! empty( $model->settings ) ? $model->settings : array();
		$enabled  = ! isset( $settings['akismet-protection'] ) || $settings['akismet-protection'];

		return $enabled;
	}

	/**
	 * Check akismet if the data is spam
	 *
	 * @since 1.0
	 * @param array $post_data - the post data.
	 * @param int   $form_id - the form id.
	 *
	 * @return bool
	 */
	private function akismet_check( $post_data, $form_id ) {
		global $akismet_api_host, $akismet_api_port;
		$post_data = apply_filters( 'forminator_akismet_values', $post_data, $form_id );

		$is_spam = false;
		$query   = $this->build_query( $post_data );

		if ( is_callable( array( 'Akismet', 'http_post' ) ) ) { // Akismet v3.0+.
			$response = Akismet::http_post( $query, 'comment-check' );
		} else {
			$response = akismet_http_post( $query, $akismet_api_host, '/1.1/comment-check', $akismet_api_port );
		}

		// Response will always be an array of array( $response['headers'], $response['body'] ).
		if ( 'true' === $response[1] ) {
			$is_spam = true;
		}

		return apply_filters( 'forminator_akismet_is_spam', $is_spam, $post_data, $form_id );
	}


	/**
	 * Build http query
	 * The default build_query function misses out alot of things
	 *
	 * @since 1.0
	 * @param array  $args - the arguments.
	 * @param string $key Key.
	 *
	 * @return string
	 */
	private function build_query( $args, $key = '' ) {
		$sep = '&';
		$ret = array();

		foreach ( (array) $args as $k => $v ) {
			$k = rawurlencode( $k );

			if ( ! empty( $key ) ) {
				$k = $key . '%5B' . $k . '%5D';
			}

			if ( null === $v ) {
				continue;
			} elseif ( false === $v ) {
				$v = '0';
			}

			if ( is_array( $v ) || is_object( $v ) ) {
				array_push( $ret, $this->build_query( $v, $k ) );
			} else {
				array_push( $ret, $k . '=' . rawurlencode( $v ) );
			}
		}

		return implode( $sep, $ret );
	}
}
