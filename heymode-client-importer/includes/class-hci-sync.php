<?php

defined('ABSPATH') || exit;

final class HCI_Sync {
    private const CAPABILITY = 'manage_woocommerce';
    public const DAILY_HOOK = 'hci_daily_sync_run';
    private const GROUP = 'hci-sync';
    private const CURSOR_OPTION = 'hci_sync_cursor_gmt';
    private const RETRY_COUNT_OPTION = 'hci_sync_retry_count';
    private const SUMMARY_OPTION = 'hci_last_sync_summary';
    private const TIMEZONE = 'Asia/Tehran';

    public static function init(): void {
        add_action('init', array(__CLASS__, 'ensure_scheduled'));
        add_action(self::DAILY_HOOK, array(__CLASS__, 'run_daily_sync'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 20);
        add_action('admin_post_hci_sync_now', array(__CLASS__, 'handle_sync_now'));
    }

    public static function ensure_scheduled(): void {
        if (!function_exists('as_schedule_recurring_action') || !function_exists('as_next_scheduled_action')) {
            return;
        }
        if (as_next_scheduled_action(self::DAILY_HOOK, array(), self::GROUP)) {
            return;
        }
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTimeImmutable('now', $tz);
        $next = $now->setTime(6, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        as_schedule_recurring_action($next->getTimestamp(), DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP);
    }

    public static function admin_menu(): void {
        add_submenu_page('heymode-client-importer', 'سینک روزانه', 'سینک روزانه', self::CAPABILITY, 'heymode-client-importer-sync', array(__CLASS__, 'render_page'));
    }

    public static function handle_sync_now(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }
        check_admin_referer('hci_sync_now');
        self::run_daily_sync(true);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-client-importer-sync', 'hci_notice' => 'synced'), admin_url('admin.php')));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }
        $notice = isset($_GET['hci_notice']) ? sanitize_key(wp_unslash($_GET['hci_notice'])) : '';
        $cursor = (string) get_option(self::CURSOR_OPTION, '');
        $summary = get_option(self::SUMMARY_OPTION, null);
        $next_run = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::DAILY_HOOK, array(), self::GROUP) : false;
        ?>
        <div class="wrap">
            <h1>سینک روزانه قیمت/موجودی</h1>

            <?php if ($notice === 'synced') : ?>
                <div class="notice notice-success is-dismissible"><p>سینک دستی اجرا شد — نتیجه در گزارش پایین صفحه.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:900px;margin-bottom:20px">
                <tbody>
                    <tr><td style="width:220px"><strong>آخرین Cursor موفق</strong></td><td><?php echo $cursor !== '' ? esc_html($cursor) . ' UTC' : 'هنوز سینک موفقی انجام نشده'; ?></td></tr>
                    <tr><td><strong>اجرای بعدی برنامه‌ریزی‌شده</strong></td><td><?php echo $next_run ? esc_html(wp_date('Y-m-d H:i:s', $next_run, new DateTimeZone(self::TIMEZONE))) . ' (تهران)' : 'زمان‌بندی نشده (Action Scheduler در دسترس نیست؟)'; ?></td></tr>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hci_sync_now">
                <?php wp_nonce_field('hci_sync_now'); ?>
                <button type="submit" class="button button-primary">Sync Now</button>
            </form>

            <h2>آخرین گزارش سینک</h2>
            <?php if (is_array($summary)) : ?>
                <table class="widefat striped" style="max-width:900px">
                    <tbody>
                        <tr><td style="width:220px"><strong>زمان</strong></td><td><?php echo esc_html((string) ($summary['started_at'] ?? '')); ?> UTC</td></tr>
                        <tr><td><strong>نوع اجرا</strong></td><td><?php echo !empty($summary['manual']) ? 'دستی (Sync Now)' : 'خودکار (زمان‌بندی‌شده)'; ?></td></tr>
                        <tr><td><strong>وضعیت</strong></td><td><?php echo !empty($summary['success']) ? '<span style="color:#008a20">موفق</span>' : '<span style="color:#d63638">ناموفق</span>'; ?></td></tr>
                        <tr><td><strong>بررسی‌شده</strong></td><td><?php echo esc_html((string) ($summary['checked'] ?? 0)); ?></td></tr>
                        <tr><td><strong>قیمت به‌روزشده</strong></td><td><?php echo esc_html((string) ($summary['price_updated'] ?? 0)); ?></td></tr>
                        <tr><td><strong>موجودی به‌روزشده</strong></td><td><?php echo esc_html((string) ($summary['stock_updated'] ?? 0)); ?></td></tr>
                        <tr><td><strong>Out of Stock شده (منبع غیرفعال)</strong></td><td><?php echo esc_html((string) ($summary['deactivated'] ?? 0)); ?></td></tr>
                        <tr><td><strong>خطاها</strong></td><td><?php echo esc_html((string) ($summary['errors'] ?? 0)); ?></td></tr>
                        <?php if (!empty($summary['message'])) : ?>
                            <tr><td><strong>پیام</strong></td><td><?php echo esc_html((string) $summary['message']); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>هنوز سینکی اجرا نشده.</p>
            <?php endif; ?>

            <h2>راه‌اندازی Cron واقعی سرور (اختیاری، برای سایت‌های کم‌بازدید)</h2>
            <p>Action Scheduler خودش با بازدید کاربر (Pseudo-Cron وردپرس) اجرا می‌شود؛ اگر سایت بازدید کمی دارد، یک Cron واقعی سرور برای اجرای دستور WP-CLI بگذارید — جزئیات در README پلاگین:</p>
            <pre style="background:#1e1e1e;color:#fff;padding:12px;max-width:900px;overflow:auto">0 6 * * * cd /path/to/wordpress && wp hci sync --quiet</pre>
        </div>
        <?php
    }

