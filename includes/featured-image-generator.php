<?php
/**
 * Dynamic SportsPress event matchup image endpoint.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ASC_SP_EVENT_IMAGE_OPTION_KEY' ) ) {
	define( 'ASC_SP_EVENT_IMAGE_OPTION_KEY', 'asc_sp_event_image_settings' );
}

if ( ! defined( 'ASC_SP_EVENT_IMAGE_OPTION_GROUP' ) ) {
	define( 'ASC_SP_EVENT_IMAGE_OPTION_GROUP', 'asc_sp_event_image_settings' );
}

if ( ! defined( 'ASC_SP_EVENT_IMAGE_SETTINGS_TAB' ) ) {
	define( 'ASC_SP_EVENT_IMAGE_SETTINGS_TAB', 'open-graph-images' );
}

if ( ! defined( 'ASC_SP_EVENT_IMAGE_CACHE_VERSION' ) ) {
	define( 'ASC_SP_EVENT_IMAGE_CACHE_VERSION', '7' );
}

/**
 * Default image generator settings.
 *
 * @return array<string,string>
 */
function asc_sp_event_image_default_settings() {
	return array(
		'fallback_left_background'  => '#4B5563',
		'fallback_right_background' => '#4B5563',
		'fallback_text_color'       => '#F9FAFB',
		'fallback_shadow_color'     => '#1F2937',
	);
}

/**
 * Get image generator settings.
 *
 * @return array<string,string>
 */
function asc_sp_event_image_get_settings() {
	return wp_parse_args( get_option( ASC_SP_EVENT_IMAGE_OPTION_KEY, array() ), asc_sp_event_image_default_settings() );
}

/**
 * Sanitize and validate a hex color.
 *
 * @param string $color Color value.
 * @param string $fallback Fallback color.
 * @return string
 */
function asc_sp_event_image_color( $color, $fallback = '#4B5563' ) {
	return is_string( $color ) && preg_match( '/^#[a-fA-F0-9]{6}$/', $color ) ? strtoupper( $color ) : strtoupper( $fallback );
}

/**
 * Sanitize image generator settings.
 *
 * @param mixed $input Raw settings.
 * @return array<string,string>
 */
function asc_sp_event_image_sanitize_settings( $input ) {
	$defaults = asc_sp_event_image_default_settings();
	$input    = is_array( $input ) ? $input : array();

	return array(
		'fallback_left_background'  => asc_sp_event_image_color( isset( $input['fallback_left_background'] ) ? $input['fallback_left_background'] : '', $defaults['fallback_left_background'] ),
		'fallback_right_background' => asc_sp_event_image_color( isset( $input['fallback_right_background'] ) ? $input['fallback_right_background'] : '', $defaults['fallback_right_background'] ),
		'fallback_text_color'       => asc_sp_event_image_color( isset( $input['fallback_text_color'] ) ? $input['fallback_text_color'] : '', $defaults['fallback_text_color'] ),
		'fallback_shadow_color'     => asc_sp_event_image_color( isset( $input['fallback_shadow_color'] ) ? $input['fallback_shadow_color'] : '', $defaults['fallback_shadow_color'] ),
	);
}

/**
 * Allocate a GD color from a hex value.
 *
 * @param GdImage|resource $image    Destination image.
 * @param string           $hex      Hex color.
 * @param string           $fallback Fallback color.
 * @return int|false
 */
function asc_sp_event_image_allocate_hex_color( $image, $hex, $fallback = '#4B5563' ) {
	$rgb = sscanf( asc_sp_event_image_color( $hex, $fallback ), '#%02x%02x%02x' );

	return imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
}

/**
 * Get cache style hash for image settings and generator version.
 *
 * @return string
 */
function asc_sp_event_image_cache_style_hash() {
	return substr( md5( wp_json_encode( asc_sp_event_image_get_settings() ) . '|' . ASC_SP_EVENT_IMAGE_CACHE_VERSION ), 0, 10 );
}

/**
 * Public image URL version token.
 *
 * @return string
 */
