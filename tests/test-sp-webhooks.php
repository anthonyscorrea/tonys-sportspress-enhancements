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
		$template = 'Trigger={{ trigger.key }} Team={{ event.teams.0.name }} Payload={{ event|tojson }}';
		$context  = array(
			'trigger' => array(
				'key' => 'event_results_updated',
			),
			'event'   => array(
				'id'    => 55,
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
		$this->assertStringContainsString( '"id":55', $rendered );
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
