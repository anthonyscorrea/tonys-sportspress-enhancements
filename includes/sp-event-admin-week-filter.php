<?php
/**
 * Admin week filter for SportsPress events.
 *
 * Adds a week selector in wp-admin for `sp_event` and filters events by
 * Monday-start week (Monday 00:00:00 through Sunday 23:59:59).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Screen-option defaults for event list filters.
 *
 * @return array<string, bool>
 */
function tony_sportspress_event_filter_defaults() {
	return array(
		'month'     => true,
		'week'      => false,
		'team'      => true,
		'venue'     => true,
		'league'    => true,
		'season'    => true,
		'match_day' => true,
	);
}

/**
 * Build user meta key for an event filter screen option.
 *
 * @param string $key Filter key.
 * @return string
 */
function tony_sportspress_event_filter_meta_key( $key ) {
	return 'tony_sp_event_filter_' . $key;
}

/**
 * Get the current singular label for event venues.
 *
 * @return string
 */
function tony_sportspress_get_event_venue_label() {
	$taxonomy = get_taxonomy( 'sp_venue' );

	if ( $taxonomy && ! empty( $taxonomy->labels->singular_name ) ) {
		return (string) $taxonomy->labels->singular_name;
	}

	return __( 'Venue', 'sportspress' );
}

/**
 * Get the current plural label for event venues.
 *
 * @return string
 */
function tony_sportspress_get_event_venue_label_plural() {
	$taxonomy = get_taxonomy( 'sp_venue' );

	if ( $taxonomy && ! empty( $taxonomy->labels->name ) ) {
		return (string) $taxonomy->labels->name;
	}

	return __( 'Venues', 'sportspress' );
}

/**
 * Normalize event filter visibility rules.
 *
 * Month/Year and Week are mutually exclusive.
 *
 * @param array<string, bool> $filters Filter states keyed by filter name.
 * @return array<string, bool>
 */
function tony_sportspress_normalize_event_filter_states( $filters ) {
	if ( ! empty( $filters['month'] ) && ! empty( $filters['week'] ) ) {
		$filters['week'] = false;
	}

	return $filters;
}

/**
 * Check whether a filter is enabled for the current user.
 *
 * @param string $key Filter key.
 * @return bool
 */
function tony_sportspress_event_filter_enabled( $key ) {
	$defaults = tony_sportspress_normalize_event_filter_states( tony_sportspress_event_filter_defaults() );
	if ( ! array_key_exists( $key, $defaults ) ) {
		return true;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return (bool) $defaults[ $key ];
	}

	$stored = get_user_meta( $user_id, tony_sportspress_event_filter_meta_key( $key ), true );
	if ( '' === $stored ) {
		return (bool) $defaults[ $key ];
	}

	$states = array();
	foreach ( $defaults as $filter_key => $enabled ) {
		$current = get_user_meta( $user_id, tony_sportspress_event_filter_meta_key( $filter_key ), true );
		$states[ $filter_key ] = '' === $current ? (bool) $enabled : '1' === (string) $current;
	}

	$states = tony_sportspress_normalize_event_filter_states( $states );

	return ! empty( $states[ $key ] );
}

/**
 * Persist event filter Screen Options via AJAX.
 */
