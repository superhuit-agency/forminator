<?php
/**
 * Template admin/views/common/dashboard/widget.php
 *
 * @package Forminator
 */

$create_dialog      = forminator_get_prefix( $module_slug, 'custom_', false, true );
$dashboard_settings = forminator_get_dashboard_settings( forminator_get_prefix( $module_slug, '', false, true ), array() );
$num_recent         = isset( $dashboard_settings['num_recent'] ) ? $dashboard_settings['num_recent'] : 5;

if ( 0 === $num_recent ) {
	return;
}
?>

<div id="forminator-dashboard-box-recent-<?php echo esc_attr( $module_slug ); ?>" class="sui-box">

	<div class="sui-box-header">

		<h3 class="sui-box-title"><i class="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i><?php echo esc_html( $module_title ); ?></h3>

	</div>

	<div class="sui-box-body">

		<p>
		<?php
		if ( $total > 0 ) {
			echo esc_html( $recent_description );
		} else {
			echo esc_html( $description );
		}
		?>
		</p>

		<?php // Strict comparison is removed for a reason! ?>
		<?php if ( 0 === $total ) { ?>

			<p><button class="sui-button sui-button-blue wpmudev-open-modal"
				data-modal="<?php echo esc_attr( $create_dialog ); ?>">
				<i class="sui-icon-plus" aria-hidden="true"></i> <?php esc_html_e( 'Add New', 'forminator' ); ?>
			</button></p>

		<?php } ?>

	</div>
	<?php if ( 0 < $total ) { ?>
		<div class="sui-box-body forminator-recent-forms-loader" style="text-align: center; padding-top: 0;">
			<span class="sui-progress-icon" aria-hidden="true">
				<span class="sui-icon-loader sui-loading"></span>
			</span>
		</div>
	<?php } ?>
</div>
<?php if ( 0 < $total ) { ?>
	<script type="text/javascript">
		jQuery(function() {
			var ajaxUrl = '<?php echo esc_url( forminator_ajax_url() ); ?>';
			jQuery.ajax({
				url: ajaxUrl,
				type: "POST",
				data: {
					action: 'forminator_load_dashboard_recent_<?php echo esc_attr( $module_slug ); ?>_widget',
					_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'forminator_load_dashboard_recent_' . $module_slug . '_widget' ) ); ?>'
				}
			}).done(function(response) {
				if (response.success && response.data.html) {
					jQuery('#forminator-dashboard-box-recent-<?php echo esc_attr( $module_slug ); ?>').append(response.data.html);
				}
			}).always(function() {
				jQuery('#forminator-dashboard-box-recent-<?php echo esc_attr( $module_slug ); ?> .forminator-recent-forms-loader').remove();
			});
		});
	</script>
<?php } ?>
