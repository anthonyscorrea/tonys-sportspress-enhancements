<?php
/**
 * Tests for schedule exporter and printable schedule helpers.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * Schedule exporter tests.
 */
class Test_SP_Schedule_Exporter extends WP_UnitTestCase {

	/**
	 * Original request globals.
	 *
	 * @var array
	 */
	private $original_get = array();

	/**
	 * Set up shared test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_get = $_GET;

		foreach ( array( 'sp_venue', 'sp_league', 'sp_season' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy( $taxonomy, 'sp_event' );
			}
		}
	}

	/**
	 * Restore request globals.
	 */
	public function tear_down() {
		$_GET = $this->original_get;

		parent::tear_down();
	}

	/**
	 * Create a SportsPress team post.
	 *
	 * @param string $name Team name.
	 * @return int
	 */
	private function create_team( $name ) {
		return self::factory()->post->create(
			array(
				'post_type'   => 'sp_team',
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
	}

	/**
	 * Create a SportsPress event with ordered teams.
	 *
	 * @param int   $home_id Home team ID.
	 * @param int   $away_id Away team ID.
	 * @param int[] $venue_ids Venue IDs.
	 * @return int
	 */
	private function create_event( $home_id, $away_id, $venue_ids = array() ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'post_title'     => 'Game',
				'post_date'      => '2026-05-20 18:00:00',
				'post_date_gmt'  => '2026-05-20 23:00:00',
			)
		);

		add_post_meta( $event_id, 'sp_team', (string) $home_id );
		add_post_meta( $event_id, 'sp_team', (string) $away_id );

		if ( ! empty( $venue_ids ) ) {
			wp_set_object_terms( $event_id, $venue_ids, 'sp_venue' );
		}

		return $event_id;
	}

	/**
	 * Multiple selected team IDs should be retained in request order.
	 */
	public function test_resolve_team_ids_accepts_multiple_request_values() {
		$team_one = $this->create_team( 'Blue' );
		$team_two = $this->create_team( 'Red' );
		$teams    = array( get_post( $team_one ), get_post( $team_two ) );

		$_GET['team_id'] = array( (string) $team_one, (string) $team_two );

		$this->assertSame( array( $team_one, $team_two ), tse_sp_schedule_exporter_resolve_team_ids( $teams ) );
	}

