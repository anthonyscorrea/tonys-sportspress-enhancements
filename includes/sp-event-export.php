<?php
/**
 * Standalone SportsPress schedule feeds.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the standalone SportsPress CSV feed endpoint.
 *
 * @return void
 */
function tse_sp_event_export_register_feed() {
	add_feed( 'sp-csv', 'tse_sp_event_export_render_feed' );
	add_feed( 'sp-ics', 'tse_sp_event_export_render_ical_feed' );
}
add_action( 'init', 'tse_sp_event_export_register_feed', 20 );

/**
 * Get available export formats.
 *
 * @return array
 */
function tse_sp_event_export_get_formats() {
	return array(
		'matchup' => array(
			'label'       => __( 'Matchup', 'tonys-sportspress-enhancements' ),
			'description' => __( 'Date, time, away team, home team, and field columns.', 'tonys-sportspress-enhancements' ),
		),
		'team'    => array(
			'label'       => __( 'Team', 'tonys-sportspress-enhancements' ),
			'description' => __( 'Team-centric schedule rows with opponent and home/away columns.', 'tonys-sportspress-enhancements' ),
		),
	);
}

/**
 * Get available export columns grouped by format.
 *
 * @return array
 */
function tse_sp_event_export_get_column_definitions() {
	return array(
		'matchup' => array(
			'date'                => __( 'Date', 'tonys-sportspress-enhancements' ),
			'time'                => __( 'Time', 'tonys-sportspress-enhancements' ),
			'season'              => __( 'Season', 'tonys-sportspress-enhancements' ),
			'league'              => __( 'League', 'tonys-sportspress-enhancements' ),
			'away_team'           => __( 'Away Team', 'tonys-sportspress-enhancements' ),
			'home_team'           => __( 'Home Team', 'tonys-sportspress-enhancements' ),
			'field_name'          => __( 'Field Name', 'tonys-sportspress-enhancements' ),
			'field_address'       => __( 'Field Address', 'tonys-sportspress-enhancements' ),
			'officials'           => __( 'Officials', 'tonys-sportspress-enhancements' ),
		),
		'team'    => array(
			'label'               => __( 'Extra Label', 'tonys-sportspress-enhancements' ),
			'date'                => __( 'Date', 'tonys-sportspress-enhancements' ),
			'time'                => __( 'Time', 'tonys-sportspress-enhancements' ),
			'season'              => __( 'Season', 'tonys-sportspress-enhancements' ),
			'league'              => __( 'League', 'tonys-sportspress-enhancements' ),
			'team_name'           => __( 'Team', 'tonys-sportspress-enhancements' ),
			'opponent_name'       => __( 'Opponent', 'tonys-sportspress-enhancements' ),
			'location_flag'       => __( 'Home/Away', 'tonys-sportspress-enhancements' ),
			'field_name'          => __( 'Field Name', 'tonys-sportspress-enhancements' ),
			'field_address'       => __( 'Field Address', 'tonys-sportspress-enhancements' ),
			'field_abbreviation'  => __( 'Field Abbreviation', 'tonys-sportspress-enhancements' ),
			'field_short_name'    => __( 'Field Short Name', 'tonys-sportspress-enhancements' ),
			'officials'           => __( 'Officials', 'tonys-sportspress-enhancements' ),
			'home_team'           => __( 'Home Team', 'tonys-sportspress-enhancements' ),
			'away_team'           => __( 'Away Team', 'tonys-sportspress-enhancements' ),
		),
	);
}

/**
 * Get default columns for an export format.
 *
 * @param string $format Export format.
 * @return array
 */
function tse_sp_event_export_get_default_columns( $format ) {
	$defaults = array(
		'matchup' => array( 'date', 'time', 'season', 'league', 'away_team', 'home_team', 'field_name' ),
		'team'    => array( 'label', 'date', 'time', 'season', 'league', 'opponent_name', 'location_flag', 'field_name' ),
	);

	return isset( $defaults[ $format ] ) ? $defaults[ $format ] : $defaults['matchup'];
}

/**
 * Sanitize an export format.
 *
 * @param string $format Raw format.
 * @return string
 */
function tse_sp_event_export_sanitize_format( $format ) {
	$format  = sanitize_key( (string) $format );
	$formats = tse_sp_event_export_get_formats();

	return isset( $formats[ $format ] ) ? $format : 'matchup';
}

