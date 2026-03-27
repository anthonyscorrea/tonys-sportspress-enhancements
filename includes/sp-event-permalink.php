<?php
/*
Plugin Name: Custom Event Permalinks
Description: Adds a custom permalink structure for the sp_event post type.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Register custom rewrite rules
function custom_event_rewrite_rules() {
    add_rewrite_rule(
        '(?:event|game)/.*?[/]?([0-9]+)[/]?$',
        'index.php?post_type=sp_event&p=$matches[1]',
        'top'
    );
}
add_action('init', 'custom_event_rewrite_rules');

// Customize the permalink structure
function custom_event_permalink($permalink, $post) {
    if ($post->post_type !== 'sp_event') {
        return $permalink;
    }

    $event = new SP_Event($post->ID);
    $teams = get_post_meta($post->ID,'sp_team', false);
    $format = get_post_meta($post->ID,'sp_format', true);
    sort($teams);
    $seasons = get_the_terms($post->ID, 'sp_season', true );
    if ($seasons) {
      $seasons_slug = implode(
        "-",
        array_map(function($season){return $season->slug;},$seasons),
      );
    } else {
      $seasons_slug = "no-season";
    };

    // Get the teams associated with the event
    $team_1 = get_post($teams[0]);
    $team_2 = get_post($teams[1]);

    switch ($format) {
      case 'league':
          $format_string = 'game';
          break;
      case 'tournament':
          $format_string = 'game';
          break;
      case 'friendly':
          $format_string = 'event';
          break;
      default:
          $format_string = 'event';
          break;
  }

    if ($team_1 && $team_2) {
        $permalink = home_url($format_string ."/". $seasons_slug . '/' . $team_1->post_name . '-' . $team_2->post_name . '/' . $post->ID);
    }

    return $permalink;
}
add_filter('post_type_link', 'custom_event_permalink', 10, 2);

// Flush rewrite rules on activation and deactivation
function custom_event_rewrite_flush() {
    custom_event_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'custom_event_rewrite_flush');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// Modify the front-end single event query to allow scheduled events to resolve.
function custom_event_parse_request( $query ) {
	if ( ! $query instanceof WP_Query ) {
		return;
	}

	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'sp_event' !== $query->get( 'post_type' ) ) {
		return;
	}

	$post_id = absint( $query->get( 'p' ) );
	if ( $post_id <= 0 ) {
		return;
	}

	$query->set( 'post_type', 'sp_event' );
	$query->set( 'p', $post_id );
	$query->set( 'post_status', array( 'publish', 'future' ) );
}
add_action('pre_get_posts', 'custom_event_parse_request');