function asc_sp_event_image_url_version() {
	return ASC_SP_EVENT_IMAGE_CACHE_VERSION . '-' . asc_sp_event_image_cache_style_hash();
}

/**
 * Supported image variants.
 *
 * @return array<string,array{width:int,height:int}>
 */
function asc_sp_event_image_variants() {
	return array(
		'wide'   => array(
			'width'  => 1200,
			'height' => 628,
		),
		'square' => array(
			'width'  => 1200,
			'height' => 1200,
		),
	);
}

/**
 * Sanitize an image variant.
 *
 * @param string $variant Variant.
 * @return string
 */
function asc_sp_event_image_sanitize_variant( $variant ) {
	$variant  = sanitize_key( $variant );
	$variants = asc_sp_event_image_variants();

	return isset( $variants[ $variant ] ) ? $variant : 'wide';
}

/**
 * Get dimensions for an image variant.
 *
 * @param string $variant Variant.
 * @return array{width:int,height:int}
 */
function asc_sp_event_image_variant_dimensions( $variant ) {
	$variants = asc_sp_event_image_variants();
	$variant  = asc_sp_event_image_sanitize_variant( $variant );

	return $variants[ $variant ];
}

/**
 * Register Tony's Settings tab.
 *
 * @param array $tabs Existing tabs.
 * @return array
 */
function asc_sp_event_image_register_settings_tab( $tabs ) {
	$tabs[ ASC_SP_EVENT_IMAGE_SETTINGS_TAB ] = __( 'Open Graph Images', 'tonys-sportspress-enhancements' );

	return $tabs;
}
add_filter( 'tse_tonys_settings_tabs', 'asc_sp_event_image_register_settings_tab' );

/**
 * Register image generator settings.
 */
function asc_sp_event_image_register_settings() {
	register_setting(
		ASC_SP_EVENT_IMAGE_OPTION_GROUP,
		ASC_SP_EVENT_IMAGE_OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'asc_sp_event_image_sanitize_settings',
			'default'           => asc_sp_event_image_default_settings(),
		)
	);
}
add_action( 'admin_init', 'asc_sp_event_image_register_settings' );

/**
 * Capability required to save image generator settings.
 *
 * @return string
 */
function asc_sp_event_image_settings_capability() {
	return 'manage_sportspress';
}
add_filter( 'option_page_capability_' . ASC_SP_EVENT_IMAGE_OPTION_GROUP, 'asc_sp_event_image_settings_capability' );

/**
 * Render a color setting row.
 *
 * @param string $key      Setting key.
 * @param string $label    Field label.
 * @param string $help     Help text.
 * @param array  $settings Current settings.
 */
function asc_sp_event_image_render_color_row( $key, $label, $help, array $settings ) {
	$value = isset( $settings[ $key ] ) ? asc_sp_event_image_color( $settings[ $key ], asc_sp_event_image_default_settings()[ $key ] ) : asc_sp_event_image_default_settings()[ $key ];
	$name  = ASC_SP_EVENT_IMAGE_OPTION_KEY . '[' . $key . ']';
	$id    = 'asc-sp-event-image-' . str_replace( '_', '-', $key );

	echo '<tr>';
	echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
	echo '<td>';
	echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
	echo ' <code>' . esc_html( $value ) . '</code>';
	echo '<p class="description">' . esc_html( $help ) . '</p>';
	echo '</td>';
	echo '</tr>';
}

/**
 * Render Tony's Settings Open Graph image tab.
 */
