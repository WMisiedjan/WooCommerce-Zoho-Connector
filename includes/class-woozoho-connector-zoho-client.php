<?php

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Woozoho_Connector
 * @subpackage Woozoho_Connector/includes
 * @author     Wendell Misiedjan <me@digispark.nl>
 */
class Woozoho_Connector_Zoho_Client {

	protected $organizationId;
	protected $accessToken;
	protected $ordersQueue;
	public $zohoAPI;
	protected $logLocation = "./";
	protected $apiItemsCachingLocation = "./cache/items.json";
	protected $apiCachingItemsTimeout;

	public function __construct() {
		$args                         = array();
		$args["accessToken"]          = WC_Admin_Settings::get_option( "wc_zoho_connector_token" );
		$args["organizationId"]       = WC_Admin_Settings::get_option( "wc_zoho_connector_organisation_id" );
		$this->apiCachingItemsTimeout = WC_Admin_Settings::get_option( "wc_zoho_connector_api_cache_items" );
		//TODO: Implement caching options.

		$this->logLocation             = realpath( __DIR__ . DIRECTORY_SEPARATOR . '..' );
		$this->apiItemsCachingLocation = $this->logLocation . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'items.json';

		$this->zohoAPI     = new Woozoho_Connector_Zoho_API( $args );
		$this->ordersQueue = new Woozoho_Connector_Orders_Queue( $this );
	}

	public function getOrdersQueue() {
		return $this->ordersQueue;
	}

	public function getContact( $company, $email ) {
		//TODO: Implement caching...
		$args                 = array();
		$args["contact_name"] = $company;
		$data                 = $this->zohoAPI->listContacts( $args ); //Find contact by company name.
		if ( $data->contacts ) {
			$contact_id = $data->contacts[0]->contact_id;

			return $this->zohoAPI->retrieveContact( $contact_id )->contact;
		} else {
			$args          = array();
			$args["email"] = $email;
			$data          = $this->zohoAPI->listContacts( $args ); //Find contact by email
			if ( $data->contacts ) {
				$contact_id = $data->contacts[0]->contact_id;

				return $this->zohoAPI->retrieveContact( $contact_id )->contact;
			} else {
				return null;
			}
		}
	}

	public function getSalesOrders( $user_id ) {
		$args                = array();
		$args["customer_id"] = $user_id;
		$data                = $this->zohoAPI->listSalesOrders( $args );
		if ( $data->salesorders ) {
			return $data->salesorders;
		} else {
			return null;
		}
	}


	/** Get Item By SKU live from API or using the build-in caching.
	 *
	 * @param string $sku SKU code from product.
	 * @param bool $useCaching Use build-in caching system.
	 *
	 * @return object|null
	 */
	public function getItem( $sku, $useCaching = true ) {
		$isCachingEnabled = ( $this->apiCachingItemsTimeout != "disabled" ) ? true : false;
		if ( $useCaching && $this->isItemsCached() && $isCachingEnabled ) { //Check if caching is enabled & valid
			$this->writeDebug( "Caching", "Looking for SKU '$sku' in item cache." );
			$cacheData = file_get_contents( $this->apiItemsCachingLocation );
			$cache     = json_decode( $cacheData );
			if ( $cache != null ) {
				$dataColumn = array_column( $cache, 'sku' );
				$key        = array_search( $sku, $dataColumn );
				if ( $key !== false ) {
					$this->writeDebug( "Caching", "Found SKU '$sku' in item cache at pos " . $key );

					return $cache[ $key ];
				}
			} else {
				$this->writeDebug( "Caching", "Item cache is invalid, continue using live API..." );
			}
		} else { //Caching not enabled
			if ( ! $this->isItemsCached() && $isCachingEnabled ) { //Caching not filled or not valid anymore
				$this->scheduleCaching();
			}
			$this->writeDebug( "Caching", "Item cache is not valid or disabled for this call, continue using live API..." );
		}

		$args        = array();
		$args["sku"] = $sku;
		$data        = $this->zohoAPI->listItems( $args );
		if ( $data->items ) {
			return $data->items[0];
		} else {
			return null;
		}
	}

	public function scheduleCaching() {
		wp_schedule_single_event( time() + 5, 'woozoho_caching' );
	}

