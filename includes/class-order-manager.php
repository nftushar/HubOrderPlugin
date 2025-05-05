 
<?php
class Hub_Order_Manager {
    private static $post_type = 'hub_order';
    private $api_receiver;

    public function __construct() {
        $this->api_receiver = new Hub_API_Receiver();
        
        add_action('init', [$this, 'register_order_post_type']);
        add_action('add_meta_boxes', [$this, 'add_order_meta_boxes']);
        add_action('save_post_' . self::$post_type, [$this, 'save_order_meta'], 10, 3);
        add_action('rest_api_init', [$this, 'register_rest_fields']);
    }

    public static function activate() {
        // Flush rewrite rules for custom post type
        flush_rewrite_rules();
        
        // Add custom capabilities
        $roles = ['administrator', 'shop_manager', 'customer_support'];
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('manage_hub_orders');
                $role->add_cap('edit_hub_orders');
                $role->add_cap('edit_others_hub_orders');
                $role->add_cap('publish_hub_orders');
                $role->add_cap('read_private_hub_orders');
            }
        }
    }

    public static function deactivate() {
        // Remove custom capabilities
        $roles = ['administrator', 'shop_manager', 'customer_support'];
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->remove_cap('manage_hub_orders');
                $role->remove_cap('edit_hub_orders');
                $role->remove_cap('edit_others_hub_orders');
                $role->remove_cap('publish_hub_orders');
                $role->remove_cap('read_private_hub_orders');
            }
        }
    }

    public function register_order_post_type() {
        $labels = [
            'name' => __('Orders', 'hub-order-manager'),
            'singular_name' => __('Order', 'hub-order-manager'),
            'menu_name' => __('Orders', 'hub-order-manager'),
            'all_items' => __('All Orders', 'hub-order-manager'),
            'add_new' => __('Add New', 'hub-order-manager'),
            'add_new_item' => __('Add New Order', 'hub-order-manager'),
            'edit_item' => __('Edit Order', 'hub-order-manager'),
            'new_item' => __('New Order', 'hub-order-manager'),
            'view_item' => __('View Order', 'hub-order-manager'),
            'search_items' => __('Search Orders', 'hub-order-manager'),
            'not_found' => __('No orders found', 'hub-order-manager'),
            'not_found_in_trash' => __('No orders found in Trash', 'hub-order-manager')
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => false,
            'capability_type' => 'hub_order',
            'capabilities' => [
                'edit_post' => 'edit_hub_order',
                'read_post' => 'read_hub_order',
                'delete_post' => 'delete_hub_order',
                'edit_posts' => 'edit_hub_orders',
                'edit_others_posts' => 'edit_others_hub_orders',
                'publish_posts' => 'publish_hub_orders',
                'read_private_posts' => 'read_private_hub_orders',
            ],
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 30,
            'menu_icon' => 'dashicons-clipboard',
            'supports' => ['title', 'custom-fields'],
            'show_in_rest' => true,
            'rest_base' => 'hub-orders'
        ];

        register_post_type(self::$post_type, $args);
    }

    public function add_order_meta_boxes() {
        add_meta_box(
            'hub_order_details',
            __('Order Details', 'hub-order-manager'),
            [$this, 'render_order_details_meta_box'],
            self::$post_type,
            'normal',
            'high'
        );

        add_meta_box(
            'hub_order_notes',
            __('Order Notes', 'hub-order-manager'),
            [$this, 'render_order_notes_meta_box'],
            self::$post_type,
            'normal',
            'high'
        );
    }

    public function render_order_details_meta_box($post) {
        $order_data = get_post_meta($post->ID, '_order_data', true);
        $customer = $order_data['customer'] ?? [];
        $shipping_date = $this->calculate_shipping_date($order_data['date_created'] ?? '');
        
        include HUB_ORDER_PLUGIN_PATH . 'templates/order-details-meta-box.php';
    }

    public function render_order_notes_meta_box($post) {
        $notes = get_post_meta($post->ID, '_order_notes', true) ?: [];
        include HUB_ORDER_PLUGIN_PATH . 'templates/order-notes-meta-box.php';
    }

    public function save_order_meta($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_hub_order', $post_id)) return;
        
        if (isset($_POST['order_status'])) {
            $new_status = sanitize_text_field($_POST['order_status']);
            $old_status = get_post_meta($post_id, '_order_status', true);
            
            update_post_meta($post_id, '_order_status', $new_status);
            
            // If status changed, send update to Store
            if ($new_status !== $old_status) {
                $this->api_receiver->send_update_to_store($post_id, [
                    'status' => $new_status
                ]);
            }
        }
        
        if (!empty($_POST['new_order_note'])) {
            $note = [
                'content' => sanitize_textarea_field($_POST['new_order_note']),
                'added_by' => wp_get_current_user()->display_name,
                'date' => current_time('mysql'),
                'is_customer_note' => isset($_POST['is_customer_note'])
            ];
            
            $notes = get_post_meta($post_id, '_order_notes', true) ?: [];
            $notes[] = $note;
            update_post_meta($post_id, '_order_notes', $notes);
            
            // Send note to Store
            $this->api_receiver->send_update_to_store($post_id, [
                'note' => $note
            ]);
        }
    }

    public function register_rest_fields() {
        register_rest_field(self::$post_type, 'order_data', [
            'get_callback' => function($post) {
                return get_post_meta($post['id'], '_order_data', true);
            },
            'update_callback' => null,
            'schema' => null
        ]);
        
        register_rest_field(self::$post_type, 'order_status', [
            'get_callback' => function($post) {
                return get_post_meta($post['id'], '_order_status', true);
            },
            'update_callback' => function($value, $post) {
                update_post_meta($post->ID, '_order_status', sanitize_text_field($value));
            },
            'schema' => [
                'type' => 'string',
                'context' => ['view', 'edit']
            ]
        ]);
    }

    public function create_order_from_store_data($order_data) {
        $existing_id = $this->find_existing_order($order_data['id']);
        
        if ($existing_id) {
            return $this->update_existing_order($existing_id, $order_data);
        }
        
        $post_id = wp_insert_post([
            'post_title' => sprintf(__('Order #%s', 'hub-order-manager'), $order_data['id']),
            'post_type' => self::$post_type,
            'post_status' => 'publish',
            'meta_input' => [
                '_order_data' => $order_data,
                '_order_status' => $order_data['status'],
                '_order_notes' => $order_data['notes'] ?? [],
                '_store_order_id' => $order_data['id']
            ]
        ]);
        
        if (is_wp_error($post_id)) {
            error_log('Failed to create order: ' . $post_id->get_error_message());
            return false;
        }
        
        return $post_id;
    }

    private function find_existing_order($store_order_id) {
        $query = new WP_Query([
            'post_type' => self::$post_type,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_store_order_id',
                    'value' => $store_order_id
                ]
            ]
        ]);
        
        return $query->posts[0] ?? false;
    }

    private function update_existing_order($post_id, $order_data) {
        update_post_meta($post_id, '_order_data', $order_data);
        
        if (isset($order_data['status'])) {
            update_post_meta($post_id, '_order_status', $order_data['status']);
        }
        
        if (isset($order_data['notes'])) {
            $existing_notes = get_post_meta($post_id, '_order_notes', true) ?: [];
            $new_notes = array_merge($existing_notes, $order_data['notes']);
            update_post_meta($post_id, '_order_notes', $new_notes);
        }
        
        return $post_id;
    }

    private function calculate_shipping_date($order_date) {
        if (empty($order_date)) return '';
        
        $date = new DateTime($order_date);
        $date->add(new DateInterval('P14D')); // Add 14 days
        return $date->format('Y-m-d H:i:s');
    }
}