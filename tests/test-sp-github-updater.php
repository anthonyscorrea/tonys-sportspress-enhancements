<?php
/**
 * Tests for the GitHub updater.
 *
 * @package Tonys_Sportspress_Enhancements
 */

/**
 * GitHub updater tests.
 */
class Test_SP_GitHub_Updater extends WP_UnitTestCase {

	/**
	 * Updater should prefer WordPress' checked plugin version.
	 */
	public function test_current_version_uses_update_transient_checked_version() {
		$updater   = new Tony_Sportspress_GitHub_Updater();
		$transient = (object) array(
			'checked' => array(
				TONY_SPORTSPRESS_ENHANCEMENTS_PLUGIN_BASENAME => 'v9.8.7',
			),
		);
		$method    = new ReflectionMethod( $updater, 'get_current_version' );
		$method->setAccessible( true );

		$this->assertSame( '9.8.7', $method->invoke( $updater, $transient ) );
	}

	/**
	 * Updater should fall back to the runtime constant when checked data is missing.
	 */
	public function test_current_version_falls_back_to_constant() {
		$updater   = new Tony_Sportspress_GitHub_Updater();
		$transient = (object) array();
		$method    = new ReflectionMethod( $updater, 'get_current_version' );
		$method->setAccessible( true );

		$this->assertSame( TONY_SPORTSPRESS_ENHANCEMENTS_VERSION, $method->invoke( $updater, $transient ) );
	}

	/**
	 * Single-plugin update completions should clear cached release metadata.
	 */
	public function test_purge_release_cache_accepts_single_plugin_payload() {
		$updater = new Tony_Sportspress_GitHub_Updater();
		set_site_transient( 'tony_sportspress_github_release', array( 'version' => '9.9.9' ), HOUR_IN_SECONDS );

		$updater->purge_release_cache(
			null,
			array(
				'type'   => 'plugin',
				'plugin' => TONY_SPORTSPRESS_ENHANCEMENTS_PLUGIN_BASENAME,
			)
		);

		$this->assertFalse( get_site_transient( 'tony_sportspress_github_release' ) );
	}
}
