<?php
/**
 * Unified team-ordering behavior for SportsPress event admin and frontend lists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether SportsPress reverse teams option is enabled.
 *
 * @return bool
 */
function tony_sportspress_reverse_teams_enabled() {
	return get_option( 'sportspress_event_reverse_teams', 'no' ) === 'yes';
}

/**
 * Add Away | Home option to Event List title format setting.
 *
 * @param array $options Event list settings options.
 * @return array
 */
function tony_sportspress_event_list_add_away_home_option( $options ) {
	if ( ! is_array( $options ) ) {
		return $options;
	}

	foreach ( $options as &$option ) {
		if ( ! is_array( $option ) || ! isset( $option['id'] ) || 'sportspress_event_list_title_format' !== $option['id'] ) {
			continue;
		}

		if ( ! isset( $option['options'] ) || ! is_array( $option['options'] ) ) {
			continue;
		}

		if ( ! array_key_exists( 'awayhome', $option['options'] ) ) {
			$option['options']['awayhome'] = sprintf( '%s | %s', esc_attr__( 'Away', 'sportspress' ), esc_attr__( 'Home', 'sportspress' ) );
		}
	}
	unset( $option );

	return $options;
}
add_filter( 'sportspress_event_list_options', 'tony_sportspress_event_list_add_away_home_option' );

/**
 * Clarify wording of SportsPress Teams reverse-order option.
 *
 * @param array $options Team options.
 * @return array
 */
function tony_sportspress_relabel_reverse_teams_option( $options ) {
	if ( ! is_array( $options ) ) {
		return $options;
	}

	foreach ( $options as &$option ) {
		if ( ! is_array( $option ) || ! isset( $option['id'] ) || 'sportspress_event_reverse_teams' !== $option['id'] ) {
			continue;
		}

		$option['desc'] = esc_attr__( 'Show away team first', 'tonys-sportspress-enhancements' );
	}
	unset( $option );

	return $options;
}
add_filter( 'sportspress_event_logo_options', 'tony_sportspress_relabel_reverse_teams_option' );

/**
 * Add Event Results team order option.
 *
 * @param array $options Event Results options.
 * @return array
 */
function tony_sportspress_add_event_results_order_option( $options ) {
	if ( ! is_array( $options ) ) {
		return $options;
	}

	$options[] = array(
		'title'   => esc_attr__( 'Order', 'sportspress' ),
		'desc'    => esc_attr__( 'Show away team first', 'tonys-sportspress-enhancements' ),
		'id'      => 'tony_sportspress_event_results_away_first',
		'default' => 'yes',
		'type'    => 'checkbox',
	);

	return $options;
}
add_filter( 'sportspress_result_options', 'tony_sportspress_add_event_results_order_option' );

/**
 * Override SportsPress event templates with plugin versions.
 *
 * @param string $template      Located template path.
 * @param string $template_name Template filename.
 * @param string $template_path Template base path.
 * @return string
 */
function tony_sportspress_locate_event_list_template( $template, $template_name, $template_path ) {
	$supported = array(
		'event-list.php',
		'event-results.php',
	);

	if ( ! in_array( $template_name, $supported, true ) ) {
		return $template;
	}

	$override = dirname( __DIR__ ) . '/templates/' . $template_name;
	if ( file_exists( $override ) ) {
		return $override;
	}

	return $template;
}
add_filter( 'sportspress_locate_template', 'tony_sportspress_locate_event_list_template', 10, 3 );

/**
 * Add admin styles for explicit Home/Away labels on event edit screens.
 */
function tony_sportspress_event_team_order_admin_styles() {
	$screen = get_current_screen();
	if ( ! $screen || 'sp_event' !== $screen->post_type ) {
		return;
	}
	?>
	<style>
		#sp_teamdiv .sp-instance {
			padding-top: 8px;
			border-top: 1px solid #dcdcde;
		}
		#sp_teamdiv .sp-instance:first-child {
			padding-top: 0;
			border-top: 0;
		}
		#sp_teamdiv .tony-sp-home-away-label {
			margin: 0 0 8px;
			font-size: 12px;
			letter-spacing: 0.02em;
			text-transform: uppercase;
			color: #50575e;
		}
	</style>
	<?php
}
add_action( 'admin_head-post.php', 'tony_sportspress_event_team_order_admin_styles' );
add_action( 'admin_head-post-new.php', 'tony_sportspress_event_team_order_admin_styles' );

