<?php

/**
 * Fired during plugin activation
 *
 * @link       https://digispark.nl/
 * @since      1.0.0
 *
 * @package    Woozoho_Connector
 * @subpackage Woozoho_Connector/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Woozoho_Connector
 * @subpackage Woozoho_Connector/includes
 * @author     Wendell Misiedjan <me@digispark.nl>
 */
class Woozoho_Connector_Activator {

	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && get_option( 'woozoho_connector_version' ) !== Woozoho_Connector()->version ) {
			self::install();
		}
	}

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function install() {
		global $wpdb;
		//TODO: Implement version management for database
		//TODO: Implement database update scripts (e.x. WooCommerce)

		$table_name = $wpdb->prefix . 'woozoho_orders_tracker';
		Woozoho_Connector_Logger::write_debug( "Install DB", "Activating plugin in " . $table_name );

		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
			Woozoho_Connector_Logger::write_debug( "Install DB", "Table doesn't exist, creating table " . $table_name );

			$sql = "CREATE TABLE $table_name (
  			ID bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            status text NOT NULL,
            date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tries int(11) NOT NULL,
            message text NOT NULL,
  			PRIMARY KEY  (ID),
  			KEY post_id (post_id)
				)";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			$resultData = dbDelta( $sql );
			Woozoho_Connector_Logger::write_debug( "Install DB", $resultData );

		} else {
			Woozoho_Connector_Logger::write_debug( "Install DB", "Table already installed. Moving on." );
		}
	}

	public static function deactivate( $dependencies_not_met = false ) {
		//TODO: Remove database on deactivation
	}

	public static function activate() {
		if ( Woozoho_Connector()->check_dependencies() ) {
			self::check_version();
		} else {
			return false;
		}
	}
}