	public function isItemsCached() {
		$this->writeDebug( "API Cache", "Checking if cache is valid." );
		if ( file_exists( $this->apiItemsCachingLocation ) ) {
			$fileTime   = filectime( $this->apiItemsCachingLocation );
			$nowTime    = time();
			$expireTime = strtotime( "+ " . $this->apiCachingItemsTimeout, $fileTime );

			//$this->writeDebug("API Cache","File time: $fileTime, Expire Time: $expireTime, Now Time: $nowTime, Expire Time should be bigger than now time for cache to be valid.");
			if ( $expireTime >= $nowTime ) {
				$this->writeDebug( "API Cache", "Cache is still valid." );
				return true;
			} else {
				$this->writeDebug( "API Cache", "Cache is outdated, removing..." );
				unlink( $this->apiItemsCachingLocation ); //Removing expired cache.
				return false;
			}
		} else {
			$this->writeDebug( "API Cache", "No cache file is available." );
			return false;
		}
	}

	public function getAllItems() {
		$returnData = array();
		$nextPage   = 1;

		while ( $nextPage ) {
			$this->writeDebug( "API Cache", "Getting all items... current page: " . $nextPage );
			$args = array();
			if ( $nextPage > 1 ) {
				$args["page"] = $nextPage;
			}

			$resultData  = $this->zohoAPI->listItems( $args );
			//TODO: Catch API return errors, retry cerain times.
			$hasNextPage = $resultData->page_context->has_more_page;
			if ( count( $resultData->items ) >= 1 ) {
				foreach ( $resultData->items as $item ) {
					$returnData[] = $item;
				}
			}

			$this->writeDebug( "API Cache", "Items in memory: " . count( $returnData ) );

			if ( $hasNextPage ) {
				$nextPage ++;
			} else {
				$nextPage = false;
			}
		}

		return $returnData;
	}

	public function cacheItems() {
		$this->writeDebug( "API Cache", "Listing all cached items..." );
		$cache_file   = $this->apiItemsCachingLocation;
		$cache_folder = dirname( $cache_file );

		if ( ! is_dir( $cache_folder ) ) {
			mkdir( $cache_folder );
		}

		//Get all items
		$itemsCache = $this->getAllItems();

		if ( ! empty( $itemsCache ) ) {
			if ( file_put_contents( $cache_file, json_encode( $itemsCache ) ) ) {
				$this->writeDebug( "API Cache", "Sucessfully wrote items to cache." );
			} else {
				$this->writeDebug( "API Cache", "Error something went wrong with writing to items cache, check file permissions!" );
			}
		} else {
			unlink( $cache_file );
		}
	}

	public function sendNotificationEmail( $subject, $message ) {
		//TODO: Add multisite support
		$mailTo = WC_Admin_Settings::get_option( "wc_zoho_connector_notify_email" );
		if ( $mailTo ) {
			$headers[] = 'From: WordPress Zoho Connector <wordpress@mydoo.nl>';
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			wp_mail( $mailTo, "WooCommerce Zoho Connector:" . $subject, $message, $headers );
			$this->writeDebug( "Notification Email", "Email with subject '" . $subject . " sent to " . $mailTo );
		}
	}

	/**
	 * @param $user_info
	 * @param WC_Order $order
	 *
	 * @return null
	 */
	public function createContact( $user_info, $order ) {
		$contactData = array(
			array(
				"contact_name"     => $order->get_billing_company(),
				"company_name"     => $order->get_billing_company(),
				"website"          => $user_info->user_url,
				"email"            => $user_info->user_email,
				"notes"            => "Created by WooCommerce Zoho Connector.",
				"billing_address"  =>
					array(
						"attention" => $order->get_billing_company(),
						"address"   => $order->get_billing_address_1(),
						"street2"   => $order->get_billing_address_2(),
						"city"      => $order->get_billing_city(),
						"state"     => $order->get_billing_state(),
						"zip"       => $order->get_billing_postcode(),
						"country"   => $order->get_billing_country(),
						"phone"     => $order->get_billing_phone()
					),
				"shipping_address" =>
					array(
						"attention" => $order->get_shipping_company(),
						"address"   => $order->get_shipping_address_1(),
						"street2"   => $order->get_shipping_address_2(),
						"city"      => $order->get_shipping_city(),
						"state"     => $order->get_shipping_state(),
						"zip"       => $order->get_shipping_postcode(),
						"country"   => $order->get_shipping_country(),
						"phone"     => $order->get_billing_phone()
					),
				"contact_persons"  => array(
					array(
						"first_name" => $order->get_billing_first_name(),
						"last_name"  => $order->get_billing_last_name(),
						"email"      => $user_info->user_email,
						"phone"      => $order->get_billing_phone()
					)
				)
			)
		);

		$resultData = $this->zohoAPI->createContact( $contactData );

		if ( $resultData->contacts[0] ) {
			return $resultData->contacts[0];
		} else {
			return null;
		}
	}

