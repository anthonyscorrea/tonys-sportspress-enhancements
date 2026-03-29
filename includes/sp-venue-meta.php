<?php
/**
 * Venue term metadata support.
 *
 * Adds short name and abbreviation fields to SportsPress venues.
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
}
add_action( 'init', 'tony_sportspress_register_venue_term_meta' );

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

	update_term_meta( $term_id, 'tse_short_name', $short_name );
	update_term_meta( $term_id, 'tse_abbreviation', $abbreviation );
}
add_action( 'created_sp_venue', 'tony_sportspress_save_venue_meta_fields' );
add_action( 'edited_sp_venue', 'tony_sportspress_save_venue_meta_fields' );