function tony_sportspress_save_event_filter_screen_options_ajax() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error();
	}

	check_ajax_referer( 'tony_sp_event_filters', 'nonce' );

	$defaults = tony_sportspress_normalize_event_filter_states( tony_sportspress_event_filter_defaults() );
	$filters  = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? $_POST['filters'] : array();
	$user_id  = get_current_user_id();

	$states = array();

	foreach ( $defaults as $key => $_enabled ) {
		$value = isset( $filters[ $key ] ) ? sanitize_text_field( wp_unslash( $filters[ $key ] ) ) : '0';
		$states[ $key ] = '1' === $value;
	}

	$states = tony_sportspress_normalize_event_filter_states( $states );

	foreach ( $states as $key => $enabled ) {
		update_user_meta( $user_id, tony_sportspress_event_filter_meta_key( $key ), $enabled ? '1' : '0' );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_tony_sp_event_filter_prefs_save', 'tony_sportspress_save_event_filter_screen_options_ajax' );

/**
 * Add filter visibility toggles to Screen Options on event list admin page.
 *
 * @param string    $settings Existing settings HTML.
 * @param WP_Screen $screen   Current screen.
 * @return string
 */
function tony_sportspress_event_filter_screen_options_markup( $settings, $screen ) {
	if ( ! $screen || 'edit-sp_event' !== $screen->id ) {
		return $settings;
	}

	$labels = array(
		'month'     => __( 'Month/Year', 'tonys-sportspress-enhancements' ),
		'week'      => __( 'Year/Week', 'tonys-sportspress-enhancements' ),
		'team'      => __( 'Team', 'tonys-sportspress-enhancements' ),
		'venue'     => tony_sportspress_get_event_venue_label(),
		'league'    => __( 'League', 'tonys-sportspress-enhancements' ),
		'season'    => __( 'Season', 'tonys-sportspress-enhancements' ),
		'match_day' => __( 'Match Day', 'tonys-sportspress-enhancements' ),
	);

	$settings .= '<fieldset class="metabox-prefs">';
	$settings .= '<legend>' . esc_html__( 'Event Filters', 'tonys-sportspress-enhancements' ) . '</legend>';

	foreach ( $labels as $key => $label ) {
		$meta_key = tony_sportspress_event_filter_meta_key( $key );
		$checked  = tony_sportspress_event_filter_enabled( $key ) ? ' checked="checked"' : '';
		$settings .= '<label for="' . esc_attr( $meta_key ) . '">';
		$settings .= '<input type="checkbox" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="1"' . $checked . ' />';
		$settings .= esc_html( $label );
		$settings .= '</label>';
	}

	$settings .= '</fieldset>';

	return $settings;
}
add_filter( 'screen_settings', 'tony_sportspress_event_filter_screen_options_markup', 10, 2 );

/**
 * Parse an ISO week input (e.g. 2026-W07) from the request.
 *
 * @return array{year:int,week:int}|null
 */
function tony_sportspress_parse_admin_week_filter() {
	if ( empty( $_GET['sp_week_filter'] ) ) {
		return null;
	}

	$raw = sanitize_text_field( wp_unslash( $_GET['sp_week_filter'] ) );
	if ( ! preg_match( '/^(\d{4})-W(0[1-9]|[1-4][0-9]|5[0-3])$/', $raw, $matches ) ) {
		return null;
	}

	$year = (int) $matches[1];
	$week = (int) $matches[2];

	return array(
		'year' => $year,
		'week' => $week,
	);
}

/**
 * Get available ISO week options from event post dates.
 *
 * @return array<int, array{value:string,label:string}>
 */
function tony_sportspress_get_admin_week_filter_options() {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT DATE_FORMAT(post_date, '%%x-W%%v') AS iso_week
			FROM {$wpdb->posts}
			WHERE post_type = %s
				AND post_status NOT IN ('auto-draft', 'trash')
				AND post_date IS NOT NULL
				AND post_date <> '0000-00-00 00:00:00'
			ORDER BY iso_week DESC",
			'sp_event'
		),
		ARRAY_A
	);

	if ( ! is_array( $results ) || empty( $results ) ) {
		return array();
	}

	$options  = array();
	$timezone = wp_timezone();

	foreach ( $results as $result ) {
		if ( empty( $result['iso_week'] ) || ! preg_match( '/^(\d{4})-W(0[1-9]|[1-4][0-9]|5[0-3])$/', $result['iso_week'], $matches ) ) {
			continue;
		}

		$year   = (int) $matches[1];
		$week   = (int) $matches[2];
		$monday = ( new DateTimeImmutable( 'now', $timezone ) )->setISODate( $year, $week, 1 )->setTime( 0, 0, 0 );
		$sunday = $monday->modify( '+6 days' );
		$start_label = wp_date( 'M j', $monday->getTimestamp(), $timezone );
		$end_label   = wp_date(
			$monday->format( 'n' ) === $sunday->format( 'n' ) ? 'j' : 'M j',
			$sunday->getTimestamp(),
			$timezone
		);

		$options[] = array(
			'value' => $result['iso_week'],
			/* translators: 1: ISO week code, 2: Monday date, 3: Sunday date. */
			'label' => sprintf(
				__( '%1$s (%2$s to %3$s)', 'tonys-sportspress-enhancements' ),
				$result['iso_week'],
				$start_label,
				$end_label
			),
		);
	}

	return $options;
}