	public function processQueue() {
		$ordersData = $this->ordersQueue->getQueue();
		foreach ( $ordersData as $order_id ) {
			$this->pushOrder( $order_id );

		}
	}

	public function itemConvert( $zohoItem, $storeItem, $quantity = 0 ) {
		$convertedItem = array(
			"item_id"     => $zohoItem->item_id,
			"rate"        => $zohoItem->rate,
			"name"        => $zohoItem->name,
			"description" => $zohoItem->description,
			"tax_id"      => $zohoItem->tax_id,
			"unit"        => $zohoItem->unit,
		);

		if ( $storeItem == false && $quantity != 0 ) {
			$convertedItem["quantity"] = $quantity;
		} else {
			$convertedItem["quantity"] = $storeItem->get_quantity();
		}

		return $convertedItem;
	}

	public function itemToNotes( $item ) {
		$returnString = "";
		$returnString .= $item['name'] . " ";
		if ( $item->get_product() && $item->get_product()->get_sku() ) {
			$returnString .= "(" . $item->get_product()->get_sku() . ") ";
		}
		$returnString .= "| Quantity: " . $item->get_quantity();
		$returnString .= " | Total Price: " . $item->get_total();
		$returnString .= "\n";

		return $returnString;
	}

	/**
	 * @param $order_id
	 *
	 * @return bool
	 */
	public function pushOrder( $order_id ) {
		$isQueued = false;
		try {
			$order         = new WC_Order( $order_id );
			$order_user_id = $order->user_id;
			$user_info     = get_userdata( $order_user_id );
			$items         = $order->get_items();
			$salesOrder    = array();

			$this->writeDebug( "Push Order", "Syncing Zoho Order ID " . $order_id . " from (" . $user_info->user_email . "): " );

			$contact = $this->getContact( $order->get_billing_company(), $user_info->user_email );

			if ( ! $contact ) {
				$this->writeDebug( "Push Order", "Contact " . $order->get_billing_company() . " (" . $user_info->user_email . ") for Order " . $order_id . " doesn't exist in Zoho. Creating contact..." );
				$contact = $this->createContact( $user_info, $order );
				if ( ! $contact ) {
					$this->writeDebug( "Push Order", "Order " . $order_id . ": Can't create contact (" . $user_info->user_email . ") in Zoho. Updating order and continue." );
					$this->ordersQueue->updateOrder( $order_id, "error", "Couldn't create contact in Zoho.", true );
					$isQueued = true;

					return false;
				} else {
					$this->writeDebug( "Push Order", "Order " . $order_id . ": Contact created for " . $order->get_billing_company() . " (" . $user_info->user_email . ") in Zoho." );
				}
			} else {
				$this->writeDebug( "Push Order", "Successfully found contact for " . $order->get_billing_company() . " (" . $user_info->user_email . ")." );
			}

			$this->writeDebug( "Push Order", "Generating output to Zoho..." );

			$salesOrder["customer_id"]   = $contact->contact_id;
			$salesOrder["customer_name"] = $contact->company_name;

			if ( WC_Admin_Settings::get_option( "wc_zoho_connector_testmode" ) == "yes" ) {
				$this->writeDebug( "Push Order", "TEST MODE IS ENABLED, USING TEST ORDER ID's." );
				$salesOrder["salesorder_number"] = "TEST-" . $order_id;
			} else {
				$this->writeDebug( "Push Order", "LIVE MODE ENABLED." );
			}


			$salesOrder["date"] = date( 'Y-m-d' );

			if ( is_multisite() ) { //Support for multi-site, adding site id after reference number.
				$salesOrder["reference_number"] = "WP-" . $order_id . "-" . get_current_blog_id();
			} else {
				$salesOrder["reference_number"] = "WP-" . $order_id;
			}

			$salesOrder["line_items"] = array();
			$salesOrder["status"]     = "draft";

			$missingProducts  = "";
			$inactiveProducts = "";

			//Loop through each item.
			foreach ( $items as $item ) {
				if ( $item->get_product() ) {
					if ( $item->get_product()->get_sku() ) {
						$this->writeDebug( "Push Order", "Looking for product in zoho with SKU: " . $item->get_product()->get_sku() );
						$zohoItem = $this->getItem( $item->get_product()->get_sku() );
						if ( ! $zohoItem ) {
							$missingProducts .= $this->itemToNotes( $item );
							$this->writeDebug( "Push Order", "Product (" . $item->get_product()->get_sku() . ") not found. Adding to notes." );
							if ( WC_Admin_Settings::get_option( "wc_zoho_connector_notify_email_option" )["sku_zoho"] ) {
								$this->sendNotificationEmail( "SKU '" . $item->get_product()->get_sku() . "' not found in Zoho.", "SKU '" . $item->get_product()->get_sku() . "' not found in Zoho." );
							}
						} else {
							if ( $zohoItem->status == "active" ) {
								$line_item = $this->itemConvert( $zohoItem, $item );
								array_push( $salesOrder["line_items"], $line_item );
								$this->writeDebug( "Push Order", "Product " . $item->get_product()->get_sku() . " successfully found." );
							} else {
								$this->writeDebug( "Push Order", "Product " . $item->get_product()->get_sku() . " is inactive, added to notes." );
								$inactiveProducts .= $this->itemToNotes( $item );
							}
						}
					} else {
						$missingProducts .= $this->itemToNotes( $item );
						$this->writeDebug( "Push Order", "Error: SKU not found of product ID: " . $item['product_id'] . ". Product is added as a note." );
						if ( WC_Admin_Settings::get_option( "wc_zoho_connector_notify_email_option" )["sku_woocommerce"] ) {
							$this->sendNotificationEmail( "SKU of Product  '" . $item['name'] . "' not found in WooCommerce.", "SKU of Product  '" . $item['name'] . "' (" . $item['product_id'] . ") not found in WooCommerce." );
						}
					}
				} else {
					$missingProducts .= $this->itemToNotes( $item );
					$this->writeDebug( "Push Order", "Error: Product (" . $item['name'] . ") not found in WooCommerce, so we can't find SKU. Product is added as a note." );
				}
			}

			if ( empty( $salesOrder["line_items"] ) ) {
				$placeHolder = $this->itemConvert( $this->getItem( "PLACEHOLDER" ), false, 1 );
				array_push( $salesOrder["line_items"], $placeHolder ); //TODO: Find long term solution for this.
				$this->writeDebug( "Push Order", "Order is empty or no SKU's can't be found for Order ID: " . $order_id );
			}

			//Handle Notes
			if ( ! empty( $missingProducts ) ) {
				$missingProducts = "Missing products:\n" . $missingProducts;
			}

			if ( ! empty( $inactiveProducts ) ) {
				$inactiveProducts = "Inactive products:\n" . $inactiveProducts;
			}

			$orderComment = $missingProducts . "\n" . $inactiveProducts . "\nAutomatically generated by WooCommerce Zoho Connector.";

			$salesOrderOutput = $this->zohoAPI->createSalesOrder( $salesOrder, ( WC_Admin_Settings::get_option( "wc_zoho_connector_testmode" ) == yes ) );

			if ( ! $salesOrderOutput->salesorder ) {
				$this->writeDebug( "Push Order", "Couldn't push $order_id to Zoho. Something went wrong with pushing the order data." );
				$this->ordersQueue->updateOrder( $order_id, "error", "Something went wrong with pushing the order data.", true );

				return false;
			}

			$this->ordersQueue->updateOrder( $order_id, "success", "Successfully pushed to Zoho.", true );

			$this->zohoAPI->createComment( $salesOrderOutput->salesorder->salesorder_id, $orderComment ); //Adding missing / inactive products.

			$this->writeDebug( "Push Order", "Successfully pushed $order_id to Zoho." );

			return true;

		} catch ( Exception $e ) {
			if ( ! $isQueued ) {
				$this->ordersQueue->updateOrder( $order_id, "error", $e->getMessage(), true );
			}
			$this->writeDebug( "Push Order", "ERROR Exception:" . $e->getMessage() );

			return false;
		}
	}

	public function writeDebug( $type, $data ) {
		if ( WC_Admin_Settings::get_option( "wc_zoho_connector_debugging" ) ) {
			$multisiteString = is_multisite() ? "[" . get_bloginfo( 'name' ) . "]" : ( "" ); //Multi-site support.
			$logfile         = realpath( __DIR__ . '/..' ) . '/debug_log';
			file_put_contents( $logfile,
				$multisiteString . "[" . date( "Y-m-d H:i:s" ) . "] [" . $type . "] " . $data . "\n", FILE_APPEND );
		}
	}

	public function queueOrder( $post_id, $timestamp ) {

		$this->ordersQueue->addOrder( $post_id );

		if ( $timestamp ) {
			wp_schedule_single_event( $timestamp, 'woozoho_push_order_queue', array( $post_id ) );
		} else {
			wp_schedule_single_event( time() + 5, 'woozoho_push_order_queue', array( $post_id ) );
		}
	}
}
