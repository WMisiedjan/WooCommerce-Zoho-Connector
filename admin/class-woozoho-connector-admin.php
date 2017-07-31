<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://digispark.nl/
 * @since      1.0.0
 *
 * @package    Woozoho_Connector
 * @subpackage Woozoho_Connector/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Woozoho_Connector
 * @subpackage Woozoho_Connector/admin
 * @author     Wendell Misiedjan <me@digispark.nl>
 */
class Woozoho_Connector_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	private $client;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 *
	 */
	public function __construct() {
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woozoho_Connector_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woozoho_Connector_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/woozoho-connector-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Woozoho_Connector_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Woozoho_Connector_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/woozoho-connector-admin.js', array( 'jquery' ), $this->version, false );

	}

	//Settings page add link to settings page.
	public function add_action_links( $links ) {
		$settings_link = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=zoho_connector">' . __( 'Settings', $this->plugin_name ) . '</a>' )
		);

		return array_merge( $settings_link, $links );
	}

	//WooCommerce Settings Tab Functionality
	public function woocommerce_add_settings_tab( $settings_tabs ) {
		$settings_tabs['zoho_connector'] = __( 'Zoho Connector', 'woozoho-connector' );

		return $settings_tabs;
	}

	function woocommerce_add_bulk_actions( $bulk_actions ) {
		$bulk_actions['send_zoho'] = __( 'Push To Zoho', 'woozoho-connector' );

		return $bulk_actions;
	}

	function woocommerce_bulk_action_send_zoho( $redirect_to, $action, $post_ids ) {
		if ( $action !== 'send_zoho' ) {
			return $redirect_to;
		}

		foreach ( $post_ids as $post_id ) {
			$this->client->scheduleOrder( $post_id, false );
		}

		$redirect_to = add_query_arg( 'bulk_send_zoho', count( $post_ids ), $redirect_to );

		return $redirect_to;
	}


	function woocommerce_zoho_connector_admin_notices() {
		if ( ! empty( $_REQUEST['bulk_send_zoho'] ) ) {
			$orders_count = intval( $_REQUEST['bulk_send_zoho'] );

			printf(
				'<div id="message" class="updated fade">' .
				_n( '%s order is queued to be send to Zoho.', '%s orders are queued to be send to Zoho.', $orders_count, 'woozoho-connector' )
				. '</div>',
				$orders_count
			);
		}
	}

	function pushOrder( $order_id ) {
		$this->client->pushOrder( $order_id );
	}


	public function woocommerce_settings_tab() {
		include_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'woozoho-connector-admin-display.php';
	}

	public function doAction( $action ) {
		switch ( $action ) {
			case "clearcache": {
				WC_Admin_Notices::add_custom_notice( 'woozoho_clear_cache_notice', "Clearing Zoho Connector cache in the background..." );
				$this->client->writeDebug( "Action", "Regenerating caches..." );
				$this->client->getCache()->scheduleCaching();
			}
		}
	}

	public function scheduleOrder( $order_id ) {
		$hook = current_action();

		if ( $hook == "woocommerce_new_order" ) {
			$this->client->writeDebug( "WooCommerce", "A new order ($order_id) received." );
		}

		if ( WC_Admin_Settings::get_option( "wc_zoho_connector_cron_orders_recurrence" ) == "directly" ) {
			$this->client->scheduleOrder( $order_id );
		} else {
			$this->client->getOrdersQueue()->addOrder( $order_id );
		}
	}

	public static function woocommerce_update_settings() {
		$oldOrderRecurrence = WC_Admin_Settings::get_option( "wc_zoho_connector_cron_orders_recurrence" );
		Woozoho_Connector_Zoho_Client::writeDebug( "Settings", "Settings updated!" );
		$data = WC_Admin_Settings::get_option( "wc_zoho_connector_notify_email_option" );
		Woozoho_Connector_Zoho_Client::writeDebug( "Settings",
			"Settings data: " .
			print_r( $data->zoho_sku, true ) );
		woocommerce_update_options( self::get_settings() );
		if ( $oldOrderRecurrence != WC_Admin_Settings::get_option( "wc_zoho_connector_cron_orders_recurrence" ) ) {
			$cronJobs = new Woozoho_Connector_Cronjobs( new Woozoho_Connector_Zoho_Client() );
			$cronJobs->updateOrdersJob( WC_Admin_Settings::get_option( "wc_zoho_connector_cron_orders_recurrence" ) );
		}
	}

	public static function get_settings() {
		$settings = array(

			array(
				'name' => __( 'Zoho Connector Authentication', 'woozoho-connector' ),
				'type' => 'title',
				'desc' => 'Your Zoho API credentials for connecting WooCommerce to Zoho.',
				'id'   => 'wc_zoho_connector_section_authentication'
			),

			array(
				'name' => __( 'Auth Token', 'woozoho-connector' ),
				'type' => 'text',
				'desc' => __( 'Generate a auth code here.', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_token'
			),

			array(
				'name' => __( 'Organisation Id', 'woozoho-connector' ),
				'type' => 'text',
				'desc' => __( 'Find your organisation id here.', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_organisation_id'
			),

			array( 'type' => 'sectionend', 'id' => 'wc_zoho_connector_section_authentication' ),

			array(
				'name' => __( 'Email Notifications', 'woozoho-connector' ),
				'type' => 'title',
				'desc' => 'Get a message or get notified when a certain thing happens.',
				'id'   => 'wc_zoho_connector_section_mail_notifications'
			),

			array(
				'name' => __( 'Notification Email', 'woozoho-connector' ),
				'type' => 'text',
				'desc' => __( 'Email where notifications and logs from synchronizing are sent too.', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_notify_email'
			),

			array(
				'title'         => __( 'Notification Email Options', 'woozoho-connector' ),
				'desc'          => __( 'When an order failed to sync.', 'woozoho-connector' ),
				'id'            => 'wc_zoho_connector_email_notifications_failed_order',
				'default'       => 'no',
				'type'          => 'checkbox',
				'checkboxgroup' => 'start',
				'desc_tip'      => __( 'Send a notification when a order failed to sync to zoho.', 'woozoho-connector' ),
			),

			array(
				'desc'          => __( 'A new contact is created in Zoho.', 'woocommerce' ),
				'id'            => 'wc_zoho_connector_email_notifications_new_contact',
				'default'       => 'no',
				'type'          => 'checkbox',
				'desc_tip'      => __( 'Send a notification when the connector finds no existing contact and creates a new one.', 'woozoho-connector' ),
				'checkboxgroup' => 'end',
				'autoload'      => false,
			),

			array( 'type' => 'sectionend', 'id' => 'wc_zoho_connector_section_mail_notifications' ),

			array(
				'name' => __( 'Cron Jobs', 'woozoho-connector' ),
				'type' => 'title',
				'desc' => 'Settings for synchronisation cron jobs.',
				'id'   => 'wc_zoho_connector_section_cronjobs'
			),

			array(
				'name' => __( 'Enable Orders Cron', 'woozoho-connector' ),
				'type' => 'checkbox',
				'desc' => __( 'Automatically sync orders to zoho?', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_cron_orders_enabled'
			),

			array(
				'name'    => __( 'Syncing Orders', 'woozoho-connector' ),
				'type'    => 'select',
				'options' => array(
					'directly'   => __( 'Directly', 'woozoho-connector' ),
					'hourly'     => __( 'Hourly', 'woozoho-connector' ),
					'twicedaily' => __( 'Twice Daily', 'woozoho-connector' ),
					'daily'      => __( 'Daily', 'woozoho-connector' )
				),
				'desc'    => __( 'How often should orders be synced?', 'woozoho-connector' ),
				'id'      => 'wc_zoho_connector_cron_orders_recurrence'
			),

			array(
				'name'    => __( 'Orders Queue Max Tries', 'woozoho-connector' ),
				'type'    => 'text',
				'default' => '10',
				'desc'    => __( 'How often should we try to push orders?', 'woozoho-connector' ),
				'id'      => 'wc_zoho_connector_orders_queue_max_tries'
			),

			array( 'type' => 'sectionend', 'id' => 'wc_zoho_connector_section_cronjobs' ),

			array(
				'name' => __( 'Advanced Settings', 'woozoho-connector' ),
				'type' => 'title',
				'desc' => 'Enable / disable debugging, test-mode, caching and more!',
				'id'   => 'wc_zoho_connector_section_advanced_settings'
			),

			array(
				'name' => __( 'Debugging', 'woozoho-connector' ),
				'type' => 'checkbox',
				'desc' => __( 'Enable debugging in logfile?', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_debugging'
			),

			array(
				'name' => __( 'Test mode', 'woozoho-connector' ),
				'type' => 'checkbox',
				'desc' => __( 'Enable testmode?', 'woozoho-connector' ),
				'id'   => 'wc_zoho_connector_testmode'
			),

			array(
				'name'    => __( 'API Caching for Items', 'woozoho-connector' ),
				'type'    => 'select',
				'options' => array(
					'disabled' => __( 'Disabled', 'woozoho-connector' ),
					'1 day'    => __( '1 day', 'woozoho-connector' ),
					'2 days'   => __( '2 days', 'woozoho-connector' ),
					'1 week'   => __( '1 week', 'woozoho-connector' ),
					'2 weeks'  => __( '2 weeks', 'woozoho-connector' )
				),
				'default' => '1 day',
				'desc'    => __( 'How long is caching valid?', 'woozoho-connector' ),
				'id'      => 'wc_zoho_connector_api_cache_items'
			),

			array(
				'type' => 'sectionend',
				'id'   => 'wc_zoho_connector_section_advanced_settings'
			)
		);

		return apply_filters( 'wc_zoho_connector_settings', $settings );
	}
	//END
}
