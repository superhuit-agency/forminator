<?php
/**
 * Template admin/views/settings/tab-payments.php
 *
 * @package Forminator
 */

$section    = Forminator_Core::sanitize_text_field( 'section', 'dashboard' );
$plugin_url = forminator_plugin_url();
$nonce      = wp_create_nonce( 'forminator_save_payments_settings' );
?>

<div class="sui-box" data-nav="payments" style="<?php echo esc_attr( 'payments' !== $section ? 'display: none;' : '' ); ?>">

	<div class="sui-box-header">
		<h2 class="sui-box-title"><?php esc_html_e( 'Payments', 'forminator' ); ?></h2>
	</div>

	<form class="forminator-settings-save" action="">

		<div class="sui-box-body">

			<div class="sui-box-settings-row">
				<p>
					<?php
					esc_html_e( 'Configure your preferred payment processors here. You can select either live or test/sandbox mode for any form when configuring the Stripe or PayPal fields in the form.', 'forminator' );
					if ( forminator_is_show_documentation_link() ) {
						printf(
							/* translators: 1. Opening anchor tag for Stripe docs, 2. closing anchor tag, 3. opening anchor tag for PayPal docs, 4. closing anchor tag. */
							' ' . esc_html__( 'Learn more about %1$sStripe%2$s and %3$sPayPal%4$s payment modes.', 'forminator' ),
							'<a href="https://wpmudev.com/docs/wpmu-dev-plugins/forminator/#stripe" target="_blank" rel="noreferrer">',
							'</a>',
							'<a href="https://wpmudev.com/docs/wpmu-dev-plugins/forminator/#paypal-field" target="_blank" rel="noreferrer">',
							'</a>'
						);
					}
					?>
				</p>
			</div>

			<?php if ( class_exists( 'Forminator_Gateway_Stripe' ) ) : ?>
				<div id="sui-box-stripe" class="sui-box-settings-row">
					<?php $this->template( 'settings/payments/section-stripe' ); ?>
				</div>
			<?php endif; ?>

			<div id="sui-box-paypal" class="sui-box-settings-row">
				<?php $this->template( 'settings/payments/section-paypal' ); ?>
			</div>

		</div>

		<div class="sui-box-footer">

			<div class="sui-actions-right">

				<button
					class="sui-button sui-button-blue wpmudev-action-done"
					data-title="<?php esc_attr_e( 'Payments settings', 'forminator' ); ?>"
					data-action="payments_settings"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
				>
					<span class="sui-loading-text"><?php esc_html_e( 'Save Settings', 'forminator' ); ?></span>
					<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
				</button>

			</div>

		</div>

	</form>

</div>