    /**
     * موتور اصلی سینک — چه با Cron روزانه صدا زده شود چه دستی (Sync Now).
     * فقط قیمت/موجودی محصولات از قبل Import‌شده (طبق wp_hci_product_map) را
     * به‌روز می‌کند؛ هیچ محصول جدیدی هرگز اینجا ساخته نمی‌شود.
     */
    public static function run_daily_sync(bool $manual = false): array {
        // Cursor از «قبل از شروع درخواست» گرفته می‌شود، نه بعد از پایان —
        // وگرنه تغییراتی که دقیقاً حین اجرای این درخواست در منبع رخ می‌دهند
        // (بین لحظه‌ی شروع و پایان) هرگز در Delta بعدی نمی‌آمدند.
        $request_started_at = current_time('mysql', true);
        $cursor = (string) get_option(self::CURSOR_OPTION, '');
        if ($cursor === '') {
            $cursor = '1970-01-01 00:00:00';
        }

        $result = HCI_Source_Client::get_delta_products($cursor);

        if (!$result['success']) {
            self::schedule_retry();
            return self::finish(array(
                'started_at' => $request_started_at,
                'manual' => $manual,
                'success' => false,
                'checked' => 0,
                'price_updated' => 0,
                'stock_updated' => 0,
                'deactivated' => 0,
                'errors' => 1,
                'message' => 'دریافت تغییرات از منبع ناموفق بود: ' . ($result['message'] ?? ''),
            ), false);
        }

        $imported_parents = HCI_DB::get_imported_parent_rows();
        $parent_by_source_id = array();
        foreach ($imported_parents as $prow) {
            $parent_by_source_id[(int) $prow['source_product_id']] = $prow;
        }
        $imported_variations = HCI_DB::get_imported_variation_rows_for_parents(array_keys($parent_by_source_id));

        $checked = 0;
        $price_updated = 0;
        $stock_updated = 0;
        $deactivated = 0;
        $errors = 0;

        foreach ((array) $result['items'] as $item) {
            $source_id = (int) ($item['source_product_id'] ?? 0);
            $parent_row = $parent_by_source_id[$source_id] ?? null;

            if ($parent_row) {
                $checked++;
                try {
                    $outcome = self::apply_item_update((int) $parent_row['dest_product_id'], $item);
                    $price_updated += $outcome['price_updated'] ? 1 : 0;
                    $stock_updated += $outcome['stock_updated'] ? 1 : 0;
                    $deactivated += $outcome['deactivated'] ? 1 : 0;
                } catch (Throwable $e) {
                    $errors++;
                }
            }

            $variation_rows = $imported_variations[$source_id] ?? array();
            if (!$variation_rows || empty($item['variations'])) {
                continue;
            }
            $variation_row_by_id = array();
            foreach ($variation_rows as $vrow) {
                $variation_row_by_id[(int) $vrow['source_variation_id']] = $vrow;
            }
            foreach ((array) $item['variations'] as $variation_item) {
                $source_variation_id = (int) ($variation_item['variation_id'] ?? 0);
                $vrow = $variation_row_by_id[$source_variation_id] ?? null;
                if (!$vrow) {
                    continue;
                }
                $checked++;
                try {
                    $outcome = self::apply_item_update((int) $vrow['dest_variation_id'], $variation_item);
                    $price_updated += $outcome['price_updated'] ? 1 : 0;
                    $stock_updated += $outcome['stock_updated'] ? 1 : 0;
                    $deactivated += $outcome['deactivated'] ? 1 : 0;
                } catch (Throwable $e) {
                    $errors++;
                }
            }
        }

        update_option(self::CURSOR_OPTION, $request_started_at, false);

        return self::finish(array(
            'started_at' => $request_started_at,
            'manual' => $manual,
            'success' => true,
            'checked' => $checked,
            'price_updated' => $price_updated,
            'stock_updated' => $stock_updated,
            'deactivated' => $deactivated,
            'errors' => $errors,
            'message' => '',
        ), true);
    }

