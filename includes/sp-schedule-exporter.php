<?php
/**
 * SportsPress schedule exporter admin page.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the schedule exporter admin page.
 *
 * @return void
 */
function tse_sp_schedule_exporter_add_admin_page() {
	add_submenu_page(
		'sportspress',
		__( 'Schedule Exporter', 'tonys-sportspress-enhancements' ),
		__( 'Schedule Exporter', 'tonys-sportspress-enhancements' ),
		'manage_sportspress',
		'tse-schedule-exporter',
		'tse_sp_schedule_exporter_render_admin_page'
	);
}
add_action( 'admin_menu', 'tse_sp_schedule_exporter_add_admin_page' );
add_shortcode( 'tse_schedule_exporter', 'tse_sp_schedule_exporter_render_shortcode' );
add_action( 'init', 'tse_sp_schedule_exporter_register_block' );

/**
 * Handle schedule export downloads.
 *
 * @return void
 */
function tse_sp_schedule_exporter_handle_download() {
	if ( is_user_logged_in() && ! current_user_can( 'manage_sportspress' ) ) {
		wp_die( esc_html__( 'You do not have permission to export schedules.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'tse_schedule_export' );

	$filters = tse_sp_event_export_normalize_request_args();

	if ( $filters['team_id'] <= 0 || 'sp_team' !== get_post_type( $filters['team_id'] ) ) {
		wp_die( esc_html__( 'Choose a valid team before exporting.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 400 ) );
	}

	tse_sp_event_export_stream_csv(
		$filters,
		array(
			'disposition' => 'attachment',
		)
	);
}
add_action( 'admin_post_tse_schedule_export', 'tse_sp_schedule_exporter_handle_download' );
add_action( 'admin_post_nopriv_tse_schedule_export', 'tse_sp_schedule_exporter_handle_download' );

/**
 * Register the schedule exporter block.
 *
 * @return void
 */
function tse_sp_schedule_exporter_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'tse-schedule-exporter-block',
		TONY_SPORTSPRESS_ENHANCEMENTS_URL . 'assets/schedule-exporter-block.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n' ),
		TONY_SPORTSPRESS_ENHANCEMENTS_VERSION,
		true
	);

	register_block_type(
		'tse/schedule-exporter',
		array(
			'api_version'     => 3,
			'title'           => __( 'Schedule Exporter', 'tonys-sportspress-enhancements' ),
			'description'     => __( 'Shows the public schedule exporter with CSV, iCal, and printable page options.', 'tonys-sportspress-enhancements' ),
			'category'        => 'widgets',
			'icon'            => 'calendar-alt',
			'editor_script'   => 'tse-schedule-exporter-block',
			'render_callback' => 'tse_sp_schedule_exporter_render_block',
			'supports'        => array(
				'html' => false,
			),
		)
	);
}

/**
 * Render the schedule exporter page.
 *
 * @return void
 */
function tse_sp_schedule_exporter_render_admin_page() {
	if ( ! current_user_can( 'manage_sportspress' ) ) {
		return;
	}

	$leagues   = tse_sp_schedule_exporter_get_leagues();
	$league_id = tse_sp_schedule_exporter_resolve_league_id( $leagues );
	$seasons   = tse_sp_schedule_exporter_get_seasons();
	$season_id = tse_sp_schedule_exporter_resolve_season_id( $seasons );
	$teams     = tse_sp_schedule_exporter_get_teams( $league_id, $season_id );
	$team_id   = tse_sp_schedule_exporter_resolve_team_id( $teams );
	$fields    = tse_sp_schedule_exporter_get_fields();
	$field_id  = tse_sp_schedule_exporter_resolve_field_id( $fields );
	$export_type = tse_sp_schedule_exporter_resolve_export_type();
	$subformat   = tse_sp_schedule_exporter_resolve_subformat();

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Schedule Exporter', 'tonys-sportspress-enhancements' ) . '</h1>';
	echo '<p>' . esc_html__( 'Choose filters once, then generate the CSV feed, iCal link, or printable page URL from the same controls.', 'tonys-sportspress-enhancements' ) . '</p>';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="tse-schedule-exporter-form" style="max-width:720px;margin:20px 0 28px;">';
	echo '<input type="hidden" name="page" value="tse-schedule-exporter" />';
	echo '<div>';

	echo '<div style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-export-type"><strong>' . esc_html__( 'Format', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-export-type" name="export_type">';
	foreach ( tse_sp_schedule_exporter_get_export_types() as $type_key => $type_label ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $type_key ),
			selected( $export_type, $type_key, false ),
			esc_html( $type_label )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'CSV builds a feed URL, iCal Link builds a subscription URL, and Printable opens the printable page.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</div>';

	echo '<div data-subformat-wrap="1" style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-subformat"><strong>' . esc_html__( 'CSV Layout', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-subformat" name="subformat">';
	foreach ( tse_sp_event_export_get_formats() as $format_key => $format_definition ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $format_key ),
			selected( $subformat, $format_key, false ),
			esc_html( $format_definition['label'] )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Matchup is away vs home. Team is opponent-based and requires one specific team.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</div>';

	echo '<div style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-league"><strong>' . esc_html__( 'League', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-league" name="league_id" data-auto-submit="1">';
	foreach ( $leagues as $league ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $league->term_id ),
			selected( $league_id, (int) $league->term_id, false ),
			esc_html( $league->name )
		);
	}
	echo '</select>';
	echo '</div>';

	echo '<div style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-season"><strong>' . esc_html__( 'Season', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-season" name="season_id" data-auto-submit="1">';
	echo '<option value="0">' . esc_html__( 'Current / All matching events', 'tonys-sportspress-enhancements' ) . '</option>';
	foreach ( $seasons as $season ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $season->term_id ),
			selected( $season_id, (int) $season->term_id, false ),
			esc_html( $season->name )
		);
	}
	echo '</select>';
	echo '</div>';

	echo '<div style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-team"><strong>' . esc_html__( 'Team', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-team" name="team_id">';
	echo '<option value="0">' . esc_html__( 'All teams', 'tonys-sportspress-enhancements' ) . '</option>';
	foreach ( $teams as $team ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $team->ID ),
			selected( $team_id, (int) $team->ID, false ),
			esc_html( $team->post_title )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Teams are filtered by the selected league and season.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</div>';

	echo '<div style="margin-bottom:16px;">';
	echo '<label for="tse-schedule-exporter-field"><strong>' . esc_html__( 'Field', 'tonys-sportspress-enhancements' ) . '</strong></label><br />';
	echo '<select id="tse-schedule-exporter-field" name="field_id">';
	echo '<option value="0">' . esc_html__( 'All fields', 'tonys-sportspress-enhancements' ) . '</option>';
	foreach ( $fields as $field ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $field->term_id ),
			selected( $field_id, (int) $field->term_id, false ),
			esc_html( $field->name )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Use the field filter to narrow the feed to a specific venue.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</div>';

	echo '</div>';
	echo '</form>';

	if ( empty( $teams ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No SportsPress teams match the selected league and season.', 'tonys-sportspress-enhancements' ) . '</p></div>';
		echo '</div>';
		return;
	}

	echo '<div style="max-width:960px;padding:20px 24px;border:1px solid #dcdcde;background:#fff;">';
	echo '<h2 style="margin-top:0;">' . esc_html__( 'Output URL', 'tonys-sportspress-enhancements' ) . '</h2>';
	echo '<p>' . esc_html__( 'The generated URL below updates from the shared controls above.', 'tonys-sportspress-enhancements' ) . '</p>';

	tse_sp_schedule_exporter_render_column_picker( 'matchup', 'admin', $subformat );
	tse_sp_schedule_exporter_render_column_picker( 'team', 'admin', $subformat );

	$base_args = array(
		'league_id' => $league_id,
		'team_id'   => $team_id,
		'season_id' => $season_id,
		'field_id'  => $field_id,
		'format'    => $subformat,
	);
	$csv_url = tse_sp_event_export_get_feed_url( $base_args, 'csv' );
	$ics_url = tse_sp_event_export_get_feed_url(
		array(
			'league_id' => $league_id,
			'team_id'   => $team_id,
			'season_id' => $season_id,
			'field_id'  => $field_id,
		),
		'ics'
	);
	$print_url = tse_sp_schedule_exporter_get_printable_url( $team_id, $season_id, 'letter', $league_id );
	$current_url = tse_sp_schedule_exporter_get_output_url( $export_type, $csv_url, $ics_url, $print_url );

	echo '<div style="display:flex;align-items:center;gap:8px;max-width:100%;margin-top:16px;">';
	echo '<input type="text" class="large-text code tse-output-url" readonly="readonly" value="' . esc_attr( $current_url ) . '" />';
	echo '<button type="button" class="button tse-copy-link" title="' . esc_attr__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '">' . esc_html__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '</button>';
	echo '<button type="button" class="button button-primary tse-open-link" data-csv-url="' . esc_url( $csv_url ) . '" data-ics-url="' . esc_url( $ics_url ) . '" data-print-url="' . esc_url( $print_url ) . '" title="' . esc_attr__( 'Open URL in new tab', 'tonys-sportspress-enhancements' ) . '">' . esc_html__( 'Open URL in New Tab', 'tonys-sportspress-enhancements' ) . '</button>';
	echo '<button type="button" class="button button-primary tse-ics-ios-link" data-ics-url="' . esc_url( $ics_url ) . '" title="' . esc_attr__( 'Subscribe on iPhone or iPad', 'tonys-sportspress-enhancements' ) . '" style="display:none;">' . esc_html__( 'Subscribe on iPhone/iPad', 'tonys-sportspress-enhancements' ) . '</button>';
	echo '<button type="button" class="button tse-ics-android-link" data-ics-url="' . esc_url( $ics_url ) . '" title="' . esc_attr__( 'Subscribe on Android', 'tonys-sportspress-enhancements' ) . '" style="display:none;">' . esc_html__( 'Subscribe on Android', 'tonys-sportspress-enhancements' ) . '</button>';
	echo '</div>';
	echo '<p class="description tse-output-note">' . esc_html__( 'Use the buttons to copy the generated URL or open the right destination for this export type.', 'tonys-sportspress-enhancements' ) . '</p>';
	tse_sp_schedule_exporter_render_link_sync_script( true );
	echo '</div>';
	echo '</div>';
}

/**
 * Render the public shortcode.
 *
 * @return string
 */
function tse_sp_schedule_exporter_render_shortcode() {
	$leagues   = tse_sp_schedule_exporter_get_leagues();
	$league_id = tse_sp_schedule_exporter_resolve_league_id( $leagues );
	$seasons   = tse_sp_schedule_exporter_get_seasons();
	$season_id = tse_sp_schedule_exporter_resolve_season_id( $seasons );
	$teams     = tse_sp_schedule_exporter_get_teams( $league_id, $season_id );
	$team_id   = tse_sp_schedule_exporter_resolve_team_id( $teams );
	$fields    = tse_sp_schedule_exporter_get_fields();
	$field_id  = tse_sp_schedule_exporter_resolve_field_id( $fields );
	$export_type = tse_sp_schedule_exporter_resolve_export_type();
	$subformat   = tse_sp_schedule_exporter_resolve_subformat();

	if ( empty( $teams ) ) {
		return '<p>' . esc_html__( 'No SportsPress teams match the selected league and season.', 'tonys-sportspress-enhancements' ) . '</p>';
	}

	ob_start();
	?>
	<div class="tse-schedule-exporter" style="max-width:960px;margin:0 auto;padding:24px;border:1px solid #d7d7db;background:#fff;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Schedule Exporter', 'tonys-sportspress-enhancements' ); ?></h2>
		<p><?php esc_html_e( 'Choose filters once, then generate the CSV feed, iCal link, or printable page URL from the same controls.', 'tonys-sportspress-enhancements' ); ?></p>

		<form method="get" action="<?php echo esc_url( get_permalink() ); ?>" class="tse-schedule-exporter-form" style="max-width:720px;margin:24px 0;">
			<div style="margin-bottom:16px;">
				<label for="tse-public-export-type"><strong><?php esc_html_e( 'Format', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-export-type" name="export_type">
					<?php foreach ( tse_sp_schedule_exporter_get_export_types() as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $export_type, $type_key ); ?>>
							<?php echo esc_html( $type_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'CSV builds a feed URL, iCal Link builds a subscription URL, and Printable opens the printable page.', 'tonys-sportspress-enhancements' ); ?></p>
			</div>

			<div data-subformat-wrap="1" style="margin-bottom:16px;">
				<label for="tse-public-subformat"><strong><?php esc_html_e( 'CSV Layout', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-subformat" name="subformat">
					<?php foreach ( tse_sp_event_export_get_formats() as $format_key => $format_definition ) : ?>
						<option value="<?php echo esc_attr( $format_key ); ?>" <?php selected( $subformat, $format_key ); ?>>
							<?php echo esc_html( $format_definition['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Matchup is away vs home. Team is opponent-based and requires one specific team.', 'tonys-sportspress-enhancements' ); ?></p>
			</div>

			<div style="margin-bottom:16px;">
				<label for="tse-public-league"><strong><?php esc_html_e( 'League', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-league" name="league_id" data-auto-submit="1">
					<?php foreach ( $leagues as $league ) : ?>
						<option value="<?php echo esc_attr( (string) $league->term_id ); ?>" <?php selected( $league_id, (int) $league->term_id ); ?>>
							<?php echo esc_html( $league->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div style="margin-bottom:16px;">
				<label for="tse-public-season"><strong><?php esc_html_e( 'Season', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-season" name="season_id" data-auto-submit="1">
					<option value="0"><?php esc_html_e( 'Current / All matching events', 'tonys-sportspress-enhancements' ); ?></option>
					<?php foreach ( $seasons as $season ) : ?>
						<option value="<?php echo esc_attr( (string) $season->term_id ); ?>" <?php selected( $season_id, (int) $season->term_id ); ?>>
							<?php echo esc_html( $season->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div style="margin-bottom:16px;">
				<label for="tse-public-team"><strong><?php esc_html_e( 'Team', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-team" name="team_id">
					<option value="0"><?php esc_html_e( 'All teams', 'tonys-sportspress-enhancements' ); ?></option>
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( (string) $team->ID ); ?>" <?php selected( $team_id, (int) $team->ID ); ?>>
							<?php echo esc_html( $team->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div style="margin-bottom:16px;">
				<label for="tse-public-field"><strong><?php esc_html_e( 'Field', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-field" name="field_id">
					<option value="0"><?php esc_html_e( 'All fields', 'tonys-sportspress-enhancements' ); ?></option>
					<?php foreach ( $fields as $field ) : ?>
						<option value="<?php echo esc_attr( (string) $field->term_id ); ?>" <?php selected( $field_id, (int) $field->term_id ); ?>>
							<?php echo esc_html( $field->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

		</form>

		<?php tse_sp_schedule_exporter_render_column_picker( 'matchup', 'public', $subformat ); ?>
		<?php tse_sp_schedule_exporter_render_column_picker( 'team', 'public', $subformat ); ?>

		<?php
		$csv_url = tse_sp_event_export_get_feed_url( array( 'team_id' => $team_id, 'season_id' => $season_id, 'league_id' => $league_id, 'field_id' => $field_id, 'format' => $subformat ), 'csv' );
		$ics_url = tse_sp_event_export_get_feed_url( array( 'team_id' => $team_id, 'season_id' => $season_id, 'league_id' => $league_id, 'field_id' => $field_id ), 'ics' );
		$print_url = tse_sp_schedule_exporter_get_printable_url( $team_id, $season_id, 'letter', $league_id );
		$current_url = tse_sp_schedule_exporter_get_output_url( $export_type, $csv_url, $ics_url, $print_url );
		?>
		<div style="display:flex;align-items:center;gap:8px;max-width:100%;margin-top:16px;">
			<input type="text" class="large-text code tse-output-url" readonly="readonly" value="<?php echo esc_attr( $current_url ); ?>" />
			<button type="button" class="button tse-copy-link" title="<?php esc_attr_e( 'Copy URL', 'tonys-sportspress-enhancements' ); ?>"><?php esc_html_e( 'Copy URL', 'tonys-sportspress-enhancements' ); ?></button>
			<button type="button" class="button button-primary tse-open-link" data-csv-url="<?php echo esc_url( $csv_url ); ?>" data-ics-url="<?php echo esc_url( $ics_url ); ?>" data-print-url="<?php echo esc_url( $print_url ); ?>" title="<?php esc_attr_e( 'Open URL in new tab', 'tonys-sportspress-enhancements' ); ?>"><?php esc_html_e( 'Open URL in New Tab', 'tonys-sportspress-enhancements' ); ?></button>
			<button type="button" class="button button-primary tse-ics-ios-link" data-ics-url="<?php echo esc_url( $ics_url ); ?>" title="<?php esc_attr_e( 'Subscribe on iPhone or iPad', 'tonys-sportspress-enhancements' ); ?>" style="display:none;"><?php esc_html_e( 'Subscribe on iPhone/iPad', 'tonys-sportspress-enhancements' ); ?></button>
			<button type="button" class="button tse-ics-android-link" data-ics-url="<?php echo esc_url( $ics_url ); ?>" title="<?php esc_attr_e( 'Subscribe on Android', 'tonys-sportspress-enhancements' ); ?>" style="display:none;"><?php esc_html_e( 'Subscribe on Android', 'tonys-sportspress-enhancements' ); ?></button>
		</div>
		<p class="description tse-output-note"><?php esc_html_e( 'Use the buttons to copy the generated URL or open the right destination for this export type.', 'tonys-sportspress-enhancements' ); ?></p>
	</div>
	<?php
	$output = (string) ob_get_clean();

	return $output . tse_sp_schedule_exporter_render_link_sync_script();
}

/**
 * Render the schedule exporter block.
 *
 * @return string
 */
function tse_sp_schedule_exporter_render_block() {
	if ( is_admin() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
		return '<div class="tse-schedule-exporter-block-placeholder"><strong>' . esc_html__( 'Schedule Exporter', 'tonys-sportspress-enhancements' ) . '</strong><p>' . esc_html__( 'The schedule exporter renders on the frontend.', 'tonys-sportspress-enhancements' ) . '</p></div>';
	}

	return tse_sp_schedule_exporter_render_shortcode();
}

/**
 * Get teams for the exporter.
 *
 * @return WP_Post[]
 */
function tse_sp_schedule_exporter_get_leagues() {
	$leagues = get_terms(
		array(
			'taxonomy'   => 'sp_league',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $leagues ) || ! is_array( $leagues ) ) {
		return array();
	}

	return $leagues;
}

/**
 * Get seasons for the exporter.
 *
 * @return WP_Term[]
 */
function tse_sp_schedule_exporter_get_seasons() {
	$seasons = get_terms(
		array(
			'taxonomy'   => 'sp_season',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $seasons ) || ! is_array( $seasons ) ) {
		return array();
	}

	return $seasons;
}

/**
 * Get fields for the exporter.
 *
 * @return WP_Term[]
 */
function tse_sp_schedule_exporter_get_fields() {
	$fields = get_terms(
		array(
			'taxonomy'   => 'sp_venue',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $fields ) || ! is_array( $fields ) ) {
		return array();
	}

	return $fields;
}

/**
 * Get teams for the exporter.
 *
 * @param int $league_id League ID.
 * @param int $season_id Season ID.
 * @return WP_Post[]
 */
function tse_sp_schedule_exporter_get_teams( $league_id = 0, $season_id = 0 ) {
	$tax_query = array();

	if ( $league_id > 0 ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_league',
			'field'    => 'term_id',
			'terms'    => array( $league_id ),
		);
	}

	if ( $season_id > 0 ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_season',
			'field'    => 'term_id',
			'terms'    => array( $season_id ),
		);
	}

	$args = array(
		'post_type'      => 'sp_team',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'cache_results'          => false,
	);

	if ( ! empty( $tax_query ) ) {
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$args['tax_query'] = $tax_query;
	}

	$teams = get_posts( $args );

	return is_array( $teams ) ? $teams : array();
}

/**
 * Resolve selected team ID.
 *
 * @param WP_Post[] $teams Team posts.
 * @return int
 */
function tse_sp_schedule_exporter_resolve_team_id( $teams ) {
	$requested = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;
	if ( $requested > 0 && 'sp_team' === get_post_type( $requested ) ) {
		foreach ( $teams as $team ) {
			if ( $team instanceof WP_Post && (int) $team->ID === $requested ) {
				return $requested;
			}
		}
	}

	if ( isset( $teams[0] ) && $teams[0] instanceof WP_Post ) {
		return (int) $teams[0]->ID;
	}

	return 0;
}

/**
 * Resolve selected field ID.
 *
 * @param WP_Term[] $fields Field terms.
 * @return int
 */
function tse_sp_schedule_exporter_resolve_field_id( $fields ) {
	$requested = isset( $_GET['field_id'] ) ? absint( wp_unslash( $_GET['field_id'] ) ) : 0;

	if ( 0 === $requested ) {
		return 0;
	}

	foreach ( $fields as $field ) {
		if ( $field instanceof WP_Term && (int) $field->term_id === $requested ) {
			return $requested;
		}
	}

	return 0;
}

/**
 * Render selectable columns for a format.
 *
 * @param string $format  Export format.
 * @param string $context Render context suffix.
 * @return void
 */
function tse_sp_schedule_exporter_render_column_picker( $format, $context, $active_format = '' ) {
	$format      = tse_sp_event_export_sanitize_format( $format );
	$definitions = tse_sp_event_export_get_column_definitions();
	$columns     = isset( $definitions[ $format ] ) ? $definitions[ $format ] : array();
	$selected    = tse_sp_event_export_get_default_columns( $format );
	$formats     = tse_sp_event_export_get_formats();
	$legend      = isset( $formats[ $format ]['label'] ) ? $formats[ $format ]['label'] : ucfirst( $format );

	if ( empty( $columns ) ) {
		return;
	}

	$style = 'margin:18px 0;padding:16px;border:1px solid #d7d7db;';
	if ( $active_format && $active_format !== $format ) {
		$style .= 'display:none;';
	}

	echo '<fieldset data-column-group="' . esc_attr( $format ) . '" style="' . esc_attr( $style ) . '">';
	echo '<legend><strong>' . esc_html( sprintf( __( '%s Columns', 'tonys-sportspress-enhancements' ), $legend ) ) . '</strong></legend>';
	echo '<div style="display:flex;flex-wrap:wrap;gap:12px 18px;">';

	foreach ( $columns as $column_key => $column_label ) {
		$input_id = sprintf( 'tse-columns-%1$s-%2$s-%3$s', sanitize_html_class( $context ), sanitize_html_class( $format ), sanitize_html_class( $column_key ) );

		echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-columns-format="' . esc_attr( $format ) . '" value="' . esc_attr( $column_key ) . '" ' . checked( in_array( $column_key, $selected, true ), true, false ) . ' />';
		echo esc_html( $column_label );
		echo '</label>';
	}

	echo '</div>';
	echo '<p class="description" style="margin:10px 0 0;">' . esc_html__( 'These checkboxes only change the CSV feed link. iCal and printable links use the same shared filters but ignore columns.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</fieldset>';
}

/**
 * Resolve selected league ID.
 *
 * @param WP_Term[] $leagues League terms.
 * @return int
 */
function tse_sp_schedule_exporter_resolve_league_id( $leagues ) {
	$requested = isset( $_GET['league_id'] ) ? absint( wp_unslash( $_GET['league_id'] ) ) : 0;
	if ( $requested > 0 ) {
		return $requested;
	}

	foreach ( $leagues as $league ) {
		if ( ! $league instanceof WP_Term ) {
			continue;
		}

		$slug = isset( $league->slug ) ? strtolower( (string) $league->slug ) : '';
		$name = isset( $league->name ) ? strtolower( trim( (string) $league->name ) ) : '';

		if ( 'cmba' === $slug || 'cmba' === $name ) {
			return (int) $league->term_id;
		}
	}

	if ( isset( $leagues[0] ) && $leagues[0] instanceof WP_Term ) {
		return (int) $leagues[0]->term_id;
	}

	return 0;
}

/**
 * Resolve selected season ID.
 *
 * @param WP_Term[] $seasons Season terms.
 * @return int
 */
function tse_sp_schedule_exporter_resolve_season_id( $seasons ) {
	$requested = isset( $_GET['season_id'] ) ? absint( wp_unslash( $_GET['season_id'] ) ) : 0;
	if ( $requested > 0 ) {
		return $requested;
	}

	$current = absint( (string) get_option( 'sportspress_season', '0' ) );
	if ( $current > 0 ) {
		return $current;
	}

	if ( isset( $seasons[0] ) && is_object( $seasons[0] ) && isset( $seasons[0]->term_id ) ) {
		return (int) $seasons[0]->term_id;
	}

	return 0;
}

/**
 * Get supported paper sizes.
 *
 * @return array
 */
function tse_sp_schedule_exporter_get_paper_sizes() {
	return array(
		'letter' => __( 'Letter', 'tonys-sportspress-enhancements' ),
		'ledger' => __( '11x17 / Ledger', 'tonys-sportspress-enhancements' ),
	);
}

/**
 * Resolve selected paper size.
 *
 * @return string
 */
function tse_sp_schedule_exporter_resolve_paper_size() {
	$paper = isset( $_GET['paper'] ) ? sanitize_key( wp_unslash( $_GET['paper'] ) ) : 'letter';

	return array_key_exists( $paper, tse_sp_schedule_exporter_get_paper_sizes() ) ? $paper : 'letter';
}

/**
 * Resolve selected export format.
 *
 * @return string
 */
function tse_sp_schedule_exporter_resolve_format() {
	$requested = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'matchup';

	return tse_sp_event_export_sanitize_format( $requested );
}

/**
 * Get supported exporter output types.
 *
 * @return array
 */
function tse_sp_schedule_exporter_get_export_types() {
	return array(
		'csv'       => __( 'CSV', 'tonys-sportspress-enhancements' ),
		'ics'       => __( 'iCal Link', 'tonys-sportspress-enhancements' ),
		'printable' => __( 'Printable', 'tonys-sportspress-enhancements' ),
	);
}

/**
 * Resolve selected exporter output type.
 *
 * @return string
 */
function tse_sp_schedule_exporter_resolve_export_type() {
	$requested = isset( $_GET['export_type'] ) ? sanitize_key( wp_unslash( $_GET['export_type'] ) ) : 'csv';
	$types     = tse_sp_schedule_exporter_get_export_types();

	return isset( $types[ $requested ] ) ? $requested : 'csv';
}

/**
 * Resolve selected CSV subformat.
 *
 * @return string
 */
function tse_sp_schedule_exporter_resolve_subformat() {
	$requested = isset( $_GET['subformat'] ) ? sanitize_key( wp_unslash( $_GET['subformat'] ) ) : 'matchup';

	return tse_sp_event_export_sanitize_format( $requested );
}

/**
 * Get current output URL for the selected export type.
 *
 * @param string $export_type Export type.
 * @param string $csv_url     CSV URL.
 * @param string $ics_url     ICS URL.
 * @param string $print_url   Printable URL.
 * @return string
 */
function tse_sp_schedule_exporter_get_output_url( $export_type, $csv_url, $ics_url, $print_url ) {
	if ( 'ics' === $export_type ) {
		return $ics_url;
	}

	if ( 'printable' === $export_type ) {
		return $print_url;
	}

	return $csv_url;
}

/**
 * Collect team schedule events for export.
 *
 * @param int $team_id   Team ID.
 * @param int $season_id Optional season ID.
 * @param int $league_id Optional league ID.
 * @return array
 */
function tse_sp_schedule_exporter_get_events( $team_id, $season_id = 0, $league_id = 0 ) {
	$team_id = absint( $team_id );
	if ( $team_id <= 0 || 'sp_team' !== get_post_type( $team_id ) ) {
		return array();
	}

	$args = array(
		'post_type'      => 'sp_event',
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'     => 'sp_team',
				'value'   => array( (string) $team_id ),
				'compare' => 'IN',
			),
		),
	);

	$tax_query = array();

	if ( $season_id > 0 ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_season',
			'field'    => 'term_id',
			'terms'    => array( $season_id ),
		);
	}

	if ( $league_id > 0 ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_league',
			'field'    => 'term_id',
			'terms'    => array( $league_id ),
		);
	}

	if ( ! empty( $tax_query ) ) {
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$args['tax_query'] = $tax_query;
	}

	$query   = new WP_Query( $args );
	$events   = array();
	$team_name = get_the_title( $team_id );

	foreach ( $query->posts as $event ) {
		$event_id = $event instanceof WP_Post ? (int) $event->ID : 0;
		if ( $event_id <= 0 ) {
			continue;
		}

		$teams = array_values( array_unique( array_map( 'intval', get_post_meta( $event_id, 'sp_team', false ) ) ) );
		if ( ! in_array( $team_id, $teams, true ) ) {
			continue;
		}

		$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
		$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;

		$location_flag = $home_id === $team_id ? 'Home' : 'Away';
		$opponent_id   = $home_id === $team_id ? $away_id : $home_id;
		$venue         = tse_sp_schedule_exporter_get_primary_venue( $event_id );

		$events[] = array(
			'label'               => '',
			'event_id'            => $event_id,
			'date'                => get_post_time( 'm/d/Y', false, $event_id, true ),
			'time'                => strtoupper( (string) ( function_exists( 'sp_get_time' ) ? sp_get_time( $event_id ) : get_post_time( get_option( 'time_format' ), false, $event_id, true ) ) ),
			'team_name'           => is_string( $team_name ) ? $team_name : '',
			'opponent_name'       => $opponent_id > 0 ? get_the_title( $opponent_id ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
			'location_flag'       => $location_flag,
			'home_team'           => $home_id > 0 ? get_the_title( $home_id ) : '',
			'away_team'           => $away_id > 0 ? get_the_title( $away_id ) : '',
			'venue_name'          => isset( $venue['name'] ) ? $venue['name'] : '',
			'venue_abbreviation'  => isset( $venue['abbreviation'] ) ? $venue['abbreviation'] : '',
			'venue_short_name'    => isset( $venue['short_name'] ) ? $venue['short_name'] : '',
		);
	}

	foreach ( $events as $index => $event ) {
		$events[ $index ]['label'] = sprintf( 'G#%02d', $index + 1 );
	}

	wp_reset_postdata();

	return $events;
}

/**
 * Get the primary venue details for an event.
 *
 * @param int $event_id Event ID.
 * @return array
 */
function tse_sp_schedule_exporter_get_primary_venue( $event_id ) {
	$venues = get_the_terms( $event_id, 'sp_venue' );

	if ( ! is_array( $venues ) || ! isset( $venues[0] ) || ! $venues[0] instanceof WP_Term ) {
		return array(
			'name'         => '',
			'abbreviation' => '',
			'short_name'   => '',
		);
	}

	$venue = $venues[0];

	return array(
		'name'         => isset( $venue->name ) ? (string) $venue->name : '',
		'abbreviation' => trim( (string) get_term_meta( $venue->term_id, 'tse_abbreviation', true ) ),
		'short_name'   => trim( (string) get_term_meta( $venue->term_id, 'tse_short_name', true ) ),
	);
}

/**
 * Build the printable page URL.
 *
 * @param int    $team_id   Team ID.
 * @param int    $season_id Season ID.
 * @param string $paper     Paper size.
 * @param int    $league_id League ID.
 * @return string
 */
function tse_sp_schedule_exporter_get_printable_url( $team_id, $season_id, $paper, $league_id = 0, $autoprint = false ) {
	return add_query_arg(
		array(
			Tony_Sportspress_Printable_Calendars::QUERY_FLAG => '1',
			'sp_team'                                        => (string) absint( $team_id ),
			'sp_season'                                      => $season_id > 0 ? (string) absint( $season_id ) : '',
			'sp_league'                                      => $league_id > 0 ? (string) absint( $league_id ) : '',
			'paper'                                          => $paper,
			'autoprint'                                      => $autoprint ? '1' : '',
		),
		home_url( '/' )
	);
}

/**
 * Render a small script that keeps export links in sync with current selections.
 *
 * @param bool $echo Whether to echo immediately.
 * @return string
 */
function tse_sp_schedule_exporter_render_link_sync_script( $echo = false ) {
	$script = <<<HTML
<script>
(function(){
	function copyText(text, done){
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done).catch(function(){
				var input = document.createElement('input');
				input.value = text;
				document.body.appendChild(input);
				input.focus();
				input.select();
				document.execCommand('copy');
				document.body.removeChild(input);
				done();
			});
			return;
		}
		var input = document.createElement('input');
		input.value = text;
		document.body.appendChild(input);
		input.focus();
		input.select();
		document.execCommand('copy');
		document.body.removeChild(input);
		done();
	}

	function syncLinks(scope){
		var form = scope.querySelector('.tse-schedule-exporter-form');
		if (!form) {
			return;
		}

		var league = form.querySelector('[name="league_id"]');
		var season = form.querySelector('[name="season_id"]');
		var team = form.querySelector('[name="team_id"]');
		var exportType = form.querySelector('[name="export_type"]');
		var subformat = form.querySelector('[name="subformat"]');
		var field = form.querySelector('[name="field_id"]');
		var outputUrl = scope.querySelector('.tse-output-url');
		var openButton = scope.querySelector('.tse-open-link');
		var iosButton = scope.querySelector('.tse-ics-ios-link');
		var androidButton = scope.querySelector('.tse-ics-android-link');
		var outputNote = scope.querySelector('.tse-output-note');
		var copyButton = scope.querySelector('.tse-copy-link');
		var teamValue = team ? (team.value || '0') : '0';
		var activeSubformat = subformat ? (subformat.value || 'matchup') : 'matchup';
		var selectedExportType = exportType ? (exportType.value || 'csv') : 'csv';

		scope.querySelectorAll('[data-column-group]').forEach(function(group){
			var visible = selectedExportType === 'csv' && group.getAttribute('data-column-group') === activeSubformat;
			group.style.display = visible ? 'block' : 'none';
		});

		if (scope.querySelector('[data-subformat-wrap]')) {
			scope.querySelectorAll('[data-subformat-wrap]').forEach(function(wrap){
				wrap.style.display = selectedExportType === 'csv' ? 'block' : 'none';
			});
		}

		var csvUrl = openButton ? new URL(openButton.dataset.csvUrl, window.location.origin) : null;
		var icsUrl = openButton ? new URL(openButton.dataset.icsUrl, window.location.origin) : null;
		var printUrl = openButton ? new URL(openButton.dataset.printUrl, window.location.origin) : null;

		if (csvUrl) {
			if (league) csvUrl.searchParams.set('league_id', league.value || '0');
			if (season) csvUrl.searchParams.set('season_id', season.value || '0');
			if (team) csvUrl.searchParams.set('team_id', teamValue);
			if (field) csvUrl.searchParams.set('field_id', field.value || '0');
			csvUrl.searchParams.set('format', activeSubformat);
			var columns = Array.prototype.slice.call(scope.querySelectorAll('[data-columns-format="' + activeSubformat + '"]:checked')).map(function(input){
				return input.value;
			}).filter(Boolean);
			if (columns.length) {
				csvUrl.searchParams.set('columns', columns.join(','));
			} else {
				csvUrl.searchParams.delete('columns');
			}
		}

		if (icsUrl) {
			if (league) icsUrl.searchParams.set('league_id', league.value || '0');
			if (season) icsUrl.searchParams.set('season_id', season.value || '0');
			if (team) icsUrl.searchParams.set('team_id', teamValue);
			if (field) icsUrl.searchParams.set('field_id', field.value || '0');
			icsUrl.searchParams.delete('format');
			icsUrl.searchParams.delete('columns');
		}

		if (printUrl) {
			if (league) printUrl.searchParams.set('sp_league', league.value || '0');
			if (season) printUrl.searchParams.set('sp_season', season.value || '0');
			if (team) printUrl.searchParams.set('sp_team', teamValue);
			printUrl.searchParams.set('paper', 'letter');
		}

		var resolvedUrl = csvUrl ? csvUrl.toString() : '';
		var label = 'Open URL in New Tab';
		var disabled = false;
		var note = 'Use the buttons to copy the generated URL or open the right destination for this export type.';
		var iosUrl = icsUrl ? icsUrl.toString().replace(/^https?:\/\//, 'webcal://') : '';
		var androidUrl = icsUrl ? 'https://calendar.google.com/calendar/render?cid=' + encodeURIComponent(icsUrl.toString()) : '';

		if (selectedExportType === 'ics' && icsUrl) {
			resolvedUrl = icsUrl.toString();
			note = 'Use the iPhone/iPad or Android button to subscribe, or copy the feed URL.';
		} else if (selectedExportType === 'printable' && printUrl) {
			resolvedUrl = printUrl.toString();
			if (teamValue === '0') {
				disabled = true;
				note = 'Printable requires a specific team. All teams is not supported.';
			}
		} else if (selectedExportType === 'csv' && activeSubformat === 'team' && teamValue === '0') {
			disabled = true;
			note = 'CSV team layout requires a specific team. All teams is not supported.';
		}

		if (outputUrl) {
			outputUrl.value = resolvedUrl;
		}

		if (openButton) {
			openButton.dataset.currentUrl = resolvedUrl;
			openButton.textContent = label;
			openButton.style.display = selectedExportType === 'ics' ? 'none' : 'inline-flex';
			openButton.disabled = disabled;
			openButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
			openButton.style.opacity = disabled ? '0.55' : '1';
		}

		if (iosButton) {
			iosButton.dataset.currentUrl = iosUrl;
			iosButton.style.display = selectedExportType === 'ics' ? 'inline-flex' : 'none';
			iosButton.disabled = !iosUrl;
			iosButton.setAttribute('aria-disabled', !iosUrl ? 'true' : 'false');
			iosButton.style.opacity = !iosUrl ? '0.55' : '1';
		}

		if (androidButton) {
			androidButton.dataset.currentUrl = androidUrl;
			androidButton.style.display = selectedExportType === 'ics' ? 'inline-flex' : 'none';
			androidButton.disabled = !androidUrl;
			androidButton.setAttribute('aria-disabled', !androidUrl ? 'true' : 'false');
			androidButton.style.opacity = !androidUrl ? '0.55' : '1';
		}

		if (outputNote) {
			outputNote.textContent = note;
		}

		if (copyButton) {
			copyButton.disabled = disabled;
		}
	}

	document.querySelectorAll('.tse-schedule-exporter, .wrap').forEach(function(scope){
		if (!scope.querySelector('.tse-schedule-exporter-form')) {
			return;
		}

		syncLinks(scope);

		scope.querySelectorAll('.tse-schedule-exporter-form select').forEach(function(select){
			select.addEventListener('change', function(){
				if (select.dataset.autoSubmit === '1') {
					select.form.submit();
					return;
				}

				syncLinks(scope);
			});
		});

		scope.querySelectorAll('[data-columns-format]').forEach(function(input){
			input.addEventListener('change', function(){
				syncLinks(scope);
			});
		});

		var copyButton = scope.querySelector('.tse-copy-link');
		var openButton = scope.querySelector('.tse-open-link');
		var iosButton = scope.querySelector('.tse-ics-ios-link');
		var androidButton = scope.querySelector('.tse-ics-android-link');
		var outputUrl = scope.querySelector('.tse-output-url');
		if (copyButton && outputUrl) {
			copyButton.addEventListener('click', function(){
				if (copyButton.disabled || !outputUrl.value) {
					return;
				}

				var defaultTitle = copyButton.getAttribute('data-default-title') || copyButton.title || 'Copy URL';
				copyButton.setAttribute('data-default-title', defaultTitle);

				copyText(outputUrl.value, function(){
					copyButton.title = 'Copied';
					window.setTimeout(function(){
						copyButton.title = defaultTitle;
					}, 1200);
				});
			});
		}

		if (openButton && outputUrl) {
			openButton.addEventListener('click', function(){
				if (openButton.disabled || !outputUrl.value) {
					return;
				}

				window.open(outputUrl.value, '_blank', 'noopener,noreferrer');
			});
		}

		if (iosButton) {
			iosButton.addEventListener('click', function(){
				var targetUrl = iosButton.dataset.currentUrl || '';
				if (iosButton.disabled || !targetUrl) {
					return;
				}

				window.location.href = targetUrl;
			});
		}

		if (androidButton) {
			androidButton.addEventListener('click', function(){
				var targetUrl = androidButton.dataset.currentUrl || '';
				if (androidButton.disabled || !targetUrl) {
					return;
				}

				window.open(targetUrl, '_blank', 'noopener,noreferrer');
			});
		}
	});
})();
</script>
HTML;

	if ( $echo ) {
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return '';
	}

	return $script;
}
