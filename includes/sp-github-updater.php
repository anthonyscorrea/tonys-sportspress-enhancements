<?php
/**
 * GitHub release updater for the plugin.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TONY_SPORTSPRESS_ENHANCEMENTS_GITHUB_REPO' ) ) {
	define( 'TONY_SPORTSPRESS_ENHANCEMENTS_GITHUB_REPO', 'anthonyscorrea/tonys-sportspress-enhancements' );
}

if ( ! class_exists( 'Tony_Sportspress_GitHub_Updater' ) ) {
	/**
	 * Integrates WordPress plugin updates with GitHub Releases.
	 */
	class Tony_Sportspress_GitHub_Updater {
		/**
		 * GitHub API URL for the latest release.
		 *
		 * @var string
		 */
		private $release_api_url;

		/**
		 * Plugin basename.
		 *
		 * @var string
		 */
		private $plugin_basename;

		/**
		 * Plugin slug.
		 *
		 * @var string
		 */
		private $plugin_slug;

		/**
		 * Cache key for release metadata.
		 *
		 * @var string
		 */
		private $cache_key = 'tony_sportspress_github_release';

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->release_api_url = sprintf(
				'https://api.github.com/repos/%s/releases/latest',
				TONY_SPORTSPRESS_ENHANCEMENTS_GITHUB_REPO
			);
			$this->plugin_basename = TONY_SPORTSPRESS_ENHANCEMENTS_PLUGIN_BASENAME;
			$this->plugin_slug     = dirname( $this->plugin_basename );

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
			add_filter( 'upgrader_source_selection', array( $this, 'normalize_source_directory' ), 10, 4 );
			add_action( 'upgrader_process_complete', array( $this, 'purge_release_cache' ), 10, 2 );
		}

		/**
		 * Adds plugin update data to WordPress' update transient.
		 *
		 * @param stdClass $transient Existing update transient.
		 * @return stdClass
		 */
		public function inject_update( $transient ) {
			if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
				return $transient;
			}

			$release = $this->get_latest_release();

			if ( ! $release ) {
				return $transient;
			}

			$remote_version = $this->normalize_version( $release['version'] );
			$current_version = $this->normalize_version( TONY_SPORTSPRESS_ENHANCEMENTS_VERSION );

			if ( version_compare( $remote_version, $current_version, '<=' ) ) {
				return $transient;
			}

			$transient->response[ $this->plugin_basename ] = (object) array(
				'id'            => $release['url'],
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote_version,
				'url'           => $release['url'],
				'package'       => $release['package'],
				'tested'        => '',
				'requires_php'  => '',
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'translations'  => array(),
			);

			return $transient;
		}

		/**
		 * Provides plugin information for the update details modal.
		 *
		 * @param false|object|array $result Existing result.
		 * @param string             $action API action.
		 * @param object             $args   API args.
		 * @return false|object|array
		 */
		public function plugin_information( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
				return $result;
			}

			$release = $this->get_latest_release();

			if ( ! $release ) {
				return $result;
			}

			return (object) array(
				'name'          => 'Tonys SportsPress Enhancements',
				'slug'          => $this->plugin_slug,
				'version'       => $this->normalize_version( $release['version'] ),
				'author'        => '<a href="https://github.com/anthonyscorrea/">Tony Correa</a>',
				'author_profile'=> 'https://github.com/anthonyscorrea/',
				'homepage'      => $release['url'],
				'download_link' => $release['package'],
				'sections'      => array(
					'description' => wp_kses_post( wpautop( 'Suite of SportsPress Enhancements.' ) ),
					'changelog'   => wp_kses_post( wpautop( $release['body'] ) ),
				),
			);
		}

		/**
		 * Ensures GitHub's extracted directory name matches the installed plugin slug.
		 *
		 * @param string       $source        Source file location.
		 * @param string       $remote_source Remote file source location.
		 * @param WP_Upgrader  $upgrader      Upgrader instance.
		 * @param array        $hook_extra    Extra hook arguments.
		 * @return string|WP_Error
		 */
		public function normalize_source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
			global $wp_filesystem;

			if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {
				return $source;
			}

			$expected_dir = trailingslashit( $remote_source ) . $this->plugin_slug;

			if ( untrailingslashit( $source ) === untrailingslashit( $expected_dir ) ) {
				return $source;
			}

			if ( ! $wp_filesystem ) {
				return $source;
			}

			if ( $wp_filesystem->exists( $expected_dir ) ) {
				$wp_filesystem->delete( $expected_dir, true );
			}

			if ( ! $wp_filesystem->move( $source, $expected_dir ) ) {
				return new WP_Error(
					'tony_sportspress_updater_rename_failed',
					__( 'The plugin update package could not be prepared for installation.', 'tonys-sportspress-enhancements' )
				);
			}

			return $expected_dir;
		}

		/**
		 * Clears cached release metadata after plugin updates complete.
		 *
		 * @param WP_Upgrader $upgrader   Upgrader instance.
		 * @param array       $hook_extra Extra hook arguments.
		 * @return void
		 */
		public function purge_release_cache( $upgrader, $hook_extra ) {
			if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
				return;
			}

			if ( empty( $hook_extra['plugins'] ) || ! in_array( $this->plugin_basename, (array) $hook_extra['plugins'], true ) ) {
				return;
			}

			delete_site_transient( $this->cache_key );
		}

		/**
		 * Reads and caches the latest GitHub release metadata.
		 *
		 * @return array|null
		 */
		private function get_latest_release() {
			$cached = get_site_transient( $this->cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$response = wp_remote_get(
				$this->release_api_url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['html_url'] ) ) {
				return null;
			}

			$release = array(
				'version' => $data['tag_name'],
				'url'     => $data['html_url'],
				'body'    => isset( $data['body'] ) ? (string) $data['body'] : '',
				'package' => $this->determine_package_url( $data ),
			);

			if ( empty( $release['package'] ) ) {
				return null;
			}

			set_site_transient( $this->cache_key, $release, 6 * HOUR_IN_SECONDS );

			return $release;
		}

		/**
		 * Selects the best package URL from a release payload.
		 *
		 * @param array $release GitHub release payload.
		 * @return string
		 */
		private function determine_package_url( $release ) {
			if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
				$fallback_asset = '';

				foreach ( $release['assets'] as $asset ) {
					if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
						continue;
					}

					if ( '.zip' !== strtolower( substr( $asset['name'], -4 ) ) ) {
						continue;
					}

					if ( false !== strpos( $asset['name'], $this->plugin_slug ) ) {
						return $asset['browser_download_url'];
					}

					if ( empty( $fallback_asset ) ) {
						$fallback_asset = $asset['browser_download_url'];
					}
				}

				if ( ! empty( $fallback_asset ) ) {
					return $fallback_asset;
				}
			}

			if ( ! empty( $release['zipball_url'] ) ) {
				return $release['zipball_url'];
			}

			return '';
		}

		/**
		 * Normalizes release versions so Git tags like v1.2.3 compare correctly.
		 *
		 * @param string $version Version string.
		 * @return string
		 */
		private function normalize_version( $version ) {
			return ltrim( (string) $version, "vV \t\n\r\0\x0B" );
		}
	}

	new Tony_Sportspress_GitHub_Updater();
}
