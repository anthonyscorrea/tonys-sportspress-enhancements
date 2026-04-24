<?php
/**
 * Tests for configurable SportsPress webhooks.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * Webhook feature tests.
 */
class Test_SP_Webhooks extends WP_UnitTestCase {

	/**
	 * Template placeholders should resolve nested values and JSON serialization.
	 */
	public function test_render_template_supports_dot_paths_and_tojson() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Trigger={{ trigger.key }} Team={{ event.teams.0.name }} Image={{ event.image }} Payload={{ event|tojson }}';
		$context  = array(
			'trigger' => array(
				'key' => 'event_results_updated',
			),
			'event'   => array(
				'id'    => 55,
				'image' => 'https://example.com/head-to-head?post=55',
				'teams' => array(
					array(
						'name' => 'Blue Team',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertStringContainsString( 'Trigger=event_results_updated', $rendered );
		$this->assertStringContainsString( 'Team=Blue Team', $rendered );
		$this->assertStringContainsString( 'Image=https://example.com/head-to-head?post=55', $rendered );
		$this->assertStringContainsString( '"id":55', $rendered );
	}

	/**
	 * Venue aliases and split schedule fields should render from the context.
	 */
	public function test_render_template_supports_field_alias_and_schedule_parts() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Field={{ event.field.short_name }} Venue={{ event.venue.abbreviation }} Time={{ event.scheduled.time }} {{ event.scheduled.timezone }}';
		$context  = array(
			'event' => array(
				'field'     => array(
					'short_name' => 'North',
				),
				'venue'     => array(
					'abbreviation' => 'NF',
				),
				'scheduled' => array(
					'time'     => '7:30 PM',
					'timezone' => 'CDT',
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Field=North Venue=NF Time=7:30 PM CDT', $rendered );
	}

	/**
	 * Event context should expose home and away team aliases.
	 */
	public function test_render_template_supports_event_team_aliases() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Home={{ event.home_team.name }} Away={{ event.away_team.name }}';
		$context  = array(
			'event' => array(
				'home_team' => array(
					'name' => 'Home Team',
				),
				'away_team' => array(
					'name' => 'Away Team',
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Home=Home Team Away=Away Team', $rendered );
	}

	/**
	 * Event context should expose the current SportsPress schedule status.
	 */
	public function test_render_template_supports_event_status_alias() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Status={{ event.status }}';
		$context  = array(
			'event' => array(
				'status' => 'Postponed',
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Status=Postponed', $rendered );
	}

	/**
	 * Date filter should accept PHP date format strings for schedule values.
	 */
	public function test_render_template_supports_date_filter() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Time={{ event.scheduled.timestamp|date("g:i A") }} ISO={{ event.scheduled.local_iso|date("m/d g:i A") }}';
		$context  = array(
			'event' => array(
				'scheduled' => array(
					'timestamp' => 1714005000,
					'local_iso' => '2024-04-24T19:30:00-05:00',
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Time=7:30 PM ISO=04/24 7:30 PM', $rendered );
	}

	/**
	 * Change notifications should expose before and after venue/time values.
	 */
	public function test_render_template_supports_before_after_venue_and_time() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Venue {{ changes.previous.venue.name }} -> {{ changes.current.venue.name }} Time {{ changes.previous.time }} -> {{ changes.current.time }}';
		$context  = array(
			'changes' => array(
				'previous' => array(
					'time'  => '6:00 PM',
					'venue' => array(
						'name' => 'North Field',
					),
				),
				'current'  => array(
					'time'  => '7:30 PM',
					'venue' => array(
						'name' => 'South Field',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Venue North Field -> South Field Time 6:00 PM -> 7:30 PM', $rendered );
	}

	/**
	 * Change notifications should expose before and after home/away team values.
	 */
	public function test_render_template_supports_before_after_teams() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Home {{ changes.previous.home_team.name }} -> {{ changes.current.home_team.name }} Away {{ changes.previous.away_team.name }} -> {{ changes.current.away_team.name }}';
		$context  = array(
			'changes' => array(
				'previous' => array(
					'home_team' => array(
						'name' => 'Old Home',
					),
					'away_team' => array(
						'name' => 'Old Away',
					),
				),
				'current'  => array(
					'home_team' => array(
						'name' => 'New Home',
					),
					'away_team' => array(
						'name' => 'New Away',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Home Old Home -> New Home Away Old Away -> New Away', $rendered );
	}

	/**
	 * Change notifications should expose before and after status values.
	 */
	public function test_render_template_supports_before_after_status() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = 'Status {{ changes.previous.status }} -> {{ changes.current.status }}';
		$context  = array(
			'changes' => array(
				'previous' => array(
					'status' => 'On time',
				),
				'current'  => array(
					'status' => 'Postponed',
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Status On time -> Postponed', $rendered );
	}

	/**
	 * Schedule snapshots should treat status changes as meaningful changes.
	 */
	public function test_schedule_snapshot_signature_changes_when_status_changes() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$method   = new ReflectionMethod( $webhooks, 'schedule_snapshots_match' );
		$method->setAccessible( true );

		$left = array(
			'local_iso' => '2026-05-02T10:30:00-05:00',
			'gmt_iso'   => '2026-05-02T15:30:00+00:00',
			'status'    => 'On time',
			'venue'     => array(
				'name' => 'Winnemac Park',
			),
			'teams'     => array(
				array( 'name' => 'Hawks' ),
				array( 'name' => 'Electrons' ),
			),
		);
		$right = $left;
		$right['status'] = 'Canceled';

		$this->assertFalse( $method->invoke( $webhooks, $left, $right ) );
	}

	/**
	 * Status snapshots should expose a display label and keep the raw SportsPress key.
	 */
	public function test_build_change_snapshot_normalizes_status_label_and_slug() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$method   = new ReflectionMethod( $webhooks, 'build_change_snapshot' );
		$method->setAccessible( true );

		$snapshot = $method->invoke( $webhooks, array(), array(), array(), 'cancelled' );

		$this->assertSame( 'Canceled', $snapshot['status'] );
		$this->assertSame( 'cancelled', $snapshot['sp_status'] );
	}

	/**
	 * Conditionals should support simple comparisons and else branches.
	 */
	public function test_render_template_supports_conditionals() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = "{% if changes.previous.time != changes.current.time %}Time changed: {{ changes.previous.time }} -> {{ changes.current.time }}\n{% endif %}{% if changes.previous.field.name == changes.current.field.name %}Field unchanged{% else %}Field changed: {{ changes.previous.field.name }} -> {{ changes.current.field.name }}{% endif %}";
		$context  = array(
			'changes' => array(
				'previous' => array(
					'time'  => '6:00 PM',
					'field' => array(
						'name' => 'North Field',
					),
				),
				'current'  => array(
					'time'  => '7:30 PM',
					'field' => array(
						'name' => 'South Field',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( "Time changed: 6:00 PM -> 7:30 PM\nField changed: North Field -> South Field", $rendered );
	}

	/**
	 * Truthy conditionals should render when the referenced value exists.
	 */
	public function test_render_template_supports_truthy_conditionals() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = '{% if changes.current.home_team.name %}Home: {{ changes.current.home_team.name }}{% endif %}';
		$context  = array(
			'changes' => array(
				'current' => array(
					'home_team' => array(
						'name' => 'Home Team',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Home: Home Team', $rendered );
	}

	/**
	 * Team change conditionals should stay false when only schedule fields changed.
	 */
	public function test_team_conditionals_do_not_fire_for_schedule_only_changes() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = '{% if changes.previous.away_team.name != changes.current.away_team.name %}Away changed{% else %}Away unchanged{% endif %}';
		$context  = array(
			'changes' => array(
				'previous' => array(
					'time'      => '6:00 PM',
					'away_team' => array(
						'name' => 'Away Team',
					),
				),
				'current'  => array(
					'time'      => '7:30 PM',
					'away_team' => array(
						'name' => 'Away Team',
					),
				),
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Away unchanged', $rendered );
	}

	/**
	 * Conditionals should support simple or expressions with else branches.
	 */
	public function test_render_template_supports_or_conditionals() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$template = '{% if event.status == "Postponed" or event.status == "Canceled" %}Delayed{% else %}Normal{% endif %}';
		$context  = array(
			'event' => array(
				'status' => 'Canceled',
			),
		);

		$rendered = $webhooks->render_template( $template, $context );

		$this->assertSame( 'Delayed', $rendered );
	}

	/**
	 * Test webhook AJAX should honor the submitted row index.
	 */
	public function test_get_submitted_test_webhook_row_uses_matching_index() {
		$webhooks = Tony_Sportspress_Webhooks::instance();
		$method   = new ReflectionMethod( $webhooks, 'get_submitted_test_webhook_row' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$webhooks,
			array(
				'2' => array(
					'name' => 'Second Row',
				),
			),
			array(
				'2' => '123',
			)
		);

		$this->assertSame( 'Second Row', $result['row']['name'] );
		$this->assertSame( 123, $result['event_id'] );
	}

	/**
	 * Sanitization should keep only complete provider-specific webhook rows.
	 */
	public function test_sanitize_settings_keeps_only_valid_webhooks() {
		$webhooks  = Tony_Sportspress_Webhooks::instance();
		$sanitized = $webhooks->sanitize_settings(
			array(
				'webhooks' => array(
					array(
						'name'     => 'Results',
						'enabled'  => '1',
						'provider' => 'google_chat',
						'url'      => 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=test&token=test',
						'triggers' => array( 'event_results_updated' ),
						'template' => '{"summary":"{{ results.summary }}"}',
					),
					array(
						'name'     => 'Invalid',
						'enabled'  => '1',
						'provider' => 'groupme_bot',
						'url'      => 'invalid bot id',
						'triggers' => array( 'event_datetime_changed' ),
						'template' => 'ignored',
					),
					array(
						'name'     => 'Missing trigger',
						'enabled'  => '1',
						'provider' => 'generic_json',
						'url'      => 'https://example.com/missing-trigger',
						'template' => 'ignored',
					),
				),
			)
		);

		$this->assertCount( 1, $sanitized['webhooks'] );
		$this->assertSame( 'Results', $sanitized['webhooks'][0]['name'] );
		$this->assertSame( 'google_chat', $sanitized['webhooks'][0]['provider'] );
		$this->assertSame( 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=test&token=test', $sanitized['webhooks'][0]['url'] );
		$this->assertSame( array( 'event_results_updated' ), $sanitized['webhooks'][0]['triggers'] );
	}
}
