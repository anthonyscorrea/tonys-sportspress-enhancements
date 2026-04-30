<?php
/**
 * Venue term metadata support.
 *
 * Adds short name, abbreviation, and ground rules fields to SportsPress venues.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register venue term meta.
 */
function tony_sportspress_register_venue_term_meta() {
	register_term_meta(
		'sp_venue',
		'tse_short_name',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
			'auth_callback'     => static function() {
				return current_user_can( 'manage_categories' );
			},
		)
	);

	register_term_meta(
		'sp_venue',
		'tse_abbreviation',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
			'auth_callback'     => static function() {
				return current_user_can( 'manage_categories' );
			},
		)
	);

	register_term_meta(
		'sp_venue',
		'tse_ground_rules',
		array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'wp_kses_post',
			'show_in_rest'      => true,
			'auth_callback'     => static function() {
				return current_user_can( 'manage_categories' );
			},
		)
	);
}
add_action( 'init', 'tony_sportspress_register_venue_term_meta' );

/**
 * Determine whether the field page should show its event list.
 *
 * The setting is stored in the core SportsPress Events settings page under
 * the venue/field section.
 *
 * @return bool
 */
function tony_sportspress_field_event_list_enabled() {
	$enabled = get_option( 'sportspress_event_show_venue_list', 'yes' ) === 'yes';

	return (bool) apply_filters( 'tony_sportspress_field_event_list_enabled', $enabled );
}

/**
 * Get the venue map caption text.
 *
 * @return string
 */
function tony_sportspress_get_venue_map_caption() {
	return (string) apply_filters( 'tony_sportspress_venue_map_caption', __( 'Field Map', 'tonys-sportspress-enhancements' ) );
}

/**
 * Add the field page event list setting to SportsPress > Settings > Games > Fields.
 *
 * @param array $options Existing venue settings.
 * @return array
 */
function tony_sportspress_add_venue_settings( $options ) {
	$options[] = array(
		'title'   => esc_attr__( 'Event List', 'tonys-sportspress-enhancements' ),
		'desc'    => esc_attr__( 'Display event list on field pages', 'tonys-sportspress-enhancements' ),
		'id'      => 'sportspress_event_show_venue_list',
		'default' => 'yes',
		'type'    => 'checkbox',
	);

	return $options;
}
add_filter( 'sportspress_venue_options', 'tony_sportspress_add_venue_settings' );

/**
 * Enqueue the visual editor on the venue taxonomy screen.
 *
 * This turns the built-in description textarea into the standard WordPress
 * TinyMCE editor so venue descriptions can use markup and links.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function tony_sportspress_enqueue_venue_description_editor( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) ) {
		return;
	}

	$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
	if ( 'sp_venue' !== $taxonomy ) {
		return;
	}

	if ( ! function_exists( 'wp_enqueue_editor' ) ) {
		return;
	}

	wp_enqueue_editor();

	$script = <<<'JS'
(function() {
	function initEditor(id) {
		var textarea = document.getElementById(id);
		if (!textarea || textarea.dataset.tseEditorInitialized) {
			return;
		}

		textarea.dataset.tseEditorInitialized = '1';
		window.wp.editor.initialize(id, {
			tinymce: {
				wpautop: true,
				menubar: false,
				statusbar: true,
				toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,spellchecker,fullscreen',
				toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
				block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Preformatted=pre',
			},
			quicktags: true,
			mediaButtons: true
		});
	}

	document.addEventListener('DOMContentLoaded', function() {
		if (!window.wp || !wp.editor) {
			return;
		}

		// Different taxonomy screens use different IDs for the same description field.
		initEditor('description');
		initEditor('tag-description');
	});
})();
JS;

	wp_add_inline_script( 'editor', $script );
}
add_action( 'admin_enqueue_scripts', 'tony_sportspress_enqueue_venue_description_editor' );

/**
 * Hide the built-in venue archive description output.
 *
 * Venue content is rendered as SportsPress-style sections instead of inside
 * the archive header.
 *
 * @param string $description Archive description HTML.
 * @return string
 */