function asc_sp_event_image_render_settings_tab() {
	if ( ! current_user_can( 'manage_sportspress' ) ) {
		return;
	}

	$settings = asc_sp_event_image_get_settings();

	settings_errors( ASC_SP_EVENT_IMAGE_OPTION_GROUP );

	echo '<form method="post" action="options.php">';
	settings_fields( ASC_SP_EVENT_IMAGE_OPTION_GROUP );

	echo '<h2>' . esc_html__( 'Open Graph Matchup Images', 'tonys-sportspress-enhancements' ) . '</h2>';
	echo '<p>' . esc_html__( 'Control the generated social preview image used for SportsPress events when a team logo is missing or a team color is not available.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '<p class="description">' . esc_html__( 'The image cache key includes these values, so saving changes forces future image requests to generate fresh files.', 'tonys-sportspress-enhancements' ) . '</p>';

	echo '<table class="form-table" role="presentation"><tbody>';
	asc_sp_event_image_render_color_row( 'fallback_left_background', __( 'Default Left Background', 'tonys-sportspress-enhancements' ), __( 'Neutral grey used when the first displayed team does not have a valid primary color.', 'tonys-sportspress-enhancements' ), $settings );
	asc_sp_event_image_render_color_row( 'fallback_right_background', __( 'Default Right Background', 'tonys-sportspress-enhancements' ), __( 'Neutral grey used when the second displayed team does not have a valid primary color.', 'tonys-sportspress-enhancements' ), $settings );
	asc_sp_event_image_render_color_row( 'fallback_text_color', __( 'Fallback Team Text', 'tonys-sportspress-enhancements' ), __( 'Large team-name text drawn when a logo is missing.', 'tonys-sportspress-enhancements' ), $settings );
	asc_sp_event_image_render_color_row( 'fallback_shadow_color', __( 'Text Shadow', 'tonys-sportspress-enhancements' ), __( 'A subtle offset shadow for readability. No heavy outline is drawn.', 'tonys-sportspress-enhancements' ), $settings );
	echo '</tbody></table>';

	echo '<div style="width:420px;max-width:100%;aspect-ratio:1200/628;position:relative;overflow:hidden;background:' . esc_attr( $settings['fallback_left_background'] ) . ';border:1px solid #dcdcde;">';
	echo '<div style="position:absolute;inset:0;clip-path:polygon(60% 0,100% 0,100% 100%,40% 100%);background:' . esc_attr( $settings['fallback_right_background'] ) . ';"></div>';
	echo '<div style="position:absolute;left:8%;right:8%;top:50%;transform:translateY(-50%);display:flex;justify-content:space-between;gap:24px;font-family:Impact,Arial Black,sans-serif;font-size:44px;line-height:.9;color:' . esc_attr( $settings['fallback_text_color'] ) . ';text-shadow:2px 2px 0 ' . esc_attr( $settings['fallback_shadow_color'] ) . ';">';
	echo '<span>' . esc_html__( 'HAWKS', 'tonys-sportspress-enhancements' ) . '</span><span>' . esc_html__( 'ELECTRONS', 'tonys-sportspress-enhancements' ) . '</span>';
	echo '</div></div>';

	submit_button( __( 'Save Settings', 'tonys-sportspress-enhancements' ) );
	echo '</form>';
}
add_action( 'tse_tonys_settings_render_tab_' . ASC_SP_EVENT_IMAGE_SETTINGS_TAB, 'asc_sp_event_image_render_settings_tab' );

/**
 * Load a raster logo with GD when the local install supports the format.
 *
 * @param string $path Local image path.
 * @return GdImage|resource|false
 */
function asc_sp_event_image_create_from_file( $path ) {
	if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
		return false;
	}

	$image_type = function_exists( 'exif_imagetype' ) ? @exif_imagetype( $path ) : false;

	if ( IMAGETYPE_PNG === $image_type && function_exists( 'imagecreatefrompng' ) ) {
		return @imagecreatefrompng( $path );
	}

	if ( IMAGETYPE_JPEG === $image_type && function_exists( 'imagecreatefromjpeg' ) ) {
		return @imagecreatefromjpeg( $path );
	}

	if ( IMAGETYPE_GIF === $image_type && function_exists( 'imagecreatefromgif' ) ) {
		return @imagecreatefromgif( $path );
	}

	if ( defined( 'IMAGETYPE_WEBP' ) && IMAGETYPE_WEBP === $image_type && function_exists( 'imagecreatefromwebp' ) ) {
		return @imagecreatefromwebp( $path );
	}

	return false;
}

