<?php
/**
 * Template admin/views/dashboard/widgets/widget-poll.php
 *
 * @package Forminator
 */

$module_slug        = 'poll';
$module_title       = esc_html__( 'Polls', 'forminator' );
$icon               = 'sui-icon-graph-bar';
$total              = forminator_polls_total();
$recent_description = esc_html__( 'Your most recent polls.', 'forminator' );
$description        = esc_html__( 'Create interactive polls to collect users\' opinions, with lots of dynamic options and settings.', 'forminator' );

require forminator_plugin_dir() . 'admin/views/common/dashboard/widget.php';
