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
    $payload = [
        'order_id' => $order_id,
        'status'   => $status,
        'note'     => $note,
    ];

    error_log("Hub Sync: Sending update to Store → " . STORE_SYNC_API_URL);
    error_log("Hub Sync: Payload → " . json_encode($payload));

    $response = wp_remote_post(STORE_SYNC_API_URL, [
        'method'    => 'POST',
        'headers'   => [
            'Content-Type' => 'application/json',
            'x-api-key'    => STORE_SYNC_API_KEY,
        ],
        'body'      => wp_json_encode($payload),
        'sslverify' => false,
    ]);

    if (is_wp_error($response)) {
        error_log('Store Sync Failed: ' . $response->get_error_message());
        return ['error' => $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    error_log("Hub Sync: Store Response → " . $body);

    return json_decode($body, true);
}


/**
 * Manual Sync Test via Browser
 * Usage: https://your-hub-site.local/wp-admin/?sync_test=1&order_id=167
 */
add_action('admin_init', function () {
    if (!isset($_GET['sync_test'])) {
        return;
    }

    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $note     = isset($_GET['note']) ? sanitize_text_field($_GET['note']) : '';

    if (!$order_id || empty($status)) {
        wp_die('<h3>❌ Missing required parameters.</h3><p>Usage: ?sync_test=1&order_id=123&status=completed&note=Optional+note</p>');
    }

    echo '<h2>🔄 Sending Sync Request from Hub → Store</h2>';
    echo '<p><strong>Order ID:</strong> ' . esc_html($order_id) . '</p>';
    echo '<p><strong>Status:</strong> ' . esc_html($status) . '</p>';
    echo '<p><strong>Note:</strong> ' . esc_html($note) . '</p>';

    $response = hub_send_order_update_to_store($order_id, $status, $note);

    echo '<h3>📝 Response from Store:</h3>';
    echo '<pre>' . print_r($response, true) . '</pre>';
    exit;
});
