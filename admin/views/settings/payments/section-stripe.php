<?php
/**
 * Template admin/views/settings/payments/section-stripe.php
 *
 * @package Forminator
 */

$stripe_loaded           = forminator_payment_lib_stripe_version_loaded();
$stripe_is_configured    = false;
$forminator_currencies   = forminator_currency_list();
$stripe_default_currency = 'USD';

$stripe_oauth_available = $stripe_loaded && class_exists( 'Forminator_Stripe_Connect' );
$stripe_site_has_ssl    = $stripe_oauth_available ? Forminator_Stripe_Connect::site_has_ssl() : false;
$stripe_live_via_oauth  = $stripe_oauth_available && Forminator_Gateway_Stripe::is_oauth_connected( 'live' );
$stripe_test_via_oauth  = $stripe_oauth_available && Forminator_Gateway_Stripe::is_oauth_connected( 'test' );
$stripe_oauth_nonce     = $stripe_oauth_available ? wp_create_nonce( 'forminator_stripe_oauth' ) : '';

// Per-mode credential state used by the OAuth settings UI.
$stripe_live_configured = $stripe_oauth_available && Forminator_Gateway_Stripe::is_mode_configured( 'live' );
$stripe_test_configured = $stripe_oauth_available && Forminator_Gateway_Stripe::is_mode_configured( 'test' );

// OAuth UI uses stored credentials only; constants must not switch to the connected table.
$stripe_oauth_is_configured = $stripe_live_configured || $stripe_test_configured;

if ( $stripe_loaded ) {

	try {
		$stripe = new Forminator_Gateway_Stripe();

		$stripe_default_currency = $stripe->get_default_currency();
		if ( $stripe->is_test_ready() || $stripe->is_live_ready() ) {
			$stripe_is_configured = true;
		}
	} catch ( Forminator_Gateway_Exception $e ) {
		$stripe_is_configured = false;
	}
}
?>

<div class="sui-box-settings-col-1">

	<span class="sui-settings-label"><?php esc_html_e( 'Stripe', 'forminator' ); ?></span>

	<span class="sui-description"><?php esc_html_e( 'Use Stripe Checkout to process payments in your forms.', 'forminator' ); ?></span>

</div>

