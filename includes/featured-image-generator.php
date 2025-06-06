<?php
/*
Plugin Name: SP Event Image Generator
Description: Auto-generates featured images for SP Events by combining team colors and logos.
Version: 1.0
Author: Your Name
*/

function generate_bisected_image($color1, $color2, $logo1_path, $logo2_path) {
    $width = 1200;
    $height = 628;
    $x_margin = 0.1 * ($width / 2); // 10% of half the width
    $y_margin = 0.1 * $height; // 10% of the height
    $image = imagecreatetruecolor($width, $height);

    // Allocate colors
    $rgb1 = sscanf($color1, "#%02x%02x%02x");
    $rgb2 = sscanf($color2, "#%02x%02x%02x");
    $color1_alloc = imagecolorallocate($image, $rgb1[0], $rgb1[1], $rgb1[2]);
    $color2_alloc = imagecolorallocate($image, $rgb2[0], $rgb2[1], $rgb2[2]);

    // Fill halves with a 15-degree angled bisection
    $points1 = [
        0, 0, 
        0, $height,
        $width*.40, $height, 
        $width*.60, 0,
    ];
    $points2 = [
        $width, 0, 
        $width, $height, 
        $width*.40, $height, 
        $width*.60, 0,
    ];
    imagefilledpolygon($image, $points1, $color1_alloc);
    imagefilledpolygon($image, $points2, $color2_alloc);

    // Add logos with resizing and positioning if paths are not empty
    if (!empty($logo1_path)) {
        $logo1 = imagecreatefrompng($logo1_path);
        $logo1_width = imagesx($logo1);
        $logo1_height = imagesy($logo1);

        // Calculate max dimensions for logo 1
        $max_width = ($width / 2) - (2 * $x_margin);
        $max_height = $height - (2 * $y_margin);

        // Resize logo 1
        $new_logo1_width = $logo1_width;
        $new_logo1_height = $logo1_height;
        if ($logo1_width > $max_width || $logo1_height > $max_height) {
            $aspect_ratio1 = $logo1_width / $logo1_height;
            if ($logo1_width / $max_width > $logo1_height / $max_height) {
                $new_logo1_width = $max_width;
                $new_logo1_height = $max_width / $aspect_ratio1;
            } else {
                $new_logo1_height = $max_height;
                $new_logo1_width = $max_height * $aspect_ratio1;
            }
        }

        // Center logo 1
        $logo1_x = (int) ($width / 4) - ($new_logo1_width / 2);
        $logo1_y = (int) ($height / 2) - ($new_logo1_height / 2);
        imagecopyresampled($image, $logo1, $logo1_x, $logo1_y, 0, 0, $new_logo1_width, $new_logo1_height, $logo1_width, $logo1_height);
        imagedestroy($logo1);
    }

    if (!empty($logo2_path)) {
        $logo2 = imagecreatefrompng($logo2_path);
        $logo2_width = imagesx($logo2);
        $logo2_height = imagesy($logo2);

        // Calculate max dimensions for logo 2
        $max_width = ($width / 2) - (2 * $x_margin);
        $max_height = $height - (2 * $y_margin);

        // Resize logo 2
        $new_logo2_width = $logo2_width;
        $new_logo2_height = $logo2_height;
        if ($logo2_width > $max_width || $logo2_height > $max_height) {
            $aspect_ratio2 = $logo2_width / $logo2_height;
            if ($logo2_width / $max_width > $logo2_height / $max_height) {
                $new_logo2_width = $max_width;
                $new_logo2_height = $max_width / $aspect_ratio2;
            } else {
                $new_logo2_height = $max_height;
                $new_logo2_width = $max_height * $aspect_ratio2;
            }
        }

        // Center logo 2
        $logo2_x = (int) (3 * $width / 4) - ($new_logo2_width / 2);
        $logo2_y = (int) ($height / 2) - ($new_logo2_height / 2);
        imagecopyresampled($image, $logo2, $logo2_x, $logo2_y, 0, 0, $new_logo2_width, $new_logo2_height, $logo2_width, $logo2_height);
        imagedestroy($logo2);
    }

    // Start output buffering to capture the image data
    ob_start();
    imagepng($image); // Output the image as PNG
    $image_data = ob_get_clean(); // Get the image data from the buffer

    // Clean up memory
    imagedestroy($image);

    return $image_data;

}