/**
 * Sanitize requested columns for an export format.
 *
 * @param string       $format  Export format.
 * @param string|array $columns Requested columns.
 * @return array
 */
function tse_sp_event_export_sanitize_columns( $format, $columns ) {
	$definitions = tse_sp_event_export_get_column_definitions();
	$available   = isset( $definitions[ $format ] ) ? $definitions[ $format ] : array();

	if ( ! is_array( $columns ) ) {
		$columns = explode( ',', (string) $columns );
	}

	$sanitized = array();

	foreach ( $columns as $column ) {
		$key = sanitize_key( (string) $column );
		if ( '' === $key || ! isset( $available[ $key ] ) || in_array( $key, $sanitized, true ) ) {
			continue;
		}

		$sanitized[] = $key;
	}

	if ( empty( $sanitized ) ) {
		return tse_sp_event_export_get_default_columns( $format );
	}

	return $sanitized;
}

/**
 * Normalize one or more numeric IDs from a request value.
 *
 * Accepts scalars, arrays, and comma-delimited strings.
 *
 * @param mixed $value Raw request value.
 * @return int[]
 */
function tse_sp_event_export_parse_id_list( $value ) {
	if ( is_array( $value ) ) {
		$raw_values = $value;
	} else {
		$raw_values = explode( ',', (string) $value );
	}

	$ids = array();

	foreach ( $raw_values as $raw_value ) {
		$id = absint( trim( (string) $raw_value ) );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}

	$ids = array_values( array_unique( $ids ) );

	return $ids;
}

/**
 * Normalize export request arguments.
 *
 * @param array|null $source Optional input source.
 * @return array
 */
function tse_sp_event_export_normalize_request_args( $source = null ) {
	$source = is_array( $source ) ? $source : $_GET;
	$format = isset( $source['format'] ) ? tse_sp_event_export_sanitize_format( wp_unslash( $source['format'] ) ) : 'matchup';
	$team_ids = isset( $source['team_id'] ) ? tse_sp_event_export_parse_id_list( wp_unslash( $source['team_id'] ) ) : array();
	$season_ids = isset( $source['season_id'] ) ? tse_sp_event_export_parse_id_list( wp_unslash( $source['season_id'] ) ) : array();
	$league_ids = isset( $source['league_id'] ) ? tse_sp_event_export_parse_id_list( wp_unslash( $source['league_id'] ) ) : array();
	$field_ids = isset( $source['field_id'] ) ? tse_sp_event_export_parse_id_list( wp_unslash( $source['field_id'] ) ) : array();

	return array(
		'team_id'   => isset( $team_ids[0] ) ? $team_ids[0] : 0,
		'team_ids'  => $team_ids,
		'season_id' => isset( $season_ids[0] ) ? $season_ids[0] : 0,
		'season_ids'=> $season_ids,
		'league_id' => isset( $league_ids[0] ) ? $league_ids[0] : 0,
		'league_ids'=> $league_ids,
		'field_id'  => isset( $field_ids[0] ) ? $field_ids[0] : 0,
		'field_ids' => $field_ids,
		'format'    => $format,
		'columns'   => isset( $source['columns'] ) ? tse_sp_event_export_sanitize_columns( $format, wp_unslash( $source['columns'] ) ) : tse_sp_event_export_get_default_columns( $format ),
	);
}

/**
 * Validate export filters for the requested format.
 *
 * @param array $filters Export filters.
 * @return void
 */