/**
 * Destroy a GD image on PHP versions where that still has an effect.
 *
 * @param GdImage|resource $image Image resource.
 */
function asc_sp_event_image_destroy( $image ) {
	if ( defined( 'PHP_VERSION_ID' ) && PHP_VERSION_ID >= 80500 ) {
		return;
	}

	if ( $image ) {
		imagedestroy( $image );
	}
}

/**
 * Get the bundled fallback font path.
 *
 * @return string
 */
function asc_sp_event_image_font_path() {
	if ( defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_DIR' ) ) {
		return TONY_SPORTSPRESS_ENHANCEMENTS_DIR . 'assets/fonts/BebasNeue-Regular.ttf';
	}

	return dirname( __DIR__ ) . '/assets/fonts/BebasNeue-Regular.ttf';
}

/**
 * Measure TrueType text dimensions.
 *
 * @param int    $font_size Font size.
 * @param string $font_path Font path.
 * @param string $text      Text.
 * @return array{width:int,height:int}
 */
function asc_sp_event_image_ttf_text_size( $font_size, $font_path, $text ) {
	$box = imagettfbbox( $font_size, 0, $font_path, $text );

	if ( ! is_array( $box ) ) {
		return array(
			'width'  => 0,
			'height' => 0,
		);
	}

	return array(
		'width'  => absint( max( $box[2], $box[4] ) - min( $box[0], $box[6] ) ),
		'height' => absint( max( $box[1], $box[3] ) - min( $box[5], $box[7] ) ),
	);
}

/**
 * Wrap text for a TrueType bounding box.
 *
 * @param string $text      Text.
 * @param string $font_path Font path.
 * @param int    $font_size Font size.
 * @param int    $max_width Maximum line width.
 * @return string[]
 */
function asc_sp_event_image_wrap_ttf_text( $text, $font_path, $font_size, $max_width ) {
	$words = preg_split( '/\s+/', trim( $text ) );

	if ( ! is_array( $words ) || empty( $words ) ) {
		return array();
	}

	$lines = array();
	$line  = '';

	foreach ( $words as $word ) {
		$candidate = '' === $line ? $word : "{$line} {$word}";
		$size      = asc_sp_event_image_ttf_text_size( $font_size, $font_path, $candidate );

		if ( $size['width'] <= $max_width || '' === $line ) {
			$line = $candidate;
			continue;
		}

		$lines[] = $line;
		$line    = $word;
	}

	if ( '' !== $line ) {
		$lines[] = $line;
	}

	return $lines;
}

/**
 * Draw large fallback team text with the bundled sporty font.
 *
 * @param GdImage|resource $image  Destination image.
 * @param string           $text   Text to draw.
 * @param int              $center Center x-coordinate.
 * @param int              $width  Canvas width.
 * @param int              $height Canvas height.
 * @param int|null         $center_y Center y-coordinate.
 * @return bool True when drawn.
 */
