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
	?>
	<style>
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
