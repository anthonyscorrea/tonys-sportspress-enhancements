<?php
/**
 * REST API fields for SportsPress posts.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register custom REST fields for SportsPress team posts.
 *
 * @return void
 */
function tony_sportspress_register_team_rest_fields() {
	if ( ! post_type_exists( 'sp_team' ) ) {
		return;
	}

	register_rest_field(
		'sp_team',
		'short_name',
		array(
			'get_callback' => 'tony_sportspress_get_team_short_name_rest_field',
			'schema'       => array(
				'description' => __( 'Team short name.', 'tonys-sportspress-enhancements' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit', 'embed' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'tony_sportspress_register_team_rest_fields' );

/**
 * Register custom REST fields for SportsPress event posts.
 *
 * @return void
 */
function tony_sportspress_register_event_rest_fields() {
	if ( ! post_type_exists( 'sp_event' ) ) {
		return;
	}

	register_rest_field(
		'sp_event',
		'event_status',
		array(
			'get_callback' => 'tony_sportspress_get_event_status_rest_field',
			'schema'       => array(
				'description' => __( 'SportsPress event time status.', 'tonys-sportspress-enhancements' ),
				'type'        => 'string',
				'enum'        => array( 'on-time', 'tbd', 'postponed', 'cancelled' ),
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		)
	);

	register_rest_field(
		'sp_event',
		'series_number',
		array(
			'get_callback' => 'tony_sportspress_get_event_series_number_rest_field',
			'schema'       => array(
				'description' => __( 'One-based series number for the event matchup when exactly one season is attached.', 'tonys-sportspress-enhancements' ),
				'type'        => array( 'integer', 'null' ),
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		)
	);

	register_rest_field(
		'sp_event',
		'series_numbers',
		array(
			'get_callback' => 'tony_sportspress_get_event_series_numbers_rest_field',
			'schema'       => array(
				'description'          => __( 'One-based series numbers for the event matchup, keyed by season term ID.', 'tonys-sportspress-enhancements' ),
				'type'                 => 'object',
				'context'              => array( 'view', 'edit', 'embed' ),
				'readonly'             => true,
				'additionalProperties' => array(
					'type' => 'integer',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'tony_sportspress_register_event_rest_fields' );

/**
 * Register custom REST fields for SportsPress venue terms.
 *
 * @return void
 */
function tony_sportspress_register_venue_rest_fields() {
	if ( ! taxonomy_exists( 'sp_venue' ) ) {
		return;
	}

	register_rest_field(
		'sp_venue',
		'address',
		array(
			'get_callback' => 'tony_sportspress_get_venue_address_rest_field',
			'schema'       => array(
				'description' => __( 'SportsPress venue address.', 'tonys-sportspress-enhancements' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit', 'embed' ),
				'readonly'    => true,
			),
		)
	);
}
add_action( 'rest_api_init', 'tony_sportspress_register_venue_rest_fields' );

/**
 * Get the REST representation of a SportsPress team short name.
 *
 * @param array           $object REST object.
 * @param string          $field_name Field name.
 * @param WP_REST_Request $request Request object.
 * @return string
 */
function tony_sportspress_get_team_short_name_rest_field( $object, $field_name, $request ) {
	$team_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;

	if ( $team_id <= 0 ) {
		return '';
	}

	if ( function_exists( 'sp_team_short_name' ) ) {
		$short_name = trim( (string) sp_team_short_name( $team_id ) );
		if ( '' !== $short_name ) {
			return $short_name;
		}
	}

	$short_name = trim( (string) get_post_meta( $team_id, 'sp_short_name', true ) );
	if ( '' !== $short_name ) {
		return $short_name;
	}

	return (string) get_the_title( $team_id );
}

/**
 * Get the REST representation of a SportsPress event status.
 *
 * @param array           $object REST object.
 * @param string          $field_name Field name.
 * @param WP_REST_Request $request Request object.
 * @return string
 */
function tony_sportspress_get_event_status_rest_field( $object, $field_name, $request ) {
	$event_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;

	if ( $event_id <= 0 ) {
		return '';
	}

	$status = sanitize_key( (string) get_post_meta( $event_id, 'sp_status', true ) );

	if ( '' === $status || 'ok' === $status ) {
		return 'on-time';
	}

	return $status;
}

/**
 * Get the REST representation of a SportsPress event series number.
 *
 * @param array           $object REST object.
 * @param string          $field_name Field name.
 * @param WP_REST_Request $request Request object.
 * @return int|null
 */
function tony_sportspress_get_event_series_number_rest_field( $object, $field_name, $request ) {
	$series_numbers = tony_sportspress_get_event_series_numbers_rest_field( $object, $field_name, $request );

	if ( 1 !== count( $series_numbers ) ) {
		return null;
	}

	return (int) reset( $series_numbers );
}

/**
 * Get the REST representation of SportsPress event series numbers keyed by season.
 *
 * @param array           $object REST object.
 * @param string          $field_name Field name.
 * @param WP_REST_Request $request Request object.
 * @return array<string,int>
 */
function tony_sportspress_get_event_series_numbers_rest_field( $object, $field_name, $request ) {
	$event_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;

	if ( $event_id <= 0 ) {
		return array();
	}

	$team_ids = tony_sportspress_get_event_matchup_team_ids( $event_id );
	if ( 2 !== count( $team_ids ) ) {
		return array();
	}

	$season_ids = tony_sportspress_get_event_season_ids( $event_id );
	if ( empty( $season_ids ) ) {
		return array();
	}

	$series_numbers = array();

	foreach ( $season_ids as $season_id ) {
		$series_number = tony_sportspress_get_event_series_number_for_season( $event_id, $team_ids, $season_id );

		if ( null !== $series_number ) {
			$series_numbers[ (string) $season_id ] = $series_number;
		}
	}

	return $series_numbers;
}

/**
 * Get the normalized matchup team IDs for an event.
 *
 * @param int $event_id Event post ID.
 * @return int[]
 */
function tony_sportspress_get_event_matchup_team_ids( $event_id ) {
	$team_ids = array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( $event_id, 'sp_team', false ) ) ) ) );
	sort( $team_ids, SORT_NUMERIC );

	return $team_ids;
}

/**
 * Get season term IDs attached to an event.
 *
 * @param int $event_id Event post ID.
 * @return int[]
 */
function tony_sportspress_get_event_season_ids( $event_id ) {
	$terms = get_the_terms( $event_id, 'sp_season' );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return array();
	}

	$season_ids = array_values( array_unique( array_map( 'absint', wp_list_pluck( $terms, 'term_id' ) ) ) );
	sort( $season_ids, SORT_NUMERIC );

	return $season_ids;
}

/**
 * Get a series number for an event within a season.
 *
 * Current ordering intentionally uses event IDs because season schedules are bulk-imported
 * in original schedule order. The original schedule date is captured separately for future use.
 *
 * @param int   $event_id Event post ID.
 * @param int[] $team_ids Normalized matchup team IDs.
 * @param int   $season_id Season term ID.
 * @return int|null
 */
function tony_sportspress_get_event_series_number_for_season( $event_id, $team_ids, $season_id ) {
	$query = new WP_Query(
		array(
			'post_type'              => 'sp_event',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => array( $season_id ),
				),
			),
		)
	);

	$series_number = 0;

	foreach ( $query->posts as $candidate_id ) {
		if ( $team_ids !== tony_sportspress_get_event_matchup_team_ids( $candidate_id ) ) {
			continue;
		}

		++$series_number;

		if ( (int) $candidate_id === (int) $event_id ) {
			return $series_number;
		}
	}

	return null;
}

/**
 * Store the original scheduled datetime for future event calculations.
 *
 * @param int     $post_id Event post ID.
 * @param WP_Post $post Event post object.
 * @param bool    $update Whether this is an existing post update.
 * @return void
 */
function tony_sportspress_store_original_scheduled_datetime( $post_id, $post, $update ) {
	if ( ! $post instanceof WP_Post || 'sp_event' !== $post->post_type ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( 'auto-draft' === $post->post_status ) {
		return;
	}

	if ( '' !== (string) get_post_meta( $post_id, '_tse_original_scheduled_datetime', true ) ) {
		return;
	}

	$post_date = (string) get_post_field( 'post_date', $post_id );
	if ( '' === $post_date || '0000-00-00 00:00:00' === $post_date ) {
		return;
	}

	update_post_meta( $post_id, '_tse_original_scheduled_datetime', $post_date );
}
add_action( 'save_post_sp_event', 'tony_sportspress_store_original_scheduled_datetime', 10, 3 );

/**
 * Get the REST representation of a SportsPress venue address.
 *
 * @param array           $object REST object.
 * @param string          $field_name Field name.
 * @param WP_REST_Request $request Request object.
 * @return string
 */
function tony_sportspress_get_venue_address_rest_field( $object, $field_name, $request ) {
	$venue_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;

	if ( $venue_id <= 0 ) {
		return '';
	}

	$meta = get_option( 'taxonomy_' . $venue_id );

	if ( is_array( $meta ) && isset( $meta['sp_address'] ) ) {
		return trim( sanitize_text_field( (string) $meta['sp_address'] ) );
	}

	return trim( sanitize_text_field( (string) get_term_meta( $venue_id, 'sp_address', true ) ) );
}
