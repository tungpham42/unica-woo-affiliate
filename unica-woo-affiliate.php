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

define('UWAFF_AFFILIATE_OPTIONS', 'uwaff_affiliate_options');
define('UWAFF_URL', 'https://unica.vn');
define('UWAFF_TIMEOUT', 3600);

// Hook actions
add_action('admin_menu', 'uwaff_add_admin_menu');
add_action('admin_init', 'uwaff_register_settings');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'uwaff_add_settings_link');
add_action('uwaff_daily_product_import', 'uwaff_auto_generate_products');
add_action('admin_head', 'uwaff_custom_admin_title'); // Add custom title to admin head

// Add Unica Affiliate menu in the admin sidebar
function uwaff_add_admin_menu() {
    add_menu_page(
        'Unica Affiliate Settings',
        'Unica Affiliate',
        'manage_options',
        'uwaff_affiliate',
        'uwaff_settings_page',
        'dashicons-welcome-learn-more',
        420
    );
}

// Display settings page content
function uwaff_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Unica Affiliate Settings', 'unica-woo-affiliate'); ?></h1>

        <?php uwaff_render_settings_form(); ?>
        <?php uwaff_render_manual_import_form(); ?>
        <?php uwaff_render_set_current_page_form(); ?>

        <?php
        uwaff_handle_manual_product_import();
        uwaff_handle_set_current_page();
        ?>
    </div>
    <?php
}

// Render the main settings form
function uwaff_render_settings_form() {
    ?>
    <form method="post" action="options.php">
        <?php
        settings_fields(UWAFF_AFFILIATE_OPTIONS);
        do_settings_sections('uwaff_affiliate');
        submit_button();
        ?>
    </form>
    <?php
}