function tony_sportspress_hide_venue_archive_description( $description ) {
	if ( is_tax( 'sp_venue' ) ) {
		return '';
	}

	return $description;
}
add_filter( 'get_the_archive_description', 'tony_sportspress_hide_venue_archive_description', 99 );

/**
 * Determine whether the current venue has map coordinates.
 *
 * @return bool
 */
function tony_sportspress_current_venue_has_map() {
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$latitude  = trim( (string) get_term_meta( $term->term_id, 'sp_latitude', true ) );
	$longitude = trim( (string) get_term_meta( $term->term_id, 'sp_longitude', true ) );

	return '' !== $latitude && '' !== $longitude;
}

/**
 * Render the venue ground rules section.
 *
 * @return void
 */
function tony_sportspress_render_venue_ground_rules_section() {
	if ( ! is_tax( 'sp_venue' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$ground_rules = get_term_meta( $term->term_id, 'tse_ground_rules', true );
	if ( ! is_string( $ground_rules ) || '' === trim( $ground_rules ) ) {
		return;
	}

	$ground_rules = apply_filters( 'the_content', $ground_rules );

	echo '<div class="sp-section-content sp-section-content-details"><div class="sp-template sp-template-venue-details tse-ground-rules"><h4 class="sp-table-caption">' . esc_html__( 'Ground Rules', 'tonys-sportspress-enhancements' ) . '</h4><div class="sp-table-wrapper tse-ground-rules-content">' . $ground_rules . '</div></div></div>';
}
add_action( 'sportspress_before_venue_map', 'tony_sportspress_render_venue_ground_rules_section', 5 );

/**
 * Open the venue map section wrapper.
 *
 * @return void
 */
function tony_sportspress_open_venue_map_section() {
	if ( ! is_tax( 'sp_venue' ) || ! tony_sportspress_current_venue_has_map() ) {
		return;
	}

	$GLOBALS['tse_venue_map_section_open'] = true;
	echo '<div class="sp-section-content sp-section-content-venue"><div class="sp-template sp-template-venue-map"><h4 class="sp-table-caption tse-venue-map-caption">' . esc_html( tony_sportspress_get_venue_map_caption() ) . '</h4><div class="sp-table-wrapper tse-venue-map-content">';
}
add_action( 'sportspress_before_venue_map', 'tony_sportspress_open_venue_map_section', 15 );

/**
 * Close the venue map section wrapper.
 *
 * @return void
 */
function tony_sportspress_close_venue_map_section() {
	if ( empty( $GLOBALS['tse_venue_map_section_open'] ) ) {
		return;
	}

	$GLOBALS['tse_venue_map_section_open'] = false;
	echo '</div></div></div>';
}
add_action( 'sportspress_after_venue_map', 'tony_sportspress_close_venue_map_section', 5 );

/**
 * Enqueue venue section styles.
 *
 * The venue map and ground rules are rendered as SportsPress-style sections
 * rather than archive header content, so they need section-scoped typography
 * and list styling.
 *
 * @return void
 */
function tony_sportspress_enqueue_venue_section_styles() {
	if ( ! is_tax( 'sp_venue' ) ) {
		return;
	}

	wp_register_style( 'tony-sportspress-venue-sections', false, array(), TONY_SPORTSPRESS_ENHANCEMENTS_VERSION );
	wp_enqueue_style( 'tony-sportspress-venue-sections' );

	$event_list_display = tony_sportspress_field_event_list_enabled() ? 'block' : 'none';

	$css = <<<CSS
body.tax-sp_venue .sp-template-venue-map .sp-table-wrapper,
body.tax-sp_venue .sp-template-venue-details .sp-table-wrapper {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-top: 0;
}

body.tax-sp_venue .site-main article.sp_event,
body.tax-sp_venue .sp-template-event-list,
body.tax-sp_venue .sp-template-event-blocks,
body.tax-sp_venue .sp-template-event-logos,
body.tax-sp_venue .sp-template-event-results {
	display: {$event_list_display};
}

body.tax-sp_venue .sp-template-venue-map .sp-google-map {
	display: block;
	margin: 0;
}

body.tax-sp_venue .tse-ground-rules-content {
	line-height: 1.7;
	padding: 1em 15px 0;
}

body.tax-sp_venue .tse-ground-rules-content h2,
body.tax-sp_venue .tse-ground-rules-content h3,
body.tax-sp_venue .tse-ground-rules-content h4,
body.tax-sp_venue .tse-ground-rules-content h5,
body.tax-sp_venue .tse-ground-rules-content h6 {
	margin: 1.25em 0 0.55em;
	font-weight: 700;
	line-height: 1.25;
	text-transform: none;
}

body.tax-sp_venue .tse-ground-rules-content h2 {
	font-size: 2rem;
}

body.tax-sp_venue .tse-ground-rules-content h3 {
	font-size: 1.75rem;
}

body.tax-sp_venue .tse-ground-rules-content h4 {
	font-size: 1.5rem;
}

body.tax-sp_venue .tse-ground-rules-content p,
body.tax-sp_venue .tse-ground-rules-content ul,
body.tax-sp_venue .tse-ground-rules-content ol,
body.tax-sp_venue .tse-ground-rules-content blockquote,
body.tax-sp_venue .tse-ground-rules-content pre {
	margin: 0 0 1em;
}

body.tax-sp_venue .tse-ground-rules-content ul,
body.tax-sp_venue .tse-ground-rules-content ol {
	margin-left: 1.5em;
	padding-left: 1.5em;
	list-style-position: outside;
}

body.tax-sp_venue .tse-ground-rules-content ul {
	list-style: disc;
}

body.tax-sp_venue .tse-ground-rules-content ol {
	list-style: decimal;
}

body.tax-sp_venue .tse-ground-rules-content li {
	margin: 0.35em 0;
}
CSS;

	wp_add_inline_style( 'tony-sportspress-venue-sections', $css );
}
add_action( 'wp_enqueue_scripts', 'tony_sportspress_enqueue_venue_section_styles' );

/**
 * Render add-form fields for venue metadata.
 */
function tony_sportspress_add_venue_meta_fields() {
	?>
	<div class="form-field term-short-name-wrap">
		<label for="tse_short_name"><?php esc_html_e( 'Short Name', 'tonys-sportspress-enhancements' ); ?></label>
		<input name="tse_short_name" id="tse_short_name" type="text" value="" maxlength="100" />
		<p><?php esc_html_e( 'Optional shorter label for this field or venue.', 'tonys-sportspress-enhancements' ); ?></p>
	</div>
	<div class="form-field term-abbreviation-wrap">
		<label for="tse_abbreviation"><?php esc_html_e( 'Abbreviation', 'tonys-sportspress-enhancements' ); ?></label>
		<input name="tse_abbreviation" id="tse_abbreviation" type="text" value="" maxlength="20" />
		<p><?php esc_html_e( 'Optional abbreviation such as CC East or Field 1.', 'tonys-sportspress-enhancements' ); ?></p>
	</div>
	<div class="form-field term-ground-rules-wrap">
		<label for="tse_ground_rules"><?php esc_html_e( 'Ground Rules', 'tonys-sportspress-enhancements' ); ?></label>
		<?php
		wp_editor(
			'',
			'tse_ground_rules',
			array(
				'textarea_name' => 'tse_ground_rules',
				'textarea_rows' => 10,
				'media_buttons' => true,
				'teeny'         => false,
				'quicktags'     => true,
				'drag_drop_upload' => true,
			)
		);
		?>
		<p><?php esc_html_e( 'Supports headings, lists, links, and images.', 'tonys-sportspress-enhancements' ); ?></p>
	</div>
	<?php
}
add_action( 'sp_venue_add_form_fields', 'tony_sportspress_add_venue_meta_fields' );

/**
 * Render edit-form fields for venue metadata.
 *
 * @param WP_Term $term Venue term.
 */
function tony_sportspress_edit_venue_meta_fields( $term ) {
	$short_name   = get_term_meta( $term->term_id, 'tse_short_name', true );
	$abbreviation = get_term_meta( $term->term_id, 'tse_abbreviation', true );
	$ground_rules  = get_term_meta( $term->term_id, 'tse_ground_rules', true );
	?>
	<tr class="form-field term-short-name-wrap">
		<th scope="row">
			<label for="tse_short_name"><?php esc_html_e( 'Short Name', 'tonys-sportspress-enhancements' ); ?></label>
		</th>
		<td>
			<input name="tse_short_name" id="tse_short_name" type="text" value="<?php echo esc_attr( $short_name ); ?>" maxlength="100" />
			<p class="description"><?php esc_html_e( 'Optional shorter label for this field or venue.', 'tonys-sportspress-enhancements' ); ?></p>
		</td>
	</tr>
	<tr class="form-field term-abbreviation-wrap">
		<th scope="row">
			<label for="tse_abbreviation"><?php esc_html_e( 'Abbreviation', 'tonys-sportspress-enhancements' ); ?></label>
		</th>
		<td>
			<input name="tse_abbreviation" id="tse_abbreviation" type="text" value="<?php echo esc_attr( $abbreviation ); ?>" maxlength="20" />
			<p class="description"><?php esc_html_e( 'Optional abbreviation such as CC East or Field 1.', 'tonys-sportspress-enhancements' ); ?></p>
		</td>
	</tr>
	<tr class="form-field term-ground-rules-wrap">
		<th scope="row">
			<label for="tse_ground_rules"><?php esc_html_e( 'Ground Rules', 'tonys-sportspress-enhancements' ); ?></label>
		</th>
		<td>
			<?php
			wp_editor(
				(string) $ground_rules,
				'tse_ground_rules',
				array(
					'textarea_name'   => 'tse_ground_rules',
					'textarea_rows'   => 10,
					'media_buttons'   => true,
					'teeny'           => false,
					'quicktags'       => true,
					'drag_drop_upload' => true,
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'Supports headings, lists, links, and images.', 'tonys-sportspress-enhancements' ); ?></p>
		</td>
	</tr>
	<?php
}
add_action( 'sp_venue_edit_form_fields', 'tony_sportspress_edit_venue_meta_fields' );

/**
 * Save venue metadata fields.
 *
 * @param int $term_id Venue term ID.
 */
function tony_sportspress_save_venue_meta_fields( $term_id ) {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}

	$short_name = isset( $_POST['tse_short_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tse_short_name'] ) ) : '';
	$short_name = is_string( $short_name ) ? trim( $short_name ) : '';

	$abbreviation = isset( $_POST['tse_abbreviation'] ) ? sanitize_text_field( wp_unslash( $_POST['tse_abbreviation'] ) ) : '';
	$abbreviation = is_string( $abbreviation ) ? trim( $abbreviation ) : '';

	$ground_rules = isset( $_POST['tse_ground_rules'] ) ? wp_kses_post( wp_unslash( $_POST['tse_ground_rules'] ) ) : '';
	$ground_rules = is_string( $ground_rules ) ? trim( $ground_rules ) : '';

	update_term_meta( $term_id, 'tse_short_name', $short_name );
	update_term_meta( $term_id, 'tse_abbreviation', $abbreviation );
	update_term_meta( $term_id, 'tse_ground_rules', $ground_rules );
}
add_action( 'created_sp_venue', 'tony_sportspress_save_venue_meta_fields' );
add_action( 'edited_sp_venue', 'tony_sportspress_save_venue_meta_fields' );