/**
 * Render week filter control in event admin list.
 *
 * @param string $post_type Current post type.
 */
function tony_sportspress_render_admin_week_filter( $post_type ) {
	if ( 'sp_event' !== $post_type ) {
		return;
	}
	if ( ! tony_sportspress_event_filter_enabled( 'week' ) ) {
		return;
	}

	$value = '';
	if ( ! empty( $_GET['sp_week_filter'] ) ) {
		$value = sanitize_text_field( wp_unslash( $_GET['sp_week_filter'] ) );
	}
	$options = tony_sportspress_get_admin_week_filter_options();
	?>
	<label for="sp_week_filter" class="screen-reader-text"><?php esc_html_e( 'Filter by week', 'tonys-sportspress-enhancements' ); ?></label>
	<select
		id="sp_week_filter"
		name="sp_week_filter"
		class="sp-week-filter-field"
		title="<?php esc_attr_e( 'Week (Monday start)', 'tonys-sportspress-enhancements' ); ?>"
	>
		<option value=""><?php esc_html_e( 'Year/Week', 'tonys-sportspress-enhancements' ); ?></option>
		<?php foreach ( $options as $option ) : ?>
			<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( $value, $option['value'] ); ?>>
				<?php echo esc_html( $option['label'] ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', 'tony_sportspress_render_admin_week_filter' );

/**
 * Add responsive admin styles so filters stay visible on narrow widths.
 */
function tony_sportspress_admin_week_filter_styles() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-sp_event' !== $screen->id ) {
		return;
	}
	$hide = array(
		'month'     => ! tony_sportspress_event_filter_enabled( 'month' ),
		'team'      => ! tony_sportspress_event_filter_enabled( 'team' ),
		'venue'     => ! tony_sportspress_event_filter_enabled( 'venue' ),
		'league'    => ! tony_sportspress_event_filter_enabled( 'league' ),
		'season'    => ! tony_sportspress_event_filter_enabled( 'season' ),
		'match_day' => ! tony_sportspress_event_filter_enabled( 'match_day' ),
		'week'      => ! tony_sportspress_event_filter_enabled( 'week' ),
	);
	?>
	<style>
		<?php if ( $hide['month'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="m"] { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['team'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="team"],
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="team"] + .chosen-container,
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="team"] + .select2 { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['venue'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_venue"],
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_venue"] + .chosen-container,
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_venue"] + .select2 { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['league'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_league"],
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_league"] + .chosen-container,
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_league"] + .select2 { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['season'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_season"],
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_season"] + .chosen-container,
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) select[name="sp_season"] + .select2 { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['match_day'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) input[name="match_day"] { display: none !important; }
		<?php endif; ?>
		<?php if ( $hide['week'] ) : ?>
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) #sp_week_filter { display: none !important; }
		<?php endif; ?>
		@media (max-width: 1200px) {
			.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) {
				display: flex;
				flex-wrap: wrap;
				gap: 6px;
				align-items: center;
			}
			.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) > * {
				float: none;
				margin-right: 0;
			}
			.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) .sp-week-filter-field {
				min-width: 145px;
			}
		}
	</style>
	<?php
}
add_action( 'admin_head-edit.php', 'tony_sportspress_admin_week_filter_styles' );

/**
 * Update admin filter labels and persist screen options.
 */
