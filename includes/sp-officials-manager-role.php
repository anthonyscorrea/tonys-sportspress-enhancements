<?php
/**
 * Officials Manager role and capability restrictions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TONY_SPORTSPRESS_OFFICIALS_MANAGER_ROLE' ) ) {
	define( 'TONY_SPORTSPRESS_OFFICIALS_MANAGER_ROLE', 'sp_officials_manager' );
}

/**
 * Build the primitive capabilities for a custom post type.
 *
 * @param string $singular Singular capability base.
 * @param string $plural   Plural capability base.
 * @return string[]
 */
function tony_sportspress_build_post_type_caps( $singular, $plural ) {
	return array(
		"edit_{$singular}",
		"read_{$singular}",
		"delete_{$singular}",
		"edit_{$plural}",
		"edit_others_{$plural}",
		"publish_{$plural}",
		"read_private_{$plural}",
		"delete_{$plural}",
		"delete_private_{$plural}",
		"delete_published_{$plural}",
		"delete_others_{$plural}",
		"edit_private_{$plural}",
		"edit_published_{$plural}",
	);
}

/**
 * Get officials manager role capabilities.
 *
 * @return array<string, bool>
 */
function tony_sportspress_get_officials_manager_caps() {
	$caps = array(
		'read'                 => true,
		'edit_posts'           => true,
		'delete_posts'         => true,
		'edit_published_posts' => true,
	);

	foreach ( tony_sportspress_build_post_type_caps( 'sp_official', 'sp_officials' ) as $cap ) {
		$caps[ $cap ] = true;
	}

	// Allow access to the event list and quick edit for assignments, without full event management.
	$caps['read_sp_event']             = true;
	$caps['edit_sp_event']             = true;
	$caps['edit_sp_events']            = true;
	$caps['edit_others_sp_events']     = true;
	$caps['edit_published_sp_events']  = true;
	$caps['read_private_sp_events']    = true;

	return $caps;
}

/**
 * Get the capabilities managed for the officials manager role.
 *
 * @return string[]
 */
function tony_sportspress_get_officials_manager_managed_caps() {
	return array_keys( tony_sportspress_get_officials_manager_caps() );
}

/**
 * Grant custom official caps to existing roles that already have matching event caps.
 *
 * This preserves existing access after moving officials off the `sp_event` capability type.
 *
 * @return void
 */
function tony_sportspress_sync_official_caps_to_existing_roles() {
	global $wp_roles;

	if ( ! class_exists( 'WP_Roles' ) ) {
		return;
	}

	if ( ! isset( $wp_roles ) ) {
		$wp_roles = wp_roles();
	}

	if ( ! $wp_roles instanceof WP_Roles ) {
		return;
	}

	$cap_map = array(
		'edit_sp_event'              => 'edit_sp_official',
		'read_sp_event'              => 'read_sp_official',
		'delete_sp_event'            => 'delete_sp_official',
		'edit_sp_events'             => 'edit_sp_officials',
		'edit_others_sp_events'      => 'edit_others_sp_officials',
		'publish_sp_events'          => 'publish_sp_officials',
		'read_private_sp_events'     => 'read_private_sp_officials',
		'delete_sp_events'           => 'delete_sp_officials',
		'delete_private_sp_events'   => 'delete_private_sp_officials',
		'delete_published_sp_events' => 'delete_published_sp_officials',
		'delete_others_sp_events'    => 'delete_others_sp_officials',
		'edit_private_sp_events'     => 'edit_private_sp_officials',
		'edit_published_sp_events'   => 'edit_published_sp_officials',
	);

	foreach ( $wp_roles->role_objects as $role ) {
		if ( ! $role instanceof WP_Role ) {
			continue;
		}

		foreach ( $cap_map as $event_cap => $official_cap ) {
			if ( $role->has_cap( $event_cap ) ) {
				$role->add_cap( $official_cap );
			}
		}
	}
}

/**
 * Create or update the officials manager role.
 *
 * @return void
 */
function tony_sportspress_sync_officials_manager_roles() {
	$role = get_role( TONY_SPORTSPRESS_OFFICIALS_MANAGER_ROLE );

	if ( ! $role ) {
		$role = add_role(
			TONY_SPORTSPRESS_OFFICIALS_MANAGER_ROLE,
			__( 'Officials Manager', 'tonys-sportspress-enhancements' ),
			array()
		);
	}

	if ( ! $role instanceof WP_Role ) {
		return;
	}

	$desired_caps = tony_sportspress_get_officials_manager_caps();

	foreach ( tony_sportspress_get_officials_manager_managed_caps() as $cap ) {
		if ( ! empty( $desired_caps[ $cap ] ) ) {
			$role->add_cap( $cap );
		} else {
			$role->remove_cap( $cap );
		}
	}

	tony_sportspress_sync_official_caps_to_existing_roles();
}
add_action( 'init', 'tony_sportspress_sync_officials_manager_roles' );

/**
 * Assign custom capabilities to the officials post type.
 *
 * @param array $args Post type registration args.
 * @return array
 */
function tony_sportspress_officials_post_type_caps( $args ) {
	$args['capability_type'] = array( 'sp_official', 'sp_officials' );
	$args['map_meta_cap']    = true;
	$args['capabilities']    = array(
		'create_posts' => 'edit_sp_officials',
	);

	return $args;
}
add_filter( 'sportspress_register_post_type_official', 'tony_sportspress_officials_post_type_caps' );

/**
 * Determine whether the current user should be restricted to assignment-only event access.
 *
 * @return bool
 */