// Render the manual product import form
function uwaff_render_manual_import_form() {
    // Check if form is submitted and nonce is valid
    if (isset($_POST['set_current_page_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['set_current_page_nonce'])), 'set_current_page_action')) {
        // Sanitize and save the new page
        $current_page = isset($_POST['uwaff_current_page']) ? intval($_POST['uwaff_current_page']) : 1;
    } elseif (isset($_POST['uwaff_manual_import'])) {
        // Fetch the latest current page value from the database
        $current_page = get_option('uwaff_current_page') + 2;
    } else {
        $current_page = get_option('uwaff_current_page') + 1;
    }
    ?>
    <h2><?php esc_html_e('Manual Courses Import', 'unica-woo-affiliate'); ?></h2>
    <form method="post">
        <input type="hidden" name="uwaff_manual_import" value="1">
        <?php wp_nonce_field('import_products_action', 'import_products_nonce'); ?>
        <?php submit_button(sprintf('Import courses in page number %d now', $current_page), 'primary', 'import_products'); ?>
    </form>
    <?php
}

// Render the set current page form with current page incremented by 1
function uwaff_render_set_current_page_form() {
    // Check if form is submitted and nonce is valid
    if (isset($_POST['set_current_page_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['set_current_page_nonce'])), 'set_current_page_action')) {
        // Sanitize and save the new page
        $new_page = isset($_POST['uwaff_current_page']) ? intval($_POST['uwaff_current_page']) : 1;
        update_option('uwaff_current_page', $new_page - 1);
        $current_page_index = $new_page; // Update the displayed page to the new value
    } elseif (isset($_POST['uwaff_manual_import'])) {
        $current_page_index = get_option('uwaff_current_page');
    } else {
        // Fetch the latest current page value from the database
        $current_page_index = get_option('uwaff_current_page') - 1;
    }
    // Display the current page incremented by 1
    $displayed_page_index = get_option('uwaff_current_page') + 1;
    ?>
    <h2><?php esc_html_e('Set Current Page for Import (each page has at most 15 courses)', 'unica-woo-affiliate'); ?></h2>
    <form method="post">
        <?php wp_nonce_field('set_current_page_action', 'set_current_page_nonce'); ?>
        <label for="uwaff_current_page"><?php esc_html_e('Current Page (start from 1):', 'unica-woo-affiliate'); ?></label>
        <input type="number" name="uwaff_current_page" id="uwaff_current_page" value="<?php echo esc_attr($displayed_page_index); ?>" min="1">
        <?php submit_button(esc_html__('Set Page', 'unica-woo-affiliate'), 'primary', 'set_current_page'); ?>
    </form>
    <?php
}

// Handle manual product import submission
function uwaff_handle_manual_product_import() {
    if (isset($_POST['uwaff_manual_import'])) {
        $nonce = isset($_POST['import_products_nonce']) ? sanitize_text_field(wp_unslash($_POST['import_products_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'import_products_action')) {
            $import_result = uwaff_auto_generate_products();
            echo '<p>' . esc_html($import_result) . '</p>';
        } else {
            echo '<p>' . esc_html__('Nonce verification failed.', 'unica-woo-affiliate') . '</p>';
        }
    }
}

// Handle setting the current page
function uwaff_handle_set_current_page() {
    if (isset($_POST['set_current_page'])) {
        $nonce = isset($_POST['set_current_page_nonce']) ? sanitize_text_field(wp_unslash($_POST['set_current_page_nonce'])) : '';
        if (wp_verify_nonce($nonce, 'set_current_page_action')) {
            // Adjust the input by subtracting 1 to store the correct zero-based index
            $new_page = isset($_POST['uwaff_current_page']) ? max(intval($_POST['uwaff_current_page']) - 1, 0) : 0;
            update_option('uwaff_current_page', $new_page);

            // Display a confirmation message with the updated page
            echo '<p>' . esc_html__('Current page updated to ', 'unica-woo-affiliate') . esc_html($new_page + 1) . '</p>';
        } else {
            echo '<p>' . esc_html__('Nonce verification failed.', 'unica-woo-affiliate') . '</p>';
        }
    }
}

// Register plugin settings
function uwaff_register_settings() {
    register_setting(UWAFF_AFFILIATE_OPTIONS, 'uwaff_username');
    register_setting(UWAFF_AFFILIATE_OPTIONS, 'uwaff_password');
    register_setting(UWAFF_AFFILIATE_OPTIONS, 'uwaff_button_text');
    register_setting(UWAFF_AFFILIATE_OPTIONS, 'uwaff_coupon_code');

    add_settings_section('uwaff_settings_section', 'Unica Affiliate Settings', null, 'uwaff_affiliate');
    add_settings_field('uwaff_username', 'Username', 'uwaff_render_username_field', 'uwaff_affiliate', 'uwaff_settings_section');
    add_settings_field('uwaff_password', 'Password', 'uwaff_render_password_field', 'uwaff_affiliate', 'uwaff_settings_section');
    add_settings_field('uwaff_button_text', 'Button Text', 'uwaff_render_button_text_field', 'uwaff_affiliate', 'uwaff_settings_section');
    add_settings_field('uwaff_coupon_code', 'Coupon Code', 'uwaff_render_coupon_code_field', 'uwaff_affiliate', 'uwaff_settings_section');
}

function uwaff_render_username_field() {
    $username = get_option('uwaff_username', '');
    echo '<input type="text" name="uwaff_username" value="' . esc_attr($username) . '" />';
}

function uwaff_render_password_field() {
    $password = get_option('uwaff_password', '');
    echo '<input type="password" name="uwaff_password" value="' . esc_attr($password) . '" />';
}

function uwaff_render_button_text_field() {
    $button_text = get_option('uwaff_button_text', 'Đăng Ký Khóa Học');
    echo '<input type="text" name="uwaff_button_text" value="' . esc_attr($button_text) . '" />';
}

function uwaff_render_coupon_code_field() {
    $coupon_code = get_option('uwaff_coupon_code', '');
    echo '<input type="text" name="uwaff_coupon_code" value="' . esc_attr($coupon_code) . '" />';
}

// Fetch Affiliate ID and Token from Unica API
function uwaff_fetch_affiliate_credentials() {
    $username = get_option('uwaff_username');
    $password = get_option('uwaff_password');

    if (empty($username) || empty($password)) {
        return ['error' => 'Username and password are required.'];
    }

    $api_url = UWAFF_URL . '/api/getToken';
    $response = wp_remote_post($api_url, [
        'timeout' => UWAFF_TIMEOUT,
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
        update_option('uwaff_aff_id', $aff_id);
        update_option('uwaff_token', $token);
    }

    return [
        'aff_id' => $aff_id,
        'token' => $token,
    ];
}

function uwaff_get_courses_quantity() {
    $api_url = sprintf('%s/api/getCourseList', UWAFF_URL);
    $response = wp_remote_get($api_url, ['timeout' => UWAFF_TIMEOUT]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return count($body['data']) ?? [];
}

// Main product import function
function uwaff_auto_generate_products() {
    $credentials = uwaff_fetch_affiliate_credentials();
    if (isset($credentials['error'])) {
        return $credentials['error'];
    }
    $aff_id = get_option('uwaff_aff_id');
    $token = get_option('uwaff_token');
    $current_page = get_option('uwaff_current_page', 0);

    // Check for necessary configurations
    if (empty($aff_id) || empty($token)) {
        return 'Failed to retrieve Affiliate ID and Token from the API.';
    }

    // Fetch courses from the API
    $courses = uwaff_fetch_courses($aff_id, $token, $current_page);

    // Handle no courses case
    if (empty($courses)) {
        update_option('uwaff_current_page', 0);
        return 'No more course to import.';
    }

    // Create or update products
    $courses_count = uwaff_process_courses($courses);

    // Update current page for pagination
    update_option('uwaff_current_page', $current_page + 1);

    return sprintf('%d course%s import completed successfully in page %d.', $courses_count, ($courses_count > 1 ? 's' : ''), $current_page + 1);
}

/**
 * Processes the fetched courses and creates or updates products.
 *
 * @param array $courses The array of courses to process.
 * @return int The number of courses processed.
 */
function uwaff_process_courses(array $courses): int {
    // Create or update products for the fetched courses
    array_walk($courses, 'uwaff_create_or_update_product');
    
    // Return the count of processed courses
    return count($courses);
}

// Fetch courses from Unica API
function uwaff_fetch_courses($aff_id, $token, $page) {
    $api_url = sprintf('%s/api/courses?aff_id=%s&token=%s&page=%d&option=new', UWAFF_URL, $aff_id, $token, $page);
    $response = wp_remote_get($api_url, ['timeout' => UWAFF_TIMEOUT]);

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['data']['data']['course'] ?? [];
}

// Create or update WooCommerce product
function uwaff_create_or_update_product($course) {
    $credentials = uwaff_fetch_affiliate_credentials();
    if (isset($credentials['error'])) {
        return $credentials['error'];
    }
    $aff_id = get_option('uwaff_aff_id');
    $token = get_option('uwaff_token');
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
    $button_text = get_option('uwaff_button_text', 'Đăng Ký Khóa Học');
    $product->set_button_text($button_text);

    // Add coupon code if it exists
    $coupon_code = get_option('uwaff_coupon_code', '');
    $product_url = UWAFF_URL . '/' . $course['url_course'] . '?aff=' . $aff_id;
    if (!empty($coupon_code)) {
        $product_url .= '&coupon=' . $coupon_code;
    }
    $product->set_product_url($product_url);

    // Upload product image if it exists
    if (!empty($course['url_thumnail'])) {
        $attachment_id = uwaff_upload_image_from_url(UWAFF_URL . $course['url_thumnail']);
        if ($attachment_id) {
            $product->set_image_id($attachment_id);
        }
    }

    $product->save();
}

// Upload image from URL
function uwaff_upload_image_from_url($url) {
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
function uwaff_add_settings_link($links) {
    $settings_link = '<a href="admin.php?page=uwaff_affiliate">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Set custom admin title
function uwaff_custom_admin_title() {
    $screen = get_current_screen();
    if ($screen->id === 'toplevel_page_uwaff_affiliate') {
        echo '<title>' . esc_html('Unica Affiliate Settings') . '</title>';
    }
}

// Register CRON job for automatic import
if (!wp_next_scheduled('uwaff_daily_product_import')) {
    wp_schedule_event(time(), 'daily', 'uwaff_daily_product_import');
}

register_activation_hook(__FILE__, 'uwaff_activation_hook');
function uwaff_activation_hook() {
    if (!wp_next_scheduled('uwaff_daily_product_import')) {
        wp_schedule_event(time(), 'daily', 'uwaff_daily_product_import');
    }
}

register_deactivation_hook(__FILE__, 'uwaff_deactivation_hook');
function uwaff_deactivation_hook() {
    wp_clear_scheduled_hook('uwaff_daily_product_import');
}
