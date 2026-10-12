<?php
/**
 * Forminator Stripe.js loader.
 *
 * Stripe.js keeps the first copy loaded on a page and discards the rest, and the unversioned v3
 * release rejects the Checkout Sessions initializers. Pages where the form can be found enqueue
 * our release early, ahead of third party enqueues. Everywhere else, any unversioned Stripe.js
 * another plugin prints is rewritten to that release instead. Releases are supersets of v3, so
 * the other plugin keeps working either way.
 *
 * @package Forminator
 * @since   1.58.0
 */

/**
 * Class Forminator_Stripe_Script_Loader
 *
 * @since 1.58.0
 */
class Forminator_Stripe_Script_Loader {

	/**
	 * Script handle used for the Stripe.js library.
	 */
	const SCRIPT_HANDLE = 'forminator-stripe';

	/**
	 * Pinned release. The Checkout Sessions API needs Basil or higher.
	 */
	const CHECKOUT_SESSION_SRC = 'https://js.stripe.com/dahlia/stripe.js';

	/**
	 * Unversioned release, used by the classic payment field.
	 */
	const CLASSIC_SRC = 'https://js.stripe.com/v3/';

	/**
	 * Caches whether the site has a form using the Checkout Sessions API.
	 */
	const HAS_CHECKOUT_SESSION_TRANSIENT = 'forminator_has_checkout_session_form';

	/**
	 * Whether the Checkout Sessions release was already requested for the current page.
	 *
	 * @var bool
	 */
	private static $checkout_session_requested = false;

	/**
	 * Init hooks.
	 *
	 * @since 1.58.0
	 */
	public static function init() {
		// Priority 1 runs before third party plugins enqueue their own copy.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'preload' ), 1 );
		add_filter( 'script_loader_src', array( __CLASS__, 'upgrade_foreign_src' ), 9999, 2 );
		add_action( 'forminator_custom_form_action_update', array( __CLASS__, 'flush_checkout_session_cache' ) );
		add_action( 'forminator_form_action_delete', array( __CLASS__, 'flush_checkout_session_cache' ) );
	}

	/**
	 * Enqueue Stripe.js in the head when the page renders a Checkout Sessions field.
	 *
	 * @since 1.58.0
	 */
	public static function preload() {
		if ( ! self::page_needs_checkout_session() ) {
			return;
		}

		self::enqueue( true, false );
	}

	/**
	 * Enqueue the Stripe.js library.
	 *
	 * @since 1.58.0
	 *
	 * @param bool $needs_checkout_session Whether the field uses the Checkout Sessions API.
	 * @param bool $in_footer              Whether to print the script in the footer.
	 */
	public static function enqueue( $needs_checkout_session, $in_footer = true ) {
		// Already printed, and Stripe.js would discard a second copy anyway.
		if ( wp_script_is( self::SCRIPT_HANDLE, 'done' ) ) {
			return;
		}

		if ( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			if ( ! $needs_checkout_session || self::$checkout_session_requested ) {
				wp_enqueue_script( self::SCRIPT_HANDLE );

				return;
			}

			// A later field needs Checkout Sessions, so swap the pending classic release for it.
			wp_deregister_script( self::SCRIPT_HANDLE );
		}

		$foreign_handle = self::get_foreign_handle();

		// The classic field can run on the copy another plugin already brings.
		if ( $foreign_handle && ! $needs_checkout_session ) {
			return;
		}

		self::$checkout_session_requested = self::$checkout_session_requested || (bool) $needs_checkout_session;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			self::get_src( $needs_checkout_session ),
			array(),
			FORMINATOR_VERSION,
			$in_footer
		);

