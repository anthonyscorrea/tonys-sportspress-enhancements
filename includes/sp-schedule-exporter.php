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

	$team_id   = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;
	$season_id = isset( $_GET['season_id'] ) ? absint( wp_unslash( $_GET['season_id'] ) ) : 0;
	$league_id = isset( $_GET['league_id'] ) ? absint( wp_unslash( $_GET['league_id'] ) ) : 0;
	$format    = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';

	if ( $team_id <= 0 || 'sp_team' !== get_post_type( $team_id ) ) {
		wp_die( esc_html__( 'Choose a valid team before exporting.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 400 ) );
	}

	if ( ! in_array( $format, array( 'matchup', 'team' ), true ) ) {
		wp_die( esc_html__( 'Choose a valid export format.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 400 ) );
	}

	$events = tse_sp_schedule_exporter_get_events( $team_id, $season_id, $league_id );
	$team   = get_post( $team_id );

	if ( ! $team instanceof WP_Post ) {
		wp_die( esc_html__( 'The selected team could not be loaded.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 404 ) );
	}

	$filename = sanitize_title( $team->post_name ? $team->post_name : $team->post_title );
	if ( '' === $filename ) {
		$filename = 'schedule';
	}

	if ( $season_id > 0 ) {
		$season = get_term( $season_id, 'sp_season' );
		if ( $season && ! is_wp_error( $season ) && ! empty( $season->slug ) ) {
			$filename .= '-' . sanitize_title( $season->slug );
		}
	}

	$filename .= '-' . $format . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$output = fopen( 'php://output', 'w' );
	if ( false === $output ) {
		wp_die( esc_html__( 'Unable to start the CSV export.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	fwrite( $output, "\xEF\xBB\xBF" );

	if ( 'matchup' === $format ) {
		fputcsv(
			$output,
			array(
				'Date',
				'Time',
				'Away Team',
				'Home Team',
				'Field Name',
			)
		);

		foreach ( $events as $event ) {
			fputcsv(
				$output,
				array(
					$event['date'],
					$event['time'],
					$event['away_team'],
					$event['home_team'],
					$event['venue_name'],
				)
			);
		}
	} else {
		fputcsv(
			$output,
			array(
				'Extra Label',
				'Date',
				'Time',
				'Opponent',
				'Home/Away',
				'Venue',
			)
		);

		foreach ( $events as $event ) {
			fputcsv(
				$output,
				array(
					$event['label'],
					$event['date'],
					$event['time'],
					$event['opponent_name'],
					$event['location_flag'],
					$event['venue_name'],
				)
			);
		}
	}

	fclose( $output );
	exit;
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
			'description'     => __( 'Shows the public schedule exporter with CSV and printable PDF options.', 'tonys-sportspress-enhancements' ),
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
	$paper     = tse_sp_schedule_exporter_resolve_paper_size();

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Schedule Exporter', 'tonys-sportspress-enhancements' ) . '</h1>';
	echo '<p>' . esc_html__( 'Choose a team and season, then export the schedule as CSV or open the printable schedule in a PDF-ready print view.', 'tonys-sportspress-enhancements' ) . '</p>';

	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="tse-schedule-exporter-form" style="max-width:960px;margin:20px 0 28px;">';
	echo '<input type="hidden" name="page" value="tse-schedule-exporter" />';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr>';
	echo '<th scope="row"><label for="tse-schedule-exporter-league">' . esc_html__( 'League', 'tonys-sportspress-enhancements' ) . '</label></th>';
	echo '<td><select id="tse-schedule-exporter-league" name="league_id" data-auto-submit="1">';
	foreach ( $leagues as $league ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $league->term_id ),
			selected( $league_id, (int) $league->term_id, false ),
			esc_html( $league->name )
		);
	}
	echo '</select></td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="tse-schedule-exporter-season">' . esc_html__( 'Season', 'tonys-sportspress-enhancements' ) . '</label></th>';
	echo '<td><select id="tse-schedule-exporter-season" name="season_id" data-auto-submit="1">';
	echo '<option value="0">' . esc_html__( 'Current / All matching events', 'tonys-sportspress-enhancements' ) . '</option>';
	foreach ( $seasons as $season ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( (string) $season->term_id ),
			selected( $season_id, (int) $season->term_id, false ),
			esc_html( $season->name )
		);
	}
	echo '</select></td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="tse-schedule-exporter-paper">' . esc_html__( 'Paper Size', 'tonys-sportspress-enhancements' ) . '</label></th>';
	echo '<td><select id="tse-schedule-exporter-paper" name="paper">';
	foreach ( tse_sp_schedule_exporter_get_paper_sizes() as $paper_value => $paper_label ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $paper_value ),
			selected( $paper, $paper_value, false ),
			esc_html( $paper_label )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'The PDF option opens the existing printable schedule and triggers the browser print dialog so you can save it as a PDF.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<th scope="row"><label for="tse-schedule-exporter-team">' . esc_html__( 'Team', 'tonys-sportspress-enhancements' ) . '</label></th>';
	echo '<td><select id="tse-schedule-exporter-team" name="team_id">';
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
	echo '</td>';
	echo '</tr>';

	echo '</tbody></table>';
	echo '</form>';

	if ( empty( $teams ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No SportsPress teams match the selected league and season.', 'tonys-sportspress-enhancements' ) . '</p></div>';
		echo '</div>';
		return;
	}

	echo '<div style="max-width:960px;padding:20px 24px;border:1px solid #dcdcde;background:#fff;">';
	echo '<h2 style="margin-top:0;">' . esc_html__( 'Exports', 'tonys-sportspress-enhancements' ) . '</h2>';

	echo '<table class="widefat striped" style="max-width:100%;margin-top:16px;"><tbody>';
	foreach ( array(
		array(
			'format'      => 'matchup',
			'label'       => __( 'Download Matchup CSV', 'tonys-sportspress-enhancements' ),
			'description' => __( 'Date, time, away team, home team, and field name.', 'tonys-sportspress-enhancements' ),
		),
		array(
			'format'      => 'team',
			'label'       => __( 'Download Team CSV', 'tonys-sportspress-enhancements' ),
			'description' => __( 'TeamSnap-compatible layout with game label, opponent, home/away flag, and venue.', 'tonys-sportspress-enhancements' ),
		),
	) as $export_option ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'tse_schedule_export',
					'league_id' => $league_id,
					'team_id'   => $team_id,
					'season_id' => $season_id,
					'format'    => $export_option['format'],
				),
				admin_url( 'admin-post.php' )
			),
			'tse_schedule_export'
		);

		echo '<tr>';
		echo '<td style="width:240px;"><a class="button button-primary tse-export-link" data-format="' . esc_attr( $export_option['format'] ) . '" href="' . esc_url( $url ) . '">' . esc_html( $export_option['label'] ) . '</a></td>';
		echo '<td>' . esc_html( $export_option['description'] ) . '</td>';
		echo '</tr>';
	}

	$pdf_url = tse_sp_schedule_exporter_get_pdf_url( $team_id, $season_id, $paper, $league_id );
	echo '<tr>';
	echo '<td style="width:240px;"><a class="button tse-pdf-link" href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Printable PDF View', 'tonys-sportspress-enhancements' ) . '</a></td>';
	echo '<td>' . esc_html__( 'Opens the printable schedule and launches the browser print dialog so you can save a PDF.', 'tonys-sportspress-enhancements' ) . '</td>';
	echo '</tr>';
	echo '</tbody></table>';
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
	$paper     = tse_sp_schedule_exporter_resolve_paper_size();

	if ( empty( $teams ) ) {
		return '<p>' . esc_html__( 'No SportsPress teams match the selected league and season.', 'tonys-sportspress-enhancements' ) . '</p>';
	}

	ob_start();
	?>
	<div class="tse-schedule-exporter" style="max-width:960px;margin:0 auto;padding:24px;border:1px solid #d7d7db;background:#fff;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Schedule Exporter', 'tonys-sportspress-enhancements' ); ?></h2>
		<p><?php esc_html_e( 'Export schedules as CSV or open the printable version and save it as a PDF.', 'tonys-sportspress-enhancements' ); ?></p>

		<form method="get" action="<?php echo esc_url( get_permalink() ); ?>" class="tse-schedule-exporter-form" style="display:grid;gap:16px;margin:24px 0;">
			<div>
				<label for="tse-public-league"><strong><?php esc_html_e( 'League', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-league" name="league_id" data-auto-submit="1">
					<?php foreach ( $leagues as $league ) : ?>
						<option value="<?php echo esc_attr( (string) $league->term_id ); ?>" <?php selected( $league_id, (int) $league->term_id ); ?>>
							<?php echo esc_html( $league->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div>
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

			<div>
				<label for="tse-public-paper"><strong><?php esc_html_e( 'Paper Size', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-paper" name="paper">
					<?php foreach ( tse_sp_schedule_exporter_get_paper_sizes() as $paper_value => $paper_label ) : ?>
						<option value="<?php echo esc_attr( $paper_value ); ?>" <?php selected( $paper, $paper_value ); ?>>
							<?php echo esc_html( $paper_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div>
				<label for="tse-public-team"><strong><?php esc_html_e( 'Team', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
				<select id="tse-public-team" name="team_id">
					<?php foreach ( $teams as $team ) : ?>
						<option value="<?php echo esc_attr( (string) $team->ID ); ?>" <?php selected( $team_id, (int) $team->ID ); ?>>
							<?php echo esc_html( $team->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

		</form>

		<table style="width:100%;border-collapse:collapse;margin-top:16px;">
			<tbody>
				<tr>
					<td style="width:240px;padding:10px 12px;border:1px solid #d7d7db;">
						<a class="button button-primary tse-export-link" data-format="matchup" href="<?php echo esc_url( tse_sp_schedule_exporter_get_export_url( $team_id, $season_id, 'matchup', $league_id ) ); ?>"><?php esc_html_e( 'Download Matchup CSV', 'tonys-sportspress-enhancements' ); ?></a>
					</td>
					<td style="padding:10px 12px;border:1px solid #d7d7db;"><?php esc_html_e( 'Date, time, away team, home team, and field name.', 'tonys-sportspress-enhancements' ); ?></td>
				</tr>
				<tr>
					<td style="width:240px;padding:10px 12px;border:1px solid #d7d7db;">
						<a class="button button-primary tse-export-link" data-format="team" href="<?php echo esc_url( tse_sp_schedule_exporter_get_export_url( $team_id, $season_id, 'team', $league_id ) ); ?>"><?php esc_html_e( 'Download Team CSV', 'tonys-sportspress-enhancements' ); ?></a>
					</td>
					<td style="padding:10px 12px;border:1px solid #d7d7db;"><?php esc_html_e( 'TeamSnap-compatible layout with game label, opponent, home/away flag, and venue.', 'tonys-sportspress-enhancements' ); ?></td>
				</tr>
				<tr>
					<td style="width:240px;padding:10px 12px;border:1px solid #d7d7db;">
						<a class="button tse-pdf-link" href="<?php echo esc_url( tse_sp_schedule_exporter_get_pdf_url( $team_id, $season_id, $paper, $league_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Printable PDF View', 'tonys-sportspress-enhancements' ); ?></a>
					</td>
					<td style="padding:10px 12px;border:1px solid #d7d7db;"><?php esc_html_e( 'Opens the printable schedule and starts the print dialog so visitors can save a PDF.', 'tonys-sportspress-enhancements' ); ?></td>
				</tr>
			</tbody>
		</table>
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
 * Build an export download URL.
 *
 * @param int    $team_id   Team ID.
 * @param int    $season_id Season ID.
 * @param string $format    Export format.
 * @param int    $league_id League ID.
 * @return string
 */
function tse_sp_schedule_exporter_get_export_url( $team_id, $season_id, $format, $league_id = 0 ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'    => 'tse_schedule_export',
				'league_id' => absint( $league_id ),
				'team_id'   => absint( $team_id ),
				'season_id' => absint( $season_id ),
				'format'    => sanitize_key( $format ),
			),
			admin_url( 'admin-post.php' )
		),
		'tse_schedule_export'
	);
}

/**
 * Build the printable PDF URL.
 *
 * @param int    $team_id   Team ID.
 * @param int    $season_id Season ID.
 * @param string $paper     Paper size.
 * @param int    $league_id League ID.
 * @return string
 */
function tse_sp_schedule_exporter_get_pdf_url( $team_id, $season_id, $paper, $league_id = 0 ) {
	return add_query_arg(
		array(
			Tony_Sportspress_Printable_Calendars::QUERY_FLAG => '1',
			'sp_team'                                        => (string) absint( $team_id ),
			'sp_season'                                      => $season_id > 0 ? (string) absint( $season_id ) : '',
			'sp_league'                                      => $league_id > 0 ? (string) absint( $league_id ) : '',
			'paper'                                          => $paper,
			'autoprint'                                      => '1',
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
	function syncLinks(scope){
		var form = scope.querySelector('.tse-schedule-exporter-form');
		if (!form) {
			return;
		}

		var league = form.querySelector('[name="league_id"]');
		var season = form.querySelector('[name="season_id"]');
		var team = form.querySelector('[name="team_id"]');
		var paper = form.querySelector('[name="paper"]');

		scope.querySelectorAll('.tse-export-link').forEach(function(link){
			var url = new URL(link.href, window.location.origin);
			if (league) url.searchParams.set('league_id', league.value || '0');
			if (season) url.searchParams.set('season_id', season.value || '0');
			if (team) url.searchParams.set('team_id', team.value || '0');
			if (link.dataset.format) url.searchParams.set('format', link.dataset.format);
			link.href = url.toString();
		});

		scope.querySelectorAll('.tse-pdf-link').forEach(function(link){
			var url = new URL(link.href, window.location.origin);
			if (league) url.searchParams.set('sp_league', league.value || '0');
			if (season) url.searchParams.set('sp_season', season.value || '0');
			if (team) url.searchParams.set('sp_team', team.value || '0');
			if (paper) url.searchParams.set('paper', paper.value || 'letter');
			link.href = url.toString();
		});
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
