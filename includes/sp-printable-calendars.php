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
			$vars[] = 'paper';
			$vars[] = 'autoprint';

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

			$current = wp_parse_args(
				is_array( $input ) ? $input : array(),
				$existing
			);

			return array(
				'calendar_feed_url'     => isset( $existing['calendar_feed_url'] ) && is_string( $existing['calendar_feed_url'] ) ? $existing['calendar_feed_url'] : '',
				'sync_interval_minutes' => isset( $existing['sync_interval_minutes'] ) ? absint( (string) $existing['sync_interval_minutes'] ) : 60,
				'venue_color_overrides' => $this->sanitize_venue_color_overrides( isset( $current['venue_color_overrides'] ) ? $current['venue_color_overrides'] : array() ),
				'venue_use_team_primary' => $this->sanitize_venue_primary_flags( isset( $current['venue_use_team_primary'] ) ? $current['venue_use_team_primary'] : array() ),
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
			$season_id            = $this->selected_season_id();
			$seasons              = $this->get_seasons();
			$venues               = $this->get_venues_for_season( $season_id );
			$overrides            = $this->get_venue_color_overrides();
			$primary_flags        = $this->get_venue_primary_flags();
			$season_key           = (string) $season_id;
			$season_overrides     = isset( $overrides[ $season_key ] ) && is_array( $overrides[ $season_key ] ) ? $overrides[ $season_key ] : array();
			$season_primary_flags = isset( $primary_flags[ $season_key ] ) && is_array( $primary_flags[ $season_key ] ) ? $primary_flags[ $season_key ] : array();

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Tony\'s Settings', 'tonys-sportspress-enhancements' ) . '</h1>';
			$this->render_settings_tabs( $current_tab );
			echo '<form method="post" action="options.php">';
			settings_fields( self::OPTION_GROUP );

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
			foreach ( $this->get_venue_label_mode_options() as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $this->get_venue_label_mode(), $mode, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Choose whether the printable schedule shows the venue full name, abbreviation, or short name below each game time.', 'tonys-sportspress-enhancements' ) . '</p>';
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
					$venue_id    = (int) $venue['id'];
					$venue_name  = isset( $venue['name'] ) ? (string) $venue['name'] : '';
					$saved       = isset( $season_overrides[ (string) $venue_id ] ) && is_string( $season_overrides[ (string) $venue_id ] ) ? $season_overrides[ (string) $venue_id ] : '';
					$suggested   = $this->suggested_palette[ $index % max( 1, $palette_count ) ];
					$value       = '' !== $saved ? $saved : $suggested;
					$adjusted    = $this->adjust_for_white_text( $value, self::MIN_WHITE_CONTRAST );
					$name        = self::OPTION_KEY . '[venue_color_overrides][' . $season_key . '][' . $venue_id . ']';
					$primary_name = self::OPTION_KEY . '[venue_use_team_primary][' . $season_key . '][' . $venue_id . ']';
					$use_primary = isset( $season_primary_flags[ (string) $venue_id ] ) && '1' === $season_primary_flags[ (string) $venue_id ];

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
			echo '</div>';
		}

		/**
		 * Render Tony's settings tabs.
		 *
		 * @param string $current_tab Current tab key.
		 */
		private function render_settings_tabs( $current_tab ) {
			$tabs = array(
				self::TAB_PRINTABLE => __( 'Printable Calendars', 'tonys-sportspress-enhancements' ),
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

				$class = self::TAB_PRINTABLE === $tab && self::TAB_PRINTABLE === $current_tab ? ' nav-tab-active' : '';
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
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_PRINTABLE;

			return self::TAB_PRINTABLE === $tab ? $tab : self::TAB_PRINTABLE;
		}

		/**
		 * Render the printable page when the flag is present.
		 */
		public function maybe_render() {
			if ( '1' !== (string) get_query_var( self::QUERY_FLAG ) ) {
				return;
			}

			$team_id = absint( (string) get_query_var( 'sp_team' ) );
			if ( $team_id <= 0 || 'sp_team' !== get_post_type( $team_id ) ) {
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

			$paper = $this->normalize_paper_size( (string) get_query_var( 'paper' ) );
			$autoprint = '1' === (string) get_query_var( 'autoprint' );

			$team_name               = get_the_title( $team_id );
			$team_logo               = get_the_post_thumbnail( $team_id, array( 72, 72 ), array( 'class' => 'team-logo-img' ) );
			$brand_logo              = $this->get_header_brand_logo();
			$site_url                = home_url( '/' );
			$qr_url                  = 'https://api.qrserver.com/v1/create-qr-code/?size=144x144&data=' . rawurlencode( $site_url );
			$season_name             = '';
			$entries                 = $this->get_schedule_entries( $team_id, $season_id, $league_id );
			$team_palette            = $this->get_team_color_palette( $team_id );
			$team_primary_for_fields = $this->get_strict_team_primary_color( $team_id );
			$entries_by_day          = array();
			$venue_colors            = array();
			$month_keys              = array();
			$layout                  = array();

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
						'name'  => (string) $entry['venue_name'],
						'color' => $this->get_venue_color( (string) $entry['venue_name'], $season_id, (int) $entry['venue_id'], $team_primary_for_fields ),
					);
				}
			}

			$month_keys = $this->get_month_keys( $entries );
			$layout     = $this->get_sheet_layout( count( $month_keys ), $paper );

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
			echo '<body class="print-preview ' . esc_attr( $paper ) . '">';

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
			echo '<div class="print-page">';
			echo '<header class="header">';
			echo '<div class="header-brand">';
			if ( '' !== $team_logo ) {
				echo '<span class="team-logo" aria-hidden="true">' . wp_kses_post( $team_logo ) . '</span>';
			}
			echo '<div class="header-copy">';
			echo '<h1 class="title">' . esc_html( $team_name ) . '</h1>';
			echo '<p class="meta">' . esc_html( $season_name ? $season_name : __( 'Current', 'tonys-sportspress-enhancements' ) ) . '</p>';
			echo '</div>';
			echo '</div>';

			if ( '' !== $brand_logo ) {
				echo '<span class="league-logo" aria-hidden="true">' . wp_kses_post( $brand_logo ) . '</span>';
			}
			echo '</header>';

			if ( empty( $entries ) ) {
				echo '<section class="empty">';
				echo '<p>' . esc_html__( 'No SportsPress events were found for this team and season.', 'tonys-sportspress-enhancements' ) . '</p>';
				echo '</section>';
			} else {
				echo '<section class="sheet-grid">';
				foreach ( $month_keys as $month_key ) {
					$this->render_month_grid( $month_key, $entries_by_day, $venue_colors, $team_palette );
				}
				echo '</section>';
			}

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
			echo '</div>';
			echo '</main>';
			echo '</body></html>';
			exit;
		}

		/**
		 * Build the printable route URL.
		 *
		 * @param int    $team_id   Team ID.
		 * @param int    $season_id Season ID.
		 * @param string $paper     Paper size.
		 * @return string
		 */
		private function build_url( $team_id, $season_id, $paper, $league_id = 0 ) {
			$paper = $this->normalize_paper_size( $paper );

			return add_query_arg(
				array(
					self::QUERY_FLAG => '1',
					'sp_team'        => (string) absint( $team_id ),
					'sp_season'      => $season_id > 0 ? (string) absint( $season_id ) : '',
					'sp_league'      => $league_id > 0 ? (string) absint( $league_id ) : '',
					'paper'          => (string) $paper,
				),
				home_url( '/' )
			);
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
				'printable_venue_label_mode' => 'full_name',
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
		 * Get supported venue label modes.
		 *
		 * @return array
		 */
		private function get_venue_label_mode_options() {
			return array(
				'full_name'    => __( 'Full Name', 'tonys-sportspress-enhancements' ),
				'abbreviation' => __( 'Abbreviation', 'tonys-sportspress-enhancements' ),
				'short_name'   => __( 'Short Name', 'tonys-sportspress-enhancements' ),
			);
		}

		/**
		 * Sanitize venue label mode.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private function sanitize_venue_label_mode( $value ) {
			$mode    = is_string( $value ) ? sanitize_key( $value ) : '';
			$allowed = array_keys( $this->get_venue_label_mode_options() );

			return in_array( $mode, $allowed, true ) ? $mode : 'full_name';
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
		 * @param int $team_id   Team ID.
		 * @param int $season_id Season ID.
		 * @param int $league_id League ID.
		 * @return array
		 */
		private function get_schedule_entries( $team_id, $season_id, $league_id = 0 ) {
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
						'value'   => array( (string) $team_id ),
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
				if ( ! in_array( $team_id, $teams, true ) ) {
					continue;
				}

				$timestamp = (int) get_post_time( 'U', true, $event_id, true );
				if ( $timestamp <= 0 ) {
					continue;
				}

				$opponent_id = 0;
				foreach ( $teams as $team_option_id ) {
					if ( $team_option_id !== $team_id ) {
						$opponent_id = $team_option_id;
						break;
					}
				}

				$venue        = $this->get_event_venue_term( $event_id );
				$venue_name   = '';
				$venue_id     = 0;
				$venue_label  = '';
				if ( $venue ) {
					$venue_name  = (string) $venue->name;
					$venue_id    = (int) $venue->term_id;
					$venue_label = $this->get_configured_venue_label( $venue );
				}

				$entries[] = array(
					'event_id'       => $event_id,
					'day_key'        => wp_date( 'Y-m-d', $timestamp ),
					'month_key'      => wp_date( 'Y-m', $timestamp ),
					'timestamp'      => $timestamp,
					'is_home'        => isset( $teams[0] ) && $teams[0] === $team_id,
					'opponent_id'    => $opponent_id,
					'opponent_name'  => $opponent_id > 0 ? $this->get_team_label( $opponent_id ) : __( 'TBD', 'tonys-sportspress-enhancements' ),
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
		 * Render a single month grid.
		 *
		 * @param string $month_key      Month key.
		 * @param array  $entries_by_day Entries keyed by day.
		 * @param array  $venue_colors   Venue colors.
		 * @param array  $team_palette   Team palette.
		 */
		private function render_month_grid( $month_key, $entries_by_day, $venue_colors, $team_palette ) {
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
				$day_class   = ! empty( $day_entries ) ? 'day has-events' : 'day no-events';
				$day_style   = '';

				if ( ! empty( $day_entries ) ) {
					$first_entry       = $day_entries[0];
					$first_is_home     = ! empty( $first_entry['is_home'] );
					$first_venue_key   = isset( $first_entry['venue_key'] ) && is_string( $first_entry['venue_key'] ) ? $first_entry['venue_key'] : '';
					$first_venue_color = ( '' !== $first_venue_key && isset( $venue_colors[ $first_venue_key ]['color'] ) ) ? (string) $venue_colors[ $first_venue_key ]['color'] : '';
					$first_background  = '' !== $first_venue_color ? $first_venue_color : ( $first_is_home ? $team_palette['primary'] : $team_palette['secondary'] );
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
						$event_class      = $is_home ? 'h' : 'a';
						$venue_key        = isset( $entry['venue_key'] ) && is_string( $entry['venue_key'] ) ? $entry['venue_key'] : '';
						$venue_color      = ( '' !== $venue_key && isset( $venue_colors[ $venue_key ]['color'] ) ) ? (string) $venue_colors[ $venue_key ]['color'] : '';
						$opponent_id      = isset( $entry['opponent_id'] ) ? (int) $entry['opponent_id'] : 0;
						$logo             = $opponent_id > 0 ? get_the_post_thumbnail( $opponent_id, 'medium', array( 'class' => 'event-logo-img', 'loading' => 'eager', 'decoding' => 'async' ) ) : '';
						$has_logo         = '' !== $logo;
						$event_background = '' !== $venue_color ? $venue_color : ( $is_home ? $team_palette['primary'] : $team_palette['secondary'] );
						$event_foreground = $this->get_readable_text_color( $event_background );
						$event_background = $this->ensure_minimum_contrast( $event_background, $event_foreground, self::MIN_WHITE_CONTRAST );
						$event_foreground = $this->get_readable_text_color( $event_background );
						$event_style      = ' style="--event-bg:' . esc_attr( $event_background ) . ';--event-fg:' . esc_attr( $event_foreground ) . ';"';

						echo '<article class="event ' . esc_attr( $event_class . ' ' . ( $has_logo ? 'has-logo' : 'no-logo' ) ) . '"' . $event_style . '>';
						echo '<div class="event-center">';
						if ( $has_logo ) {
							echo wp_kses_post( $logo );
						} else {
							echo '<span class="event-name">' . esc_html( isset( $entry['opponent_name'] ) ? (string) $entry['opponent_name'] : '' ) . '</span>';
						}
						echo '</div>';
						echo '<span class="ha-flag">' . esc_html( $is_home ? 'H' : 'A' ) . '</span>';
						echo '<div class="event-meta">';
						echo '<div class="event-time">' . esc_html( isset( $entry['event_time'] ) ? (string) $entry['event_time'] : '' ) . '</div>';
						if ( ! empty( $entry['venue_label'] ) ) {
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
		private function get_team_label( $team_id ) {
			if ( function_exists( 'sp_team_abbreviation' ) ) {
				$label = (string) sp_team_abbreviation( $team_id );
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
		private function get_configured_venue_label( $venue ) {
			$full_name = isset( $venue->name ) ? trim( (string) $venue->name ) : '';
			if ( '' === $full_name || ! isset( $venue->term_id ) ) {
				return '';
			}

			$term_id = (int) $venue->term_id;
			$mode    = $this->get_venue_label_mode();

			if ( 'abbreviation' === $mode ) {
				$abbreviation = trim( (string) get_term_meta( $term_id, 'tse_abbreviation', true ) );
				return '' !== $abbreviation ? $abbreviation : $full_name;
			}

			if ( 'short_name' === $mode ) {
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
		 * @param string $team_primary  Team primary color.
		 * @return string
		 */
		private function get_venue_color( $venue_name, $season_id, $venue_id, $team_primary ) {
			if ( $this->should_use_team_primary_for_venue( $season_id, $venue_id ) && '' !== $this->sanitize_color( $team_primary ) ) {
				return $this->sanitize_color( $team_primary );
			}

			$custom = $this->get_custom_venue_color( $season_id, $venue_id );
			if ( '' !== $custom ) {
				return $custom;
			}

			$palette = array( '#1D4ED8', '#DC2626', '#A16207', '#15803D', '#0E7490', '#BE185D', '#6D28D9', '#C2410C' );
			$index   = abs( crc32( strtolower( $venue_name ) ) ) % count( $palette );

			return $palette[ $index ];
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
