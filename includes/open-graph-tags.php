<?php
/*
Plugin Name: Custom Open Graph Tags with SportsPress Integration
Description: Adds custom Open Graph tags to posts based on their type, specifically handling sp_event post types with methods from the SportsPress SP_Event class.
Version: 1.0
Author: Your Name
*/

add_action('wp_head', 'custom_open_graph_tags_with_sportspress_integration');

function asc_generate_sp_event_title( $post ) {
  // See https://github.com/ThemeBoy/SportsPress/blob/770fa8c6654d7d6648791e877709c2428677635b/includes/admin/post-types/class-sp-admin-cpt-event.php#L99C40-L99C55
	if ( is_numeric( $post ) ) {
		$post = get_post( $post );
	}
	if ( ! $post || $post->post_type !== 'sp_event' ) {
		return get_the_title();
	}

	$teams = get_post_meta( $post->ID, 'sp_team', false );
	$teams = array_filter( $teams );

	$team_names = array();
	foreach ( $teams as $team ) {
		while ( is_array( $team ) ) {
			$team = array_shift( array_filter( $team ) );
		}
		if ( $team > 0 ) {
			$team_names[] = sp_team_short_name( $team );
		}
	}

	$team_names = array_unique( $team_names );

	if ( get_option( 'sportspress_event_reverse_teams', 'no' ) === 'yes' ) {
		$team_names = array_reverse( $team_names );
	}

	$delimiter = ' ' . get_option( 'sportspress_event_teams_delimiter', 'vs' ) . ' ';

	return implode( $delimiter, $team_names );
}

function asc_generate_short_date( $post, $withTime = true ) {
  $formatted_date =  get_the_date('D n/j/y', $post);

  if (!$withTime){
    return $formatted_date;
  } 

  if (  get_the_date('i', $post) == "00") {
    $formatted_time = get_the_date('gA', $post);
  } else {
    $formatted_time = get_the_date('g:iA', $post);
  }
  return $formatted_date . " " . $formatted_time ;

}

function custom_open_graph_tags_with_sportspress_integration() {
    if (is_single()) {
        global $post;
        if ($post->post_type === 'sp_event') {
            // Instantiate SP_Event object
            $event = new SP_Event($post->ID);

            // Fetch details using SP_Event methods
            $publish_date = get_the_date('F j, Y', $post);
            $venue_terms = get_the_terms($post->ID, 'sp_venue');
            $venue_name = $venue_terms ? $venue_terms[0]->name : 'Venue TBD';
            $results = $event->results();  // Using SP_Event method
            $title = asc_generate_sp_event_title($post);
            $sp_status = get_post_meta( $post->ID, 'sp_status', true );
            $status = $event->status();  // Using SP_Event method
            $publish_date_and_time = get_the_date('F j, Y g:i A', $post);
            $description = "{$publish_date_and_time} at {$venue_name}.";
            
            if ( 'postponed' == $sp_status || 'cancelled' == $sp_status || 'tbd' == $sp_status) {
              $description = strtoupper($sp_status) . " — " . $description;
              $title = strtoupper($sp_status) . " — " . $title . " — " . asc_generate_short_date($post) . " — " . $venue_name;
            }

            if ( 'future' == $status ) {
              $description = $description;
              $title = $title . " — " . asc_generate_short_date($post) . " — " . $venue_name;
            }

            if ( 'results' == $status ) { // checks if there is a final score
              // Get event result data
              $data = $event->results();

              // The first row should be column labels
              $labels = $data[0];

              // Remove the first row to leave us with the actual data
              unset( $data[0] );

              $data = array_filter( $data );

              if ( empty( $data ) ) {
                return false;
              }

              // Initialize
              $i          = 0;
              $result_string = '';
              $title_string = '';

              // Reverse teams order if the option "Events > Teams > Order > Reverse order" is enabled.
              $reverse_teams = get_option( 'sportspress_event_reverse_teams', 'no' ) === 'yes' ? true : false;
              if ( $reverse_teams ) {
                $data = array_reverse( $data, true );
              }

              $teams_result_array = [];

              foreach ( $data as $team_id => $result ) :
                  $outcomes       = array();
                  $result_outcome = sp_array_value( $result, 'outcome' );
                  if ( ! is_array( $result_outcome ) ) :
                    $outcomes = array( '&mdash;' );
                  else :
                    foreach ( $result_outcome as $outcome ) :
                      $the_outcome = get_page_by_path( $outcome, OBJECT, 'sp_outcome' );
                      if ( is_object( $the_outcome ) ) :
                        $outcomes[] = $the_outcome->post_title;
                      endif;
                    endforeach;
                  endif;
              
                unset( $result['outcome'] );
              
                $team_name = sp_team_short_name( $team_id );
                $team_abbreviation = sp_team_abbreviation( $team_id );

                $outcome_abbreviation = get_post_meta( $the_outcome->ID, 'sp_abbreviation', true );
                if ( ! $outcome_abbreviation ) {
                  $outcome_abbreviation = sp_substr( $the_outcome->post_title, 0, 1 );
                }
                
                array_push($teams_result_array, [
                  "result" => $result,
                  "outcome" => $the_outcome->post_title,
                  "outcome_abbreviation" => $outcome_abbreviation,
                  "team_name" => $team_name,
                  "team_abbreviation" => $team_abbreviation
                ]
              );              
                $i++;
              endforeach;
              $publish_date = asc_generate_short_date($post, false);

              $special_result_suffix_abbreviation = '';
              $special_result_suffix= '';

              foreach ( $teams_result_array as $team ) {
                  $outcome_abbreviation = strtoupper( $team['outcome_abbreviation'] ); // Normalize case

                  if ( $outcome_abbreviation === 'TF-W' ) {
                    $special_result_suffix_abbreviation = 'TF-W';
                    $special_result_suffix = 'Technical Forfeit Win';
                    break;
                  } elseif ( $outcome_abbreviation === 'TF-L' ) {
                    $special_result_suffix_abbreviation = 'TF';
                    $special_result_suffix = 'Technical Forfeit';
                    break;
                  } elseif ( $outcome_abbreviation === 'F-W' || $outcome_abbreviation === 'F-L' ) {
                    $special_result_suffix_abbreviation = 'Forfeit';
                    $special_result_suffix = 'Forfeit';
                    break;
                  }
              }

              $title = "{$teams_result_array[0]['team_name']} {$teams_result_array[0]['result']['r']}-{$teams_result_array[1]['result']['r']} {$teams_result_array[1]['team_name']} — {$publish_date}" . ($special_result_suffix ? "({$special_result_suffix_abbreviation})" : "");
              $description .= " " . "{$teams_result_array[0]['team_name']} ({$teams_result_array[0]['outcome']}), {$teams_result_array[1]['team_name']} ({$teams_result_array[1]['outcome']})." ;
            }
            $description .= " " . $post->post_content;
            $image = get_site_url() . "/head-to-head?post={$post->ID}";
            echo '<meta property="og:type" content="article" />' . "\n";
            echo '<meta property="og:image" content="'. $image . '" />' . "\n";
            echo '<meta property="og:title" content="' . $title . '" />' . "\n";
            echo '<meta property="og:description" content="' . $description . '" />' . "\n";
            echo '<meta property="og:url" content="' . get_permalink() . '" />' . "\n";
        }
    }
}
?>
