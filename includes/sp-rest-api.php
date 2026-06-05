<?php
/**
 * REST API fields for SportsPress team posts.
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
