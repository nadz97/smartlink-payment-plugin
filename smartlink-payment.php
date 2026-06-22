<?php

/**
 * Plugin Name: Smartlink payment
 * Plugin URI:  https://smartlink.pakar-digital.com/
 * Author:      Esmartlink
 * Author URI:  https://pakar-digital.com/
 * Description: This plugin allow to make payment in your website using smartlink
 * Version:     0.1.0
 * License:     GPL-2.0+
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * text-domain: smartlink-payment
 */

if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

//define the plugin constants
define('SMARTLINK_PAYMENT_VERSION', '0.1.0');
define('SMARTLINK_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SMARTLINK_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SMARTLINK_PAYMENT_TEXT_DOMAIN', 'smartlink-payment');
define('SMARTLINK_PAYMENT_API_URL', 'https://payment-service-sbx.pakar-digital.com');

// check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    // add notice
    add_action('admin_notices', 'smartlink_payment_woocommerce_notice');
} else {
    // add action plugin loaded
    add_action('plugins_loaded', 'smartlink_payment_init');
    // add settings url
    add_filter(
        'plugin_action_links_' . plugin_basename(__FILE__),
        'smartlink_payment_settings_link'
    );
    // register woocommerce payment gateway
    add_filter('woocommerce_payment_gateways', 'smartlink_payment');
}

//initialize the plugin
function smartlink_payment_init()
{
    // check if the class is exist smartlink_payment_gateway
    if (!class_exists('smartlink_payment_gateway')) {
        include_once SMARTLINK_PAYMENT_PLUGIN_PATH . '/includes/main-file.php';
    }
}



function smartlink_payment_woocommerce_notice()
{
    ob_start();
    // require the admin notice template
    require_once SMARTLINK_PAYMENT_PLUGIN_PATH . '/templates/admin_notice.php';
    echo ob_get_clean();
}

function smartlink_payment_settings_link($links)
{
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=smartlink_payment">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}

function smartlink_payment($gateways)
{
    $gateways[] = 'smartlink_payment_gateway';
    return $gateways;
}
