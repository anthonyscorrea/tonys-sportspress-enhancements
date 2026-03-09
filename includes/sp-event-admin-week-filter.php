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
		'week'      => true,
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
 * Check whether a filter is enabled for the current user.
 *
 * @param string $key Filter key.
 * @return bool
 */
function tony_sportspress_event_filter_enabled( $key ) {
	$defaults = tony_sportspress_event_filter_defaults();
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

	return '1' === (string) $stored;
}

/**
 * Persist event filter Screen Options via AJAX.
 */
function tony_sportspress_save_event_filter_screen_options_ajax() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error();
	}

	check_ajax_referer( 'tony_sp_event_filters', 'nonce' );

	$defaults = tony_sportspress_event_filter_defaults();
	$filters  = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? $_POST['filters'] : array();
	$user_id  = get_current_user_id();

	foreach ( $defaults as $key => $_enabled ) {
		$value = isset( $filters[ $key ] ) ? sanitize_text_field( wp_unslash( $filters[ $key ] ) ) : '0';
		update_user_meta( $user_id, tony_sportspress_event_filter_meta_key( $key ), '1' === $value ? '1' : '0' );
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
		'week'      => __( 'Week', 'tonys-sportspress-enhancements' ),
		'team'      => __( 'Team', 'tonys-sportspress-enhancements' ),
		'venue'     => __( 'Venue', 'tonys-sportspress-enhancements' ),
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

	$summary_text = __( 'Select a week', 'tonys-sportspress-enhancements' );
	$parsed       = tony_sportspress_parse_admin_week_filter();
	if ( is_array( $parsed ) ) {
		$timezone = wp_timezone();
		$monday   = ( new DateTimeImmutable( 'now', $timezone ) )->setISODate( $parsed['year'], $parsed['week'], 1 )->setTime( 0, 0, 0 );
		$sunday   = $monday->modify( '+6 days' )->setTime( 23, 59, 59 );
		/* translators: 1: Monday label/date, 2: Sunday label/date. */
		$summary_text = sprintf(
			__( '%1$s to %2$s', 'tonys-sportspress-enhancements' ),
			wp_date( 'D M j, Y', $monday->getTimestamp(), $timezone ),
			wp_date( 'D M j, Y', $sunday->getTimestamp(), $timezone )
		);
	}
	?>
	<label for="sp_week_filter" class="screen-reader-text"><?php esc_html_e( 'Filter by week', 'tonys-sportspress-enhancements' ); ?></label>
	<input
		type="week"
		id="sp_week_filter"
		name="sp_week_filter"
		class="sp-week-filter-field"
		value="<?php echo esc_attr( $value ); ?>"
		title="<?php esc_attr_e( 'Week (Monday start)', 'tonys-sportspress-enhancements' ); ?>"
	/>
	<span id="sp-week-filter-summary" class="sp-week-filter-summary"><?php echo esc_html( $summary_text ); ?></span>
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
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) #sp_week_filter,
		.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) #sp-week-filter-summary { display: none !important; }
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
			.post-type-sp_event .tablenav.top .alignleft.actions:not(.bulkactions) .sp-week-filter-summary {
				display: block;
				width: 100%;
				margin-top: 2px;
				color: #50575e;
				font-size: 12px;
				line-height: 1.4;
			}
		}
	</style>
	<?php
}
add_action( 'admin_head-edit.php', 'tony_sportspress_admin_week_filter_styles' );

/**
 * Update week summary text when week input changes.
 */
function tony_sportspress_admin_week_filter_script() {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-sp_event' !== $screen->id ) {
		return;
	}
	?>
	<script>
	(function() {
		const filterCheckboxes = Array.from(
			document.querySelectorAll('#screen-options-wrap input[type="checkbox"][id^="tony_sp_event_filter_"]')
		);

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
			checkbox.addEventListener('change', saveFilterPrefs);
		});

		const monthSelect = document.querySelector('select[name="m"]');
		if (monthSelect) {
			const allDates = monthSelect.querySelector('option[value="0"]');
			if (allDates) {
				allDates.textContent = <?php echo wp_json_encode( __( 'Month/Year', 'tonys-sportspress-enhancements' ) ); ?>;
			}
		}

		const input = document.getElementById('sp_week_filter');
		const summary = document.getElementById('sp-week-filter-summary');
		if (!input || !summary) {
			return;
		}

		function updateSummary() {
			const raw = (input.value || '').trim();
			const match = raw.match(/^(\d{4})-W(\d{2})$/);
			if (!match) {
				summary.textContent = 'Select a week';
				return;
			}

			const year = parseInt(match[1], 10);
			const week = parseInt(match[2], 10);

			const jan4 = new Date(Date.UTC(year, 0, 4));
			const jan4Day = jan4.getUTCDay() || 7;
			const mondayWeek1 = new Date(jan4);
			mondayWeek1.setUTCDate(jan4.getUTCDate() - jan4Day + 1);

			const monday = new Date(mondayWeek1);
			monday.setUTCDate(mondayWeek1.getUTCDate() + (week - 1) * 7);
			const sunday = new Date(monday);
			sunday.setUTCDate(monday.getUTCDate() + 6);

			const fmt = new Intl.DateTimeFormat(undefined, {
				weekday: 'short',
				month: 'short',
				day: 'numeric',
				year: 'numeric',
				timeZone: 'UTC'
			});

			summary.textContent = fmt.format(monday) + ' to ' + fmt.format(sunday);
		}

		input.addEventListener('change', updateSummary);
		updateSummary();
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
