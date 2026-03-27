<?php
/**
 * Tests for custom event permalink query behavior.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * Event permalink query tests.
 */
class Test_SP_Event_Permalink extends WP_UnitTestCase {

	/**
	 * Preserve global main query references for each test.
	 *
	 * @var WP_Query
	 */
	private $original_wp_query;

	/**
	 * Preserve global main query references for each test.
	 *
	 * @var WP_Query
	 */
	private $original_wp_the_query;

	/**
	 * Set up test case state.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_wp_query     = $GLOBALS['wp_query'];
		$this->original_wp_the_query = $GLOBALS['wp_the_query'];
		set_current_screen( 'front' );
	}

	/**
	 * Restore global query references.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_query']     = $this->original_wp_query;
		$GLOBALS['wp_the_query'] = $this->original_wp_the_query;
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The admin event list query should not be altered by permalink handling.
	 */
	public function test_admin_event_queries_are_not_modified() {
		set_current_screen( 'edit-sp_event' );

		$query = new WP_Query();
		$query->set( 'post_type', 'sp_event' );
		$query->set( 'p', 123 );
		$query->set( 'post_status', 'future' );

		custom_event_parse_request( $query );

		$this->assertSame( 'future', $query->get( 'post_status' ) );
		$this->assertSame( 123, $query->get( 'p' ) );
	}

	/**
	 * Front-end single event requests should include future posts.
	 */
	public function test_frontend_single_event_queries_include_future_posts() {
		$query = new WP_Query();
		$query->set( 'post_type', 'sp_event' );
		$query->set( 'p', 456 );

		$GLOBALS['wp_query']     = $query;
		$GLOBALS['wp_the_query'] = $query;

		custom_event_parse_request( $query );

		$this->assertSame( 'sp_event', $query->get( 'post_type' ) );
		$this->assertSame( 456, $query->get( 'p' ) );
		$this->assertSame( array( 'publish', 'future' ), $query->get( 'post_status' ) );
	}
}