	/**
	 * Printable URLs should carry multiple team IDs and the selected field.
	 */
	public function test_printable_url_accepts_multiple_teams_and_field() {
		$url   = tse_sp_schedule_exporter_get_printable_url( array( 12, 34 ), 56, 'letter', 78, false, 90, true );
		$query = array();

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '12,34', $query['sp_team'] );
		$this->assertSame( '90', $query['sp_field'] );
		$this->assertSame( 'name', $query['team_label'] );
		$this->assertSame( 'name', $query['field_label'] );
		$this->assertSame( '1', $query['month_pages'] );
	}

	/**
	 * Printable URLs should carry selected team and field label modes.
	 */
	public function test_printable_url_accepts_label_modes() {
		$url   = tse_sp_schedule_exporter_get_printable_url( 12, 56, 'letter', 78, false, 90, false, 'shortname', 'abbreviation' );
		$query = array();

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'shortname', $query['team_label'] );
		$this->assertSame( 'abbreviation', $query['field_label'] );
	}

	/**
	 * Single-team printable entries should keep the existing opponent perspective.
	 */
	public function test_printable_single_team_entries_keep_opponent_perspective() {
		$home_id  = $this->create_team( 'Home Team' );
		$away_id  = $this->create_team( 'Away Team' );
		$venue_id = self::factory()->term->create( array( 'taxonomy' => 'sp_venue', 'name' => 'North Field' ) );
		$this->create_event( $home_id, $away_id, array( $venue_id ) );

		$printable = Tony_Sportspress_Printable_Calendars::instance();
		$method    = new ReflectionMethod( $printable, 'get_schedule_entries' );
		$method->setAccessible( true );

		$entries = $method->invoke( $printable, $home_id, 0, 0, array() );

		$this->assertCount( 1, $entries );
		$this->assertFalse( $entries[0]['is_matchup'] );
		$this->assertTrue( $entries[0]['is_home'] );
		$this->assertSame( 'Away Team', $entries[0]['opponent_name'] );
	}

	/**
	 * Printable entries should honor requested team and field labels.
	 */
	public function test_printable_entries_honor_label_modes() {
		$home_id  = $this->create_team( 'Home Team' );
		$away_id  = $this->create_team( 'Away Team' );
		$venue_id = self::factory()->term->create( array( 'taxonomy' => 'sp_venue', 'name' => 'North Field' ) );
		update_post_meta( $away_id, 'sp_abbreviation', 'AWY' );
		update_term_meta( $venue_id, 'tse_abbreviation', 'NF' );
		$this->create_event( $home_id, $away_id, array( $venue_id ) );

		$printable = Tony_Sportspress_Printable_Calendars::instance();
		$method    = new ReflectionMethod( $printable, 'get_schedule_entries' );
		$method->setAccessible( true );

		$entries = $method->invoke( $printable, $home_id, 0, 0, array(), 'abbreviation', 'abbreviation' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'AWY', $entries[0]['opponent_name'] );
		$this->assertSame( 'NF', $entries[0]['venue_label'] );
	}

	/**
	 * Multi-team printable entries should return one matchup row per event.
	 */
	public function test_printable_multi_team_entries_are_matchup_rows() {
		$home_id  = $this->create_team( 'Home Team' );
		$away_id  = $this->create_team( 'Away Team' );
		$venue_id = self::factory()->term->create( array( 'taxonomy' => 'sp_venue', 'name' => 'North Field' ) );
		$this->create_event( $home_id, $away_id, array( $venue_id ) );

		$printable = Tony_Sportspress_Printable_Calendars::instance();
		$method    = new ReflectionMethod( $printable, 'get_schedule_entries' );
		$method->setAccessible( true );

		$entries = $method->invoke( $printable, array( $home_id, $away_id ), 0, 0, array() );

		$this->assertCount( 1, $entries );
		$this->assertTrue( $entries[0]['is_matchup'] );
		$this->assertSame( 'Away Team', $entries[0]['away_team_name'] );
		$this->assertSame( 'Home Team', $entries[0]['home_team_name'] );
	}

	/**
	 * Exactly one selected field should suppress per-event venue labels.
	 */
	public function test_render_month_grid_can_suppress_event_venue_label() {
		$printable = Tony_Sportspress_Printable_Calendars::instance();
		$method    = new ReflectionMethod( $printable, 'render_month_grid' );
		$method->setAccessible( true );

		$entries = array(
			'2026-05-20' => array(
				array(
					'day_key'        => '2026-05-20',
					'month_key'      => '2026-05',
					'timestamp'      => strtotime( '2026-05-20 18:00:00' ),
					'is_matchup'     => true,
					'away_team_name' => 'Away',
					'home_team_name' => 'Home',
					'event_time'     => '6:00 PM',
					'venue_label'    => 'North',
					'venue_name'     => 'North Field',
					'venue_key'      => 'v:1',
				),
			),
		);

		ob_start();
		$method->invoke(
			$printable,
			'2026-05',
			$entries,
			array( 'v:1' => array( 'name' => 'North Field', 'color' => '#1D4ED8' ) ),
			array(
				'primary'   => '#1D4ED8',
				'secondary' => '#DC2626',
				'accent'    => '#1D4ED8',
				'ink'       => '#111827',
				'muted_ink' => '#334155',
			),
			true,
			true
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Away', $output );
		$this->assertStringContainsString( 'Home', $output );
		$this->assertStringNotContainsString( 'class="event-venue"', $output );
	}
}
