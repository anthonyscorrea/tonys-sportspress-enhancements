<?php
/**
 * Read-only SportsPress event revision audit trail.
 *
 * @package Tonys_Sportspress_Enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Tony_Sportspress_Event_Revisions' ) ) {
	/**
	 * Store normalized event snapshots on WordPress revisions and render an audit table.
	 */
	class Tony_Sportspress_Event_Revisions {

		const SNAPSHOT_META_KEY = '_tse_sp_event_snapshot';
		const TAB_REVISIONS    = 'event-revisions';
		const PER_PAGE         = 20;

		/**
		 * Singleton instance.
		 *
		 * @var Tony_Sportspress_Event_Revisions|null
		 */
		private static $instance = null;

		/**
		 * Whether hooks were already registered.
		 *
		 * @var bool
		 */
		private $booted = false;

		/**
		 * Event IDs that should be checked after the current save request settles.
		 *
		 * @var int[]
		 */
		private $pending_event_ids = array();

		/**
		 * Get singleton instance.
		 *
		 * @return Tony_Sportspress_Event_Revisions
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		public function boot() {
			if ( $this->booted ) {
				return;
			}

			$this->booted = true;

			add_filter( 'sportspress_register_post_type_event', array( $this, 'enable_event_revisions' ) );
			add_action( 'save_post_revision', array( $this, 'store_revision_snapshot' ), 10, 3 );
			add_action( '_wp_put_post_revision', array( $this, 'store_revision_snapshot' ), 10, 1 );
			add_action( 'post_updated', array( $this, 'queue_event_datetime_snapshot_check' ), 10, 3 );
			add_action( 'save_post_sp_event', array( $this, 'queue_event_snapshot_check' ), 100, 3 );
			add_action( 'set_object_terms', array( $this, 'queue_event_term_snapshot_check' ), 10, 6 );
			add_action( 'added_post_meta', array( $this, 'queue_event_meta_snapshot_check' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'queue_event_meta_snapshot_check' ), 10, 4 );
			add_action( 'deleted_post_meta', array( $this, 'queue_event_meta_snapshot_check' ), 10, 4 );
			add_action( 'shutdown', array( $this, 'process_pending_event_snapshots' ), 100 );

			if ( is_admin() ) {
				add_filter( 'tse_tonys_settings_tabs', array( $this, 'register_settings_tab' ) );
				add_action( 'tse_tonys_settings_render_tab_' . self::TAB_REVISIONS, array( $this, 'render_settings_tab' ) );
			}
		}

		/**
		 * Add revision support to SportsPress events.
		 *
		 * @param array $args Post type registration args.
		 * @return array
		 */
		public function enable_event_revisions( $args ) {
			$supports = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array();

			if ( ! in_array( 'revisions', $supports, true ) ) {
				$supports[] = 'revisions';
			}

			$args['supports'] = $supports;

			return $args;
		}

		/**
		 * Register the Tony's Settings tab label.
		 *
		 * @param array $tabs Existing tabs.
		 * @return array
		 */
		public function register_settings_tab( $tabs ) {
			$tabs[ self::TAB_REVISIONS ] = __( 'Event Revisions', 'tonys-sportspress-enhancements' );

			return $tabs;
		}

		/**
		 * Save the current parent event snapshot on a revision post.
		 *
		 * @param int     $revision_id Revision post ID.
		 * @param WP_Post $revision Revision post object.
		 * @param bool    $update Whether this is an update.
		 * @return void
		 */
		public function store_revision_snapshot( $revision_id, $revision = null, $update = false ) {
			unset( $update );

			if ( ! $revision instanceof WP_Post ) {
				$revision = get_post( $revision_id );
			}

			if ( ! $revision instanceof WP_Post || 'revision' !== $revision->post_type ) {
				return;
			}

			$event_id = absint( $revision->post_parent );
			if ( $event_id <= 0 || 'sp_event' !== get_post_type( $event_id ) ) {
				return;
			}

			$this->save_snapshot_to_revision( $revision_id, $event_id );
		}

		/**
		 * Queue an event after a normal post save.
		 *
		 * @param int     $post_id Event post ID.
		 * @param WP_Post $post Event post object.
		 * @param bool    $update Whether this is an update.
		 * @return void
		 */
		public function queue_event_snapshot_check( $post_id, $post, $update ) {
			unset( $update );

			if ( ! $post instanceof WP_Post || 'sp_event' !== $post->post_type ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
				return;
			}

			$this->queue_event_id( $post_id );
		}

		/**
		 * Queue an event when its scheduled date/time changes.
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post_after Updated post object.
		 * @param WP_Post $post_before Previous post object.
		 * @return void
		 */
		public function queue_event_datetime_snapshot_check( $post_id, $post_after, $post_before ) {
			if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
				return;
			}

			if ( 'sp_event' !== $post_after->post_type ) {
				return;
			}

			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}

			if ( $post_after->post_date === $post_before->post_date && $post_after->post_date_gmt === $post_before->post_date_gmt ) {
				return;
			}

			$this->queue_event_id( $post_id );
		}

		/**
		 * Queue an event when tracked taxonomy relationships change.
		 *
		 * @param int          $object_id Object ID.
		 * @param array|string $terms Terms.
		 * @param array        $tt_ids Term taxonomy IDs.
		 * @param string       $taxonomy Taxonomy slug.
		 * @param bool         $append Whether terms were appended.
		 * @param array        $old_tt_ids Old term taxonomy IDs.
		 * @return void
		 */
		public function queue_event_term_snapshot_check( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
			unset( $terms, $tt_ids, $append, $old_tt_ids );

			if ( ! in_array( $taxonomy, array( 'sp_venue', 'sp_league' ), true ) ) {
				return;
			}

			if ( 'sp_event' !== get_post_type( $object_id ) ) {
				return;
			}

			$this->queue_event_id( $object_id );
		}

		/**
		 * Queue an event when tracked SportsPress post meta changes.
		 *
		 * @param int    $meta_id Meta row ID.
		 * @param int    $object_id Object ID.
		 * @param string $meta_key Meta key.
		 * @param mixed  $meta_value Meta value.
		 * @return void
		 */
		public function queue_event_meta_snapshot_check( $meta_id, $object_id, $meta_key, $meta_value ) {
			unset( $meta_id, $meta_value );

			if ( ! in_array( $meta_key, array( 'sp_team', 'sp_results' ), true ) ) {
				return;
			}

			if ( 'sp_event' !== get_post_type( $object_id ) ) {
				return;
			}

			$this->queue_event_id( $object_id );
		}

		/**
		 * Create or update revision snapshots for queued events.
		 *
		 * @return void
		 */
		public function process_pending_event_snapshots() {
			$event_ids = array_values( array_unique( array_map( 'absint', $this->pending_event_ids ) ) );
			$this->pending_event_ids = array();

			foreach ( $event_ids as $event_id ) {
				if ( $event_id <= 0 || 'sp_event' !== get_post_type( $event_id ) ) {
					continue;
				}

				$current_snapshot = $this->build_event_snapshot( $event_id );
				$latest_revision  = $this->get_latest_revision( $event_id );

				if ( $latest_revision instanceof WP_Post ) {
					$latest_snapshot = $this->get_revision_snapshot( $latest_revision->ID );

					if ( $current_snapshot === $latest_snapshot ) {
						continue;
					}

					if ( empty( $latest_snapshot ) && $this->revision_was_recent( $latest_revision ) ) {
						$this->save_snapshot_to_revision( $latest_revision->ID, $event_id );
						continue;
					}
				}

				$revision_id = $this->create_revision_for_current_event_state( $event_id );
				if ( $revision_id > 0 ) {
					$this->save_snapshot_to_revision( $revision_id, $event_id );
				}
			}
		}

		/**
		 * Render the read-only revision audit tab.
		 *
		 * @return void
		 */
		public function render_settings_tab() {
			if ( ! current_user_can( 'manage_sportspress' ) ) {
				return;
			}

			$paged  = isset( $_GET['tse_revision_page'] ) ? max( 1, absint( wp_unslash( $_GET['tse_revision_page'] ) ) ) : 1;
			$offset = ( $paged - 1 ) * self::PER_PAGE;
			$total  = $this->count_event_revisions();
			$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
			$rows   = $this->get_event_revision_rows( self::PER_PAGE, $offset );

			echo '<h2>' . esc_html__( 'SportsPress Event Revisions', 'tonys-sportspress-enhancements' ) . '</h2>';
			echo '<p>' . esc_html__( 'Read-only audit trail for SportsPress event date/time, teams, results, venue, and league snapshots stored on WordPress revision posts.', 'tonys-sportspress-enhancements' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'This view intentionally does not expose revision restore actions.', 'tonys-sportspress-enhancements' ) . '</p>';

			if ( empty( $rows ) ) {
				echo '<div style="max-width:1100px;padding:16px 18px;border:1px solid #dcdcde;background:#fff;">';
				echo '<p style="margin:0;">' . esc_html__( 'No SportsPress event revisions have been captured yet.', 'tonys-sportspress-enhancements' ) . '</p>';
				echo '</div>';
				return;
			}

			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Changed', 'tonys-sportspress-enhancements' ) . '</th>';
			echo '<th>' . esc_html__( 'Author', 'tonys-sportspress-enhancements' ) . '</th>';
			echo '<th>' . esc_html__( 'Event', 'tonys-sportspress-enhancements' ) . '</th>';
			echo '<th>' . esc_html__( 'Changed Fields', 'tonys-sportspress-enhancements' ) . '</th>';
			echo '<th>' . esc_html__( 'Before / After', 'tonys-sportspress-enhancements' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$revision = $row['revision'];
				$event    = $row['event'];
				$author   = get_userdata( (int) $revision->post_author );
				$edit_url = get_edit_post_link( $event->ID, 'raw' );

				echo '<tr>';
				echo '<td>' . esc_html( get_date_from_gmt( $revision->post_date_gmt, 'Y-m-d g:i A' ) ) . '</td>';
				echo '<td>' . esc_html( $author ? $author->display_name : __( 'Unknown', 'tonys-sportspress-enhancements' ) ) . '</td>';
				echo '<td>';
				if ( $edit_url ) {
					echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( get_the_title( $event ) ) . '</a>';
				} else {
					echo esc_html( get_the_title( $event ) );
				}
				echo '<br><code>#' . esc_html( (string) $event->ID ) . '</code></td>';
				echo '<td>' . esc_html( implode( ', ', $row['changed_labels'] ) ) . '</td>';
				echo '<td>' . $this->render_change_summary( $row['changes'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</tr>';
			}

			echo '</tbody></table>';
			$this->render_pagination( $paged, $pages );
		}

		/**
		 * Add an event ID to the pending list.
		 *
		 * @param int $event_id Event post ID.
		 * @return void
		 */
		private function queue_event_id( $event_id ) {
			$event_id = absint( $event_id );
			if ( $event_id > 0 ) {
				$this->pending_event_ids[] = $event_id;
			}
		}

		/**
		 * Save an event snapshot to a revision.
		 *
		 * @param int $revision_id Revision post ID.
		 * @param int $event_id Event post ID.
		 * @return void
		 */
		private function save_snapshot_to_revision( $revision_id, $event_id ) {
			update_metadata( 'post', $revision_id, self::SNAPSHOT_META_KEY, $this->build_event_snapshot( $event_id ) );
		}

		/**
		 * Build the normalized event snapshot.
		 *
		 * @param int $event_id Event post ID.
		 * @return array
		 */
		private function build_event_snapshot( $event_id ) {
			$post = get_post( $event_id );

			return array(
				'date'     => $post instanceof WP_Post ? (string) $post->post_date : '',
				'date_gmt' => $post instanceof WP_Post ? (string) $post->post_date_gmt : '',
				'teams'    => $this->get_team_snapshot( $event_id ),
				'results'  => $this->normalize_value( get_post_meta( $event_id, 'sp_results', true ) ),
				'venue'    => $this->get_term_snapshot( $event_id, 'sp_venue' ),
				'league'   => $this->get_term_snapshot( $event_id, 'sp_league' ),
			);
		}

		/**
		 * Get event team snapshot in stored meta order.
		 *
		 * @param int $event_id Event post ID.
		 * @return array
		 */
		private function get_team_snapshot( $event_id ) {
			$team_ids = get_post_meta( $event_id, 'sp_team', false );
			$teams    = array();

			foreach ( $team_ids as $team_id ) {
				$team_id = absint( $team_id );
				if ( $team_id <= 0 ) {
					continue;
				}

				$teams[] = array(
					'id'    => $team_id,
					'title' => get_the_title( $team_id ),
					'slug'  => (string) get_post_field( 'post_name', $team_id ),
				);
			}

			return $teams;
		}

		/**
		 * Get attached term snapshot.
		 *
		 * @param int    $event_id Event post ID.
		 * @param string $taxonomy Taxonomy slug.
		 * @return array
		 */
		private function get_term_snapshot( $event_id, $taxonomy ) {
			$terms = get_the_terms( $event_id, $taxonomy );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				return array();
			}

			$snapshot = array();
			foreach ( $terms as $term ) {
				$snapshot[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				);
			}

			usort(
				$snapshot,
				static function ( $a, $b ) {
					return $a['id'] <=> $b['id'];
				}
			);

			return $snapshot;
		}

		/**
		 * Normalize values for stable comparisons and storage.
		 *
		 * @param mixed $value Raw value.
		 * @return mixed
		 */
		private function normalize_value( $value ) {
			if ( is_array( $value ) ) {
				$normalized = array();
				foreach ( $value as $key => $item ) {
					$normalized[ (string) $key ] = $this->normalize_value( $item );
				}

				ksort( $normalized );

				return $normalized;
			}

			if ( is_object( $value ) ) {
				return $this->normalize_value( get_object_vars( $value ) );
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				return $value;
			}

			return (string) $value;
		}

		/**
		 * Get the latest event revision.
		 *
		 * @param int $event_id Event post ID.
		 * @return WP_Post|null
		 */
		private function get_latest_revision( $event_id ) {
			$revisions = wp_get_post_revisions(
				$event_id,
				array(
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			if ( empty( $revisions ) ) {
				return null;
			}

			$revision = reset( $revisions );

			return $revision instanceof WP_Post ? $revision : null;
		}

		/**
		 * Get a stored revision snapshot.
		 *
		 * @param int $revision_id Revision post ID.
		 * @return array
		 */
		private function get_revision_snapshot( $revision_id ) {
			$snapshot = get_metadata( 'post', $revision_id, self::SNAPSHOT_META_KEY, true );

			return is_array( $snapshot ) ? $snapshot : array();
		}

		/**
		 * Determine whether a revision likely belongs to the current save request.
		 *
		 * @param WP_Post $revision Revision post object.
		 * @return bool
		 */
		private function revision_was_recent( $revision ) {
			$timestamp = get_post_timestamp( $revision, 'date' );

			return $timestamp && ( time() - $timestamp ) < 300;
		}

		/**
		 * Create a core revision for the current event state.
		 *
		 * @param int $event_id Event post ID.
		 * @return int
		 */
		private function create_revision_for_current_event_state( $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post instanceof WP_Post ) {
				return 0;
			}

			if ( function_exists( '_wp_put_post_revision' ) ) {
				$revision_id = _wp_put_post_revision( $post );
				return is_wp_error( $revision_id ) ? 0 : absint( $revision_id );
			}

			if ( function_exists( 'wp_save_post_revision' ) ) {
				$revision_id = wp_save_post_revision( $event_id );
				return is_wp_error( $revision_id ) ? 0 : absint( $revision_id );
			}

			return 0;
		}

		/**
		 * Count all event revisions.
		 *
		 * @return int
		 */
		private function count_event_revisions() {
			global $wpdb;

			$sql = $wpdb->prepare(
				"SELECT COUNT(DISTINCT revisions.ID)
				FROM {$wpdb->posts} revisions
				INNER JOIN {$wpdb->posts} events ON events.ID = revisions.post_parent
				INNER JOIN {$wpdb->postmeta} snapshots ON snapshots.post_id = revisions.ID
				WHERE revisions.post_type = %s
					AND revisions.post_name NOT LIKE %s
					AND events.post_type = %s
					AND snapshots.meta_key = %s",
				'revision',
				'%autosave%',
				'sp_event',
				self::SNAPSHOT_META_KEY
			);

			return absint( $wpdb->get_var( $sql ) );
		}

		/**
		 * Get paginated event revision rows.
		 *
		 * @param int $limit Number of rows.
		 * @param int $offset Offset.
		 * @return array
		 */
		private function get_event_revision_rows( $limit, $offset ) {
			global $wpdb;

			$revision_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT revisions.ID
					FROM {$wpdb->posts} revisions
					INNER JOIN {$wpdb->posts} events ON events.ID = revisions.post_parent
					INNER JOIN {$wpdb->postmeta} snapshots ON snapshots.post_id = revisions.ID
					WHERE revisions.post_type = %s
						AND revisions.post_name NOT LIKE %s
						AND events.post_type = %s
						AND snapshots.meta_key = %s
					ORDER BY revisions.post_date_gmt DESC, revisions.ID DESC
					LIMIT %d OFFSET %d",
					'revision',
					'%autosave%',
					'sp_event',
					self::SNAPSHOT_META_KEY,
					max( 1, absint( $limit ) ),
					max( 0, absint( $offset ) )
				)
			);

			$rows = array();
			foreach ( $revision_ids as $revision_id ) {
				$revision = get_post( $revision_id );
				if ( ! $revision instanceof WP_Post ) {
					continue;
				}

				$event = get_post( $revision->post_parent );
				if ( ! $event instanceof WP_Post || 'sp_event' !== $event->post_type ) {
					continue;
				}

				$snapshot          = $this->get_revision_snapshot( $revision->ID );
				$previous_snapshot = $this->get_previous_revision_snapshot( $event->ID, $revision->ID );
				$changes           = $this->compare_snapshots( $previous_snapshot, $snapshot );

				$rows[] = array(
					'revision'       => $revision,
					'event'          => $event,
					'snapshot'       => $snapshot,
					'changes'        => $changes,
					'changed_labels' => $this->get_changed_field_labels( array_keys( $changes ) ),
				);
			}

			return $rows;
		}

		/**
		 * Get the previous stored snapshot for an event revision.
		 *
		 * @param int $event_id Event post ID.
		 * @param int $revision_id Current revision ID.
		 * @return array
		 */
		private function get_previous_revision_snapshot( $event_id, $revision_id ) {
			global $wpdb;

			$current = get_post( $revision_id );
			if ( ! $current instanceof WP_Post ) {
				return array();
			}

			$previous_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID
					FROM {$wpdb->posts}
					INNER JOIN {$wpdb->postmeta} snapshots ON snapshots.post_id = {$wpdb->posts}.ID
					WHERE post_type = %s
						AND post_parent = %d
						AND post_name NOT LIKE %s
						AND snapshots.meta_key = %s
						AND (post_date_gmt < %s OR (post_date_gmt = %s AND ID < %d))
					ORDER BY post_date_gmt DESC, ID DESC
					LIMIT 1",
					'revision',
					$event_id,
					'%autosave%',
					self::SNAPSHOT_META_KEY,
					$current->post_date_gmt,
					$current->post_date_gmt,
					$revision_id
				)
			);

			return $previous_id ? $this->get_revision_snapshot( absint( $previous_id ) ) : array();
		}

		/**
		 * Compare snapshots by top-level field.
		 *
		 * @param array $previous Previous snapshot.
		 * @param array $current Current snapshot.
		 * @return array
		 */
		private function compare_snapshots( $previous, $current ) {
			if ( empty( $current ) ) {
				return array(
					'snapshot' => array(
						'before' => array(),
						'after'  => array(),
					),
				);
			}

			$fields  = array( 'date', 'date_gmt', 'teams', 'results', 'venue', 'league' );
			$changes = array();

			foreach ( $fields as $field ) {
				$before = isset( $previous[ $field ] ) ? $previous[ $field ] : null;
				$after  = isset( $current[ $field ] ) ? $current[ $field ] : null;

				if ( $before !== $after ) {
					$changes[ $field ] = array(
						'before' => $before,
						'after'  => $after,
					);
				}
			}

			return $changes;
		}

		/**
		 * Get labels for changed fields.
		 *
		 * @param string[] $fields Field keys.
		 * @return string[]
		 */
		private function get_changed_field_labels( $fields ) {
			$labels = array(
				'date'     => __( 'Date', 'tonys-sportspress-enhancements' ),
				'date_gmt' => __( 'Date GMT', 'tonys-sportspress-enhancements' ),
				'teams'    => __( 'Teams', 'tonys-sportspress-enhancements' ),
				'results'  => __( 'Results', 'tonys-sportspress-enhancements' ),
				'venue'    => __( 'Venue', 'tonys-sportspress-enhancements' ),
				'league'   => __( 'League', 'tonys-sportspress-enhancements' ),
				'snapshot' => __( 'Snapshot', 'tonys-sportspress-enhancements' ),
			);

			$output = array();
			foreach ( $fields as $field ) {
				$output[] = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
			}

			return empty( $output ) ? array( __( 'No tracked changes', 'tonys-sportspress-enhancements' ) ) : $output;
		}

		/**
		 * Render compact before/after summaries.
		 *
		 * @param array $changes Field changes.
		 * @return string
		 */
		private function render_change_summary( $changes ) {
			if ( empty( $changes ) ) {
				return '<span class="description">' . esc_html__( 'No tracked changes in stored snapshot.', 'tonys-sportspress-enhancements' ) . '</span>';
			}

			$output = '<div style="display:grid;gap:8px;max-width:520px;">';
			foreach ( $changes as $field => $change ) {
				$output .= '<div>';
				$output .= '<strong>' . esc_html( implode( ', ', $this->get_changed_field_labels( array( $field ) ) ) ) . '</strong>';
				$output .= '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;margin-top:4px;">';
				$output .= '<pre style="white-space:pre-wrap;margin:0;max-height:120px;overflow:auto;">' . esc_html( $this->summarize_value( isset( $change['before'] ) ? $change['before'] : null ) ) . '</pre>';
				$output .= '<pre style="white-space:pre-wrap;margin:0;max-height:120px;overflow:auto;">' . esc_html( $this->summarize_value( isset( $change['after'] ) ? $change['after'] : null ) ) . '</pre>';
				$output .= '</div></div>';
			}
			$output .= '</div>';

			return $output;
		}

		/**
		 * Summarize a value for table display.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		private function summarize_value( $value ) {
			if ( null === $value || array() === $value || '' === $value ) {
				return '—';
			}

			if ( is_array( $value ) ) {
				$json = wp_json_encode( $value );
				return false === $json ? '' : $json;
			}

			return (string) $value;
		}

		/**
		 * Render pagination links.
		 *
		 * @param int $paged Current page.
		 * @param int $pages Total pages.
		 * @return void
		 */
		private function render_pagination( $paged, $pages ) {
			if ( $pages <= 1 ) {
				return;
			}

			$base_args = array(
				'page' => class_exists( 'Tony_Sportspress_Printable_Calendars' ) ? Tony_Sportspress_Printable_Calendars::PAGE_SLUG : 'tonys-settings',
				'tab'  => self::TAB_REVISIONS,
			);

			echo '<p class="tablenav-pages" style="margin:16px 0;">';
			echo esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$d of %2$d', 'tonys-sportspress-enhancements' ),
					$paged,
					$pages
				)
			);

			if ( $paged > 1 ) {
				echo ' <a class="button" href="' . esc_url( add_query_arg( array_merge( $base_args, array( 'tse_revision_page' => $paged - 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Previous', 'tonys-sportspress-enhancements' ) . '</a>';
			}

			if ( $paged < $pages ) {
				echo ' <a class="button" href="' . esc_url( add_query_arg( array_merge( $base_args, array( 'tse_revision_page' => $paged + 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Next', 'tonys-sportspress-enhancements' ) . '</a>';
			}

			echo '</p>';
		}
	}
}

/**
 * Boot the event revision audit feature.
 *
 * @return void
 */
function tony_sportspress_event_revisions_boot() {
	Tony_Sportspress_Event_Revisions::instance()->boot();
}
add_action( 'plugins_loaded', 'tony_sportspress_event_revisions_boot' );
