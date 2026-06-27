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
	 * Event REST responses should expose a series number by same matchup and season.
	 */
	public function test_event_rest_response_exposes_series_number_by_matchup_and_season() {
		$season_id       = self::factory()->term->create( array( 'taxonomy' => 'sp_season' ) );
		$other_season_id = self::factory()->term->create( array( 'taxonomy' => 'sp_season' ) );
		$hawks_id        = $this->create_team( 'Hawks' );
		$eagles_id       = $this->create_team( 'Eagles' );
		$falcons_id      = $this->create_team( 'Falcons' );

		$first_id = $this->create_event(
			'Hawks vs Eagles 1',
			array( $hawks_id, $eagles_id ),
			array( $season_id ),
			'2026-05-10 18:00:00'
		);
		$this->create_event(
			'Hawks vs Falcons',
			array( $hawks_id, $falcons_id ),
			array( $season_id ),
			'2026-05-11 18:00:00'
		);
		$second_id = $this->create_event(
			'Eagles vs Hawks 2',
			array( $eagles_id, $hawks_id ),
			array( $season_id ),
			'2026-05-12 18:00:00'
		);
		$this->create_event(
			'Hawks vs Eagles Other Season',
			array( $hawks_id, $eagles_id ),
			array( $other_season_id ),
			'2026-05-13 18:00:00'
		);

		wp_update_post(
			array(
				'ID'        => $first_id,
				'post_date' => '2026-06-01 18:00:00',
			)
		);

		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/sp_event/' . $second_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'series_number', $data );
		$this->assertArrayHasKey( 'series_numbers', $data );
		$this->assertSame( 2, $data['series_number'] );
		$this->assertSame( array( (string) $season_id => 2 ), $data['series_numbers'] );
	}

	/**
	 * Event REST responses should expose all series numbers when multiple seasons are attached.
	 */
	public function test_event_rest_response_exposes_series_numbers_for_multiple_seasons() {
		$season_id       = self::factory()->term->create( array( 'taxonomy' => 'sp_season' ) );
		$other_season_id = self::factory()->term->create( array( 'taxonomy' => 'sp_season' ) );
		$hawks_id        = $this->create_team( 'Hawks' );
		$eagles_id       = $this->create_team( 'Eagles' );

		$this->create_event(
			'Hawks vs Eagles Season One',
			array( $hawks_id, $eagles_id ),
			array( $season_id ),
			'2026-05-10 18:00:00'
		);
		$this->create_event(
			'Hawks vs Eagles Season Two',
			array( $hawks_id, $eagles_id ),
			array( $other_season_id ),
			'2026-05-11 18:00:00'
		);
		$event_id = $this->create_event(
			'Hawks vs Eagles Multi Season',
			array( $eagles_id, $hawks_id ),
			array( $season_id, $other_season_id ),
			'2026-05-12 18:00:00'
		);

		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/sp_event/' . $event_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $data['series_number'] );
		$this->assertSame(
			array(
				(string) $season_id       => 2,
				(string) $other_season_id => 2,
			),
			$data['series_numbers']
		);
	}

	/**
	 * Original scheduled datetime should be captured once for future use.
	 */
	public function test_original_scheduled_datetime_is_captured_once() {
		$hawks_id  = $this->create_team( 'Hawks' );
		$eagles_id = $this->create_team( 'Eagles' );
		$event_id  = $this->create_event(
			'Hawks vs Eagles',
			array( $hawks_id, $eagles_id ),
			array(),
			'2026-05-10 18:00:00'
		);

		$this->assertSame( '2026-05-10 18:00:00', get_post_meta( $event_id, '_tse_original_scheduled_datetime', true ) );

		wp_update_post(
			array(
				'ID'        => $event_id,
				'post_date' => '2026-06-01 18:00:00',
			)
		);

		$this->assertSame( '2026-05-10 18:00:00', get_post_meta( $event_id, '_tse_original_scheduled_datetime', true ) );
	}

	/**
	 * Venue REST responses should expose the SportsPress venue address.
	 */
	public function test_venue_rest_response_exposes_address() {
		$venue_id = self::factory()->term->create(
			array(
				'taxonomy' => 'sp_venue',
				'name'     => 'North Field',
			)
		);

		update_option(
			'taxonomy_' . $venue_id,
			array(
				'sp_address' => '123 Main Street, Chicago, IL',
			)
		);

		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/wp/v2/venues/' . $venue_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'address', $data );
		$this->assertSame( '123 Main Street, Chicago, IL', $data['address'] );
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

	/**
	 * Create a SportsPress team.
	 *
	 * @param string $name Team name.
	 * @return int
	 */
	private function create_team( $name ) {
		return self::factory()->post->create(
			array(
				'post_type'  => 'sp_team',
				'post_title' => $name,
			)
		);
	}

	/**
	 * Create a SportsPress event.
	 *
	 * @param string $title Event title.
	 * @param int[]  $team_ids Team IDs.
	 * @param int[]  $season_ids Season term IDs.
	 * @param string $post_date Event date.
	 * @return int
	 */
	private function create_event( $title, $team_ids, $season_ids, $post_date ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'  => 'sp_event',
				'post_title' => $title,
				'post_date'  => $post_date,
			)
		);

		foreach ( $team_ids as $team_id ) {
			add_post_meta( $event_id, 'sp_team', (string) $team_id );
		}

		if ( ! empty( $season_ids ) ) {
			wp_set_object_terms( $event_id, array_map( 'intval', $season_ids ), 'sp_season' );
		}

		return $event_id;
	}
}
