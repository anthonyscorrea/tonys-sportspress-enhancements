<?php
/**
 * Printable calendar views for SportsPress teams.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tony_Sportspress_Printable_Calendars' ) ) {
	/**
	 * Printable SportsPress calendar feature.
	 */
	class Tony_Sportspress_Printable_Calendars {

		/**
		 * Printable calendar query flag.
		 */
		const QUERY_FLAG = 'asc_sp_print';

		/**
		 * Settings option key preserved from the standalone plugin.
		 */
		const OPTION_KEY = 'asc_sp_printable_calendars_settings';

		/**
		 * Settings group.
		 */
		const OPTION_GROUP = 'asc_sp_printable_calendars';

		/**
		 * Settings page slug preserved from the standalone plugin.
		 */
		const PAGE_SLUG = 'tonys-sportspress-settings';

		/**
		 * Printable calendars tab key.
		 */
		const TAB_PRINTABLE = 'printable-calendars';

		/**
		 * Minimum contrast ratio against white.
		 */
		const MIN_WHITE_CONTRAST = 4.8;

		/**
		 * Singleton instance.
		 *
		 * @var Tony_Sportspress_Printable_Calendars|null
		 */
		private static $instance = null;

		/**
		 * Allowed paper sizes.
		 *
		 * @var string[]
		 */
		private $allowed_paper_sizes = array( 'letter', 'ledger', '11x17' );

		/**
		 * Allowed refresh intervals.
		 *
		 * @var int[]
		 */
		private $allowed_intervals = array( 15, 30, 60, 120 );

		/**
		 * Suggested venue palette.
		 *
		 * @var string[]
		 */
		private $suggested_palette = array(
			'#1D4ED8', '#DC2626', '#A16207', '#15803D', '#0E7490', '#BE185D',
			'#6D28D9', '#C2410C', '#334155', '#0369A1', '#B91C1C', '#166534',
		);

		/**
		 * Whether hooks were registered.
		 *
		 * @var bool
		 */
		private $booted = false;

		/**
		 * Get singleton instance.
		 *
		 * @return Tony_Sportspress_Printable_Calendars
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Bootstrap the feature.
		 */
		public function boot() {
			if ( $this->booted ) {
				return;
			}

			$this->booted = true;

			if ( ! $this->sportspress_available() ) {
				if ( is_admin() ) {
					add_action( 'admin_notices', array( $this, 'render_missing_dependency_notice' ) );
				}
				return;
			}

			add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
			add_action( 'template_redirect', array( $this, 'maybe_render' ) );
			add_action( 'sportspress_after_single_team', array( $this, 'render_team_page_cta' ) );

			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
				add_action( 'admin_init', array( $this, 'register_settings' ) );
				add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
			}
		}

		/**
		 * Show dependency notice.
		 */
		public function render_missing_dependency_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Printable calendars require SportsPress to be installed and active.', 'tonys-sportspress-enhancements' );
			echo '</p></div>';
		}

		/**
		 * Register query vars for the printable route.
		 *
		 * @param array $vars Existing vars.
		 * @return array
		 */
		public function register_query_vars( $vars ) {
			$vars[] = self::QUERY_FLAG;
			$vars[] = 'sp_team';
			$vars[] = 'sp_season';
			$vars[] = 'sp_league';
			$vars[] = 'sp_field';
			$vars[] = 'team_label';
			$vars[] = 'field_label';
			$vars[] = 'title_format';
			$vars[] = 'paper';
			$vars[] = 'autoprint';
			$vars[] = 'month_pages';

			return $vars;
		}

		/**
		 * Render team CTA.
		 */
		public function render_team_page_cta() {
			$team_id = get_the_ID();
			if ( ! is_int( $team_id ) || $team_id <= 0 || 'sp_team' !== get_post_type( $team_id ) ) {
				return;
			}

			$season_id = absint( (string) get_option( 'sportspress_season', '0' ) );
			$link      = $this->build_url( $team_id, $season_id, '11x17' );

			echo '<p class="asc-sp-printable-schedule-link">';
			echo '<a class="button" href="' . esc_url( $link ) . '">';
			echo esc_html__( 'Printable Schedule', 'tonys-sportspress-enhancements' );
			echo '</a>';
			echo '</p>';
		}

		/**
		 * Register plugin settings.
		 */
		public function register_settings() {
			register_setting(
				self::OPTION_GROUP,
				self::OPTION_KEY,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
					'default'           => $this->default_settings(),
				)
			);
		}

		/**
		 * Add settings page.
		 */
		public function add_settings_page() {
			add_submenu_page(
				'sportspress',
				__( 'Tony\'s Settings', 'tonys-sportspress-enhancements' ),
				__( 'Tony\'s Settings', 'tonys-sportspress-enhancements' ),
				'manage_sportspress',
				self::PAGE_SLUG,
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Capability required to save this settings group.
		 *
		 * @return string
		 */
		public function settings_capability() {
			return 'manage_sportspress';
		}

		/**
		 * Sanitize settings.
		 *
		 * @param mixed $input Raw input.
		 * @return array
		 */
		public function sanitize_settings( $input ) {
			$existing = wp_parse_args(
				get_option( self::OPTION_KEY, array() ),
				$this->default_settings()
			);

			$current          = is_array( $input ) ? $input : array();
			$active_season_id = isset( $current['active_season_id'] ) ? absint( (string) $current['active_season_id'] ) : 0;

			return array(
				'calendar_feed_url'     => isset( $existing['calendar_feed_url'] ) && is_string( $existing['calendar_feed_url'] ) ? $existing['calendar_feed_url'] : '',
				'sync_interval_minutes' => isset( $existing['sync_interval_minutes'] ) ? absint( (string) $existing['sync_interval_minutes'] ) : 60,
				'venue_color_overrides' => $this->merge_sanitized_season_settings(
					isset( $existing['venue_color_overrides'] ) && is_array( $existing['venue_color_overrides'] ) ? $existing['venue_color_overrides'] : array(),
					$this->sanitize_venue_color_overrides( isset( $current['venue_color_overrides'] ) ? $current['venue_color_overrides'] : array() ),
					$active_season_id,
					true
				),
				'venue_use_team_primary' => $this->merge_sanitized_season_settings(
					isset( $existing['venue_use_team_primary'] ) && is_array( $existing['venue_use_team_primary'] ) ? $existing['venue_use_team_primary'] : array(),
					$this->sanitize_venue_primary_flags( isset( $current['venue_use_team_primary'] ) ? $current['venue_use_team_primary'] : array() ),
					$active_season_id,
					false
				),
				'printable_venue_label_mode' => $this->sanitize_venue_label_mode( isset( $current['printable_venue_label_mode'] ) ? $current['printable_venue_label_mode'] : '' ),
			);
		}

		/**
		 * Render settings page.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_sportspress' ) ) {
				return;
			}

			$current_tab          = $this->current_settings_tab();

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Tony\'s Settings', 'tonys-sportspress-enhancements' ) . '</h1>';
			$this->render_settings_tabs( $current_tab );

			if ( self::TAB_PRINTABLE === $current_tab ) {
				$this->render_printable_settings_tab( $current_tab );
			} else {
				do_action( 'tse_tonys_settings_render_tab_' . $current_tab );
			}

			echo '</div>';
		}

		/**
		 * Render Tony's settings tabs.
		 *
		 * @param string $current_tab Current tab key.
		 */
		private function render_settings_tabs( $current_tab ) {
			$tabs = apply_filters(
				'tse_tonys_settings_tabs',
				array(
				self::TAB_PRINTABLE => __( 'Printable Calendars', 'tonys-sportspress-enhancements' ),
				)
			);

			echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
			foreach ( $tabs as $tab => $label ) {
				$url = add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'tab'  => $tab,
					),
					admin_url( 'admin.php' )
				);

				$class = $tab === $current_tab ? ' nav-tab-active' : '';
				echo '<a class="nav-tab' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}

		/**
		 * Resolve the current settings tab.
		 *
		 * @return string
		 */
		private function current_settings_tab() {
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_PRINTABLE;
			$tabs = apply_filters(
				'tse_tonys_settings_tabs',
				array(
					self::TAB_PRINTABLE => __( 'Printable Calendars', 'tonys-sportspress-enhancements' ),
				)
			);

			return isset( $tabs[ $tab ] ) ? $tab : self::TAB_PRINTABLE;
		}

		/**
		 * Render printable settings tab content.
		 *
		 * @param string $current_tab Current tab key.
		 * @return void
		 */
		private function render_printable_settings_tab( $current_tab ) {
			$season_id            = $this->selected_season_id();
			$seasons              = $this->get_seasons();
			$venues               = $this->get_venues_for_season( $season_id );
			$overrides            = $this->get_venue_color_overrides();
			$primary_flags        = $this->get_venue_primary_flags();
			$season_key           = (string) $season_id;
			$season_overrides     = isset( $overrides[ $season_key ] ) && is_array( $overrides[ $season_key ] ) ? $overrides[ $season_key ] : array();
			$season_primary_flags = isset( $primary_flags[ $season_key ] ) && is_array( $primary_flags[ $season_key ] ) ? $primary_flags[ $season_key ] : array();

			echo '<form method="post" action="options.php">';
			settings_fields( self::OPTION_GROUP );
			echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY . '[active_season_id]' ) . '" value="' . esc_attr( (string) $season_id ) . '" />';

			echo '<h2>' . esc_html__( 'Field Colors By Season', 'tonys-sportspress-enhancements' ) . '</h2>';
			echo '<p>' . esc_html__( 'Pick venue colors per season. Colors are darkened automatically when needed so white text still reads clearly.', 'tonys-sportspress-enhancements' ) . '</p>';

			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr>';
			echo '<th scope="row"><label for="asc-sp-season-selector">' . esc_html__( 'Season', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td>';
			echo '<select id="asc-sp-season-selector" onchange="window.location=this.value;">';
			foreach ( $seasons as $season ) {
				if ( ! is_object( $season ) || ! isset( $season->term_id, $season->name ) ) {
					continue;
				}

				$url = add_query_arg(
					array(
						'page'      => self::PAGE_SLUG,
						'tab'       => $current_tab,
						'season_id' => (int) $season->term_id,
					),
					admin_url( 'admin.php' )
				);

				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_url( $url ),
					selected( $season_id, (int) $season->term_id, false ),
					esc_html( (string) $season->name )
				);
			}
			echo '</select>';
			echo '</td>';
			echo '</tr>';
			echo '<tr>';
			echo '<th scope="row"><label for="asc-sp-printable-venue-label-mode">' . esc_html__( 'Venue Label Under Time', 'tonys-sportspress-enhancements' ) . '</label></th>';
			echo '<td>';
			echo '<select id="asc-sp-printable-venue-label-mode" name="' . esc_attr( self::OPTION_KEY . '[printable_venue_label_mode]' ) . '">';
			foreach ( $this->get_label_mode_options() as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $this->get_venue_label_mode(), $mode, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Default field label mode for printable URLs that do not include a field_label parameter.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '</td>';
			echo '</tr>';
			echo '</tbody></table>';

			echo '<div style="margin:10px 0 14px;">';
			echo '<p><strong>' . esc_html__( 'Suggested dark palette:', 'tonys-sportspress-enhancements' ) . '</strong></p>';
			echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
			foreach ( $this->suggested_palette as $swatch ) {
				echo '<code style="display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;background:#fff;">';
				echo '<span style="display:inline-block;width:14px;height:14px;border:1px solid #cbd5e1;background:' . esc_attr( $swatch ) . ';"></span>';
				echo esc_html( $swatch );
				echo '</code>';
			}
			echo '</div>';
			echo '</div>';

			if ( empty( $venues ) ) {
				echo '<p>' . esc_html__( 'No venues found for this season yet.', 'tonys-sportspress-enhancements' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:980px">';
				echo '<thead><tr><th>' . esc_html__( 'Field / Venue', 'tonys-sportspress-enhancements' ) . '</th><th>' . esc_html__( 'Background Color', 'tonys-sportspress-enhancements' ) . '</th><th>' . esc_html__( 'Use Team Primary', 'tonys-sportspress-enhancements' ) . '</th><th>' . esc_html__( 'Preview', 'tonys-sportspress-enhancements' ) . '</th></tr></thead>';
				echo '<tbody>';

				$palette_count = count( $this->suggested_palette );
				foreach ( $venues as $index => $venue ) {
					$venue_id     = (int) $venue['id'];
					$venue_name   = isset( $venue['name'] ) ? (string) $venue['name'] : '';
					$saved        = isset( $season_overrides[ (string) $venue_id ] ) && is_string( $season_overrides[ (string) $venue_id ] ) ? $season_overrides[ (string) $venue_id ] : '';
					$suggested    = $this->suggested_palette[ $index % max( 1, $palette_count ) ];
					$value        = '' !== $saved ? $saved : $suggested;
					$adjusted     = $this->adjust_for_white_text( $value, self::MIN_WHITE_CONTRAST );
					$name         = self::OPTION_KEY . '[venue_color_overrides][' . $season_key . '][' . $venue_id . ']';
					$primary_name = self::OPTION_KEY . '[venue_use_team_primary][' . $season_key . '][' . $venue_id . ']';
					$use_primary  = isset( $season_primary_flags[ (string) $venue_id ] ) && '1' === $season_primary_flags[ (string) $venue_id ];

					echo '<tr>';
					echo '<td>' . esc_html( $venue_name ) . '</td>';
					echo '<td><input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $adjusted ) . '" placeholder="#1E3A8A" /></td>';
					echo '<td><label><input type="checkbox" name="' . esc_attr( $primary_name ) . '" value="1" ' . checked( $use_primary, true, false ) . ' /> ' . esc_html__( 'Use team primary color', 'tonys-sportspress-enhancements' ) . '</label></td>';
					echo '<td><span style="display:inline-block;min-width:70px;padding:4px 8px;color:#fff;background:' . esc_attr( $adjusted ) . ';font-weight:700;border-radius:3px;text-align:center;">' . esc_html__( 'Sample', 'tonys-sportspress-enhancements' ) . '</span></td>';
					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
			}

			submit_button( __( 'Save Settings', 'tonys-sportspress-enhancements' ) );
			echo '</form>';

			$this->render_printable_url_builder( $season_id );
		}

		/**
		 * Render printable calendar URL builder.
		 *
		 * @param int $season_id Current season context.
		 * @return void
		 */
		private function render_printable_url_builder( $season_id ) {
			$leagues = function_exists( 'tse_sp_schedule_exporter_get_leagues' ) ? tse_sp_schedule_exporter_get_leagues() : array();
			$teams   = function_exists( 'tse_sp_schedule_exporter_get_teams' ) ? tse_sp_schedule_exporter_get_teams() : array();
			$fields  = function_exists( 'tse_sp_schedule_exporter_get_fields' ) ? tse_sp_schedule_exporter_get_fields() : array();
			$paper   = '11x17';

			echo '<div class="tse-printable-url-builder" style="max-width:1100px;margin-top:28px;padding:20px 24px;border:1px solid #dcdcde;background:#fff;">';
			echo '<h2 style="margin-top:0;">' . esc_html__( 'Printable Calendar URL Builder', 'tonys-sportspress-enhancements' ) . '</h2>';
			echo '<p>' . esc_html__( 'Build a shareable printable calendar URL with teams, season, league, field, label formats, paper size, and optional auto-print.', 'tonys-sportspress-enhancements' ) . '</p>';

			echo '<table class="form-table" role="presentation"><tbody>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-team">' . esc_html__( 'Team', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-team" multiple="multiple" size="' . esc_attr( (string) min( 8, max( 3, count( $teams ) ) ) ) . '" style="min-width:280px;">';
			foreach ( $teams as $team ) {
				if ( ! $team instanceof WP_Post ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $team->ID ) . '">' . esc_html( $team->post_title ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Select one or more teams. Multiple teams create a combined matchup calendar.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-season">' . esc_html__( 'Season', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-season" style="min-width:280px;">';
			echo '<option value="0">' . esc_html__( 'Current season', 'tonys-sportspress-enhancements' ) . '</option>';
			foreach ( $this->get_seasons() as $season ) {
				if ( ! $season instanceof WP_Term ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $season->term_id ) . '" ' . selected( $season_id, (int) $season->term_id, false ) . '>' . esc_html( $season->name ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-league">' . esc_html__( 'League', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-league" style="min-width:280px;">';
			echo '<option value="0">' . esc_html__( 'Any league', 'tonys-sportspress-enhancements' ) . '</option>';
			foreach ( $leagues as $league ) {
				if ( ! $league instanceof WP_Term ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $league->term_id ) . '">' . esc_html( $league->name ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-field">' . esc_html__( 'Field', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-field" style="min-width:280px;">';
			echo '<option value="0">' . esc_html__( 'Any field', 'tonys-sportspress-enhancements' ) . '</option>';
			foreach ( $fields as $field ) {
				if ( ! $field instanceof WP_Term ) {
					continue;
				}
				echo '<option value="' . esc_attr( (string) $field->term_id ) . '">' . esc_html( $field->name ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-paper">' . esc_html__( 'Paper Size', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-paper" style="min-width:280px;">';
			foreach ( array( 'letter' => __( 'Letter', 'tonys-sportspress-enhancements' ), '11x17' => __( '11x17 / Ledger', 'tonys-sportspress-enhancements' ) ) as $paper_value => $paper_label ) {
				echo '<option value="' . esc_attr( $paper_value ) . '" ' . selected( $paper, $paper_value, false ) . '>' . esc_html( $paper_label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-team-label">' . esc_html__( 'Team Name Format', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-team-label" style="min-width:280px;">';
			foreach ( $this->get_label_mode_options() as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '" ' . selected( 'name', $mode, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-field-label">' . esc_html__( 'Field Name Format', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-field-label" style="min-width:280px;">';
			foreach ( $this->get_label_mode_options() as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $this->get_venue_label_mode(), $mode, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="tse-printable-builder-title-format">' . esc_html__( 'Title Format', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
			echo '<select id="tse-printable-builder-title-format" style="min-width:280px;">';
			foreach ( $this->get_title_format_options() as $format => $label ) {
				echo '<option value="' . esc_attr( $format ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';

			echo '<tr><th scope="row">' . esc_html__( 'Options', 'tonys-sportspress-enhancements' ) . '</th><td>';
			echo '<label for="tse-printable-builder-autoprint" style="display:inline-flex;align-items:center;gap:6px;">';
			echo '<input id="tse-printable-builder-autoprint" type="checkbox" value="1" />';
			echo esc_html__( 'Auto-open print dialog', 'tonys-sportspress-enhancements' );
			echo '</label>';
			echo '<br />';
			echo '<label for="tse-printable-builder-month-pages" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;">';
			echo '<input id="tse-printable-builder-month-pages" type="checkbox" value="1" />';
			echo esc_html__( 'Print each month on its own page', 'tonys-sportspress-enhancements' );
			echo '</label>';
			echo '</td></tr>';

			echo '</tbody></table>';

			echo '<h3 style="margin-top:24px;">' . esc_html__( 'Generated URL', 'tonys-sportspress-enhancements' ) . '</h3>';
			echo '<div style="display:flex;align-items:center;gap:8px;max-width:100%;">';
			echo '<input type="text" id="tse-printable-builder-output" class="large-text code" readonly="readonly" />';
			echo '<button type="button" id="tse-printable-builder-copy" class="button" aria-label="' . esc_attr__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '" title="' . esc_attr__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '" style="display:inline-flex;align-items:center;justify-content:center;min-width:40px;padding:0 10px;">';
			echo '<span aria-hidden="true" style="font-size:16px;line-height:1;">⧉</span>';
			echo '</button>';
			echo '</div>';
			echo '<p><a id="tse-printable-builder-open" class="button button-primary" href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Printable URL', 'tonys-sportspress-enhancements' ) . '</a></p>';
			echo '<p class="description">' . esc_html__( 'The printable route requires at least one selected team.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '</div>';

			$this->render_printable_url_builder_script();
		}

		/**
		 * Render printable URL builder script.
		 *
		 * @return void
		 */
		private function render_printable_url_builder_script() {
			$base_url = home_url( '/' );
			$query_flag = self::QUERY_FLAG;
			?>
			<script>
			(function(){
				var root = document.querySelector('.tse-printable-url-builder');
				if (!root) {
					return;
				}

				var baseUrl = <?php echo wp_json_encode( $base_url ); ?>;
				var queryFlag = <?php echo wp_json_encode( $query_flag ); ?>;
				var team = root.querySelector('#tse-printable-builder-team');
				var season = root.querySelector('#tse-printable-builder-season');
				var league = root.querySelector('#tse-printable-builder-league');
				var field = root.querySelector('#tse-printable-builder-field');
				var paper = root.querySelector('#tse-printable-builder-paper');
				var teamLabel = root.querySelector('#tse-printable-builder-team-label');
				var fieldLabel = root.querySelector('#tse-printable-builder-field-label');
				var titleFormat = root.querySelector('#tse-printable-builder-title-format');
				var autoprint = root.querySelector('#tse-printable-builder-autoprint');
				var monthPages = root.querySelector('#tse-printable-builder-month-pages');
				var output = root.querySelector('#tse-printable-builder-output');
				var copyButton = root.querySelector('#tse-printable-builder-copy');
				var openLink = root.querySelector('#tse-printable-builder-open');

				function selectedValues(select) {
					if (!select) {
						return [];
					}

					return Array.prototype.slice.call(select.selectedOptions || []).map(function(option){
						return option.value;
					}).filter(function(value){
						return value && value !== '0';
					});
				}

				function buildUrl() {
					var url = new URL(baseUrl, window.location.origin);
					var teamValues = selectedValues(team);
					url.searchParams.set(queryFlag, '1');

					if (teamValues.length) {
						url.searchParams.set('sp_team', teamValues.join(','));
					} else {
						url.searchParams.delete('sp_team');
					}

					if (season.value && season.value !== '0') {
						url.searchParams.set('sp_season', season.value);
					} else {
						url.searchParams.delete('sp_season');
					}

					if (league.value && league.value !== '0') {
						url.searchParams.set('sp_league', league.value);
					} else {
						url.searchParams.delete('sp_league');
					}

					if (field.value && field.value !== '0') {
						url.searchParams.set('sp_field', field.value);
					} else {
						url.searchParams.delete('sp_field');
					}

					if (paper.value) {
						url.searchParams.set('paper', paper.value);
					}

					if (teamLabel.value) {
						url.searchParams.set('team_label', teamLabel.value);
					}

					if (fieldLabel.value) {
						url.searchParams.set('field_label', fieldLabel.value);
					}

					if (titleFormat.value) {
						url.searchParams.set('title_format', titleFormat.value);
					}

					if (autoprint.checked) {
						url.searchParams.set('autoprint', '1');
					} else {
						url.searchParams.delete('autoprint');
					}

					if (monthPages.checked) {
						url.searchParams.set('month_pages', '1');
					} else {
						url.searchParams.delete('month_pages');
					}

					output.value = url.toString();
					openLink.href = url.toString();
					openLink.toggleAttribute('disabled', !teamValues.length);
				}

				[team, season, league, field, paper, teamLabel, fieldLabel, titleFormat, autoprint, monthPages].forEach(function(input){
					input.addEventListener('change', buildUrl);
				});

				if (copyButton) {
					copyButton.addEventListener('click', function(){
						var value = output.value || '';
						if (!value) {
							return;
						}

						var defaultTitle = copyButton.getAttribute('data-default-title') || copyButton.title || 'Copy URL';
						copyButton.setAttribute('data-default-title', defaultTitle);

						function markCopied() {
							copyButton.title = 'Copied';
							window.setTimeout(function(){
								copyButton.title = defaultTitle;
							}, 1200);
						}

						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(value).then(markCopied).catch(function(){
								output.focus();
								output.select();
								document.execCommand('copy');
								markCopied();
							});
							return;
						}

						output.focus();
						output.select();
						document.execCommand('copy');
						markCopied();
					});
				}

				buildUrl();
			})();
			</script>
			<?php
		}


		/**
		 * Render the printable page when the flag is present.
		 */
		public function maybe_render() {
			if ( '1' !== (string) get_query_var( self::QUERY_FLAG ) ) {
				return;
			}

			$team_ids = $this->parse_team_ids( get_query_var( 'sp_team' ) );
			if ( empty( $team_ids ) ) {
				status_header( 400 );
				nocache_headers();
				echo esc_html__( 'Missing or invalid team id.', 'tonys-sportspress-enhancements' );
				exit;
			}

			$season_id = absint( (string) get_query_var( 'sp_season' ) );
			if ( $season_id <= 0 ) {
				$season_id = absint( (string) get_option( 'sportspress_season', '0' ) );
			}
			$league_id = absint( (string) get_query_var( 'sp_league' ) );
			$field_ids = $this->parse_term_ids( get_query_var( 'sp_field' ), 'sp_venue' );
			$team_label_mode  = $this->sanitize_label_mode( get_query_var( 'team_label' ), 'abbreviation' );
			$field_label_mode = $this->sanitize_label_mode( get_query_var( 'field_label' ), $this->get_venue_label_mode() );
			$title_format     = $this->sanitize_title_format( get_query_var( 'title_format' ) );

			$paper = $this->normalize_paper_size( (string) get_query_var( 'paper' ) );
			$autoprint = '1' === (string) get_query_var( 'autoprint' );
			$month_pages = '1' === (string) get_query_var( 'month_pages' );

			$is_multi_team           = count( $team_ids ) > 1;
			$primary_team_id         = isset( $team_ids[0] ) ? (int) $team_ids[0] : 0;
			$team_name               = $this->get_printable_title( $team_ids, $team_label_mode );
			$team_logo               = $is_multi_team ? '' : get_the_post_thumbnail( $primary_team_id, array( 72, 72 ), array( 'class' => 'team-logo-img' ) );
			$brand_logo              = $this->get_header_brand_logo();
			$site_url                = home_url( '/' );
			$qr_url                  = 'https://api.qrserver.com/v1/create-qr-code/?size=144x144&data=' . rawurlencode( $site_url );
			$season_name             = '';
			$field_name              = count( $field_ids ) === 1 ? $this->get_term_label( (int) $field_ids[0], 'sp_venue', $field_label_mode ) : '';
			$entries                 = $this->get_schedule_entries( $team_ids, $season_id, $league_id, $field_ids, $team_label_mode, $field_label_mode, $title_format );
			$team_palette            = $is_multi_team ? $this->get_default_printable_palette() : $this->get_team_color_palette( $primary_team_id );
			$team_primary_for_fields = $is_multi_team ? '' : $this->get_strict_team_primary_color( $primary_team_id );
			$entries_by_day          = array();
			$venue_colors            = array();
			$month_keys              = array();
			$layout                  = array();
			$suppress_event_venue    = 1 === count( $field_ids );

			if ( $season_id > 0 ) {
				$season = get_term( $season_id, 'sp_season' );
				if ( $season && ! is_wp_error( $season ) ) {
					$season_name = $season->name;
				}
			}

			foreach ( $entries as $entry ) {
				$entries_by_day[ $entry['day_key'] ][] = $entry;

				if ( '' !== $entry['venue_name'] && is_string( $entry['venue_key'] ) && ! isset( $venue_colors[ $entry['venue_key'] ] ) ) {
					$venue_colors[ $entry['venue_key'] ] = array(
						'name'  => ! empty( $entry['venue_label'] ) ? (string) $entry['venue_label'] : (string) $entry['venue_name'],
						'color' => $this->get_venue_color( (string) $entry['venue_name'], $season_id, (int) $entry['venue_id'], $team_primary_for_fields, $is_multi_team ),
					);
				}
			}

			$month_keys = $this->get_month_keys( $entries );
			$layout     = $this->get_sheet_layout( $month_pages ? 1 : count( $month_keys ), $paper );

			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

			echo '<!doctype html>';
			echo '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">';
			echo '<head>';
			echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
			echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
			echo '<title>' . esc_html( $team_name . ' Printable Schedule' ) . '</title>';
			echo '<link rel="stylesheet" href="' . esc_url( TONY_SPORTSPRESS_ENHANCEMENTS_URL . 'assets/print-calendar.css' ) . '?ver=' . rawurlencode( TONY_SPORTSPRESS_ENHANCEMENTS_VERSION ) . '">';
			echo '<style>';
			echo '@page{size:' . ( 'ledger' === $paper ? '11in 17in' : 'letter' ) . ';margin:0.45in;}';
			echo '</style>';
			if ( $autoprint ) {
				echo '<script>window.addEventListener("load",function(){window.print();});</script>';
			}
			echo '</head>';
			echo '<body class="print-preview ' . esc_attr( $paper . ( $month_pages ? ' month-pages' : '' ) ) . '">';

			$root_vars = array(
				'--sheet-scale:' . $layout['sheet_scale'],
				'--month-columns:' . $layout['columns'],
				'--month-font-scale:' . $layout['font_scale'],
				'--day-aspect:' . $layout['day_aspect'],
				'--team-primary:' . $team_palette['primary'],
				'--team-on-primary:' . $this->get_readable_text_color( $team_palette['primary'] ),
				'--team-primary-contrast:' . $this->get_readable_text_color( $team_palette['primary'] ),
				'--team-link-color:' . $team_palette['secondary'],
				'--team-on-link-color:' . $this->get_readable_text_color( $team_palette['secondary'] ),
				'--team-link-color-contrast:' . $this->get_readable_text_color( $team_palette['secondary'] ),
				'--team-secondary:' . $team_palette['secondary'],
				'--team-on-secondary:' . $this->get_readable_text_color( $team_palette['secondary'] ),
				'--team-secondary-contrast:' . $this->get_readable_text_color( $team_palette['secondary'] ),
				'--team-accent:' . $team_palette['accent'],
				'--team-ink:' . $team_palette['ink'],
				'--team-muted-ink:' . $team_palette['muted_ink'],
			);

			echo '<main class="print-shell ' . esc_attr( $paper ) . '" style="' . esc_attr( implode( ';', $root_vars ) . ';' ) . '">';
			$meta_parts = array( $season_name ? $season_name : __( 'Current', 'tonys-sportspress-enhancements' ) );
			if ( '' !== $field_name ) {
				$meta_parts[] = $field_name;
			}

			if ( empty( $entries ) ) {
				echo '<div class="print-page">';
				$this->render_printable_header( $team_name, $meta_parts, $team_logo, $brand_logo );
				echo '<section class="empty">';
				echo '<p>' . esc_html__( 'No SportsPress events were found for the selected teams and filters.', 'tonys-sportspress-enhancements' ) . '</p>';
				echo '</section>';
				$this->render_printable_footer( $venue_colors, $site_url, $qr_url );
				echo '</div>';
			} elseif ( $month_pages ) {
				foreach ( $month_keys as $month_key ) {
					echo '<div class="print-page month-page">';
					$this->render_printable_header( $team_name, $meta_parts, $team_logo, $brand_logo );
					echo '<section class="sheet-grid">';
					$this->render_month_grid( $month_key, $entries_by_day, $venue_colors, $team_palette, $is_multi_team, $suppress_event_venue );
					echo '</section>';
					$this->render_printable_footer( $venue_colors, $site_url, $qr_url );
					echo '</div>';
				}
			} else {
				echo '<div class="print-page">';
				$this->render_printable_header( $team_name, $meta_parts, $team_logo, $brand_logo );
				echo '<section class="sheet-grid">';
				foreach ( $month_keys as $month_key ) {
					$this->render_month_grid( $month_key, $entries_by_day, $venue_colors, $team_palette, $is_multi_team, $suppress_event_venue );
				}
				echo '</section>';
				$this->render_printable_footer( $venue_colors, $site_url, $qr_url );
				echo '</div>';
			}
			echo '</main>';
			echo '</body></html>';
			exit;
		}

		/**
		 * Render the printable page header.
		 *
		 * @param string $team_name  Header title.
		 * @param array  $meta_parts Header metadata strings.
		 * @param string $team_logo  Team logo markup.
		 * @param string $brand_logo Brand logo markup.
		 * @return void
		 */
		private function render_printable_header( $team_name, $meta_parts, $team_logo, $brand_logo ) {
			echo '<header class="header">';
			echo '<div class="header-brand">';
			if ( '' !== $team_logo ) {
				echo '<span class="team-logo" aria-hidden="true">' . wp_kses_post( $team_logo ) . '</span>';
			}
			echo '<div class="header-copy">';
			echo '<h1 class="title">' . esc_html( $team_name ) . '</h1>';
			echo '<p class="meta">' . esc_html( implode( ' | ', array_filter( array_map( 'strval', $meta_parts ) ) ) ) . '</p>';
			echo '</div>';
			echo '</div>';

			if ( '' !== $brand_logo ) {
				echo '<span class="league-logo" aria-hidden="true">' . wp_kses_post( $brand_logo ) . '</span>';
			}
			echo '</header>';
		}

		/**
		 * Render the printable page footer.
		 *
		 * @param array  $venue_colors Venue legend data.
		 * @param string $site_url     Site URL.
		 * @param string $qr_url       QR code image URL.
		 * @return void
		 */
		private function render_printable_footer( $venue_colors, $site_url, $qr_url ) {
			echo '<footer class="footer-meta">';
			if ( ! empty( $venue_colors ) ) {
				echo '<div class="legend legend-bottom">';
				foreach ( $venue_colors as $venue_data ) {
					if ( ! is_array( $venue_data ) || ! isset( $venue_data['name'], $venue_data['color'] ) ) {
						continue;
					}

					$legend_color      = (string) $venue_data['color'];
					$legend_foreground = $this->get_readable_text_color( $legend_color );
					echo '<span class="legend-item" style="background:' . esc_attr( $legend_color ) . ';color:' . esc_attr( $legend_foreground ) . ';border-color:' . esc_attr( $legend_foreground ) . '40;">';
					echo esc_html( (string) $venue_data['name'] ) . '</span>';
				}
				echo '</div>';
			}
			echo '<div class="footer-qr">';
			echo '<div class="footer-qr-copy">';
			echo '<span class="footer-qr-label">' . esc_html__( 'Scan schedule', 'tonys-sportspress-enhancements' ) . '</span>';
			echo '<a class="footer-qr-link" href="' . esc_url( $site_url ) . '">' . esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ? wp_parse_url( $site_url, PHP_URL_HOST ) : $site_url ) . '</a>';
			echo '</div>';
			echo '<img class="footer-qr-image" src="' . esc_url( $qr_url ) . '" alt="' . esc_attr__( 'QR code for website', 'tonys-sportspress-enhancements' ) . '" loading="lazy" decoding="async">';
			echo '</div>';
			echo '</footer>';
		}

		/**
		 * Build the printable route URL.
		 *
		 * @param int    $team_id   Team ID.
		 * @param int    $season_id Season ID.
		 * @param string $paper     Paper size.
		 * @return string
		 */
		private function build_url( $team_id, $season_id, $paper, $league_id = 0, $field_id = 0, $month_pages = false, $team_label_mode = 'name', $field_label_mode = '', $title_format = 'selected_first' ) {
			$paper = $this->normalize_paper_size( $paper );
			$field_label_mode = '' === $field_label_mode ? $this->get_venue_label_mode() : $field_label_mode;

			return add_query_arg(
				array(
					self::QUERY_FLAG => '1',
					'sp_team'        => (string) absint( $team_id ),
					'sp_season'      => $season_id > 0 ? (string) absint( $season_id ) : '',
					'sp_league'      => $league_id > 0 ? (string) absint( $league_id ) : '',
					'sp_field'       => $field_id > 0 ? (string) absint( $field_id ) : '',
					'team_label'     => $this->sanitize_label_mode( $team_label_mode, 'name' ),
					'field_label'    => $this->sanitize_label_mode( $field_label_mode, $this->get_venue_label_mode() ),
					'title_format'   => $this->sanitize_title_format( $title_format ),
					'paper'          => (string) $paper,
					'month_pages'    => $month_pages ? '1' : '',
				),
				home_url( '/' )
			);
		}

		/**
		 * Parse and validate one or more team IDs.
		 *
		 * @param mixed $value Raw query value.
		 * @return int[]
		 */
		private function parse_team_ids( $value ) {
			$ids   = $this->parse_id_list( $value );
			$valid = array();

			foreach ( $ids as $id ) {
				if ( 'sp_team' === get_post_type( $id ) ) {
					$valid[] = $id;
				}
			}

			return array_values( array_unique( $valid ) );
		}

		/**
		 * Parse and validate one or more term IDs for a taxonomy.
		 *
		 * @param mixed  $value    Raw query value.
		 * @param string $taxonomy Taxonomy name.
		 * @return int[]
		 */
		private function parse_term_ids( $value, $taxonomy ) {
			$ids   = $this->parse_id_list( $value );
			$valid = array();

			foreach ( $ids as $id ) {
				$term = get_term( $id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$valid[] = $id;
				}
			}

			return array_values( array_unique( $valid ) );
		}

		/**
		 * Parse scalar, array, or comma-delimited ID values.
		 *
		 * @param mixed $value Raw value.
		 * @return int[]
		 */
		private function parse_id_list( $value ) {
			$values = is_array( $value ) ? $value : explode( ',', (string) $value );
			$ids    = array();

			foreach ( $values as $raw_value ) {
				$id = absint( trim( (string) $raw_value ) );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}

			return array_values( array_unique( $ids ) );
		}

		/**
		 * Build the printable title for selected teams.
		 *
		 * @param int[] $team_ids Team IDs.
		 * @return string
		 */
		private function get_printable_title( $team_ids, $mode = 'name' ) {
			$names = array();

			foreach ( $team_ids as $team_id ) {
				$title = $this->get_team_label( $team_id, $mode );
				if ( is_string( $title ) && '' !== trim( $title ) ) {
					$names[] = trim( $title );
				}
			}

			if ( empty( $names ) ) {
				return __( 'Printable Schedule', 'tonys-sportspress-enhancements' );
			}

			return implode( ', ', $names );
		}

		/**
		 * Get a term display name.
		 *
		 * @param int    $term_id  Term ID.
		 * @param string $taxonomy Taxonomy name.
		 * @return string
		 */
		private function get_term_name( $term_id, $taxonomy ) {
			$term = get_term( $term_id, $taxonomy );

			return $term && ! is_wp_error( $term ) && isset( $term->name ) ? (string) $term->name : '';
		}

		/**
		 * Get a term display label.
		 *
		 * @param int    $term_id  Term ID.
		 * @param string $taxonomy Taxonomy name.
		 * @param string $mode     Label mode.
		 * @return string
		 */
		private function get_term_label( $term_id, $taxonomy, $mode = 'name' ) {
			$term = get_term( $term_id, $taxonomy );

			if ( ! $term || is_wp_error( $term ) || ! isset( $term->name ) ) {
				return '';
			}

			return $this->get_configured_venue_label( $term, $mode );
		}

		/**
		 * Default option payload.
		 *
		 * @return array
		 */
		private function default_settings() {
			return array(
				'calendar_feed_url'     => '',
				'sync_interval_minutes' => 60,
				'venue_color_overrides' => array(),
				'venue_use_team_primary'=> array(),
				'printable_venue_label_mode' => 'name',
			);
		}

		/**
		 * Current settings with defaults.
		 *
		 * @return array
		 */
		private function get_settings() {
			return wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->default_settings() );
		}

		/**
		 * Normalize paper-size input to the internal sheet key.
		 *
		 * @param string $paper Raw paper-size value.
		 * @return string
		 */
		private function normalize_paper_size( $paper ) {
			$paper = strtolower( trim( (string) $paper ) );

			if ( '11x17' === $paper ) {
				return 'ledger';
			}

			if ( ! in_array( $paper, $this->allowed_paper_sizes, true ) ) {
				return 'letter';
			}

			return $paper;
		}

		/**
		 * Get supported label modes.
		 *
		 * @return array
		 */
		private function get_label_mode_options() {
			return array(
				'name'         => __( 'Name', 'tonys-sportspress-enhancements' ),
				'shortname'    => __( 'Short Name', 'tonys-sportspress-enhancements' ),
				'abbreviation' => __( 'Abbreviation', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Sanitize label mode.
		 *
		 * @param mixed  $value    Raw value.
		 * @param string $fallback Fallback mode.
		 * @return string
		 */
		private function sanitize_label_mode( $value, $fallback = 'name' ) {
			$mode    = is_string( $value ) ? sanitize_key( $value ) : '';
			$aliases = array(
				'full_name'  => 'name',
				'fullname'   => 'name',
				'short_name' => 'shortname',
				'short'      => 'shortname',
				'abbr'       => 'abbreviation',
			);
			if ( isset( $aliases[ $mode ] ) ) {
				$mode = $aliases[ $mode ];
			}

			$allowed  = array_keys( $this->get_label_mode_options() );
			$fallback = isset( $aliases[ $fallback ] ) ? $aliases[ $fallback ] : $fallback;

			return in_array( $mode, $allowed, true ) ? $mode : ( in_array( $fallback, $allowed, true ) ? $fallback : 'name' );
		}

		/**
		 * Sanitize venue label mode.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private function sanitize_venue_label_mode( $value ) {
			return $this->sanitize_label_mode( $value, 'name' );
		}

		/**
		 * Get supported event title formats.
		 *
		 * @return array
		 */
		private function get_title_format_options() {
			return array(
				'selected_first' => __( 'Selected Teams First', 'tonys-sportspress-enhancements' ),
				'matchup'        => __( 'Matchup', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Sanitize event title format.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private function sanitize_title_format( $value ) {
			$format = is_string( $value ) ? sanitize_key( $value ) : '';
			$options = array_keys( $this->get_title_format_options() );

			return in_array( $format, $options, true ) ? $format : 'selected_first';
		}

		/**
		 * Whether SportsPress is available.
		 *
		 * @return bool
		 */
		private function sportspress_available() {
			if ( class_exists( 'SportsPress' ) ) {
				return true;
			}

			return post_type_exists( 'sp_team' ) && post_type_exists( 'sp_event' );
		}

		/**
		 * Sanitize venue color overrides.
		 *
		 * @param mixed $raw Raw value.
		 * @return array
		 */
		private function sanitize_venue_color_overrides( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $raw as $season_key => $season_values ) {
				$season_id = absint( (string) $season_key );
				if ( $season_id <= 0 || ! is_array( $season_values ) ) {
					continue;
				}

				foreach ( $season_values as $venue_key => $color ) {
					$venue_id = absint( (string) $venue_key );
					if ( $venue_id <= 0 || ! is_string( $color ) ) {
						continue;
					}

					$hex = $this->sanitize_hex_color( $color );
					if ( '' === $hex ) {
						continue;
					}

					$sanitized[ (string) $season_id ][ (string) $venue_id ] = $this->adjust_for_white_text( $hex, self::MIN_WHITE_CONTRAST );
				}
			}

			return $sanitized;
		}

		/**
		 * Sanitize venue primary flags.
		 *
		 * @param mixed $raw Raw value.
		 * @return array
		 */
		private function sanitize_venue_primary_flags( $raw ) {
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$sanitized = array();
			foreach ( $raw as $season_key => $season_values ) {
				$season_id = absint( (string) $season_key );
				if ( $season_id <= 0 || ! is_array( $season_values ) ) {
					continue;
				}

				foreach ( $season_values as $venue_key => $value ) {
					$venue_id = absint( (string) $venue_key );
					if ( $venue_id <= 0 ) {
						continue;
					}

					if ( is_scalar( $value ) && '1' === (string) $value ) {
						$sanitized[ (string) $season_id ][ (string) $venue_id ] = '1';
					}
				}
			}

			return $sanitized;
		}

		/**
		 * Merge submitted season-scoped settings without preserving unchecked boxes.
		 *
		 * @param array $existing         Existing settings.
		 * @param array $submitted        Sanitized submitted settings.
		 * @param int   $active_season_id Active season ID.
		 * @param bool  $preserve_missing Whether to preserve season if missing.
		 * @return array
		 */
		private function merge_sanitized_season_settings( $existing, $submitted, $active_season_id, $preserve_missing ) {
			$merged = is_array( $existing ) ? $existing : array();

			if ( $active_season_id <= 0 ) {
				return ! empty( $submitted ) ? $submitted : $merged;
			}

			$season_key = (string) $active_season_id;
			if ( isset( $submitted[ $season_key ] ) && is_array( $submitted[ $season_key ] ) ) {
				$merged[ $season_key ] = $submitted[ $season_key ];
			} elseif ( $preserve_missing ) {
				if ( isset( $existing[ $season_key ] ) && is_array( $existing[ $season_key ] ) ) {
					$merged[ $season_key ] = $existing[ $season_key ];
				}
			} else {
				unset( $merged[ $season_key ] );
			}

			return $merged;
		}

		/**
		 * Get seasons list.
		 *
		 * @return array
		 */
		private function get_seasons() {
			$terms = get_terms(
				array(
					'taxonomy'   => 'sp_season',
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				return array();
			}

			return $terms;
		}

		/**
		 * Resolve selected season ID.
		 *
		 * @return int
		 */
		private function selected_season_id() {
			$requested = isset( $_GET['season_id'] ) ? absint( (string) wp_unslash( $_GET['season_id'] ) ) : 0;
			if ( $requested > 0 ) {
				return $requested;
			}

			$current = absint( (string) get_option( 'sportspress_season', '0' ) );
			if ( $current > 0 ) {
				return $current;
			}

			$seasons = $this->get_seasons();
			if ( isset( $seasons[0] ) && is_object( $seasons[0] ) && isset( $seasons[0]->term_id ) ) {
				return (int) $seasons[0]->term_id;
			}

			return 0;
		}

		/**
		 * Get venues for a season.
		 *
		 * @param int $season_id Season ID.
		 * @return array
		 */
		private function get_venues_for_season( $season_id ) {
			if ( $season_id <= 0 ) {
				return array();
			}

			$events = get_posts(
				array(
					'post_type'      => 'sp_event',
					'post_status'    => array( 'publish', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => 'sp_season',
							'field'    => 'term_id',
							'terms'    => array( $season_id ),
						),
					),
				)
			);

			if ( ! is_array( $events ) || empty( $events ) ) {
				return array();
			}

			$terms = wp_get_object_terms(
				$events,
				'sp_venue',
				array(
					'orderby' => 'name',
					'order'   => 'ASC',
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				return array();
			}

			$venues = array();
			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
					continue;
				}

				$venues[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
				);
			}

			return $venues;
		}

		/**
		 * Get stored venue color overrides.
		 *
		 * @return array
		 */
		private function get_venue_color_overrides() {
			$settings = $this->get_settings();

			return isset( $settings['venue_color_overrides'] ) && is_array( $settings['venue_color_overrides'] ) ? $settings['venue_color_overrides'] : array();
		}

		/**
		 * Get stored venue primary flags.
		 *
		 * @return array
		 */
		private function get_venue_primary_flags() {
			$settings = $this->get_settings();

			return isset( $settings['venue_use_team_primary'] ) && is_array( $settings['venue_use_team_primary'] ) ? $settings['venue_use_team_primary'] : array();
		}

		/**
		 * Get configured venue label mode.
		 *
		 * @return string
		 */
		private function get_venue_label_mode() {
			$settings = $this->get_settings();

			return $this->sanitize_venue_label_mode( isset( $settings['printable_venue_label_mode'] ) ? $settings['printable_venue_label_mode'] : '' );
		}

		/**
		 * Collect event entries for the calendar.
		 *
		 * @param int|int[] $team_ids Team IDs.
		 * @param int       $season_id Season ID.
		 * @param int       $league_id League ID.
		 * @param int[]     $field_ids Field IDs.
		 * @return array
		 */
		private function get_schedule_entries( $team_ids, $season_id, $league_id = 0, $field_ids = array(), $team_label_mode = 'abbreviation', $field_label_mode = '', $title_format = 'selected_first' ) {
			$team_ids      = is_array( $team_ids ) ? array_values( array_filter( array_map( 'absint', $team_ids ) ) ) : array( absint( $team_ids ) );
			$primary_team  = isset( $team_ids[0] ) ? (int) $team_ids[0] : 0;
			$is_multi_team = count( $team_ids ) > 1;
			$field_ids     = is_array( $field_ids ) ? array_values( array_filter( array_map( 'absint', $field_ids ) ) ) : array();
			$team_label_mode  = $this->sanitize_label_mode( $team_label_mode, 'abbreviation' );
			$field_label_mode = '' === $field_label_mode ? $this->get_venue_label_mode() : $this->sanitize_label_mode( $field_label_mode, $this->get_venue_label_mode() );
			$title_format     = $this->sanitize_title_format( $title_format );

			if ( empty( $team_ids ) ) {
				return array();
			}

			$args = array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'sp_team',
						'value'   => array_map( 'strval', $team_ids ),
						'compare' => 'IN',
					),
				),
			);

			$tax_query = array();

			if ( $season_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => array( $season_id ),
				);
			}

			if ( $league_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => 'sp_league',
					'field'    => 'term_id',
					'terms'    => array( $league_id ),
				);
			}

			if ( ! empty( $field_ids ) ) {
				$tax_query[] = array(
					'taxonomy' => 'sp_venue',
					'field'    => 'term_id',
					'terms'    => $field_ids,
				);
			}

			if ( ! empty( $tax_query ) ) {
				if ( count( $tax_query ) > 1 ) {
					$tax_query['relation'] = 'AND';
				}

				$args['tax_query'] = $tax_query;
			}

			$query   = new WP_Query( $args );
			$entries = array();

			foreach ( $query->posts as $event ) {
				$event_id = is_object( $event ) ? (int) $event->ID : 0;
				if ( $event_id <= 0 ) {
					continue;
				}

				$teams = array_values( array_unique( array_map( 'intval', get_post_meta( $event_id, 'sp_team', false ) ) ) );
				$matching_team_ids = array_values( array_intersect( $team_ids, $teams ) );
				if ( empty( $matching_team_ids ) ) {
					continue;
				}

				$timestamp = (int) get_post_time( 'U', true, $event_id, true );
				if ( $timestamp <= 0 ) {
					continue;
				}

				$home_id     = isset( $teams[0] ) ? (int) $teams[0] : 0;
				$away_id     = isset( $teams[1] ) ? (int) $teams[1] : 0;
				$context_id  = $is_multi_team ? 0 : $primary_team;
				$opponent_id = 0;
				if ( ! $is_multi_team ) {
					foreach ( $teams as $team_option_id ) {
						if ( $team_option_id !== $context_id ) {
							$opponent_id = $team_option_id;
							break;
						}
					}
				}
				$title_data = $this->get_event_title_data( $home_id, $away_id, $team_ids, $team_label_mode, $title_format );

				$venue        = $this->get_event_venue_term( $event_id );
				$venue_name   = '';
				$venue_id     = 0;
				$venue_label  = '';
				if ( $venue ) {
					$venue_name  = (string) $venue->name;
					$venue_id    = (int) $venue->term_id;
					$venue_label = $this->get_configured_venue_label( $venue, $field_label_mode );
				}

				$entries[] = array(
					'event_id'       => $event_id,
					'day_key'        => wp_date( 'Y-m-d', $timestamp ),
					'month_key'      => wp_date( 'Y-m', $timestamp ),
					'timestamp'      => $timestamp,
					'is_home'        => ! $is_multi_team && $home_id === $context_id,
					'is_matchup'     => 'matchup' === $title_format,
					'opponent_id'    => $opponent_id,
					'opponent_name'  => $opponent_id > 0 ? $this->get_team_label( $opponent_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
					'title_team_name' => $title_data['team_name'],
					'title_separator' => $title_data['separator'],
					'title_opponent_name' => $title_data['opponent_name'],
					'home_team_id'   => $home_id,
					'away_team_id'   => $away_id,
					'home_team_name' => $home_id > 0 ? $this->get_team_label( $home_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
					'away_team_name' => $away_id > 0 ? $this->get_team_label( $away_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
					'event_time'     => function_exists( 'sp_get_time' ) ? sp_get_time( $event_id ) : get_post_time( get_option( 'time_format' ), false, $event_id, true ),
					'venue_name'     => $venue_name,
					'venue_label'    => $venue_label,
					'venue_id'       => $venue_id,
					'venue_key'      => $venue_id > 0 ? 'v:' . $venue_id : 'n:' . strtolower( $venue_name ),
				);
			}

			wp_reset_postdata();

			usort(
				$entries,
				function ( $left, $right ) {
					return (int) $left['timestamp'] <=> (int) $right['timestamp'];
				}
			);

			return $entries;
		}

		/**
		 * Build an ordered list of month keys.
		 *
		 * @param array $entries Schedule entries.
		 * @return array
		 */
		private function get_month_keys( $entries ) {
			if ( empty( $entries ) ) {
				return array( wp_date( 'Y-m' ) );
			}

			$first       = (int) $entries[0]['timestamp'];
			$last        = (int) $entries[ count( $entries ) - 1 ]['timestamp'];
			$first_month = ( new DateTimeImmutable( wp_date( 'Y-m-01 00:00:00', $first ) ) )->modify( 'first day of this month' );
			$last_month  = ( new DateTimeImmutable( wp_date( 'Y-m-01 00:00:00', $last ) ) )->modify( 'first day of next month' );
			$range       = new DatePeriod( $first_month, new DateInterval( 'P1M' ), $last_month );
			$months      = array();

			foreach ( $range as $month ) {
				$months[] = $month->format( 'Y-m' );
			}

			return $months;
		}

		/**
		 * Get event title parts for the selected title format.
		 *
		 * @param int    $home_id         Home team ID.
		 * @param int    $away_id         Away team ID.
		 * @param int[]  $selected_ids    Selected team IDs.
		 * @param string $team_label_mode Team label mode.
		 * @param string $title_format    Title format.
		 * @return array
		 */
		private function get_event_title_data( $home_id, $away_id, $selected_ids, $team_label_mode, $title_format ) {
			$home_id      = absint( $home_id );
			$away_id      = absint( $away_id );
			$selected_ids = array_values( array_filter( array_map( 'absint', (array) $selected_ids ) ) );
			$title_format = $this->sanitize_title_format( $title_format );

			if ( 'matchup' === $title_format ) {
				return array(
					'team_name'     => $away_id > 0 ? $this->get_team_label( $away_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
					'separator'     => 'at',
					'opponent_name' => $home_id > 0 ? $this->get_team_label( $home_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
				);
			}

			$context = $this->get_selected_team_context( $home_id, $away_id, $selected_ids );
			if ( empty( $context['team_id'] ) ) {
				return array(
					'team_name'     => '',
					'separator'     => '',
					'opponent_name' => '',
				);
			}

			$opponent_id = isset( $context['opponent_id'] ) ? (int) $context['opponent_id'] : 0;
			if ( 1 === count( $selected_ids ) ) {
				return array(
					'team_name'     => '',
					'separator'     => '',
					'opponent_name' => $opponent_id > 0 ? $this->get_team_label( $opponent_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
				);
			}

			return array(
				'team_name'     => $this->get_team_label( (int) $context['team_id'], $team_label_mode ),
				'separator'     => isset( $context['separator'] ) ? (string) $context['separator'] : '',
				'opponent_name' => $opponent_id > 0 ? $this->get_team_label( $opponent_id, $team_label_mode ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Get selected-team perspective for an event.
		 *
		 * @param int   $home_id      Home team ID.
		 * @param int   $away_id      Away team ID.
		 * @param int[] $selected_ids Selected team IDs.
		 * @return array
		 */
		private function get_selected_team_context( $home_id, $away_id, $selected_ids ) {
			$home_id      = absint( $home_id );
			$away_id      = absint( $away_id );
			$selected_ids = array_values( array_filter( array_map( 'absint', (array) $selected_ids ) ) );

			if ( $away_id > 0 && in_array( $away_id, $selected_ids, true ) ) {
				return array(
					'team_id'     => $away_id,
					'opponent_id' => $home_id,
					'separator'   => 'at',
				);
			}

			if ( $home_id > 0 && in_array( $home_id, $selected_ids, true ) ) {
				return array(
					'team_id'     => $home_id,
					'opponent_id' => $away_id,
					'separator'   => 'vs',
				);
			}

			return array(
				'team_id'     => 0,
				'opponent_id' => 0,
				'separator'   => '',
			);
		}

		/**
		 * Render a single month grid.
		 *
		 * @param string $month_key      Month key.
		 * @param array  $entries_by_day Entries keyed by day.
		 * @param array  $venue_colors   Venue colors.
		 * @param array  $team_palette   Team palette.
		 * @param bool   $is_multi_team  Whether this is a combined team schedule.
		 * @param bool   $suppress_venue Whether to hide per-event venue labels.
		 */
		private function render_month_grid( $month_key, $entries_by_day, $venue_colors, $team_palette, $is_multi_team = false, $suppress_venue = false ) {
			$month = DateTimeImmutable::createFromFormat( 'Y-m-d', $month_key . '-01' );
			if ( ! $month instanceof DateTimeImmutable ) {
				return;
			}

			$month_label    = wp_date( 'F', (int) $month->format( 'U' ) );
			$days_in_month  = (int) $month->format( 't' );
			$leading_blanks = (int) $month->format( 'w' );

			echo '<section class="month">';
			echo '<h2 class="month-title">' . esc_html( $month_label ) . '</h2>';
			echo '<div class="dow">';
			foreach ( array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) as $day_name ) {
				echo '<span>' . esc_html( $day_name ) . '</span>';
			}
			echo '</div>';
			echo '<div class="grid">';

			for ( $i = 0; $i < $leading_blanks; $i++ ) {
				echo '<div class="day muted"></div>';
			}

			for ( $day = 1; $day <= $days_in_month; $day++ ) {
				$day_key     = sprintf( '%s-%02d', $month_key, $day );
				$day_entries = isset( $entries_by_day[ $day_key ] ) ? $entries_by_day[ $day_key ] : array();
				$has_matchup = false;
				foreach ( $day_entries as $day_entry ) {
					if ( ! empty( $day_entry['is_matchup'] ) || ! empty( $day_entry['title_team_name'] ) ) {
						$has_matchup = true;
						break;
					}
				}
				$day_class = ! empty( $day_entries ) ? 'day has-events' : 'day no-events';
				if ( $has_matchup ) {
					$day_class .= ' has-matchups';
				}
				$day_style   = '';

				if ( ! empty( $day_entries ) ) {
					$first_entry       = $day_entries[0];
					$first_is_home     = ! empty( $first_entry['is_home'] );
					$first_is_matchup  = ! empty( $first_entry['is_matchup'] ) || ! empty( $first_entry['title_team_name'] );
					$first_venue_key   = isset( $first_entry['venue_key'] ) && is_string( $first_entry['venue_key'] ) ? $first_entry['venue_key'] : '';
					$first_venue_color = ( '' !== $first_venue_key && isset( $venue_colors[ $first_venue_key ]['color'] ) ) ? (string) $venue_colors[ $first_venue_key ]['color'] : '';
					$first_background  = '' !== $first_venue_color ? $first_venue_color : ( $first_is_home || $first_is_matchup ? $team_palette['primary'] : $team_palette['secondary'] );
					$first_foreground  = $this->get_readable_text_color( $first_background );
					$first_background  = $this->ensure_minimum_contrast( $first_background, $first_foreground, self::MIN_WHITE_CONTRAST );
					$first_foreground  = $this->get_readable_text_color( $first_background );
					$day_num_shadow    = '#000000' === strtoupper( $first_foreground ) ? 'none' : '0 1px 1px rgba(0,0,0,0.5)';
					$day_style         = ' style="--day-num-color:' . esc_attr( $first_foreground ) . ';--day-num-shadow:' . esc_attr( $day_num_shadow ) . ';"';
				}

				echo '<div class="' . esc_attr( $day_class ) . '"' . $day_style . '>';
				echo '<div class="day-num">' . esc_html( (string) $day ) . '</div>';

				if ( ! empty( $day_entries ) ) {
					echo '<div class="events-stack" style="--event-count:' . esc_attr( (string) count( $day_entries ) ) . ';">';
					foreach ( $day_entries as $entry ) {
						$is_home          = ! empty( $entry['is_home'] );
						$is_matchup       = ! empty( $entry['is_matchup'] );
						$uses_split_title = $is_matchup || ! empty( $entry['title_team_name'] );
						$event_class      = $uses_split_title ? 'matchup' : ( $is_home ? 'h' : 'a' );
						$venue_key        = isset( $entry['venue_key'] ) && is_string( $entry['venue_key'] ) ? $entry['venue_key'] : '';
						$venue_color      = ( '' !== $venue_key && isset( $venue_colors[ $venue_key ]['color'] ) ) ? (string) $venue_colors[ $venue_key ]['color'] : '';
						$opponent_id      = isset( $entry['opponent_id'] ) ? (int) $entry['opponent_id'] : 0;
						$logo             = ( ! $uses_split_title && $opponent_id > 0 ) ? get_the_post_thumbnail( $opponent_id, 'medium', array( 'class' => 'event-logo-img', 'loading' => 'eager', 'decoding' => 'async' ) ) : '';
						$has_logo         = '' !== $logo;
						$event_background = '' !== $venue_color ? $venue_color : ( $is_home || $uses_split_title ? $team_palette['primary'] : $team_palette['secondary'] );
						$event_foreground = $this->get_readable_text_color( $event_background );
						$event_background = $this->ensure_minimum_contrast( $event_background, $event_foreground, self::MIN_WHITE_CONTRAST );
						$event_foreground = $this->get_readable_text_color( $event_background );
						$event_style      = ' style="--event-bg:' . esc_attr( $event_background ) . ';--event-fg:' . esc_attr( $event_foreground ) . ';"';

						echo '<article class="event ' . esc_attr( $event_class . ' ' . ( $has_logo ? 'has-logo' : 'no-logo' ) ) . '"' . $event_style . '>';
						echo '<div class="event-center">';
						if ( $has_logo ) {
							echo wp_kses_post( $logo );
						} else {
							if ( $uses_split_title ) {
								$team_name = isset( $entry['title_team_name'] ) ? (string) $entry['title_team_name'] : __( 'TBD', 'tonys-sportspress-enhancements' );
								$separator = isset( $entry['title_separator'] ) ? (string) $entry['title_separator'] : 'at';
								$opponent_name = isset( $entry['title_opponent_name'] ) ? (string) $entry['title_opponent_name'] : __( 'TBD', 'tonys-sportspress-enhancements' );
								echo '<span class="event-name matchup-name"><span class="matchup-team away-team">' . esc_html( $team_name ) . '</span><span class="matchup-vs">' . esc_html( $separator ) . '</span><span class="matchup-team home-team">' . esc_html( $opponent_name ) . '</span></span>';
							} else {
								echo '<span class="event-name">' . esc_html( isset( $entry['title_opponent_name'] ) ? (string) $entry['title_opponent_name'] : '' ) . '</span>';
							}
						}
						echo '</div>';
						if ( ! $uses_split_title ) {
							echo '<span class="ha-flag">' . esc_html( $is_home ? 'H' : 'A' ) . '</span>';
						}
						echo '<div class="event-meta">';
						echo '<div class="event-time">' . esc_html( isset( $entry['event_time'] ) ? (string) $entry['event_time'] : '' ) . '</div>';
						if ( ! $suppress_venue && ! empty( $entry['venue_label'] ) ) {
							echo '<div class="event-venue" title="' . esc_attr( isset( $entry['venue_name'] ) ? (string) $entry['venue_name'] : '' ) . '">' . esc_html( (string) $entry['venue_label'] ) . '</div>';
						}
						echo '</div>';
						echo '</article>';
					}
					echo '</div>';
				}

				echo '</div>';
			}

			$rendered_cells = $leading_blanks + $days_in_month;
			$remaining      = 7 - ( $rendered_cells % 7 );
			if ( $remaining < 7 ) {
				for ( $i = 0; $i < $remaining; $i++ ) {
					echo '<div class="day muted"></div>';
				}
			}

			echo '</div>';
			echo '</section>';
		}

		/**
		 * Resolve team label.
		 *
		 * @param int $team_id Team ID.
		 * @return string
		 */
		private function get_team_label( $team_id, $mode = 'abbreviation' ) {
			$mode = $this->sanitize_label_mode( $mode, 'abbreviation' );

			if ( 'shortname' === $mode ) {
				if ( function_exists( 'sp_team_short_name' ) ) {
					$label = trim( (string) sp_team_short_name( $team_id ) );
					if ( '' !== $label ) {
						return $label;
					}
				}

				$label = trim( (string) get_post_meta( $team_id, 'sp_short_name', true ) );
				if ( '' !== $label ) {
					return $label;
				}
			}

			if ( 'abbreviation' === $mode ) {
				if ( function_exists( 'sp_team_abbreviation' ) ) {
					$label = trim( (string) sp_team_abbreviation( $team_id ) );
					if ( '' !== $label ) {
						return $label;
					}
				}

				$label = trim( (string) get_post_meta( $team_id, 'sp_abbreviation', true ) );
				if ( '' !== $label ) {
					return $label;
				}
			}

			$title = get_the_title( $team_id );

			return is_string( $title ) && '' !== $title ? $title : __( 'TBD', 'tonys-sportspress-enhancements' );
		}

		/**
		 * Get the first event venue term.
		 *
		 * @param int $event_id Event ID.
		 * @return WP_Term|null
		 */
		private function get_event_venue_term( $event_id ) {
			$venues = get_the_terms( $event_id, 'sp_venue' );
			if ( ! is_array( $venues ) || ! isset( $venues[0] ) || ! is_object( $venues[0] ) || ! isset( $venues[0]->term_id, $venues[0]->name ) ) {
				return null;
			}

			return $venues[0];
		}

		/**
		 * Get the venue label for the current printable mode.
		 *
		 * Falls back to the full venue name when term meta is empty.
		 *
		 * @param WP_Term $venue Venue term.
		 * @return string
		 */
		private function get_configured_venue_label( $venue, $mode = '' ) {
			$full_name = isset( $venue->name ) ? trim( (string) $venue->name ) : '';
			if ( '' === $full_name || ! isset( $venue->term_id ) ) {
				return '';
			}

			$term_id = (int) $venue->term_id;
			$mode    = '' === $mode ? $this->get_venue_label_mode() : $this->sanitize_label_mode( $mode, $this->get_venue_label_mode() );

			if ( 'abbreviation' === $mode ) {
				$abbreviation = trim( (string) get_term_meta( $term_id, 'tse_abbreviation', true ) );
				return '' !== $abbreviation ? $abbreviation : $full_name;
			}

			if ( 'shortname' === $mode ) {
				$short_name = trim( (string) get_term_meta( $term_id, 'tse_short_name', true ) );
				return '' !== $short_name ? $short_name : $full_name;
			}

			return $full_name;
		}

		/**
		 * Resolve venue color.
		 *
		 * @param string $venue_name    Venue name.
		 * @param int    $season_id     Season ID.
		 * @param int    $venue_id      Venue ID.
		 * @param string $team_primary        Team primary color.
		 * @param bool   $ignore_team_primary Whether to ignore venue primary flags.
		 * @return string
		 */
		private function get_venue_color( $venue_name, $season_id, $venue_id, $team_primary, $ignore_team_primary = false ) {
			$custom = $this->get_custom_venue_color( $season_id, $venue_id );
			if ( $ignore_team_primary && '' !== $custom ) {
				return $custom;
			}

			if ( $ignore_team_primary ) {
				$suggested = $this->get_suggested_venue_color( $season_id, $venue_id );
				if ( '' !== $suggested ) {
					return $suggested;
				}
			}

			if ( ! $ignore_team_primary && $this->should_use_team_primary_for_venue( $season_id, $venue_id ) && '' !== $this->sanitize_color( $team_primary ) ) {
				return $this->sanitize_color( $team_primary );
			}

			if ( '' !== $custom ) {
				return $custom;
			}

			$palette = array( '#1D4ED8', '#DC2626', '#A16207', '#15803D', '#0E7490', '#BE185D', '#6D28D9', '#C2410C' );
			$index   = abs( crc32( strtolower( $venue_name ) ) ) % count( $palette );

			return $palette[ $index ];
		}

		/**
		 * Get the settings-screen suggested venue color for a season.
		 *
		 * @param int $season_id Season ID.
		 * @param int $venue_id  Venue ID.
		 * @return string
		 */
		private function get_suggested_venue_color( $season_id, $venue_id ) {
			if ( $season_id <= 0 || $venue_id <= 0 ) {
				return '';
			}

			$venues = $this->get_venues_for_season( $season_id );
			if ( empty( $venues ) ) {
				return '';
			}

			$palette_count = count( $this->suggested_palette );
			foreach ( $venues as $index => $venue ) {
				if ( ! is_array( $venue ) || ! isset( $venue['id'] ) || (int) $venue['id'] !== (int) $venue_id ) {
					continue;
				}

				$suggested = isset( $this->suggested_palette[ $index % max( 1, $palette_count ) ] ) ? $this->suggested_palette[ $index % max( 1, $palette_count ) ] : '';
				return '' !== $suggested ? $this->adjust_for_white_text( $suggested, self::MIN_WHITE_CONTRAST ) : '';
			}

			return '';
		}

		/**
		 * Resolve primary team color without site fallbacks.
		 *
		 * @param int $team_id Team ID.
		 * @return string
		 */
		private function get_strict_team_primary_color( $team_id ) {
			$team_colors = get_post_meta( $team_id, 'sp_colors', true );
			if ( is_array( $team_colors ) ) {
				$primary_keys = array( 'primary', 'link', 'heading', 'background', 'text' );
				foreach ( $primary_keys as $key ) {
					if ( isset( $team_colors[ $key ] ) && is_string( $team_colors[ $key ] ) ) {
						$hex = $this->sanitize_color( $team_colors[ $key ] );
						if ( '' !== $hex ) {
							return $hex;
						}
					}
				}

				foreach ( $team_colors as $value ) {
					if ( is_string( $value ) ) {
						$hex = $this->sanitize_color( $value );
						if ( '' !== $hex ) {
							return $hex;
						}
					}
				}
			}

			foreach ( array( get_post_meta( $team_id, 'sp_color', true ), get_post_meta( $team_id, 'asc_sp_primary_color', true ) ) as $candidate ) {
				$hex = $this->sanitize_color( (string) $candidate );
				if ( '' !== $hex ) {
					return $hex;
				}
			}

			return '#1B76D1';
		}

		/**
		 * Get stored custom venue color.
		 *
		 * @param int $season_id Season ID.
		 * @param int $venue_id  Venue ID.
		 * @return string
		 */
		private function get_custom_venue_color( $season_id, $venue_id ) {
			if ( $season_id <= 0 || $venue_id <= 0 ) {
				return '';
			}

			$overrides  = $this->get_venue_color_overrides();
			$season_key = (string) $season_id;
			$venue_key  = (string) $venue_id;

			if ( ! isset( $overrides[ $season_key ] ) || ! is_array( $overrides[ $season_key ] ) ) {
				return '';
			}

			if ( ! isset( $overrides[ $season_key ][ $venue_key ] ) || ! is_string( $overrides[ $season_key ][ $venue_key ] ) ) {
				return '';
			}

			$hex = strtoupper( trim( $overrides[ $season_key ][ $venue_key ] ) );
			if ( 1 !== preg_match( '/^#[0-9A-F]{6}$/', $hex ) ) {
				return '';
			}

			return $hex;
		}

		/**
		 * Get header logo markup.
		 *
		 * @return string
		 */
		private function get_header_brand_logo() {
			$themeboy_options = get_option( 'themeboy', array() );
			if ( is_array( $themeboy_options ) && isset( $themeboy_options['logo_url'] ) && is_string( $themeboy_options['logo_url'] ) ) {
				$themeboy_logo_url = esc_url( set_url_scheme( $themeboy_options['logo_url'] ) );
				if ( '' !== $themeboy_logo_url ) {
					return '<img class="league-logo-img" src="' . esc_url( $themeboy_logo_url ) . '" alt="">';
				}
			}

			$custom_logo_html = get_custom_logo();
			if ( is_string( $custom_logo_html ) && '' !== trim( $custom_logo_html ) ) {
				return $custom_logo_html;
			}

			if ( function_exists( 'render_block' ) ) {
				$block_logo = render_block(
					array(
						'blockName'    => 'core/site-logo',
						'attrs'        => array( 'width' => 72 ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					)
				);
				if ( is_string( $block_logo ) && false !== strpos( $block_logo, '<img' ) ) {
					return $block_logo;
				}
			}

			$custom_logo_id = absint( (string) get_theme_mod( 'custom_logo', 0 ) );
			if ( $custom_logo_id > 0 ) {
				$custom_logo = wp_get_attachment_image( $custom_logo_id, array( 72, 72 ), false, array( 'class' => 'league-logo-img' ) );
				if ( is_string( $custom_logo ) && '' !== $custom_logo ) {
					return $custom_logo;
				}
			}

			$site_logo_id = absint( (string) get_option( 'site_logo', 0 ) );
			if ( $site_logo_id > 0 ) {
				$site_logo = wp_get_attachment_image( $site_logo_id, array( 72, 72 ), false, array( 'class' => 'league-logo-img' ) );
				if ( is_string( $site_logo ) && '' !== $site_logo ) {
					return $site_logo;
				}
			}

			return '';
		}

		/**
		 * Check whether venue should inherit team primary color.
		 *
		 * @param int $season_id Season ID.
		 * @param int $venue_id  Venue ID.
		 * @return bool
		 */
		private function should_use_team_primary_for_venue( $season_id, $venue_id ) {
			if ( $season_id <= 0 || $venue_id <= 0 ) {
				return false;
			}

			$flags      = $this->get_venue_primary_flags();
			$season_key = (string) $season_id;
			$venue_key  = (string) $venue_id;

			return isset( $flags[ $season_key ], $flags[ $season_key ][ $venue_key ] ) && '1' === (string) $flags[ $season_key ][ $venue_key ];
		}

		/**
		 * Resolve team palette.
		 *
		 * @param int $team_id Team ID.
		 * @return array
		 */
		private function get_team_color_palette( $team_id ) {
			$option_colors = get_option( 'sportspress_frontend_css_colors', array() );
			$team_colors   = get_post_meta( $team_id, 'sp_colors', true );
			$primary       = '';
			$secondary     = '';
			$accent        = '';

			if ( is_array( $team_colors ) ) {
				$primary   = $this->sanitize_color( (string) ( isset( $team_colors['primary'] ) ? $team_colors['primary'] : ( isset( $team_colors['link'] ) ? $team_colors['link'] : '' ) ) );
				$secondary = $this->sanitize_color( (string) ( isset( $team_colors['heading'] ) ? $team_colors['heading'] : ( isset( $team_colors['background'] ) ? $team_colors['background'] : '' ) ) );
				$accent    = $this->sanitize_color( (string) ( isset( $team_colors['text'] ) ? $team_colors['text'] : '' ) );
			}

			if ( '' === $primary ) {
				$primary = $this->sanitize_color( (string) get_post_meta( $team_id, 'sp_color', true ) );
			}
			if ( '' === $secondary ) {
				$secondary = $this->sanitize_color( (string) get_post_meta( $team_id, 'asc_sp_secondary_color', true ) );
			}
			if ( '' === $accent ) {
				$accent = $this->sanitize_color( (string) get_post_meta( $team_id, 'asc_sp_accent_color', true ) );
			}
			if ( '' === $primary ) {
				$primary = $this->sanitize_color( is_array( $option_colors ) && isset( $option_colors['primary'] ) ? (string) $option_colors['primary'] : '' );
			}
			if ( '' === $secondary ) {
				$secondary = $this->sanitize_color( is_array( $option_colors ) && isset( $option_colors['link'] ) ? (string) $option_colors['link'] : '' );
			}
			if ( '' === $accent ) {
				$accent = $this->sanitize_color( is_array( $option_colors ) && isset( $option_colors['heading'] ) ? (string) $option_colors['heading'] : '' );
			}
			if ( '' === $primary ) {
				$primary = '#1B76D1';
			}
			if ( '' === $secondary ) {
				$secondary = '#8B3F1F';
			}
			if ( '' === $accent ) {
				$accent = $primary;
			}

			return array(
				'primary'   => $primary,
				'secondary' => $secondary,
				'accent'    => $accent,
				'ink'       => '#111827',
				'muted_ink' => '#334155',
			);
		}

		/**
		 * Get neutral printable colors for combined-team schedules.
		 *
		 * @return array
		 */
		private function get_default_printable_palette() {
			return array(
				'primary'   => '#334155',
				'secondary' => '#64748B',
				'accent'    => '#475569',
				'ink'       => '#111827',
				'muted_ink' => '#334155',
			);
		}

		/**
		 * Sanitize a six-digit hex color.
		 *
		 * @param string $color Input color.
		 * @return string
		 */
		private function sanitize_color( $color ) {
			$value = trim( $color );
			if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
				return '';
			}

			return strtoupper( $value );
		}

		/**
		 * Get readable foreground.
		 *
		 * @param string $background_hex Background.
		 * @return string
		 */
		private function get_readable_text_color( $background_hex ) {
			$hex = ltrim( trim( $background_hex ), '#' );
			if ( 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
				return '#FFFFFF';
			}

			$red            = hexdec( substr( $hex, 0, 2 ) );
			$green          = hexdec( substr( $hex, 2, 2 ) );
			$blue           = hexdec( substr( $hex, 4, 2 ) );
			$luminance      = $this->relative_luminance( $red, $green, $blue );
			$contrast_white = 1.05 / ( $luminance + 0.05 );
			$contrast_black = ( $luminance + 0.05 ) / 0.05;

			return $contrast_black > $contrast_white ? '#000000' : '#FFFFFF';
		}

		/**
		 * Ensure minimum contrast.
		 *
		 * @param string $background_hex Background.
		 * @param string $foreground_hex Foreground.
		 * @param float  $target_ratio   Ratio target.
		 * @return string
		 */
		private function ensure_minimum_contrast( $background_hex, $foreground_hex, $target_ratio ) {
			$background_rgb = $this->hex_to_rgb( $background_hex );
			$foreground_rgb = $this->hex_to_rgb( $foreground_hex );
			if ( null === $background_rgb || null === $foreground_rgb ) {
				return $background_hex;
			}

			$current  = $background_rgb;
			$mix_with = '#FFFFFF' === strtoupper( $foreground_hex ) ? array( 0, 0, 0 ) : array( 255, 255, 255 );

			for ( $i = 0; $i < 24; $i++ ) {
				if ( $this->contrast_ratio( $current, $foreground_rgb ) >= $target_ratio ) {
					return $this->rgb_to_hex( $current );
				}

				$current = $this->mix_rgb( $current, $mix_with, 0.08 );
			}

			return $this->rgb_to_hex( $current );
		}

		/**
		 * Sanitize hex color.
		 *
		 * @param string $value Input color.
		 * @return string
		 */
		private function sanitize_hex_color( $value ) {
			$hex = strtoupper( trim( $value ) );
			if ( 1 !== preg_match( '/^#[0-9A-F]{6}$/', $hex ) ) {
				return '';
			}

			return $hex;
		}

		/**
		 * Force dark-enough background for white text.
		 *
		 * @param string $background_hex Background color.
		 * @param float  $min_ratio      Minimum ratio.
		 * @return string
		 */
		private function adjust_for_white_text( $background_hex, $min_ratio ) {
			$rgb = $this->hex_to_rgb( $background_hex );
			if ( null === $rgb ) {
				return '#1E3A8A';
			}

			$white   = array( 255, 255, 255 );
			$current = $rgb;
			for ( $i = 0; $i < 30; $i++ ) {
				if ( $this->contrast_ratio( $current, $white ) >= $min_ratio ) {
					return $this->rgb_to_hex( $current );
				}

				$current = $this->mix_rgb( $current, array( 0, 0, 0 ), 0.08 );
			}

			return $this->rgb_to_hex( $current );
		}

		/**
		 * Contrast ratio.
		 *
		 * @param array $background_rgb Background RGB.
		 * @param array $foreground_rgb Foreground RGB.
		 * @return float
		 */
		private function contrast_ratio( $background_rgb, $foreground_rgb ) {
			$bg_luminance = $this->relative_luminance( $background_rgb[0], $background_rgb[1], $background_rgb[2] );
			$fg_luminance = $this->relative_luminance( $foreground_rgb[0], $foreground_rgb[1], $foreground_rgb[2] );
			$lighter      = max( $bg_luminance, $fg_luminance );
			$darker       = min( $bg_luminance, $fg_luminance );

			return ( $lighter + 0.05 ) / ( $darker + 0.05 );
		}

		/**
		 * Convert hex to RGB.
		 *
		 * @param string $hex Hex input.
		 * @return array|null
		 */
		private function hex_to_rgb( $hex ) {
			$value = ltrim( trim( $hex ), '#' );
			if ( 1 !== preg_match( '/^[0-9a-fA-F]{6}$/', $value ) ) {
				return null;
			}

			return array(
				hexdec( substr( $value, 0, 2 ) ),
				hexdec( substr( $value, 2, 2 ) ),
				hexdec( substr( $value, 4, 2 ) ),
			);
		}

		/**
		 * Convert RGB to hex.
		 *
		 * @param array $rgb RGB triplet.
		 * @return string
		 */
		private function rgb_to_hex( $rgb ) {
			return sprintf( '#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2] );
		}

		/**
		 * Mix two RGB values.
		 *
		 * @param array $source Source RGB.
		 * @param array $target Target RGB.
		 * @param float $weight Weight.
		 * @return array
		 */
		private function mix_rgb( $source, $target, $weight ) {
			$mix = function ( $start, $end, $ratio ) {
				return (int) max( 0, min( 255, round( $start + ( $end - $start ) * $ratio ) ) );
			};

			return array(
				$mix( $source[0], $target[0], $weight ),
				$mix( $source[1], $target[1], $weight ),
				$mix( $source[2], $target[2], $weight ),
			);
		}

		/**
		 * Relative luminance.
		 *
		 * @param int $red   Red channel.
		 * @param int $green Green channel.
		 * @param int $blue  Blue channel.
		 * @return float
		 */
		private function relative_luminance( $red, $green, $blue ) {
			$r = $this->channel_luminance( $red / 255 );
			$g = $this->channel_luminance( $green / 255 );
			$b = $this->channel_luminance( $blue / 255 );

			return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		}

		/**
		 * Luminance for a single channel.
		 *
		 * @param float $channel Channel value.
		 * @return float
		 */
		private function channel_luminance( $channel ) {
			if ( $channel <= 0.03928 ) {
				return $channel / 12.92;
			}

			return ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		}

		/**
		 * Determine sheet layout.
		 *
		 * @param int    $month_count Number of months.
		 * @param string $paper       Paper size.
		 * @return array
		 */
		private function get_sheet_layout( $month_count, $paper ) {
			$count   = max( 1, (int) $month_count );
			$columns = 1;

			if ( $count <= 4 ) {
				$columns = 2;
			} elseif ( $count <= 9 ) {
				$columns = 3;
			} elseif ( $count <= 16 ) {
				$columns = 4;
			} else {
				$columns = 5;
			}

			$rows = (int) ceil( $count / $columns );

			$font_scale = 1.0;
			if ( $count >= 3 && $count <= 4 ) {
				$font_scale = 0.9;
			} elseif ( $count >= 5 && $count <= 6 ) {
				$font_scale = 0.78;
			} elseif ( $count > 6 && $count <= 9 ) {
				$font_scale = 0.68;
			} elseif ( $count > 9 ) {
				$font_scale = 0.58;
			}

			$sheet_scale = 1.0;
			if ( $count > 12 ) {
				$sheet_scale = max( 0.42, 12 / $count );
			}

			$day_aspect = '4 / 5';
			if ( $count >= 5 && $count <= 6 ) {
				$day_aspect = '1 / 1';
			} elseif ( $count >= 7 && $count <= 9 ) {
				$day_aspect = '6 / 5';
			} elseif ( $count > 9 ) {
				$day_aspect = '5 / 4';
			}

			if ( 'ledger' === $paper && $count <= 2 ) {
				$day_aspect = '1 / 1';
			}

			return array(
				'columns'     => $columns,
				'rows'        => $rows,
				'font_scale'  => $font_scale,
				'day_aspect'  => $day_aspect,
				'sheet_scale' => $sheet_scale,
			);
		}
	}
}

/**
 * Bootstrap printable calendars after plugins load.
 */
function tony_sportspress_printable_calendars_boot() {
	Tony_Sportspress_Printable_Calendars::instance()->boot();
}
add_action( 'plugins_loaded', 'tony_sportspress_printable_calendars_boot' );