<div class="sui-box-settings-col-2">

	<?php if ( ! $stripe_loaded ) : ?>

		<div
			role="alert"
			class="sui-notice sui-notice-yellow sui-active"
			style="display: block; text-align: left;"
			aria-live="assertive"
		>

			<div class="sui-notice-content">

				<div class="sui-notice-message">

					<span class="sui-notice-icon sui-icon-info" aria-hidden="true"></span>

					<p><?php esc_html_e( 'Failed to load Stripe Library, possibly conflict with other plugins. Please contact our support .', 'forminator' ); ?></p>

				</div>

			</div>

		</div>

	<?php else : ?>

		<?php if ( $stripe_oauth_available ) : ?>

			<span class="sui-settings-label"><?php esc_html_e( 'Stripe Connect (OAuth)', 'forminator' ); ?></span>

			<span class="sui-description">
				<?php esc_html_e( 'Connect securely to Stripe in just a few clicks and start accepting payments with ease.', 'forminator' ); ?>
			</span>

			<?php
			$stripe_oauth_modes = array(
				'test' => array(
					'label'        => esc_html__( 'Test', 'forminator' ),
					'configured'   => $stripe_test_configured,
					'via_oauth'    => $stripe_test_via_oauth,
					'connect_text' => esc_html__( 'Connect Test', 'forminator' ),
					'disabled'     => false,
				),
				'live' => array(
					'label'        => esc_html__( 'Live', 'forminator' ),
					'configured'   => $stripe_live_configured,
					'via_oauth'    => $stripe_live_via_oauth,
					'connect_text' => esc_html__( 'Connect Live', 'forminator' ),
					'disabled'     => ! $stripe_site_has_ssl,
				),
			);
			?>

			<?php if ( $stripe_oauth_is_configured ) : ?>

				<table class="sui-table" style="margin-top: 10px;">

					<thead>
						<tr>
							<th><?php esc_html_e( 'Mode', 'forminator' ); ?></th>
							<th><?php esc_html_e( 'Status', 'forminator' ); ?></th>
							<th><?php esc_html_e( 'Account', 'forminator' ); ?></th>
							<th><?php esc_html_e( 'Action', 'forminator' ); ?></th>
						</tr>
					</thead>

					<tbody>

						<?php foreach ( $stripe_oauth_modes as $stripe_mode => $stripe_mode_data ) : ?>

							<tr>
								<td class="sui-table-title"><?php echo esc_html( $stripe_mode_data['label'] ); ?></td>
								<td>
									<?php if ( $stripe_mode_data['configured'] ) : ?>
										<span class="sui-tag sui-tag-green"><?php esc_html_e( 'Connected', 'forminator' ); ?></span>
									<?php else : ?>
										<span class="sui-tag"><?php esc_html_e( 'Disconnected', 'forminator' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<span style="display: block; word-break: break-word;">
										<?php
										if ( $stripe_mode_data['via_oauth'] ) {
											echo esc_html( Forminator_Gateway_Stripe::get_oauth_account_name( $stripe_mode ) );
										} elseif ( $stripe_mode_data['configured'] ) {
											esc_html_e( 'Configured manually', 'forminator' );
										}
										?>
									</span>
								</td>
								<td>
									<?php if ( $stripe_mode_data['configured'] ) : ?>

										<button
											class="sui-button sui-button-ghost wpmudev-open-modal"
											type="button"
											data-modal="disconnect-stripe"
											data-mode="<?php echo esc_attr( $stripe_mode ); ?>"
											data-modal-title="<?php esc_attr_e( 'Disconnect Stripe Account', 'forminator' ); ?>"
											data-modal-content="<?php esc_attr_e( 'Are you sure you want to disconnect this Stripe account? This will affect the forms using the Stripe field.', 'forminator' ); ?>"
											data-nonce="<?php echo esc_attr( $stripe_oauth_nonce ); ?>"
										>
											<span class="sui-loading-text"><?php esc_html_e( 'Disconnect', 'forminator' ); ?></span>
											<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
										</button>

									<?php else : ?>

										<button
											class="sui-button forminator-stripe-oauth-connect"
											type="button"
											data-mode="<?php echo esc_attr( $stripe_mode ); ?>"
											data-nonce="<?php echo esc_attr( $stripe_oauth_nonce ); ?>"
											<?php disabled( 'live' === $stripe_mode && ! $stripe_site_has_ssl ); ?>
										>
											<span class="sui-loading-text"><?php esc_html_e( 'Connect', 'forminator' ); ?></span>
											<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
										</button>

									<?php endif; ?>
								</td>
							</tr>

						<?php endforeach; ?>

					</tbody>

				</table>

			<?php else : ?>

				<div class="sui-form-field">
					<?php foreach ( $stripe_oauth_modes as $stripe_mode => $stripe_mode_data ) : ?>
						<button
							class="sui-button forminator-stripe-oauth-connect"
							type="button"
							data-mode="<?php echo esc_attr( $stripe_mode ); ?>"
							data-nonce="<?php echo esc_attr( $stripe_oauth_nonce ); ?>"
							<?php disabled( $stripe_mode_data['disabled'] ); ?>
						>
							<span class="sui-loading-text">
								<?php echo esc_html( $stripe_mode_data['connect_text'] ); ?>
							</span>
							<i class="sui-icon-loader sui-loading" aria-hidden="true"></i>
						</button>
					<?php endforeach; ?>
				</div>

			<?php endif; ?>

			<?php if ( ! $stripe_site_has_ssl ) : ?>

				<div
					role="alert"
					class="sui-notice sui-notice-blue sui-active"
					style="display: block; text-align: left; margin-top: 10px;"
					aria-live="assertive"
				>

					<div class="sui-notice-content">

						<div class="sui-notice-message">

							<span class="sui-notice-icon sui-icon-info" aria-hidden="true"></span>

							<p><?php esc_html_e( 'Live mode requires a valid SSL certificate. Please enable SSL on your site to connect a live Stripe account.', 'forminator' ); ?></p>

						</div>

					</div>

				</div>

			<?php endif; ?>

		<?php endif; ?>

		<?php if ( $stripe_is_configured || $stripe_live_configured || $stripe_test_configured ) : ?>

			<div class="sui-form-field" style="margin-top: 30px;">

				<label for="forminator-stripe-currency" class="sui-settings-label"><?php esc_html_e( 'Default charge currency', 'forminator' ); ?></label>

				<span class="sui-description" aria-describedby="forminator-stripe-currency"><?php esc_html_e( 'Choose the default charge currency for your Stripe payments. You can override this while setting up the Stripe field in your forms.', 'forminator' ); ?></span>

				<div style="max-width: 240px; display: block; margin-top: 10px;">

					<select class="sui-select" id="forminator-stripe-currency" name="stripe-default-currency">
						<?php foreach ( $forminator_currencies as $currency => $currency_nice ) : ?>
							<option value="<?php echo esc_attr( $currency ); ?>" <?php echo selected( $currency, $stripe_default_currency ); ?>><?php echo esc_html( $currency ); ?></option>
						<?php endforeach; ?>
					</select>

				</div>

			</div>

		<?php endif; ?>

	<?php endif; ?>

</div>
