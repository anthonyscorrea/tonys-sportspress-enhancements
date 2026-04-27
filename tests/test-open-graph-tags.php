<?php
/**
 * Tests for SportsPress event Open Graph output.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! class_exists( 'SP_Event' ) ) {
	/**
	 * Minimal SportsPress event test double.
	 */
	class SP_Event {
		/**
		 * Event post ID.
		 *
		 * @var int
		 */
		private $id;

		/**
		 * Status values by event ID.
		 *
		 * @var array<int,string>
		 */
		public static $statuses = array();

		/**
		 * Result values by event ID.
		 *
		 * @var array<int,array>
		 */
		public static $results = array();

		/**
		 * Constructor.
		 *
		 * @param int $id Event post ID.
		 */
		public function __construct( $id ) {
			$this->id = absint( $id );
		}

		/**
		 * Get event status.
		 *
		 * @return string
		 */
		public function status() {
			return self::$statuses[ $this->id ] ?? '';
		}

		/**
		 * Get event results.
		 *
		 * @return array
		 */
		public function results() {
			return self::$results[ $this->id ] ?? array();
		}
	}
}

/**
 * Open Graph tests.
 */
class Test_Open_Graph_Tags extends WP_UnitTestCase {

	/**
	 * Reset mock SportsPress state.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( property_exists( 'SP_Event', 'statuses' ) ) {
			SP_Event::$statuses = array();
			SP_Event::$results  = array();
		}

		update_option( 'sportspress_event_reverse_teams', 'no' );
		update_option( 'sportspress_event_teams_delimiter', 'vs' );
	}

	/**
	 * Create a team.
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
	 * Create an event.
	 *
	 * @param array $args Post args.
	 * @return int
	 */
	private function create_event( array $args = array() ) {
		return self::factory()->post->create(
			wp_parse_args(
				$args,
				array(
					'post_type'    => 'sp_event',
					'post_title'   => 'Test Event',
					'post_status'  => 'future',
					'post_date'    => '2026-05-02 13:00:00',
					'post_content' => 'First pitch at one.',
				)
			)
		);
	}

	/**
	 * Future event emits complete Open Graph data.
	 */
	public function test_future_event_emits_core_open_graph_values() {
		$home  = $this->create_team( 'Hawks' );
		$away  = $this->create_team( 'Electrons' );
		$event = $this->create_event();

		add_post_meta( $event, 'sp_team', $home );
		add_post_meta( $event, 'sp_team', $away );

		if ( property_exists( 'SP_Event', 'statuses' ) ) {
			SP_Event::$statuses[ $event ] = 'future';
		}

		$meta = asc_sp_event_open_graph_data( $event );

		$this->assertSame( 'article', $meta['type'] );
		$this->assertStringContainsString( 'Hawks vs Electrons', $meta['title'] );
		$this->assertStringContainsString( 'First pitch at one.', $meta['description'] );
		$this->assertCount( 2, $meta['images'] );
		$this->assertSame( '1200', $meta['images'][0]['width'] );
		$this->assertSame( '628', $meta['images'][0]['height'] );
		$this->assertSame( '1200', $meta['images'][1]['width'] );
		$this->assertSame( '1200', $meta['images'][1]['height'] );
		$this->assertSame( '1200', $meta['image_width'] );
		$this->assertSame( '628', $meta['image_height'] );
		$this->assertStringContainsString( '/head-to-head?post=' . $event, $meta['image'] );
		$this->assertStringContainsString( 'variant=square', $meta['images'][1]['url'] );
		$this->assertNotEmpty( $meta['url'] );
	}

	/**
	 * Postponed, cancelled, and TBD labels appear in title and description.
	 *
	 * @dataProvider status_provider
	 *
	 * @param string $status Status slug.
	 */
	public function test_schedule_status_appears_in_title_and_description( $status ) {
		$home  = $this->create_team( 'Hawks' );
		$away  = $this->create_team( 'Electrons' );
		$event = $this->create_event();

		add_post_meta( $event, 'sp_team', $home );
		add_post_meta( $event, 'sp_team', $away );
		update_post_meta( $event, 'sp_status', $status );

		$meta  = asc_sp_event_open_graph_data( $event );
		$label = strtoupper( $status );

		$this->assertStringStartsWith( $label, $meta['title'] );
		$this->assertStringStartsWith( $label, $meta['description'] );
	}

	/**
	 * Status provider.
	 *
	 * @return array
	 */
	public function status_provider() {
		return array(
			array( 'postponed' ),
			array( 'cancelled' ),
			array( 'tbd' ),
		);
	}

	/**
	 * Result events with scores emit score titles.
	 */
	public function test_result_event_with_scores_emits_score_title() {
		$home  = $this->create_team( 'Hawks' );
		$away  = $this->create_team( 'Electrons' );
		$event = $this->create_event( array( 'post_status' => 'publish' ) );

		add_post_meta( $event, 'sp_team', $home );
		add_post_meta( $event, 'sp_team', $away );

		if ( property_exists( 'SP_Event', 'statuses' ) ) {
			SP_Event::$statuses[ $event ] = 'results';
			SP_Event::$results[ $event ]  = array(
				0     => array( 'r' => 'R' ),
				$home => array( 'r' => '7' ),
				$away => array( 'r' => '4' ),
			);
		}

		$meta = asc_sp_event_open_graph_data( $event );

		$this->assertStringContainsString( 'Hawks 7-4 Electrons', $meta['title'] );
	}

	/**
	 * Missing teams/results/outcomes still produce valid data.
	 */
	public function test_missing_sportspress_data_does_not_break_meta_generation() {
		$event = $this->create_event(
			array(
				'post_title'   => 'Sparse Event',
				'post_content' => '',
			)
		);

		if ( property_exists( 'SP_Event', 'statuses' ) ) {
			SP_Event::$statuses[ $event ] = 'results';
			SP_Event::$results[ $event ]  = array();
		}

		$meta = asc_sp_event_open_graph_data( $event );

		$this->assertSame( 'Sparse Event', $meta['title'] );
		$this->assertNotEmpty( $meta['description'] );
		$this->assertSame( '1200', $meta['image_width'] );
	}

	/**
	 * HTML-heavy post content is stripped and escaped in rendered tags.
	 */
	public function test_description_strips_html_and_rendered_tags_are_escaped() {
		$home  = $this->create_team( 'Hawks "A"' );
		$away  = $this->create_team( 'Electrons <B>' );
		$event = $this->create_event(
			array(
				'post_content' => '<script>alert("x")</script><p>Bring <strong>bats</strong> & gloves.</p>',
			)
		);

		add_post_meta( $event, 'sp_team', $home );
		add_post_meta( $event, 'sp_team', $away );

		$meta = asc_sp_event_open_graph_data( $event );

		$this->assertStringNotContainsString( '<script', $meta['description'] );
		$this->assertStringContainsString( 'Bring bats & gloves.', $meta['description'] );

		$GLOBALS['post']              = get_post( $event );
		$GLOBALS['wp_query']->is_single = true;

		ob_start();
		custom_open_graph_tags_with_sportspress_integration();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'og:image:width', $output );
		$this->assertSame( 2, substr_count( $output, 'property="og:image" content=' ) );
		$this->assertStringContainsString( 'content="628"', $output );
		$this->assertStringContainsString( 'content="1200"', $output );
		$this->assertStringContainsString( 'variant=square', $output );
		$this->assertStringContainsString( 'Hawks &quot;A&quot;', $output );
		$this->assertStringNotContainsString( '<B>', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}
}
