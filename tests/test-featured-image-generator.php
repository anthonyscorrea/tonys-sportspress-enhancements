<?php
/**
 * Tests for the SportsPress event image generator.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * Featured image generator tests.
 */
class Test_Featured_Image_Generator extends WP_UnitTestCase {

	/**
	 * Temp files created during a test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Clean up temp files.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->temp_files = array();
		parent::tear_down();
	}

	/**
	 * Create a post.
	 *
	 * @param string $type  Post type.
	 * @param string $title Title.
	 * @return int
	 */
	private function create_post_of_type( $type, $title ) {
		return self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Create a small raster fixture.
	 *
	 * @param string $extension File extension.
	 * @return string
	 */
	private function create_raster_fixture( $extension ) {
		$image = imagecreatetruecolor( 24, 24 );
		$red   = imagecolorallocate( $image, 200, 0, 0 );
		imagefilledrectangle( $image, 0, 0, 23, 23, $red );

		$file = tempnam( sys_get_temp_dir(), 'sp-img-' );
		$path = $file . '.' . $extension;
		rename( $file, $path );

		switch ( $extension ) {
			case 'jpg':
				imagejpeg( $image, $path );
				break;
			case 'gif':
				imagegif( $image, $path );
				break;
			case 'webp':
				imagewebp( $image, $path );
				break;
			case 'png':
			default:
				imagepng( $image, $path );
				break;
		}

		asc_sp_event_image_destroy( $image );

		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * Invalid IDs and non-event posts produce request errors.
	 */
	public function test_invalid_and_non_event_requests_prepare_404_errors() {
		$this->assertWPError( asc_sp_event_prepare_image_request( 999999 ) );

		$post_id = $this->create_post_of_type( 'post', 'Regular Post' );
		$error   = asc_sp_event_prepare_image_request( $post_id );

		$this->assertWPError( $error );
		$this->assertSame( 'invalid_event', $error->get_error_code() );
	}

	/**
	 * Missing team logo paths fall back to generated text and valid dimensions.
	 */
	public function test_missing_logo_path_generates_png_with_expected_dimensions() {
		$image_data = generate_bisected_image( '#123456', '#abcdef', '/missing-left.png', '/missing-right.png', 'Hawks', 'Electrons' );
		$image      = imagecreatefromstring( $image_data );

		$this->assertNotFalse( $image );
		$this->assertSame( 1200, imagesx( $image ) );
		$this->assertSame( 628, imagesy( $image ) );

		asc_sp_event_image_destroy( $image );
	}

	/**
	 * Square image variant generates square PNG dimensions.
	 */
	public function test_square_variant_generates_expected_dimensions() {
		$dimensions = asc_sp_event_image_variant_dimensions( 'square' );
		$image_data = generate_bisected_image( '#123456', '#abcdef', '/missing-left.png', '/missing-right.png', 'Hawks', 'Electrons', $dimensions['width'], $dimensions['height'] );
		$image      = imagecreatefromstring( $image_data );

		$this->assertNotFalse( $image );
		$this->assertSame( 1200, imagesx( $image ) );
		$this->assertSame( 1200, imagesy( $image ) );

		asc_sp_event_image_destroy( $image );
	}

	/**
	 * Raster loader supports common GD-backed formats.
	 */
	public function test_raster_loader_supports_common_formats_when_available() {
		$formats = array(
			'png' => 'imagecreatefrompng',
			'jpg' => 'imagecreatefromjpeg',
			'gif' => 'imagecreatefromgif',
		);

		if ( function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromwebp' ) ) {
			$formats['webp'] = 'imagecreatefromwebp';
		}

		foreach ( $formats as $extension => $function ) {
			if ( ! function_exists( $function ) ) {
				continue;
			}

			$path  = $this->create_raster_fixture( $extension );
			$image = asc_sp_event_image_create_from_file( $path );

			$this->assertNotFalse( $image, "Failed loading {$extension}" );
			$this->assertSame( 24, imagesx( $image ) );
			$this->assertSame( 24, imagesy( $image ) );
			asc_sp_event_image_destroy( $image );
		}
	}

	/**
	 * Bundled sporty font is available for fallback text.
	 */
	public function test_bundled_bebas_neue_font_is_available() {
		$this->assertFileExists( asc_sp_event_image_font_path() );
		$this->assertIsReadable( asc_sp_event_image_font_path() );
	}

	/**
	 * Prepared event request includes fallback text for missing logos.
	 */
	public function test_prepare_image_request_uses_team_short_name_fallbacks() {
		$team1 = $this->create_post_of_type( 'sp_team', 'Hawks' );
		$team2 = $this->create_post_of_type( 'sp_team', 'Electrons' );
		$event = $this->create_post_of_type( 'sp_event', 'Hawks vs Electrons' );

		add_post_meta( $event, 'sp_team', $team1 );
		add_post_meta( $event, 'sp_team', $team2 );

		$request = asc_sp_event_prepare_image_request( $event );

		$this->assertIsArray( $request );
		$this->assertSame( 'Hawks', $request['team1_fallback'] );
		$this->assertSame( 'Electrons', $request['team2_fallback'] );
		$this->assertSame( '', $request['team1_logo'] );
		$this->assertSame( '', $request['team2_logo'] );
	}

	/**
	 * Invalid colors are safely normalized.
	 */
	public function test_invalid_colors_fall_back_to_configured_defaults() {
		$this->assertSame( '#4B5563', asc_sp_event_image_color( 'not-a-color' ) );
		$this->assertSame( '#6B7280', asc_sp_event_image_color( 'not-a-color', '#6B7280' ) );
		$this->assertSame( '#112233', asc_sp_event_image_color( '#112233' ) );
	}

	/**
	 * Image cache keys include the generator version and style hash.
	 */
	public function test_prepare_image_request_uses_versioned_style_cache_key() {
		$team1 = $this->create_post_of_type( 'sp_team', 'Hawks' );
		$team2 = $this->create_post_of_type( 'sp_team', 'Electrons' );
		$event = $this->create_post_of_type( 'sp_event', 'Hawks vs Electrons' );

		add_post_meta( $event, 'sp_team', $team1 );
		add_post_meta( $event, 'sp_team', $team2 );

		$request = asc_sp_event_prepare_image_request( $event );

		$this->assertStringStartsWith( 'team_image_v' . ASC_SP_EVENT_IMAGE_CACHE_VERSION . '_' . asc_sp_event_image_cache_style_hash(), $request['cache_key'] );
		$this->assertSame( 'wide', $request['variant'] );
		$this->assertSame( 1200, $request['width'] );
		$this->assertSame( 628, $request['height'] );

		$square_request = asc_sp_event_prepare_image_request( $event, 'square' );

		$this->assertStringContainsString( '_square_', $square_request['cache_key'] );
		$this->assertSame( 'square', $square_request['variant'] );
		$this->assertSame( 1200, $square_request['width'] );
		$this->assertSame( 1200, $square_request['height'] );
	}
}
