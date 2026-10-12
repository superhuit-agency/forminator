<?php
/**
 * The Forminator Aweber Form Hooks.
 *
 * @package Forminator
 */

/**
 * Class Forminator_Aweber_Form_Hooks
 *
 * @since 1.0 Aweber Integration
 */
class Forminator_Aweber_Form_Hooks extends Forminator_Integration_Form_Hooks {

	/**
	 * Subscribers added per submission, keyed by entry ID, list ID and email.
	 *
	 * Static, as each connection has its own hooks instance.
	 *
	 * @var array
	 */
	private static $added_subscribers = array();

	/**
	 * Return custom entry fields
	 *
	 * @param array $submitted_data Submitted data.
	 * @param array $current_entry_fields Current entry fields.
	 * @return array
	 */
	protected function custom_entry_fields( $submitted_data, $current_entry_fields ): array {
		$entry                = func_get_args()[2];
		$addon_setting_values = $this->settings_instance->get_settings_values();
		$data                 = array();

		foreach ( $addon_setting_values as $key => $addon_setting_value ) {
			// save it on entry field, with name `status-$MULTI_ID`, and value is the return result on sending data to aweber.
			if ( $this->settings_instance->is_multi_id_completed( $key ) ) {
				// exec only on completed connection.
				$data[] = array(
					'name'  => 'status-' . $key,
					'value' => $this->get_status_on_add_subscriber( $key, $submitted_data, $addon_setting_value, $current_entry_fields, $entry ),
				);
			}
		}

		return $data;
	}

