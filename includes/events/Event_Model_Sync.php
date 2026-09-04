<?php

namespace WpCalendar\Events;

use DateTimeImmutable;
use WpCalendar\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Model_Sync {
	private Event_Query_Service $event_query_service;

	public function __construct( ?Event_Query_Service $event_query_service = null ) {
		$this->event_query_service = $event_query_service ?? new Event_Query_Service();

		add_action( 'save_post', array( $this, 'sync_post' ), 20, 2 );
	}

	public function sync_post( int $post_id, $post ): void {
		if ( ! is_object( $post ) || empty( $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Settings_Page::get_event_source_post_types(), true ) ) {
			return;
		}

		$definitions = $this->event_query_service->get_event_definitions_for_post( $post_id );

		if ( empty( $definitions ) ) {
			$this->clear_summary_meta( $post_id );
			return;
		}

		update_post_meta( $post_id, Event_Query_Service::EVENT_HAS_EVENTS_META, '1' );
		update_post_meta( $post_id, Event_Query_Service::EVENT_RANGE_START_META, $this->resolve_range_start( $definitions )->format( 'Y-m-d H:i:s' ) );

		$range_end = $this->resolve_range_end( $definitions );

		if ( $range_end ) {
			update_post_meta( $post_id, Event_Query_Service::EVENT_RANGE_END_META, $range_end->format( 'Y-m-d H:i:s' ) );
		} else {
			delete_post_meta( $post_id, Event_Query_Service::EVENT_RANGE_END_META );
		}
	}

	private function clear_summary_meta( int $post_id ): void {
		delete_post_meta( $post_id, Event_Query_Service::EVENT_HAS_EVENTS_META );
		delete_post_meta( $post_id, Event_Query_Service::EVENT_RANGE_START_META );
		delete_post_meta( $post_id, Event_Query_Service::EVENT_RANGE_END_META );
	}

	private function resolve_range_start( array $definitions ): DateTimeImmutable {
		$range_start = $definitions[0]['scheduled_start_time'];

		foreach ( $definitions as $definition ) {
			if ( $definition['scheduled_start_time'] < $range_start ) {
				$range_start = $definition['scheduled_start_time'];
			}
		}

		return $range_start;
	}

	private function resolve_range_end( array $definitions ): ?DateTimeImmutable {
		$range_end = null;

		foreach ( $definitions as $definition ) {
			$definition_range_end = $this->resolve_definition_range_end( $definition );

			if ( ! $definition_range_end ) {
				return null;
			}

			if ( ! $range_end || $definition_range_end > $range_end ) {
				$range_end = $definition_range_end;
			}
		}

		return $range_end;
	}

	private function resolve_definition_range_end( array $definition ): ?DateTimeImmutable {
		if ( Event_Query_Service::REPEAT_NONE === $definition['frequency'] ) {
			return $definition['scheduled_end_time'];
		}

		if ( ! $definition['recurrence_end'] ) {
			return null;
		}

		$duration = max( 0, $definition['scheduled_end_time']->getTimestamp() - $definition['scheduled_start_time']->getTimestamp() );

		return $definition['recurrence_end']->modify( sprintf( '+%d seconds', $duration ) );
	}
}
