<?php
/**
 * Plugin Name: Hub Order Sync
 * Description: Sends updates to the Store site when order status or notes are changed.
 * Version: 1.2
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// ▶️ Update this with your actual Store domain and API key
//    Use HTTP if your local site is not SSL-enabled
define('STORE_SYNC_API_URL', 'http://newwp.local/wp-json/store-sync/v1/update-order');
define('STORE_SYNC_API_KEY', 'hd8F#9d@2mKz$G7P'); // Must match the key in Store plugin

/**
 * Sends a POST to the Store site to update an order.
 */
function hub_send_order_update_to_store($order_id, $status, $note = '') {
    $response = wp_remote_post(STORE_SYNC_API_URL, [
        'method'    => 'POST',
        'headers'   => [
            'Content-Type' => 'application/json',
            'x-api-key'    => STORE_SYNC_API_KEY,
        ],
        'body'      => wp_json_encode([
            'order_id' => $order_id,
            'status'   => $status,
            'note'     => $note,
        ]),
        'sslverify' => false, // For local HTTPS with self-signed certs
    ]);

    if (is_wp_error($response)) {
        error_log('Store Sync Failed: ' . $response->get_error_message());
        return ['error' => $response->get_error_message()];
    }

    return json_decode(wp_remote_retrieve_body($response), true);
}

/**
 * Manual Sync Test via Browser
 * Usage: https://your-hub-site.local/wp-admin/?sync_test=1&order_id=167
 */
add_action('admin_init', function () {
    if (isset($_GET['sync_test'])) {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if (! $order_id) {
            wp_die('❌ Please provide a valid order_id: <code>?sync_test=1&order_id=167</code>');
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'completed';
        $note   = isset($_GET['note'])   ? sanitize_text_field($_GET['note'])   : 'Test note from Hub';

        $response = hub_send_order_update_to_store($order_id, $status, $note);
        wp_die('<h2>Hub → Store Sync Response</h2><pre>' . print_r($response, true) . '</pre>');
    }
});
