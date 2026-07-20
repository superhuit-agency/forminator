<?php
/**
 * Fork-owned customization: disable all updates for this plugin.
 *
 * This lives in a fork-owned, sync-excluded directory (`fork/`) so the weekly
 * upstream sync never touches it. As a result the only change the sync must
 * re-apply to an upstream file is a single `require_once` line in
 * `forminator.php` (see `patches/0001-...`), instead of a 36-line insertion
 * into the Forminator class — which is far less likely to conflict when
 * upstream refactors that class.
 *
 * @package Forminator
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

add_filter( 'site_transient_update_plugins', 'forminator_fork_disable_plugin_update' );
add_filter( 'auto_update_plugin', 'forminator_fork_disable_auto_update', 10, 2 );

/**
 * Remove this plugin from the list of available updates.
 *
 * @param object|bool $transient Transient object of available updates.
 * @return mixed
 */
function forminator_fork_disable_plugin_update( $transient ) {
	$plugin_slug = 'forminator/forminator.php';

	if ( isset( $transient->response[ $plugin_slug ] ) ) {
		unset( $transient->response[ $plugin_slug ] );
	}

	return $transient;
}

/**
 * Disable automatic updates for this plugin.
 *
 * @param bool|null $update Whether to update.
 * @param object    $item   The update offer.
 * @return bool|null
 */
function forminator_fork_disable_auto_update( $update, $item ) {
	if ( isset( $item->slug ) && 'forminator' === $item->slug ) {
		return false;
	}
	return $update;
}
