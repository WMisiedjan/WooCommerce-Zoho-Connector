<?php

class Woozoho_Connector_Orders_Queue {

	protected $maxTries;
	protected $dataTable;
	protected $client;

	public function __construct() {
		$this->maxTries  = Woozoho_Connector::get_option( "orders_queue_max_tries" );
		$this->dataTable = "woozoho_orders_tracker";
	}

	public function getQueue() {
		global $wpdb;

		Woozoho_Connector_Logger::write_debug( "Orders Queue", "Listing all orders in queue in array." );

		$results = array();
		//First getting error orders with maxtries.

		$errorQueue = $wpdb->get_results(
			"
	SELECT * 
	FROM " . $wpdb->prefix . $this->dataTable . "
	WHERE (status = 'error' OR status = 'queued')
		AND tries <= " . $this->maxTries . "
	"
		);

		foreach ( $errorQueue as $orderQueueItem ) {
			array_push( $results, $orderQueueItem->post_id );
		}

		Woozoho_Connector_Logger::write_debug( "Orders Queue", count( $results ) . " active orders in queue listed" );

		return $results;
	}

	function addOrder( $order_id ) {
		global $wpdb;

		Woozoho_Connector_Logger::write_debug( "Orders Queue", "Inserting order '" . $order_id . "' into queue." );

		if ( ! $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM " . $wpdb->prefix . $this->dataTable . "
                    WHERE post_id = %d LIMIT 1",
				$order_id ) ) ) {
			if ( $wpdb->insert(
				$wpdb->prefix . $this->dataTable,
				array(
					'post_id'      => $order_id,
					'status'       => 'queued',
					'date_created' => date( "Y-m-d H:i:s" ),
					'date_updated' => date( "Y-m-d H:i:s" ),
					'tries'        => 0,
					'message'      => ''
				),
				array(
					'%d',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s'
				) ) ) {
				Woozoho_Connector_Logger::write_debug( "Orders Queue", "Sucessfully inserted '" . $order_id . "' into queue." );
			} else {
				Woozoho_Connector_Logger::write_debug( "Orders Queue", "ERROR: Something went wrong with queuing '" . $order_id . "' into queue." );
			}
		} else {
			Woozoho_Connector_Logger::write_debug( "Orders Queue", "Order '" . $order_id . "' already exists in queue, skipping..." );
		}
	}

	function updateOrder( $order_id, $status, $message = '', $pushtry = false ) {
		global $wpdb;

		$orderQueue = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . $this->dataTable . " WHERE post_id = " . $order_id );
		if ( $orderQueue != null ) {
			if ( $wpdb->update(
					$wpdb->prefix . 'woozoho_orders_tracker',
					array(
						'status'       => $status,    // string
						'date_updated' => date( "Y-m-d H:i:s" ),
						'message'      => ( $message != '' ) ? $message : $orderQueue->message,
						'tries'        => $pushtry ? ( $orderQueue->tries + 1 ) : $orderQueue->tries
					),
					array( 'post_id' => $order_id ),
					array(
						'%s',    // value1
						'%s',
						'%s',
						'%d'    // value2
					),
					array( '%d' )
				) !== false
			) {
				Woozoho_Connector_Logger::write_debug( "Orders Queue", "Successfully updated order queue of order_id:" . $order_id );
			} else {
				Woozoho_Connector_Logger::write_debug( "Orders Queue", "ERROR: Error updating orders queue for " . $order_id . "." );
			}
		} else {
			Woozoho_Connector_Logger::write_debug( "Orders Queue", "ERROR: No orders in queue found for order id: " . $order_id . "." );
		}
	}
}