function asc_sp_event_image_draw_ttf_team_text( $image, $text, $center, $width, $height, $center_y = null ) {
	$font_path = asc_sp_event_image_font_path();

	if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) || ! is_readable( $font_path ) ) {
		return false;
	}

	$text = strtoupper( trim( wp_strip_all_tags( (string) $text ) ) );

	if ( '' === $text ) {
		return false;
	}

	$center_y = null === $center_y ? (int) ( $height / 2 ) : (int) $center_y;
	$half_width = (int) ( $width / 2 );
	$min_x      = $center < $half_width ? 48 : $half_width + 48;
	$max_x      = $center < $half_width ? $half_width - 48 : $width - 48;
	$max_width  = $max_x - $min_x;
	$max_height = (int) ( $height * 0.68 );
	$font_size  = 190;
	$lines      = array( $text );
	$line_gap   = 12;

	while ( $font_size >= 42 ) {
		$lines        = asc_sp_event_image_wrap_ttf_text( $text, $font_path, $font_size, $max_width );
		$line_heights = array();
		$widest       = 0;

		foreach ( $lines as $line ) {
			$size           = asc_sp_event_image_ttf_text_size( $font_size, $font_path, $line );
			$line_heights[] = $size['height'];
			$widest         = max( $widest, $size['width'] );
		}

		$total_height = array_sum( $line_heights ) + max( 0, count( $lines ) - 1 ) * $line_gap;

		if ( $widest <= $max_width && $total_height <= $max_height && count( $lines ) <= 3 ) {
			break;
		}

		$font_size -= 6;
	}

	$line_heights = array();
	$total_height = 0;

	foreach ( $lines as $line ) {
		$size           = asc_sp_event_image_ttf_text_size( $font_size, $font_path, $line );
		$line_heights[] = $size['height'];
		$total_height  += $size['height'];
	}

	$total_height += max( 0, count( $lines ) - 1 ) * $line_gap;
	$y             = (int) ( $center_y - ( $total_height / 2 ) );
	$settings      = asc_sp_event_image_get_settings();
	$fill          = asc_sp_event_image_allocate_hex_color( $image, $settings['fallback_text_color'], '#F9FAFB' );
	$shadow        = asc_sp_event_image_allocate_hex_color( $image, $settings['fallback_shadow_color'], '#1F2937' );

	foreach ( $lines as $index => $line ) {
		$size = asc_sp_event_image_ttf_text_size( $font_size, $font_path, $line );
		$x    = (int) ( $center - ( $size['width'] / 2 ) );
		$x    = max( $min_x, min( $x, $max_x - $size['width'] ) );
		$y   += $line_heights[ $index ];

		imagettftext( $image, $font_size, 0, $x + 4, $y + 5, $shadow, $font_path, $line );
		imagettftext( $image, $font_size, 0, $x, $y, $fill, $font_path, $line );
		$y += $line_gap;
	}

	return true;
}

/**
 * Draw fallback team text when no readable logo is available.
 *
 * @param GdImage|resource $image  Destination image.
 * @param string           $text   Text to draw.
 * @param int              $center Center x-coordinate.
 * @param int              $width  Canvas width.
 * @param int              $height Canvas height.
 * @param int|null         $center_y Center y-coordinate.
 */
function asc_sp_event_image_draw_team_text( $image, $text, $center, $width, $height, $center_y = null ) {
	$text = trim( wp_strip_all_tags( (string) $text ) );

	if ( '' === $text ) {
		return;
	}

	if ( asc_sp_event_image_draw_ttf_team_text( $image, $text, $center, $width, $height, $center_y ) ) {
		return;
	}

	$center_y    = null === $center_y ? (int) ( $height / 2 ) : (int) $center_y;
	$font  = 5;
	$lines = explode( "\n", wordwrap( strtoupper( $text ), 14, "\n", true ) );
	$lines = array_slice( $lines, 0, 3 );

	$line_height = imagefontheight( $font ) + 8;
	$total       = count( $lines ) * $line_height;
	$y           = (int) ( $center_y - ( $total / 2 ) );
	$settings    = asc_sp_event_image_get_settings();
	$fill        = asc_sp_event_image_allocate_hex_color( $image, $settings['fallback_text_color'], '#F9FAFB' );
	$shadow      = asc_sp_event_image_allocate_hex_color( $image, $settings['fallback_shadow_color'], '#1F2937' );
	$half_width  = (int) ( $width / 2 );

	foreach ( $lines as $line ) {
		$line       = trim( $line );
		$text_width = imagefontwidth( $font ) * strlen( $line );
		$x          = (int) ( $center - ( $text_width / 2 ) );
		$min_x      = $center < $half_width ? 12 : $half_width + 12;
		$max_x      = $center < $half_width ? $half_width - $text_width - 12 : $width - $text_width - 12;
		$x          = max( $min_x, min( $x, $max_x ) );

		imagestring( $image, $font, $x + 2, $y + 2, $line, $shadow );
		imagestring( $image, $font, $x, $y, $line, $fill );
		$y += $line_height;
	}
}