function tony_sportspress_admin_week_filter_script() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-sp_event' !== $screen->id ) {
		return;
	}

	$venue_filter_text = sprintf(
		/* translators: %s: plural venue label. */
		__( 'Show all %s', 'sportspress' ),
		strtolower( tony_sportspress_get_event_venue_label_plural() )
	);
	?>
	<script>
	(function() {
		const filterCheckboxes = Array.from(
			document.querySelectorAll('#screen-options-wrap input[type="checkbox"][id^="tony_sp_event_filter_"]')
		);
		const monthCheckbox = document.getElementById('tony_sp_event_filter_month');
		const weekCheckbox = document.getElementById('tony_sp_event_filter_week');

		function syncExclusiveFilters(changedCheckbox) {
			if (!changedCheckbox || !changedCheckbox.checked) {
				return;
			}

			if (changedCheckbox === monthCheckbox && weekCheckbox) {
				weekCheckbox.checked = false;
			}

			if (changedCheckbox === weekCheckbox && monthCheckbox) {
				monthCheckbox.checked = false;
			}
		}

		function saveFilterPrefs() {
			if (!filterCheckboxes.length || typeof ajaxurl === 'undefined') {
				return;
			}

			const body = new URLSearchParams();
			body.append('action', 'tony_sp_event_filter_prefs_save');
			body.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'tony_sp_event_filters' ) ); ?>);

			filterCheckboxes.forEach(function(checkbox) {
				const key = checkbox.id.replace('tony_sp_event_filter_', '');
				body.append('filters[' + key + ']', checkbox.checked ? '1' : '0');
			});

			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString(),
				keepalive: true
			}).catch(function() {});
		}

		filterCheckboxes.forEach(function(checkbox) {
			checkbox.addEventListener('change', function() {
				syncExclusiveFilters(checkbox);
				saveFilterPrefs();
			});
		});

		const monthSelect = document.querySelector('select[name="m"]');
		if (monthSelect) {
			const allDates = monthSelect.querySelector('option[value="0"]');
			if (allDates) {
				allDates.textContent = <?php echo wp_json_encode( __( 'Month/Year', 'tonys-sportspress-enhancements' ) ); ?>;
			}
		}

		const venueSelect = document.querySelector('select[name="sp_venue"]');
		if (venueSelect) {
			const allVenues = venueSelect.querySelector('option[value="0"]');
			if (allVenues) {
				allVenues.textContent = <?php echo wp_json_encode( $venue_filter_text ); ?>;
			}
		}
	})();
	</script>
	<?php
}
add_action( 'admin_footer-edit.php', 'tony_sportspress_admin_week_filter_script' );

/**
 * Apply Monday-start week date query to event admin list.
 *
 * @param WP_Query $query Main query.
 */
function tony_sportspress_apply_admin_week_filter( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	$post_type = $query->get( 'post_type' );
	if ( 'sp_event' !== $post_type ) {
		return;
	}

	$parsed = tony_sportspress_parse_admin_week_filter();
	if ( null === $parsed ) {
		return;
	}

	$timezone = wp_timezone();
	$monday   = ( new DateTimeImmutable( 'now', $timezone ) )->setISODate( $parsed['year'], $parsed['week'], 1 )->setTime( 0, 0, 0 );
	$sunday   = $monday->modify( '+6 days' )->setTime( 23, 59, 59 );

	$date_query = $query->get( 'date_query' );
	if ( ! is_array( $date_query ) ) {
		$date_query = array();
	}

	$date_query[] = array(
		'after'     => array(
			'year'  => (int) $monday->format( 'Y' ),
			'month' => (int) $monday->format( 'n' ),
			'day'   => (int) $monday->format( 'j' ),
		),
		'before'    => array(
			'year'  => (int) $sunday->format( 'Y' ),
			'month' => (int) $sunday->format( 'n' ),
			'day'   => (int) $sunday->format( 'j' ),
		),
		'inclusive' => true,
	);

	$query->set( 'date_query', $date_query );
}
add_action( 'pre_get_posts', 'tony_sportspress_apply_admin_week_filter' );
