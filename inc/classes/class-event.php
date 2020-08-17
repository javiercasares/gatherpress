<?php
/**
 * Class is responsible for all event related functionality.
 *
 * @package GatherPress
 * @subpackage Core
 * @since 1.0.0
 */

namespace GatherPress\Inc;

use GatherPress\Inc\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event.
 */
class Event {

	use Singleton;

	const POST_TYPE          = 'gp_event';
	const TABLE_FORMAT       = '%s%s_extended';
	const DATETIME_CACHE_KEY = 'datetime_%d';

	/**
	 * Event constructor.
	 */
	protected function __construct() {
		$this->rest_namespace = GP_REST_NAMESPACE . '/event';

		$this->setup_hooks();
	}

	/**
	 * Setup hooks.
	 */
	protected function setup_hooks() {
		/**
		 * Actions.
		 */
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'change_rewrite_rule' ) );
		add_action( 'init', array( $this, 'maybe_create_custom_table' ) );
		add_action( 'delete_post', array( $this, 'delete_event' ) );
		add_action( sprintf( 'manage_%s_posts_custom_column', self::POST_TYPE ), array( $this, 'custom_columns' ), 10, 2 );

		/**
		 * Filters.
		 */
		add_filter( 'wpmu_drop_tables', array( $this, 'on_site_delete' ) );
		add_filter( 'wp_unique_post_slug', array( $this, 'append_id_to_event_slug' ), 10, 4 );
		add_filter( sprintf( 'manage_%s_posts_columns', self::POST_TYPE ), array( $this, 'set_custom_columns' ) );
		add_filter( sprintf( 'manage_edit-%s_sortable_columns', self::POST_TYPE ), array( $this, 'sortable_columns' ) );
		add_filter( 'the_content', array( $this, 'before_content' ), 0 );
		add_filter( 'the_content', array( $this, 'after_content' ), 99999 );
		add_filter( 'get_the_date', array( $this, 'get_the_event_date' ), 10, 2 );
		add_filter( 'the_time', array( $this, 'get_the_event_date' ), 10, 2 );
	}

	/**
	 * Ensure that event slugs always have ID appended to URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug        The desired slug (post_name).
	 * @param int    $post_id     Post ID.
	 * @param string $post_status No uniqueness checks are made if the post is still draft or pending.
	 * @param string $post_type   Post type.
	 *
	 * @return string
	 */
	public function append_id_to_event_slug( string $slug, int $post_id, string $post_status, string $post_type ) : string {
		if ( static::POST_TYPE !== $post_type ) {
			return $slug;
		}

		if ( 1 > intval( $post_id ) ) {
			return $slug;
		}

		if ( ! preg_match( '/-(\d+)$/', $slug, $matches ) ) {
			return "{$slug}-{$post_id}";
		}

		$slug_id = intval( $matches[1] );

		if ( $slug_id === $post_id ) {
			return $slug;
		}

		return preg_replace( '/-\d+$/', '-' . $post_id, $slug );
	}

	/**
	 * Add new rewrite rule for event to append Post ID.
	 *
	 * @since 1.0.0
	 */
	public function change_rewrite_rule() {
		add_rewrite_rule(
			'^events/([^/]*)-([0-9]+)/?$',
			sprintf(
				'index.php?post_type=%s&postname=$matches[1]&p=$matches[2]',
				static::POST_TYPE
			),
			'top'
		);
	}

	/**
	 * Maybe create custom table if doesn't exist for main site or current site in network.
	 *
	 * @since 1.0.0
	 */
	public function maybe_create_custom_table() {
		$this->create_table();

		if ( is_multisite() ) {
			$blog_id = get_current_blog_id();

			switch_to_blog( $blog_id );
			$this->create_table();
			restore_current_blog();
		}
	}

	/**
	 * Delete custom table on site deletion.
	 *
	 * @since 1.0.0
	 *
	 * @param array $tables Array of names of the site tables to be dropped.
	 *
	 * @return array
	 */
	public function on_site_delete( array $tables ) : array {
		global $wpdb;

		$tables[] = sprintf( static::TABLE_FORMAT, $wpdb->prefix, static::POST_TYPE );

		return $tables;
	}

	/**
	 * Create custom event table.
	 *
	 * @since 1.0.0
	 */
	public function create_table() {
		global $wpdb;

		$sql             = array();
		$charset_collate = $GLOBALS['wpdb']->get_charset_collate();
		$table           = sprintf( static::TABLE_FORMAT, $wpdb->prefix, static::POST_TYPE );

		$sql[] = "CREATE TABLE {$table} (
					post_id bigint(20) unsigned NOT NULL default '0',
					datetime_start datetime NOT NULL default '0000-00-00 00:00:00',
					datetime_start_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					datetime_end datetime NOT NULL default '0000-00-00 00:00:00',
					datetime_end_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					timezone varchar(255) default NULL,
					PRIMARY KEY  (post_id),
					KEY datetime_start_gmt (datetime_start_gmt),
					KEY datetime_end_gmt (datetime_end_gmt)
				) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );
	}

	/**
	 * Register the Event post type.
	 *
	 * @since 1.0.0
	 */
	public function register_post_types() {
		register_post_type(
			static::POST_TYPE,
			array(
				'labels'        => array(
					'name'               => _x( 'Events', 'Post Type General Name', 'gatherpress' ),
					'singular_name'      => _x( 'Event', 'Post Type Singular Name', 'gatherpress' ),
					'menu_name'          => __( 'Events', 'gatherpress' ),
					'all_items'          => __( 'All Events', 'gatherpress' ),
					'view_item'          => __( 'View Event', 'gatherpress' ),
					'add_new_item'       => __( 'Add New Event', 'gatherpress' ),
					'add_new'            => __( 'Add New', 'gatherpress' ),
					'edit_item'          => __( 'Edit Event', 'gatherpress' ),
					'update_item'        => __( 'Update Event', 'gatherpress' ),
					'search_items'       => __( 'Search Events', 'gatherpress' ),
					'not_found'          => __( 'Not Found', 'gatherpress' ),
					'not_found_in_trash' => __( 'Not found in Trash', 'gatherpress' ),
				),
				'show_in_rest'  => true,
				'public'        => true,
				'hierarchical'  => false,
				'menu_position' => 3,
				'supports'      => array(
					'title',
					'editor',
					'thumbnail',
					'comments',
					'revisions',
				),
				'menu_icon'     => 'dashicons-calendar',
				'rewrite'       => array(
					'slug' => 'events',
				),
			)
		);
	}

	/**
	 * Delete event record from custom table when event is deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id An event post ID.
	 */
	public function delete_event( int $post_id ) {
		global $wpdb;

		if ( static::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$table = sprintf( static::TABLE_FORMAT, $wpdb->prefix, static::POST_TYPE );

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Save the start and end datetimes for an event.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     An array of arguments used to save event data to custom event table.
	 *
	 *     @type int     $post_id        An event post ID.
	 *     @type string  $datetime_start Start DateTime to save for event.
	 *     @type string  $datetime_end   End DateTime to save for event.
	 *     @type string  $timezone       Timezone of the event.
	 *
	 * }
	 *
	 * @return bool
	 */
	public function save_datetimes( array $params ) : bool {
		global $wpdb;

		$retval = false;
		$fields = array_filter(
			$params,
			function( $key ) {
				return in_array(
					$key,
					array(
						'post_id',
						'datetime_start',
						'datetime_end',
						'timezone',
					),
					true
				);
			},
			ARRAY_FILTER_USE_KEY
		);

		if ( 1 > intval( $fields['post_id'] ) ) {
			return $retval;
		}

		$fields['datetime_start_gmt'] = get_gmt_from_date( $fields['datetime_start'] );
		$fields['datetime_end_gmt']   = get_gmt_from_date( $fields['datetime_end'] );
		$fields['timezone']           = ( ! empty( $fields['timezone'] ) ) ? $fields['timezone'] : wp_timezone_string();
		$table                        = sprintf( static::TABLE_FORMAT, $wpdb->prefix, static::POST_TYPE );

		// @todo Add caching to this and create new method to check existence.
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT post_id FROM ' . esc_sql( $table ) . ' WHERE post_id = %d',
				$fields['post_id']
			)
		);

		if ( ! empty( $exists ) ) {
			$retval = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				$fields,
				array( 'post_id' => $fields['post_id'] )
			);
			wp_cache_delete( sprintf( self::DATETIME_CACHE_KEY, $fields['post_id'] ) );
		} else {
			$retval = $wpdb->insert( $table, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}

		return (bool) $retval;
	}

	/**
	 * Get display DateTime.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id An event post ID.
	 *
	 * @return string
	 */
	public function get_display_datetime( int $post_id ) : string {
		if ( 0 >= $post_id ) {
			return '';
		}

		if ( $this->is_same_date( $post_id ) ) {
			$start = $this->get_datetime_start( $post_id, 'l, F j, Y g:i A' );
			$end   = $this->get_datetime_end( $post_id, 'g:i A T' );
		} else {
			$start = $this->get_datetime_start( $post_id, 'l, F j, Y, g:i A' );
			$end   = $this->get_datetime_end( $post_id, 'l, F j, Y, g:i A T' );
		}

		return sprintf( '%s to %s', $start, $end );
	}

	/**
	 * Check if start DateTime and end DateTime is same date.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id An event post ID.
	 *
	 * @return bool
	 */
	public function is_same_date( int $post_id ) : bool {
		$datetime_start = $this->get_datetime_start( $post_id, 'Y-m-d' );
		$datetime_end   = $this->get_datetime_end( $post_id, 'Y-m-d' );

		if ( empty( $datetime_start ) || empty( $datetime_end ) ) {
			return false;
		}

		if ( $datetime_start === $datetime_end ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if event is in the past.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id An event post ID.
	 *
	 * @return bool
	 */
	public function has_event_past( int $post_id ) : bool {
		$data    = $this->get_datetime( $post_id );
		$end     = $data['datetime_end_gmt'];
		$current = time();

		if ( $current > strtotime( $end ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get datetime start.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id An event post ID.
	 * @param string $format  PHP date format.
	 *
	 * @return string
	 */
	public function get_datetime_start( int $post_id, string $format = 'D, F j, g:ia T' ) : string {
		return $this->get_formatted_date( $post_id, $format, 'datetime_start' );
	}


	/**
	 * Get datetime end.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id An event post ID.
	 * @param string $format  PHP date format.
	 *
	 * @return string
	 */
	public function get_datetime_end( int $post_id, string $format = 'D, F j, g:ia T' ) : string {
		return $this->get_formatted_date( $post_id, $format, 'datetime_end' );
	}

	/**
	 * Format date for display.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id An event post ID.
	 * @param string $format  PHP date format.
	 * @param string $which   The datetime field in event table.
	 *
	 * @return string
	 */
	protected function get_formatted_date( int $post_id, string $format = 'D, F j, g:ia T', string $which = 'datetime_start' ) : string {
		$server_timezone = date_default_timezone_get();
		$site_timezone   = wp_timezone_string();

		// If site timezone is a valid setting, set it for timezone, if not remove `T` from format.
		if ( ! preg_match( '/^-|\+/', $site_timezone ) ) {
			date_default_timezone_set( $site_timezone ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
		} else {
			$format = str_replace( ' T', '', $format );
		}

		$dt   = $this->get_datetime( $post_id );
		$date = $dt[ $which ];

		if ( ! empty( $date ) ) {
			$ts   = strtotime( $date );
			$date = gmdate( $format, $ts );
		}

		date_default_timezone_set( $server_timezone ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

		return (string) $date;
	}

	/**
	 * Get the datetime from custom table.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id An event post ID.
	 *
	 * @return array
	 */
	public function get_datetime( int $post_id ) : array {
		global $wpdb;

		$data = array();

		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			$cache_key = sprintf( self::DATETIME_CACHE_KEY, $post_id );
			$data      = wp_cache_get( $cache_key );

			if ( empty( $data ) || ! is_array( $data ) ) {
				$table = sprintf( static::TABLE_FORMAT, $wpdb->prefix, static::POST_TYPE );
				$data  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT datetime_start, datetime_start_gmt, datetime_end, datetime_end_gmt FROM ' . esc_sql( $table ) . ' WHERE post_id = %d LIMIT 1', $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$data  = ( ! empty( $data ) ) ? (array) current( $data ) : array();

				wp_cache_set( $cache_key, $data, 15 * MINUTE_IN_SECONDS );
			}
		}

		return array_merge(
			array(
				'datetime_start'     => '',
				'datetime_start_gmt' => '',
				'datetime_end'       => '',
				'datetime_end_gmt'   => '',
			),
			(array) $data
		);
	}

	/**
	 * Get all supported add to calendar links for event.
	 *
	 * @since 1.0.0
	 *
	 * @todo need to add venue location for all calendar methods when feature is done.
	 *
	 * @param int $post_id An event post ID.
	 *
	 * @return array
	 */
	public function get_calendar_links( int $post_id ) : array {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return array();
		}

		$event = get_post( $post_id );

		return array(
			'google' => $this->get_google_calendar_link( $event ),
			'isc'    => $this->get_ics_calendar_download( $event ),
			'yahoo'  => $this->get_yahoo_calendar_link( $event ),
		);
	}

	/**
	 * Get add to Google calendar link for event.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $event An event post object.
	 *
	 * @return string
	 */
	protected function get_google_calendar_link( \WP_Post $event ) : string {
		$date_start = $this->get_formatted_date( $event->ID, 'Ymd', 'datetime_start_gmt' );
		$time_start = $this->get_formatted_date( $event->ID, 'His', 'datetime_start_gmt' );
		$date_end   = $this->get_formatted_date( $event->ID, 'Ymd', 'datetime_end_gmt' );
		$time_end   = $this->get_formatted_date( $event->ID, 'His', 'datetime_end_gmt' );
		$datetime   = sprintf( '%sT%sZ/%sT%sZ', $date_start, $time_start, $date_end, $time_end );

		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => sanitize_text_field( $event->post_title ),
				'dates'    => sanitize_text_field( $datetime ),
				'details'  => sanitize_text_field( $event->post_content ),
				'location' => '',
				'sprop'    => 'name:',
			),
			'https://www.google.com/calendar/render/'
		);
	}

	/**
	 * Get add to Yahoo! calendar link for event.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $event An event post object.
	 *
	 * @return string
	 */
	protected function get_yahoo_calendar_link( \WP_Post $event ) : string {
		$date_start     = $this->get_formatted_date( $event->ID, 'Ymd', 'datetime_start_gmt' );
		$time_start     = $this->get_formatted_date( $event->ID, 'His', 'datetime_start_gmt' );
		$datetime_start = sprintf( '%sT%sZ', $date_start, $time_start );

		// Figure out duration of event in hours and minutes: hhmm format.
		$diff_start = $this->get_formatted_date( $event->ID, 'Y-m-d H:i:s', 'datetime_start_gmt' );
		$diff_end   = $this->get_formatted_date( $event->ID, 'Y-m-d H:i:s', 'datetime_end_gmt' );
		$duration   = ( ( strtotime( $diff_end ) - strtotime( $diff_start ) ) / 60 / 60 );
		$full       = intval( $duration );
		$fraction   = ( $duration - $full );
		$hours      = str_pad( intval( $duration ), 2, '0', STR_PAD_LEFT );
		$minutes    = str_pad( intval( $fraction * 60 ), 2, '0', STR_PAD_LEFT );

		return add_query_arg(
			array(
				'v'      => '60',
				'view'   => 'd',
				'type'   => '20',
				'title'  => sanitize_text_field( $event->post_title ),
				'st'     => sanitize_text_field( $datetime_start ),
				'dur'    => sanitize_text_field( (string) $hours . (string) $minutes ),
				'desc'   => sanitize_text_field( $event->post_content ),
				'in_loc' => '',
			),
			'https://calendar.yahoo.com/'
		);
	}

	/**
	 * Get ICS download for event.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $event An event post object.
	 *
	 * @return string
	 */
	protected function get_ics_calendar_download( \WP_Post $event ) : string {
		$date_start     = $this->get_formatted_date( $event->ID, 'Ymd', 'datetime_start_gmt' );
		$time_start     = $this->get_formatted_date( $event->ID, 'His', 'datetime_start_gmt' );
		$date_end       = $this->get_formatted_date( $event->ID, 'Ymd', 'datetime_end_gmt' );
		$time_end       = $this->get_formatted_date( $event->ID, 'His', 'datetime_end_gmt' );
		$datetime_start = sprintf( '%sT%sZ', $date_start, $time_start );
		$datetime_end   = sprintf( '%sT%sZ', $date_end, $time_end );

		$args = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'BEGIN:VEVENT',
			sprintf( 'URL:%s', esc_url_raw( get_permalink( $event->ID ) ) ),
			sprintf( 'DTSTART:%s', sanitize_text_field( $datetime_start ) ),
			sprintf( 'DTEND:%s', sanitize_text_field( $datetime_end ) ),
			sprintf( 'SUMMARY:%s', sanitize_text_field( $event->post_title ) ),
			sprintf( 'DESCRIPTION:%s', sanitize_text_field( $event->post_content ) ),
			sprintf( 'LOCATION:%s', '' ),
			'END:VEVENT',
			'END:VCALENDAR',
		);

		return 'data:text/calendar;charset=utf8,' . implode( '%0A', $args );
	}

	/**
	 * Set custom columns for Event post type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns An associative array of column headings.
	 *
	 * @return array
	 */
	public function set_custom_columns( array $columns ) : array {
		$placement = 2;
		$insert    = array(
			'datetime' => __( 'Date & time', 'gatherpress' ),
		);

		return array_slice( $columns, 0, $placement, true ) + $insert + array_slice( $columns, $placement, null, true );
	}

	/**
	 * Populate custom columns for Event post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  The name of the column to display.
	 * @param int    $post_id The current post ID.
	 */
	public function custom_columns( string $column, int $post_id ) {
		switch ( $column ) {
			case 'datetime':
				echo esc_html( $this->get_display_datetime( $post_id ) );
				break;
		}
	}

	/**
	 * Make custom columns sortable for Event post type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns An array of sortable columns.
	 *
	 * @return array
	 */
	public function sortable_columns( array $columns ) : array {
		$columns['datetime'] = 'datetime';

		return $columns;
	}

	/**
	 * Adjust SQL for Event queries to join on gp_event_extended table.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $pieces Includes pieces of the query like join, where, orderby, et al.
	 * @param string $type   Options are all, future, or past.
	 * @param string $order  Event order DESC or ASC.
	 *
	 * @return array
	 */
	public function adjust_sql( array $pieces, string $type = 'all', string $order = 'DESC' ) : array {
		global $wp_query, $wpdb;

		$defaults = array(
			'where'    => '',
			'groupby'  => '',
			'join'     => '',
			'orderby'  => '',
			'distinct' => '',
			'fields'   => '',
			'limits'   => '',
		);
		$pieces   = array_merge( $defaults, $pieces );

		if ( self::POST_TYPE === $wp_query->get( 'post_type' ) ) {
			$table          = sprintf( self::TABLE_FORMAT, $wpdb->prefix, self::POST_TYPE );
			$pieces['join'] = 'LEFT JOIN ' . esc_sql( $table ) . ' ON ' . esc_sql( $wpdb->posts ) . '.ID=' . esc_sql( $table ) . '.post_id';
			$order          = strtoupper( $order );

			if ( in_array( $order, array( 'DESC', 'ASC' ), true ) ) {
				$pieces['orderby'] = sprintf( esc_sql( $table ) . '.datetime_start_gmt %s', esc_sql( $order ) );
			}

			if ( 'all' !== $type ) {
				$current = gmdate( 'Y-m-d H:i:s', time() );

				switch ( $type ) {
					case 'future':
						$pieces['where'] .= $wpdb->prepare( ' AND ' . esc_sql( $table ) . '.datetime_end_gmt >= %s', esc_sql( $current ) );
						break;
					case 'past':
						$pieces['where'] .= $wpdb->prepare( ' AND ' . esc_sql( $table ) . '.datetime_end_gmt < %s', esc_sql( $current ) );
						break;
				}
			}
		}

		return $pieces;
	}

	/**
	 * Adds before content template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content of the current post.
	 *
	 * @return string
	 */
	public function before_content( $content ) : string {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $content;
		}

		$before = Helper::render_template(
			GP_CORE_PATH . '/template-parts/before-event-content.php',
			array(
				'event' => $this,
			)
		);

		return $before . $content;
	}

	/**
	 * Adds after content template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content of the current post.
	 *
	 * @return string
	 */
	public function after_content( $content ) : string {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $content;
		}

		$after = Helper::render_template(
			GP_CORE_PATH . '/template-parts/after-event-content.php'
		);

		return $content . $after;
	}

	/**
	 * Returns the event date instead of publish date for events.
	 *
	 * @since 1.0.0
	 *
	 * @param string $the_date The formatted date.
	 * @param string $format   PHP date format.
	 *
	 * @return string
	 */
	public function get_the_event_date( $the_date, $format ) : string {
		global $post;

		if ( ! is_a( $post, '\WP_Post' ) && self::POST_TYPE !== $post->post_type ) {
			return $the_date;
		}

		if ( empty( $format ) ) {
			$format = get_option( 'date_format' );
		}

		return $this->get_datetime_start( $post->ID, $format );
	}

}