/**
 * Place a logo on one half of the canvas, falling back to text.
 *
 * @param GdImage|resource $image     Destination image.
 * @param string           $logo_path Logo path.
 * @param string           $fallback  Fallback text.
 * @param int              $center    Center x-coordinate.
 * @param int              $width     Canvas width.
 * @param int              $height    Canvas height.
 * @param int|null         $center_y  Center y-coordinate.
 */
function asc_sp_event_image_place_logo_or_text( $image, $logo_path, $fallback, $center, $width, $height, $center_y = null ) {
	$x_margin = 0.1 * ( $width / 2 );
	$y_margin = 0.1 * $height;
	$logo     = asc_sp_event_image_create_from_file( $logo_path );
	$center_y = null === $center_y ? (int) ( $height / 2 ) : (int) $center_y;

	if ( ! $logo ) {
		asc_sp_event_image_draw_team_text( $image, $fallback, $center, $width, $height, $center_y );
		return;
	}

	imagealphablending( $logo, true );
	imagesavealpha( $logo, true );

	$logo_width  = imagesx( $logo );
	$logo_height = imagesy( $logo );

	if ( $logo_width <= 0 || $logo_height <= 0 ) {
		asc_sp_event_image_destroy( $logo );
		asc_sp_event_image_draw_team_text( $image, $fallback, $center, $width, $height, $center_y );
		return;
	}

	$max_width  = ( $width / 2 ) - ( 2 * $x_margin );
	$max_height = $height - ( 2 * $y_margin );
	$new_width  = $logo_width;
	$new_height = $logo_height;

	if ( $logo_width > $max_width || $logo_height > $max_height ) {
		$aspect_ratio = $logo_width / $logo_height;

		if ( $logo_width / $max_width > $logo_height / $max_height ) {
			$new_width  = $max_width;
			$new_height = $max_width / $aspect_ratio;
		} else {
			$new_height = $max_height;
			$new_width  = $max_height * $aspect_ratio;
		}
	}

	$logo_x = (int) ( $center - ( $new_width / 2 ) );
	$logo_y = (int) ( $center_y - ( $new_height / 2 ) );

	imagecopyresampled( $image, $logo, $logo_x, $logo_y, 0, 0, (int) $new_width, (int) $new_height, $logo_width, $logo_height );
	asc_sp_event_image_destroy( $logo );
}

/**
 * Generate a PNG matchup image.
 *
 * @param string $color1          Left color.
 * @param string $color2          Right color.
 * @param string $logo1_path      Left logo path.
 * @param string $logo2_path      Right logo path.
 * @param string $team1_fallback  Left fallback text.
 * @param string $team2_fallback  Right fallback text.
 * @param int    $width           Image width.
 * @param int    $height          Image height.
 * @return string
 */
function generate_bisected_image( $color1, $color2, $logo1_path, $logo2_path, $team1_fallback = '', $team2_fallback = '', $width = 1200, $height = 628 ) {
	$width    = max( 1, absint( $width ) );
	$height   = max( 1, absint( $height ) );
	$image    = imagecreatetruecolor( $width, $height );
	$settings = asc_sp_event_image_get_settings();

	imagealphablending( $image, true );
	imagesavealpha( $image, true );

	$color1_alloc = asc_sp_event_image_allocate_hex_color( $image, $color1, $settings['fallback_left_background'] );
	$color2_alloc = asc_sp_event_image_allocate_hex_color( $image, $color2, $settings['fallback_right_background'] );

	$points1 = array(
		0,
		0,
		0,
		$height,
		$width * .40,
		$height,
		$width * .60,
		0,
	);
	$points2 = array(
		$width,
		0,
		$width,
		$height,
		$width * .40,
		$height,
		$width * .60,
		0,
	);

	imagefilledpolygon( $image, $points1, $color1_alloc );
	imagefilledpolygon( $image, $points2, $color2_alloc );

	$left_center_y  = (int) ( $height / 2 );
	$right_center_y = (int) ( $height / 2 );

	if ( $width === $height ) {
		$left_center_y  = (int) ( $height * 0.28 );
		$right_center_y = (int) ( $height * 0.72 );
	}

	asc_sp_event_image_place_logo_or_text( $image, $logo1_path, $team1_fallback, (int) ( $width / 4 ), $width, $height, $left_center_y );
	asc_sp_event_image_place_logo_or_text( $image, $logo2_path, $team2_fallback, (int) ( 3 * $width / 4 ), $width, $height, $right_center_y );

	ob_start();
	imagepng( $image );
	$image_data = ob_get_clean();

	asc_sp_event_image_destroy( $image );

	return $image_data;
}

