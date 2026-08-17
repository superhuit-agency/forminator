<?php
/**
 * Custom sequence helpers.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Normalize custom sequence prefix.
 *
 * @param string $prefix Prefix value.
 *
 * @return string
 */
function forminator_custom_sequence_normalize_prefix( $prefix ) {
	$prefix = sanitize_text_field( wp_unslash( (string) $prefix ) );
	$prefix = preg_replace( '/[^A-Za-z0-9 _\-]/', '', $prefix );

	return substr( $prefix, 0, 16 );
}

/**
 * Normalize custom sequence number.
 *
 * @param mixed $number Sequence number.
 * @param int   $minimum Minimum allowed value.
 *
 * @return int
 */
function forminator_custom_sequence_normalize_number( $number, $minimum = 1 ) {
	$minimum = max( 1, absint( $minimum ) );
	$number  = absint( $number );

	if ( $number < $minimum ) {
		$number = $minimum;
	}

	return $number;
}

/**
 * Get custom sequence counter meta key.
 *
 * Stores the last allocated sequence number so the next number can be incremented atomically.
 *
 * @return string
 */
function forminator_custom_sequence_counter_meta_key() {
	return 'forminator_custom_sequence_counter';
}

/**
 * Get custom sequence dirty token meta key.
 *
 * @return string
 */
function forminator_custom_sequence_dirty_token_meta_key() {
	return 'forminator_custom_sequence_dirty_token';
}

/**
 * Get normalized custom sequence counter value.
 *
 * @param int $form_id Form ID.
 *
 * @return int
 */
function forminator_custom_sequence_get_counter( $form_id ) {
	$form_id = absint( $form_id );
	$last    = 0;

	if ( ! $form_id ) {
		return $last;
	}

	$counter = get_post_meta( $form_id, forminator_custom_sequence_counter_meta_key(), true );

	if ( '' === $counter || null === $counter ) {
		return $last;
	}

	return max( $last, absint( $counter ) );
}

/**
 * Sync custom sequence counter meta with the next available number.
 *
 * @param int $form_id Form ID.
 * @param int $next    Next available number.
 *
 * @return void
 */
function forminator_custom_sequence_sync_counter( $form_id, $next ) {
	$form_id = absint( $form_id );
	$next    = forminator_custom_sequence_normalize_number( $next );

	if ( ! $form_id ) {
		return;
	}

	update_post_meta( $form_id, forminator_custom_sequence_counter_meta_key(), (string) max( 0, $next - 1 ) );
}

/**
 * Get normalized custom sequence settings for a form.
 *
 * @param int $form_id Form ID.
 *
 * @return array
 */
function forminator_custom_sequence_get_settings( $form_id ) {
	$defaults = array(
		'enabled' => false,
		'prefix'  => '',
		'next'    => 1,
	);

	$form_id = absint( $form_id );
	if ( ! $form_id ) {
		return $defaults;
	}

	$meta = get_post_meta( $form_id, Forminator_Base_Form_Model::META_KEY, true );
	if ( ! is_array( $meta ) || empty( $meta['settings'] ) || ! is_array( $meta['settings'] ) ) {
		return $defaults;
	}

	$settings = $meta['settings'];

	$enabled = false;
	if ( isset( $settings['custom_sequence_enabled'] ) ) {
		$enabled = filter_var( $settings['custom_sequence_enabled'], FILTER_VALIDATE_BOOLEAN );
	}

	$prefix = isset( $settings['custom_sequence_prefix'] ) ? forminator_custom_sequence_normalize_prefix( $settings['custom_sequence_prefix'] ) : '';
	$next   = forminator_custom_sequence_get_counter( $form_id ) + 1;

	return array(
		'enabled' => $enabled,
		'prefix'  => $prefix,
		'next'    => $next,
	);
}

/**
 * Sanitize custom sequence settings on form save.
 *
 * @param array $settings Form settings.
 *
 * @return array
 */
function forminator_custom_sequence_sanitize_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return array();
	}

	$enabled = false;
	if ( isset( $settings['custom_sequence_enabled'] ) ) {
		$enabled = filter_var( $settings['custom_sequence_enabled'], FILTER_VALIDATE_BOOLEAN );
	}

	$prefix = isset( $settings['custom_sequence_prefix'] ) ? forminator_custom_sequence_normalize_prefix( $settings['custom_sequence_prefix'] ) : '';

	$settings['custom_sequence_enabled'] = $enabled ? '1' : '';
	$settings['custom_sequence_prefix']  = $prefix;
	unset( $settings['custom_sequence_next_number'] );
	unset( $settings['custom_sequence_next_number_dirty'] );

	return $settings;
}

