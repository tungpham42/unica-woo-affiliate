<?php
/*
Plugin Name: Unica WooCommerce Affiliate
Description: Auto-generate WooCommerce products from Unica.vn API.
Version: 1.0.0
Author: Tung Pham, Hoang Anh Phan
Author URI: https://tungpham42.github.io
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit();
}

define('UNICA_AFFILIATE_OPTIONS', 'unica_affiliate_options');
define('UNICA_URL', 'https://unica.vn');
define('UNICA_TIMEOUT', 3600);

// Hook actions
add_action('admin_menu', 'unica_add_admin_menu');
add_action('admin_init', 'unica_register_settings');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'unica_add_settings_link');
add_action('unica_daily_product_import', 'unica_auto_generate_products');
add_action('admin_head', 'unica_custom_admin_title'); // Add custom title to admin head

// Add Unica Affiliate menu in the admin sidebar
function unica_add_admin_menu() {
    add_menu_page(
        'Unica Affiliate Settings',
        'Unica Affiliate',
        'manage_options',
        'unica_affiliate',
        'unica_settings_page',
        'dashicons-welcome-learn-more',
        420
    );
}

// Display settings page content
function unica_settings_page() {
    ?>
    <div class="wrap">
        <h1>Unica Affiliate Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields(UNICA_AFFILIATE_OPTIONS);
            do_settings_sections('unica_affiliate');
            submit_button();
            ?>
        </form>
        <h2>Manual Product Import</h2>
        <form method="post">
            <input type="hidden" name="unica_manual_import" value="1">
            <?php submit_button('Import Products Now', 'primary', 'import_products'); ?>
        </form>
        <?php
        if (isset($_POST['unica_manual_import'])) {
            $import_result = unica_auto_generate_products();
            echo '<p>' . esc_html($import_result) . '</p>';
        }
        ?>
    </div>
    <?php
}

// Register plugin settings
function unica_register_settings() {
    register_setting(UNICA_AFFILIATE_OPTIONS, 'unica_username');
    register_setting(UNICA_AFFILIATE_OPTIONS, 'unica_password');
    register_setting(UNICA_AFFILIATE_OPTIONS, 'unica_button_text');
    register_setting(UNICA_AFFILIATE_OPTIONS, 'unica_coupon_code');

    add_settings_section('unica_settings_section', 'Unica Affiliate Settings', null, 'unica_affiliate');
    add_settings_field('unica_username', 'Username', 'unica_render_username_field', 'unica_affiliate', 'unica_settings_section');
    add_settings_field('unica_password', 'Password', 'unica_render_password_field', 'unica_affiliate', 'unica_settings_section');
    add_settings_field('unica_button_text', 'Button Text', 'unica_render_button_text_field', 'unica_affiliate', 'unica_settings_section');
    add_settings_field('unica_coupon_code', 'Coupon Code', 'unica_render_coupon_code_field', 'unica_affiliate', 'unica_settings_section');
}

function unica_render_username_field() {
    $username = get_option('unica_username', '');
    echo '<input type="text" name="unica_username" value="' . esc_attr($username) . '" />';
}

function unica_render_password_field() {
    $password = get_option('unica_password', '');
    echo '<input type="password" name="unica_password" value="' . esc_attr($password) . '" />';
}

function unica_render_button_text_field() {
    $button_text = get_option('unica_button_text', 'Đăng Ký Khóa Học');
    echo '<input type="text" name="unica_button_text" value="' . esc_attr($button_text) . '" />';
}

function unica_render_coupon_code_field() {
    $coupon_code = get_option('unica_coupon_code', '');
    echo '<input type="text" name="unica_coupon_code" value="' . esc_attr($coupon_code) . '" />';
}

// Fetch Affiliate ID and Token from Unica API
function unica_fetch_affiliate_credentials() {
    $username = get_option('unica_username');
    $password = get_option('unica_password');

    if (empty($username) || empty($password)) {
        return ['error' => 'Username and password are required.'];
    }

    $api_url = UNICA_URL . '/api/getToken';
    $response = wp_remote_post($api_url, [
        'timeout' => UNICA_TIMEOUT,
        'body'    => [
            'username' => $username,
            'password' => $password,
        ],
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        return ['error' => $body['error']];
    }

    return [
        'aff_id' => $body['data']['id'] ?? null,
        'token' => $body['data']['token'] ?? null,
    ];
}

function unica_get_courses_quantity() {
    $api_url = sprintf('%s/api/getCourseList', UNICA_URL);
    $response = wp_remote_get($api_url, ['timeout' => UNICA_TIMEOUT]);

    if (is_wp_error($response)) {
        error_log("Unica API request failed: " . $response->get_error_message());
        return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return count($body['data']) ?? [];
}

// Main product import function
function unica_auto_generate_products() {
    $credentials = unica_fetch_affiliate_credentials();
    if (isset($credentials['error'])) {
        return $credentials['error'];
    }

    $aff_id = $credentials['aff_id'];
    $token = $credentials['token'];

    // Check for necessary configurations
    if (empty($aff_id) || empty($token)) {
        return 'Failed to retrieve Affiliate ID and Token from the API.';
    }

    $total_courses = unica_get_courses_quantity();

    // Fetch courses from the API
    $courses = unica_fetch_courses($aff_id, $token);
    $courses_count = count($courses);

    // Create or update products
    array_walk($courses, 'unica_create_or_update_product');

    // Return success message
    return sprintf('%d course%s import completed successfully. Total %d course%s.', $courses_count, ($courses_count > 1 ? 's' : ''), $total_courses, ($total_courses > 1 ? 's' : ''));
}

// Fetch courses from Unica API
function unica_fetch_courses($aff_id, $token) {
    $api_url = sprintf('%s/api/courses?aff_id=%s&token=%s', UNICA_URL, $aff_id, $token);
    $response = wp_remote_get($api_url, ['timeout' => UNICA_TIMEOUT]);

    if (is_wp_error($response)) {
        error_log("Unica API request failed: " . $response->get_error_message());
        return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['data']['data']['course'] ?? [];
}

// Create or update WooCommerce product
function unica_create_or_update_product($course) {
    $credentials = unica_fetch_affiliate_credentials();
    $aff_id = $credentials['aff_id'];
    $product_id = wc_get_product_id_by_sku($course['id']);

    if ($product_id) return; // Product already exists

    $product = new WC_Product_External();
    $product->set_name($course['course_name']);
    $product->set_sku($course['id']);
    $product->set_description($course['content']);
    $product->set_regular_price($course['price_origin']);
    $product->set_catalog_visibility('visible');
    $product->set_status('publish');

    // Set custom button text from settings
    $button_text = get_option('unica_button_text', 'Đăng Ký Khóa Học');
    $product->set_button_text($button_text);

    // Add coupon code if it exists
    $coupon_code = get_option('unica_coupon_code', '');
    $product_url = UNICA_URL . '/' . $course['url_course'] . '?aff=' . $aff_id;
    if (!empty($coupon_code)) {
        $product_url .= '&coupon=' . $coupon_code;
    }
    $product->set_product_url($product_url);

    // Upload product image if it exists
    if (!empty($course['url_thumnail'])) {
        $attachment_id = upload_image_from_url(UNICA_URL . $course['url_thumnail']);
        if ($attachment_id) {
            $product->set_image_id($attachment_id);
        }
    }

    $product->save();
}

// Upload image from URL
function upload_image_from_url($url) {
    $temp_file = download_url($url);

    if (is_wp_error($temp_file)) {
        return false;
    }

    $file = [
        'name' => basename($url),
        'type' => mime_content_type($temp_file),
        'tmp_name' => $temp_file,
        'error' => 0,
        'size' => filesize($temp_file),
    ];

    $attachment_id = media_handle_sideload($file);
    if (is_wp_error($attachment_id)) {
        @unlink($temp_file); // Clean up the temp file
        return false;
    }

    return $attachment_id;
}

// Add settings link to the plugins page
function unica_add_settings_link($links) {
    $settings_link = '<a href="admin.php?page=unica_affiliate">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Set custom admin title
function unica_custom_admin_title() {
    $screen = get_current_screen();
    if ($screen->id === 'toplevel_page_unica_affiliate') {
        echo '<title>' . esc_html('Unica Affiliate Settings') . '</title>';
    }
}

// Register CRON job for automatic import
if (!wp_next_scheduled('unica_daily_product_import')) {
    wp_schedule_event(time(), 'daily', 'unica_daily_product_import');
}

register_activation_hook(__FILE__, 'unica_activation_hook');
function unica_activation_hook() {
    if (!wp_next_scheduled('unica_daily_product_import')) {
        wp_schedule_event(time(), 'daily', 'unica_daily_product_import');
    }
}

register_deactivation_hook(__FILE__, 'unica_deactivation_hook');
function unica_deactivation_hook() {
    wp_clear_scheduled_hook('unica_daily_product_import');
}
