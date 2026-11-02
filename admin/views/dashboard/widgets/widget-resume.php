<?php
/**
 * Template admin/views/dashboard/widgets/widget-resume.php
 *
 * @package Forminator
 */

?>

<div id="forminator-dashboard-box-summary" class="sui-box sui-summary <?php echo esc_attr( $this->get_box_summary_classes() ); ?>">
	<div class="sui-summary-image-space" aria-hidden="true" style="<?php echo esc_attr( $this->get_box_summary_image_style() ); ?>"></div>
	<div class="sui-summary-segment forminator-summary-loader" style="text-align: center;">
		<span class="sui-progress-icon" aria-hidden="true">
			<span class="sui-icon-loader sui-loading"></span>
		</span>
	</div>
</div>
<script type="text/javascript">
	jQuery(function() {
		var ajaxUrl = '<?php echo esc_url( forminator_ajax_url() ); ?>';
		jQuery.ajax({
			url: ajaxUrl,
			type: "POST",
			data: {
				action: 'forminator_load_dashboard_summary_widget',
				_ajax_nonce: '<?php echo esc_attr( wp_create_nonce( 'forminator_load_dashboard_summary_widget' ) ); ?>'
			}
		}).done(function(response) {
			if ( response.success && response.data.html ) {
				jQuery('#forminator-dashboard-box-summary').append( response.data.html );
			}
		}).always(function() {
			jQuery('#forminator-dashboard-box-summary .forminator-summary-loader').remove();
		});
	});
</script>