/**
 * Register the image endpoint.
 */
function add_image_generator_endpoint() {
	add_rewrite_endpoint( 'head-to-head', EP_ROOT, true );
}
add_action( 'init', 'add_image_generator_endpoint' );

/**
 * Return a clean 404 response for bad image requests.
 *
 * @param string $message Response body.
 */
function asc_sp_event_image_not_found( $message = 'Image not found.' ) {
	status_header( 404 );
	nocache_headers();

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	echo esc_html( $message );
	exit;
}

/**
 * Get a sanitized post ID from the request.
 *
 * @return int
 */
function asc_sp_event_image_request_post_id() {
	if ( ! isset( $_GET['post'] ) ) {
		return 0;
	}

	$post_id = wp_unslash( $_GET['post'] );

	if ( is_array( $post_id ) ) {
		return 0;
	}

	return absint( $post_id );
}

/**
 * Get the requested image variant.
 *
 * @return string
 */
function asc_sp_event_image_request_variant() {
	if ( ! isset( $_GET['variant'] ) ) {
		return 'wide';
	}

	$variant = wp_unslash( $_GET['variant'] );

	if ( is_array( $variant ) ) {
		return 'wide';
	}

	return asc_sp_event_image_sanitize_variant( $variant );
}

/**
 * Prepare image request data, or a WP_Error for 404 handling.
 *
 * @param int    $post_id Event post ID.
 * @param string $variant Image variant.
 * @return array|WP_Error
 */
