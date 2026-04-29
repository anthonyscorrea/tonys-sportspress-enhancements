<?php
/**
 * Open Graph tags for SportsPress events.
 *
 * @package Tonys_Sportspress_Enhancements
 */

add_action( 'wp_head', 'custom_open_graph_tags_with_sportspress_integration' );

/**
 * Build the dynamic matchup image URL for an event.
 *
 * @param int|WP_Post $post Event post or ID.
 * @param string      $variant Image variant.
 * @return string
 */
function asc_sp_event_matchup_image_url( $post, $variant = 'wide' ) {
	$post = asc_sp_event_get_post( $post );

	if ( ! $post ) {
		return '';
	}

	$args = array(
		'post' => $post->ID,
	);

	if ( 'wide' !== $variant ) {
		$args['variant'] = $variant;
	}

	if ( function_exists( 'asc_sp_event_image_url_version' ) ) {
		$args['v'] = asc_sp_event_image_url_version();
	}

	return add_query_arg( $args, home_url( '/head-to-head' ) );
}

/**
 * Build Open Graph image descriptors for an event.
 *
 * @param WP_Post $post Event post.
 * @return array<int,array<string,string>>
 */
function asc_sp_event_open_graph_images( WP_Post $post ) {
	return array(
		array(
			'url'    => asc_sp_event_matchup_image_url( $post, 'wide' ),
			'width'  => '1200',
			'height' => '628',
		),
	);
}

/**
 * Normalize an event post argument.
 *
 * @param int|WP_Post|null $post Post object or ID.
 * @return WP_Post|null
 */
function asc_sp_event_get_post( $post = null ) {
	if ( null === $post ) {
		$post = get_post();
	} elseif ( is_numeric( $post ) ) {
		$post = get_post( absint( $post ) );
	}

	if ( ! $post instanceof WP_Post || 'sp_event' !== $post->post_type ) {
		return null;
	}

	return $post;
}

/**
 * Get event team IDs in SportsPress display order.
 *
 * @param int|WP_Post $post Event post or ID.
 * @return int[]
 */
function asc_sp_event_team_ids( $post ) {
	$post = asc_sp_event_get_post( $post );

	if ( ! $post ) {
		return array();
	}

	$teams    = get_post_meta( $post->ID, 'sp_team', false );
	$team_ids = array();

	foreach ( $teams as $team ) {
		while ( is_array( $team ) ) {
			$team = array_shift( array_filter( $team ) );
		}

		$team = absint( $team );
		if ( $team > 0 ) {
			$team_ids[] = $team;
		}
	}

	$team_ids = array_values( array_unique( $team_ids ) );

	if ( 'yes' === get_option( 'sportspress_event_reverse_teams', 'no' ) ) {
		$team_ids = array_reverse( $team_ids );
	}

	return $team_ids;
}

/**
 * Get a safe team short name with fallbacks for test and partial SportsPress environments.
 *
 * @param int $team_id Team post ID.
 * @return string
 */
function asc_sp_team_short_name( $team_id ) {
	$name = '';

	if ( function_exists( 'sp_team_short_name' ) ) {
		$name = (string) sp_team_short_name( $team_id );
	}

	if ( '' === trim( $name ) ) {
		$name = get_the_title( $team_id );
	}

	return '' !== trim( $name ) ? $name : __( 'Team TBD', 'tonys-sportspress-enhancements' );
}

/**
 * Generate a matchup title from event teams.
 *
 * @param int|WP_Post $post Event post or ID.
 * @return string
 */
function asc_generate_sp_event_title( $post ) {
	$post = asc_sp_event_get_post( $post );

	if ( ! $post ) {
		return get_the_title();
	}

	$team_names = array_map( 'asc_sp_team_short_name', asc_sp_event_team_ids( $post ) );
	$team_names = array_values( array_filter( array_unique( $team_names ) ) );

	if ( empty( $team_names ) ) {
		return get_the_title( $post );
	}

	$delimiter = ' ' . get_option( 'sportspress_event_teams_delimiter', 'vs' ) . ' ';

	return implode( $delimiter, $team_names );
}

/**
 * Generate compact event date text.
 *
 * @param int|WP_Post $post     Event post or ID.
 * @param bool        $withTime Include time.
 * @return string
 */
function asc_generate_short_date( $post, $withTime = true ) {
	$post = asc_sp_event_get_post( $post );

	if ( ! $post ) {
		return '';
	}

	$formatted_date = get_the_date( 'D n/j/y', $post );

	if ( ! $withTime ) {
		return $formatted_date;
	}

	$formatted_time = '00' === get_the_date( 'i', $post ) ? get_the_date( 'gA', $post ) : get_the_date( 'g:iA', $post );

	return trim( $formatted_date . ' ' . $formatted_time );
}

/**
 * Get venue name for an event.
 *
 * @param WP_Post $post Event post.
 * @return string
 */
