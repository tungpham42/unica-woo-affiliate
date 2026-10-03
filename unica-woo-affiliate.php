<?php
/*
Plugin Name: Unica Woo Affiliate
Description: Turn Unica.vn courses into WooCommerce affiliate products, with a one-click importer, live progress and daily auto-import.
Version: 2.0.0
Author: Tung Pham, Hoang Anh Phan
Author URI: https://tungpham42.github.io
Requires Plugins: woocommerce
Text Domain: unica-woo-affiliate
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit();
}

define('UWAFF_VERSION', '2.0.0');
define('UWAFF_OPTIONS', 'uwaff_affiliate_options');
define('UWAFF_URL', 'https://unica.vn');
define('UWAFF_TIMEOUT', 60);
define('UWAFF_PER_PAGE', 15);
define('UWAFF_CRON', 'uwaff_daily_product_import');
define('UWAFF_SLUG', 'uwaff_affiliate');

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

final class Uwaff_Plugin {

    /* ---------------------------------------------------------------
     * Boot
     * ------------------------------------------------------------- */

    public static function boot() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'ensure_cron']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'plugin_links']);
        add_action(UWAFF_CRON, [__CLASS__, 'cron_import']);

        foreach (['import', 'set_page', 'test', 'state', 'clear_log'] as $action) {
            add_action('wp_ajax_uwaff_' . $action, [__CLASS__, 'ajax_' . $action]);
        }
    }

    public static function activate() {
        if (!wp_next_scheduled(UWAFF_CRON)) {
            wp_schedule_event(time(), 'daily', UWAFF_CRON);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(UWAFF_CRON);
    }

    public static function ensure_cron() {
        if (!wp_next_scheduled(UWAFF_CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', UWAFF_CRON);
        }
    }

    /* ---------------------------------------------------------------
     * Menu, settings, links
     * ------------------------------------------------------------- */

    public static function add_menu() {
        add_menu_page(
            __('Unica Affiliate', 'unica-woo-affiliate'),
            __('Unica Affiliate', 'unica-woo-affiliate'),
            'manage_options',
            UWAFF_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-welcome-learn-more',
            56
        );
    }

    public static function register_settings() {
        $text = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'];
        register_setting(UWAFF_OPTIONS, 'uwaff_username', $text);
        register_setting(UWAFF_OPTIONS, 'uwaff_password', ['type' => 'string', 'sanitize_callback' => static function ($v) {
            return trim((string) $v);
        }]);
        register_setting(UWAFF_OPTIONS, 'uwaff_button_text', $text);
        register_setting(UWAFF_OPTIONS, 'uwaff_coupon_code', $text);
    }

    public static function plugin_links($links) {
        array_unshift($links, '<a href="' . esc_url(self::tab_url('settings')) . '">' . esc_html__('Settings', 'unica-woo-affiliate') . '</a>');
        return $links;
    }

    private static function tab_url($tab) {
        return add_query_arg(['page' => UWAFF_SLUG, 'tab' => $tab], admin_url('admin.php'));
    }

    /* ---------------------------------------------------------------
     * State helpers
     * ------------------------------------------------------------- */

    private static function woo_ready() {
        return class_exists('WooCommerce') && class_exists('WC_Product_External');
    }

    private static function has_credentials() {
        return get_option('uwaff_username') && get_option('uwaff_password');
    }

    private static function count_products() {
        if (!self::woo_ready()) {
            return 0;
        }
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => 'external',
            ]],
        ]);
        return (int) $q->found_posts;
    }

    private static function state() {
        $page  = (int) get_option('uwaff_current_page', 0); // zero-based, kept for backward compatibility
        $total = (int) get_transient('uwaff_total_courses');
        $pages = $total ? (int) ceil($total / UWAFF_PER_PAGE) : 0;
        $pct   = $pages ? min(100, (int) round(($page / $pages) * 100)) : 0;
        $last  = (int) get_option('uwaff_last_run', 0);
        $next  = wp_next_scheduled(UWAFF_CRON);

        return [
            'next_page'         => $page + 1,
            'total_pages'       => $pages,
            'total_pages_label' => $pages ? (string) $pages : '?',
            'total_courses'     => $total,
            'percent'           => $pct,
            'imported'          => self::count_products(),
            'last_run'          => $last ? sprintf(__('%s ago', 'unica-woo-affiliate'), human_time_diff($last)) : __('Never', 'unica-woo-affiliate'),
            'next_cron'         => $next ? sprintf(__('in %s', 'unica-woo-affiliate'), human_time_diff($next)) : __('Not scheduled', 'unica-woo-affiliate'),
        ];
    }

    /* ---------------------------------------------------------------
     * Activity log
     * ------------------------------------------------------------- */

    private static function log($type, $message) {
        $log = get_option('uwaff_log', []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, ['time' => time(), 'type' => $type, 'message' => $message]);
        update_option('uwaff_log', array_slice($log, 0, 30), false);
    }

    /* ---------------------------------------------------------------
     * Unica API
     * ------------------------------------------------------------- */

    private static function api($method, $path, array $args = [], $timeout = UWAFF_TIMEOUT) {
        $url = UWAFF_URL . $path;
        if ($method === 'POST') {
            $res = wp_remote_post($url, ['timeout' => $timeout, 'body' => $args]);
        } else {
            $res = wp_remote_get(add_query_arg($args, $url), ['timeout' => $timeout]);
        }
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 400 || !is_array($body)) {
            return new WP_Error('uwaff_api', sprintf(__('Unica returned an unexpected response (HTTP %d).', 'unica-woo-affiliate'), $code));
        }
        if (!empty($body['error'])) {
            $msg = is_scalar($body['error']) ? (string) $body['error'] : wp_json_encode($body['error']);
            return new WP_Error('uwaff_api', $msg);
        }
        return $body;
    }

    private static function authenticate($username, $password, $persist = true) {
        if ($username === '' || $password === '') {
            return new WP_Error('uwaff_creds', __('Enter your Unica username and password first.', 'unica-woo-affiliate'));
        }
        $body = self::api('POST', '/api/getToken', ['username' => $username, 'password' => $password], 30);
        if (is_wp_error($body)) {
            return $body;
        }
        $aff_id = $body['data']['id'] ?? null;
        $token  = $body['data']['token'] ?? null;
        if (!$aff_id || !$token) {
            return new WP_Error('uwaff_creds', __('Unica did not accept those credentials.', 'unica-woo-affiliate'));
        }
        if ($persist) {
            update_option('uwaff_aff_id', $aff_id);
            update_option('uwaff_token', $token);
        }
        return ['aff_id' => $aff_id, 'token' => $token];
    }

    private static function refresh_total() {
        $body = self::api('GET', '/api/getCourseList', [], 20);
        if (is_wp_error($body) || !is_array($body['data'] ?? null)) {
            return 0;
        }
        $total = count($body['data']);
        set_transient('uwaff_total_courses', $total, 12 * HOUR_IN_SECONDS);
        return $total;
    }

    /* ---------------------------------------------------------------
     * Import engine
     * ------------------------------------------------------------- */

    /**
     * Imports one page of courses.
     *
     * @return array|WP_Error
     */
    public static function import_page($source = 'manual') {
        if (!self::woo_ready()) {
            return new WP_Error('uwaff_woo', __('WooCommerce must be active before you can import courses.', 'unica-woo-affiliate'));
        }

        $auth = self::authenticate((string) get_option('uwaff_username'), (string) get_option('uwaff_password'));
        if (is_wp_error($auth)) {
            self::log('err', $auth->get_error_message());
            return $auth;
        }

        $page = (int) get_option('uwaff_current_page', 0);
        $body = self::api('GET', '/api/courses', [
            'aff_id' => $auth['aff_id'],
            'token'  => $auth['token'],
            'page'   => $page,
            'option' => 'new',
        ]);
        if (is_wp_error($body)) {
            self::log('err', $body->get_error_message());
            return $body;
        }

        $courses = $body['data']['data']['course'] ?? [];
        if (empty($courses) || !is_array($courses)) {
            update_option('uwaff_current_page', 0);
            update_option('uwaff_last_run', time());
            $msg = __('Reached the end of the catalog. The next import starts again from page 1.', 'unica-woo-affiliate');
            self::log('ok', $msg);
            return ['message' => $msg, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'done' => true];
        }

        self::load_media_helpers();
        $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($courses as $course) {
            $counts[self::create_product((array) $course, $auth['aff_id'])]++;
        }

        update_option('uwaff_current_page', $page + 1);
        update_option('uwaff_last_run', time());

        $msg = sprintf(
            /* translators: 1: page, 2: added, 3: skipped, 4: failed */
            __('Page %1$d: %2$d added, %3$d already in store, %4$d failed.', 'unica-woo-affiliate'),
            $page + 1,
            $counts['created'],
            $counts['skipped'],
            $counts['failed']
        );
        self::log($counts['failed'] ? 'warn' : 'ok', ($source === 'cron' ? '[auto] ' : '') . $msg);

        return $counts + ['message' => $msg, 'done' => false];
    }

    private static function load_media_helpers() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /** @return string created|skipped|failed */
    private static function create_product(array $course, $aff_id) {
        if (empty($course['id']) || empty($course['course_name'])) {
            return 'failed';
        }
        $sku = (string) $course['id'];
        if (wc_get_product_id_by_sku($sku)) {
            return 'skipped';
        }

        try {
            $product = new WC_Product_External();
            $product->set_name(sanitize_text_field($course['course_name']));
            $product->set_sku($sku);
            $product->set_description(wp_kses_post($course['content'] ?? ''));
            $product->set_regular_price(wc_format_decimal($course['price_origin'] ?? 0));
            $product->set_catalog_visibility('visible');
            $product->set_status('publish');
            $product->set_button_text(get_option('uwaff_button_text', 'Đăng Ký Khóa Học') ?: 'Đăng Ký Khóa Học');

            $query = array_filter([
                'aff'    => $aff_id,
                'coupon' => trim((string) get_option('uwaff_coupon_code', '')),
            ]);
            $product->set_product_url(add_query_arg($query, UWAFF_URL . '/' . ltrim((string) ($course['url_course'] ?? ''), '/')));

            if (!empty($course['url_thumnail'])) { // sic: field name as returned by the Unica API
                $thumb = (string) $course['url_thumnail'];
                $thumb = preg_match('#^https?://#i', $thumb) ? $thumb : UWAFF_URL . '/' . ltrim($thumb, '/');
                $image = self::sideload_image($thumb);
                if ($image) {
                    $product->set_image_id($image);
                }
            }

            $product->save();
        } catch (Exception $e) {
            return 'failed';
        }
        return 'created';
    }

    private static function sideload_image($url) {
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $name = basename((string) wp_parse_url($url, PHP_URL_PATH)) ?: 'course-thumbnail.jpg';
        $id   = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
        if (is_wp_error($id)) {
            wp_delete_file($tmp);
            return 0;
        }
        return (int) $id;
    }

    public static function cron_import() {
        self::import_page('cron');
    }

    /* ---------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------- */

    private static function ajax_guard() {
        check_ajax_referer('uwaff_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'unica-woo-affiliate')], 403);
        }
    }

    public static function ajax_import() {
        self::ajax_guard();
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore
        }
        $result = self::import_page('manual');
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $result['state'] = self::state();
        wp_send_json_success($result);
    }

    public static function ajax_set_page() {
        self::ajax_guard();
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        update_option('uwaff_current_page', $page - 1);
        self::log('ok', sprintf(__('Next import set to page %d.', 'unica-woo-affiliate'), $page));
        wp_send_json_success([
            'message' => sprintf(__('Next import will start at page %d.', 'unica-woo-affiliate'), $page),
            'state'   => self::state(),
        ]);
    }

    public static function ajax_test() {
        self::ajax_guard();
        $user = isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '';
        $pass = isset($_POST['password']) ? trim(wp_unslash($_POST['password'])) : ''; // phpcs:ignore
        $auth = self::authenticate($user, $pass, false);
        if (is_wp_error($auth)) {
            wp_send_json_error(['message' => $auth->get_error_message()]);
        }
        wp_send_json_success(['message' => sprintf(__('Connected. Your affiliate ID is %s.', 'unica-woo-affiliate'), $auth['aff_id'])]);
    }

    public static function ajax_state() {
        self::ajax_guard();
        if (!get_transient('uwaff_total_courses')) {
            self::refresh_total();
        }
        wp_send_json_success(['state' => self::state()]);
    }

    public static function ajax_clear_log() {
        self::ajax_guard();
        delete_option('uwaff_log');
        wp_send_json_success(['message' => __('Activity cleared.', 'unica-woo-affiliate')]);
    }

    /* ---------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------- */

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_' . UWAFF_SLUG) {
            return;
        }
        wp_register_style('uwaff-admin', false, [], UWAFF_VERSION);
        wp_enqueue_style('uwaff-admin');
        wp_add_inline_style('uwaff-admin', self::css());

        $state = self::state();
        wp_register_script('uwaff-admin', false, [], UWAFF_VERSION, true);
        wp_enqueue_script('uwaff-admin');
        wp_add_inline_script('uwaff-admin', 'window.UWAFF=' . wp_json_encode([
            'ajax'       => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('uwaff_nonce'),
            'needsTotal' => empty($state['total_pages']),
            'i18n'       => [
                'network'  => __('Could not reach the server. Check your connection and try again.', 'unica-woo-affiliate'),
                'testing'  => __('Testing…', 'unica-woo-affiliate'),
                'importing'=> __('Importing…', 'unica-woo-affiliate'),
                'stopped'  => __('Stopped. You can pick up where you left off.', 'unica-woo-affiliate'),
                'finished' => __('All pages imported.', 'unica-woo-affiliate'),
                'cleared'  => __('No activity yet.', 'unica-woo-affiliate'),
                'default_btn' => 'Đăng Ký Khóa Học',
            ],
        ])  . ';', 'before');
        wp_add_inline_script('uwaff-admin', self::js());
    }

    /* ---------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------- */

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard'; // phpcs:ignore
        if (!in_array($tab, ['dashboard', 'settings', 'activity'], true)) {
            $tab = 'dashboard';
        }
        $s          = self::state();
        $configured = self::has_credentials();
        $circ       = 2 * M_PI * 52;
        $offset     = $circ * (1 - $s['percent'] / 100);
        $tabs       = [
            'dashboard' => [__('Import', 'unica-woo-affiliate'), 'dashicons-download'],
            'settings'  => [__('Settings', 'unica-woo-affiliate'), 'dashicons-admin-generic'],
            'activity'  => [__('Activity', 'unica-woo-affiliate'), 'dashicons-backup'],
        ];
        ?>
        <div class="wrap uwaff">
            <hr class="wp-header-end">

            <header class="uwaff-hero">
                <div class="uwaff-hero-copy">
                    <h1><?php esc_html_e('Unica Affiliate', 'unica-woo-affiliate'); ?></h1>
                    <p><?php esc_html_e('Fill your store with Unica.vn courses. Each product sends visitors to Unica with your affiliate ID and coupon, so every click can earn commission.', 'unica-woo-affiliate'); ?></p>
                    <span class="uwaff-pill <?php echo $configured ? 'is-ok' : 'is-warn'; ?>">
                        <?php echo $configured ? esc_html__('Credentials saved', 'unica-woo-affiliate') : esc_html__('Add your Unica login to start', 'unica-woo-affiliate'); ?>
                    </span>
                </div>
                <div class="uwaff-ring" role="img" aria-label="<?php esc_attr_e('Catalog progress', 'unica-woo-affiliate'); ?>">
                    <svg viewBox="0 0 120 120" aria-hidden="true">
                        <circle class="uwaff-ring-bg" cx="60" cy="60" r="52"/>
                        <circle class="uwaff-ring-fg" cx="60" cy="60" r="52" stroke-dasharray="<?php echo esc_attr(round($circ, 2)); ?>" stroke-dashoffset="<?php echo esc_attr(round($offset, 2)); ?>" transform="rotate(-90 60 60)"/>
                    </svg>
                    <div class="uwaff-ring-label">
                        <strong><span data-bind="percent"><?php echo (int) $s['percent']; ?></span>%</strong>
                        <small><?php esc_html_e('of catalog', 'unica-woo-affiliate'); ?></small>
                    </div>
                </div>
            </header>

            <nav class="uwaff-tabs" aria-label="<?php esc_attr_e('Sections', 'unica-woo-affiliate'); ?>">
                <?php foreach ($tabs as $key => $t) : ?>
                    <a href="<?php echo esc_url(self::tab_url($key)); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
                        <span class="dashicons <?php echo esc_attr($t[1]); ?>" aria-hidden="true"></span><?php echo esc_html($t[0]); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php settings_errors(); ?>

            <?php
            if ($tab === 'settings') {
                self::render_settings();
            } elseif ($tab === 'activity') {
                self::render_activity();
            } else {
                self::render_dashboard($s, $configured);
            }
            ?>
        </div>
        <?php
    }

    private static function render_dashboard(array $s, $configured) {
        $ready = self::woo_ready() && $configured;
        ?>
        <?php if (!self::woo_ready()) : ?>
            <div class="uwaff-alert is-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <div><?php esc_html_e('WooCommerce is not active. Activate it to create products from Unica courses.', 'unica-woo-affiliate'); ?></div></div>
        <?php elseif (!$configured) : ?>
            <div class="uwaff-alert is-warn"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
                <div><?php esc_html_e('Add your Unica username and password before importing.', 'unica-woo-affiliate'); ?>
                    <a href="<?php echo esc_url(self::tab_url('settings')); ?>"><?php esc_html_e('Open settings', 'unica-woo-affiliate'); ?></a></div></div>
        <?php endif; ?>

        <section class="uwaff-stats">
            <div class="uwaff-stat"><span data-bind="imported"><?php echo (int) $s['imported']; ?></span><p><?php esc_html_e('Courses in your store', 'unica-woo-affiliate'); ?></p></div>
            <div class="uwaff-stat"><span><?php esc_html_e('Page', 'unica-woo-affiliate'); ?> <b data-bind="next_page"><?php echo (int) $s['next_page']; ?></b></span><p><?php esc_html_e('Next page to import', 'unica-woo-affiliate'); ?></p></div>
            <div class="uwaff-stat"><span data-bind="last_run"><?php echo esc_html($s['last_run']); ?></span><p><?php esc_html_e('Last import', 'unica-woo-affiliate'); ?></p></div>
            <div class="uwaff-stat"><span data-bind="next_cron"><?php echo esc_html($s['next_cron']); ?></span><p><?php esc_html_e('Next automatic import', 'unica-woo-affiliate'); ?></p></div>
        </section>

        <div class="uwaff-grid">
            <section class="uwaff-card">
                <h2><?php esc_html_e('Import courses', 'unica-woo-affiliate'); ?></h2>
                <p class="uwaff-muted"><?php printf(esc_html__('Each page brings in up to %d courses. Courses already in your store are skipped, so re-running a page is safe.', 'unica-woo-affiliate'), UWAFF_PER_PAGE); ?></p>

                <div class="uwaff-progress">
                    <div class="uwaff-bar" aria-hidden="true"><span style="width:<?php echo (int) $s['percent']; ?>%"></span></div>
                    <p><?php printf(
                        esc_html__('Page %1$s of %2$s', 'unica-woo-affiliate'),
                        '<b data-bind="next_page">' . (int) $s['next_page'] . '</b>',
                        '<b data-bind="total_pages_label">' . esc_html($s['total_pages_label']) . '</b>'
                    ); ?></p>
                </div>

                <div class="uwaff-actions">
                    <button type="button" class="button button-primary button-hero" id="uwaff-import" <?php disabled(!$ready); ?>>
                        <?php printf(esc_html__('Import page %s', 'unica-woo-affiliate'), '<span data-bind="next_page">' . (int) $s['next_page'] . '</span>'); ?>
                    </button>
                    <button type="button" class="button button-hero" id="uwaff-import-all" <?php disabled(!$ready); ?>><?php esc_html_e('Import all remaining pages', 'unica-woo-affiliate'); ?></button>
                    <button type="button" class="button button-hero uwaff-stop" id="uwaff-stop" hidden><?php esc_html_e('Stop after this page', 'unica-woo-affiliate'); ?></button>
                </div>

                <ul class="uwaff-results" id="uwaff-results" aria-live="polite"></ul>

                <div class="uwaff-jump">
                    <label for="uwaff-page"><?php esc_html_e('Start from a different page', 'unica-woo-affiliate'); ?></label>
                    <div>
                        <input type="number" id="uwaff-page" min="1" value="<?php echo (int) $s['next_page']; ?>">
                        <button type="button" class="button" id="uwaff-set"><?php esc_html_e('Set page', 'unica-woo-affiliate'); ?></button>
                    </div>
                </div>
            </section>

            <aside class="uwaff-card uwaff-how">
                <h2><?php esc_html_e('How it works', 'unica-woo-affiliate'); ?></h2>
                <ol>
                    <li><b><?php esc_html_e('Connect', 'unica-woo-affiliate'); ?></b><span><?php esc_html_e('Save your Unica login in Settings.', 'unica-woo-affiliate'); ?></span></li>
                    <li><b><?php esc_html_e('Import', 'unica-woo-affiliate'); ?></b><span><?php esc_html_e('Courses become external WooCommerce products with image, price and description.', 'unica-woo-affiliate'); ?></span></li>
                    <li><b><?php esc_html_e('Earn', 'unica-woo-affiliate'); ?></b><span><?php esc_html_e('The product button opens Unica with your affiliate ID and coupon attached.', 'unica-woo-affiliate'); ?></span></li>
                    <li><b><?php esc_html_e('Stay current', 'unica-woo-affiliate'); ?></b><span><?php esc_html_e('One page is imported automatically every day, continuing from where you stopped.', 'unica-woo-affiliate'); ?></span></li>
                </ol>
            </aside>
        </div>
        <?php
    }

    private static function render_settings() {
        $btn    = get_option('uwaff_button_text', 'Đăng Ký Khóa Học');
        $coupon = get_option('uwaff_coupon_code', '');
        ?>
        <form method="post" action="options.php" class="uwaff-grid">
            <?php settings_fields(UWAFF_OPTIONS); ?>

            <div class="uwaff-stack">
                <section class="uwaff-card">
                    <h2><?php esc_html_e('Unica account', 'unica-woo-affiliate'); ?></h2>
                    <p class="uwaff-muted"><?php esc_html_e('Used to fetch your affiliate ID and a fresh access token for every import.', 'unica-woo-affiliate'); ?></p>

                    <div class="uwaff-field">
                        <label for="uwaff_username"><?php esc_html_e('Username', 'unica-woo-affiliate'); ?></label>
                        <input type="text" id="uwaff_username" name="uwaff_username" value="<?php echo esc_attr(get_option('uwaff_username', '')); ?>" autocomplete="off">
                    </div>
                    <div class="uwaff-field">
                        <label for="uwaff_password"><?php esc_html_e('Password', 'unica-woo-affiliate'); ?></label>
                        <div class="uwaff-inline">
                            <input type="password" id="uwaff_password" name="uwaff_password" value="<?php echo esc_attr(get_option('uwaff_password', '')); ?>" autocomplete="new-password">
                            <button type="button" class="button" id="uwaff-reveal"><?php esc_html_e('Show', 'unica-woo-affiliate'); ?></button>
                        </div>
                    </div>
                    <div class="uwaff-test">
                        <button type="button" class="button" id="uwaff-test"><?php esc_html_e('Test connection', 'unica-woo-affiliate'); ?></button>
                        <span id="uwaff-test-result" role="status"></span>
                    </div>
                </section>

                <section class="uwaff-card">
                    <h2><?php esc_html_e('Product button', 'unica-woo-affiliate'); ?></h2>
                    <div class="uwaff-field">
                        <label for="uwaff_button_text"><?php esc_html_e('Button text', 'unica-woo-affiliate'); ?></label>
                        <input type="text" id="uwaff_button_text" name="uwaff_button_text" value="<?php echo esc_attr($btn); ?>" data-preview="button">
                    </div>
                    <div class="uwaff-field">
                        <label for="uwaff_coupon_code"><?php esc_html_e('Coupon code', 'unica-woo-affiliate'); ?></label>
                        <input type="text" id="uwaff_coupon_code" name="uwaff_coupon_code" value="<?php echo esc_attr($coupon); ?>" data-preview="coupon">
                        <p class="description"><?php esc_html_e('Optional. Added to every course link as ?coupon=. Applies to newly imported courses only.', 'unica-woo-affiliate'); ?></p>
                    </div>
                    <?php submit_button(__('Save changes', 'unica-woo-affiliate'), 'primary', 'submit', false); ?>
                </section>
            </div>

            <aside class="uwaff-card uwaff-preview">
                <h2><?php esc_html_e('Live preview', 'unica-woo-affiliate'); ?></h2>
                <p class="uwaff-muted"><?php esc_html_e('How a course looks to your visitors.', 'unica-woo-affiliate'); ?></p>
                <div class="uwaff-product">
                    <div class="uwaff-thumb"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span></div>
                    <h3><?php esc_html_e('Sample course title', 'unica-woo-affiliate'); ?></h3>
                    <div class="uwaff-price">1.200.000&#8363;</div>
                    <span class="uwaff-coupon" id="uwaff-pv-coupon" <?php echo $coupon === '' ? 'hidden' : ''; ?>><?php esc_html_e('Coupon', 'unica-woo-affiliate'); ?>: <b><?php echo esc_html($coupon); ?></b></span>
                    <span class="uwaff-pv-btn" id="uwaff-pv-btn"><?php echo esc_html($btn !== '' ? $btn : 'Đăng Ký Khóa Học'); ?></span>
                </div>
            </aside>
        </form>
        <?php
    }

    private static function render_activity() {
        $log = get_option('uwaff_log', []);
        $log = is_array($log) ? $log : [];
        ?>
        <section class="uwaff-card" id="uwaff-activity">
            <div class="uwaff-card-head">
                <h2><?php esc_html_e('Recent activity', 'unica-woo-affiliate'); ?></h2>
                <?php if ($log) : ?><button type="button" class="button" id="uwaff-clear"><?php esc_html_e('Clear activity', 'unica-woo-affiliate'); ?></button><?php endif; ?>
            </div>
            <?php if (!$log) : ?>
                <p class="uwaff-empty"><?php esc_html_e('No activity yet. Import a page and the results will show up here.', 'unica-woo-affiliate'); ?></p>
            <?php else : ?>
                <ul class="uwaff-log">
                    <?php foreach ($log as $row) : ?>
                        <li class="is-<?php echo esc_attr($row['type'] ?? 'ok'); ?>">
                            <time datetime="<?php echo esc_attr(gmdate('c', (int) $row['time'])); ?>"><?php echo esc_html(sprintf(__('%s ago', 'unica-woo-affiliate'), human_time_diff((int) $row['time']))); ?></time>
                            <span><?php echo esc_html($row['message'] ?? ''); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    }

    /* ---------------------------------------------------------------
     * Inline CSS / JS
     * ------------------------------------------------------------- */

    private static function css() {
        return <<<'CSS'
.uwaff{--ink:#0F1B3D;--ink2:#1D2D63;--orange:#FF5A1F;--mint:#0E9F6E;--rose:#D6344A;--amber:#B7791F;--bg:#F3F6FB;--line:#DCE3F0;--muted:#5B6783;max-width:1120px;margin:20px 20px 0 2px;color:var(--ink);font-size:14px;line-height:1.55}
.uwaff *{box-sizing:border-box}
.uwaff h1,.uwaff h2,.uwaff h3{color:inherit;margin:0}
.uwaff-hero{display:flex;justify-content:space-between;align-items:center;gap:32px;padding:36px 40px;border-radius:16px;background:var(--ink);color:#fff}
.uwaff-hero h1{font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1.15}
.uwaff-hero p{max-width:52ch;margin:10px 0 18px;font-size:15px;color:#C9D3EE}
.uwaff-pill{display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:99px;font-size:13px;font-weight:600;background:rgba(255,255,255,.1)}
.uwaff-pill:before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
.uwaff-pill.is-ok{color:#5FE3B3}.uwaff-pill.is-warn{color:#FFB48F}
.uwaff-ring{position:relative;flex:none;width:156px;height:156px}
.uwaff-ring svg{width:100%;height:100%}
.uwaff-ring circle{fill:none;stroke-width:9}
.uwaff-ring-bg{stroke:rgba(255,255,255,.14)}
.uwaff-ring-fg{stroke:var(--orange);stroke-linecap:round;transition:stroke-dashoffset .8s cubic-bezier(.2,.8,.2,1)}
.uwaff-ring-label{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.uwaff-ring-label strong{font-size:32px;font-weight:700;letter-spacing:-.02em;line-height:1}
.uwaff-ring-label small{margin-top:4px;color:#C9D3EE;font-size:12px}
.uwaff-tabs{display:flex;gap:4px;margin:20px 0;border-bottom:1px solid var(--line)}
.uwaff-tabs a{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;margin-bottom:-1px;color:var(--muted);font-weight:600;text-decoration:none;border-bottom:3px solid transparent}
.uwaff-tabs a:hover{color:var(--ink)}
.uwaff-tabs a.is-active{color:var(--ink);border-color:var(--orange)}
.uwaff a:focus-visible,.uwaff button:focus-visible,.uwaff input:focus-visible{outline:3px solid #7C9BFF;outline-offset:2px;box-shadow:none}
.uwaff-alert{display:flex;gap:12px;align-items:flex-start;padding:14px 18px;margin-bottom:20px;border-radius:10px;border:1px solid #F4C7A8;background:#FFF3EA}
.uwaff-alert .dashicons{color:var(--orange)}
.uwaff-alert a{font-weight:600;margin-left:6px}
.uwaff-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.uwaff-stat{padding:18px 20px;background:#fff;border:1px solid var(--line);border-radius:12px}
.uwaff-stat>span{display:block;font-size:24px;font-weight:700;letter-spacing:-.01em;line-height:1.2}
.uwaff-stat p{margin:4px 0 0;color:var(--muted)}
.uwaff-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:20px;align-items:start}
.uwaff-stack{display:grid;gap:20px}
.uwaff-card{padding:26px 28px;background:#fff;border:1px solid var(--line);border-radius:12px}
.uwaff-card h2{font-size:18px;font-weight:700;margin-bottom:6px}
.uwaff-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.uwaff-muted{margin:0 0 20px;color:var(--muted);max-width:62ch}
.uwaff-progress{margin-bottom:22px}
.uwaff-progress p{margin:8px 0 0;color:var(--muted)}
.uwaff-bar{height:10px;border-radius:99px;background:#E7ECF6;overflow:hidden}
.uwaff-bar span{display:block;height:100%;border-radius:99px;background:var(--orange);transition:width .8s cubic-bezier(.2,.8,.2,1)}
.uwaff-actions{display:flex;flex-wrap:wrap;gap:10px}
.uwaff .button{border-radius:8px;border-color:var(--line);color:var(--ink);font-weight:600}
.uwaff .button-hero{min-height:44px;line-height:42px;padding:0 22px;font-size:14px}
.uwaff .button-primary{background:var(--ink);border-color:var(--ink);color:#fff}
.uwaff .button-primary:hover,.uwaff .button-primary:focus{background:var(--ink2);border-color:var(--ink2);color:#fff}
.uwaff .button[disabled]{opacity:.5;cursor:not-allowed}
.uwaff .button.is-busy{position:relative;color:transparent!important;pointer-events:none}
.uwaff .button.is-busy:after{content:"";position:absolute;top:50%;left:50%;width:16px;height:16px;margin:-8px 0 0 -8px;border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:uwspin .7s linear infinite}
.uwaff .button:not(.button-primary).is-busy:after{border-color:rgba(15,27,61,.25);border-top-color:var(--ink)}
@keyframes uwspin{to{transform:rotate(360deg)}}
.uwaff-results{list-style:none;margin:18px 0 0;padding:0;display:grid;gap:8px}
.uwaff-results:empty{display:none}
.uwaff-results li,.uwaff-log li{display:flex;gap:10px;align-items:baseline;padding:10px 14px;border-radius:8px;background:var(--bg);border-left:4px solid var(--mint)}
.uwaff-results li.is-err,.uwaff-log li.is-err{border-color:var(--rose);background:#FDEEF0}
.uwaff-results li.is-warn,.uwaff-log li.is-warn{border-color:var(--amber);background:#FFF8E8}
.uwaff-jump{margin-top:26px;padding-top:20px;border-top:1px solid var(--line)}
.uwaff-jump label,.uwaff-field label{display:block;margin-bottom:6px;font-weight:600}
.uwaff-jump>div,.uwaff-inline{display:flex;gap:8px}
.uwaff input[type=text],.uwaff input[type=password],.uwaff input[type=number]{width:100%;max-width:360px;min-height:40px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:#fff}
.uwaff input[type=number]{max-width:120px}
.uwaff-field{margin-bottom:18px}
.uwaff-field .description{margin:6px 0 0;color:var(--muted)}
.uwaff-test{display:flex;align-items:center;gap:12px;padding-top:4px}
#uwaff-test-result.is-ok{color:var(--mint);font-weight:600}
#uwaff-test-result.is-err{color:var(--rose);font-weight:600}
.uwaff-how ol{list-style:none;margin:16px 0 0;padding:0;display:grid;gap:16px;counter-reset:s}
.uwaff-how li{position:relative;padding-left:40px;counter-increment:s}
.uwaff-how li:before{content:counter(s);position:absolute;left:0;top:0;width:28px;height:28px;border-radius:50%;background:var(--ink);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center}
.uwaff-how b{display:block}
.uwaff-how span{color:var(--muted)}
.uwaff-preview{position:sticky;top:48px}
.uwaff-product{padding:16px;border:1px solid var(--line);border-radius:12px;text-align:center}
.uwaff-thumb{display:flex;align-items:center;justify-content:center;aspect-ratio:16/10;margin-bottom:14px;border-radius:8px;background:linear-gradient(135deg,#DCE6FF,#FFE3D6)}
.uwaff-thumb .dashicons{width:48px;height:48px;font-size:48px;color:var(--ink)}
.uwaff-product h3{font-size:15px;margin-bottom:4px}
.uwaff-price{font-weight:700;margin-bottom:10px}
.uwaff-coupon{display:inline-block;margin-bottom:10px;padding:3px 10px;border:1px dashed var(--orange);border-radius:6px;font-size:12px}
.uwaff-coupon[hidden]{display:none}
.uwaff-pv-btn{display:block;padding:10px 14px;border-radius:8px;background:var(--ink);color:#fff;font-weight:600}
.uwaff-log{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.uwaff-log time{flex:none;min-width:96px;color:var(--muted);font-size:12px}
.uwaff-empty{padding:32px 0;text-align:center;color:var(--muted)}
.uwaff-toast{position:fixed;right:24px;bottom:24px;z-index:100000;max-width:360px;padding:12px 18px;border-radius:10px;background:#0F1B3D;color:#fff;font-weight:600;border-left:5px solid var(--mint);opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}
.uwaff-toast.is-err{border-color:var(--rose)}
.uwaff-toast.is-show{opacity:1;transform:none}
@media (max-width:960px){.uwaff-grid{grid-template-columns:1fr}.uwaff-stats{grid-template-columns:repeat(2,1fr)}.uwaff-preview{position:static}.uwaff-hero{padding:28px}}
@media (max-width:600px){.uwaff-ring{display:none}.uwaff-stats{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.uwaff *,.uwaff-toast{transition:none!important;animation:none!important}}
CSS;
    }

    private static function js() {
        return <<<'JS'
(function () {
  'use strict';
  var cfg = window.UWAFF || {}, i18n = cfg.i18n || {}, running = false, stopFlag = false;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function post(action, data) {
    var b = new URLSearchParams();
    b.append('action', action);
    b.append('nonce', cfg.nonce);
    Object.keys(data || {}).forEach(function (k) { b.append(k, data[k]); });
    return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: b })
      .then(function (r) { return r.json(); })
      .catch(function () { return { success: false, data: { message: i18n.network } }; });
  }

  function toast(msg, type) {
    var t = document.createElement('div');
    t.className = 'uwaff-toast' + (type === 'err' ? ' is-err' : '');
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-show'); });
    setTimeout(function () { t.classList.remove('is-show'); setTimeout(function () { t.remove(); }, 300); }, 4200);
  }

  function busy(btn, on) { if (btn) { btn.disabled = on; btn.classList.toggle('is-busy', on); } }

  function bind(s) {
    if (!s) return;
    Object.keys(s).forEach(function (k) {
      $$('[data-bind="' + k + '"]').forEach(function (el) { el.textContent = s[k]; });
    });
    var fg = $('.uwaff-ring-fg');
    if (fg) {
      var c = parseFloat(fg.getAttribute('stroke-dasharray'));
      fg.style.strokeDashoffset = c * (1 - s.percent / 100);
    }
    $$('.uwaff-bar span').forEach(function (b) { b.style.width = s.percent + '%'; });
    var input = $('#uwaff-page');
    if (input) input.value = s.next_page;
  }

  function addResult(msg, type) {
    var ul = $('#uwaff-results');
    if (!ul) return;
    var li = document.createElement('li');
    li.className = 'is-' + (type || 'ok');
    li.textContent = msg;
    ul.insertBefore(li, ul.firstChild);
  }

  /* Import */
  var importBtn = $('#uwaff-import'), allBtn = $('#uwaff-import-all'), stopBtn = $('#uwaff-stop');

  function lock(on) {
    running = on;
    [importBtn, allBtn, $('#uwaff-set')].forEach(function (b) { if (b) b.disabled = on; });
    if (stopBtn) stopBtn.hidden = !(on && stopBtn.dataset.all === '1');
  }

  function step() {
    return post('uwaff_import').then(function (res) {
      if (!res.success) {
        var m = (res.data && res.data.message) || i18n.network;
        addResult(m, 'err'); toast(m, 'err');
        return false;
      }
      bind(res.data.state);
      addResult(res.data.message, res.data.failed ? 'warn' : 'ok');
      return !res.data.done;
    });
  }

  function run(all) {
    if (running) return;
    stopFlag = false;
    stopBtn.dataset.all = all ? '1' : '0';
    lock(true);
    busy(all ? allBtn : importBtn, true);
    (function loop() {
      step().then(function (more) {
        if (all && more && !stopFlag) return loop();
        busy(all ? allBtn : importBtn, false);
        lock(false);
        if (all) toast(stopFlag ? i18n.stopped : i18n.finished);
        else { var last = $('#uwaff-results li'); if (last) toast(last.textContent, last.classList.contains('is-err') ? 'err' : 'ok'); }
      });
    })();
  }

  if (importBtn) importBtn.addEventListener('click', function () { run(false); });
  if (allBtn) allBtn.addEventListener('click', function () { run(true); });
  if (stopBtn) stopBtn.addEventListener('click', function () { stopFlag = true; stopBtn.disabled = true; });

  var setBtn = $('#uwaff-set');
  if (setBtn) setBtn.addEventListener('click', function () {
    busy(setBtn, true);
    post('uwaff_set_page', { page: $('#uwaff-page').value }).then(function (res) {
      busy(setBtn, false);
      if (res.success) { bind(res.data.state); toast(res.data.message); }
      else toast((res.data && res.data.message) || i18n.network, 'err');
    });
  });

  if (cfg.needsTotal && $('.uwaff-stats')) {
    post('uwaff_state').then(function (res) { if (res.success) bind(res.data.state); });
  }

  /* Settings */
  var reveal = $('#uwaff-reveal');
  if (reveal) reveal.addEventListener('click', function () {
    var p = $('#uwaff_password'), show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    reveal.textContent = show ? 'Hide' : 'Show';
  });

  var testBtn = $('#uwaff-test');
  if (testBtn) testBtn.addEventListener('click', function () {
    var out = $('#uwaff-test-result');
    out.className = ''; out.textContent = i18n.testing;
    busy(testBtn, true);
    post('uwaff_test', { username: $('#uwaff_username').value, password: $('#uwaff_password').value }).then(function (res) {
      busy(testBtn, false);
      out.className = res.success ? 'is-ok' : 'is-err';
      out.textContent = (res.data && res.data.message) || i18n.network;
    });
  });

  var btnIn = $('#uwaff_button_text'), cpIn = $('#uwaff_coupon_code');
  if (btnIn) btnIn.addEventListener('input', function () {
    $('#uwaff-pv-btn').textContent = btnIn.value.trim() || i18n.default_btn;
  });
  if (cpIn) cpIn.addEventListener('input', function () {
    var el = $('#uwaff-pv-coupon'), v = cpIn.value.trim();
    el.hidden = !v;
    var b = $('b', el); if (b) b.textContent = v;
  });

  /* Activity */
  var clear = $('#uwaff-clear');
  if (clear) clear.addEventListener('click', function () {
    busy(clear, true);
    post('uwaff_clear_log').then(function (res) {
      if (!res.success) { busy(clear, false); return; }
      var list = $('.uwaff-log');
      if (list) { var p = document.createElement('p'); p.className = 'uwaff-empty'; p.textContent = i18n.cleared; list.replaceWith(p); }
      clear.remove();
      toast(res.data.message);
    });
  });
})();
JS;
    }
}

Uwaff_Plugin::boot();
register_activation_hook(__FILE__, ['Uwaff_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Uwaff_Plugin', 'deactivate']);
