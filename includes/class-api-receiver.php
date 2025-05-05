<?php
class Hub_API_Receiver {
    private $api_key;
    private $secret_key;

    public function __construct() {
        $this->api_key = get_option('hub_order_sync_api_key');
        $this->secret_key = get_option('hub_order_sync_secret_key');
        
        add_action('rest_api_init', [$this, 'register_api_endpoints']);
    }

    public function register_api_endpoints() {
        // Endpoint to receive new orders
        register_rest_route('hub-order-sync/v1', '/orders', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_new_order'],
            'permission_callback' => [$this, 'verify_api_request']
        ]);
        
        // Endpoint to receive order updates from Store
        register_rest_route('hub-order-sync/v1', '/orders/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'handle_order_update'],
            'permission_callback' => [$this, 'verify_api_request'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ]
            ]
        ]);
        
        // Endpoint to add notes to orders
        register_rest_route('hub-order-sync/v1', '/orders/(?P<id>\d+)/notes', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_add_note'],
            'permission_callback' => [$this, 'verify_api_request'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'content' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                ],
                'is_customer_note' => [
                    'required' => false,
                    'default' => false,
                    'validate_callback' => function($param) {
                        return is_bool($param);
                    }
                ]
            ]
        ]);
    }

    public function verify_api_request($request) {
        $api_key = $request->get_header('X-API-KEY');
        $signature = $request->get_header('X-API-SIGNATURE');
        $timestamp = $request->get_header('X-API-TIMESTAMP');
        $nonce = $request->get_header('X-API-NONCE');
        
        // Basic validation
        if (empty($api_key) || empty($signature) || empty($timestamp) || empty($nonce)) {
            return false;
        }
        
        // Verify timestamp isn't too old (5 minutes)
        if (time() - $timestamp > 300) {
            return false;
        }
        
        // Verify API key matches
        if ($api_key !== $this->api_key) {
            return false;
        }
        
        // Verify signature
        $message = $timestamp . $nonce . $request->get_route() . $request->get_body();
        $expected_signature = hash_hmac('sha256', $message, $this->secret_key);
        
        return hash_equals($expected_signature, $signature);
    }

    public function handle_new_order($request) {
        $order_data = $request->get_json_params();
        
        if (empty($order_data['id'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing order ID'
            ], 400);
        }
        
        $order_manager = new Hub_Order_Manager();
        $post_id = $order_manager->create_order_from_store_data($order_data);
        
        if (!$post_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to create order'
            ], 500);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'order_id' => $post_id,
            'store_order_id' => $order_data['id']
        ]);
    }

    public function handle_order_update($request) {
        $store_order_id = $request['id'];
        $update_data = $request->get_json_params();
        
        $order_manager = new Hub_Order_Manager();
        $post_id = $order_manager->find_existing_order($store_order_id);
        
        if (!$post_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
        
        // Update status if provided
        if (isset($update_data['status'])) {
            update_post_meta($post_id, '_order_status', $update_data['status']);
        }
        
        // Add note if provided
        if (isset($update_data['note'])) {
            $notes = get_post_meta($post_id, '_order_notes', true) ?: [];
            $notes[] = $update_data['note'];
            update_post_meta($post_id, '_order_notes', $notes);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Order updated'
        ]);
    }

    public function handle_add_note($request) {
        $post_id = $request['id'];
        $note_content = sanitize_textarea_field($request['content']);
        $is_customer_note = (bool) $request['is_customer_note'];
        
        $note = [
            'content' => $note_content,
            'added_by' => wp_get_current_user()->display_name,
            'date' => current_time('mysql'),
            'is_customer_note' => $is_customer_note
        ];
        
        $notes = get_post_meta($post_id, '_order_notes', true) ?: [];
        $notes[] = $note;
        update_post_meta($post_id, '_order_notes', $notes);
        
        // Send note back to Store
        $this->send_update_to_store($post_id, [
            'note' => $note
        ]);
        
        return new WP_REST_Response([
            'success' => true,
            'note' => $note
        ]);
    }

    public function send_update_to_store($post_id, $update_data) {
        $store_url = get_option('hub_order_sync_store_url');
        $store_order_id = get_post_meta($post_id, '_store_order_id', true);
        
        if (empty($store_url) || empty($store_order_id)) {
            return false;
        }
        
        $endpoint = trailingslashit($store_url) . 'wp-json/store-order-sync/v1/update-order/' . $store_order_id;
        $timestamp = time();
        $nonce = wp_generate_password(32, false);
        $body = json_encode($update_data);
        
        // Generate signature
        $message = $timestamp . $nonce . $endpoint . $body;
        $signature = hash_hmac('sha256', $message, $this->secret_key);
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->api_key,
                'X-API-SIGNATURE' => $signature,
                'X-API-TIMESTAMP' => $timestamp,
                'X-API-NONCE' => $nonce
            ],
            'body' => $body,
            'timeout' => 15
        ];
        
        $response = wp_remote_request($endpoint, $args);
        
        if (is_wp_error($response)) {
            error_log('Failed to send update to Store: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            error_log('Store update failed with status: ' . $response_code);
            return false;
        }
        
        return true;
    }
}