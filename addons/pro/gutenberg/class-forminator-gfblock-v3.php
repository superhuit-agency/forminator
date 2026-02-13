<?php
/**
 * Forminator Gutenberg Blocks – Block API v3 Override.
 *
 * Prevents the original v1 Forminator block scripts from executing on the
 * client and replaces them with Block API v3 equivalents that use
 * useBlockProps() for iframe editor compatibility (WP 6.3+, required 7.0+).
 *
 * Strategy:
 *   1. The original block classes (priority 10) enqueue their scripts and
 *      localize data (frmnt_form_data, frmnt_poll_data, frmnt_quiz_data).
 *   2. At priority 20 we transfer that localized data to our v3 script,
 *      then dequeue & deregister the originals so their v1
 *      registerBlockType() calls never run.
 *   3. The v3 script registers all three blocks with apiVersion 3.
 *
 * The server-side registrations (REST API, render callbacks, styles) remain
 * untouched — only the client-side block definitions are replaced.
 *
 * @package Forminator
 * @see     https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Replace original v1 block scripts with the v3 override.
 *
 * Hooked at priority 20 on enqueue_block_editor_assets so that the
 * original block classes have already enqueued + localised at priority 10.
 */
function forminator_enqueue_blocks_v3() {
	$script_path = plugin_dir_path( __FILE__ ) . 'js/blocks-v3.js';

	if ( ! file_exists( $script_path ) ) {
		return;
	}

	// Enqueue the v3 script — only core WP dependencies are needed.
	wp_enqueue_script(
		'forminator-blocks-v3',
		forminator_gutenberg()->get_plugin_url() . 'js/blocks-v3.js',
		array(
			'wp-blocks',
			'wp-i18n',
			'wp-element',
			'wp-block-editor',
			'wp-components',
		),
		filemtime( $script_path ),
		false
	);

	// The original block classes localised data on their own script handles
	// (e.g. wp_localize_script( 'forminator-block-forms', 'frmnt_form_data', … )).
	// We move that inline data to the v3 handle, then remove the originals
	// so the v1 registerBlockType() calls never execute.
	$original_handles = array(
		'forminator-block-forms',
		'forminator-block-polls',
		'forminator-block-quizzes',
	);

	$scripts = wp_scripts();

	foreach ( $original_handles as $handle ) {
		if ( ! wp_script_is( $handle, 'registered' ) && ! wp_script_is( $handle, 'enqueued' ) ) {
			continue;
		}

		// Grab the inline data set by wp_localize_script
		// (e.g. "var frmnt_form_data = {…};").
		$inline_data = $scripts->get_data( $handle, 'data' );

		if ( $inline_data ) {
			wp_add_inline_script( 'forminator-blocks-v3', $inline_data, 'before' );
		}

		// Remove the original script so its v1 code never runs.
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}

add_action( 'enqueue_block_editor_assets', 'forminator_enqueue_blocks_v3', 20 );
