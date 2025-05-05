 <?php
    class Hub_Admin_UI
    {
        public function __construct()
        {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_shortcode('hub_order_table', [$this, 'render_order_table_shortcode']);
            add_action('admin_menu', [$this, 'add_settings_page']);
        }

        public function enqueue_scripts($hook) {
            if ('toplevel_page_hub-order-manager' === $hook) {
                // Enqueue React app
                $react_app_path = HUB_ORDER_PLUGIN_URL . 'assets/js/react-app/build/';
                
                // CSS
                wp_enqueue_style(
                    'hub-order-manager-react',
                    $react_app_path . 'static/css/main.css',
                    [],
                    HUB_ORDER_PLUGIN_VERSION
                );
                
                // JS
                wp_enqueue_script(
                    'hub-order-manager-react',
                    $react_app_path . 'static/js/main.js',
                    [],
                    HUB_ORDER_PLUGIN_VERSION,
                    true
                );
                
                // Localize data
                wp_localize_script('hub-order-manager-react', 'hubOrderManager', [
                    'api_nonce' => wp_create_nonce('wp_rest'),
                    'api_url' => rest_url('hub-order-sync/v1'),
                    'orders' => $this->get_orders_for_react()
                ]);
            }
        }
        public function render_order_table_shortcode($atts)
        {
            if (!current_user_can('manage_hub_orders')) {
                return '';
            }

            ob_start();
    ?>
         <div id="hub-order-table"></div>
     <?php
            return ob_get_clean();
        }

        public function add_settings_page()
        {
            add_menu_page(
                __('Order Manager', 'hub-order-manager'),
                __('Order Manager', 'hub-order-manager'),
                'manage_hub_orders',
                'hub-order-manager',
                [$this, 'render_admin_page'],
                'dashicons-clipboard',
                30
            );
        }

        public function render_admin_page()
        {
        ?>
         <div class="wrap">
             <h1><?php esc_html_e('React Order Manager', 'hub-order-manager'); ?></h1>
             <div id="hub-order-manager-root"></div>
         </div>
 <?php
        }

        private function get_orders_for_react()
        {
            $query = new WP_Query([
                'post_type' => 'hub_order',
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ]);

            $orders = [];

            foreach ($query->posts as $post) {
                $order_data = get_post_meta($post->ID, '_order_data', true);
                $status = get_post_meta($post->ID, '_order_status', true);
                $notes = get_post_meta($post->ID, '_order_notes', true) ?: [];

                $orders[] = [
                    'id' => $post->ID,
                    'store_order_id' => get_post_meta($post->ID, '_store_order_id', true),
                    'title' => $post->post_title,
                    'status' => $status,
                    'customer' => $order_data['customer'] ?? [],
                    'date_created' => $order_data['date_created'] ?? '',
                    'shipping_date' => $this->calculate_shipping_date($order_data['date_created'] ?? ''),
                    'line_items' => $order_data['line_items'] ?? [],
                    'notes' => $notes,
                    'payment_method' => $order_data['payment_method'] ?? '',
                    'shipping_method' => $order_data['shipping'] ?? ''
                ];
            }

            return $orders;
        }

        private function calculate_shipping_date($order_date)
        {
            if (empty($order_date)) return '';

            $date = new DateTime($order_date);
            $date->add(new DateInterval('P14D')); // Add 14 days
            return $date->format('Y-m-d H:i:s');
        }
    }
