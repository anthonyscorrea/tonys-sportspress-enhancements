<?php
/**
 * Quick Edit officials support for SportsPress events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add an Officials column to the event admin list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function tony_sportspress_event_add_officials_column( $columns ) {
	$updated = array();

	foreach ( $columns as $key => $label ) {
		$updated[ $key ] = $label;

		if ( 'sp_team' === $key ) {
			$updated['tony_sp_officials'] = esc_html__( 'Officials', 'tonys-sportspress-enhancements' );
		}
	}

	if ( ! isset( $updated['tony_sp_officials'] ) ) {
		$updated['tony_sp_officials'] = esc_html__( 'Officials', 'tonys-sportspress-enhancements' );
	}

	return $updated;
}
add_filter( 'manage_edit-sp_event_columns', 'tony_sportspress_event_add_officials_column', 20 );

/**
 * Build a display-ready officials map for an event.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{name: string, officials: string[]}>
 */
function tony_sportspress_event_get_officials_display( $post_id ) {
	$officials_by_duty = get_post_meta( $post_id, 'sp_officials', true );
	if ( ! is_array( $officials_by_duty ) || empty( $officials_by_duty ) ) {
		return array();
	}

	$duties = get_terms(
		array(
			'taxonomy'   => 'sp_duty',
			'hide_empty' => false,
		)
	);

	$duty_names = array();
	if ( is_array( $duties ) ) {
		foreach ( $duties as $duty ) {
			if ( isset( $duty->term_id, $duty->name ) ) {
				$duty_names[ (int) $duty->term_id ] = $duty->name;
			}
		}
	}

	$rows = array();
	foreach ( $officials_by_duty as $duty_id => $official_ids ) {
		$duty_id      = absint( $duty_id );
		$official_ids = array_filter( array_map( 'absint', (array) $official_ids ) );

		if ( $duty_id <= 0 || empty( $official_ids ) ) {
			continue;
		}

		$names = array();
		foreach ( $official_ids as $official_id ) {
			$title = get_the_title( $official_id );
			if ( is_string( $title ) && '' !== $title ) {
				$names[] = $title;
			}
		}

		if ( empty( $names ) ) {
			continue;
		}

		$rows[] = array(
			'name'      => isset( $duty_names[ $duty_id ] ) ? $duty_names[ $duty_id ] : (string) $duty_id,
			'officials' => $names,
		);
	}

	return $rows;
}

/**
 * Print hidden officials data on each event row for quick edit prefill.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function tony_sportspress_event_quick_edit_officials_row_data( $column, $post_id ) {
	if ( 'tony_sp_officials' !== $column ) {
		return;
	}

	$officials = get_post_meta( $post_id, 'sp_officials', true );
	if ( ! is_array( $officials ) ) {
		$officials = array();
	}

	$serialized = wp_json_encode( $officials );
	if ( ! is_string( $serialized ) ) {
		$serialized = '{}';
	}

	$rows = tony_sportspress_event_get_officials_display( $post_id );
	if ( empty( $rows ) ) {
		echo '&mdash;';
	} else {
		foreach ( $rows as $row ) {
			echo '<div class="tony-sp-event-official-row">';
			echo '<strong>' . esc_html( $row['name'] ) . ':</strong> ';
			echo esc_html( implode( ', ', $row['officials'] ) );
			echo '</div>';
		}
	}

	echo '<span class="hidden tony-event-officials-data" data-officials="' . esc_attr( $serialized ) . '"></span>';
}
add_action( 'manage_sp_event_posts_custom_column', 'tony_sportspress_event_quick_edit_officials_row_data', 20, 2 );

/**
 * Render quick edit UI for officials.
 *
 * @param string $column_name Column key.
 * @param string $post_type   Post type key.
 */