function add_image_generator_endpoint() {
    add_rewrite_endpoint('head-to-head', EP_ROOT, true);
}
add_action('init', 'add_image_generator_endpoint');

function handle_image_request() {
    if (!isset($_GET['post'])) return;
    
    $post_id = $_GET['post'];
    $post = get_post($post_id);

    // Verify post type
    if (!$post && $post->post_type !== 'sp_event') return;

    // Get associated teams from post meta
    $team_ids = get_post_meta($post_id, 'sp_team', false); // false to get an array of values

    // Ensure we have exactly two teams
    if (count($team_ids) < 2) return;

    $team1_id = $team_ids[0];
    $team2_id = $team_ids[1];

    $team1 = get_post($team1_id);
    $team2 = get_post($team2_id);
    $team1_postmodified = strtotime($team1->post_modified);
    $team2_postmodified = strtotime($team2->post_modified);

    $cache_key = "team_image_{$team1_id}_{$team1_postmodified}-{$team2_id}_{$team2_postmodified}";
    $cached_image_path = get_transient($cache_key);

    if ($cached_image_path && file_exists($cached_image_path)) {
        serve_image($cached_image_path);
        exit;
    }

    // Get team colors and logos
    $team1_colors = get_post_meta($team1_id, 'sp_colors', true);
    $team2_colors = get_post_meta($team2_id, 'sp_colors', true);

    $default_color = '#FFFFFF'; // Default color (black)
    $team1_color = !empty($team1_colors['primary']) ? $team1_colors['primary'] : $default_color;
    $team2_color = !empty($team2_colors['primary']) ? $team2_colors['primary'] : $default_color;

    // Security check for hex color
    $team1_color = preg_match('/^#[a-fA-F0-9]{6}$/', $team1_color) ? $team1_color : '#FFFFFF';
    $team2_color = preg_match('/^#[a-fA-F0-9]{6}$/', $team2_color) ? $team2_color : '#FFFFFF';

    $team1_logo_url = get_the_post_thumbnail_url($team1_id, 'full');
    $team2_logo_url = get_the_post_thumbnail_url($team2_id, 'full');

    // Check if both team colors are default and both logos are empty
    if (($team1_color === $default_color && empty($team1_logo_url)) && ($team2_color === $default_color && empty($team2_logo_url))) {
        return; // Do nothing if both teams have no valid color or logo
    }

    $team1_logo_thumbnail_id = get_post_thumbnail_id($team1_id, 'full');
    $team2_logo_thumbnail_id = get_post_thumbnail_id($team2_id, 'full');
    $team1_logo = get_attached_file($team1_logo_thumbnail_id);
    $team2_logo = get_attached_file($team2_logo_thumbnail_id);

    // Generate the image if no valid cache exists
    $image_data = generate_bisected_image($team1_color, $team2_color, $team1_logo, $team2_logo);
    $image_path = save_image_to_cache($image_data, $cache_key);
    set_transient($cache_key, $image_path, DAY_IN_SECONDS * 30); // Cache for 30 days

    serve_image($image_path);

    exit;
}
add_action('template_redirect', 'handle_image_request');

function serve_image($image_path) {
    header('Content-Type: image/png');
    if (file_exists($image_path)) {
        status_header( 200 ); 
    } else {
        status_header( 404 );
        die("Image not found.");
    }

    // Clear all output buffering to prevent any extra output
    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($image_path);
}

function save_image_to_cache($image_data, $cache_key) {
    $upload_dir = wp_get_upload_dir();
    $file_path = $upload_dir['path'] . '/' . $cache_key . '.png';

    // Assuming $image_data is raw image data
    file_put_contents($file_path, $image_data);

    return $file_path;
}