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
            <?php elseif ($notice === 'data_reset') :
                $reset_result = get_transient('hci_reset_result_' . get_current_user_id());
                delete_transient('hci_reset_result_' . get_current_user_id());
                $reset_result = is_array($reset_result) ? $reset_result : array();
                ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(self::describe_reset_result($reset_result)); ?></p></div>
                <script>
                (function () {
                    // ریست سمت سرور کامل شد؛ انتخاب‌های محلی مرورگر (localStorage
                    // صفحه محصولات) هم پاک می‌شود تا چک‌باکس‌های قدیمی/کش‌شده
                    // دوباره تیک‌خورده دیده نشوند.
                    try { localStorage.removeItem('hci_selection_v1_' + <?php echo (int) get_current_user_id(); ?>); } catch (e) {}
                })();
                </script>
            <?php elseif ($notice === 'delete_confirm_mismatch') : ?>
                <div class="notice notice-error is-dismissible"><p>کلمه تأیید را درست ننوشتید، برای همین هیچ‌چیزی پاک نشد. دوباره تلاش کنید.</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'connection', $base_url)); ?>" class="nav-tab <?php echo $tab === 'connection' ? 'nav-tab-active' : ''; ?>">اتصال</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'pricing', $base_url)); ?>" class="nav-tab <?php echo $tab === 'pricing' ? 'nav-tab-active' : ''; ?>">قیمت‌گذاری</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'reset', $base_url)); ?>" class="nav-tab <?php echo $tab === 'reset' ? 'nav-tab-active' : ''; ?>">پاک کردن اطلاعات</a>
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
                    <tr>
                        <th><label for="hci_apply_categories">دسته‌بندی‌های هی‌مد</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="hci_apply_categories" name="apply_categories" value="1" <?php checked(HCI_Import::should_apply_categories()); ?>>
                                دسته‌بندی‌های هی‌مد در سایت من ساخته و اعمال شود
                            </label>
                            <p style="color:#787c82;margin-top:4px">پیش‌فرض: بله. اگر خاموش کنید، هیچ دسته‌ای ساخته/اعمال نمی‌شود و محصولات Import‌شده دسته پیش‌فرض ووکامرس (Uncategorized) را می‌گیرند.</p>
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

    private const DELETE_CONFIRM_WORD = 'حذف';

    public static function describe_reset_result(array $r): string {
        $n = static fn ($v): string => HCI_Sync::to_persian_digits((string) (int) $v);
        $rows = (int) ($r['rows_cleared'] ?? 0);
        $queued = (int) ($r['queue_cleared'] ?? 0);
        $parts = array();
        if ($rows > 0) {
            $parts[] = $n($rows) . ' محصول ثبت‌شده پاک شد.';
        } else {
            $parts[] = 'محصول ثبت‌شده‌ای وجود نداشت که پاک شود.';
        }
        if ($queued > 0) {
            $parts[] = $n($queued) . ' محصولِ در انتظار وارد شدن هم لغو شد.';
        }
        if (isset($r['deleted_products'])) {
            $parts[] = $n($r['deleted_products']) . ' محصول و ' . $n($r['deleted_images'] ?? 0) . ' تصویر از فروشگاه شما حذف شد.';
        }
        return implode(' ', $parts);
    }

    private static function render_reset_tab(): void {
        ?>
        <h2>پاک کردن اطلاعات ثبت‌شده در این پلاگین</h2>
        <p>با این کار، فهرست محصولاتی که این پلاگین برای وارد‌کردن و بروزرسانی ثبت کرده پاک می‌شود، همین‌طور انتخاب‌های نیمه‌کاره و محصولاتی که در انتظار وارد شدن بودند.</p>
        <p>این کار <strong>خودِ محصولاتی که قبلاً در فروشگاه شما ساخته شده‌اند را حذف نمی‌کند.</strong> فقط یعنی پلاگین دیگر آن‌ها را «قبلاً وارد‌شده» نمی‌شناسد و ممکن است دوباره قابل وارد‌کردن باشند. تنظیمات اتصال و قیمت‌گذاری شما هم دست‌نخورده می‌ماند.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="hci-reset-form"
            onsubmit="return hciConfirmReset();">
            <input type="hidden" name="action" value="hci_reset_data">
            <?php wp_nonce_field('hci_reset_data'); ?>

            <div style="border:1px solid #d63638;border-radius:4px;padding:12px;margin:16px 0;max-width:640px">
                <label>
                    <input type="checkbox" id="hci-delete-products-checkbox" name="delete_products" value="1">
                    <strong>علاوه بر این، خود محصولاتی را هم که این پلاگین از هی‌مد وارد کرده (و تصاویرشان) حذف کن</strong>
                </label>
                <p style="margin:6px 0 0;color:#b32d2e"><strong>این کار قابل بازگشت نیست و آن محصولات کاملاً از فروشگاه شما حذف می‌شوند.</strong> محصولات دیگر فروشگاه شما هرگز حذف نمی‌شوند.</p>
                <p style="margin:10px 0 0">
                    <label for="hci-delete-confirm-text">برای تایید، کلمه «<strong><?php echo esc_html(self::DELETE_CONFIRM_WORD); ?></strong>» را در کادر زیر بنویسید.</label><br>
                    <input type="text" id="hci-delete-confirm-text" name="delete_confirm_text" class="regular-text" autocomplete="off">
                </p>
            </div>

            <button type="submit" class="button button-secondary" style="color:#a00;border-color:#a00">پاک کردن اطلاعات</button>
        </form>
        <script>
        function hciConfirmReset() {
            const deleteChecked = document.getElementById('hci-delete-products-checkbox').checked;
            if (deleteChecked) {
                const confirmText = document.getElementById('hci-delete-confirm-text').value.trim();
                if (confirmText !== <?php echo wp_json_encode(self::DELETE_CONFIRM_WORD); ?>) {
                    alert('برای حذف محصولات، کلمه تأیید را دقیقاً همان‌طور که نوشته شده در کادر بنویسید.');
                    return false;
                }
                return confirm('مطمئن هستید؟ محصولات وارد‌شده از هی‌مد برای همیشه از فروشگاه شما حذف می‌شوند و بازگشتی ندارد.');
            }
            return confirm('مطمئن هستید؟ فهرست محصولات ثبت‌شده در این پلاگین پاک می‌شود.');
        }
        </script>
        <?php
    }

    /**
     * منطق واقعی ریست — جدا از handle_reset_data() (که Nonce/Capability را
     * چک و در پایان Redirect+exit می‌کند) تا مستقل و بدون HTTP قابل تست باشد.
     * خروجی تعداد واقعی هر مورد پاک‌شده را برمی‌گرداند تا پیام موفقیت هرگز
     * یک جمله ثابت/گمراه‌کننده نباشد.
     */
    public static function reset_data(): array {
        // قبل از truncate شمرده می‌شود — بعد از آن دیگر قابل شمارش نیست.
        $queue_cleared = HCI_DB::count_queued_or_processing();
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

        $cache_cleared = HCI_Source_Client::clear_all_cache();
        $selection_cleared = self::clear_all_selection_transients();

        return array(
            'rows_cleared' => $rows_cleared,
            'queue_cleared' => $queue_cleared,
            'cache_cleared' => $cache_cleared,
            'selection_cleared' => $selection_cleared,
        );
    }

    /**
     * هیچ فهرست مرکزی‌ای از «کدام کاربران Selection ذخیره‌شده دارند» وجود
     * ندارد (Transient است، نه یک جدول)، پس همه کاربرهای واقعی سایت را
     * می‌گیریم و برای هر کدام کلید مخصوص همان کاربر را چک می‌کنیم — به‌جای
     * یک Query خام روی wp_options که با ساختار داخلی Transient/Object Cache
     * (که ممکن است اصلاً SQL نباشد) شکننده می‌شد.
     */
    private static function clear_all_selection_transients(): int {
        if (!function_exists('get_users')) {
            return 0;
        }
        $user_ids = (array) get_users(array('fields' => 'ID'));
        $cleared = 0;
        foreach ($user_ids as $user_id) {
            $key = HCI_Products::SELECTION_TRANSIENT_PREFIX . (int) $user_id;
            if (get_transient($key) !== false) {
                delete_transient($key);
                $cleared++;
            }
        }
        return $cleared;
    }

    /**
     * گزینه اختیاری/پیش‌فرض‌خاموش: فقط محصولاتی که واقعاً این پلاگین ساخته
     * (با متای _hci_source_product_id، یا برای رکوردهای قدیمی‌تر از قبل از
     * اضافه‌شدن این Meta، از طریق dest_product_id جدول ردیابی) و فقط
     * تصاویری که همین پلاگین دانلود کرده (متای _hci_imported) حذف می‌شوند.
     * باید قبل از reset_data()/truncate_product_map() صدا زده شود، وگرنه
     * dest_product_id ردیف‌های قدیمی از دست می‌رود.
     */
    public static function delete_plugin_created_products(): array {
        $meta_ids = array();
        if (function_exists('get_posts')) {
            $meta_ids = array_map('intval', (array) get_posts(array(
                'post_type' => 'product',
                'meta_key' => HCI_Import::SOURCE_PRODUCT_META_KEY,
                'posts_per_page' => -1,
                'fields' => 'ids',
            )));
        }
        $legacy_ids = HCI_DB::get_all_dest_product_ids();
        $all_ids = array_values(array_unique(array_merge($meta_ids, $legacy_ids)));

        $deleted_products = 0;
        $deleted_images = 0;

        foreach ($all_ids as $product_id) {
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $image_ids = array_values(array_unique(array_filter(array_map('intval', array_merge(
                        array($product->get_image_id()),
                        (array) $product->get_gallery_image_ids()
                    )))));
                    foreach ($image_ids as $image_id) {
                        // فقط تصویری که خودِ پلاگین دانلود کرده حذف می‌شود —
                        // نه تصویری که کارمند دستی از رسانه انتخاب کرده بود.
                        if (get_post_meta($image_id, HCI_Import::IMAGE_META_KEY, true)) {
                            if (function_exists('wp_delete_attachment') && wp_delete_attachment($image_id, true)) {
                                $deleted_images++;
                            }
                        }
                    }
                }
            }
            if (function_exists('wp_delete_post') && wp_delete_post($product_id, true)) {
                $deleted_products++;
            }
        }

        return array('deleted_products' => $deleted_products, 'deleted_images' => $deleted_images);
    }

    public static function handle_reset_data(): void {
        self::guard('hci_reset_data');

        $want_delete_products = !empty($_POST['delete_products']);
        $delete_result = null;

        if ($want_delete_products) {
            $confirm_text = isset($_POST['delete_confirm_text']) ? trim((string) wp_unslash($_POST['delete_confirm_text'])) : '';
            if ($confirm_text !== self::DELETE_CONFIRM_WORD) {
                wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer', 'tab' => 'reset', 'hci_notice' => 'delete_confirm_mismatch'), admin_url('admin.php')));
                exit;
            }
            // قبل از reset_data() (که truncate هم می‌کند) صدا زده می‌شود، وگرنه
            // dest_product_id رکوردهای قدیمی‌تر بدون Meta از دست می‌رود.
            $delete_result = self::delete_plugin_created_products();
        }

        $result = self::reset_data();
        if ($delete_result !== null) {
            $result = array_merge($result, $delete_result);
        }

        set_transient('hci_reset_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);
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
        update_option('hci_apply_categories', !empty($_POST['apply_categories']), false);
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