function asc_sp_event_venue_name( WP_Post $post ) {
	$venue_terms = get_the_terms( $post->ID, 'sp_venue' );

	if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
		return __( 'Venue TBD', 'tonys-sportspress-enhancements' );
	}

	return $venue_terms[0]->name;
}

/**
 * Normalize event body content for meta descriptions.
 *
 * @param WP_Post $post Event post.
 * @return string
 */
function asc_sp_event_body_excerpt( WP_Post $post ) {
	$content = strip_shortcodes( $post->post_content );
	$content = wp_strip_all_tags( $content, true );
	$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
	$content = preg_replace( '/\s+/', ' ', $content );
	$content = trim( (string) $content );

	if ( '' === $content ) {
		return '';
	}

	return wp_trim_words( $content, 35, '' );
}

/**
 * Safely instantiate a SportsPress event object.
 *
 * @param WP_Post $post Event post.
 * @return object|null
 */
function asc_sp_event_object( WP_Post $post ) {
	if ( ! class_exists( 'SP_Event' ) ) {
		return null;
	}

	try {
		return new SP_Event( $post->ID );
	} catch ( Throwable $e ) {
		return null;
	}
}

/**
 * Get the SportsPress event status with fallbacks.
 *
 * @param WP_Post     $post  Event post.
 * @param object|null $event SportsPress event object.
 * @return string
 */
function asc_sp_event_status( WP_Post $post, $event = null ) {
	if ( $event && is_callable( array( $event, 'status' ) ) ) {
		try {
			$status = (string) $event->status();
			if ( '' !== $status ) {
				return $status;
			}
		} catch ( Throwable $e ) {
			return '';
		}
	}

	return 'future' === $post->post_status ? 'future' : '';
}

/**
 * Get SportsPress result rows safely.
 *
 * @param object|null $event SportsPress event object.
 * @return array
 */
function asc_sp_event_results( $event = null ) {
	if ( ! $event || ! is_callable( array( $event, 'results' ) ) ) {
		return array();
	}

	try {
		$results = $event->results();
		return is_array( $results ) ? $results : array();
	} catch ( Throwable $e ) {
		return array();
	}
}

/**
 * Convert a result row into outcome labels.
 *
 * @param array $result Result row.
 * @return array
 */
function asc_sp_event_result_outcomes( array $result ) {
	$result_outcome = isset( $result['outcome'] ) ? $result['outcome'] : null;

	if ( ! is_array( $result_outcome ) ) {
		return array();
	}

	$outcomes = array();

	foreach ( $result_outcome as $outcome ) {
		$the_outcome = get_page_by_path( $outcome, OBJECT, 'sp_outcome' );

		if ( $the_outcome instanceof WP_Post ) {
			$outcome_abbreviation = get_post_meta( $the_outcome->ID, 'sp_abbreviation', true );
			if ( ! $outcome_abbreviation ) {
				$outcome_abbreviation = function_exists( 'sp_substr' ) ? sp_substr( $the_outcome->post_title, 0, 1 ) : substr( $the_outcome->post_title, 0, 1 );
			}

			$outcomes[] = array(
				'title'        => $the_outcome->post_title,
				'abbreviation' => $outcome_abbreviation,
			);
		}
	}

	return $outcomes;
}

/**
 * Build a result title/description from SportsPress result data.
 *
 * @param WP_Post $post        Event post.
 * @param array   $results     Event results data.
 * @param string  $description Existing description.
 * @return array{title:string,description:string}|null
 */
