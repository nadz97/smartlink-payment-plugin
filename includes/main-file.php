<?php

if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

class smartlink_payment_gateway extends WC_Payment_Gateway
{
    /**
     * Auth key
     * 
     * @var string
     */
    public $auth_key;

    /**
     * Test mode
     * 
     */
    public $test_mode;

    /**
     * settings
     * 
     */
    public $settings;

    /**
     * Logger
     * 
     */
    private $logger;

    /**
     * Constructor
     * 
     * @since 1.0.0
     */
    public function __construct()
    {
        // check if logger exists
        if (class_exists('WC_Logger')) {
            $this->logger = new WC_Logger();
        }
        //id
        $this->id = 'smartlink_payment';
        //has fields
        $this->has_fields = true;
        //method title
        $this->method_title = __('Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN);
        //description
        $this->method_description = __('This plugin allows you to accept payment on your website
        using Smartlink Payment Gateway.', SMARTLINK_PAYMENT_TEXT_DOMAIN);
        // supports
        $this->supports = array('products');
        // add form fields
        $this->init_form_fields();

        $this->settings = get_option('woocommerce_smartlink_payment_settings');

        // process admin options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // callback 
        add_action('woocommerce_api_smartlink', array($this, 'payment_callback'));

        add_action('woocommerce_checkout_order_processed', [$this, 'smartlink_delayed_payment_processing'], 20, 3);
    }

    function smartlink_delayed_payment_processing($order_id, $posted_data, $order)
    {
        $payment_gateway = new Smartlink_Payment_Gateway();
        $payment_gateway->process_payment($order_id);
    }

    // Callback function for handling the payment-callback route
    public function payment_callback()
    {
        $this->logger->log('info', 'Payment callback initiated.');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data) {
            $this->logger->log('error', 'Invalid JSON payload: ' . $json);
            wp_send_json(['success' => false, 'message' => 'Invalid JSON payload.'], 400);
            exit;
        }

        if (isset($data['data']['order_id'])) {
            $this->logger->log('info', 'Processing order with data: ' . json_encode($data));

            $is_test_mode = $this->settings['test_mode'] === 'yes';
            $email_credentials = $is_test_mode
                ? $this->get_option('sbx_email_credential')
                : $this->get_option('live_email_credential');

            $order_id = $data['data']['order_id'] ?? null;
            $signature = $data['data']['signature'] ?? null;
            $channel = $data['data']['channel'] ?? null;
            $amount = $data['data']['amount'] ?? null;
            $transaction_time = $data['data']['transaction_time'] ?? null;
            $status = $data['data']['status'] ?? null;

            $this->logger->log('info', "Order ID: $order_id, Status: $status");
            $this->logger->log('info', "Signature inputs - Order ID: $order_id, Amount: $amount, Channel: $channel, Transaction Time: $transaction_time, Credentials: $email_credentials");

            $expectedSignature = hash('sha256', $order_id . $amount . $channel . $transaction_time . $email_credentials);

            $this->logger->log('info', "Expected Signature: $expectedSignature");
            $this->logger->log('info', "Received Signature: $signature");

            if ($signature !== $expectedSignature) {
                $this->logger->log('error', 'Invalid signature. Expected: ' . $expectedSignature . ', Received: ' . $signature);
                wp_send_json(['error' => 'Invalid signature.'], 401);
                exit;
            }

            $order = wc_get_order($order_id); // Use wc_get_order to initialize the order

            if (!$order) {
                $this->logger->log('error', "Order not found for Order ID: $order_id");
                return; // Exit if the order does not exist
            }

            $orderStatus = $order->get_status();

            if (strtoupper($status) == 'SUCCESS' && $orderStatus != 'completed') {
                $this->logger->log('info', "Updating order status to completed for Order ID: $order_id");

                $order->update_status('completed');

                // Prepare items data
                $order_items = $order->get_items();
                $items_data = [];

                foreach ($order_items as $item_id => $item) {
                    $items_data[] = [
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => (int) $item->get_total(),
                    ];
                }

                // Prepare webhook data
                $webhook_data = [
                    'order_id' => $order_id,
                    'status' => 'completed',
                    'amount' => $amount,
                    'payment_type' => ($amount <= 500000) ? 'tabungan' : 'full',
                    'channel' => $channel,
                    'transaction_time' => $transaction_time,
                    'customer_email' => $order->get_billing_email(),
                    'customer_phone' => $order->get_billing_phone(),
                    'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'items' => $items_data,
                ];

                if (!empty($this->settings['smartlink_webhook_url'])) {
                    $webhook_url = rtrim($this->settings['smartlink_webhook_url'], '/') . '/api/webhook/processWebhook';

                    $this->logger->log('info', 'Sending webhook to: ' . $webhook_url . ' with data: ' . json_encode($webhook_data));

                    $webhook_response = wp_remote_post($webhook_url, [
                        'method' => 'POST',
                        'timeout' => 45,
                        'body' => json_encode($webhook_data),
                        'headers' => [
                            'Content-Type' => 'application/json',
                        ],
                    ]);

                    if (is_wp_error($webhook_response)) {
                        $this->logger->log('error', 'Failed to send webhook: ' . $webhook_response->get_error_message());
                    } else {
                        $response_code = wp_remote_retrieve_response_code($webhook_response);
                        $response_body = wp_remote_retrieve_body($webhook_response);

                        $this->logger->log('info', "Webhook sent successfully. Response Code: $response_code");
                    }
                }


                wp_send_json([
                    'success' => true,
                    'message' => 'Payment completed successfully for order ID ' . $order_id,
                ]);
            } else {
                $this->logger->log('info', 'Order already completed or status not successful. Order ID: ' . $order_id);
                wp_send_json(['success' => false, 'message' => 'Order already completed or status not successful.']);
            }
        }
    }

    public function init_form_fields()
    {
        $form_fields = apply_filters('woo_smartlink_payment', [
            'enabled' => [
                'title' => __('Enable/Disable', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => 'no'
            ],
            'test_mode' => [
                'title' => __('Test Mode', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'select',
                'label' => __('Select test mode', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'options' => [
                    'yes' => __('Yes', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'no' => __('No', SMARTLINK_PAYMENT_TEXT_DOMAIN)
                ],
            ],
            'title' => [
                'title' => __('Title', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('This controls the description which the user sees during checkout', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => __('Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'description' => [
                'title' => __('Description', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => __('Pay with your bank choice', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'special_dp' => [
                'title' => __('DP Non Tabungan haji', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('This controls the Downpayment for Non tabungan haji payment', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => __('1500000', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'dp_non_tabungan' => [
                'title' => __('DP Non Tabungan', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('This controls the Downpayment for Non tabungan payment', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => __('10000000', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'dp_tabungan' => [
                'title' => __('DP Tabungan', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('This controls the Downpayment for tabungan payment', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => __('500000', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'live_email_credential' => [
                'title' => __('Live Email Credential', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'email',
                'description' => __('This is the email provided by Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '',
            ],
            'live_password' => [
                'title' => __('Live Password', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'password',
                'description' => __('This is the password provided by Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '',
            ],
            'sbx_email_credential' => [
                'title' => __('Sandbox Email Credential', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'email',
                'description' => __('This is the email provided by Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '',
            ],
            'sbx_password' => [
                'title' => __('Sandbox Password', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'password',
                'description' => __('This is the password provided by Smartlink Payment Gateway', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '',
            ],
            'email' => [
                'title' => __('Email confirmation', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'email',
                'description' => __('This is the email for sending success confirmation', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '',
            ],
            'smartlink_webhook_url' => [ // New configurable webhook URL field
                'title' => __('Webhook URL', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('URL to send webhook notifications', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => 'https://testhook.free.beeceptor.com',
            ],
            'cc_mode' => [
                'title' => __('Payment Mode', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'select',
                'label' => __('Select Payment Mode', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'options' => [
                    'NORMAL' => __('Normal (Non CC)', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'CC' => __('CC', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'CC_RECURRING' => __('CC Recurring', SMARTLINK_PAYMENT_TEXT_DOMAIN)
                ],
                'default' => 'NORMAL'
            ],
            'interval' => [
                'title' => __('Periode Tagihan (Interval)', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('Interval for recurring payment', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '2',
            ],
            'interval_unit' => [
                'title' => __('Periode Tagihan (Unit)', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'select',
                'options' => [
                    'DAY' => 'DAY',
                    'WEEK' => 'WEEK',
                    'MONTH' => 'MONTH',
                    'YEAR' => 'YEAR',
                ],
                'default' => 'DAY',
            ],
            'recurring_expiry_hours' => [
                'title' => __('Recurring Expiry (Hours)', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('How many hours after the order time the recurring payment should expire', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '1',
                'desc_tip' => true,
            ],
            'total_recurring' => [
                'title' => __('Jumlah Tagihan', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'type' => 'number',
                'description' => __('Total recurrence for recurring payment', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                'default' => '3',
            ],
        ]);

        $this->form_fields = array_merge($form_fields);
    }

    public function payment_fields()
    {
        echo '<p>' . __('Choose your payment method:', 'woocommerce') . '</p>';
        echo '<div class="smartlink-payment-options">';

        // Full Payment Option
        echo '<label style="display: block; margin-bottom: 10px; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">';
        echo '<input type="radio" name="smartlink_payment_option" value="full" checked style="margin-right: 10px;" />';
        echo esc_html__('Non tabungan (Down Payment: 10,000,000 IDR)', 'woocommerce');
        echo '</label>';

        // Tabungan Option
        echo '<label style="display: block; margin-bottom: 10px; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">';
        echo '<input type="radio" name="smartlink_payment_option" value="tabungan" style="margin-right: 10px;" />';
        echo esc_html__('Tabungan (Down Payment: 500,000 IDR)', 'woocommerce');
        echo '</label>';

        echo '</div>';
    }

    /**
     * Check if a product belongs to a specific subcategory under certain parent categories
     */
    function is_in_subcategory_of($product_id, $parent_slugs, $sub_slug)
    {
        $product_categories = wp_get_post_terms($product_id, 'product_cat');

        foreach ($product_categories as $category) {
            $parent = get_term($category->parent, 'product_cat');

            // Check if parent is in the specified list and child is the target subcategory
            if ($parent && in_array($parent->slug, $parent_slugs) && $category->slug === $sub_slug) {
                return true;
            }
        }
        return false;
    }


    public function process_payment($order_id)
    {
        if (empty($order_id) || !is_numeric($order_id)) {
            $this->logger->log('error', 'Invalid order ID received: ' . json_encode($order_id), ['source' => 'smartlink-payment']);
            return;
        }

        if ($this->logger) {
            $this->logger->log('info', 'Processing payment...', ['source' => 'smartlink-payment']);
        }

        // Get the order
        $order = wc_get_order($order_id);
        if (!$order || !is_object($order)) {
            $this->logger->log('error', 'Invalid order ID: ' . $order_id, ['source' => 'smartlink-payment']);
            wc_add_notice(__('Order not found. Please try again.', 'woothemes'), 'error');
            return;
        }

        // Get selected payment option
        $payment_option = isset($_POST['smartlink_payment_option']) ? sanitize_text_field($_POST['smartlink_payment_option']) : '';

        // Define category that should always have a DP of 1,500,000
        $special_category = ['ebad-haji', 'diva-haji'];
        // $special_down_payment = $this->settings['special_dp'];
        $special_down_payment = $this->get_option('special_dp');

        // Default down payments based on user selection
        // $default_down_payment = ($payment_option === 'full') ? $this->settings['dp_non_tabungan'] : $this->settings['dp_tabungan'];
        $default_down_payment = ($payment_option === 'full') ? $this->get_option('dp_non_tabungan') : $this->get_option('dp_tabungan');

        $total_down_payment = 0;
        $api_data = [
            'order_id' => $order->get_id(),
            'amount' => 0,
            'description' => 'Payment for Order #' . $order->get_id(),
            'customer' => [
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
            'item' => [],
            'type' => 'payment-page',
            'callback_url' => get_site_url() . '/wc-api/smartlink/',
            'success_redirect_url' => add_query_arg('order_id', $order_id, $this->get_return_url($order)),
            'failed_redirect_url' => add_query_arg('order_id', $order_id, get_site_url() . '/failed-payment'),
        ];

        $cc_mode = $this->get_option('cc_mode');
        if ($cc_mode === 'CC_RECURRING') {
            $api_data['payment_mode'] = 'RECURRING';
            $api_data['channel'] = ['CC_VISA'];

            $timezone = wp_timezone();
            $now = new DateTimeImmutable('now', $timezone);
            
            $expiry_hours = max(1, (int) $this->get_option('recurring_expiry_hours'));
            $expiry_time = $now->modify('+' . $expiry_hours . ' hours')->format('Y-m-d\TH:i:sP');

            $interval = (int) $this->get_option('interval');
            $interval_unit = strtolower($this->get_option('interval_unit')); // e.g., 'day', 'month'
            $start_time = $now->modify("+$interval $interval_unit")->format('Y-m-d\TH:i:sP');

            $api_data['recurrence_details'] = [
                'interval' => (int) $this->get_option('interval'),
                'interval_unit' => $this->get_option('interval_unit'),
                'start_time' => $start_time,
                'total_recurrence' => (int) $this->get_option('total_recurring')
            ];
            $api_data['expired_time'] = $expiry_time;
        } elseif ($cc_mode === 'CC') {
            $api_data['payment_mode'] = 'CLOSE';
            $api_data['channel'] = ['CC_VISA'];
        } else {
            $api_data['channel'] = ['ALL'];
        }

        // Loop through order items and calculate DP
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $quantity = (int) $item->get_quantity();

            // Step 1: Set default DP based on payment option
            $item_dp = $default_down_payment;

            // Step 2: Get product categories
            $product_categories = wp_get_post_terms($product_id, 'product_cat');

            // Step 3: Check if product belongs to the special category 'ebad-reg'
            $this->logger->log('info', 'Product: ' . $item->get_name(), ['source' => 'smartlink-payment']);

            foreach ($product_categories as $category) {
                $this->logger->log('info', 'Category: ' . $category->slug, ['source' => 'smartlink-payment']);

                if ($category->slug === $special_category) {
                    $item_dp = $special_down_payment;
                    $this->logger->log('info', '✅ DP Overridden to: ' . number_format($item_dp, 0, ',', '.') . ' for product: ' . $item->get_name(), ['source' => 'smartlink-payment']);
                    break;
                }
            }

            $this->logger->log('info', 'Final DP for "' . $item->get_name() . '": ' . number_format($item_dp, 0, ',', '.'), ['source' => 'smartlink-payment']);

            // Step 4: Calculate total DP
            $total_down_payment += ($item_dp * $quantity);

            // Step 5: Add item to API request data
            $api_data['item'][] = [
                'name' => $item->get_name(),
                'amount' => $item_dp,
                'qty' => $quantity,
                'total_down_payment' => $item_dp * $quantity,
            ];
        }

        // Set the total amount in API request
        $api_data['amount'] = $total_down_payment;

        // Prepare API request
        $payment_url = $this->get_option('test_mode') === 'yes'
            ? 'https://payment-service-sbx.pakar-digital.com'
            : 'https://payment-service.pakar-digital.com';

        $auth_key = $this->settings['test_mode'] === 'yes'
            ? base64_encode($this->get_option('sbx_email_credential') . ':' . $this->get_option('sbx_password'))
            : base64_encode($this->get_option('live_email_credential') . ':' . $this->get_option('live_password'));

        $api_url = $payment_url . '/api/payment/create-order';

        $response = wp_remote_post($api_url, [
            'method'    => 'POST',
            'timeout'   => 45,
            'body'      => json_encode($api_data),
            'headers'   => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . $auth_key,
            ],
        ]);

        // Handle API response
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wc_add_notice(__('Payment error:', 'woothemes') . $error_message, 'error');
            $this->logger->log('error', 'Payment error: ' . $error_message, ['source' => 'smartlink-payment']);
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            return [
                'result'   => 'success',
                'redirect' => $response_data['data']['payment_url'],
            ];
        } else {
            $error_msg = isset($response_data['message']) ? $response_data['message'] : 'Unknown error';
            wc_add_notice(__('Payment failed: ', 'woothemes') . $error_msg, 'error');
            $this->logger->log('error', 'Payment failed for order ' . $order->get_id() . ': ' . $error_msg);
            $this->logger->log('error', 'API Endpoint Hit: ' . $api_url);
            $this->logger->log('error', 'Request Payload: ' . json_encode($api_data));
            $this->logger->log('error', 'API Response: ' . json_encode($response_data));
            return;
        }
    }
}