/**
 * Add explicit Home/Away labels and reverse visual order in event edit teams metabox.
 */
function tony_sportspress_event_team_order_admin_script() {
	$screen = get_current_screen();
	if ( ! $screen || 'sp_event' !== $screen->post_type ) {
		return;
	}

	$slot_labels     = array(
		__( 'Home Team', 'tonys-sportspress-enhancements' ),
		__( 'Away Team', 'tonys-sportspress-enhancements' ),
	);
	$show_away_first = tony_sportspress_reverse_teams_enabled();
	?>
	<script>
	(function($) {
		function applyHomeAwayLabels() {
			var labels = <?php echo wp_json_encode( $slot_labels ); ?>;
			var showAwayFirst = <?php echo $show_away_first ? 'true' : 'false'; ?>;
			var $instances = $('#sp_teamdiv .sp-instance');

			if (!$instances.length) {
				return;
			}

			var $container = $instances.first().parent();
			$instances.css('order', '');

			if (showAwayFirst && $instances.length > 1) {
				$container.css({
					display: 'flex',
					flexDirection: 'column'
				});
				$instances.each(function(index) {
					$(this).css('order', index + 1);
				});
				$instances.eq(0).css('order', 2);
				$instances.eq(1).css('order', 1);
			}

			$instances.each(function(index) {
				var label = labels[index] || ('Team ' + (index + 1));
				var $instance = $(this);

				if (!$instance.children('.tony-sp-home-away-label').length) {
					$instance.prepend('<p class="tony-sp-home-away-label"><strong>' + label + '</strong></p>');
				} else {
					$instance.children('.tony-sp-home-away-label').find('strong').text(label);
				}

				$instance.find('select[name="sp_team[]"]').first().attr('aria-label', label);
			});
		}

		$(applyHomeAwayLabels);
		$(document).on('sp-init-chosen sp-init', applyHomeAwayLabels);
	})(jQuery);
	</script>
	<?php
}
add_action( 'admin_footer-post.php', 'tony_sportspress_event_team_order_admin_script' );
add_action( 'admin_footer-post-new.php', 'tony_sportspress_event_team_order_admin_script' );

/**
 * Normalize event-list results array to match event team order.
 *
 * @param array $main_results Team results array.
 * @param int   $event_id     Event ID.
 * @return array
 */
function tony_sportspress_event_list_score_order( $main_results, $event_id ) {
	if ( ! is_array( $main_results ) || empty( $main_results ) ) {
		return $main_results;
	}

	$teams = (array) get_post_meta( $event_id, 'sp_team', false );
	$teams = array_values( array_filter( array_map( 'absint', $teams ) ) );
	if ( empty( $teams ) ) {
		return $main_results;
	}

	$ordered = array();

	foreach ( $teams as $index => $team_id ) {
		if ( array_key_exists( $team_id, $main_results ) ) {
			$ordered[ $team_id ] = $main_results[ $team_id ];
			continue;
		}

		// SportsPress main_results() can be positional (0,1,...) in team order.
		if ( array_key_exists( $index, $main_results ) ) {
			$ordered[ $team_id ] = $main_results[ $index ];
		}
	}

	if ( empty( $ordered ) ) {
		return $main_results;
	}

	foreach ( $main_results as $team_id => $result ) {
		if ( array_key_exists( $team_id, $ordered ) ) {
			continue;
		}

		// Skip positional keys that have already been remapped to team IDs.
		if ( is_int( $team_id ) || ctype_digit( (string) $team_id ) ) {
			$position = (int) $team_id;
			if ( array_key_exists( $position, $teams ) ) {
				continue;
			}
		}

		$ordered[ $team_id ] = $result;
	}

	return $ordered;
}
add_filter( 'sportspress_event_list_main_results', 'tony_sportspress_event_list_score_order', 999, 2 );
