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
        add_submenu_page('heymode-client-importer', 'بروزرسانی قیمت و موجودی', 'بروزرسانی قیمت و موجودی', self::CAPABILITY, 'heymode-client-importer-sync', array(__CLASS__, 'render_page'));
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

    public static function to_persian_digits(string $text): string {
        return strtr($text, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    /**
     * زمان را به فارسی روزمره و به وقت تهران می‌نویسد: «امروز ساعت ۰۹:۰۱»،
     * «دیروز ساعت ۰۶:۰۰»، «فردا ساعت ۰۶:۰۰» یا تاریخ ساده برای روزهای دیگر.
     */
    public static function format_friendly_time(int $timestamp, ?int $now = null): string {
        $tz = new DateTimeZone(self::TIMEZONE);
        $moment = (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        $today = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->setTime(0, 0, 0);
        $day = $moment->setTime(0, 0, 0);
        $diff_days = (int) round(($day->getTimestamp() - $today->getTimestamp()) / DAY_IN_SECONDS);
        $clock = self::to_persian_digits($moment->format('H:i'));

        if ($diff_days === 0) {
            return 'امروز ساعت ' . $clock;
        }
        if ($diff_days === -1) {
            return 'دیروز ساعت ' . $clock;
        }
        if ($diff_days === 1) {
            return 'فردا ساعت ' . $clock;
        }
        return self::to_persian_digits($moment->format('d/m/Y')) . ' ساعت ' . $clock;
    }

    /**
     * خلاصه آخرین بروزرسانی به جمله‌های ساده (بدون اصطلاح فنی).
     * @return string[]
     */
    public static function describe_changes(?array $summary): array {
        if (!is_array($summary)) {
            return array('هنوز بروزرسانی‌ای انجام نشده است.');
        }
        if (empty($summary['success'])) {
            return array('آخرین تلاش برای بروزرسانی انجام نشد. برنامه چند دقیقه بعد خودکار دوباره تلاش می‌کند و تا آن موقع قیمت و موجودی فروشگاه شما دست‌نخورده می‌ماند.');
        }
        $lines = array();
        $price = (int) ($summary['price_updated'] ?? 0);
        $stock = (int) ($summary['stock_updated'] ?? 0);
        $gone = (int) ($summary['deactivated'] ?? 0);
        $errors = (int) ($summary['errors'] ?? 0);
        if ($price > 0) {
            $lines[] = self::to_persian_digits((string) $price) . ' محصول قیمتشان تغییر کرد.';
        }
        if ($stock > 0) {
            $lines[] = self::to_persian_digits((string) $stock) . ' محصول موجودی‌اش تغییر کرد.';
        }
        if ($gone > 0) {
            $lines[] = self::to_persian_digits((string) $gone) . ' محصول دیگر در هی‌مد فروخته نمی‌شود و در فروشگاه شما ناموجود شد.';
        }
        if ($errors > 0) {
            $lines[] = self::to_persian_digits((string) $errors) . ' محصول به‌روز نشد؛ در بروزرسانی بعدی دوباره امتحان می‌شود.';
        }
        if (!$lines) {
            $lines[] = 'در آخرین بروزرسانی چیزی برای تغییر نبود.';
        }
        return $lines;
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }
        $notice = isset($_GET['hci_notice']) ? sanitize_key(wp_unslash($_GET['hci_notice'])) : '';
        $cursor = (string) get_option(self::CURSOR_OPTION, '');
        $summary = get_option(self::SUMMARY_OPTION, null);
        $next_run = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::DAILY_HOOK, array(), self::GROUP) : false;
        $last_ts = $cursor !== '' ? strtotime($cursor . ' UTC') : false;
        $imported_count = count(HCI_DB::get_imported_parent_rows());
        ?>
        <div class="wrap">
            <h1>بروزرسانی قیمت و موجودی</h1>
            <p>هر روز صبح ساعت ۶، قیمت و موجودی محصولاتی که از هی‌مد وارد کرده‌اید خودکار با هی‌مد هماهنگ می‌شود.</p>

            <?php if ($notice === 'synced') : ?>
                <?php $ok = is_array($summary) && !empty($summary['success']); ?>
                <div class="notice notice-<?php echo $ok ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo $ok ? 'بروزرسانی انجام شد.' : 'بروزرسانی انجام نشد. کمی بعد دوباره تلاش کنید.'; ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px;max-width:640px;margin:16px 0">
                <p style="margin:0;color:#50575e">تعداد محصولات وارد‌شده از هی‌مد</p>
                <p style="margin:4px 0 16px;font-size:44px;font-weight:700;line-height:1"><?php echo esc_html(self::to_persian_digits((string) $imported_count)); ?></p>
                <p style="margin:4px 0"><strong>آخرین بروزرسانی:</strong> <?php echo $last_ts ? esc_html(self::format_friendly_time((int) $last_ts)) : 'هنوز انجام نشده'; ?></p>
                <p style="margin:4px 0"><strong>بروزرسانی بعدی:</strong> <?php echo $next_run ? esc_html(self::format_friendly_time((int) $next_run)) : 'هنوز زمان‌بندی نشده'; ?></p>
            </div>

            <h2 style="margin-top:24px">در آخرین بروزرسانی چه شد؟</h2>
            <ul style="list-style:disc;padding-right:20px">
                <?php foreach (self::describe_changes(is_array($summary) ? $summary : null) as $line) : ?>
                    <li><?php echo esc_html($line); ?></li>
                <?php endforeach; ?>
            </ul>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="hci-sync-form" style="margin-top:20px">
                <input type="hidden" name="action" value="hci_sync_now">
                <?php wp_nonce_field('hci_sync_now'); ?>
                <button type="submit" class="button button-primary" id="hci-sync-btn">بروزرسانی همین الان</button>
                <span id="hci-sync-msg" style="margin-right:10px;color:#50575e"></span>
            </form>
            <script>
            document.getElementById('hci-sync-form').addEventListener('submit', function () {
                var b = document.getElementById('hci-sync-btn');
                b.disabled = true;
                document.getElementById('hci-sync-msg').textContent = 'در حال بروزرسانی...';
                setTimeout(function () { b.disabled = false; }, 60000);
            });
            </script>

            <p style="margin-top:40px"><a href="#" id="hci-adv-link" style="font-size:11px;color:#a7aaad;text-decoration:none">تنظیمات پیشرفته</a></p>
            <div id="hci-adv-box" style="display:none;max-width:640px;font-size:12px;color:#50575e">
                <p>این بخش فقط برای پشتیبان فنی است. جزئیات (زمان دقیق به وقت جهانی، اجرای خودکار با سرور) در فایل README پلاگین آمده است.</p>
                <p>آخرین نقطه هماهنگی موفق: <?php echo $cursor !== '' ? esc_html($cursor) . ' UTC' : '-'; ?></p>
                <pre style="background:#f6f7f7;padding:8px;overflow:auto">0 6 * * * cd /path/to/wordpress && wp hci sync --quiet</pre>
            </div>
            <script>
            document.getElementById('hci-adv-link').addEventListener('click', function (e) {
                e.preventDefault();
                var box = document.getElementById('hci-adv-box');
                box.style.display = box.style.display === 'none' ? 'block' : 'none';
            });
            </script>
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
            $any_variation_changed = false;
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
                    $any_variation_changed = $any_variation_changed || $outcome['price_updated'] || $outcome['stock_updated'] || $outcome['deactivated'];
                } catch (Throwable $e) {
                    $errors++;
                }
            }
            // وضعیت والد Variable از روی تنوع‌ها دوباره حساب می‌شود.
            if ($any_variation_changed && $parent_row) {
                try {
                    HCI_Import::refresh_variable_parent(
                        (int) $parent_row['dest_product_id'],
                        array_map(static fn (array $r): int => (int) $r['dest_variation_id'], $variation_rows)
                    );
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

    /**
     * برای دکمه «ریست داده‌ها»: شمارنده Retry را پاک می‌کند تا اگر Action
     * Scheduler هم مستقلاً پاک شده، یک زنجیره Backoff نیمه‌کاره باقی نماند
     * (اجرای بعدی از تلاش اول/۵ دقیقه شروع می‌شود، نه از جایی که رها شده بود).
     */
    public static function reset_retry_state(): void {
        delete_option(self::RETRY_COUNT_OPTION);
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

        // مبدا قدیمی (نه عدد، نه وضعیت): چیزی برای به‌روزرسانی موجودی نداریم.
        $stock_unknown = ($item['stock_quantity'] ?? null) === null && empty($item['stock_status']);
        if (array_key_exists('stock_quantity', $item) && !$stock_unknown) {
            // همان قاعده مشترک Import: stock_quantity=NULL یعنی مدیریت
            // موجودی در مبدا خاموش است — manage_stock را روشن نمی‌کند، فقط
            // stock_status خام مبدا را منعکس می‌کند (نه outofstock حدسی).
            $stock_changed = HCI_Import::apply_stock($product, $item['stock_quantity'] ?? null, $item['stock_status'] ?? null);
            if ($stock_changed) {
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
