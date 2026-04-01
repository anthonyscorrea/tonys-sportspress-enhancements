<?php
/**
 * Tony's Settings CSV URL builder tab.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the URL builder tab label.
 *
 * @param array $tabs Existing tab map.
 * @return array
 */
function tse_sp_url_builder_register_tab( $tabs ) {
	$tabs['url-builder'] = __( 'Feed Builder', 'tonys-sportspress-enhancements' );

	return $tabs;
}
add_filter( 'tse_tonys_settings_tabs', 'tse_sp_url_builder_register_tab' );

/**
 * Render the URL builder tab.
 *
 * @return void
 */
function tse_sp_url_builder_render_tab() {
	$leagues = function_exists( 'tse_sp_schedule_exporter_get_leagues' ) ? tse_sp_schedule_exporter_get_leagues() : array();
	$seasons = function_exists( 'tse_sp_schedule_exporter_get_seasons' ) ? tse_sp_schedule_exporter_get_seasons() : array();
	$teams   = function_exists( 'tse_sp_schedule_exporter_get_teams' ) ? tse_sp_schedule_exporter_get_teams() : array();
	$fields  = function_exists( 'tse_sp_schedule_exporter_get_fields' ) ? tse_sp_schedule_exporter_get_fields() : array();
	$formats = function_exists( 'tse_sp_event_export_get_formats' ) ? tse_sp_event_export_get_formats() : array();
	$columns = function_exists( 'tse_sp_event_export_get_column_definitions' ) ? tse_sp_event_export_get_column_definitions() : array();

	echo '<h2>' . esc_html__( 'Feed Builder', 'tonys-sportspress-enhancements' ) . '</h2>';
	echo '<p>' . esc_html__( 'Build a shareable CSV feed URL with format, filters, and custom columns. This does not save settings.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '<div class="tse-url-builder" style="max-width:1100px;padding:20px 24px;border:1px solid #dcdcde;background:#fff;">';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row"><label for="tse-url-builder-feed-type">' . esc_html__( 'Feed Type', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
	echo '<select id="tse-url-builder-feed-type">';
	echo '<option value="csv">' . esc_html__( 'CSV', 'tonys-sportspress-enhancements' ) . '</option>';
	echo '<option value="ics">' . esc_html__( 'iCal / ICS', 'tonys-sportspress-enhancements' ) . '</option>';
	echo '</select>';
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="tse-url-builder-format">' . esc_html__( 'Format', 'tonys-sportspress-enhancements' ) . '</label></th><td>';
	echo '<select id="tse-url-builder-format">';
	foreach ( $formats as $format_key => $format ) {
		echo '<option value="' . esc_attr( $format_key ) . '">' . esc_html( $format['label'] ) . '</option>';
	}
	echo '</select>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'League', 'tonys-sportspress-enhancements' ) . '</th><td>';
	echo '<div id="tse-url-builder-league" style="display:flex;flex-wrap:wrap;gap:10px 14px;max-width:720px;">';
	foreach ( $leagues as $league ) {
		$input_id = 'tse-url-builder-league-' . absint( $league->term_id );
		echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-tse-builder-filter="league_id" value="' . esc_attr( (string) $league->term_id ) . '" />';
		echo esc_html( $league->name );
		echo '</label>';
	}
	echo '</div>';
	echo '<p class="description">' . esc_html__( 'Select one or more leagues. Leave empty to include all leagues.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Season', 'tonys-sportspress-enhancements' ) . '</th><td>';
	echo '<div id="tse-url-builder-season" style="display:flex;flex-wrap:wrap;gap:10px 14px;max-width:720px;">';
	foreach ( $seasons as $season ) {
		$input_id = 'tse-url-builder-season-' . absint( $season->term_id );
		echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-tse-builder-filter="season_id" value="' . esc_attr( (string) $season->term_id ) . '" />';
		echo esc_html( $season->name );
		echo '</label>';
	}
	echo '</div>';
	echo '<p class="description">' . esc_html__( 'Select one or more seasons. Leave empty to include all seasons.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Team', 'tonys-sportspress-enhancements' ) . '</th><td>';
	echo '<div id="tse-url-builder-team" style="display:flex;flex-wrap:wrap;gap:10px 14px;max-width:720px;">';
	foreach ( $teams as $team ) {
		$input_id = 'tse-url-builder-team-' . absint( $team->ID );
		echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-tse-builder-filter="team_id" value="' . esc_attr( (string) $team->ID ) . '" />';
		echo esc_html( $team->post_title );
		echo '</label>';
	}
	echo '</div>';
	echo '<p class="description">' . esc_html__( 'Select one or more teams. Team format requires exactly one team.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Field', 'tonys-sportspress-enhancements' ) . '</th><td>';
	echo '<div id="tse-url-builder-field" style="display:flex;flex-wrap:wrap;gap:10px 14px;max-width:720px;">';
	foreach ( $fields as $field ) {
		$input_id = 'tse-url-builder-field-' . absint( $field->term_id );
		echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
		echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-tse-builder-filter="field_id" value="' . esc_attr( (string) $field->term_id ) . '" />';
		echo esc_html( $field->name );
		echo '</label>';
	}
	echo '</div>';
	echo '<p class="description">' . esc_html__( 'Select one or more fields. Leave empty to include all fields.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</td></tr>';

	echo '</tbody></table>';

	echo '<div style="display:grid;gap:16px;margin-top:20px;">';
	foreach ( $columns as $format_key => $format_columns ) {
		$default_columns = function_exists( 'tse_sp_event_export_get_default_columns' ) ? tse_sp_event_export_get_default_columns( $format_key ) : array();
		$label           = isset( $formats[ $format_key ]['label'] ) ? $formats[ $format_key ]['label'] : ucfirst( $format_key );

		echo '<fieldset data-tse-builder-columns="' . esc_attr( $format_key ) . '" style="margin:0;padding:16px;border:1px solid #d7d7db;">';
		echo '<legend><strong>' . esc_html( sprintf( __( '%s Columns', 'tonys-sportspress-enhancements' ), $label ) ) . '</strong></legend>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px 18px;">';
		foreach ( $format_columns as $column_key => $column_label ) {
			$input_id = 'tse-url-builder-' . sanitize_html_class( $format_key . '-' . $column_key );
			echo '<label for="' . esc_attr( $input_id ) . '" style="display:inline-flex;align-items:center;gap:6px;">';
			echo '<input id="' . esc_attr( $input_id ) . '" type="checkbox" data-tse-builder-column="' . esc_attr( $format_key ) . '" value="' . esc_attr( $column_key ) . '" ' . checked( in_array( $column_key, $default_columns, true ), true, false ) . ' />';
			echo esc_html( $column_label );
			echo '</label>';
		}
		echo '</div>';
		echo '</fieldset>';
	}
	echo '</div>';

	echo '<h3 style="margin-top:24px;">' . esc_html__( 'Generated URL', 'tonys-sportspress-enhancements' ) . '</h3>';
	echo '<div style="display:flex;align-items:center;gap:8px;max-width:100%;">';
	echo '<input type="text" id="tse-url-builder-output" class="large-text code" readonly="readonly" />';
	echo '<button type="button" id="tse-url-builder-copy" class="button" aria-label="' . esc_attr__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '" title="' . esc_attr__( 'Copy URL', 'tonys-sportspress-enhancements' ) . '" style="display:inline-flex;align-items:center;justify-content:center;min-width:40px;padding:0 10px;">';
	echo '<span aria-hidden="true" style="font-size:16px;line-height:1;">⧉</span>';
	echo '</button>';
	echo '</div>';
	echo '<p><a id="tse-url-builder-open" class="button button-primary" href="' . esc_url( home_url( '/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Feed URL', 'tonys-sportspress-enhancements' ) . '</a></p>';
	echo '<p class="description">' . esc_html__( 'The builder always generates the standalone CSV feed endpoint using the selected filters and columns.', 'tonys-sportspress-enhancements' ) . '</p>';
	echo '</div>';

	tse_sp_url_builder_render_script();
}
add_action( 'tse_tonys_settings_render_tab_url-builder', 'tse_sp_url_builder_render_tab' );

/**
 * Render builder script.
 *
 * @return void
 */
function tse_sp_url_builder_render_script() {
	$base_url = home_url( '/' );
	?>
	<script>
	(function(){
		var root = document.querySelector('.tse-url-builder');
		if (!root) {
			return;
		}

		var baseUrl = <?php echo wp_json_encode( $base_url ); ?>;
		var feedType = root.querySelector('#tse-url-builder-feed-type');
		var format = root.querySelector('#tse-url-builder-format');
		var output = root.querySelector('#tse-url-builder-output');
		var copyButton = root.querySelector('#tse-url-builder-copy');
		var openLink = root.querySelector('#tse-url-builder-open');

		function getSelectedValues(filterName) {
			return Array.prototype.slice.call(root.querySelectorAll('[data-tse-builder-filter="' + filterName + '"]:checked')).map(function(input){
				return input.value;
			});
		}

		function syncColumnGroups() {
			var selectedFormat = format.value || 'matchup';
			var isCsv = (feedType.value || 'csv') === 'csv';
			root.querySelectorAll('[data-tse-builder-columns]').forEach(function(group){
				group.style.display = (isCsv && group.getAttribute('data-tse-builder-columns') === selectedFormat) ? 'block' : 'none';
			});
		}

		function buildUrl() {
			var selectedFeedType = feedType.value || 'csv';
			var selectedFormat = format.value || 'matchup';
			var url = new URL(baseUrl, window.location.origin);
			var leagues = getSelectedValues('league_id');
			var seasons = getSelectedValues('season_id');
			var teams = getSelectedValues('team_id');
			var fields = getSelectedValues('field_id');
			var selectedColumns = Array.prototype.slice.call(root.querySelectorAll('[data-tse-builder-column="' + selectedFormat + '"]:checked')).map(function(input){
				return input.value;
			}).filter(Boolean);

			url.searchParams.set('feed', selectedFeedType === 'ics' ? 'sp-ics' : 'sp-csv');
			url.searchParams.set('format', selectedFormat);

			if (leagues.length) {
				url.searchParams.set('league_id', leagues.join(','));
			} else {
				url.searchParams.delete('league_id');
			}
			if (seasons.length) {
				url.searchParams.set('season_id', seasons.join(','));
			} else {
				url.searchParams.delete('season_id');
			}
			if (teams.length) {
				url.searchParams.set('team_id', teams.join(','));
			} else {
				url.searchParams.delete('team_id');
			}
			if (fields.length) {
				url.searchParams.set('field_id', fields.join(','));
			} else {
				url.searchParams.delete('field_id');
			}
			if (selectedFeedType === 'csv' && selectedColumns.length) {
				url.searchParams.set('columns', selectedColumns.join(','));
			} else {
				url.searchParams.delete('columns');
			}

			output.value = url.toString();
			openLink.href = url.toString();
			openLink.textContent = selectedFeedType === 'ics' ? 'Open ICS Feed' : 'Open Feed URL';
		}

		syncColumnGroups();
		buildUrl();

		[feedType, format].forEach(function(input){
			input.addEventListener('change', function(){
				syncColumnGroups();
				buildUrl();
			});
		});

		root.querySelectorAll('[data-tse-builder-filter]').forEach(function(input){
			input.addEventListener('change', buildUrl);
		});

		root.querySelectorAll('[data-tse-builder-column]').forEach(function(input){
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
					navigator.clipboard.writeText(value).then(function(){
						markCopied();
					}).catch(function(){
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
	})();
	</script>
	<?php
}
