<?php
/**
 * Configurable SportsPress webhooks.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tony_Sportspress_Webhooks' ) ) {
	/**
	 * Manage Tony's Settings webhooks and event-triggered delivery.
	 */
	class Tony_Sportspress_Webhooks {

		/**
		 * Webhook settings option key.
		 */
		const OPTION_KEY = 'tse_sportspress_webhooks_settings';

		/**
		 * Webhook settings group.
		 */
		const OPTION_GROUP = 'tse_sportspress_webhooks';

		/**
		 * Tony's Settings tab slug.
		 */
		const TAB_WEBHOOKS = 'webhooks';

		/**
		 * Meta key used to suppress duplicate result notifications.
		 */
		const RESULTS_SIGNATURE_META_KEY = '_tse_sp_webhook_results_signature';

		/**
		 * Meta key used to suppress duplicate schedule notifications.
		 */
		const SCHEDULE_SIGNATURE_META_KEY = '_tse_sp_webhook_schedule_signature';

		/**
		 * Singleton instance.
		 *
		 * @var Tony_Sportspress_Webhooks|null
		 */
		private static $instance = null;

		/**
		 * Whether hooks were already registered.
		 *
		 * @var bool
		 */
		private $booted = false;

		/**
		 * Get singleton instance.
		 *
		 * @return Tony_Sportspress_Webhooks
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register the feature hooks.
		 *
		 * @return void
		 */
		public function boot() {
			if ( $this->booted ) {
				return;
			}

			$this->booted = true;

			add_filter( 'tse_tonys_settings_tabs', array( $this, 'register_settings_tab' ) );
			add_action( 'tse_tonys_settings_render_tab_' . self::TAB_WEBHOOKS, array( $this, 'render_settings_tab' ) );
			add_action( 'post_updated', array( $this, 'handle_event_schedule_update' ), 10, 3 );
			add_action( 'added_post_meta', array( $this, 'handle_event_results_meta_change' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'handle_event_results_meta_change' ), 10, 4 );
			add_action( 'deleted_post_meta', array( $this, 'handle_event_results_meta_change' ), 10, 4 );

			if ( is_admin() ) {
				add_action( 'admin_init', array( $this, 'register_settings' ) );
				add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
				add_action( 'wp_ajax_tse_sp_webhook_test', array( $this, 'handle_test_webhook_ajax' ) );
			}
		}

		/**
		 * Register the settings option.
		 *
		 * @return void
		 */
		public function register_settings() {
			register_setting(
				self::OPTION_GROUP,
				self::OPTION_KEY,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->default_settings(),
				)
			);
		}

		/**
		 * Capability required to save this option group.
		 *
		 * @return string
		 */
		public function settings_capability() {
			return 'manage_sportspress';
		}

		/**
		 * Add the Webhooks tab to Tony's Settings.
		 *
		 * @param array $tabs Existing tab labels.
		 * @return array
		 */
		public function register_settings_tab( $tabs ) {
			$tabs[ self::TAB_WEBHOOKS ] = __( 'Webhooks', 'tonys-sportspress-enhancements' );

			return $tabs;
		}

		/**
		 * Sanitize webhook settings.
		 *
		 * @param mixed $input Raw option payload.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$rows       = is_array( $input ) && isset( $input['webhooks'] ) && is_array( $input['webhooks'] ) ? $input['webhooks'] : array();
			$normalized = array();
			$row_number = 0;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				++$row_number;

				if ( $this->is_empty_webhook_row( $row ) ) {
					continue;
				}

				$sanitized = $this->sanitize_webhook_row( $row, true );
				if ( is_wp_error( $sanitized ) ) {
					add_settings_error(
						self::OPTION_GROUP,
						'tse_sp_webhooks_invalid_' . $row_number,
						sprintf( __( 'Webhook %1$d was skipped: %2$s', 'tonys-sportspress-enhancements' ), $row_number, $sanitized->get_error_message() ),
						'error'
					);
					continue;
				}

				if ( '' === $sanitized['name'] ) {
					$sanitized['name'] = sprintf(
						/* translators: %d: webhook row number. */
						__( 'Webhook %d', 'tonys-sportspress-enhancements' ),
						count( $normalized ) + 1
					);
				}

				$normalized[] = $sanitized;
			}

			return array(
				'webhooks' => $normalized,
			);
		}

		/**
		 * Render the Webhooks tab.
		 *
		 * @return void
		 */
		public function render_settings_tab() {
			if ( ! current_user_can( 'manage_sportspress' ) ) {
				return;
			}

			$settings = $this->get_settings();
			$webhooks = isset( $settings['webhooks'] ) && is_array( $settings['webhooks'] ) ? $settings['webhooks'] : array();

			settings_errors( self::OPTION_GROUP );

			echo '<form method="post" action="options.php">';
			settings_fields( self::OPTION_GROUP );
			wp_referer_field();

			echo '<h2>' . esc_html__( 'SportsPress Webhooks', 'tonys-sportspress-enhancements' ) . '</h2>';
			echo '<p>' . esc_html__( 'Create multiple outbound notifications for SportsPress events. Each webhook can listen for schedule changes, result updates, or both, then send the rendered message to the selected destination type.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Google Chat incoming webhooks send JSON with a text field. GroupMe bot delivery sends bot_id and text to the GroupMe bots endpoint. Generic JSON sends a richer payload with message and context.', 'tonys-sportspress-enhancements' ) . '</p>';

			echo '<div style="margin:18px 0 12px;padding:16px 18px;border:1px solid #dcdcde;background:#fff;max-width:1100px;">';
			echo '<strong>' . esc_html__( 'Message template variables', 'tonys-sportspress-enhancements' ) . '</strong>';
			echo '<p style="margin:8px 0 0;">';
			echo esc_html__( 'Use Jinja-style placeholders such as', 'tonys-sportspress-enhancements' ) . ' ';
			echo '<code>{{ event.title }}</code>, <code>{{ event.permalink }}</code>, <code>{{ trigger.key }}</code>, <code>{{ changes.previous.local_display }}</code>, <code>{{ changes.current.local_display }}</code>, <code>{{ results.summary }}</code>, ';
			echo esc_html__( 'or serialize values safely for JSON with', 'tonys-sportspress-enhancements' ) . ' <code>{{ event|tojson }}</code> ' . esc_html__( 'and', 'tonys-sportspress-enhancements' ) . ' <code>{{ event.title|tojson }}</code>. ';
			echo esc_html__( 'The rendered template becomes the outgoing message text.', 'tonys-sportspress-enhancements' );
			echo '</p>';
			echo '</div>';

			$this->render_template_tag_reference();

			echo '<div id="tse-webhook-rows" style="display:grid;gap:16px;max-width:1100px;">';
			if ( empty( $webhooks ) ) {
				$this->render_webhook_row( $this->default_webhook(), 0 );
			} else {
				foreach ( array_values( $webhooks ) as $index => $webhook ) {
					$this->render_webhook_row( $webhook, $index );
				}
			}
			echo '</div>';

			echo '<p style="margin-top:16px;"><button type="button" class="button" id="tse-webhook-add">' . esc_html__( 'Add Webhook', 'tonys-sportspress-enhancements' ) . '</button></p>';

			submit_button( __( 'Save Webhooks', 'tonys-sportspress-enhancements' ) );
			echo '</form>';

			echo '<script type="text/html" id="tmpl-tse-webhook-row">';
			$this->render_webhook_row( $this->default_webhook(), '__index__' );
			echo '</script>';

			$this->render_settings_script();
		}

		/**
		 * Send notifications when an event date/time changes.
		 *
		 * @param int     $post_id    Event post ID.
		 * @param WP_Post $post_after Updated post object.
		 * @param WP_Post $post_before Previous post object.
		 * @return void
		 */
		public function handle_event_schedule_update( $post_id, $post_after, $post_before ) {
			if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
				return;
			}

			if ( ! $this->should_handle_event_post( $post_id, $post_after ) ) {
				return;
			}

			$previous = $this->event_schedule_from_post( $post_before );
			$current  = $this->event_schedule_from_post( $post_after );

			if ( $previous['local_iso'] === $current['local_iso'] && $previous['gmt_iso'] === $current['gmt_iso'] ) {
				return;
			}

			$signature = md5( $current['gmt_iso'] . '|' . $current['local_iso'] );
			if ( $signature === (string) get_post_meta( $post_id, self::SCHEDULE_SIGNATURE_META_KEY, true ) ) {
				return;
			}

			update_post_meta( $post_id, self::SCHEDULE_SIGNATURE_META_KEY, $signature );

			$this->dispatch_trigger(
				'event_datetime_changed',
				$post_id,
				array(
					'previous' => $previous,
					'current'  => $current,
				)
			);
		}

		/**
		 * Send notifications when results metadata changes.
		 *
		 * @param mixed  $meta_id_or_ids Meta identifier.
		 * @param int    $post_id        Event post ID.
		 * @param string $meta_key       Meta key.
		 * @param mixed  $meta_value     Meta value.
		 * @return void
		 */
		public function handle_event_results_meta_change( $meta_id_or_ids, $post_id, $meta_key, $meta_value ) {
			unset( $meta_id_or_ids, $meta_value );

			if ( 'sp_results' !== $meta_key ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $this->should_handle_event_post( $post_id, $post ) ) {
				return;
			}

			$results = get_post_meta( $post_id, 'sp_results', true );

			if ( ! $this->has_meaningful_results( $results ) ) {
				delete_post_meta( $post_id, self::RESULTS_SIGNATURE_META_KEY );
				return;
			}

			$signature = md5( (string) wp_json_encode( $results ) );
			if ( $signature === (string) get_post_meta( $post_id, self::RESULTS_SIGNATURE_META_KEY, true ) ) {
				return;
			}

			update_post_meta( $post_id, self::RESULTS_SIGNATURE_META_KEY, $signature );

			$this->dispatch_trigger(
				'event_results_updated',
				$post_id,
				array(
					'current' => is_array( $results ) ? $results : array(),
				)
			);
		}

		/**
		 * Render one webhook settings card.
		 *
		 * @param array      $webhook Webhook configuration.
		 * @param int|string $index   Array index placeholder.
		 * @return void
		 */
		private function render_webhook_row( $webhook, $index ) {
			$webhook = wp_parse_args( is_array( $webhook ) ? $webhook : array(), $this->default_webhook() );
			$base    = self::OPTION_KEY . '[webhooks][' . $index . ']';
			$destination_config = $this->get_destination_field_config( isset( $webhook['provider'] ) ? $webhook['provider'] : 'generic_json' );

			if ( ! is_int( $index ) && ! ctype_digit( (string) $index ) ) {
				$webhook['id'] = '';
			}

			echo '<section class="tse-webhook-row" style="padding:18px 20px;border:1px solid #dcdcde;background:#fff;">';
			echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;">';
			echo '<h3 data-tse-webhook-label style="margin:0;">' . esc_html__( 'Webhook', 'tonys-sportspress-enhancements' ) . '</h3>';
			echo '<button type="button" class="button-link-delete" data-tse-remove-webhook="1">' . esc_html__( 'Remove', 'tonys-sportspress-enhancements' ) . '</button>';
			echo '</div>';

			echo '<input type="hidden" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( $webhook['id'] ) . '" />';

			echo '<p>';
			echo '<label style="display:inline-flex;align-items:center;gap:6px;">';
			echo '<input type="checkbox" name="' . esc_attr( $base . '[enabled]' ) . '" value="1" ' . checked( '1' === $webhook['enabled'], true, false ) . ' />';
			echo esc_html__( 'Enabled', 'tonys-sportspress-enhancements' );
			echo '</label>';
			echo '</p>';

			echo '<table class="form-table" role="presentation" style="margin-top:0;"><tbody>';

			echo '<tr>';
			echo '<th scope="row"><label>' . esc_html__( 'Name', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td><input type="text" class="regular-text" name="' . esc_attr( $base . '[name]' ) . '" value="' . esc_attr( $webhook['name'] ) . '" placeholder="' . esc_attr__( 'Game Time Updates', 'tonys-sportspress-enhancements' ) . '" /></td>';
			echo '</tr>';

			echo '<tr>';
			echo '<th scope="row"><label>' . esc_html__( 'Triggers', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td>';
			foreach ( $this->trigger_labels() as $trigger => $label ) {
				$input_id = sanitize_html_class( 'tse-webhook-' . $index . '-' . $trigger );
				echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;margin:0 18px 8px 0;">';
				echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" name="' . esc_attr( $base . '[triggers][]' ) . '" value="' . esc_attr( $trigger ) . '" ' . checked( in_array( $trigger, $webhook['triggers'], true ), true, false ) . ' />';
				echo esc_html( $label );
				echo '</label>';
			}
			echo '<p class="description" style="margin:0;">' . esc_html__( 'Create separate webhook rows if each trigger should send to a different destination or use a different body template.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '</td>';
			echo '</tr>';

			echo '<tr>';
			echo '<th scope="row"><label>' . esc_html__( 'Destination Type', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td>';
			echo '<select name="' . esc_attr( $base . '[provider]' ) . '">';
			foreach ( $this->provider_labels() as $provider_key => $provider_label ) {
				echo '<option value="' . esc_attr( $provider_key ) . '" ' . selected( $webhook['provider'], $provider_key, false ) . '>' . esc_html( $provider_label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Choose how this message is delivered.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '</td>';
			echo '</tr>';

			echo '<tr>';
			echo '<th scope="row"><label>' . esc_html( $destination_config['label'] ) . '</label></th>';
			echo '<td>';
			echo '<input type="text" class="large-text code" name="' . esc_attr( $base . '[url]' ) . '" value="' . esc_attr( $webhook['url'] ) . '" placeholder="' . esc_attr( $destination_config['placeholder'] ) . '" />';
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html( $destination_config['description'] ) . '</p>';
			echo '</td>';
			echo '</tr>';

			echo '<tr>';
			echo '<th scope="row"><label>' . esc_html__( 'Message Template', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td>';
			echo '<textarea class="large-text code" rows="11" name="' . esc_attr( $base . '[template]' ) . '">' . esc_textarea( $webhook['template'] ) . '</textarea>';
			echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'Example message:', 'tonys-sportspress-enhancements' ) . ' <code>{{ trigger.label }} - {{ event.title }} - {{ results.summary }}</code></p>';
			echo '<p style="margin:10px 0 0 0;">';
			echo '<label for="' . esc_attr( 'tse-webhook-test-event-' . $index ) . '" style="display:block;font-weight:600;margin-bottom:6px;">' . esc_html__( 'Test Event', 'tonys-sportspress-enhancements' ) . '</label>';
			echo '<select id="' . esc_attr( 'tse-webhook-test-event-' . $index ) . '" name="' . esc_attr( 'tse_sp_webhook_test_event[' . $index . ']' ) . '" style="min-width:360px;max-width:100%;">';
			echo '<option value="0">' . esc_html__( 'Use sample event data', 'tonys-sportspress-enhancements' ) . '</option>';
			foreach ( $this->get_test_event_options() as $event_option ) {
				echo '<option value="' . esc_attr( (string) $event_option['id'] ) . '">' . esc_html( $event_option['label'] ) . '</option>';
			}
			echo '</select>';
			echo '<span class="description" style="display:block;margin-top:4px;">' . esc_html__( 'Choose a real SportsPress event to test the exact title, image, results, and links for that event.', 'tonys-sportspress-enhancements' ) . '</span>';
			echo '</p>';
			echo '<p style="margin:10px 0 0;">';
			echo '<button type="button" class="button" data-tse-send-test="1">' . esc_html__( 'Send Test', 'tonys-sportspress-enhancements' ) . '</button> ';
			echo '<span class="description" data-tse-test-status="1"></span>';
			echo '</p>';
			echo '</td>';
			echo '</tr>';

			echo '</tbody></table>';
			echo '</section>';
		}

		/**
		 * Render the repeatable-row admin script.
		 *
		 * @return void
		 */
		private function render_settings_script() {
			$ajax_url = admin_url( 'admin-ajax.php' );
			$nonce    = wp_create_nonce( 'tse_sp_webhook_test' );
			?>
			<script>
			(function(){
				var container = document.getElementById('tse-webhook-rows');
				var addButton = document.getElementById('tse-webhook-add');
				var template = document.getElementById('tmpl-tse-webhook-row');
				var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;

				if (!container || !addButton || !template) {
					return;
				}

				function syncLabels() {
					Array.prototype.slice.call(container.querySelectorAll('.tse-webhook-row')).forEach(function(row, index) {
						var label = row.querySelector('[data-tse-webhook-label]');
						if (label) {
							label.textContent = 'Webhook ' + (index + 1);
						}
					});
				}

				addButton.addEventListener('click', function(event) {
					event.preventDefault();
					var index = container.querySelectorAll('.tse-webhook-row').length;
					var wrapper = document.createElement('div');
					wrapper.innerHTML = template.innerHTML.replace(/__index__/g, String(index));
					if (wrapper.firstElementChild) {
						container.appendChild(wrapper.firstElementChild);
						syncLabels();
					}
				});

				container.addEventListener('click', function(event) {
					var button = event.target.closest('[data-tse-remove-webhook]');
					if (!button) {
						button = event.target.closest('[data-tse-send-test]');
						if (!button) {
							return;
						}

						event.preventDefault();
						var testRow = button.closest('.tse-webhook-row');
						var status = testRow ? testRow.querySelector('[data-tse-test-status]') : null;
						var formData = new FormData();

						formData.append('action', 'tse_sp_webhook_test');
						formData.append('nonce', nonce);

						if (testRow) {
							Array.prototype.slice.call(testRow.querySelectorAll('input, textarea, select')).forEach(function(field) {
								if (!field.name || field.disabled) {
									return;
								}

								if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
									return;
								}

								formData.append(field.name, field.value);
							});
						}

						if (status) {
							status.textContent = 'Sending test...';
						}

						fetch(ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						})
							.then(function(response) {
								return response.json();
							})
							.then(function(data) {
								if (!status) {
									return;
								}

								if (data && data.success && data.data && data.data.message) {
									status.textContent = data.data.message;
									return;
								}

								status.textContent = (data && data.data && data.data.message) ? data.data.message : 'Test failed.';
							})
							.catch(function() {
								if (status) {
									status.textContent = 'Test failed.';
								}
							});
						return;
					}

					event.preventDefault();
					var row = button.closest('.tse-webhook-row');
					if (row) {
						row.remove();
						syncLabels();
					}
				});

				syncLabels();
			})();
			</script>
			<?php
		}

		/**
		 * Render a collapsible template-tag reference.
		 *
		 * @return void
		 */
		private function render_template_tag_reference() {
			echo '<details style="max-width:1100px;margin:0 0 16px;padding:0;border:1px solid #dcdcde;background:#fff;">';
			echo '<summary style="cursor:pointer;padding:14px 18px;font-weight:600;">' . esc_html__( 'Available Template Tags', 'tonys-sportspress-enhancements' ) . '</summary>';
			echo '<div style="padding:0 18px 18px;">';
			echo '<p>' . esc_html__( 'Use these Jinja-style tags inside the message template. Dot notation works for nested values.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr><th style="width:32%;">' . esc_html__( 'Tag', 'tonys-sportspress-enhancements' ) . '</th><th>' . esc_html__( 'Description', 'tonys-sportspress-enhancements' ) . '</th></tr></thead><tbody>';
			foreach ( $this->template_tag_definitions() as $tag_definition ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $tag_definition['tag'] ) . '</code></td>';
				echo '<td>' . esc_html( $tag_definition['description'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
			echo '</details>';
		}

		/**
		 * Get template tag definitions for the admin reference.
		 *
		 * @return array[]
		 */
		private function template_tag_definitions() {
			return array(
				array(
					'tag'         => '{{ trigger.key }}',
					'description' => __( 'Trigger slug such as event_datetime_changed or event_results_updated.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ trigger.label }}',
					'description' => __( 'Human-readable trigger label.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ webhook.name }}',
					'description' => __( 'The name configured for this webhook row.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ site.name }}',
					'description' => __( 'The WordPress site name.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.id }}',
					'description' => __( 'SportsPress event post ID.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.title }}',
					'description' => __( 'Formatted matchup title for the event.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.permalink }}',
					'description' => __( 'Public event permalink.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.image }}',
					'description' => __( 'Same matchup image URL used in the Open Graph tags.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.matchup_image }}',
					'description' => __( 'Alias for event.image.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.scheduled.local_display }}',
					'description' => __( 'Scheduled date/time in the site timezone.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.teams.0.name }}',
					'description' => __( 'First team name in event order.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event.venue.name }}',
					'description' => __( 'Primary venue name.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ results.summary }}',
					'description' => __( 'Result summary string when scores exist.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ changes.previous.local_display }}',
					'description' => __( 'Previous scheduled date/time for date/time change notifications.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ changes.current.local_display }}',
					'description' => __( 'Current scheduled date/time for date/time change notifications.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ occurred_at.local_display }}',
					'description' => __( 'Timestamp of when the notification was generated.', 'tonys-sportspress-enhancements' ),
				),
				array(
					'tag'         => '{{ event|tojson }}',
					'description' => __( 'Serialize a nested object as JSON.', 'tonys-sportspress-enhancements' ),
				),
			);
		}

		/**
		 * Get recent event options for admin test sends.
		 *
		 * @return array[]
		 */
		private function get_test_event_options() {
			$posts = get_posts(
				array(
					'post_type'      => 'sp_event',
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'posts_per_page' => 25,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$options = array();
			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$timestamp = get_post_timestamp( $post );
				$label     = get_the_title( $post );

				if ( $timestamp ) {
					$label .= ' | ' . wp_date( 'Y-m-d g:i A', $timestamp, wp_timezone() );
				}

				$options[] = array(
					'id'    => (int) $post->ID,
					'label' => $label,
				);
			}

			return $options;
		}

		/**
		 * Get the configured webhooks option with defaults.
		 *
		 * @return array
		 */
		private function get_settings() {
			return wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->default_settings() );
		}

		/**
		 * Get the option defaults.
		 *
		 * @return array
		 */
		private function default_settings() {
			return array(
				'webhooks' => array(),
			);
		}

		/**
		 * Get the default webhook row.
		 *
		 * @return array
		 */
		private function default_webhook() {
			return array(
				'id'       => sanitize_key( wp_generate_uuid4() ),
				'enabled'  => '1',
				'name'     => '',
				'provider' => 'generic_json',
				'url'      => '',
				'triggers' => array(),
				'template' => $this->default_template(),
			);
		}

		/**
		 * Get supported delivery providers.
		 *
		 * @return array
		 */
		private function provider_labels() {
			return array(
				'generic_json' => __( 'Generic JSON', 'tonys-sportspress-enhancements' ),
				'google_chat'  => __( 'Google Chat', 'tonys-sportspress-enhancements' ),
				'groupme_bot'  => __( 'GroupMe Bot', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Get destination field copy for a provider.
		 *
		 * @param string $provider Delivery provider key.
		 * @return array
		 */
		private function get_destination_field_config( $provider ) {
			switch ( $provider ) {
				case 'groupme_bot':
					return array(
						'label'       => __( 'Destination (GroupMe bot_id)', 'tonys-sportspress-enhancements' ),
						'placeholder' => '123456789012345678',
						'description' => __( 'Enter only the GroupMe bot_id. Do not enter https://api.groupme.com/v3/bots/post; the plugin uses that endpoint automatically.', 'tonys-sportspress-enhancements' ),
					);

				case 'google_chat':
					return array(
						'label'       => __( 'Destination (Google Chat webhook URL)', 'tonys-sportspress-enhancements' ),
						'placeholder' => 'https://chat.googleapis.com/v1/spaces/SPACE_ID/messages?key=KEY&token=TOKEN',
						'description' => __( 'Paste the full Google Chat incoming webhook URL.', 'tonys-sportspress-enhancements' ),
					);

				case 'generic_json':
				default:
					return array(
						'label'       => __( 'Destination (Webhook URL)', 'tonys-sportspress-enhancements' ),
						'placeholder' => 'https://example.com/webhooks/sportspress',
						'description' => __( 'Enter the HTTP or HTTPS URL that should receive the JSON payload.', 'tonys-sportspress-enhancements' ),
					);
			}
		}

		/**
		 * Get trigger labels.
		 *
		 * @return array
		 */
		private function trigger_labels() {
			return array(
				'event_datetime_changed' => __( 'Game date/time changes', 'tonys-sportspress-enhancements' ),
				'event_results_updated'  => __( 'Results updated', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Get the default webhook request body.
		 *
		 * @return string
		 */
		private function default_template() {
			return "{{ trigger.label }}\n{{ event.title }}\n{{ event.scheduled.local_display }}\n{{ results.summary }}\n{{ event.permalink }}";
		}

		/**
		 * Handle an admin AJAX test-send request.
		 *
		 * @return void
		 */
		public function handle_test_webhook_ajax() {
			if ( ! current_user_can( 'manage_sportspress' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to send test webhooks.', 'tonys-sportspress-enhancements' ),
					),
					403
				);
			}

			check_ajax_referer( 'tse_sp_webhook_test', 'nonce' );

			$raw_settings = isset( $_POST[ self::OPTION_KEY ] ) ? wp_unslash( $_POST[ self::OPTION_KEY ] ) : array();
			$rows         = is_array( $raw_settings ) && isset( $raw_settings['webhooks'] ) && is_array( $raw_settings['webhooks'] ) ? array_values( $raw_settings['webhooks'] ) : array();
			$row          = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
			$test_events  = isset( $_POST['tse_sp_webhook_test_event'] ) && is_array( $_POST['tse_sp_webhook_test_event'] ) ? wp_unslash( $_POST['tse_sp_webhook_test_event'] ) : array();
			$test_event_id = isset( $test_events[0] ) ? absint( $test_events[0] ) : 0;

			if ( empty( $row ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'No webhook row was submitted.', 'tonys-sportspress-enhancements' ),
					),
					400
				);
			}

			$webhook = $this->sanitize_webhook_row( $row, false );
			if ( is_wp_error( $webhook ) ) {
				wp_send_json_error(
					array(
						'message' => $webhook->get_error_message(),
					),
					400
				);
			}

			$trigger = ! empty( $webhook['triggers'] ) ? $webhook['triggers'][0] : 'manual_test';
			$context = $this->build_test_context( $trigger, $webhook, $test_event_id );
			$result  = $this->deliver_webhook( $webhook['url'], $webhook['template'], $webhook, $context );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
					),
					500
				);
			}

			$status_code = isset( $result['status_code'] ) ? (int) $result['status_code'] : 0;

			wp_send_json_success(
				array(
					'message' => sprintf( __( 'Test sent successfully. Remote response: HTTP %d.', 'tonys-sportspress-enhancements' ), $status_code ),
					'status_code' => $status_code,
				)
			);
		}

		/**
		 * Sanitize a provider-specific destination.
		 *
		 * @param string $url      Raw destination value.
		 * @param string $provider Delivery provider.
		 * @return string
		 */
		private function sanitize_destination_url( $url, $provider ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '';
			}

			if ( 'groupme_bot' === $provider ) {
				return preg_match( '/^[A-Za-z0-9_-]+$/', $url ) ? $url : '';
			}

			$validated = wp_http_validate_url( $url );
			if ( ! $validated ) {
				return '';
			}

			$scheme = strtolower( (string) wp_parse_url( $validated, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return '';
			}

			return esc_url_raw( $validated, array( 'http', 'https' ) );
		}

		/**
		 * Sanitize a stored template.
		 *
		 * @param mixed $template Raw template.
		 * @return string
		 */
		private function sanitize_template( $template ) {
			$template = is_string( $template ) ? wp_unslash( $template ) : '';
			$template = str_replace( array( "\r\n", "\r" ), "\n", $template );
			$template = wp_check_invalid_utf8( $template );
			$template = wp_kses_no_null( $template );

			return trim( $template );
		}

		/**
		 * Determine whether a row is effectively empty.
		 *
		 * @param mixed $row Candidate row.
		 * @return bool
		 */
		private function is_empty_webhook_row( $row ) {
			if ( ! is_array( $row ) ) {
				return true;
			}

			$name     = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( (string) $row['name'] ) ) : '';
			$url_raw  = isset( $row['url'] ) ? trim( wp_unslash( (string) $row['url'] ) ) : '';
			$template = isset( $row['template'] ) ? $this->sanitize_template( $row['template'] ) : '';
			$triggers = isset( $row['triggers'] ) && is_array( $row['triggers'] ) ? $row['triggers'] : array();

			return '' === $name
				&& '' === $url_raw
				&& empty( $triggers )
				&& ( '' === $template || $this->default_template() === $template );
		}

		/**
		 * Sanitize a single webhook row.
		 *
		 * @param array $row              Raw row payload.
		 * @param bool  $require_triggers Whether at least one trigger is required.
		 * @return array|WP_Error
		 */
		private function sanitize_webhook_row( $row, $require_triggers ) {
			$name     = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( (string) $row['name'] ) ) : '';
			$url_raw  = isset( $row['url'] ) ? trim( wp_unslash( (string) $row['url'] ) ) : '';
			$template = isset( $row['template'] ) ? $this->sanitize_template( $row['template'] ) : '';
			$enabled  = ! empty( $row['enabled'] ) ? '1' : '0';
			$id       = isset( $row['id'] ) ? sanitize_key( wp_unslash( (string) $row['id'] ) ) : '';
			$provider = isset( $row['provider'] ) ? sanitize_key( wp_unslash( (string) $row['provider'] ) ) : 'generic_json';
			$triggers = array();

			if ( ! isset( $this->provider_labels()[ $provider ] ) ) {
				$provider = 'generic_json';
			}

			if ( isset( $row['triggers'] ) && is_array( $row['triggers'] ) ) {
				$triggers = array_map( 'sanitize_key', array_map( 'wp_unslash', $row['triggers'] ) );
				$triggers = array_values( array_intersect( $triggers, array_keys( $this->trigger_labels() ) ) );
			}

			$url = $this->sanitize_destination_url( $url_raw, $provider );
			if ( '' === $url ) {
				if ( 'groupme_bot' === $provider ) {
					return new WP_Error( 'invalid_url', __( 'GroupMe delivery requires a valid bot_id.', 'tonys-sportspress-enhancements' ) );
				}

				if ( 'google_chat' === $provider ) {
					return new WP_Error( 'invalid_url', __( 'Google Chat delivery requires a valid incoming webhook URL.', 'tonys-sportspress-enhancements' ) );
				}

				return new WP_Error( 'invalid_url', __( 'Generic JSON delivery requires a valid HTTP or HTTPS URL.', 'tonys-sportspress-enhancements' ) );
			}

			if ( $require_triggers && empty( $triggers ) ) {
				return new WP_Error( 'missing_triggers', __( 'At least one trigger is required.', 'tonys-sportspress-enhancements' ) );
			}

			if ( '' === $template ) {
				$template = $this->default_template();
			}

			if ( '' === $id ) {
				$id = sanitize_key( wp_generate_uuid4() );
			}

			return array(
				'id'       => $id,
				'enabled'  => $enabled,
				'name'     => $name,
				'provider' => $provider,
				'url'      => $url,
				'triggers' => $triggers,
				'template' => $template,
			);
		}

		/**
		 * Determine whether a post should trigger SportsPress webhooks.
		 *
		 * @param int          $post_id Event post ID.
		 * @param WP_Post|bool $post    Post object.
		 * @return bool
		 */
		private function should_handle_event_post( $post_id, $post ) {
			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			if ( 'sp_event' !== $post->post_type ) {
				return false;
			}

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Build schedule metadata for an event post object.
		 *
		 * @param WP_Post $post Event post object.
		 * @return array
		 */
		private function event_schedule_from_post( $post ) {
			$timezone = wp_timezone();
			$utc      = new DateTimeZone( 'UTC' );
			$empty    = array(
				'local_iso'     => '',
				'local_display' => '',
				'gmt_iso'       => '',
				'timestamp'     => 0,
			);

			if ( ! $post instanceof WP_Post ) {
				return $empty;
			}

			$local = null;
			$gmt   = null;

			if ( ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
				$gmt   = new DateTimeImmutable( $post->post_date_gmt, $utc );
				$local = $gmt->setTimezone( $timezone );
			} elseif ( ! empty( $post->post_date ) && '0000-00-00 00:00:00' !== $post->post_date ) {
				$local = new DateTimeImmutable( $post->post_date, $timezone );
				$gmt   = $local->setTimezone( $utc );
			}

			if ( ! $local instanceof DateTimeImmutable || ! $gmt instanceof DateTimeImmutable ) {
				return $empty;
			}

			return array(
				'local_iso'     => $local->format( DATE_ATOM ),
				'local_display' => wp_date( 'Y-m-d g:i A T', $local->getTimestamp(), $timezone ),
				'gmt_iso'       => $gmt->format( DATE_ATOM ),
				'timestamp'     => $local->getTimestamp(),
			);
		}

		/**
		 * Build the template/render context for a webhook delivery.
		 *
		 * @param int    $post_id Event post ID.
		 * @param string $trigger Trigger slug.
		 * @param array  $changes Trigger-specific change data.
		 * @param array  $webhook Webhook configuration.
		 * @return array
		 */
		private function build_context( $post_id, $trigger, $changes, $webhook ) {
			$post        = get_post( $post_id );
			$schedule    = $this->event_schedule_from_post( $post );
			$results     = get_post_meta( $post_id, 'sp_results', true );
			$teams       = $this->get_event_teams( $post_id );
			$venue       = $this->get_event_venue( $post_id );
			$event_title = $this->get_event_title( $post_id );
			$results_arr = is_array( $results ) ? $results : array();
			$results_sum = $this->get_results_summary( $post );
			$now         = new DateTimeImmutable( 'now', wp_timezone() );

			$context = array(
				'trigger' => array(
					'key'   => $trigger,
					'label' => isset( $this->trigger_labels()[ $trigger ] ) ? $this->trigger_labels()[ $trigger ] : $trigger,
				),
				'webhook' => array(
					'id'   => isset( $webhook['id'] ) ? (string) $webhook['id'] : '',
					'name' => isset( $webhook['name'] ) ? (string) $webhook['name'] : '',
					'url'  => isset( $webhook['url'] ) ? (string) $webhook['url'] : '',
				),
				'site' => array(
					'name'     => get_bloginfo( 'name' ),
					'url'      => home_url( '/' ),
					'timezone' => wp_timezone_string(),
				),
				'event' => array(
					'id'         => $post instanceof WP_Post ? (int) $post->ID : 0,
					'title'      => $event_title,
					'raw_title'  => $post instanceof WP_Post ? get_the_title( $post ) : '',
					'permalink'  => $post instanceof WP_Post ? get_permalink( $post ) : '',
					'image'      => $post instanceof WP_Post && function_exists( 'asc_sp_event_matchup_image_url' ) ? asc_sp_event_matchup_image_url( $post ) : '',
					'matchup_image' => $post instanceof WP_Post && function_exists( 'asc_sp_event_matchup_image_url' ) ? asc_sp_event_matchup_image_url( $post ) : '',
					'edit_url'   => $post instanceof WP_Post ? get_edit_post_link( $post->ID, 'raw' ) : '',
					'post_status' => $post instanceof WP_Post ? (string) $post->post_status : '',
					'sp_status'  => (string) get_post_meta( $post_id, 'sp_status', true ),
					'scheduled'  => $schedule,
					'teams'      => $teams,
					'venue'      => $venue,
				),
				'changes' => is_array( $changes ) ? $changes : array(),
				'results' => array(
					'summary' => $results_sum,
					'data'    => $results_arr,
				),
				'occurred_at' => array(
					'local_iso'     => $now->format( DATE_ATOM ),
					'local_display' => wp_date( 'Y-m-d g:i A T', $now->getTimestamp(), wp_timezone() ),
				),
			);

			/**
			 * Filter the webhook render context before dispatch.
			 *
			 * @param array  $context Event context.
			 * @param int    $post_id Event post ID.
			 * @param string $trigger Trigger slug.
			 * @param array  $changes Trigger-specific data.
			 * @param array  $webhook Webhook configuration.
			 */
			return apply_filters( 'tse_sp_webhook_context', $context, $post_id, $trigger, $changes, $webhook );
		}

		/**
		 * Build a sample context for manual test sends.
		 *
		 * @param string $trigger Trigger slug.
		 * @param array  $webhook Webhook configuration.
		 * @return array
		 */
		private function build_test_context( $trigger, $webhook, $event_id = 0 ) {
			$event_id = absint( $event_id );
			$post     = $event_id > 0 ? get_post( $event_id ) : null;

			if ( $post instanceof WP_Post && 'sp_event' === $post->post_type ) {
				return $this->build_real_event_test_context( $event_id, $trigger, $webhook );
			}

			$labels = $this->trigger_labels();
			$now    = new DateTimeImmutable( 'now', wp_timezone() );
			$next   = $now->modify( '+2 hours' );

			return array(
				'trigger' => array(
					'key'   => $trigger,
					'label' => isset( $labels[ $trigger ] ) ? $labels[ $trigger ] : __( 'Manual test', 'tonys-sportspress-enhancements' ),
				),
				'webhook' => array(
					'id'   => isset( $webhook['id'] ) ? (string) $webhook['id'] : '',
					'name' => isset( $webhook['name'] ) ? (string) $webhook['name'] : __( 'Test Webhook', 'tonys-sportspress-enhancements' ),
					'url'  => isset( $webhook['url'] ) ? (string) $webhook['url'] : '',
				),
				'site' => array(
					'name'     => get_bloginfo( 'name' ),
					'url'      => home_url( '/' ),
					'timezone' => wp_timezone_string(),
				),
				'event' => array(
					'id'          => 0,
					'title'       => __( 'Test Event: Away at Home', 'tonys-sportspress-enhancements' ),
					'raw_title'   => __( 'Test Event', 'tonys-sportspress-enhancements' ),
					'permalink'   => home_url( '/?tse-webhook-test=1' ),
					'image'       => home_url( '/head-to-head?post=0' ),
					'matchup_image' => home_url( '/head-to-head?post=0' ),
					'edit_url'    => admin_url( 'edit.php?post_type=sp_event' ),
					'post_status' => 'publish',
					'sp_status'   => 'future',
					'scheduled'   => array(
						'local_iso'     => $next->format( DATE_ATOM ),
						'local_display' => wp_date( 'Y-m-d g:i A T', $next->getTimestamp(), wp_timezone() ),
						'gmt_iso'       => $next->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
						'timestamp'     => $next->getTimestamp(),
					),
					'teams'       => array(
						array(
							'id'           => 0,
							'name'         => __( 'Home Team', 'tonys-sportspress-enhancements' ),
							'short_name'   => __( 'Home', 'tonys-sportspress-enhancements' ),
							'abbreviation' => 'HOME',
							'role'         => 'home',
						),
						array(
							'id'           => 0,
							'name'         => __( 'Away Team', 'tonys-sportspress-enhancements' ),
							'short_name'   => __( 'Away', 'tonys-sportspress-enhancements' ),
							'abbreviation' => 'AWAY',
							'role'         => 'away',
						),
					),
					'venue'       => array(
						'id'   => 0,
						'name' => __( 'Sample Field', 'tonys-sportspress-enhancements' ),
						'slug' => 'sample-field',
					),
				),
				'changes' => array(
					'previous' => array(
						'local_iso'     => $now->format( DATE_ATOM ),
						'local_display' => wp_date( 'Y-m-d g:i A T', $now->getTimestamp(), wp_timezone() ),
						'gmt_iso'       => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
						'timestamp'     => $now->getTimestamp(),
					),
					'current'  => array(
						'local_iso'     => $next->format( DATE_ATOM ),
						'local_display' => wp_date( 'Y-m-d g:i A T', $next->getTimestamp(), wp_timezone() ),
						'gmt_iso'       => $next->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM ),
						'timestamp'     => $next->getTimestamp(),
					),
				),
				'results' => array(
					'summary' => __( 'Home Team 3 Away Team 2', 'tonys-sportspress-enhancements' ),
					'data'    => array(
						'home' => array( 'r' => 3 ),
						'away' => array( 'r' => 2 ),
					),
				),
				'occurred_at' => array(
					'local_iso'     => $now->format( DATE_ATOM ),
					'local_display' => wp_date( 'Y-m-d g:i A T', $now->getTimestamp(), wp_timezone() ),
				),
			);
		}

		/**
		 * Build a test context from an actual SportsPress event.
		 *
		 * @param int    $event_id Event post ID.
		 * @param string $trigger  Trigger slug.
		 * @param array  $webhook  Webhook configuration.
		 * @return array
		 */
		private function build_real_event_test_context( $event_id, $trigger, $webhook ) {
			$post     = get_post( $event_id );
			$schedule = $this->event_schedule_from_post( $post );
			$changes  = array();

			if ( 'event_datetime_changed' === $trigger ) {
				$previous = $schedule;
				if ( ! empty( $schedule['timestamp'] ) ) {
					$previous_timestamp           = max( 0, (int) $schedule['timestamp'] - HOUR_IN_SECONDS );
					$previous['timestamp']        = $previous_timestamp;
					$previous['local_iso']        = wp_date( DATE_ATOM, $previous_timestamp, wp_timezone() );
					$previous['local_display']    = wp_date( 'Y-m-d g:i A T', $previous_timestamp, wp_timezone() );
					$previous['gmt_iso']          = gmdate( DATE_ATOM, $previous_timestamp );
				}

				$changes = array(
					'previous' => $previous,
					'current'  => $schedule,
				);
			} elseif ( 'event_results_updated' === $trigger ) {
				$results = get_post_meta( $event_id, 'sp_results', true );
				$changes = array(
					'current' => is_array( $results ) ? $results : array(),
				);
			}

			return $this->build_context( $event_id, $trigger, $changes, $webhook );
		}

		/**
		 * Deliver all matching webhooks for a trigger.
		 *
		 * @param string $trigger Trigger slug.
		 * @param int    $post_id Event post ID.
		 * @param array  $changes Trigger-specific data.
		 * @return void
		 */
		private function dispatch_trigger( $trigger, $post_id, $changes ) {
			$settings = $this->get_settings();
			$webhooks = isset( $settings['webhooks'] ) && is_array( $settings['webhooks'] ) ? $settings['webhooks'] : array();

			if ( empty( $webhooks ) ) {
				return;
			}

			foreach ( $webhooks as $webhook ) {
				if ( ! is_array( $webhook ) ) {
					continue;
				}

				if ( empty( $webhook['enabled'] ) ) {
					continue;
				}

				$triggers = isset( $webhook['triggers'] ) && is_array( $webhook['triggers'] ) ? $webhook['triggers'] : array();
				if ( ! in_array( $trigger, $triggers, true ) ) {
					continue;
				}

				$url = isset( $webhook['url'] ) ? (string) $webhook['url'] : '';
				if ( '' === $url ) {
					continue;
				}

				$context = $this->build_context( $post_id, $trigger, $changes, $webhook );
				$this->deliver_webhook( $url, isset( $webhook['template'] ) ? (string) $webhook['template'] : '', $webhook, $context );
			}
		}

		/**
		 * Send the webhook notification through the configured provider.
		 *
		 * @param string $url      Destination value.
		 * @param string $template Message template.
		 * @param array  $webhook  Webhook configuration.
		 * @param array  $context  Render context.
		 * @return array|WP_Error
		 */
		private function deliver_webhook( $url, $template, $webhook, $context ) {
			$message = trim( $this->render_template( (string) $template, $context ) );
			if ( '' === $message ) {
				return new WP_Error( 'empty_message', __( 'Rendered message is empty.', 'tonys-sportspress-enhancements' ) );
			}

			$provider = isset( $webhook['provider'] ) ? sanitize_key( (string) $webhook['provider'] ) : 'generic_json';
			$title    = $this->build_notification_title( $webhook, $context );

			switch ( $provider ) {
				case 'google_chat':
					$request_url = $url;
					$payload     = $this->build_google_chat_payload( $message, $title, $context );
					break;

				case 'groupme_bot':
					$request_url = 'https://api.groupme.com/v3/bots/post';
					$payload     = array(
						'bot_id' => $url,
						'text'   => $message,
					);
					break;

				case 'generic_json':
				default:
					$request_url = $url;
					$payload     = $this->build_generic_payload( $message, $title, $webhook, $context );
					break;
			}

			$args = array(
				'timeout'     => 10,
				'redirection' => 2,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'User-Agent'   => 'Tonys-SportsPress-Enhancements/' . TONY_SPORTSPRESS_ENHANCEMENTS_VERSION,
				),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			);

			/**
			 * Filter webhook request arguments before delivery.
			 *
			 * @param array  $args        Request args.
			 * @param string $request_url Final request URL.
			 * @param array  $payload     Provider-specific payload.
			 * @param array  $webhook     Webhook configuration.
			 * @param array  $context     Render context.
			 */
			$args = apply_filters( 'tse_sp_webhook_request_args', $args, $request_url, $payload, $webhook, $context );

			$response = wp_remote_post( $request_url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$body        = (string) wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				return new WP_Error(
					'delivery_failed',
					sprintf(
						/* translators: 1: HTTP status code, 2: response body. */
						__( 'Webhook delivery failed with HTTP %1$d. %2$s', 'tonys-sportspress-enhancements' ),
						$status_code,
						'' !== trim( $body ) ? trim( $body ) : __( 'No response body.', 'tonys-sportspress-enhancements' )
					)
				);
			}

			return array(
				'status_code' => $status_code,
				'body'        => $body,
			);
		}

		/**
		 * Build a title for outbound notifications.
		 *
		 * @param array  $webhook Webhook configuration.
		 * @param array  $context Render context.
		 * @return string
		 */
		private function build_notification_title( $webhook, $context ) {
			$title_parts = array();

			if ( ! empty( $webhook['name'] ) ) {
				$title_parts[] = (string) $webhook['name'];
			}

			if ( isset( $context['trigger']['label'] ) && '' !== (string) $context['trigger']['label'] ) {
				$title_parts[] = (string) $context['trigger']['label'];
			}

			if ( isset( $context['event']['title'] ) && '' !== (string) $context['event']['title'] ) {
				$title_parts[] = (string) $context['event']['title'];
			}

			$title = implode( ' | ', array_slice( $title_parts, 0, 3 ) );

			if ( '' === $title ) {
				$title = (string) get_bloginfo( 'name' );
			}

			/**
			 * Filter the outbound notification title.
			 *
			 * @param string $title   Notification title.
			 * @param array  $webhook Webhook configuration.
			 * @param array  $context Render context.
			 */
			return (string) apply_filters( 'tse_sp_webhook_title', $title, $webhook, $context );
		}

		/**
		 * Build the generic JSON payload.
		 *
		 * @param string $message Rendered message.
		 * @param string $title   Derived title.
		 * @param array  $webhook Webhook configuration.
		 * @param array  $context Render context.
		 * @return array
		 */
		private function build_generic_payload( $message, $title, $webhook, $context ) {
			return array(
				'title'   => $title,
				'message' => $message,
				'body'    => $message,
				'webhook' => array(
					'id'       => isset( $webhook['id'] ) ? (string) $webhook['id'] : '',
					'name'     => isset( $webhook['name'] ) ? (string) $webhook['name'] : '',
					'provider' => isset( $webhook['provider'] ) ? (string) $webhook['provider'] : 'generic_json',
				),
				'context' => $context,
			);
		}

		/**
		 * Build the Google Chat payload.
		 *
		 * Plain text messages do not render images inline, so when an HTTPS matchup
		 * image exists we send a card with an image widget as well as text fallback.
		 *
		 * @param string $message Rendered message.
		 * @param string $title   Derived title.
		 * @param array  $context Render context.
		 * @return array
		 */
		private function build_google_chat_payload( $message, $title, $context ) {
			$payload = array(
				'text' => $message,
			);

			$image_url = isset( $context['event']['image'] ) ? (string) $context['event']['image'] : '';
			if ( 0 !== strpos( $image_url, 'https://' ) ) {
				return $payload;
			}

			$widgets = array(
				array(
					'textParagraph' => array(
						'text' => nl2br( esc_html( $message ) ),
					),
				),
				array(
					'image' => array(
						'imageUrl' => $image_url,
						'altText'  => isset( $context['event']['title'] ) ? (string) $context['event']['title'] : $title,
					),
				),
			);

			if ( ! empty( $context['event']['permalink'] ) ) {
				$widgets[1]['image']['onClick'] = array(
					'openLink' => array(
						'url' => (string) $context['event']['permalink'],
					),
				);
			}

			$header = array(
				'title' => $title,
			);

			if ( ! empty( $context['event']['scheduled']['local_display'] ) ) {
				$header['subtitle'] = (string) $context['event']['scheduled']['local_display'];
			}

			$payload['cardsV2'] = array(
				array(
					'cardId' => 'matchup-image',
					'card'   => array(
						'header'   => $header,
						'sections' => array(
							array(
								'widgets' => $widgets,
							),
						),
					),
				),
			);

			return $payload;
		}

		/**
		 * Render Jinja-style placeholders with a minimal dot-path syntax.
		 *
		 * Supports `{{ event.title }}` and `{{ event|tojson }}`.
		 *
		 * @param string $template Template body.
		 * @param array  $context  Template context.
		 * @return string
		 */
		public function render_template( $template, $context ) {
			$template = (string) $template;
			$context  = is_array( $context ) ? $context : array();

			return preg_replace_callback(
				'/\{\{\s*(.+?)\s*\}\}/',
				function ( $matches ) use ( $context ) {
					$expression = trim( (string) $matches[1] );
					$parts      = array_map( 'trim', explode( '|', $expression ) );
					$path       = array_shift( $parts );
					$value      = $this->resolve_context_path( $context, $path );

					$force_json = false;
					foreach ( $parts as $filter ) {
						if ( in_array( strtolower( $filter ), array( 'tojson', 'json' ), true ) ) {
							$force_json = true;
						}
					}

					if ( $force_json ) {
						return (string) wp_json_encode( $value );
					}

					if ( is_array( $value ) || is_object( $value ) ) {
						return (string) wp_json_encode( $value );
					}

					if ( is_bool( $value ) ) {
						return $value ? 'true' : 'false';
					}

					if ( null === $value ) {
						return '';
					}

					return (string) $value;
				},
				$template
			);
		}

		/**
		 * Resolve a dot-path from the render context.
		 *
		 * @param mixed  $context Full context.
		 * @param string $path    Dot path.
		 * @return mixed
		 */
		private function resolve_context_path( $context, $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				return '';
			}

			$segments = array_filter( explode( '.', $path ), 'strlen' );
			$current  = $context;

			foreach ( $segments as $segment ) {
				if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
					$current = $current[ $segment ];
					continue;
				}

				if ( is_object( $current ) && isset( $current->{$segment} ) ) {
					$current = $current->{$segment};
					continue;
				}

				if ( is_array( $current ) && ctype_digit( $segment ) ) {
					$segment = (int) $segment;
					if ( array_key_exists( $segment, $current ) ) {
						$current = $current[ $segment ];
						continue;
					}
				}

				return '';
			}

			return $current;
		}

		/**
		 * Determine whether a nested results payload actually contains values.
		 *
		 * @param mixed $results Results payload.
		 * @return bool
		 */
		private function has_meaningful_results( $results ) {
			if ( is_array( $results ) ) {
				foreach ( $results as $value ) {
					if ( $this->has_meaningful_results( $value ) ) {
						return true;
					}
				}

				return false;
			}

			return '' !== trim( (string) $results );
		}

		/**
		 * Get a summary string for current results.
		 *
		 * @param WP_Post|false|null $post Event post.
		 * @return string
		 */
		private function get_results_summary( $post ) {
			if ( ! $post instanceof WP_Post ) {
				return '';
			}

			if ( function_exists( 'tse_sp_event_export_get_ical_summary' ) ) {
				return (string) tse_sp_event_export_get_ical_summary( $post, array( 'format' => 'matchup' ) );
			}

			return (string) get_the_title( $post );
		}

		/**
		 * Get a display title for the event.
		 *
		 * @param int $post_id Event post ID.
		 * @return string
		 */
		private function get_event_title( $post_id ) {
			if ( function_exists( 'asc_generate_sp_event_title' ) ) {
				return (string) asc_generate_sp_event_title( $post_id );
			}

			return (string) get_the_title( $post_id );
		}

		/**
		 * Get team metadata in event order.
		 *
		 * @param int $post_id Event post ID.
		 * @return array
		 */
		private function get_event_teams( $post_id ) {
			$team_ids = get_post_meta( $post_id, 'sp_team', false );
			$teams    = array();

			foreach ( $team_ids as $index => $team_id ) {
				while ( is_array( $team_id ) ) {
					$team_id = array_shift( array_filter( $team_id ) );
				}

				$team_id = absint( $team_id );
				if ( $team_id <= 0 ) {
					continue;
				}

				$teams[] = array(
					'id'           => $team_id,
					'name'         => get_the_title( $team_id ),
					'short_name'   => function_exists( 'sp_team_short_name' ) ? (string) sp_team_short_name( $team_id ) : get_the_title( $team_id ),
					'abbreviation' => function_exists( 'sp_team_abbreviation' ) ? (string) sp_team_abbreviation( $team_id ) : '',
					'role'         => 0 === $index ? 'home' : ( 1 === $index ? 'away' : 'team' ),
				);
			}

			return $teams;
		}

		/**
		 * Get basic venue metadata for an event.
		 *
		 * @param int $post_id Event post ID.
		 * @return array
		 */
		private function get_event_venue( $post_id ) {
			$terms = get_the_terms( $post_id, 'sp_venue' );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return array(
					'id'   => 0,
					'name' => '',
					'slug' => '',
				);
			}

			$venue = reset( $terms );

			return array(
				'id'   => isset( $venue->term_id ) ? (int) $venue->term_id : 0,
				'name' => isset( $venue->name ) ? (string) $venue->name : '',
				'slug' => isset( $venue->slug ) ? (string) $venue->slug : '',
			);
		}
	}
}

/**
 * Bootstrap SportsPress webhooks after plugins load.
 *
 * @return void
 */
function tony_sportspress_webhooks_boot() {
	Tony_Sportspress_Webhooks::instance()->boot();
}
add_action( 'plugins_loaded', 'tony_sportspress_webhooks_boot' );
