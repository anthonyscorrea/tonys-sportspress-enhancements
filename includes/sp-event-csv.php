<?php
/**
 * SportsPress event CSV tools.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the SportsPress calendar CSV feed endpoint.
 *
 * @return void
 */
function tse_sp_register_calendar_csv_feed() {
	add_feed( 'sp-csv', 'tse_sp_render_calendar_csv_feed' );
}
add_action( 'init', 'tse_sp_register_calendar_csv_feed', 20 );

/**
 * Replace the stock SportsPress calendar feeds metabox with one that includes CSV.
 *
 * @return void
 */
function tse_sp_replace_calendar_feeds_metabox() {
	remove_meta_box( 'sp_feedsdiv', 'sp_calendar', 'side' );
	add_meta_box(
		'sp_feedsdiv',
		esc_attr__( 'Feeds', 'sportspress' ),
		'tse_sp_render_calendar_feeds_metabox',
		'sp_calendar',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_sp_calendar', 'tse_sp_replace_calendar_feeds_metabox', 40 );

/**
 * Return the CSV feed format definition used in the metabox.
 *
 * @return array
 */
function tse_sp_get_calendar_csv_feed_formats() {
	return array(
		'download' => array(
			'name' => __( 'CSV Download', 'tonys-sportspress-enhancements' ),
		),
	);
}

/**
 * Render the calendar feeds metabox with CSV support.
 *
 * @param WP_Post $post Current calendar post.
 * @return void
 */
function tse_sp_render_calendar_feeds_metabox( $post ) {
	$feeds                = new SP_Feeds();
	$calendar_feeds       = is_array( $feeds->calendar ) ? $feeds->calendar : array();
	$calendar_feeds['csv'] = tse_sp_get_calendar_csv_feed_formats();
	?>
	<div>
		<?php foreach ( $calendar_feeds as $slug => $formats ) : ?>
			<?php
			if ( 'csv' === $slug ) {
				$link = tse_sp_get_calendar_csv_url( $post->ID );
			} else {
				$link = add_query_arg( 'feed', 'sp-' . $slug, untrailingslashit( get_post_permalink( $post ) ) );
			}
			?>
			<?php foreach ( $formats as $format ) : ?>
				<?php
				if ( 'csv' === $slug ) {
					$feed = $link;
				} else {
					$protocol = sp_array_value( $format, 'protocol' );
					if ( $protocol ) {
						$feed = str_replace( array( 'http:', 'https:' ), 'webcal:', $link );
					} else {
						$feed = $link;
					}
					$prefix = sp_array_value( $format, 'prefix' );
					if ( $prefix ) {
						$feed = $prefix . urlencode( $feed );
					}
				}
				?>
				<p>
					<strong><?php echo esc_html( sp_array_value( $format, 'name' ) ); ?></strong>
					<a class="sp-link" href="<?php echo esc_url( $feed ); ?>" target="_blank" title="<?php esc_attr_e( 'Link', 'sportspress' ); ?>"></a>
				</p>
				<p>
					<input type="text" value="<?php echo esc_attr( $feed ); ?>" readonly="readonly" class="code widefat">
				</p>
				<?php if ( 'csv' === $slug ) : ?>
					<p class="description">
						<?php esc_html_e( 'Optional team filter: add &team_id=123 to only include games for that team.', 'tonys-sportspress-enhancements' ); ?>
					</p>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Build the CSV feed URL for a SportsPress calendar.
 *
 * @param int $calendar_id SportsPress calendar post ID.
 * @param int $team_id     Optional team ID filter.
 * @return string
 */
function tse_sp_get_calendar_csv_url( $calendar_id, $team_id = 0 ) {
	$calendar_id = absint( $calendar_id );
	$team_id     = absint( $team_id );

	if ( ! $calendar_id || 'sp_calendar' !== get_post_type( $calendar_id ) ) {
		return '';
	}

	$url = add_query_arg( 'feed', 'sp-csv', get_post_permalink( $calendar_id ) );

	if ( $team_id ) {
		$url = add_query_arg( 'team_id', $team_id, $url );
	}

	return $url;
}

/**
 * Get the queried SportsPress calendar post for the CSV feed.
 *
 * @return WP_Post|null
 */
function tse_sp_get_calendar_csv_post() {
	$post = get_post();

	if ( $post instanceof WP_Post && 'sp_calendar' === $post->post_type ) {
		return $post;
	}

	$queried_object = get_queried_object();

	if ( $queried_object instanceof WP_Post && 'sp_calendar' === $queried_object->post_type ) {
		return $queried_object;
	}

	$calendar_id = get_queried_object_id();
	if ( $calendar_id && 'sp_calendar' === get_post_type( $calendar_id ) ) {
		return get_post( $calendar_id );
	}

	return null;
}

/**
 * Return the home and away teams for an event in stored order.
 *
 * @param int $event_id SportsPress event ID.
 * @return array
 */
function tse_sp_get_event_home_away_teams( $event_id ) {
	$teams = array_values( array_filter( array_map( 'absint', get_post_meta( $event_id, 'sp_team', false ) ) ) );

	return array(
		'home' => isset( $teams[0] ) ? get_the_title( $teams[0] ) : '',
		'away' => isset( $teams[1] ) ? get_the_title( $teams[1] ) : '',
	);
}

/**
 * Return the field name(s) for an event.
 *
 * @param int $event_id SportsPress event ID.
 * @return string
 */
function tse_sp_get_event_field_name( $event_id ) {
	$venues = get_the_terms( $event_id, 'sp_venue' );

	if ( empty( $venues ) || is_wp_error( $venues ) ) {
		return '';
	}

	return implode( ', ', wp_list_pluck( $venues, 'name' ) );
}

/**
 * Render the SportsPress calendar CSV feed.
 *
 * @return void
 */
function tse_sp_render_calendar_csv_feed() {
	if ( ! class_exists( 'SP_Calendar' ) ) {
		wp_die( esc_html__( 'ERROR: SportsPress is required for this feed.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	$calendar = tse_sp_get_calendar_csv_post();

	if ( ! $calendar ) {
		wp_die( esc_html__( 'ERROR: This is not a valid calendar feed.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 404 ) );
	}

	$team_id = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;

	$calendar_data = new SP_Calendar( $calendar );
	if ( $team_id ) {
		$calendar_data->team = $team_id;
	}

	$events = (array) $calendar_data->data();

	$filename = sanitize_title( $calendar->post_name ? $calendar->post_name : $calendar->post_title );
	if ( '' === $filename ) {
		$filename = 'schedule';
	}
	if ( $team_id ) {
		$filename .= '-team-' . $team_id;
	}

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: inline; filename=' . $filename . '.csv' );

	$output = fopen( 'php://output', 'w' );

	if ( false === $output ) {
		wp_die( esc_html__( 'ERROR: Unable to generate the CSV feed.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	// Excel expects a BOM for UTF-8 CSV files.
	fwrite( $output, "\xEF\xBB\xBF" );

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
		$teams = tse_sp_get_event_home_away_teams( $event->ID );

		fputcsv(
			$output,
			array(
				sp_get_date( $event ),
				sp_get_time( $event ),
				$teams['away'],
				$teams['home'],
				tse_sp_get_event_field_name( $event->ID ),
			)
		);
	}

	fclose( $output );
	exit;
}

/**
 * CSV headers recognized by this importer.
 *
 * Empty field key means "accepted but ignored".
 *
 * @return array
 */
function tse_sp_event_csv_recognized_headers() {
	return array(
		'Date'                => 'date',
		'Time'                => 'time',
		'Venue'               => 'venue',
		'Home'                => 'home',
		'Away'                => 'away',
		'Week / Round'        => 'week_round',
		'Division'            => '',
		'Label'               => '',
		'League'              => 'league',
		'Season'              => 'season',
		'Home Score'          => 'home_score',
		'Away Score'          => 'away_score',
		'Referee'             => '',
		'Assistant Referees'  => '',
		'Notes'               => 'notes',
	);
}

/**
 * Capability used for importer access.
 *
 * @return string
 */
function tse_sp_event_csv_importer_capability() {
	return current_user_can( 'manage_sportspress' ) ? 'manage_sportspress' : 'manage_options';
}

/**
 * Get available SportsPress event formats.
 *
 * @return array
 */
function tse_sp_event_csv_event_formats() {
	$formats = array();

	if ( function_exists( 'SP' ) && is_object( SP() ) && isset( SP()->formats->event ) && is_array( SP()->formats->event ) ) {
		$formats = SP()->formats->event;
	}

	if ( empty( $formats ) ) {
		$formats = array(
			'league'   => __( 'Competitive', 'sportspress' ),
			'friendly' => __( 'Friendly', 'sportspress' ),
		);
	}

	return $formats;
}

/**
 * Validate incoming event format against available SportsPress formats.
 *
 * @param string $event_format Proposed format key.
 * @return string
 */
function tse_sp_event_csv_validate_event_format( $event_format ) {
	$event_format = sanitize_key( (string) $event_format );
	$formats      = tse_sp_event_csv_event_formats();

	if ( isset( $formats[ $event_format ] ) ) {
		return $event_format;
	}

	return 'league';
}

/**
 * Normalize an outcome token for case-insensitive matching.
 *
 * @param string $value Outcome value.
 * @return string
 */
function tse_sp_event_csv_normalize_outcome_token( $value ) {
	$value = wp_strip_all_tags( (string) $value );
	$value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) );
	$value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
	return $value;
}

/**
 * Build and cache SportsPress outcome maps.
 *
 * @param bool $refresh Force cache rebuild.
 * @return array
 */
function tse_sp_event_csv_outcome_catalog( $refresh = false ) {
	static $catalog = null;

	if ( ! $refresh && null !== $catalog ) {
		return $catalog;
	}

	$catalog = array(
		'slugs'         => array(),
		'titles'        => array(),
		'compact_slugs' => array(),
	);

	$outcome_posts = get_posts(
		array(
			'post_type'      => 'sp_outcome',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	foreach ( $outcome_posts as $outcome_post ) {
		if ( empty( $outcome_post->post_name ) ) {
			continue;
		}

		$slug  = (string) $outcome_post->post_name;
		$title = (string) $outcome_post->post_title;

		$catalog['slugs'][ $slug ] = $slug;
		$catalog['titles'][ tse_sp_event_csv_normalize_outcome_token( $title ) ] = $slug;
		$catalog['compact_slugs'][ preg_replace( '/[^a-z0-9]/', '', strtolower( $slug ) ) ] = $slug;
	}

	return $catalog;
}

/**
 * Resolve a user-entered outcome token to an outcome slug.
 *
 * Creates outcome posts when a custom value is supplied and no match exists.
 *
 * @param string $value Outcome input value.
 * @return string
 */
function tse_sp_event_csv_resolve_outcome_slug( $value ) {
	$value = sanitize_text_field( wp_strip_all_tags( (string) $value ) );
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	$catalog      = tse_sp_event_csv_outcome_catalog();
	$raw_slug     = sanitize_key( $value );
	$slug_guess   = sanitize_title( $value );
	$title_key    = tse_sp_event_csv_normalize_outcome_token( $value );
	$compact_key  = preg_replace( '/[^a-z0-9]/', '', strtolower( $value ) );

	if ( '' !== $raw_slug && isset( $catalog['slugs'][ $raw_slug ] ) ) {
		return $catalog['slugs'][ $raw_slug ];
	}
	if ( '' !== $slug_guess && isset( $catalog['slugs'][ $slug_guess ] ) ) {
		return $catalog['slugs'][ $slug_guess ];
	}
	if ( '' !== $title_key && isset( $catalog['titles'][ $title_key ] ) ) {
		return $catalog['titles'][ $title_key ];
	}
	if ( '' !== $compact_key && isset( $catalog['compact_slugs'][ $compact_key ] ) ) {
		return $catalog['compact_slugs'][ $compact_key ];
	}

	$title = preg_match( '/\s/', $value ) ? $value : ucwords( str_replace( array( '-', '_' ), ' ', $value ) );
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'sp_outcome',
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $title ),
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return '';
	}

	update_post_meta( $post_id, '_sp_import', 1 );

	$outcome = get_post( $post_id );
	if ( ! $outcome || empty( $outcome->post_name ) ) {
		return '';
	}

	tse_sp_event_csv_outcome_catalog( true );

	return (string) $outcome->post_name;
}

/**
 * Parse an outcome input value into an array of outcome slugs.
 *
 * @param string $value Outcome input value.
 * @return array
 */
function tse_sp_event_csv_parse_outcome_value( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return array();
	}

	$tokens   = preg_split( '/[\|,]/', $value );
	$outcomes = array();

	foreach ( $tokens as $token ) {
		$slug = tse_sp_event_csv_resolve_outcome_slug( $token );
		if ( '' !== $slug ) {
			$outcomes[ $slug ] = $slug;
		}
	}

	return array_values( $outcomes );
}

/**
 * Build default home/away outcomes based on score.
 *
 * @param string $home_score Home score.
 * @param string $away_score Away score.
 * @return array
 */
function tse_sp_event_csv_default_outcomes( $home_score, $away_score ) {
	if ( ! is_numeric( $home_score ) || ! is_numeric( $away_score ) ) {
		return array(
			'home' => '',
			'away' => '',
		);
	}

	$home_score = (float) $home_score;
	$away_score = (float) $away_score;

	if ( $home_score > $away_score ) {
		return array(
			'home' => 'win',
			'away' => 'loss',
		);
	}

	if ( $away_score > $home_score ) {
		return array(
			'home' => 'loss',
			'away' => 'win',
		);
	}

	return array(
		'home' => 'tie',
		'away' => 'tie',
	);
}

/**
 * Get suggested outcome slugs for preview inputs.
 *
 * @return array
 */
function tse_sp_event_csv_outcome_suggestions() {
	$suggestions = array(
		'technicalforfeitwin',
		'technicalforfeitloss',
		'win',
		'loss',
		'tie',
		'forfeitwin',
		'forfeitloss',
	);

	$catalog = tse_sp_event_csv_outcome_catalog();
	foreach ( array_keys( $catalog['slugs'] ) as $slug ) {
		$suggestions[] = $slug;
	}

	$suggestions = array_values( array_unique( array_filter( $suggestions ) ) );
	sort( $suggestions );

	return $suggestions;
}

/**
 * Register importer in WordPress Tools > Import screen.
 */
function tse_sp_event_csv_register_importer() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! function_exists( 'register_importer' ) ) {
		require_once ABSPATH . 'wp-admin/includes/import.php';
	}

	if ( ! function_exists( 'register_importer' ) ) {
		return;
	}

	register_importer(
		'tse_sp_event_csv',
		__( 'SportsPress Events CSV', 'tonys-sportspress-enhancements' ),
		__( 'Import SportsPress events directly from CSV with preview and duplicate detection. Provided by Tony\'s SportsPress Enhancements.', 'tonys-sportspress-enhancements' ),
		'tse_sp_event_csv_importer_bootstrap'
	);
}
add_action( 'admin_init', 'tse_sp_event_csv_register_importer' );

/**
 * Importer callback wrapper for WordPress importer screen.
 */
function tse_sp_event_csv_importer_bootstrap() {
	if ( ! current_user_can( 'import' ) ) {
		wp_die( esc_html__( 'You do not have permission to import.', 'tonys-sportspress-enhancements' ) );
	}

	tse_sp_event_csv_importer_page();
}

/**
 * Get transient key for current user's preview payload.
 *
 * @return string
 */
function tse_sp_event_csv_preview_key() {
	return 'tse_sp_event_csv_preview_' . get_current_user_id();
}

/**
 * Normalize header labels (trim/BOM cleanup).
 *
 * @param string $header Header label.
 * @return string
 */
function tse_sp_event_csv_normalize_header( $header ) {
	$header = trim( (string) $header );
	return (string) preg_replace( '/^\xEF\xBB\xBF/', '', $header );
}

/**
 * Build CSV column index map from incoming header.
 *
 * @param array $header Normalized CSV header row.
 * @return array|WP_Error
 */
function tse_sp_event_csv_build_header_map( $header ) {
	$recognized = tse_sp_event_csv_recognized_headers();
	$map        = array();

	foreach ( $header as $index => $column ) {
		$field = isset( $recognized[ $column ] ) ? $recognized[ $column ] : null;
		if ( null === $field || '' === $field ) {
			continue;
		}

		if ( ! isset( $map[ $field ] ) ) {
			$map[ $field ] = (int) $index;
		}
	}

	$required = array(
		'date'       => 'Date',
		'home'       => 'Home',
		'away'       => 'Away',
		'home_score' => 'Home Score',
		'away_score' => 'Away Score',
	);
	$missing  = array();
	foreach ( $required as $required_field => $required_label ) {
		if ( ! isset( $map[ $required_field ] ) ) {
			$missing[] = $required_label;
		}
	}

	if ( ! empty( $missing ) ) {
		return new WP_Error(
			'invalid_header',
			sprintf(
				/* translators: %s: missing required CSV columns. */
				__( 'CSV header is missing required columns: %s', 'tonys-sportspress-enhancements' ),
				implode( ', ', $missing )
			)
		);
	}

	return $map;
}

/**
 * Get a normalized CSV cell by logical field.
 *
 * @param array  $values CSV row values.
 * @param array  $header_map Field->index map.
 * @param string $field Logical field key.
 * @return string
 */
function tse_sp_event_csv_row_value( $values, $header_map, $field ) {
	if ( ! isset( $header_map[ $field ] ) ) {
		return '';
	}

	$index = (int) $header_map[ $field ];
	if ( ! isset( $values[ $index ] ) ) {
		return '';
	}

	return trim( (string) $values[ $index ] );
}

/**
 * Normalize team lookup tokens.
 *
 * @param string $value Raw team text.
 * @return string
 */
function tse_sp_event_csv_normalize_team_token( $value ) {
	$value = wp_strip_all_tags( (string) $value );
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	$value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) );
	$value = preg_replace( '/\s+/', ' ', $value );
	$value = strtolower( $value );

	return trim( (string) $value );
}

/**
 * Build a normalized lookup map for existing SportsPress teams.
 *
 * @param bool $refresh Force cache rebuild.
 * @return array
 */
function tse_sp_event_csv_team_lookup_map( $refresh = false ) {
	static $map = null;

	if ( ! $refresh && null !== $map ) {
		return $map;
	}

	$map      = array();
	$team_ids = get_posts(
		array(
			'post_type'      => 'sp_team',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $team_ids as $team_id ) {
		$tokens = array(
			get_the_title( $team_id ),
			get_post_meta( $team_id, 'sp_short_name', true ),
			get_post_meta( $team_id, 'sp_abbreviation', true ),
		);

		foreach ( $tokens as $token ) {
			$key = tse_sp_event_csv_normalize_team_token( $token );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = (int) $team_id;
			}
		}
	}

	return $map;
}

/**
 * Find team post ID by exact title.
 *
 * @param string $team_name Team name.
 * @return int
 */
function tse_sp_event_csv_find_team_id( $team_name ) {
	$key = tse_sp_event_csv_normalize_team_token( $team_name );
	if ( '' === $key ) {
		return 0;
	}

	$map = tse_sp_event_csv_team_lookup_map();
	if ( isset( $map[ $key ] ) ) {
		return (int) $map[ $key ];
	}

	return 0;
}

/**
 * Find existing team or create a new one.
 *
 * @param string $team_name Team name.
 * @return int|WP_Error
 */
function tse_sp_event_csv_find_or_create_team( $team_name ) {
	$team_name = trim( (string) $team_name );
	if ( '' === $team_name ) {
		return new WP_Error( 'team_name_missing', __( 'Team name is required.', 'tonys-sportspress-enhancements' ) );
	}

	$team_id = tse_sp_event_csv_find_team_id( $team_name );
	if ( $team_id > 0 ) {
		return $team_id;
	}

	$team_id = wp_insert_post(
		array(
			'post_type'   => 'sp_team',
			'post_status' => 'publish',
			'post_title'  => wp_strip_all_tags( $team_name ),
		),
		true
	);

	if ( is_wp_error( $team_id ) ) {
		return $team_id;
	}

	update_post_meta( $team_id, '_sp_import', 1 );

	// Refresh static lookup cache for subsequent rows in the same request.
	tse_sp_event_csv_team_lookup_map( true );

	return (int) $team_id;
}

/**
 * Parse date/time into local site datetime string.
 *
 * @param string $date_raw Date text.
 * @param string $time_raw Time text.
 * @return string|WP_Error
 */
function tse_sp_event_csv_parse_datetime( $date_raw, $time_raw ) {
	$date_raw = trim( (string) $date_raw );
	$time_raw = trim( (string) $time_raw );

	if ( '' === $date_raw ) {
		return new WP_Error( 'missing_date', __( 'Date is required.', 'tonys-sportspress-enhancements' ) );
	}

	$input = $date_raw . ' ' . ( '' !== $time_raw ? $time_raw : '00:00' );
	$dt    = date_create_immutable( $input, wp_timezone() );
	if ( false === $dt ) {
		return new WP_Error( 'invalid_datetime', __( 'Date/Time could not be parsed.', 'tonys-sportspress-enhancements' ) );
	}

	return $dt->format( 'Y-m-d H:i:s' );
}

/**
 * Build a deterministic event key from post_date and two team IDs.
 *
 * @param string $post_date Event post_date.
 * @param int    $team_a Team ID A.
 * @param int    $team_b Team ID B.
 * @return string
 */
function tse_sp_event_csv_build_event_key( $post_date, $team_a, $team_b ) {
	$post_date = trim( (string) $post_date );
	$teams     = array_values( array_filter( array_map( 'intval', array( $team_a, $team_b ) ) ) );

	if ( '' === $post_date || count( $teams ) < 2 ) {
		return '';
	}

	sort( $teams );
	return $post_date . '|' . $teams[0] . '|' . $teams[1];
}

/**
 * Build a row signature for in-file duplicate checks.
 *
 * @param string $post_date Event post_date.
 * @param string $home Home team text.
 * @param string $away Away team text.
 * @return string
 */
function tse_sp_event_csv_build_row_signature( $post_date, $home, $away ) {
	$post_date = trim( (string) $post_date );
	if ( '' === $post_date ) {
		return '';
	}

	$teams = array(
		tse_sp_event_csv_normalize_team_token( $home ),
		tse_sp_event_csv_normalize_team_token( $away ),
	);
	$teams = array_values( array_filter( $teams ) );
	if ( count( $teams ) < 2 ) {
		return '';
	}

	sort( $teams );
	return $post_date . '|name:' . $teams[0] . '|name:' . $teams[1];
}

/**
 * Build existing SportsPress event key map for duplicate detection.
 *
 * @return array
 */
function tse_sp_event_csv_existing_event_keys() {
	static $keys = null;

	if ( null !== $keys ) {
		return $keys;
	}

	$keys      = array();
	$event_ids = get_posts(
		array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $event_ids as $event_id ) {
		$post_date = (string) get_post_field( 'post_date', $event_id );
		$teams     = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) );
		if ( count( $teams ) < 2 ) {
			continue;
		}

		$key = tse_sp_event_csv_build_event_key( $post_date, $teams[0], $teams[1] );
		if ( '' !== $key ) {
			$keys[ $key ] = (int) $event_id;
		}
	}

	return $keys;
}

/**
 * Get the primary result key from SportsPress result variables.
 *
 * @return string
 */
function tse_sp_event_csv_primary_result_key() {
	if ( function_exists( 'sp_get_var_labels' ) ) {
		$labels = sp_get_var_labels( 'sp_result' );
		if ( is_array( $labels ) && ! empty( $labels ) ) {
			return (string) array_key_first( $labels );
		}
	}

	return 'result';
}

/**
 * Parse uploaded CSV into normalized preview payload.
 *
 * @param string $file_path Temp uploaded file path.
 * @return array|WP_Error
 */
function tse_sp_event_csv_parse_file( $file_path ) {
	$handle = fopen( $file_path, 'r' );
	if ( false === $handle ) {
		return new WP_Error( 'file_open_failed', __( 'Unable to read uploaded CSV file.', 'tonys-sportspress-enhancements' ) );
	}

	$header = fgetcsv( $handle, 0, ',', '"', '\\' );
	if ( false === $header ) {
		fclose( $handle );
		return new WP_Error( 'missing_header', __( 'CSV appears empty.', 'tonys-sportspress-enhancements' ) );
	}

	$header     = array_map( 'tse_sp_event_csv_normalize_header', $header );
	$header_map = tse_sp_event_csv_build_header_map( $header );
	if ( is_wp_error( $header_map ) ) {
		fclose( $handle );
		return $header_map;
	}

	$rows                = array();
	$total_rows          = 0;
	$rows_with_errors    = 0;
	$rows_with_new_teams = 0;
	$rows_with_duplicates = 0;
	$new_teams           = array();
	$line_number         = 1;
	$seen_row_signatures = array();
	$existing_event_keys = tse_sp_event_csv_existing_event_keys();

	while ( ( $values = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
		++$line_number;

		if ( ! is_array( $values ) ) {
			continue;
		}

		if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) {
			continue;
		}

		$normalized = array(
			'line'        => $line_number,
			'date'        => tse_sp_event_csv_row_value( $values, $header_map, 'date' ),
			'time'        => tse_sp_event_csv_row_value( $values, $header_map, 'time' ),
			'venue'       => tse_sp_event_csv_row_value( $values, $header_map, 'venue' ),
			'home'        => tse_sp_event_csv_row_value( $values, $header_map, 'home' ),
			'away'        => tse_sp_event_csv_row_value( $values, $header_map, 'away' ),
			'week_round'  => tse_sp_event_csv_row_value( $values, $header_map, 'week_round' ),
			'league'      => tse_sp_event_csv_row_value( $values, $header_map, 'league' ),
			'season'      => tse_sp_event_csv_row_value( $values, $header_map, 'season' ),
			'home_score'  => tse_sp_event_csv_row_value( $values, $header_map, 'home_score' ),
			'away_score'  => tse_sp_event_csv_row_value( $values, $header_map, 'away_score' ),
			'notes'       => tse_sp_event_csv_row_value( $values, $header_map, 'notes' ),
			'errors'      => array(),
			'duplicate_existing' => false,
			'duplicate_file'     => false,
			'home_team_id'=> 0,
			'away_team_id'=> 0,
		);

		if ( '' === $normalized['home'] ) {
			$normalized['errors'][] = __( 'Home team is required.', 'tonys-sportspress-enhancements' );
		}
		if ( '' === $normalized['away'] ) {
			$normalized['errors'][] = __( 'Away team is required.', 'tonys-sportspress-enhancements' );
		}

		$parsed_datetime = tse_sp_event_csv_parse_datetime( $normalized['date'], $normalized['time'] );
		if ( is_wp_error( $parsed_datetime ) ) {
			$normalized['errors'][] = $parsed_datetime->get_error_message();
		} else {
			$normalized['post_date'] = $parsed_datetime;
		}

		if ( '' !== $normalized['home'] ) {
			$normalized['home_team_id'] = tse_sp_event_csv_find_team_id( $normalized['home'] );
		}
		if ( '' !== $normalized['away'] ) {
			$normalized['away_team_id'] = tse_sp_event_csv_find_team_id( $normalized['away'] );
		}

		$row_signature = tse_sp_event_csv_build_row_signature(
			isset( $normalized['post_date'] ) ? $normalized['post_date'] : '',
			$normalized['home'],
			$normalized['away']
		);
		if ( '' !== $row_signature ) {
			if ( isset( $seen_row_signatures[ $row_signature ] ) ) {
				$normalized['duplicate_file'] = true;
			} else {
				$seen_row_signatures[ $row_signature ] = $line_number;
			}
		}

		$event_key = tse_sp_event_csv_build_event_key(
			isset( $normalized['post_date'] ) ? $normalized['post_date'] : '',
			$normalized['home_team_id'],
			$normalized['away_team_id']
		);
		if ( '' !== $event_key && isset( $existing_event_keys[ $event_key ] ) ) {
			$normalized['duplicate_existing'] = true;
		}

		if ( $normalized['home_team_id'] <= 0 || $normalized['away_team_id'] <= 0 ) {
			++$rows_with_new_teams;
		}
		if ( $normalized['home_team_id'] <= 0 && '' !== $normalized['home'] ) {
			$new_teams[ tse_sp_event_csv_normalize_team_token( $normalized['home'] ) ] = $normalized['home'];
		}
		if ( $normalized['away_team_id'] <= 0 && '' !== $normalized['away'] ) {
			$new_teams[ tse_sp_event_csv_normalize_team_token( $normalized['away'] ) ] = $normalized['away'];
		}

		if ( ! empty( $normalized['errors'] ) ) {
			++$rows_with_errors;
		}
		if ( $normalized['duplicate_existing'] || $normalized['duplicate_file'] ) {
			++$rows_with_duplicates;
		}

		$rows[] = $normalized;
		++$total_rows;
	}

	fclose( $handle );

	return array(
		'created_at'          => time(),
		'rows'                => $rows,
		'total_rows'          => $total_rows,
		'rows_with_errors'    => $rows_with_errors,
		'rows_with_new_teams' => $rows_with_new_teams,
		'rows_with_duplicates' => $rows_with_duplicates,
		'unique_new_teams'    => array_values( array_filter( $new_teams ) ),
		'result_key'          => tse_sp_event_csv_primary_result_key(),
	);
}

/**
 * Import parsed preview payload into SportsPress events.
 *
 * @param array $preview Preview payload.
 * @param array $options Import options.
 * @return array
 */
function tse_sp_event_csv_run_import( $preview, $options = array() ) {
	$results = array(
		'imported' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'excluded' => 0,
		'errors'   => array(),
	);

	$result_key = isset( $preview['result_key'] ) ? (string) $preview['result_key'] : tse_sp_event_csv_primary_result_key();
	$delimiter  = get_option( 'sportspress_event_teams_delimiter', 'vs' );
	$rows       = ( isset( $preview['rows'] ) && is_array( $preview['rows'] ) ) ? $preview['rows'] : array();

	$selection_mode = ! empty( $options['selection_mode'] );
	$include_lookup = ( isset( $options['include_lookup'] ) && is_array( $options['include_lookup'] ) ) ? $options['include_lookup'] : array();
	$duplicate_actions = ( isset( $options['duplicate_actions'] ) && is_array( $options['duplicate_actions'] ) ) ? $options['duplicate_actions'] : array();
	$home_outcome_inputs = ( isset( $options['home_outcomes'] ) && is_array( $options['home_outcomes'] ) ) ? $options['home_outcomes'] : array();
	$away_outcome_inputs = ( isset( $options['away_outcomes'] ) && is_array( $options['away_outcomes'] ) ) ? $options['away_outcomes'] : array();
	$event_format = isset( $options['event_format'] ) ? tse_sp_event_csv_validate_event_format( $options['event_format'] ) : 'league';
	$existing_event_keys = tse_sp_event_csv_existing_event_keys();

	foreach ( $rows as $row ) {
		$line = isset( $row['line'] ) ? (int) $row['line'] : 0;

		if ( $selection_mode && ! isset( $include_lookup[ $line ] ) ) {
			++$results['excluded'];
			continue;
		}

		if ( ! empty( $row['errors'] ) ) {
			++$results['skipped'];
			$results['errors'][] = sprintf(
				/* translators: %d: line number. */
				__( 'Line %d skipped (invalid preview row).', 'tonys-sportspress-enhancements' ),
				$line
			);
			continue;
		}

		$post_date = isset( $row['post_date'] ) ? $row['post_date'] : '';
		$home_id = tse_sp_event_csv_find_or_create_team( isset( $row['home'] ) ? $row['home'] : '' );
		$away_id = tse_sp_event_csv_find_or_create_team( isset( $row['away'] ) ? $row['away'] : '' );
		if ( is_wp_error( $home_id ) || is_wp_error( $away_id ) ) {
			++$results['skipped'];
			$results['errors'][] = sprintf(
				/* translators: 1: line number, 2: error details. */
				__( 'Line %1$d skipped: %2$s', 'tonys-sportspress-enhancements' ),
				$line,
				is_wp_error( $home_id ) ? $home_id->get_error_message() : $away_id->get_error_message()
			);
			continue;
		}

		if ( '' === $post_date ) {
			++$results['skipped'];
			$results['errors'][] = sprintf(
				/* translators: %d: line number. */
				__( 'Line %d skipped: missing parsed date.', 'tonys-sportspress-enhancements' ),
				$line
			);
			continue;
		}

		$event_key = tse_sp_event_csv_build_event_key( $post_date, (int) $home_id, (int) $away_id );
		$duplicate_action = isset( $duplicate_actions[ $line ] ) ? $duplicate_actions[ $line ] : 'ignore';
		if ( 'update' !== $duplicate_action ) {
			$duplicate_action = 'ignore';
		}

		$is_update = false;
		if ( '' !== $event_key && isset( $existing_event_keys[ $event_key ] ) ) {
			if ( 'update' !== $duplicate_action ) {
				++$results['skipped'];
				$results['errors'][] = sprintf(
					/* translators: %d: line number. */
					__( 'Line %d skipped: duplicate event already exists for same date/time and teams.', 'tonys-sportspress-enhancements' ),
					$line
				);
				continue;
			}

			$event_id = wp_update_post(
				array(
					'ID'           => (int) $existing_event_keys[ $event_key ],
					'post_date'    => $post_date,
					'post_title'   => trim( $row['home'] . ' ' . $delimiter . ' ' . $row['away'] ),
					'post_content' => isset( $row['notes'] ) ? wp_kses_post( $row['notes'] ) : '',
				),
				true
			);
			$is_update = true;
		} else {
			$event_id = wp_insert_post(
				array(
					'post_type'    => 'sp_event',
					'post_status'  => 'publish',
					'post_date'    => $post_date,
					'post_title'   => trim( $row['home'] . ' ' . $delimiter . ' ' . $row['away'] ),
					'post_content' => isset( $row['notes'] ) ? wp_kses_post( $row['notes'] ) : '',
				),
				true
			);
		}

		if ( is_wp_error( $event_id ) || ! $event_id ) {
			++$results['skipped'];
			$results['errors'][] = sprintf(
				/* translators: 1: line number, 2: error details. */
				__( 'Line %1$d failed: %2$s', 'tonys-sportspress-enhancements' ),
				$line,
				is_wp_error( $event_id ) ? $event_id->get_error_message() : __( 'Unknown save error', 'tonys-sportspress-enhancements' )
			);
			continue;
		}

		update_post_meta( $event_id, '_sp_import', 1 );
		update_post_meta( $event_id, 'sp_format', $event_format );

		delete_post_meta( $event_id, 'sp_team' );
		add_post_meta( $event_id, 'sp_team', (int) $home_id );
		add_post_meta( $event_id, 'sp_team', (int) $away_id );
		if ( ! $is_update ) {
			add_post_meta( $event_id, 'sp_player', 0 );
			add_post_meta( $event_id, 'sp_player', 0 );
		}

		if ( ! empty( $row['venue'] ) ) {
			wp_set_object_terms( $event_id, sanitize_text_field( $row['venue'] ), 'sp_venue', false );
		}
		if ( ! empty( $row['league'] ) ) {
			$league_term = sanitize_text_field( $row['league'] );
			wp_set_object_terms( $event_id, $league_term, 'sp_league', false );

			// Ensure teams are linked to every league they appear in.
			wp_set_object_terms( (int) $home_id, $league_term, 'sp_league', true );
			wp_set_object_terms( (int) $away_id, $league_term, 'sp_league', true );
		}
		if ( ! empty( $row['season'] ) ) {
			$season_term = sanitize_text_field( $row['season'] );
			wp_set_object_terms( $event_id, $season_term, 'sp_season', false );

			// Ensure teams are linked to every season they appear in.
			wp_set_object_terms( (int) $home_id, $season_term, 'sp_season', true );
			wp_set_object_terms( (int) $away_id, $season_term, 'sp_season', true );
		}

		$home_score = isset( $row['home_score'] ) ? trim( (string) $row['home_score'] ) : '';
		$away_score = isset( $row['away_score'] ) ? trim( (string) $row['away_score'] ) : '';
		$default_outcomes = tse_sp_event_csv_default_outcomes( $home_score, $away_score );

		$home_outcome_input = isset( $home_outcome_inputs[ $line ] ) ? (string) $home_outcome_inputs[ $line ] : '';
		$away_outcome_input = isset( $away_outcome_inputs[ $line ] ) ? (string) $away_outcome_inputs[ $line ] : '';

		if ( '' === trim( $home_outcome_input ) ) {
			$home_outcome_input = $default_outcomes['home'];
		}
		if ( '' === trim( $away_outcome_input ) ) {
			$away_outcome_input = $default_outcomes['away'];
		}

		$home_outcomes = tse_sp_event_csv_parse_outcome_value( $home_outcome_input );
		$away_outcomes = tse_sp_event_csv_parse_outcome_value( $away_outcome_input );

		if ( '' !== $home_score || '' !== $away_score || ! empty( $home_outcomes ) || ! empty( $away_outcomes ) ) {
			update_post_meta(
				$event_id,
				'sp_results',
				array(
					(int) $home_id => array(
						$result_key => $home_score,
						'outcome'   => $home_outcomes,
					),
					(int) $away_id => array(
						$result_key => $away_score,
						'outcome'   => $away_outcomes,
					),
				)
			);
		}

		if ( $is_update ) {
			++$results['updated'];
		} else {
			++$results['imported'];
		}

		if ( '' !== $event_key ) {
			$existing_event_keys[ $event_key ] = (int) $event_id;
		}
	}

	return $results;
}

/**
 * Render importer admin page and process actions.
 */
function tse_sp_event_csv_importer_page() {
	if ( ! current_user_can( tse_sp_event_csv_importer_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to access this importer.', 'tonys-sportspress-enhancements' ) );
	}

	$messages    = array();
	$error_lines = array();
	$key         = tse_sp_event_csv_preview_key();

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	if ( 'POST' === $request_method ) {
		$action = isset( $_POST['tse_action'] ) ? sanitize_key( wp_unslash( $_POST['tse_action'] ) ) : '';

		if ( 'preview' === $action ) {
			check_admin_referer( 'tse_sp_event_csv_preview' );

			if ( empty( $_FILES['tse_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['tse_csv_file']['tmp_name'] ) ) {
				$messages[] = array(
					'type' => 'error',
					'text' => __( 'Please choose a CSV file to preview.', 'tonys-sportspress-enhancements' ),
				);
			} else {
				$preview = tse_sp_event_csv_parse_file( $_FILES['tse_csv_file']['tmp_name'] );
				if ( is_wp_error( $preview ) ) {
					$messages[] = array(
						'type' => 'error',
						'text' => $preview->get_error_message(),
					);
				} else {
					set_transient( $key, $preview, 6 * HOUR_IN_SECONDS );
					$messages[] = array(
						'type' => 'success',
						'text' => __( 'Preview ready. Review rows below, then import or abort.', 'tonys-sportspress-enhancements' ),
					);
				}
			}
		} elseif ( 'abort' === $action ) {
			check_admin_referer( 'tse_sp_event_csv_abort' );
			delete_transient( $key );
			$messages[] = array(
				'type' => 'success',
				'text' => __( 'Preview aborted. Nothing was imported.', 'tonys-sportspress-enhancements' ),
			);
		} elseif ( 'import' === $action ) {
			check_admin_referer( 'tse_sp_event_csv_import' );
			$preview = get_transient( $key );
			if ( ! is_array( $preview ) || empty( $preview['rows'] ) ) {
				$messages[] = array(
					'type' => 'error',
					'text' => __( 'No preview data found. Upload the CSV again.', 'tonys-sportspress-enhancements' ),
				);
			} else {
				$selection_mode = isset( $_POST['tse_has_row_selection'] );
				$include_lookup = array();
				if ( $selection_mode && isset( $_POST['include_rows'] ) && is_array( $_POST['include_rows'] ) ) {
					foreach ( $_POST['include_rows'] as $line => $include_flag ) {
						$line = absint( $line );
						if ( $line > 0 && '1' === (string) $include_flag ) {
							$include_lookup[ $line ] = true;
						}
					}
				}

				$duplicate_actions = array();
				if ( isset( $_POST['duplicate_actions'] ) && is_array( $_POST['duplicate_actions'] ) ) {
					foreach ( $_POST['duplicate_actions'] as $line => $action_choice ) {
						$line = absint( $line );
						if ( $line < 1 ) {
							continue;
						}

						$action_choice = sanitize_key( wp_unslash( $action_choice ) );
						$duplicate_actions[ $line ] = in_array( $action_choice, array( 'ignore', 'update' ), true ) ? $action_choice : 'ignore';
					}
				}

				$event_format = isset( $_POST['event_format'] ) ? tse_sp_event_csv_validate_event_format( wp_unslash( $_POST['event_format'] ) ) : 'league';

				$results = tse_sp_event_csv_run_import(
					$preview,
					array(
						'selection_mode'    => $selection_mode,
						'include_lookup'    => $include_lookup,
						'duplicate_actions' => $duplicate_actions,
						'event_format'      => $event_format,
					)
				);
				delete_transient( $key );

				$messages[] = array(
					'type' => 'success',
					'text' => sprintf(
						/* translators: 1: imported count, 2: updated count, 3: skipped count, 4: excluded count. */
						__( 'Import complete. Imported: %1$d. Updated: %2$d. Skipped: %3$d. Excluded: %4$d.', 'tonys-sportspress-enhancements' ),
						(int) $results['imported'],
						(int) $results['updated'],
						(int) $results['skipped'],
						(int) $results['excluded']
					),
				);
				$error_lines = isset( $results['errors'] ) && is_array( $results['errors'] ) ? $results['errors'] : array();
			}
		}
	}

	$preview_data = get_transient( $key );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SportsPress Direct Event CSV Import', 'tonys-sportspress-enhancements' ); ?></h1>
		<p><?php esc_html_e( 'Upload your CSV, review the preview, then import directly into SportsPress.', 'tonys-sportspress-enhancements' ); ?></p>

		<?php foreach ( $messages as $message ) : ?>
			<div class="notice notice-<?php echo esc_attr( 'error' === $message['type'] ? 'error' : 'success' ); ?>">
				<p><?php echo esc_html( $message['text'] ); ?></p>
			</div>
		<?php endforeach; ?>

		<?php if ( ! empty( $error_lines ) ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'Import issues:', 'tonys-sportspress-enhancements' ); ?></p>
				<ul style="margin-left:1.2em;list-style:disc;">
					<?php foreach ( $error_lines as $line_error ) : ?>
						<li><?php echo esc_html( $line_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data" style="margin: 16px 0;">
			<?php wp_nonce_field( 'tse_sp_event_csv_preview' ); ?>
			<input type="hidden" name="tse_action" value="preview" />
			<input type="file" name="tse_csv_file" accept=".csv,text/csv" required />
			<?php submit_button( __( 'Build Preview', 'tonys-sportspress-enhancements' ), 'primary', 'submit', false ); ?>
		</form>

		<?php if ( is_array( $preview_data ) && ! empty( $preview_data['rows'] ) ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Preview', 'tonys-sportspress-enhancements' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: total rows, 2: rows with errors, 3: rows with unmatched teams, 4: duplicate rows. */
						__( 'Rows: %1$d | Rows with errors: %2$d | Rows with unmatched teams: %3$d | Rows flagged duplicate: %4$d', 'tonys-sportspress-enhancements' ),
						(int) $preview_data['total_rows'],
						(int) $preview_data['rows_with_errors'],
						(int) $preview_data['rows_with_new_teams'],
						(int) ( isset( $preview_data['rows_with_duplicates'] ) ? $preview_data['rows_with_duplicates'] : 0 )
					)
				);
				?>
			</p>
			<?php if ( ! empty( $preview_data['unique_new_teams'] ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: unique team count. */
							__( 'Distinct unmatched team names: %d', 'tonys-sportspress-enhancements' ),
							count( $preview_data['unique_new_teams'] )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" id="tse-preview-import-form">
				<?php wp_nonce_field( 'tse_sp_event_csv_import' ); ?>
				<input type="hidden" name="tse_action" value="import" />
				<input type="hidden" name="tse_has_row_selection" value="1" />
				<?php
				$event_formats        = tse_sp_event_csv_event_formats();
				$default_event_format = tse_sp_event_csv_validate_event_format( 'league' );
				?>
				<p>
					<label for="tse-event-format"><strong><?php esc_html_e( 'Game Type', 'tonys-sportspress-enhancements' ); ?></strong></label><br />
					<select id="tse-event-format" name="event_format">
						<?php foreach ( $event_formats as $format_key => $format_label ) : ?>
							<option value="<?php echo esc_attr( $format_key ); ?>" <?php selected( $default_event_format, $format_key ); ?>>
								<?php echo esc_html( $format_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Applies to all selected rows (Competitive = league, Friendly = exhibition).', 'tonys-sportspress-enhancements' ); ?></span>
				</p>
				<p class="description"><?php esc_html_e( 'Outcomes are read-only and auto-derived from score.', 'tonys-sportspress-enhancements' ); ?></p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="white-space:nowrap;">
								<?php esc_html_e( 'Include', 'tonys-sportspress-enhancements' ); ?><br />
								<button type="button" class="button-link" id="tse-select-all"><?php esc_html_e( 'All', 'tonys-sportspress-enhancements' ); ?></button>
								/
								<button type="button" class="button-link" id="tse-select-none"><?php esc_html_e( 'None', 'tonys-sportspress-enhancements' ); ?></button>
							</th>
							<th><?php esc_html_e( 'Line', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Date Time', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Venue', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Home', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Away', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'League', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Season', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Score', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Home Outcome', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Away Outcome', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tonys-sportspress-enhancements' ); ?></th>
							<th><?php esc_html_e( 'Duplicate Action', 'tonys-sportspress-enhancements' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $preview_data['rows'] as $row ) : ?>
							<?php
							$line          = isset( $row['line'] ) ? (int) $row['line'] : 0;
								$has_errors    = ! empty( $row['errors'] );
								$is_duplicate  = ! empty( $row['duplicate_existing'] ) || ! empty( $row['duplicate_file'] );
								$checkbox_name = 'include_rows[' . $line . ']';
								$action_name   = 'duplicate_actions[' . $line . ']';
								$default_outcomes = tse_sp_event_csv_default_outcomes(
									isset( $row['home_score'] ) ? $row['home_score'] : '',
									isset( $row['away_score'] ) ? $row['away_score'] : ''
								);
								?>
							<tr>
								<td>
									<input
										type="checkbox"
										class="tse-include-row"
										name="<?php echo esc_attr( $checkbox_name ); ?>"
										value="1"
										<?php checked( ! $has_errors ); ?>
										<?php disabled( $has_errors ); ?>
									/>
								</td>
								<td><?php echo esc_html( (string) $line ); ?></td>
								<td><?php echo esc_html( isset( $row['post_date'] ) ? $row['post_date'] : 'Invalid' ); ?></td>
								<td><?php echo esc_html( $row['venue'] ); ?></td>
								<td>
									<?php echo esc_html( $row['home'] ); ?>
									<?php if ( empty( $row['home_team_id'] ) ) : ?>
										<em>(<?php esc_html_e( 'new team', 'tonys-sportspress-enhancements' ); ?>)</em>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $row['away'] ); ?>
									<?php if ( empty( $row['away_team_id'] ) ) : ?>
										<em>(<?php esc_html_e( 'new team', 'tonys-sportspress-enhancements' ); ?>)</em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( isset( $row['league'] ) ? $row['league'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $row['season'] ) ? $row['season'] : '' ); ?></td>
								<td><?php echo esc_html( $row['home_score'] . ' - ' . $row['away_score'] ); ?></td>
								<td><?php echo esc_html( $default_outcomes['home'] ); ?></td>
								<td><?php echo esc_html( $default_outcomes['away'] ); ?></td>
								<td>
									<?php
									if ( ! empty( $row['errors'] ) ) {
										echo esc_html( implode( '; ', $row['errors'] ) );
									} elseif ( ! empty( $row['duplicate_existing'] ) ) {
										esc_html_e( 'Duplicate: existing event', 'tonys-sportspress-enhancements' );
									} elseif ( ! empty( $row['duplicate_file'] ) ) {
										esc_html_e( 'Duplicate: repeated in CSV', 'tonys-sportspress-enhancements' );
									} else {
										esc_html_e( 'Ready', 'tonys-sportspress-enhancements' );
									}
									?>
								</td>
								<td>
									<?php if ( $is_duplicate && ! $has_errors ) : ?>
										<select name="<?php echo esc_attr( $action_name ); ?>">
											<option value="ignore"><?php esc_html_e( 'Ignore', 'tonys-sportspress-enhancements' ); ?></option>
											<option value="update"><?php esc_html_e( 'Update', 'tonys-sportspress-enhancements' ); ?></option>
										</select>
									<?php else : ?>
										<input type="hidden" name="<?php echo esc_attr( $action_name ); ?>" value="ignore" />
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div style="margin-top: 16px;">
					<?php submit_button( __( 'Import Selected Rows', 'tonys-sportspress-enhancements' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'tse_sp_event_csv_abort' ); ?>
				<input type="hidden" name="tse_action" value="abort" />
				<?php submit_button( __( 'Abort Preview', 'tonys-sportspress-enhancements' ), 'secondary', 'submit', false ); ?>
			</form>

			<script>
			(function() {
				const selectAll = document.getElementById('tse-select-all');
				const selectNone = document.getElementById('tse-select-none');

				function rowCheckboxes() {
					return document.querySelectorAll('.tse-include-row:not(:disabled)');
				}

				if (selectAll) {
					selectAll.addEventListener('click', function() {
						rowCheckboxes().forEach(function(cb) {
							cb.checked = true;
						});
					});
				}

				if (selectNone) {
					selectNone.addEventListener('click', function() {
						rowCheckboxes().forEach(function(cb) {
							cb.checked = false;
						});
					});
				}
			})();
			</script>
		<?php endif; ?>
	</div>
	<?php
}
