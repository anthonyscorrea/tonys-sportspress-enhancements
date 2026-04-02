<?php
/**
 * Plugin Name:     Tonys SportsPress Enhancements
 * Plugin URI:      https://github.com/anthonyscorrea/tonys-sportspress-enhancements
 * Description:     Suite of SportsPress Enhancements
 * Author:          Tony Correa
 * Author URI:      https://github.com/anthonyscorrea/
 * Text Domain:     tonys-sportspress-enhancements
 * Domain Path:     /languages
 * Update URI:      https://github.com/anthonyscorrea/tonys-sportspress-enhancements
 * Version:         0.1.8
 *
 * @package         Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_VERSION' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_VERSION', '0.1.8' );
}

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_FILE' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_FILE', __FILE__ );
}

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_DIR' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_URL' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_PLUGIN_BASENAME' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// Include other files here
require_once plugin_dir_path(__FILE__) . 'includes/sp-github-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-officials-manager-role.php';
require_once plugin_dir_path(__FILE__) . 'includes/open-graph-tags.php';
require_once plugin_dir_path(__FILE__) . 'includes/featured-image-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-permalink.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-export.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-csv.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-admin-week-filter.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-quick-edit-officials.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-event-team-ordering.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-printable-calendars.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-url-builder.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-schedule-exporter.php';
require_once plugin_dir_path(__FILE__) . 'includes/sp-venue-meta.php';

register_activation_hook( __FILE__, 'tony_sportspress_sync_officials_manager_roles' );
