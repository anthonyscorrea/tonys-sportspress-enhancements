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

	/**
	 * Event REST responses should expose the SportsPress event status.
	 *
	 * @dataProvider event_status_provider
	 *
	 * @param string $stored_status Stored SportsPress status.
	 * @param string $expected_status Expected REST status.
	 */
	public function test_event_rest_response_exposes_event_status( $stored_status, $expected_status ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'  => 'sp_event',
				'post_title' => 'Hawks vs Eagles',
			)
		);

		if ( '' !== $stored_status ) {
			update_post_meta( $event_id, 'sp_status', $stored_status );
		}

		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/sp_event/' . $event_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'event_status', $data );
		$this->assertSame( $expected_status, $data['event_status'] );
	}

	/**
	 * Event status provider.
	 *
	 * @return array
	 */
	public function event_status_provider() {
		return array(
			'empty status defaults to on-time' => array( '', 'on-time' ),
			'ok maps to on-time'              => array( 'ok', 'on-time' ),
			'postponed passes through'        => array( 'postponed', 'postponed' ),
			'cancelled passes through'        => array( 'cancelled', 'cancelled' ),
		);
	}
}