function tony_sportspress_event_quick_edit_officials_field( $column_name, $post_type ) {
	if ( 'sp_event' !== $post_type || 'tony_sp_officials' !== $column_name ) {
		return;
	}

	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	wp_nonce_field( 'tony_sp_event_officials_quick_edit', 'tony_sp_event_officials_quick_edit_nonce' );

	$duties = get_terms(
		array(
			'taxonomy'   => 'sp_duty',
			'hide_empty' => false,
			'orderby'    => 'meta_value_num',
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'sp_order',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'sp_order',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	if ( ! is_array( $duties ) || empty( $duties ) ) {
		return;
	}

	$officials = get_posts(
		array(
			'post_type'      => 'sp_official',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	if ( ! is_array( $officials ) || empty( $officials ) ) {
		return;
	}
	?>
	<fieldset class="inline-edit-col-right tony-sp-event-officials-wrap">
		<div class="inline-edit-col">
			<span class="title inline-edit-categories-label"><?php esc_html_e( 'Officials', 'tonys-sportspress-enhancements' ); ?></span>
			<?php foreach ( $duties as $duty ) : ?>
				<div class="tony-sp-duty-group">
					<span class="title inline-edit-categories-label"><?php echo esc_html( $duty->name ); ?></span>
					<input type="hidden" name="tony_sp_officials[<?php echo esc_attr( $duty->term_id ); ?>][]" value="0">
					<ul class="cat-checklist">
						<?php foreach ( $officials as $official ) : ?>
							<li>
								<label class="selectit">
									<input
										value="<?php echo esc_attr( $official->ID ); ?>"
										type="checkbox"
										name="tony_sp_officials[<?php echo esc_attr( $duty->term_id ); ?>][]"
										class="tony-sp-official-checkbox"
										data-duty-id="<?php echo esc_attr( $duty->term_id ); ?>"
									>
									<?php echo esc_html( $official->post_title ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</fieldset>
	<?php
}
add_action( 'quick_edit_custom_box', 'tony_sportspress_event_quick_edit_officials_field', 10, 2 );

/**
 * Save quick edit officials data.
 *
 * @param int $post_id Post ID.
 */
function tony_sportspress_event_quick_edit_officials_save( $post_id ) {
	if ( empty( $_POST ) ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	if ( 'sp_event' !== get_post_type( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$nonce = isset( $_POST['tony_sp_event_officials_quick_edit_nonce'] )
		? sanitize_text_field( wp_unslash( $_POST['tony_sp_event_officials_quick_edit_nonce'] ) )
		: '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tony_sp_event_officials_quick_edit' ) ) {
		return;
	}

	$raw_officials = isset( $_POST['tony_sp_officials'] ) ? wp_unslash( $_POST['tony_sp_officials'] ) : array();
	if ( ! is_array( $raw_officials ) ) {
		$raw_officials = array();
	}

	$clean_officials = array();
	foreach ( $raw_officials as $duty_id => $official_ids ) {
		$duty_id = absint( $duty_id );
		if ( $duty_id <= 0 || ! is_array( $official_ids ) ) {
			continue;
		}

		$official_ids = array_map( 'absint', $official_ids );
		$official_ids = array_filter( $official_ids );
		$official_ids = array_values( array_unique( $official_ids ) );

		if ( ! empty( $official_ids ) ) {
			$clean_officials[ $duty_id ] = $official_ids;
		}
	}

	update_post_meta( $post_id, 'sp_officials', $clean_officials );
}
add_action( 'save_post', 'tony_sportspress_event_quick_edit_officials_save' );

/**
 * Prefill quick edit checkboxes with existing officials.
 */
function tony_sportspress_event_quick_edit_officials_script() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-sp_event' !== $screen->id ) {
		return;
	}
	?>
	<script>
	(function($) {
		if (typeof inlineEditPost === 'undefined' || !inlineEditPost.edit) {
			return;
		}

		var wpInlineEdit = inlineEditPost.edit;

		inlineEditPost.edit = function(id) {
			wpInlineEdit.apply(this, arguments);

			var postId = 0;
			if (typeof id === 'object') {
				postId = parseInt(this.getId(id), 10);
			}
			if (!postId) {
				return;
			}

			var editRow = $('#edit-' + postId);
			var postRow = $('#post-' + postId);
			var payload = postRow.find('.tony-event-officials-data').attr('data-officials');
			var selected = {};

			try {
				selected = payload ? JSON.parse(payload) : {};
			} catch (e) {
				selected = {};
			}

			editRow.find('.tony-sp-official-checkbox').prop('checked', false);

			Object.keys(selected).forEach(function(dutyId) {
				var ids = selected[dutyId];
				if (!Array.isArray(ids)) {
					return;
				}
				ids.forEach(function(officialId) {
					editRow
						.find('.tony-sp-official-checkbox[data-duty-id="' + dutyId + '"][value="' + String(officialId) + '"]')
						.prop('checked', true);
				});
			});
		};
	})(jQuery);
	</script>
	<?php
}
add_action( 'admin_footer-edit.php', 'tony_sportspress_event_quick_edit_officials_script' );