function asc_sp_event_result_meta( WP_Post $post, array $results, $description ) {
	unset( $results[0] );

	$results = array_filter( $results );

	if ( count( $results ) < 2 ) {
		return null;
	}

	if ( 'yes' === get_option( 'sportspress_event_reverse_teams', 'no' ) ) {
		$results = array_reverse( $results, true );
	}

	$teams_result_array = array();

	foreach ( $results as $team_id => $result ) {
		if ( ! is_array( $result ) ) {
			continue;
		}

		$outcomes       = asc_sp_event_result_outcomes( $result );
		$first_outcome  = ! empty( $outcomes ) ? $outcomes[0] : array( 'title' => __( 'Result', 'tonys-sportspress-enhancements' ), 'abbreviation' => '' );
		$team_name      = asc_sp_team_short_name( $team_id );
		$team_score     = isset( $result['r'] ) && '' !== $result['r'] ? $result['r'] : null;
		$team_score     = null !== $team_score ? (string) $team_score : '';

		if ( '' === $team_score ) {
			continue;
		}

		$teams_result_array[] = array(
			'score'                => $team_score,
			'outcome'              => $first_outcome['title'],
			'outcome_abbreviation' => $first_outcome['abbreviation'],
			'team_name'            => $team_name,
		);
	}

	if ( count( $teams_result_array ) < 2 ) {
		return null;
	}

	$special_result_suffix_abbreviation = '';
	$special_result_suffix              = '';

	foreach ( $teams_result_array as $team ) {
		$outcome_abbreviation = strtoupper( (string) $team['outcome_abbreviation'] );

		if ( 'TF-W' === $outcome_abbreviation ) {
			$special_result_suffix_abbreviation = 'TF-W';
			$special_result_suffix              = 'Technical Forfeit Win';
			break;
		}

		if ( 'TF-L' === $outcome_abbreviation ) {
			$special_result_suffix_abbreviation = 'TF';
			$special_result_suffix              = 'Technical Forfeit';
			break;
		}

		if ( 'F-W' === $outcome_abbreviation || 'F-L' === $outcome_abbreviation ) {
			$special_result_suffix_abbreviation = 'Forfeit';
			$special_result_suffix              = 'Forfeit';
			break;
		}
	}

	$publish_date = asc_generate_short_date( $post, false );
	$title        = sprintf(
		'%1$s %2$s-%3$s %4$s%s',
		$teams_result_array[0]['team_name'],
		$teams_result_array[0]['score'],
		$teams_result_array[1]['score'],
		$teams_result_array[1]['team_name'],
		$publish_date ? ' - ' . $publish_date : ''
	);

	if ( $special_result_suffix ) {
		$title .= " ({$special_result_suffix_abbreviation})";
	}

	$description .= sprintf(
		' %1$s (%2$s), %3$s (%4$s).',
		$teams_result_array[0]['team_name'],
		$teams_result_array[0]['outcome'],
		$teams_result_array[1]['team_name'],
		$teams_result_array[1]['outcome']
	);

	return array(
		'title'       => $title,
		'description' => $description,
	);
}

/**
 * Build all Open Graph values for an event.
 *
 * @param int|WP_Post $post Event post or ID.
 * @return array<string,mixed>
 */
function asc_sp_event_open_graph_data( $post ) {
	$post = asc_sp_event_get_post( $post );

	if ( ! $post ) {
		return array();
	}

	$event                 = asc_sp_event_object( $post );
	$venue_name            = asc_sp_event_venue_name( $post );
	$publish_date_and_time = get_the_date( 'F j, Y g:i A', $post );
	$description           = trim( "{$publish_date_and_time} at {$venue_name}." );
	$title                 = asc_generate_sp_event_title( $post );
	$sp_status             = get_post_meta( $post->ID, 'sp_status', true );
	$status                = asc_sp_event_status( $post, $event );

	if ( in_array( $sp_status, array( 'postponed', 'cancelled', 'tbd' ), true ) ) {
		$status_label = strtoupper( $sp_status );
		$description  = "{$status_label} - {$description}";
		$title        = trim( "{$status_label} - {$title} - " . asc_generate_short_date( $post ) . " - {$venue_name}", ' -' );
	} elseif ( 'future' === $status ) {
		$title = trim( $title . ' - ' . asc_generate_short_date( $post ) . " - {$venue_name}", ' -' );
	} elseif ( 'results' === $status ) {
		$result_meta = asc_sp_event_result_meta( $post, asc_sp_event_results( $event ), $description );

		if ( $result_meta ) {
			$title       = $result_meta['title'];
			$description = $result_meta['description'];
		}
	}

	$body_excerpt = asc_sp_event_body_excerpt( $post );
	if ( '' !== $body_excerpt ) {
		$description = trim( $description . ' ' . $body_excerpt );
	}

	return array(
		'type'         => 'article',
		'images'       => asc_sp_event_open_graph_images( $post ),
		'image'        => asc_sp_event_matchup_image_url( $post, 'wide' ),
		'image_width'  => '1200',
		'image_height' => '628',
		'title'        => $title,
		'description'  => $description,
		'url'          => get_permalink( $post ),
	);
}

/**
 * Echo Open Graph meta tags for single SportsPress events.
 */
function custom_open_graph_tags_with_sportspress_integration() {
	if ( ! is_single() ) {
		return;
	}

	$post = asc_sp_event_get_post();

	if ( ! $post ) {
		return;
	}

	$meta = asc_sp_event_open_graph_data( $post );

	if ( empty( $meta ) ) {
		return;
	}

	echo '<meta property="og:type" content="' . esc_attr( $meta['type'] ) . '" />' . "\n";
	foreach ( $meta['images'] as $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";
		echo '<meta property="og:image:width" content="' . esc_attr( $image['width'] ) . '" />' . "\n";
		echo '<meta property="og:image:height" content="' . esc_attr( $image['height'] ) . '" />' . "\n";
	}
	echo '<meta property="og:title" content="' . esc_attr( $meta['title'] ) . '" />' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $meta['description'] ) . '" />' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $meta['url'] ) . '" />' . "\n";
}