function tony_sportspress_is_officials_manager_user() {
	$user = wp_get_current_user();

	if ( ! $user instanceof WP_User || empty( $user->roles ) ) {
		return false;
	}

	if ( ! in_array( TONY_SPORTSPRESS_OFFICIALS_MANAGER_ROLE, $user->roles, true ) ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_sportspress' ) ) {
		return false;
	}

	return true;
}

/**
 * Prevent assignment-only users from opening full event edit screens.
 *
 * @return void
 */
function tony_sportspress_lock_event_editor_for_officials_manager() {
	global $pagenow;

	if ( ! is_admin() || ! tony_sportspress_is_officials_manager_user() ) {
		return;
	}

	if ( 'post-new.php' === $pagenow ) {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';

		if ( 'sp_event' === $post_type ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=sp_event&tse_event_editor_locked=1' ) );
			exit;
		}
	}

	if ( 'post.php' !== $pagenow ) {
		return;
	}

	$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
	if ( $post_id > 0 && 'sp_event' === get_post_type( $post_id ) ) {
		wp_safe_redirect( admin_url( 'edit.php?post_type=sp_event&tse_event_editor_locked=1' ) );
		exit;
	}
}
add_action( 'admin_init', 'tony_sportspress_lock_event_editor_for_officials_manager' );

/**
 * Show an admin notice when event editor access is blocked.
 *
 * @return void
 */
function tony_sportspress_officials_manager_admin_notice() {
	if ( ! tony_sportspress_is_officials_manager_user() ) {
		return;
	}

	if ( empty( $_GET['tse_event_editor_locked'] ) ) {
		return;
	}

	echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Officials Managers can assign officials from the events list via Quick Edit, but cannot open the full event editor.', 'tonys-sportspress-enhancements' ) . '</p></div>';
}
add_action( 'admin_notices', 'tony_sportspress_officials_manager_admin_notice' );

/**
 * Remove event row actions that would expose broader editing.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Post object.
 * @return array
 */
function tony_sportspress_limit_event_row_actions_for_officials_manager( $actions, $post ) {
	if ( ! tony_sportspress_is_officials_manager_user() || ! $post instanceof WP_Post || 'sp_event' !== $post->post_type ) {
		return $actions;
	}

	$allowed = array();

	if ( isset( $actions['inline hide-if-no-js'] ) ) {
		$allowed['inline hide-if-no-js'] = $actions['inline hide-if-no-js'];
	}

	if ( isset( $actions['view'] ) ) {
		$allowed['view'] = $actions['view'];
	}

	return $allowed;
}
add_filter( 'post_row_actions', 'tony_sportspress_limit_event_row_actions_for_officials_manager', 10, 2 );

/**
 * Remove bulk actions from the events list for assignment-only users.
 *
 * @param array $actions Bulk actions.
 * @return array
 */
function tony_sportspress_limit_event_bulk_actions_for_officials_manager( $actions ) {
	if ( ! tony_sportspress_is_officials_manager_user() ) {
		return $actions;
	}

	return array();
}
add_filter( 'bulk_actions-edit-sp_event', 'tony_sportspress_limit_event_bulk_actions_for_officials_manager' );

/**
 * Remove the Add New events submenu for assignment-only users.
 *
 * @return void
 */
function tony_sportspress_limit_event_admin_menu_for_officials_manager() {
	if ( ! tony_sportspress_is_officials_manager_user() ) {
		return;
	}

	remove_submenu_page( 'edit.php?post_type=sp_event', 'post-new.php?post_type=sp_event' );
}
add_action( 'admin_menu', 'tony_sportspress_limit_event_admin_menu_for_officials_manager', 99 );

/**
 * Hide event Add New buttons on the list screen for assignment-only users.
 *
 * @return void
 */
function tony_sportspress_limit_event_admin_ui_for_officials_manager() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-sp_event' !== $screen->id || ! tony_sportspress_is_officials_manager_user() ) {
		return;
	}
	?>
	<style>
		.post-type-sp_event .page-title-action,
		.post-type-sp_event .wrap .bulkactions {
			display: none;
		}
	</style>
	<?php
}
add_action( 'admin_head', 'tony_sportspress_limit_event_admin_ui_for_officials_manager' );

/**
 * Preserve core event fields so assignment-only users cannot alter them via Quick Edit.
 *
 * @param array $data    Sanitized post data.
 * @param array $postarr Raw post array.
 * @return array
 */
function tony_sportspress_protect_event_fields_for_officials_manager( $data, $postarr ) {
	if ( ! is_admin() || ! tony_sportspress_is_officials_manager_user() ) {
		return $data;
	}

	if ( empty( $postarr['ID'] ) || 'sp_event' !== $data['post_type'] ) {
		return $data;
	}

	$existing_post = get_post( (int) $postarr['ID'], ARRAY_A );
	if ( ! is_array( $existing_post ) ) {
		return $data;
	}

	$protected_fields = array(
		'post_author',
		'post_content',
		'post_content_filtered',
		'post_date',
		'post_date_gmt',
		'post_excerpt',
		'post_name',
		'post_parent',
		'post_password',
		'post_status',
		'post_title',
	);

	foreach ( $protected_fields as $field ) {
		if ( isset( $existing_post[ $field ] ) ) {
			$data[ $field ] = $existing_post[ $field ];
		}
	}

	return $data;
}
add_filter( 'wp_insert_post_data', 'tony_sportspress_protect_event_fields_for_officials_manager', 20, 2 );
