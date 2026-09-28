<?php

defined('ABSPATH') || exit;

final class HCI_Admin {
    private const CAPABILITY = 'manage_woocommerce';
    private const API_URL_OPTION = 'hci_api_url';
    private const API_KEY_OPTION = 'hci_api_key';

    public static function requirements_met(): bool {
        return PHP_VERSION_ID >= 80100 && self::woocommerce_active();
    }

    private static function woocommerce_active(): bool {
        if (class_exists('WooCommerce')) {
            return true;
        }
        $active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $active_plugins = array_merge(
                $active_plugins,
                array_keys((array) get_site_option('active_sitewide_plugins', array()))
            );
        }
        return in_array('woocommerce/woocommerce.php', $active_plugins, true);
    }

    public static function activate(): void {
        if (!self::requirements_met()) {
            deactivate_plugins(plugin_basename(HCI_FILE));
            wp_die(
                esc_html__('این پلاگین نیاز به فعال بودن WooCommerce و PHP نسخه 8.1 یا بالاتر دارد.', 'heymode-client-importer'),
                esc_html__('فعال‌سازی ناموفق بود', 'heymode-client-importer'),
                array('back_link' => true)
            );
        }
        HCI_DB::create_tables();
    }

    public static function deactivate(): void {
        // فعلاً چیزی برای پاک‌سازی (Cron/Lock) وجود ندارد؛ Import/Cron در فاز ۲/۳ اضافه می‌شود.
    }

    public static function requirements_notice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p>' .
            esc_html__('پلاگین «HeyMode Client Importer» غیرفعال است چون WooCommerce فعال نیست یا نسخه PHP کمتر از 8.1 است.', 'heymode-client-importer') .
            '</p></div>';
    }

    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_hci_save_connection', array(__CLASS__, 'handle_save_connection'));
        add_action('admin_post_hci_test_connection', array(__CLASS__, 'handle_test_connection'));
        add_action('admin_post_hci_save_pricing', array(__CLASS__, 'handle_save_pricing'));
        add_action('admin_post_hci_reset_data', array(__CLASS__, 'handle_reset_data'));
    }

    public static function admin_menu(): void {
        add_menu_page(
            'HeyMode Import',
            'HeyMode Import',
            self::CAPABILITY,
            'heymode-client-importer',
            array(__CLASS__, 'render_page'),
            'dashicons-download',
            58
        );
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
        if (!in_array($tab, array('connection', 'pricing', 'reset'), true)) {
            $tab = 'connection';
        }

        $base_url = admin_url('admin.php?page=heymode-client-importer');
        $notice = isset($_GET['hci_notice']) ? sanitize_key(wp_unslash($_GET['hci_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>HeyMode Client Importer — v<?php echo esc_html(HCI_VERSION); ?></h1>

            <?php if ($notice === 'connection_saved') : ?>
                <div class="notice notice-success is-dismissible"><p>تنظیمات اتصال ذخیره شد.</p></div>
            <?php elseif ($notice === 'connection_ok') : ?>
                <div class="notice notice-success is-dismissible"><p>تست اتصال موفق بود.</p></div>
            <?php elseif ($notice === 'connection_failed') : ?>
                <div class="notice notice-error is-dismissible"><p>تست اتصال ناموفق بود. جزئیات: <?php echo esc_html((string) get_transient('hci_last_test_message')); ?></p></div>
            <?php elseif ($notice === 'pricing_saved') : ?>
                <div class="notice notice-success is-dismissible"><p>تنظیمات قیمت‌گذاری ذخیره شد.</p></div>
            <?php elseif ($notice === 'data_reset') : ?>
                <div class="notice notice-success is-dismissible"><p>داده‌ها ریست شدند: جدول ردیابی، صف‌های Action Scheduler و کش محصولات کاملاً پاک شدند.</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'connection', $base_url)); ?>" class="nav-tab <?php echo $tab === 'connection' ? 'nav-tab-active' : ''; ?>">اتصال</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'pricing', $base_url)); ?>" class="nav-tab <?php echo $tab === 'pricing' ? 'nav-tab-active' : ''; ?>">قیمت‌گذاری</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'reset', $base_url)); ?>" class="nav-tab <?php echo $tab === 'reset' ? 'nav-tab-active' : ''; ?>">ریست داده‌ها</a>
            </h2>

            <div style="max-width:900px;margin-top:20px">
                <?php
                if ($tab === 'pricing') {
                    self::render_pricing_tab();
                } elseif ($tab === 'reset') {
                    self::render_reset_tab();
                } else {
                    self::render_connection_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function render_connection_tab(): void {
        $api_url = (string) get_option(self::API_URL_OPTION, '');
        $api_key = (string) get_option(self::API_KEY_OPTION, '');
        ?>
        <h2>اتصال به API هی‌مد Wholesale</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hci_save_connection">
            <?php wp_nonce_field('hci_save_connection'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><label for="hci_api_url">API URL</label></th>
                        <td>
                            <input type="url" id="hci_api_url" name="api_url" class="regular-text" style="width:100%;max-width:520px"
                                placeholder="https://source-site.example.com/wp-json/hmw/v1"
                                value="<?php echo esc_attr($api_url); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hci_api_key">API Key</label></th>
                        <td>
                            <input type="password" id="hci_api_key" name="api_key" class="regular-text" style="width:100%;max-width:520px"
                                autocomplete="off"
                                value="<?php echo esc_attr($api_key); ?>">
                        </td>
                    </tr>
                </tbody>
            </table>
            <p><button type="submit" class="button button-primary">ذخیره تنظیمات اتصال</button></p>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hci_test_connection">
            <?php wp_nonce_field('hci_test_connection'); ?>
            <button type="submit" class="button">تست اتصال</button>
        </form>
        <?php
    }

    private static function render_pricing_tab(): void {
        $fixed_amount = HCI_Pricing::get_fixed_amount();
        $percent = HCI_Pricing::get_percent();
        $default_status = HCI_Pricing::get_default_post_status();
        ?>
        <h2>فرمول قیمت‌گذاری</h2>
        <p>فرمول: <code>final_price = (hmp_price + مبلغ_ثابت) × (1 + درصد / 100)</code></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hci_save_pricing">
            <?php wp_nonce_field('hci_save_pricing'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><label for="hci_fixed_amount">مبلغ ثابت (تومان)</label></th>
                        <td><input type="number" step="any" id="hci_fixed_amount" name="fixed_amount" class="regular-text" value="<?php echo esc_attr((string) $fixed_amount); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="hci_percent">درصد افزایش (%)</label></th>
                        <td><input type="number" step="any" id="hci_percent" name="percent" class="regular-text" value="<?php echo esc_attr((string) $percent); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="hci_default_status">وضعیت پیش‌فرض بعد از Import</label></th>
                        <td>
                            <select id="hci_default_status" name="default_status">
                                <option value="draft" <?php selected($default_status, 'draft'); ?>>Draft</option>
                                <option value="publish" <?php selected($default_status, 'publish'); ?>>Publish</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3>پیش‌نمایش زنده</h3>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><label for="hci_sample_price">قیمت پایه نمونه (hmp_price)</label></th>
                        <td><input type="number" step="any" id="hci_sample_price" value="100000" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>قیمت نهایی</th>
                        <td><strong id="hci_preview_result">-</strong></td>
                    </tr>
                </tbody>
            </table>

            <p><button type="submit" class="button button-primary">ذخیره تنظیمات قیمت‌گذاری</button></p>
        </form>

        <script>
        (function () {
            const fixedInput = document.getElementById('hci_fixed_amount');
            const percentInput = document.getElementById('hci_percent');
            const sampleInput = document.getElementById('hci_sample_price');
            const result = document.getElementById('hci_preview_result');

            function recalc() {
                const fixed = parseFloat(fixedInput.value) || 0;
                const percent = parseFloat(percentInput.value) || 0;
                const sample = parseFloat(sampleInput.value) || 0;
                const finalPrice = (sample + fixed) * (1 + (percent / 100));
                result.textContent = finalPrice.toLocaleString('fa-IR', { maximumFractionDigits: 2 });
            }

            [fixedInput, percentInput, sampleInput].forEach(function (el) {
                el.addEventListener('input', recalc);
            });
            recalc();
        })();
        </script>
        <?php
    }

    private static function render_reset_tab(): void {
        ?>
        <h2>ریست داده‌ها</h2>
        <p>این عملیات موارد زیر را کاملاً پاک می‌کند:</p>
        <ul style="list-style:disc;padding-right:20px">
            <li>جدول ردیابی Import (<code>wp_hci_product_map</code>) — همه رکوردهای Imported/Duplicate/Partial/Error/در صف.</li>
            <li>صف‌های Action Scheduler این پلاگین — هم Importهای در حال انتظار، هم زمان‌بندی سینک روزانه (که بلافاصله دوباره خودکار زمان‌بندی می‌شود).</li>
            <li>کش محصولات منبع (صفحه محصولات، شامل هر حالت نیمه‌کاره ناشی از Rate Limit).</li>
        </ul>
        <p><strong>توجه:</strong> این کار هیچ محصولی را از ووکامرس حذف نمی‌کند و روی محصولات از قبل Import‌شده در سایت اثری ندارد — فقط ردیابی/صف/کش این پلاگین پاک می‌شود؛ یعنی بعد از ریست، محصولات قبلاً Import‌شده دیگر به‌عنوان «قبلاً Import‌شده» شناخته نمی‌شوند و سینک روزانه دیگر آن‌ها را به‌روز نمی‌کند تا دوباره از صفحه محصولات Import شوند. تنظیمات اتصال/قیمت‌گذاری و Cursor سینک دست‌نخورده می‌مانند.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('مطمئنید؟ این کار جدول ردیابی، صف‌های Import/سینک، و کش محصولات را کاملاً پاک می‌کند و برگشت‌پذیر نیست.');">
            <input type="hidden" name="action" value="hci_reset_data">
            <?php wp_nonce_field('hci_reset_data'); ?>
            <button type="submit" class="button button-secondary" style="color:#a00;border-color:#a00">ریست داده‌ها</button>
        </form>
        <?php
    }

    /**
     * منطق واقعی ریست — جدا از handle_reset_data() (که Nonce/Capability را
     * چک و در پایان Redirect+exit می‌کند) تا مستقل و بدون HTTP قابل تست باشد.
     */
    public static function reset_data(): array {
        $rows_cleared = HCI_DB::truncate_product_map();

        if (function_exists('as_unschedule_all_actions')) {
            // فقط با Hook (بدون args/group) صدا زده می‌شود تا همه نمونه‌های
            // زمان‌بندی‌شده آن Hook — صرف‌نظر از آرگومان‌هایشان — پاک شوند؛
            // چون هر دو Hook کاملاً مختص این پلاگین‌اند، اثری روی Action
            // Scheduler سایر پلاگین‌ها/ووکامرس ندارد. زمان‌بندی روزانه سینک
            // بلافاصله در اجرای بعدی init() دوباره خودش را می‌سازد.
            as_unschedule_all_actions(HCI_Import::ACTION_HOOK);
            as_unschedule_all_actions(HCI_Sync::DAILY_HOOK);
        }
        HCI_Sync::reset_retry_state();

        HCI_Source_Client::clear_all_cache();

        return array('rows_cleared' => $rows_cleared);
    }

    public static function handle_reset_data(): void {
        self::guard('hci_reset_data');
        self::reset_data();
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer', 'tab' => 'reset', 'hci_notice' => 'data_reset'), admin_url('admin.php')));
        exit;
    }

    public static function handle_save_connection(): void {
        self::guard('hci_save_connection');
        $api_url = isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '';
        $api_key = isset($_POST['api_key']) ? trim((string) wp_unslash($_POST['api_key'])) : '';
        update_option(self::API_URL_OPTION, $api_url, false);
        update_option(self::API_KEY_OPTION, $api_key, false);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer', 'tab' => 'connection', 'hci_notice' => 'connection_saved'), admin_url('admin.php')));
        exit;
    }

    public static function handle_test_connection(): void {
        self::guard('hci_test_connection');
        $api_url = (string) get_option(self::API_URL_OPTION, '');
        $api_key = (string) get_option(self::API_KEY_OPTION, '');
        $result = HCI_Source_Client::test_connection($api_url, $api_key);
        set_transient('hci_last_test_message', $result['message'] ?? '', 60);
        $notice = !empty($result['success']) ? 'connection_ok' : 'connection_failed';
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer', 'tab' => 'connection', 'hci_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    public static function handle_save_pricing(): void {
        self::guard('hci_save_pricing');
        $fixed_amount = isset($_POST['fixed_amount']) ? (float) $_POST['fixed_amount'] : 0.0;
        $percent = isset($_POST['percent']) ? (float) $_POST['percent'] : 0.0;
        $default_status = isset($_POST['default_status']) ? sanitize_key(wp_unslash($_POST['default_status'])) : 'draft';
        HCI_Pricing::save($fixed_amount, $percent, $default_status);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer', 'tab' => 'pricing', 'hci_notice' => 'pricing_saved'), admin_url('admin.php')));
        exit;
    }

    private static function guard(string $nonce_action): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }
        check_admin_referer($nonce_action);
    }
}
