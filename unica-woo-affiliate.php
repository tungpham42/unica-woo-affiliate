<?php
/*
Plugin Name: Unica Woo Affiliate
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
        <h1><?php esc_html_e('Unica Affiliate Settings', 'unica-woo-affiliate'); ?></h1>

        <?php render_settings_form(); ?>
        <?php render_manual_import_form(); ?>
        <?php render_set_current_page_form(); ?>

        <?php
        handle_manual_product_import();
        handle_set_current_page();
        ?>
    </div>
    <?php
}

// Render the main settings form
function render_settings_form() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields(UNICA_AFFILIATE_OPTIONS);
        do_settings_sections('unica_affiliate');
        submit_button();
        ?>
    </form>
    <?php
}

// Render the manual product import form
function render_manual_import_form() {
    ?>
    <h2><?php esc_html_e('Manual Product Import', 'unica-woo-affiliate'); ?></h2>
    <form method="post">
        <input type="hidden" name="unica_manual_import" value="1">
        <?php wp_nonce_field('import_products_action', 'import_products_nonce'); ?>
        <?php submit_button(esc_html__('Import Products Now', 'unica-woo-affiliate'), 'primary', 'import_products'); ?>
    </form>
    <?php
}

// Render the set current page form
function render_set_current_page_form() {
    // Check if form is submitted and nonce is valid
    if (isset($_POST['set_current_page_nonce']) && wp_verify_nonce($_POST['set_current_page_nonce'], 'set_current_page_action')) {
        // Sanitize and save the new page index
        $new_page = isset($_POST['unica_current_page']) ? intval($_POST['unica_current_page']) : 0;
        update_option('unica_current_page', $new_page);
        $current_page_index = $new_page; // Update the displayed page index to the new value
    } else {
        // Fetch the latest current page index value from the database
        $current_page_index = get_option('unica_current_page');
    }
    ?>
    <h2><?php esc_html_e('Set Current Page Index for Import', 'unica-woo-affiliate'); ?></h2>
    <form method="post">
        <?php wp_nonce_field('set_current_page_action', 'set_current_page_nonce'); ?>
        <label for="unica_current_page"><?php esc_html_e('Current Page Index (start from 0):', 'unica-woo-affiliate'); ?></label>
        <input type="number" name="unica_current_page" id="unica_current_page" value="<?php echo esc_attr($current_page_index); ?>">
        <?php submit_button(esc_html__('Set Page Index', 'unica-woo-affiliate'), 'primary', 'set_current_page'); ?>
    </form>
    <?php
}

// Handle manual product import submission
function handle_manual_product_import() {
    if (isset($_POST['unica_manual_import'])) {
        $nonce = isset($_POST['import_products_nonce']) ? sanitize_text_field(wp_unslash($_POST['import_products_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'import_products_action')) {
            $import_result = unica_auto_generate_products();
            echo '<p>' . esc_html($import_result) . '</p>';
        } else {
            echo '<p>' . esc_html__('Nonce verification failed.', 'unica-woo-affiliate') . '</p>';
        }
    }
}

// Handle setting the current page index
function handle_set_current_page() {
    if (isset($_POST['set_current_page'])) {
        $nonce = isset($_POST['set_current_page_nonce']) ? sanitize_text_field(wp_unslash($_POST['set_current_page_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'set_current_page_action')) {
            $new_page = isset($_POST['unica_current_page']) ? absint($_POST['unica_current_page']) : 0;
            update_option('unica_current_page', $new_page);

            // Display a confirmation message with the updated page index
            echo '<p>' . esc_html__('Current page index updated to ', 'unica-woo-affiliate') . esc_html($new_page) . '</p>';
        } else {
            echo '<p>' . esc_html__('Nonce verification failed.', 'unica-woo-affiliate') . '</p>';
        }
    }
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

    $aff_id = $body['data']['id'] ?? null;
    $token = $body['data']['token'] ?? null;

    if ($aff_id && $token) {
        update_option('unica_aff_id', $aff_id);
        update_option('unica_token', $token);
    }

    return [
        'aff_id' => $aff_id,
        'token' => $token,
    ];
}

function unica_get_courses_quantity() {
    $api_url = sprintf('%s/api/getCourseList', UNICA_URL);
    $response = wp_remote_get($api_url, ['timeout' => UNICA_TIMEOUT]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return count($body['data']) ?? [];
}

// Main product import function
function unica_auto_generate_products() {
    $credentials = unica_fetch_affiliate_credentials();
    if (isset($credentials['error'])) {
        return $credentials['error'];
    }
    $aff_id = get_option('unica_aff_id');
    $token = get_option('unica_token');
    $current_page = get_option('unica_current_page', 0);

    // Check for necessary configurations
    if (empty($aff_id) || empty($token)) {
        return 'Failed to retrieve Affiliate ID and Token from the API.';
    }

    // Fetch courses from the API
    $courses = unica_fetch_courses($aff_id, $token, $current_page);

    // Handle no courses case
    if (empty($courses)) {
        update_option('unica_current_page', 0);
        return 'No more products to import.';
    }

    // Create or update products
    array_walk($courses, 'unica_create_or_update_product');

    // Update current page for pagination
    update_option('unica_current_page', $current_page + 1);

    // Return success message
    $courses_count = count($courses);
    return sprintf('%d course%s import completed successfully in page %d.', $courses_count, ($courses_count > 1 ? 's' : ''), $current_page + 1);
}

/**
 * Processes the fetched courses and creates or updates products.
 *
 * @param array $courses The array of courses to process.
 * @return int The number of courses processed.
 */
function process_courses(array $courses): int {
    // Create or update products for the fetched courses
    array_walk($courses, 'unica_create_or_update_product');
    
    // Return the count of processed courses
    return count($courses);
}

// Fetch courses from Unica API
function unica_fetch_courses($aff_id, $token, $page) {
    $api_url = sprintf('%s/api/courses?aff_id=%s&token=%s&page=%d&option=new', UNICA_URL, $aff_id, $token, $page);
    $response = wp_remote_get($api_url, ['timeout' => UNICA_TIMEOUT]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['data']['data']['course'] ?? [];
}

// Create or update WooCommerce product
function unica_create_or_update_product($course) {
    $credentials = unica_fetch_affiliate_credentials();
    if (isset($credentials['error'])) {
        return $credentials['error'];
    }
    $aff_id = get_option('unica_aff_id');
    $token = get_option('unica_token');
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
        wp_delete_file($temp_file); // Clean up the temp file
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
