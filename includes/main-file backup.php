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

            // process admin options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

            // callback 
            add_action('woocommerce_api_smartlink', array($this, 'payment_callback'));
        }


        // Callback function for handling the payment-callback route
        public function payment_callback()
        {
            $json = file_get_contents('php://input');

            $data = json_decode($json, true);

            if (isset($data['data']['order_id'])) {
                // Check if in test mode or live mode
                $is_test_mode = get_option('test_mode') === 'yes';

                $liveCred = $this->get_option('live_email_credential') . ':' . $this->get_option('live_password');
                $sbxCred = $this->get_option('sbx_email') . ':' . $this->get_option('sbx_password');

                $email_credentials = $is_test_mode
                    ? $this->get_option('sbx_email')
                    : $this->get_option('live_email_credential');

                $order_id = $data['data']['order_id'] ?? null;
                $signature = $data['data']['signature'] ?? null;
                $channel = $data['data']['channel'] ?? null;
                $amount = $data['data']['amount'] ?? null;
                $transaction_time = $data['data']['transaction_time'] ?? null;
                $status = $data['data']['status'] ?? null;

                $expectedSignature = hash('sha256', $order_id . $amount . $channel . $transaction_time . $email_credentials);



                if ($signature !== $expectedSignature) {
                    header('Content-Type: application/json');
                    http_response_code(401);
                    echo json_encode(['error' => 'Invalid signature.']);
                    exit;
                }

                $order = new WC_Order($order_id);
                $orderStatus = $order->get_status();

                $response = [];

                if (strtoupper($status) == 'SUCCESS' && $orderStatus != 'completed') {
                    $order->update_status('completed');
                    $this->logger->log('info', 'Payment ' . $order->get_status() . ' for order ID ' . $order_id);

                    $to = $order->get_billing_email();

                    // Get the CC email from the options (the email you set in your WordPress settings)
                    $cc = get_option('email');

                    // Prepare the email subject and message
                    $subject = 'Payment Completed';
                    $message = 'Your payment for Order ID ' . $order_id . ' has been successfully completed.';

                    // Prepare the email headers
                    $headers = [
                        'Content-Type: text/html; charset=UTF-8',
                        'Cc: ' . $cc
                    ];

                    // Send the email
                    wp_mail($to, $subject, $message, $headers);

                    $response = [
                        'success' => true,
                        'message' => 'Payment completed successfully for order ID ' . $order_id,
                        'order_status' => $order->get_status(),
                    ];
                } elseif ($orderStatus == 'completed') {
                    $this->logger->log('info', 'This order has already been paid.');
                    $response = [
                        'success' => false,
                        'message' => 'This order has already been paid.',
                        'order_status' => $order->get_status(),
                    ];

                    wp_redirect($this->get_return_url($order));
                    exit;
                } elseif (strtoupper($status) == 'EXPIRED') {
                    $this->logger->log('info', 'This order has already expired.');
                    $response = [
                        'success' => false,
                        'message' => 'This order has already expired.',
                        'order_status' => $order->get_status(),
                    ];
                    exit;
                } else {
                    $this->logger->log('info', 'Unhandled status: ' . $status);
                    $response = [
                        'success' => false,
                        'message' => 'Unhandled status: ' . $status,
                        'order_status' => $order->get_status(),
                    ];
                    exit;
                }
            } else {
                $response = array(
                    'success' => false,
                    'message' => 'Order ID not provided!',
                );
            }

            wp_send_json($response);
        }

        public function init_form_fields()
        {
            // bank list
            $bank_fields = [
                'VA_SINARMAS' => [
                    'title' => __('Bank SINARMAS', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank Sinarmas', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_SINARMAS.png" alt="Bank A Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_PERMATA' => [
                    'title' => __('Bank PERMATA', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank PERMATA', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_PERMATA.png" alt="Bank B Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_MUAMALAT' => [
                    'title' => __('Bank MUAMALAT', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank MUAMALAT', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_MUAMALAT.png" alt="Bank C Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_DANAMON' => [
                    'title' => __('Bank DANAMON', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank DANAMON', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_DANAMON.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_CIMB' => [
                    'title' => __('Bank CIMB', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank CIMB', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_CIMB.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_BSI' => [
                    'title' => __('Bank BSI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank BSI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_BSI.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_BRI' => [
                    'title' => __('Bank BRI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank BRI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_BRI.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_BNI' => [
                    'title' => __('Bank BNI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank BNI', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_BNI.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_BNC' => [
                    'title' => __('Bank BNC', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank BNC', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_BNC.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
                'VA_BCA' => [
                    'title' => __('Bank BCA', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'type' => 'checkbox',
                    'label' => __('Enable Bank BCA', SMARTLINK_PAYMENT_TEXT_DOMAIN),
                    'description' => '<img src="https://payment-service-sbx.pakar-digital.com/assets/paymentchannel/VA_BCA.png" alt="Bank D Logo" width="150px" />',
                    'default' => 'no'
                ],
            ];

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
                    'default' => 'yes'
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

            ]);

            $this->form_fields = array_merge($form_fields, $bank_fields);
        }

        public function payment_fields()
        {
            echo '<p>' . __('Complete the payment with the available payment channel:', 'woocommerce') . '</p>';
            echo '<div class="smartlink-bank-options">';

            echo '<label style="display: block; margin-bottom: 10px; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">';
            echo '<input type="radio" name="smartlink_bank_option" value="ALL" checked style="margin-right: 10px;" />';
            echo esc_html__('PAYMENT', 'woocommerce');
            echo '</label>';

            echo '</div>';
        }

        /**
         * Validate the selected bank option.
         */
        public function validate_fields()
        {
            if (empty($_POST['smartlink_bank_option'])) {
                wc_add_notice(__('Please select a bank to proceed with the payment.', 'woocommerce'), 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id)
        {
            if ($this->logger) {
                $this->logger->log('info', 'Processing payment...', array('source' => 'smartlink-payment'));
            }

            // Get the order
            $order = wc_get_order($order_id);

            // Combine them as 'username:password'
            $liveCred = $this->get_option('live_email_credential') . ':' . $this->get_option('live_password');
            $sbxCred = $this->get_option('sbx_email') . ':' . $this->get_option('sbx_password');

            //error_log('live_email Key: ' . $this->get_option('live_email_credential'));
            //error_log('live_password Key: ' . $this->get_option('live_password'));
            
            //error_log('liveCred Key: ' . $liveCred);
            
            // Convert to Base64
            $liveBase64 = base64_encode($liveCred);
            $sbxBase64 = base64_encode($sbxCred);

            // Get the selected bank option from the user input
            $bank_option = isset($_POST['smartlink_bank_option']) ? sanitize_text_field($_POST['smartlink_bank_option']) : '';

            // Get test mode status and relevant auth key
            $is_test_mode = ($this->get_option('test_mode') === 'yes');
            $auth_key = $is_test_mode ? $sbxBase64 : $liveBase64;
            $payment_url = $is_test_mode ? 'https://payment-service-sbx.pakar-digital.com' : 'https://payment-service.pakar-digital.com';
			//error_log('Authorization Key: ' . $auth_key);
			
            // Prepare the API data
            $api_data = [
                'order_id' => $order->get_id(),
                'amount' => (int) $order->get_total(),
                'description' => 'Payment for Order #' . $order->get_id(),
                'customer' => [
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                ],
                'item' => [],
                'channel' => ['ALL'],
                'type' => 'payment-page',
                'callback_url' => get_site_url() . '/wc-api/smartlink/',
                'success_redirect_url' => add_query_arg('order_id', $order_id, $this->get_return_url($order)),
                'failed_redirect_url' => add_query_arg('order_id', $order_id, get_site_url() . '/failed-payment'),
            ];

            foreach ($order->get_items() as $item_id => $item) {
                $variation_id = $item->get_variation_id();

                $product_id = $variation_id ? $variation_id : $item->get_product_id();

                $product = wc_get_product($product_id);

                if ($product) {
                    $price_per_item = (int) $product->get_price();

                    $api_data['item'][] = [
                        'name' => $item->get_name(),
                        'amount' => $price_per_item,
                        'qty' => (int) $item->get_quantity(),
                    ];
                }
            }

            // Prepare the API URL
            $api_url = $payment_url . '/api/payment/create-order';

            // Prepare the API request with the dynamic authorization key
            $response = wp_remote_post($api_url, [
                'method'    => 'POST',
                'body'      => json_encode($api_data),
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $auth_key,
                ],
            ]);
            
            //error_log('Authorization: Basic ' . $auth_key);


            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                wc_add_notice(__('Payment error:', 'woothemes') . $error_message, 'error');

                // Log the error
                if ($this->logger) {
                    $this->logger->log('error', 'Payment error: ' . $error_message, array('source' => 'smartlink-payment'));
                }

                return;
            }

            // Handle the API response
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);



            // Check response data for success/failure
            if (isset($response_data['status']) && $response_data['status'] === 'success') {
                return [
                    'result'   => 'success',
                    'redirect' => $response_data['data']['payment_url'],
                ];
            } else {
                // Payment failed
                wc_add_notice(__('Payment failed:', 'woothemes') . $response_data['message'], 'error');

                if ($this->logger) {
                    $log_message = 'Payment failed for order ' . $order->get_id() . ': ' .
                        $response_data['message'] .
                        ' | Response: ' . print_r($response_body, true) .
                        ' | Request: ' . print_r($api_data, true);

                    $this->logger->log(
                        'error',
                        $log_message,
                        array('source' => 'smartlink-payment')
                    );
                }

                return;
            }
        }
    }