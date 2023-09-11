<?php
/**
 * Class handles unit tests for GatherPress\Core\Event_Query.
 *
 * @package GatherPress
 * @subpackage Core
 * @since 1.0.0
 */

namespace GatherPress\Tests\Core;

use GatherPress\Core\Event;
use GatherPress\Core\Event_Query;
use PMC\Unit_Test\Base;

/**
 * Class Test_Event_Query.
 *
 * @coversDefaultClass \GatherPress\Core\Event_Query
 */
class Test_Event_Query extends Base {

	/**
	 * Coverage for setup_hooks method.
	 *
	 * @covers ::__construct
	 * @covers ::setup_hooks
	 *
	 * @return void
	 */
	public function test_setup_hooks(): void {
		$instance = Event_Query::get_instance();
		$hooks    = array(
			array(
				'type'     => 'action',
				'name'     => 'pre_get_posts',
				'priority' => 10,
				'callback' => array( $instance, 'prepare_event_query_before_execution' ),
			),
			array(
				'type'     => 'filter',
				'name'     => 'posts_clauses',
				'priority' => 10,
				'callback' => array( $instance, 'adjust_admin_event_sorting' ),
			),
		);

		$this->assert_hooks( $hooks, $instance );
	}

	/**
	 * Coverage for get_upcoming_events method.
	 *
	 * @covers ::get_upcoming_events
	 * @covers ::adjust_sorting_for_upcoming_events
	 * @covers ::get_events_list
	 *
	 * @return void
	 */
	public function test_get_upcoming_events(): void {
		$instance = Event_Query::get_instance();
		$response = $instance->get_upcoming_events();

		$this->assertEmpty( $response->posts, 'Failed to assert that posts array is empty.' );
		$this->assertSame( 5, $response->query['posts_per_page'], 'Failed to assert post per page limit.' );

		$post  = $this->mock->post( array( 'post_type' => 'gp_event' ) )->get();
		$event = new Event( $post->ID );
		$date  = new \DateTime( 'tomorrow' );

		$params = array(
			'datetime_start' => $date->format( 'Y-m-d H:i:s' ),
			'datetime_end'   => $date->modify( '+1 day' )->format( 'Y-m-d H:i:s' ),
			'timezone'       => 'America/New_York',
		);

		$event->save_datetimes( $params );

		$response = $instance->get_upcoming_events( 1 );

		$this->assertSame( $response->posts[0], $post->ID, 'Failed to assert that event ID is in array.' );
		$this->assertSame( 1, $response->query['posts_per_page'], 'Failed to assert post per page limit.' );
		$this->assertSame( 'upcoming', $response->query['gp_events_query'], 'Failed to assert query is upcoming.' );
		$this->assertSame( 'gp_event', $response->query['post_type'], 'Failed to assert post type is gp_event.' );
	}

	/**
	 * Coverage for get_past_events method.
	 *
	 * @covers ::get_past_events
	 * @covers ::adjust_sorting_for_past_events
	 * @covers ::get_events_list
	 *
	 * @return void
	 */
	public function test_get_past_events(): void {
		$instance = Event_Query::get_instance();
		$response = $instance->get_past_events();

		$this->assertEmpty( $response->posts, 'Failed to assert that posts array is empty.' );
		$this->assertSame( 5, $response->query['posts_per_page'], 'Failed to assert post per page limit.' );

		$post  = $this->mock->post( array( 'post_type' => 'gp_event' ) )->get();
		$event = new Event( $post->ID );
		$date  = new \DateTime( 'yesterday' );

		$params = array(
			'datetime_start' => $date->modify( '-1 day' )->format( 'Y-m-d H:i:s' ),
			'datetime_end'   => $date->format( 'Y-m-d H:i:s' ),
			'timezone'       => 'America/New_York',
		);

		$event->save_datetimes( $params );

		$response = $instance->get_past_events( 1 );

		$this->assertSame( $response->posts[0], $post->ID, 'Failed to assert that event ID is in array.' );
		$this->assertSame( 1, $response->query['posts_per_page'], 'Failed to assert post per page limit.' );
		$this->assertSame( 'past', $response->query['gp_events_query'], 'Failed to assert query is past.' );
		$this->assertSame( 'gp_event', $response->query['post_type'], 'Failed to assert post type is gp_event.' );
	}

	/**
	 * Coverage for adjust_admin_event_sorting method.
	 *
	 * @covers ::adjust_admin_event_sorting
	 *
	 * @return void
	 */
	public function test_adjust_admin_event_sorting(): void {
		$instance = Event_Query::get_instance();

		$this->mock->user( false, 'admin' );
		$response = $instance->adjust_admin_event_sorting( array() );
		$this->assertEmpty( $response, 'Failed to assert array is not empty' );

		$this->mock->user( true, 'admin' );

		// Set 'orderby' admin query to 'datetime'.
		global $wp_query;
		$wp_query->set( 'orderby', 'datetime' );

		// Run function with empty array passed as 'pieces' argument.
		$response = $instance->adjust_admin_event_sorting( array() );

		// Assert that an array was generated from the adjustsql argument. todo: make this test more meaningful.
		$this->assertNotEmpty( $response, 'Failed to assert array is empty' );
	}

	/**
	 * Coverage for adjust_event_sql method.
	 *
	 * @covers ::adjust_event_sql
	 *
	 * @return void
	 */
	public function test_adjust_event_sql(): void {
		global $wpdb;

		$instance = Event_Query::get_instance();

		$table  = sprintf( Event::TABLE_FORMAT, $wpdb->prefix, Event::POST_TYPE );
		$retval = $instance->adjust_event_sql( array(), 'all', 'DESC' );

		$this->assertStringContainsString( 'DESC', $retval['orderby'] );
		$this->assertEmpty( $retval['where'] );

		$retval = $instance->adjust_event_sql( array(), 'past', 'desc' );

		$this->assertStringContainsString( 'DESC', $retval['orderby'] );
		$this->assertStringContainsString( "AND {$table}.datetime_end_gmt <", $retval['where'] );

		$retval = $instance->adjust_event_sql( array(), 'upcoming', 'ASC' );

		$this->assertStringContainsString( 'ASC', $retval['orderby'] );
		$this->assertStringContainsString( "AND {$table}.datetime_end_gmt >=", $retval['where'] );
	}

}