/**
 * Sync custom sequence counter meta from submitted settings when required.
 *
 * @param array $settings Form settings.
 * @param int   $form_id  Form ID.
 *
 * @return void
 */
function forminator_custom_sequence_maybe_sync_counter( $settings, $form_id ) {
	if ( ! is_array( $settings ) ) {
		return;
	}

	$form_id = absint( $form_id );
	if ( ! $form_id ) {
		return;
	}

	$enabled = false;
	if ( isset( $settings['custom_sequence_enabled'] ) ) {
		$enabled = filter_var( $settings['custom_sequence_enabled'], FILTER_VALIDATE_BOOLEAN );
	}

	if ( ! $enabled ) {
		return;
	}

	$counter_exists = metadata_exists( 'post', $form_id, forminator_custom_sequence_counter_meta_key() );
	$dirty_token    = isset( $settings['custom_sequence_next_number_dirty'] ) ? sanitize_text_field( wp_unslash( (string) $settings['custom_sequence_next_number_dirty'] ) ) : '';
	$next_dirty     = '' !== $dirty_token;

	if ( ! $next_dirty && $counter_exists ) {
		return;
	}

	if ( $next_dirty && $counter_exists ) {
		$consumed_token = get_post_meta( $form_id, forminator_custom_sequence_dirty_token_meta_key(), true );
		if ( $dirty_token === $consumed_token ) {
			return;
		}
	}

	$next = forminator_custom_sequence_normalize_number( $settings['custom_sequence_next_number'] ?? 1 );
	forminator_custom_sequence_sync_counter( $form_id, $next );

	if ( $next_dirty ) {
		update_post_meta( $form_id, forminator_custom_sequence_dirty_token_meta_key(), $dirty_token );
	}
}


/**
 * Allocate and persist custom ID values for a saved entry.
 *
 * @param Forminator_Form_Entry_Model $entry Entry model.
 * @param int                         $form_id Form ID.
 *
 * @return void
 */
function forminator_custom_sequence_assign_custom_id( $entry, $form_id ) {
	global $wpdb;

	if ( ! is_object( $entry ) || empty( $entry->entry_id ) ) {
		return;
	}

	if ( empty( $entry->status ) || 'active' !== $entry->status ) {
		return;
	}

	if ( ! empty( $entry->custom_id ) ) {
		return;
	}

	$form_id = absint( $form_id );
	if ( ! $form_id ) {
		return;
	}

	$entry_table = Forminator_Database_Tables::get_table_name( Forminator_Database_Tables::FORM_ENTRY );
	if ( ! $entry_table ) {
		return;
	}

	wp_cache_delete( $form_id, 'post_meta' );
	$meta = get_post_meta( $form_id, Forminator_Base_Form_Model::META_KEY, true );
	if ( ! is_array( $meta ) ) {
		return;
	}

	if ( empty( $meta['settings'] ) || ! is_array( $meta['settings'] ) ) {
		$meta['settings'] = array();
	}

	if ( isset( $meta['settings']['form-type'] ) && 'leads' === $meta['settings']['form-type'] ) {
		return;
	}

	$enabled = ! empty( $meta['settings']['custom_sequence_enabled'] ) && filter_var( $meta['settings']['custom_sequence_enabled'], FILTER_VALIDATE_BOOLEAN );
	if ( ! $enabled ) {
		return;
	}

	$prefix = isset( $meta['settings']['custom_sequence_prefix'] ) ? forminator_custom_sequence_normalize_prefix( $meta['settings']['custom_sequence_prefix'] ) : '';

	$counter_updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = LAST_INSERT_ID(CAST(meta_value AS UNSIGNED) + 1) WHERE post_id = %d AND meta_key = %s",
			$form_id,
			forminator_custom_sequence_counter_meta_key()
		)
	);

	if ( ! $counter_updated || 0 === $counter_updated ) {
		return;
	}

	$allocated_number = (int) mysqli_insert_id( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_insert_id

	if ( $allocated_number < 1 ) {
		return;
	}

	$rows_updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_table,
		array(
			'custom_id'     => $allocated_number,
			'custom_prefix' => $prefix,
		),
		array(
			'entry_id'  => absint( $entry->entry_id ),
			'custom_id' => 0,
		)
	);

	if ( ! $rows_updated || 0 === $rows_updated ) {
		return;
	}

	$entry->custom_id     = $allocated_number;
	$entry->custom_prefix = $prefix;
}