function asc_sp_event_prepare_image_request( $post_id, $variant = 'wide' ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );
	$variant = asc_sp_event_image_sanitize_variant( $variant );
	$dimensions = asc_sp_event_image_variant_dimensions( $variant );

	if ( ! $post || 'sp_event' !== $post->post_type ) {
		return new WP_Error( 'invalid_event', __( 'Invalid event image request.', 'tonys-sportspress-enhancements' ) );
	}

	if ( function_exists( 'asc_sp_event_team_ids' ) ) {
		$team_ids = asc_sp_event_team_ids( $post );
	} else {
		$team_ids = array();

		foreach ( get_post_meta( $post_id, 'sp_team', false ) as $team_id ) {
			while ( is_array( $team_id ) ) {
				$team_id = array_shift( array_filter( $team_id ) );
			}

			$team_id = absint( $team_id );
			if ( $team_id > 0 ) {
				$team_ids[] = $team_id;
			}
		}
	}

	$team_ids = array_values( array_unique( $team_ids ) );

	if ( count( $team_ids ) < 2 ) {
		return new WP_Error( 'missing_teams', __( 'Event image request is missing teams.', 'tonys-sportspress-enhancements' ) );
	}

	$team1_id = $team_ids[0];
	$team2_id = $team_ids[1];
	$team1    = get_post( $team1_id );
	$team2    = get_post( $team2_id );

	if ( ! $team1 || ! $team2 ) {
		return new WP_Error( 'invalid_teams', __( 'Event image request has invalid teams.', 'tonys-sportspress-enhancements' ) );
	}

	$settings     = asc_sp_event_image_get_settings();
	$team1_colors = get_post_meta( $team1_id, 'sp_colors', true );
	$team2_colors = get_post_meta( $team2_id, 'sp_colors', true );
	$team1_color  = is_array( $team1_colors ) && ! empty( $team1_colors['primary'] ) ? $team1_colors['primary'] : $settings['fallback_left_background'];
	$team2_color  = is_array( $team2_colors ) && ! empty( $team2_colors['primary'] ) ? $team2_colors['primary'] : $settings['fallback_right_background'];

	$team1_logo_thumbnail_id = get_post_thumbnail_id( $team1_id );
	$team2_logo_thumbnail_id = get_post_thumbnail_id( $team2_id );
	$team1_logo              = $team1_logo_thumbnail_id ? get_attached_file( $team1_logo_thumbnail_id ) : '';
	$team2_logo              = $team2_logo_thumbnail_id ? get_attached_file( $team2_logo_thumbnail_id ) : '';
	$team1_modified          = strtotime( $team1->post_modified );
	$team2_modified          = strtotime( $team2->post_modified );

	return array(
		'cache_key'      => 'team_image_v' . ASC_SP_EVENT_IMAGE_CACHE_VERSION . '_' . asc_sp_event_image_cache_style_hash() . "_{$variant}_{$team1_id}_{$team1_modified}-{$team2_id}_{$team2_modified}",
		'variant'        => $variant,
		'width'          => $dimensions['width'],
		'height'         => $dimensions['height'],
		'team1_color'    => asc_sp_event_image_color( $team1_color, $settings['fallback_left_background'] ),
		'team2_color'    => asc_sp_event_image_color( $team2_color, $settings['fallback_right_background'] ),
		'team1_logo'     => $team1_logo,
		'team2_logo'     => $team2_logo,
		'team1_fallback' => function_exists( 'asc_sp_team_short_name' ) ? asc_sp_team_short_name( $team1_id ) : get_the_title( $team1_id ),
		'team2_fallback' => function_exists( 'asc_sp_team_short_name' ) ? asc_sp_team_short_name( $team2_id ) : get_the_title( $team2_id ),
	);
}

/**
 * Handle the image endpoint request.
 */
function handle_image_request() {
	if ( ! isset( $_GET['post'] ) ) {
		return;
	}

	$request = asc_sp_event_prepare_image_request( asc_sp_event_image_request_post_id(), asc_sp_event_image_request_variant() );

	if ( is_wp_error( $request ) ) {
		asc_sp_event_image_not_found( $request->get_error_message() );
	}

	$cached_image_path = get_transient( $request['cache_key'] );

	if ( $cached_image_path && file_exists( $cached_image_path ) ) {
		serve_image( $cached_image_path );
		exit;
	}

	$image_data = generate_bisected_image(
		$request['team1_color'],
		$request['team2_color'],
		$request['team1_logo'],
		$request['team2_logo'],
		$request['team1_fallback'],
		$request['team2_fallback'],
		$request['width'],
		$request['height']
	);
	$image_path = save_image_to_cache( $image_data, $request['cache_key'] );

	set_transient( $request['cache_key'], $image_path, DAY_IN_SECONDS * 30 );
	serve_image( $image_path );
	exit;
}
add_action( 'template_redirect', 'handle_image_request' );

/**
 * Serve a cached image file.
 *
 * @param string $image_path Local image path.
 */
function serve_image( $image_path ) {
	if ( ! file_exists( $image_path ) ) {
		asc_sp_event_image_not_found();
	}

	status_header( 200 );
	header( 'Content-Type: image/png' );

	while ( ob_get_level() ) {
		ob_end_clean();
	}

	readfile( $image_path );
}

/**
 * Save generated image data to the upload cache.
 *
 * @param string $image_data Raw PNG bytes.
 * @param string $cache_key  Cache key.
 * @return string
 */
function save_image_to_cache( $image_data, $cache_key ) {
	$upload_dir = wp_get_upload_dir();
	$file_path  = trailingslashit( $upload_dir['path'] ) . sanitize_file_name( $cache_key ) . '.png';

	file_put_contents( $file_path, $image_data );

	return $file_path;
}