		if ( $foreign_handle ) {
			// Name the script that got in first so the field can report the conflict.
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				'window.ForminatorStripeConflict = ' . wp_json_encode( $foreign_handle ) . ';',
				'before'
			);
		}
	}

	/**
	 * Get the Stripe.js URL for a release.
	 *
	 * @since 1.58.0
	 *
	 * @param bool $needs_checkout_session Whether the field uses the Checkout Sessions API.
	 *
	 * @return string
	 */
	public static function get_src( $needs_checkout_session ) {
		return $needs_checkout_session ? self::CHECKOUT_SESSION_SRC : self::CLASSIC_SRC;
	}

	/**
	 * Check whether Stripe.js is already on its way to the page through our own handle.
	 *
	 * @since 1.58.0
	 *
	 * @return bool
	 */
	public static function is_enqueued() {
		return wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) || wp_script_is( self::SCRIPT_HANDLE, 'done' );
	}

	/**
	 * Point another plugin's unversioned Stripe.js at the release Checkout Sessions needs.
	 *
	 * Runs when the script is printed, so it does not matter which plugin enqueues first.
	 *
	 * @since 1.58.0
	 *
	 * @param string $src    Script URL.
	 * @param string $handle Script handle.
	 *
	 * @return string
	 */
	public static function upgrade_foreign_src( $src, $handle ) {
		if ( empty( $src ) || self::SCRIPT_HANDLE === $handle || is_admin() ) {
			return $src;
		}

		// Never touch a release a plugin pinned deliberately.
		if ( ! self::is_unversioned_stripe_src( $src ) ) {
			return $src;
		}

		// Ours is already on the page, so Stripe discards theirs regardless.
		if ( wp_script_is( self::SCRIPT_HANDLE, 'done' ) ) {
			return $src;
		}

		if ( ! self::site_has_checkout_session_form() ) {
			return $src;
		}

		/**
		 * Filter whether Forminator upgrades another plugin's unversioned Stripe.js.
		 *
		 * Return false to leave the other plugin's URL untouched.
		 *
		 * @since 1.58.0
		 *
		 * @param bool   $upgrade Whether to upgrade the URL.
		 * @param string $src     Script URL.
		 * @param string $handle  Script handle.
		 */
		if ( ! apply_filters( 'forminator_stripe_upgrade_foreign_src', true, $src, $handle ) ) {
			return $src;
		}

		// Theirs now serves the release we need, so drop ours to keep one copy per page.
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			wp_dequeue_script( self::SCRIPT_HANDLE );
		}

		return self::CHECKOUT_SESSION_SRC;
	}

	/**
	 * Forget whether the site has a Checkout Sessions form.
	 *
	 * @since 1.58.0
	 */
	public static function flush_checkout_session_cache() {
		delete_transient( self::HAS_CHECKOUT_SESSION_TRANSIENT );
	}

	/**
	 * Check whether the current page renders a field that uses the Checkout Sessions API.
	 *
	 * @since 1.58.0
	 *
	 * @return bool
	 */
	private static function page_needs_checkout_session() {
		global $post;

		$needs_checkout_session = false;

		if ( $post instanceof WP_Post && ! empty( $post->post_content ) ) {
			foreach ( self::get_content_form_ids( $post->post_content ) as $form_id ) {
				if ( self::form_uses_checkout_session( $form_id ) ) {
					$needs_checkout_session = true;
					break;
				}
			}
		}

		/**
		 * Filter whether Forminator loads the Checkout Sessions release of Stripe.js in the head.
		 *
		 * Only post content is scanned, so forms rendered from a widget, a template call or a page
		 * builder fall back to the render-time enqueue. Return true for those pages to keep the head
		 * enqueue that wins against globally loaded Stripe.js.
		 *
		 * @since 1.58.0
		 *
		 * @param bool $needs_checkout_session Whether the page renders a Checkout Sessions field.
		 */
		return (bool) apply_filters( 'forminator_stripe_preload_checkout_session', $needs_checkout_session );
	}

	/**
	 * Get the IDs of the forms embedded in a piece of content.
	 *
	 * @since 1.58.0
	 *
	 * @param string $content Post content.
	 *
	 * @return array
	 */
	private static function get_content_form_ids( $content ) {
		// The Forminator block saves a shortcode, so this covers both editors.
		if ( false === strpos( $content, '[forminator_form' ) ) {
			return array();
		}

		$form_ids = array();

		preg_match_all(
			'/' . get_shortcode_regex( array( 'forminator_form' ) ) . '/',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $shortcode ) {
			if ( empty( $shortcode[3] ) ) {
				continue;
			}

			// Before WP 6.5 this returns a string when the attributes cannot be parsed.
			$attributes = shortcode_parse_atts( $shortcode[3] );

			if ( is_array( $attributes ) && ! empty( $attributes['id'] ) ) {
				$form_ids[] = $attributes['id'];
			}
		}

		return array_unique( $form_ids );
	}

	/**
	 * Check whether a form has a usable field that runs on the Checkout Sessions API.
	 *
	 * @since 1.58.0
	 *
	 * @param int|string $form_id Form ID.
	 *
	 * @return bool
	 */
	private static function form_uses_checkout_session( $form_id ) {
		$model = Forminator_Form_Model::model()->load( $form_id );
		if ( ! $model instanceof Forminator_Form_Model ) {
			return false;
		}

		$stripe_field = Forminator_Core::get_field_object( 'stripe' );
		if ( ! $stripe_field instanceof Forminator_Stripe ) {
			return false;
		}

		foreach ( $model->get_fields_as_array() as $field ) {
			if ( ! $stripe_field->is_checkout_session( $field ) ) {
				continue;
			}

			// Same availability check Forminator_CForm_Front::has_stripe() makes before rendering.
			$field_object = Forminator_Core::get_field_object( $field['type'] );
			if ( $field_object && $field_object->is_available( $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the handle of a Stripe.js copy another plugin is loading on this page.
	 *
	 * @since 1.58.0
	 *
	 * @return string Empty when no other copy is on its way to the page.
	 */
	private static function get_foreign_handle() {
		foreach ( wp_scripts()->registered as $handle => $script ) {
			if ( self::SCRIPT_HANDLE === $handle || empty( $script->src ) ) {
				continue;
			}

			if ( ! self::is_stripe_library_src( $script->src ) ) {
				continue;
			}

			if ( ! wp_script_is( $handle, 'enqueued' ) && ! wp_script_is( $handle, 'done' ) ) {
				continue;
			}

			return $handle;
		}

		return '';
	}

	/**
	 * Check whether any published form on the site uses the Checkout Sessions API.
	 *
	 * @since 1.58.0
	 *
	 * @return bool
	 */
	private static function site_has_checkout_session_form() {
		static $has_form = null;

		if ( null !== $has_form ) {
			return $has_form;
		}

		$cached = get_transient( self::HAS_CHECKOUT_SESSION_TRANSIENT );
		if ( false !== $cached ) {
			$has_form = (bool) $cached;

			return $has_form;
		}

		$forms = get_posts(
			array(
				'post_type'              => 'forminator_forms',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cached in a transient.
				'meta_query'             => array(
					array(
						'key'     => Forminator_Base_Form_Model::META_KEY,
						'value'   => 'stripe-ocs',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$has_form = ! empty( $forms );
		set_transient( self::HAS_CHECKOUT_SESSION_TRANSIENT, $has_form ? 1 : 0, DAY_IN_SECONDS );

		return $has_form;
	}

	/**
	 * Check whether a URL points at the unversioned Stripe.js release.
	 *
	 * @since 1.58.0
	 *
	 * @param string $src Script URL.
	 *
	 * @return bool
	 */
	private static function is_unversioned_stripe_src( $src ) {
		if ( 'js.stripe.com' !== wp_parse_url( $src, PHP_URL_HOST ) ) {
			return false;
		}

		return in_array( wp_parse_url( $src, PHP_URL_PATH ), array( '/v3', '/v3/' ), true );
	}

	/**
	 * Check whether a URL points at a Stripe.js release.
	 *
	 * @since 1.58.0
	 *
	 * @param string $src Script URL.
	 *
	 * @return bool
	 */
	private static function is_stripe_library_src( $src ) {
		if ( 'js.stripe.com' !== wp_parse_url( $src, PHP_URL_HOST ) ) {
			return false;
		}

		$path = wp_parse_url( $src, PHP_URL_PATH );

		// Only /v3/ and /<release>/stripe.js expose Stripe() - the Buy Button and Terminal do not.
		return ! empty( $path ) && 1 === preg_match( '#^/(v3/?|[a-z0-9]+/stripe\.js)$#', $path );
	}
}