function tse_sp_event_export_validate_filters( $filters ) {
	$format   = tse_sp_event_export_sanitize_format( isset( $filters['format'] ) ? $filters['format'] : 'matchup' );
	$team_ids = isset( $filters['team_ids'] ) && is_array( $filters['team_ids'] ) ? $filters['team_ids'] : array();

	if ( 'team' !== $format ) {
		return;
	}

	if ( empty( $team_ids ) ) {
		wp_die( esc_html__( 'Team format requires a team filter.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 400 ) );
	}

	if ( count( $team_ids ) > 1 ) {
		wp_die( esc_html__( 'Team format does not support multiple teams.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 400 ) );
	}
}

/**
 * Query matching event posts for export.
 *
 * @param array $filters Export filters.
 * @return WP_Post[]
 */
function tse_sp_event_export_query_posts( $filters ) {
	$team_ids   = isset( $filters['team_ids'] ) && is_array( $filters['team_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['team_ids'] ) ) ) : array();
	$season_ids = isset( $filters['season_ids'] ) && is_array( $filters['season_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['season_ids'] ) ) ) : array();
	$league_ids = isset( $filters['league_ids'] ) && is_array( $filters['league_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['league_ids'] ) ) ) : array();
	$field_ids  = isset( $filters['field_ids'] ) && is_array( $filters['field_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['field_ids'] ) ) ) : array();

	$args = array(
		'post_type'      => 'sp_event',
		'post_status'    => array( 'publish', 'future' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);

	if ( ! empty( $team_ids ) ) {
		$args['meta_query'] = array(
			array(
				'key'     => 'sp_team',
				'value'   => array_map( 'strval', $team_ids ),
				'compare' => 'IN',
			),
		);
	}

	$tax_query = array();

	if ( ! empty( $season_ids ) ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_season',
			'field'    => 'term_id',
			'terms'    => $season_ids,
		);
	}

	if ( ! empty( $league_ids ) ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_league',
			'field'    => 'term_id',
			'terms'    => $league_ids,
		);
	}

	if ( ! empty( $field_ids ) ) {
		$tax_query[] = array(
			'taxonomy' => 'sp_venue',
			'field'    => 'term_id',
			'terms'    => $field_ids,
		);
	}

	if ( ! empty( $tax_query ) ) {
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$args['tax_query'] = $tax_query;
	}

	$query = new WP_Query( $args );

	return is_array( $query->posts ) ? $query->posts : array();
}

/**
 * Query matching schedule events for export.
 *
 * @param array $filters Export filters.
 * @return array
 */
function tse_sp_event_export_get_events( $filters ) {
	$team_id     = isset( $filters['team_id'] ) ? absint( $filters['team_id'] ) : 0;
	$selected_ids = isset( $filters['team_ids'] ) && is_array( $filters['team_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['team_ids'] ) ) ) : array();
	$query_posts = tse_sp_event_export_query_posts( $filters );
	$events     = array();
	$team_name = $team_id > 0 ? get_the_title( $team_id ) : '';

	foreach ( $query_posts as $event ) {
		$event_id = $event instanceof WP_Post ? (int) $event->ID : 0;
		if ( $event_id <= 0 ) {
			continue;
		}

		$teams = array_values( array_unique( array_map( 'intval', get_post_meta( $event_id, 'sp_team', false ) ) ) );
		if ( ! empty( $selected_ids ) && empty( array_intersect( $selected_ids, $teams ) ) ) {
			continue;
		}

		$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
		$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;
		$venue   = tse_sp_event_export_get_primary_field( $event_id );

		if ( $team_id > 0 ) {
			$location_flag = $home_id === $team_id ? 'Home' : 'Away';
			$opponent_id   = $home_id === $team_id ? $away_id : $home_id;
		} else {
			$location_flag = '';
			$opponent_id   = 0;
		}

		$events[] = array(
			'event_id'            => $event_id,
			'label'               => '',
			'date'                => get_post_time( 'm/d/Y', false, $event_id, true ),
			'time'                => strtoupper( (string) ( function_exists( 'sp_get_time' ) ? sp_get_time( $event_id ) : get_post_time( get_option( 'time_format' ), false, $event_id, true ) ) ),
			'team_name'           => is_string( $team_name ) ? $team_name : '',
			'opponent_name'       => $opponent_id > 0 ? get_the_title( $opponent_id ) : '',
			'location_flag'       => $location_flag,
			'home_team'           => $home_id > 0 ? get_the_title( $home_id ) : '',
			'away_team'           => $away_id > 0 ? get_the_title( $away_id ) : '',
			'field_name'          => isset( $venue['name'] ) ? $venue['name'] : '',
			'field_address'       => isset( $venue['address'] ) ? $venue['address'] : '',
			'field_abbreviation'  => isset( $venue['abbreviation'] ) ? $venue['abbreviation'] : '',
			'field_short_name'    => isset( $venue['short_name'] ) ? $venue['short_name'] : '',
			'season'              => tse_sp_event_export_get_event_term_names( $event_id, 'sp_season' ),
			'league'              => tse_sp_event_export_get_event_term_names( $event_id, 'sp_league' ),
			'officials'           => tse_sp_event_export_get_officials_value( $event_id ),
		);
	}

	foreach ( $events as $index => $event ) {
		$events[ $index ]['label'] = sprintf( 'G#%02d', $index + 1 );
	}

	wp_reset_postdata();

	return $events;
}

/**
 * Get event term names as a semicolon-delimited string.
 *
 * @param int    $event_id  Event ID.
 * @param string $taxonomy  Taxonomy name.
 * @return string
 */
function tse_sp_event_export_get_event_term_names( $event_id, $taxonomy ) {
	$terms = get_the_terms( $event_id, $taxonomy );

	if ( ! is_array( $terms ) || empty( $terms ) ) {
		return '';
	}

	$names = array_values( array_filter( array_map( 'strval', wp_list_pluck( $terms, 'name' ) ) ) );

	return implode( '; ', array_unique( $names ) );
}

/**
 * Get primary field metadata for an event.
 *
 * @param int $event_id Event ID.
 * @return array
 */
function tse_sp_event_export_get_primary_field( $event_id ) {
	$venues = get_the_terms( $event_id, 'sp_venue' );

	if ( ! is_array( $venues ) || ! isset( $venues[0] ) || ! $venues[0] instanceof WP_Term ) {
		return array(
			'name'         => '',
			'address'      => '',
			'abbreviation' => '',
			'short_name'   => '',
		);
	}

	$venue = $venues[0];
	$meta  = get_option( 'taxonomy_' . $venue->term_id );

	return array(
		'name'         => isset( $venue->name ) ? (string) $venue->name : '',
		'address'      => is_array( $meta ) && isset( $meta['sp_address'] ) ? trim( (string) $meta['sp_address'] ) : '',
		'abbreviation' => trim( (string) get_term_meta( $venue->term_id, 'tse_abbreviation', true ) ),
		'short_name'   => trim( (string) get_term_meta( $venue->term_id, 'tse_short_name', true ) ),
	);
}

/**
 * Get event officials as a semicolon-delimited string.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function tse_sp_event_export_get_officials_value( $event_id ) {
	$official_groups = get_post_meta( $event_id, 'sp_officials', true );

	if ( ! is_array( $official_groups ) || empty( $official_groups ) ) {
		return '';
	}

	$official_names = array();

	foreach ( $official_groups as $official_ids ) {
		if ( ! is_array( $official_ids ) ) {
			continue;
		}

		foreach ( $official_ids as $official_id ) {
			$official_id = absint( $official_id );
			if ( $official_id <= 0 || 'sp_official' !== get_post_type( $official_id ) ) {
				continue;
			}

			$name = get_the_title( $official_id );
			if ( '' === $name ) {
				continue;
			}

			$official_names[ $official_id ] = $name;
		}
	}

	if ( empty( $official_names ) ) {
		return '';
	}

	return implode( '; ', array_values( $official_names ) );
}

/**
 * Escape iCalendar text content.
 *
 * @param string $value Raw value.
 * @return string
 */
function tse_sp_event_export_escape_ical_text( $value ) {
	$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	$value = str_replace( array( '\\', "\r\n", "\r", "\n", ',', ';' ), array( '\\\\', '\n', '\n', '\n', '\,', '\;' ), $value );

	return $value;
}

/**
 * Fold an iCalendar content line.
 *
 * @param string $line Raw line.
 * @return string
 */
function tse_sp_event_export_fold_ical_line( $line ) {
	return wordwrap( (string) $line, 60, "\r\n\t", true );
}

/**
 * Get the ICS summary for an event.
 *
 * For matchup format, this mirrors the SportsPress result/title behavior.
 * For team format, this becomes a team-centric opponent summary.
 *
 * @param WP_Post $event   Event post.
 * @param array   $filters Export filters.
 * @return string
 */
function tse_sp_event_export_get_ical_summary( $event, $filters ) {
	$format   = tse_sp_event_export_sanitize_format( isset( $filters['format'] ) ? $filters['format'] : 'matchup' );
	$team_ids = isset( $filters['team_ids'] ) && is_array( $filters['team_ids'] ) ? array_values( array_filter( array_map( 'absint', $filters['team_ids'] ) ) ) : array();
	$team_id  = isset( $team_ids[0] ) ? $team_ids[0] : 0;

	if ( 'team' === $format && $team_id > 0 ) {
		$teams   = array_values( array_unique( array_map( 'intval', get_post_meta( $event->ID, 'sp_team', false ) ) ) );
		$home_id = isset( $teams[0] ) ? (int) $teams[0] : 0;
		$away_id = isset( $teams[1] ) ? (int) $teams[1] : 0;

		if ( in_array( $team_id, $teams, true ) ) {
			$is_home     = $home_id === $team_id;
			$opponent_id = $is_home ? $away_id : $home_id;
			$opponent    = $opponent_id > 0 ? get_the_title( $opponent_id ) : __( 'TBD', 'tonys-sportspress-enhancements' );
			$summary     = sprintf(
				/* translators: 1: preposition, 2: opponent name. */
				__( '%1$s %2$s', 'tonys-sportspress-enhancements' ),
				$is_home ? 'vs' : 'at',
				$opponent
			);

			return apply_filters( 'sportspress_ical_feed_summary', $summary, $event );
		}
	}

	$main_result = get_option( 'sportspress_primary_result', null );
	$results     = array();
	$teams       = (array) get_post_meta( $event->ID, 'sp_team', false );
	$teams       = array_filter( array_unique( $teams ) );

	if ( ! empty( $teams ) ) {
		$event_results = get_post_meta( $event->ID, 'sp_results', true );

		foreach ( $teams as $team_id ) {
			$team_id = absint( $team_id );
			if ( $team_id <= 0 ) {
				continue;
			}

			$team = get_post( $team_id );
			if ( ! $team instanceof WP_Post ) {
				continue;
			}

			$team_results = is_array( $event_results ) && isset( $event_results[ $team_id ] ) ? $event_results[ $team_id ] : null;

			if ( $main_result ) {
				$team_result = is_array( $team_results ) && isset( $team_results[ $main_result ] ) ? $team_results[ $main_result ] : null;
			} else {
				if ( is_array( $team_results ) ) {
					end( $team_results );
					$team_result = prev( $team_results );
				} else {
					$team_result = null;
				}
			}

			if ( null !== $team_result && '' !== (string) $team_result ) {
				$results[] = get_the_title( $team_id ) . ' ' . $team_result;
			}
		}
	}

	$summary = ! empty( $results ) ? implode( ' ', $results ) : $event->post_title;

	$summary = preg_replace_callback(
		'/(&#[0-9]+;)/',
		static function( $matches ) {
			return mb_convert_encoding( $matches[1], 'UTF-8', 'HTML-ENTITIES' );
		},
		$summary
	);

	return apply_filters( 'sportspress_ical_feed_summary', $summary, $event );
}

/**
 * Get the ICS location payload for an event.
 *
 * @param WP_Post $event Event post.
 * @return array
 */
function tse_sp_event_export_get_ical_location_data( $event ) {
	$location = '';
	$geo      = false;
	$venues   = get_the_terms( $event->ID, 'sp_venue' );

	if ( ! is_array( $venues ) || empty( $venues ) ) {
		return array(
			'location' => $location,
			'geo'      => $geo,
		);
	}

	$venue    = reset( $venues );
	$location = $venue->name;
	$meta     = get_option( 'taxonomy_' . $venue->term_id );
	$address  = is_array( $meta ) && isset( $meta['sp_address'] ) ? $meta['sp_address'] : false;

	if ( false !== $address && '' !== (string) $address ) {
		$location = $venue->name . ', ' . $address;
	}

	$latitude  = is_array( $meta ) && isset( $meta['sp_latitude'] ) ? $meta['sp_latitude'] : false;
	$longitude = is_array( $meta ) && isset( $meta['sp_longitude'] ) ? $meta['sp_longitude'] : false;

	if ( false !== $latitude && false !== $longitude && '' !== (string) $latitude && '' !== (string) $longitude ) {
		$geo = $latitude . ';' . $longitude;
	}

	return array(
		'location' => (string) $location,
		'geo'      => $geo,
	);
}

/**
 * Build iCalendar output for the requested filters.
 *
 * @param array $filters Export filters.
 * @return string
 */
function tse_sp_event_export_build_ical_output( $filters ) {
	$query_posts = tse_sp_event_export_query_posts( $filters );
	$locale      = substr( get_locale(), 0, 2 );
	$timezone    = sanitize_option( 'timezone_string', get_option( 'timezone_string' ) );
	$url         = tse_sp_event_export_get_feed_url( $filters, 'ics' );
	$calendar    = tse_sp_event_export_get_feed_title( $filters );
	$output      =
"BEGIN:VCALENDAR\r\n" .
"VERSION:2.0\r\n" .
'PRODID:-//ThemeBoy//SportsPress//' . strtoupper( $locale ) . "\r\n" .
"CALSCALE:GREGORIAN\r\n" .
"METHOD:PUBLISH\r\n" .
'URL:' . tse_sp_event_export_fold_ical_line( $url ) . "\r\n" .
'X-FROM-URL:' . tse_sp_event_export_fold_ical_line( $url ) . "\r\n" .
'NAME:' . tse_sp_event_export_escape_ical_text( $calendar ) . "\r\n" .
'X-WR-CALNAME:' . tse_sp_event_export_escape_ical_text( $calendar ) . "\r\n" .
'DESCRIPTION:' . tse_sp_event_export_escape_ical_text( $calendar ) . "\r\n" .
'X-WR-CALDESC:' . tse_sp_event_export_escape_ical_text( $calendar ) . "\r\n" .
"REFRESH-INTERVAL;VALUE=DURATION:PT2M\r\n" .
"X-PUBLISHED-TTL:PT2M\r\n" .
'TZID:' . $timezone . "\r\n" .
'X-WR-TIMEZONE:' . $timezone . "\r\n";

	foreach ( $query_posts as $event ) {
		if ( ! $event instanceof WP_Post ) {
			continue;
		}

		$date_format = 'Ymd\THis';
		$description = tse_sp_event_export_escape_ical_text( $event->post_content );
		$summary     = tse_sp_event_export_get_ical_summary( $event, $filters );
		$minutes     = get_post_meta( $event->ID, 'sp_minutes', true );
		$minutes     = '' === $minutes ? get_option( 'sportspress_event_minutes', 90 ) : $minutes;
		$end         = new DateTime( $event->post_date );
		$end->add( new DateInterval( 'PT' . absint( $minutes ) . 'M' ) );
		$location    = tse_sp_event_export_get_ical_location_data( $event );
		$event_url   = get_permalink( $event );

		$output .= "BEGIN:VEVENT\r\n";
		$output .= tse_sp_event_export_fold_ical_line( 'SUMMARY:' . tse_sp_event_export_escape_ical_text( $summary ) ) . "\r\n";
		$output .= 'UID:' . $event->ID . "\r\n";
		$output .= "STATUS:CONFIRMED\r\n";
		$output .= "DTSTAMP:19700101T000000\r\n";
		$output .= 'DTSTART:' . mysql2date( $date_format, $event->post_date ) . "\r\n";
		$output .= 'DTEND:' . $end->format( $date_format ) . "\r\n";
		$output .= 'LAST-MODIFIED:' . mysql2date( $date_format, $event->post_modified_gmt ) . "\r\n";

		if ( '' !== $description ) {
			$output .= tse_sp_event_export_fold_ical_line( 'DESCRIPTION:' . $description ) . "\r\n";
		}

		if ( '' !== $location['location'] ) {
			$output .= tse_sp_event_export_fold_ical_line( 'LOCATION:' . tse_sp_event_export_escape_ical_text( $location['location'] ) ) . "\r\n";
		}

		if ( ! empty( $location['geo'] ) ) {
			$output .= 'GEO:' . $location['geo'] . "\r\n";
		}

		if ( is_string( $event_url ) && '' !== $event_url ) {
			$output .= tse_sp_event_export_fold_ical_line( 'URL:' . esc_url_raw( $event_url ) ) . "\r\n";
		}

		$output .= "END:VEVENT\r\n";
	}

	$output .= 'END:VCALENDAR';

	return $output;
}

/**
 * Stream ICS output.
 *
 * @param array $filters Export filters.
 * @param array $args    Optional render args.
 * @return void
 */
function tse_sp_event_export_stream_ical( $filters, $args = array() ) {
	tse_sp_event_export_validate_filters( $filters );

	$disposition = isset( $args['disposition'] ) ? sanitize_key( $args['disposition'] ) : 'inline';
	$disposition = in_array( $disposition, array( 'inline', 'attachment' ), true ) ? $disposition : 'inline';
	$output      = tse_sp_event_export_build_ical_output( $filters );
	$filename    = tse_sp_event_export_build_filename( $filters ) . '.ics';
	$etag        = md5( $output );

	header( 'Content-type: text/calendar; charset=utf-8' );
	header( 'Etag:' . '"' . $etag . '"' );
	header( 'Content-Disposition: ' . $disposition . '; filename=' . $filename );

	echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * Build a CSV row value for a logical column.
 *
 * @param array  $event  Normalized event row.
 * @param string $column Column key.
 * @return string
 */
function tse_sp_event_export_get_row_value( $event, $column ) {
	return isset( $event[ $column ] ) ? (string) $event[ $column ] : '';
}

/**
 * Build a CSV filename for the export.
 *
 * @param array $filters Export filters.
 * @return string
 */
function tse_sp_event_export_build_filename( $filters ) {
	$parts = array( 'schedule' );

	$parts = array_merge( $parts, tse_sp_event_export_get_post_slugs( isset( $filters['team_ids'] ) ? $filters['team_ids'] : array() ) );
	$parts = array_merge( $parts, tse_sp_event_export_get_term_slugs_by_ids( isset( $filters['season_ids'] ) ? $filters['season_ids'] : array(), 'sp_season' ) );
	$parts = array_merge( $parts, tse_sp_event_export_get_term_slugs_by_ids( isset( $filters['league_ids'] ) ? $filters['league_ids'] : array(), 'sp_league' ) );
	$parts = array_merge( $parts, tse_sp_event_export_get_term_slugs_by_ids( isset( $filters['field_ids'] ) ? $filters['field_ids'] : array(), 'sp_venue' ) );

	$parts[] = tse_sp_event_export_sanitize_format( isset( $filters['format'] ) ? $filters['format'] : 'matchup' );
	$parts   = array_values( array_filter( $parts ) );

	return implode( '-', $parts );
}

/**
 * Build a human-readable feed title from filters.
 *
 * @param array $filters Export filters.
 * @return string
 */
function tse_sp_event_export_get_feed_title( $filters ) {
	$site_title  = trim( (string) get_bloginfo( 'name' ) );
	$team_name   = tse_sp_event_export_get_post_titles( isset( $filters['team_ids'] ) ? $filters['team_ids'] : array() );
	$season_name = tse_sp_event_export_get_term_names_by_ids( isset( $filters['season_ids'] ) ? $filters['season_ids'] : array(), 'sp_season' );
	$league_name = tse_sp_event_export_get_term_names_by_ids( isset( $filters['league_ids'] ) ? $filters['league_ids'] : array(), 'sp_league' );
	$field_name  = tse_sp_event_export_get_term_names_by_ids( isset( $filters['field_ids'] ) ? $filters['field_ids'] : array(), 'sp_venue' );

	$title   = '' !== $site_title ? $site_title . ' ' . __( 'Event Feed', 'tonys-sportspress-enhancements' ) : __( 'Event Feed', 'tonys-sportspress-enhancements' );
	$details = array_filter( array( $team_name, $season_name, $league_name, $field_name ) );

	if ( ! empty( $details ) ) {
		$title .= ' (' . implode( ' • ', $details ) . ')';
	}

	return $title;
}

/**
 * Get term names for a list of term IDs.
 *
 * @param array  $ids      Term IDs.
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function tse_sp_event_export_get_term_names_by_ids( $ids, $taxonomy ) {
	$names = array();

	foreach ( (array) $ids as $id ) {
		$term = get_term( absint( $id ), $taxonomy );
		if ( $term instanceof WP_Term ) {
			$names[] = $term->name;
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );

	return implode( '; ', $names );
}

/**
 * Get post titles for a list of post IDs.
 *
 * @param array $ids Post IDs.
 * @return string
 */
function tse_sp_event_export_get_post_titles( $ids ) {
	$titles = array();

	foreach ( (array) $ids as $id ) {
		$post = get_post( absint( $id ) );
		if ( $post instanceof WP_Post ) {
			$titles[] = $post->post_title;
		}
	}

	$titles = array_values( array_unique( array_filter( $titles ) ) );

	return implode( '; ', $titles );
}

/**
 * Get term slugs for a list of term IDs.
 *
 * @param array  $ids      Term IDs.
 * @param string $taxonomy Taxonomy name.
 * @return string[]
 */
function tse_sp_event_export_get_term_slugs_by_ids( $ids, $taxonomy ) {
	$slugs = array();

	foreach ( (array) $ids as $id ) {
		$term = get_term( absint( $id ), $taxonomy );
		if ( $term instanceof WP_Term && ! empty( $term->slug ) ) {
			$slugs[] = sanitize_title( $term->slug );
		}
	}

	return array_values( array_unique( array_filter( $slugs ) ) );
}

/**
 * Get post slugs for a list of post IDs.
 *
 * @param array $ids Post IDs.
 * @return string[]
 */
function tse_sp_event_export_get_post_slugs( $ids ) {
	$slugs = array();

	foreach ( (array) $ids as $id ) {
		$post = get_post( absint( $id ) );
		if ( $post instanceof WP_Post ) {
			$slugs[] = sanitize_title( $post->post_name ? $post->post_name : $post->post_title );
		}
	}

	return array_values( array_unique( array_filter( $slugs ) ) );
}

/**
 * Stream CSV output for the requested export.
 *
 * @param array $filters Export filters.
 * @param array $args    Optional render args.
 * @return void
 */
function tse_sp_event_export_stream_csv( $filters, $args = array() ) {
	$filters['format']  = tse_sp_event_export_sanitize_format( isset( $filters['format'] ) ? $filters['format'] : 'matchup' );
	$filters['columns'] = tse_sp_event_export_sanitize_columns( $filters['format'], isset( $filters['columns'] ) ? $filters['columns'] : array() );
	tse_sp_event_export_validate_filters( $filters );

	$definitions = tse_sp_event_export_get_column_definitions();
	$headers     = array();

	foreach ( $filters['columns'] as $column ) {
		$headers[] = $definitions[ $filters['format'] ][ $column ];
	}

	$events       = tse_sp_event_export_get_events( $filters );
	$disposition  = isset( $args['disposition'] ) ? sanitize_key( $args['disposition'] ) : 'inline';
	$disposition  = in_array( $disposition, array( 'inline', 'attachment' ), true ) ? $disposition : 'inline';
	$filename     = tse_sp_event_export_build_filename( $filters ) . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: ' . $disposition . '; filename=' . $filename );

	$output = fopen( 'php://output', 'w' );

	if ( false === $output ) {
		wp_die( esc_html__( 'Unable to generate the CSV export.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	fwrite( $output, "\xEF\xBB\xBF" );
	fputcsv( $output, $headers );

	foreach ( $events as $event ) {
		$row = array();

		foreach ( $filters['columns'] as $column ) {
			$row[] = tse_sp_event_export_get_row_value( $event, $column );
		}

		fputcsv( $output, $row );
	}

	fclose( $output );
	exit;
}

/**
 * Render the standalone CSV feed.
 *
 * @return void
 */
function tse_sp_event_export_render_feed() {
	if ( ! class_exists( 'SP_Calendar' ) && ! post_type_exists( 'sp_event' ) ) {
		wp_die( esc_html__( 'SportsPress is required for this feed.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	tse_sp_event_export_stream_csv( tse_sp_event_export_normalize_request_args() );
}

/**
 * Render the standalone ICS feed.
 *
 * @return void
 */
function tse_sp_event_export_render_ical_feed() {
	if ( ! class_exists( 'SP_Calendar' ) && ! post_type_exists( 'sp_event' ) ) {
		wp_die( esc_html__( 'SportsPress is required for this feed.', 'tonys-sportspress-enhancements' ), '', array( 'response' => 500 ) );
	}

	tse_sp_event_export_stream_ical( tse_sp_event_export_normalize_request_args() );
}

/**
 * Build a shareable feed URL.
 *
 * @param array  $args      Export args.
 * @param string $feed_type Feed type.
 * @return string
 */
function tse_sp_event_export_get_feed_url( $args = array(), $feed_type = 'csv' ) {
	$filters = tse_sp_event_export_normalize_request_args( $args );
	$feed    = 'ics' === sanitize_key( $feed_type ) ? 'sp-ics' : 'sp-csv';
	$query   = array(
		'feed'      => $feed,
		'format'    => $filters['format'],
		'team_id'   => ! empty( $filters['team_ids'] ) ? implode( ',', $filters['team_ids'] ) : '',
		'season_id' => ! empty( $filters['season_ids'] ) ? implode( ',', $filters['season_ids'] ) : '',
		'league_id' => ! empty( $filters['league_ids'] ) ? implode( ',', $filters['league_ids'] ) : '',
		'field_id'  => ! empty( $filters['field_ids'] ) ? implode( ',', $filters['field_ids'] ) : '',
		'columns'   => implode( ',', $filters['columns'] ),
	);

	return add_query_arg( array_filter( $query, 'strlen' ), home_url( '/' ) );
}