    private static function finish(array $summary, bool $success): array {
        update_option(self::RETRY_COUNT_OPTION, $success ? 0 : (int) get_option(self::RETRY_COUNT_OPTION, 0), false);
        update_option(self::SUMMARY_OPTION, $summary, false);
        return $summary;
    }

    /**
     * ۰۶:۰۰ (یا هر لحظه‌ای که Sync دستی/خودکار fail شود) → ۵ دقیقه بعد →
     * ۱۰ دقیقه‌ی دیگر (یعنی ۱۵ دقیقه از تلاش اول) → تسلیم تا اجرای بعدیِ
     * برنامه‌ریزی‌شده. Cursor در مسیر شکست اصلاً لمس نمی‌شود (بالاتر، فقط در
     * مسیر موفقیت update_option می‌شود)، پس این عملیات کاملاً Idempotent است.
     */
    private static function schedule_retry(): void {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        $retry_count = (int) get_option(self::RETRY_COUNT_OPTION, 0);
        if ($retry_count >= 2) {
            return;
        }
        $delay = $retry_count === 0 ? (5 * MINUTE_IN_SECONDS) : (10 * MINUTE_IN_SECONDS);
        update_option(self::RETRY_COUNT_OPTION, $retry_count + 1, false);
        as_schedule_single_action(time() + $delay, self::DAILY_HOOK, array(), self::GROUP);
    }

    /**
     * فقط Price/Stock را روی یک محصول/Variation مقصد به‌روز می‌کند — هرگز
     * نام/توضیح/تصویر/دسته‌بندی. اگر is_active=false باشد (محصول در منبع
     * غیرفعال/حذف/Draft شده)، فقط Out of Stock می‌شود و قیمت اصلاً لمس
     * نمی‌شود. فقط وقتی مقدار واقعاً تغییر کرده save() صدا زده می‌شود.
     */
    private static function apply_item_update(int $dest_id, array $item): array {
        $outcome = array('price_updated' => false, 'stock_updated' => false, 'deactivated' => false);
        if ($dest_id <= 0) {
            return $outcome;
        }
        $product = wc_get_product($dest_id);
        if (!$product) {
            return $outcome;
        }

        $is_active = array_key_exists('is_active', $item) ? (bool) $item['is_active'] : true;
        if (!$is_active) {
            if ($product->get_stock_status() !== 'outofstock') {
                $product->set_stock_status('outofstock');
                $product->save();
                $outcome['deactivated'] = true;
            }
            return $outcome;
        }

        $changed = false;

        if (array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '') {
            $new_price = (string) HCI_Pricing::resolve_price((float) $item['price']);
            if ((string) $product->get_regular_price() !== $new_price) {
                $product->set_regular_price($new_price);
                $product->set_price($new_price);
                $changed = true;
                $outcome['price_updated'] = true;
            }
        }

        if (array_key_exists('stock_quantity', $item)) {
            $new_quantity = $item['stock_quantity'] !== null ? (float) $item['stock_quantity'] : 0.0;
            if ((float) $product->get_stock_quantity() !== $new_quantity) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($new_quantity);
                $product->set_stock_status($new_quantity > 0 ? 'instock' : 'outofstock');
                $changed = true;
                $outcome['stock_updated'] = true;
            }
        }

        if ($changed) {
            $product->save();
        }

        return $outcome;
    }
}
