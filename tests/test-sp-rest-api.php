<?php
/**
 * Tests for SportsPress REST API extensions.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * REST API test case.
 */
class Test_SP_Rest_Api extends WP_UnitTestCase {

	/**
	 * Team REST responses should expose the short_name field.
	 */
	public function test_team_rest_response_exposes_short_name() {
		$team_id = self::factory()->post->create(
			array(
				'post_type'  => 'sp_team',
				'post_title' => 'Hawks',
			)
		);

		update_post_meta( $team_id, 'sp_short_name', 'HWK' );

		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/sp_team/' . $team_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'short_name', $data );
		$this->assertSame( 'HWK', $data['short_name'] );
	}
}
