 
<?php
/**
 * Plugin Name: Hub Order Manager
 * Description: Receives and manages orders from Store website
 * Version: 1.0.0
 * Author: NF Tushar
 * Text Domain: hub-order-manager
 */

defined('ABSPATH') || exit;

// Define constants
define('HUB_ORDER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('HUB_ORDER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HUB_ORDER_PLUGIN_VERSION', '1.0.0');

// Include required files
require_once HUB_ORDER_PLUGIN_PATH . 'includes/class-order-manager.php';
require_once HUB_ORDER_PLUGIN_PATH . 'includes/class-api-receiver.php';
require_once HUB_ORDER_PLUGIN_PATH . 'includes/class-admin-ui.php';

// Initialize plugin
function hub_order_plugin_init() {
    new Hub_Order_Manager();
    new Hub_API_Receiver();
    new Hub_Admin_UI();
}
add_action('plugins_loaded', 'hub_order_plugin_init');

// Activation/deactivation hooks
register_activation_hook(__FILE__, ['Hub_Order_Manager', 'activate']);
register_deactivation_hook(__FILE__, ['Hub_Order_Manager', 'deactivate']);