	/**
	 * Get status on add subscriber to AWeber
	 *
	 * @since 1.0 AWeber Integration
	 *
	 * @param string                           $connection_id Connection Id.
	 * @param array                            $submitted_data Submitted data.
	 * @param array                            $connection_settings Connection settings.
	 * @param array                            $form_entry_fields Form entry fields.
	 * @param Forminator_Form_Entry_Model|null $entry Entry.
	 *
	 * @return array `is_sent` true means its success send data to AWeber, false otherwise.
	 * @throws Forminator_Integration_Exception Throws Integration Exception.
	 */
	private function get_status_on_add_subscriber( $connection_id, $submitted_data, $connection_settings, $form_entry_fields, $entry ) {
		// initialize as null.
		$api = null;

		$form_id                = $this->module_id;
		$entry_id               = $entry->entry_id ?? 0;
		$form_settings_instance = $this->settings_instance;
		$form_settings          = $this->settings_instance->get_form_settings();

		// check required fields.
		try {
			$api  = $this->addon->get_api();
			$args = array();

			if ( ! isset( $connection_settings['list_id'] ) ) {
				throw new Forminator_Integration_Exception( esc_html__( 'List ID not properly set up.', 'forminator' ) );
			}

			$list_id = $connection_settings['list_id'];

			$fields_map    = $connection_settings['fields_map'];
			$fields_mapper = $connection_settings['fields_mapper'];

			$email_element_id = $connection_settings['fields_map']['default_field_email'];
			if ( ! isset( $submitted_data[ $email_element_id ] ) || empty( $submitted_data[ $email_element_id ] ) ) {
				throw new Forminator_Integration_Exception(
					sprintf(
					/* translators: 1: Email field ID */
						esc_html__( 'Email Address on element %1$s not found or not filled on submitted data.', 'forminator' ),
						$email_element_id
					)
				);
			}
			$email         = $submitted_data[ $email_element_id ];
			$email         = strtolower( trim( $email ) );
			$args['email'] = $email;

			// find existing subscriber first.
			/**
			 * Filter arguments to passed on to Find Subscriber AWeber API
			 *
			 * @since 1.3
			 *
			 * @param array                                 $args
			 * @param int                                   $form_id                Current Form id.
			 * @param string                                $connection_id          ID of current connection.
			 * @param array                                 $submitted_data
			 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
			 * @param array                                 $form_entry_fields      default entry fields of form.
			 * @param array                                 $form_settings          Displayed Form settings.
			 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
			 */
			$args = apply_filters(
				'forminator_addon_aweber_find_subscriber_args',
				$args,
				$form_id,
				$connection_id,
				$submitted_data,
				$connection_settings,
				$form_entry_fields,
				$form_settings,
				$form_settings_instance
			);

			$subscriber_is_exist = false;
			$existing_subscriber = null;
			$setting_values      = $this->addon->get_settings_values();

			$existing_subscriber_request = $api->find_account_list_subscriber( $setting_values['account_id'], $list_id, $args );

			if ( isset( $existing_subscriber_request->entries ) && is_array( $existing_subscriber_request->entries ) ) {
				$existing_subscriber_entries = $existing_subscriber_request->entries;
				if ( isset( $existing_subscriber_entries[0] ) ) {
					$existing_subscriber = $existing_subscriber_entries[0];
					if ( isset( $existing_subscriber->id ) ) {
						$subscriber_is_exist = true;
						// https://labs.aweber.com/docs/reference/1.0#subscriber_entry.
						// you can not modify or delete Subscribers with a status of 'unconfirmed'.
						if ( isset( $existing_subscriber->status ) && 'unconfirmed' === $existing_subscriber->status ) {
							throw new Forminator_Integration_Exception( esc_html__( 'Unconfirmed subscriber can\'t be modified.', 'forminator' ) );
						}
					}
				}
			}

			// Only the owner may update an existing subscriber, unless this submission added it.
			if ( $subscriber_is_exist && empty( self::$added_subscribers[ $entry_id ][ $list_id ][ $email ] )
				&& ! $this->addon->can_update_subscriber( $email, $existing_subscriber, $form_id, $connection_id, $entry ) ) {
				throw new Forminator_Integration_Exception( esc_html__( 'Subscriber already exists, only its owner can update it.', 'forminator' ) );
			}

			// processed.
			unset( $fields_map['default_field_email'] );

			$name_element_id = $connection_settings['fields_map']['default_field_name'];

			if ( isset( $submitted_data[ $name_element_id ] ) ) {
				$args['name'] = $submitted_data[ $name_element_id ];
			}

			// processed.
			unset( $fields_map['default_field_name'] );

			$custom_fields = array();
			// process rest extra fields if available.
			foreach ( $fields_map as $field_id => $element_id ) {
				if ( empty( $element_id ) || ! isset( $fields_mapper[ $field_id ] ) || ! isset( $submitted_data[ $element_id ] ) ) {
					continue;
				}

				$custom_fields[ $fields_mapper[ $field_id ] ] = (string) $submitted_data[ $element_id ]; // custom value must be string.
			}
			if ( ! empty( $custom_fields ) ) {
				$args['custom_fields'] = $custom_fields;
			}

			if ( isset( $connection_settings['ad_tracking'] ) && ! empty( $connection_settings['ad_tracking'] ) ) {
				$ad_tracking = $connection_settings['ad_tracking'];

				// disable all_fields here.
				$ad_tracking = str_ireplace( '{all_fields}', '', $ad_tracking );
				$ad_tracking = forminator_addon_replace_custom_vars( $ad_tracking, $submitted_data, $this->module, $form_entry_fields, false );

				/**
				 * Filter `ad_tracking` to passed onto API
				 *
				 * @since 1.2
				 *
				 * @param string                                $card_name
				 * @param int                                   $form_id                Current Form id.
				 * @param string                                $connection_id          ID of current connection.
				 * @param array                                 $submitted_data
				 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
				 * @param array                                 $form_entry_fields      default entry fields of form.
				 * @param array                                 $form_settings          Displayed Form settings.
				 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
				 */
				$ad_tracking = apply_filters(
					'forminator_addon_aweber_subscriber_ad_tracking',
					$ad_tracking,
					$form_id,
					$connection_id,
					$submitted_data,
					$connection_settings,
					$form_entry_fields,
					$form_settings,
					$form_settings_instance
				);

				if ( ! empty( $ad_tracking ) && is_string( $ad_tracking ) ) {
					if ( strlen( $ad_tracking ) > 20 ) {
						// 20 chars max.
						$ad_tracking = substr( $ad_tracking, 0, 20 );
					}
					$args['ad_tracking'] = $ad_tracking;
				}
			}

			// Tags this connection may remove: its choice field options.
			$managed_tags = array();
			if ( isset( $connection_settings['tags'] ) && ! empty( $connection_settings['tags'] ) ) {
				$tags = array();
				foreach ( $connection_settings['tags'] as $tag ) {
					if ( stripos( $tag, '{' ) === 0
						&& stripos( $tag, '}' ) === ( strlen( $tag ) - 1 )
					) {
						// translate to value.
						$element_id = str_ireplace( '{', '', $tag );
						$element_id = str_ireplace( '}', '', $element_id );

						foreach ( Forminator_Aweber::get_choice_option_values( $this->module->get_field( $element_id ) ) as $option_value ) {
							foreach ( explode( ',', $option_value ) as $value ) {
								$managed_tags[] = sanitize_title( $value );
							}
						}

						if ( isset( $submitted_data[ $element_id ] ) ) {
							$field_tags = $submitted_data[ $element_id ];
							$field_tags = explode( ',', $field_tags );

							foreach ( $field_tags as $tag ) {
								$tags[] = sanitize_title( $tag );
							}
						}
					} else {
						$tags[] = sanitize_title( $tag );
					}
				}

				/**
				 * Filter `tags` to passed onto API
				 *
				 * @since 1.2
				 *
				 * @param string                                $card_name
				 * @param int                                   $form_id                Current Form id.
				 * @param string                                $connection_id          ID of current connection.
				 * @param array                                 $submitted_data
				 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
				 * @param array                                 $form_entry_fields      default entry fields of form.
				 * @param array                                 $form_settings          Displayed Form settings.
				 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
				 */
				$tags = apply_filters(
					'forminator_addon_aweber_subscriber_tags',
					$tags,
					$form_id,
					$connection_id,
					$submitted_data,
					$connection_settings,
					$form_entry_fields,
					$form_settings,
					$form_settings_instance
				);

				if ( ! empty( $tags ) ) {
					sort( $tags );
					$args['tags'] = $tags;
				}
			}

			$ip_address = Forminator_Geo::get_user_ip();

			/**
			 * Filter `ip_address` to passed onto API
			 *
			 * @since 1.2
			 *
			 * @param string                                $card_name
			 * @param int                                   $form_id                Current Form id.
			 * @param string                                $connection_id          ID of current connection.
			 * @param array                                 $submitted_data
			 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
			 * @param array                                 $form_entry_fields      default entry fields of form.
			 * @param array                                 $form_settings          Displayed Form settings.
			 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
			 */
			$ip_address = apply_filters(
				'forminator_addon_aweber_subscriber_ip_address',
				$ip_address,
				$form_id,
				$connection_id,
				$submitted_data,
				$connection_settings,
				$form_entry_fields,
				$form_settings,
				$form_settings_instance
			);

			$args['ip_address'] = $ip_address;

			if ( ! $subscriber_is_exist ) {
				/**
				 * Filter arguments to passed on to Add Subscriber AWeber API
				 *
				 * @since 1.3
				 *
				 * @param array                                 $args
				 * @param int                                   $form_id                Current Form id.
				 * @param string                                $connection_id          ID of current connection.
				 * @param array                                 $submitted_data
				 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
				 * @param array                                 $form_entry_fields      default entry fields of form.
				 * @param array                                 $form_settings          Displayed Form settings.
				 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
				 */
				$args = apply_filters(
					'forminator_addon_aweber_add_subscriber_args',
					$args,
					$form_id,
					$connection_id,
					$submitted_data,
					$connection_settings,
					$form_entry_fields,
					$form_settings,
					$form_settings_instance
				);
				$api->add_account_list_subscriber( $setting_values['account_id'], $list_id, $args );

				self::$added_subscribers[ $entry_id ][ $list_id ][ $email ] = true;
			} else {
				/**
				 * This will only update information
				 * subscribed, unconfirmed, unsubscribed status wont be updated
				 * use hooks @see forminator_addon_aweber_update_subscriber_args, if needed
				 */
				// update if exist.
				$current_tags = array();
				if ( isset( $existing_subscriber->tags ) && is_array( $existing_subscriber->tags ) ) {
					$current_tags = $existing_subscriber->tags;
				}

				if ( ! isset( $args['tags'] ) ) {
					$args['tags'] = array();
				}

				$add_tags    = array_diff( $args['tags'], $current_tags );
				$remove_tags = array_intersect( array_diff( $current_tags, $args['tags'] ), $managed_tags );

				sort( $add_tags );
				sort( $remove_tags );

				$new_tags = array(
					'add'    => $add_tags,
					'remove' => $remove_tags,
				);

				$args['tags'] = $new_tags;

				/**
				 * Filter arguments to passed on to Add Subscriber AWeber API
				 *
				 * @since 1.3
				 *
				 * @param array                                 $args
				 * @param int                                   $form_id                Current Form id.
				 * @param string                                $connection_id          ID of current connection.
				 * @param array                                 $submitted_data
				 * @param array                                 $connection_settings    current connection setting, contains options of like `name`, `list_id` etc.
				 * @param array                                 $form_entry_fields      default entry fields of form.
				 * @param array                                 $form_settings          Displayed Form settings.
				 * @param Forminator_Aweber_Form_Settings $form_settings_instance AWeber Integration Form Settings instance.
				 */
				$args = apply_filters(
					'forminator_addon_aweber_update_subscriber_args',
					$args,
					$form_id,
					$connection_id,
					$submitted_data,
					$connection_settings,
					$form_entry_fields,
					$form_settings,
					$form_settings_instance
				);
				$api->update_account_list_subscriber( $setting_values['account_id'], $list_id, $existing_subscriber->id, $args );
			}

			return array(
				'is_sent'         => true,
				'connection_name' => $connection_settings['name'],
				'description'     => esc_html__( 'Successfully send data to AWeber', 'forminator' ),
				'data_sent'       => $api->get_last_data_sent(),
				'data_received'   => $api->get_last_data_received(),
				'url_request'     => $api->get_last_url_request(),
				'list_id'         => $list_id, // for delete reference.
			);

		} catch ( Forminator_Integration_Exception $e ) {
			$addon_entry_fields = array(
				'is_sent'         => false,
				'description'     => $e->getMessage(),
				'connection_name' => $connection_settings['name'],
				'data_sent'       => ( ( $api instanceof Forminator_Aweber_Wp_Api ) ? $api->get_last_data_sent() : array() ),
				'data_received'   => ( ( $api instanceof Forminator_Aweber_Wp_Api ) ? $api->get_last_data_received() : array() ),
				'url_request'     => ( ( $api instanceof Forminator_Aweber_Wp_Api ) ? $api->get_last_url_request() : '' ),
			);

			return $addon_entry_fields;
		}
	}
}
