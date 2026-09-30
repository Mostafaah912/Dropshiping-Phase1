<?php

defined('ABSPATH') || exit;

final class HCI_Import {
    private const CAPABILITY = 'manage_woocommerce';
    public const ACTION_HOOK = 'hci_import_product';
    public const GROUP = 'hci-import';
    private const SKU_SOURCE_PREFIX = 'hmp';
    private const SKU_DEST_PREFIX = 'vsp';
    private const IMAGE_URL_META_KEY = '_hci_source_image_url';
    // این دو کلید متا برای دکمه «ریست داده‌ها» عمومی‌اند: HCI_Admin با آن‌ها
    // فقط محصولات/تصاویری را که واقعاً همین پلاگین ساخته پیدا و (در صورت
    // درخواست صریح) حذف می‌کند.
    public const SOURCE_PRODUCT_META_KEY = '_hci_source_product_id';
    public const IMAGE_META_KEY = '_hci_imported';

    public static function init(): void {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'process_import_action'), 10, 1);
        add_action('wp_ajax_hci_queue_import', array(__CLASS__, 'ajax_queue_import'));
        add_action('wp_ajax_hci_queue_batch_import', array(__CLASS__, 'ajax_queue_batch_import'));
        add_action('wp_ajax_hci_poll_import_status', array(__CLASS__, 'ajax_poll_import_status'));
        add_action('wp_ajax_hci_import_next', array(__CLASS__, 'ajax_import_next'));
        add_action('wp_ajax_hci_repair_variations', array(__CLASS__, 'ajax_repair_variations'));
    }

    // =========================================================================
    // AJAX — صف‌بندی و Polling (بدون هیچ درخواست به منبع، فقط جدول ردیابی)
    // =========================================================================

    public static function ajax_queue_import(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hci_queue_import');

        $source_product_id = isset($_POST['source_product_id']) ? (int) $_POST['source_product_id'] : 0;
        $source_sku = isset($_POST['source_sku']) ? sanitize_text_field(wp_unslash($_POST['source_sku'])) : '';
        $entry_json = isset($_POST['entry_json']) ? (string) wp_unslash($_POST['entry_json']) : '';

        $result = self::queue_single($source_product_id, $source_sku !== '' ? $source_sku : null, $entry_json);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result, 400);
    }

    public static function ajax_queue_batch_import(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hci_queue_import');

        $items = json_decode(isset($_POST['items']) ? (string) wp_unslash($_POST['items']) : '', true);
        if (!is_array($items)) {
            wp_send_json_error(array('message' => 'داده نامعتبر است.'), 400);
        }

        $queued = 0;
        $skipped = array();
        foreach ($items as $item) {
            $source_product_id = (int) ($item['source_product_id'] ?? 0);
            $source_sku = !empty($item['source_sku']) ? sanitize_text_field((string) $item['source_sku']) : null;
            $entry_json = isset($item['entry']) ? wp_json_encode($item['entry']) : '';
            $result = self::queue_single($source_product_id, $source_sku, (string) $entry_json);
            if ($result['success']) {
                $queued++;
            } else {
                $skipped[] = array('source_product_id' => $source_product_id, 'message' => $result['message']);
            }
        }

        wp_send_json_success(array('queued' => $queued, 'skipped' => $skipped));
    }

    public static function ajax_poll_import_status(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hci_poll_import_status');

        $ids_raw = isset($_POST['source_product_ids']) ? (string) wp_unslash($_POST['source_product_ids']) : '';
        $ids = array_map('intval', array_filter(explode(',', $ids_raw)));
        wp_send_json_success(array('statuses' => HCI_DB::get_import_statuses($ids)));
    }

    /**
     * محصول را صف‌بندی می‌کند: Snapshot ویرایش‌شده را دائمی ذخیره می‌کند (چون
     * Transient انتخاب فاز ۲ فقط ۳۰ دقیقه عمر دارد و Action Scheduler ممکن
     * است دیرتر اجرا شود) و یک Action مجزا برای همین یک محصول می‌سازد. یک
     * پیش‌بررسی سریع Duplicate اینجا انجام می‌شود تا کاربر فوراً در UI پیام
     * بگیرد؛ بررسی قطعی (شامل لایه سوم SKU مقصد واقعی) داخل خودِ Action در
     * process_import_action() دوباره انجام می‌شود چون فقط همان لحظه معتبر است.
     */
    private static function queue_single(int $source_product_id, ?string $source_sku, string $entry_json): array {
        if ($source_product_id <= 0) {
            return array('success' => false, 'message' => 'source_product_id نامعتبر است.');
        }

        $decoded = json_decode($entry_json, true);
        if (!is_array($decoded)) {
            return array('success' => false, 'message' => 'داده محصول نامعتبر است.');
        }

        $existing_row = HCI_DB::get_map_row($source_product_id);
        if ($existing_row && $existing_row['import_status'] === HCI_DB::STATUS_IMPORTED) {
            return array('success' => false, 'message' => 'این محصول قبلاً Import شده است.', 'duplicate' => true);
        }

        $sanitized = self::sanitize_payload($decoded);
        $queue = HCI_DB::queue_import($source_product_id, $source_sku, (string) wp_json_encode($sanitized, JSON_UNESCAPED_UNICODE));
        if (!$queue['success']) {
            return array('success' => false, 'message' => $queue['message'] ?? 'خطا در صف‌بندی.');
        }

        if (!function_exists('as_schedule_single_action')) {
            return array('success' => false, 'message' => 'Action Scheduler در دسترس نیست — مطمئن شوید ووکامرس فعال است.');
        }
        as_schedule_single_action(time(), self::ACTION_HOOK, array('source_product_id' => $source_product_id), self::GROUP);

        return array('success' => true, 'message' => 'صف‌بندی شد.');
    }

    private static function sanitize_stock_status($value): ?string {
        $value = is_string($value) ? sanitize_key($value) : '';
        return in_array($value, array('instock', 'outofstock', 'onbackorder'), true) ? $value : null;
    }

    private static function sanitize_payload(array $decoded): array {
        $variations = array();
        foreach ((array) ($decoded['variations'] ?? array()) as $v) {
            $attrs = array();
            foreach ((array) ($v['attributes'] ?? array()) as $a) {
                $name = sanitize_text_field((string) ($a['name'] ?? ''));
                $option = sanitize_text_field((string) ($a['option'] ?? ''));
                if ($name === '' || $option === '') {
                    continue;
                }
                $attrs[] = array('name' => $name, 'option' => $option);
            }
            $variations[] = array(
                'variation_id' => (int) ($v['variation_id'] ?? 0),
                'attributes' => $attrs,
                'price' => isset($v['price']) && $v['price'] !== null && $v['price'] !== '' ? (string) $v['price'] : null,
                'stock_quantity' => isset($v['stock_quantity']) && $v['stock_quantity'] !== null && $v['stock_quantity'] !== '' ? (float) $v['stock_quantity'] : null,
                'stock_status' => self::sanitize_stock_status($v['stock_status'] ?? null),
                'sku' => !empty($v['sku']) ? sanitize_text_field((string) $v['sku']) : null,
            );
        }

        $product_type = ($decoded['product_type'] ?? '') === 'variable' ? 'variable' : 'simple';

        return array(
            'name' => sanitize_text_field((string) ($decoded['name'] ?? '')),
            'short_description' => wp_kses_post((string) ($decoded['short_description'] ?? '')),
            'featured_image' => !empty($decoded['featured_image']) ? esc_url_raw((string) $decoded['featured_image']) : null,
            'images' => array_values(array_filter(array_map(
                static fn ($u): string => esc_url_raw((string) $u),
                (array) ($decoded['images'] ?? array())
            ))),
            'category_path' => array_values(array_filter(array_map(
                static function ($node): ?array {
                    $name = sanitize_text_field((string) ($node['name'] ?? ''));
                    if ($name === '') {
                        return null;
                    }
                    return array(
                        'id' => (int) ($node['id'] ?? 0),
                        'name' => $name,
                        'slug' => sanitize_title((string) ($node['slug'] ?? '')),
                        'parent_id' => isset($node['parent_id']) && $node['parent_id'] !== null ? (int) $node['parent_id'] : null,
                    );
                },
                (array) ($decoded['category_path'] ?? array())
            ))),
            'product_type' => $product_type,
            'sku' => !empty($decoded['sku']) ? sanitize_text_field((string) $decoded['sku']) : null,
            'price' => isset($decoded['price']) && $decoded['price'] !== null && $decoded['price'] !== '' ? (string) $decoded['price'] : null,
            'stock_quantity' => isset($decoded['stock_quantity']) && $decoded['stock_quantity'] !== null && $decoded['stock_quantity'] !== '' ? (float) $decoded['stock_quantity'] : null,
            'stock_status' => self::sanitize_stock_status($decoded['stock_status'] ?? null),
            'variations' => $variations,
        );
    }

    // =========================================================================
    // منطق قابل‌تست به‌تنهایی (بدون نیاز به WooCommerce واقعی)
    // =========================================================================

    /**
     * فقط پیشوند اول hmp به vsp تبدیل می‌شود؛ بقیه رشته و SKUهای بدون این
     * پیشوند دست‌نخورده می‌مانند. hmp-12345 → vsp-12345.
     */
    public static function transform_sku(?string $sku): ?string {
        if ($sku === null || $sku === '') {
            return null;
        }
        if (stripos($sku, self::SKU_SOURCE_PREFIX) === 0) {
            return self::SKU_DEST_PREFIX . substr($sku, strlen(self::SKU_SOURCE_PREFIX));
        }
        return $sku;
    }

    /**
     * سه لایه: (۱) رکورد 'imported' دیگری با همین source_product_id،
     * (۲) رکورد 'imported' دیگری با همین source_sku، (۳) یک محصول واقعی در
     * ووکامرس مقصد که همین SKU تبدیل‌شده را دارد ولی خودِ محصولِ در حال
     * Retry ما نیست (wc_get_product_id_by_sku). در صورت تشخیص، پیام‌دار
     * برمی‌گرداند؛ اگر Duplicate نبود null.
     */
    public static function check_duplicate(int $current_row_id, int $source_product_id, ?string $source_sku, ?string $dest_sku, int $existing_dest_id): ?string {
        $other = HCI_DB::find_other_imported_row($current_row_id, $source_product_id, $source_sku);
        if ($other) {
            return 'این محصول (یا SKU آن) قبلاً با شناسه محصول مقصد #' . (int) ($other['dest_product_id'] ?? 0) . ' Import شده است.';
        }

        if ($dest_sku !== null && function_exists('wc_get_product_id_by_sku')) {
            $external_id = (int) wc_get_product_id_by_sku($dest_sku);
            if ($external_id > 0 && $external_id !== $existing_dest_id) {
                return 'یک محصول با SKU مقصد "' . $dest_sku . '" از قبل در ووکامرس وجود دارد (شناسه #' . $external_id . ') که توسط این پلاگین ساخته نشده.';
            }
        }

        return null;
    }

    /**
     * آیا اصلاً دسته‌بندی‌های هی‌مد باید در مقصد ساخته/اعمال شوند؟ تنظیم
     * جدید کارمند: پیش‌فرض بله (رفتار قبلی حفظ شده). «خیر» یعنی هیچ Term‌ای
     * ساخته/جست‌وجو نمی‌شود و محصول دسته پیش‌فرض ووکامرس را می‌گیرد.
     */
    public static function should_apply_categories(): bool {
        return (bool) get_option('hci_apply_categories', true);
    }

    /**
     * سلسله‌مراتب را از روی ارجاع صریح parent_id هر Node می‌سازد (نه با
     * فرض این‌که آیتم i همیشه والدِ آیتم i+1 است). رگرسیون قبلی («فقط دسته
     * آخر ساخته می‌شود») از همین فرض ترتیبی می‌آمد: وقتی category_path
     * منبع بیش از یک شاخه دارد (محصول در چند دسته هم‌زمان)، آرایه‌ی مسطح
     * چند شاخه را پشت‌سرهم می‌چیند و زنجیره‌ی ترتیبیِ قدیم آن‌ها را به‌اشتباه
     * زیر هم (نه زیر Parent واقعی‌شان) می‌ساخت. اینجا با یک map از
     * source_id → dest_term_id، هر Node دقیقاً زیر همان Parentی ساخته
     * می‌شود که واقعاً در مبدا داشته.
     *
     * تطبیق دسته موجود همچنان «نام دقیق + Parent» است (نه Slug) —
     * term_exists($name, $tax, $dest_parent_id) خودِ وردپرس این را تضمین
     * می‌کند. Slug فقط برای Termهای *جدید* استفاده می‌شود (همان‌طور که در
     * مبدا ذخیره شده)؛ اگر مبدا Slug نداد (نسخه قدیمی‌تر heymode-wholesale)
     * یا در مقصد قبلاً گرفته شده باشد، وردپرس خودش از روی نام یک Slug
     * یکتا می‌سازد — نیازی به منطق اضافه اینجا نیست.
     *
     * @param array $category_path آرایه‌ی ریشه→برگ از {id, name, slug, parent_id}
     */
    public static function resolve_category_hierarchy(array $category_path): array {
        $dest_term_id_by_source_id = array();
        $leaf_term_id = 0;

        foreach ($category_path as $node) {
            $name = trim((string) ($node['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $source_id = (int) ($node['id'] ?? 0);
            $source_parent_id = isset($node['parent_id']) ? (int) $node['parent_id'] : 0;
            $dest_parent_id = ($source_parent_id > 0 && isset($dest_term_id_by_source_id[$source_parent_id]))
                ? $dest_term_id_by_source_id[$source_parent_id]
                : 0;

            $existing = term_exists($name, 'product_cat', $dest_parent_id);
            if (is_array($existing) && !empty($existing['term_id'])) {
                $term_id = (int) $existing['term_id'];
            } elseif (is_numeric($existing) && (int) $existing > 0) {
                $term_id = (int) $existing;
            } else {
                $insert_args = array('parent' => $dest_parent_id);
                $slug = trim((string) ($node['slug'] ?? ''));
                if ($slug !== '') {
                    $insert_args['slug'] = $slug;
                }
                $inserted = wp_insert_term($name, 'product_cat', $insert_args);
                if (is_wp_error($inserted)) {
                    continue;
                }
                $term_id = (int) $inserted['term_id'];
            }

            if ($source_id > 0) {
                $dest_term_id_by_source_id[$source_id] = $term_id;
            }
            $leaf_term_id = $term_id;
        }

        return $leaf_term_id > 0 ? array($leaf_term_id) : array();
    }

    /**
     * دانلود تصویر را با یک URL بین کل Batch (نه فقط یک محصول) دور می‌زند:
     * قبل از دانلود، به‌ازای همین URL در Meta پیوست‌ها جستجو می‌کند. چون هر
     * محصول به‌صورت یک Action Scheduler مجزا (یک درخواست PHP جدا) پردازش
     * می‌شود، این Dedup باید از طریق دیتابیس واقعی باشد، نه یک متغیر حافظه.
     */
    public static function get_or_sideload_image(string $url): int {
        $existing = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => self::IMAGE_URL_META_KEY,
            'meta_value' => $url,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));
        if (!empty($existing[0])) {
            return (int) $existing[0];
        }

        if (!function_exists('media_sideload_image')) {
            return 0;
        }
        $attachment_id = media_sideload_image($url, 0, null, 'id');
        if (is_wp_error($attachment_id) || !$attachment_id) {
            return 0;
        }
        update_post_meta((int) $attachment_id, self::IMAGE_URL_META_KEY, $url);
        // برای دکمه «ریست داده‌ها»: علامت‌گذاری صریح که این پیوست را خودِ
        // پلاگین دانلود کرده — تا گزینه اختیاری حذف محصولات/تصاویر فقط
        // همین‌ها را پاک کند، نه تصویری که کارمند دستی در رسانه آپلود کرده.
        update_post_meta((int) $attachment_id, self::IMAGE_META_KEY, 1);
        return (int) $attachment_id;
    }

    // =========================================================================
    // پردازشِ خودِ Action Scheduler — یک محصول در هر Action
    // =========================================================================

    /**
     * هدف Action Scheduler (مسیر پشتیبان — برای وقتی کاربر صفحه بازبینی را
     * بسته و کارگر مرورگر (ajax_import_next) دیگر در حال اجرا نیست). قبل از
     * پردازش، همان Claim اتمیک مسیر AJAX را هم رعایت می‌کند تا اگر آن مسیر
     * از قبل همین محصول را برده و پردازش کرده، اینجا دوباره ساخته نشود.
     */
    public static function process_import_action(int $source_product_id): void {
        HCI_DB::release_stale_locks();
        $claim = HCI_DB::claim_specific($source_product_id);
        if ($claim === null) {
            return; // یا وجود ندارد، یا کس دیگری (مسیر AJAX) از قبل برده/تمام کرده
        }
        self::process_claimed_row($source_product_id);
    }

    /**
     * «ترمیم تنوع‌ها»: فقط تنوع‌های ناقص یک محصول Variable که قبلاً وارد شده
     * دوباره ساخته می‌شود؛ والد و تنوع‌های سالم دست‌نخورده می‌مانند.
     */
    public static function repair_variations(int $source_product_id): array {
        $broken_ids = array_map(static fn (array $r): int => (int) $r['source_product_id'], HCI_DB::get_broken_variable_rows());
        if (!in_array($source_product_id, $broken_ids, true)) {
            return array('success' => false, 'message' => 'این محصول نیازی به ترمیم ندارد.');
        }
        if (!HCI_DB::requeue_for_repair($source_product_id)) {
            return array('success' => false, 'message' => 'این محصول همین الان در حال وارد شدن است.');
        }
        return array('success' => true, 'message' => 'ترمیم شروع شد.');
    }

    public static function ajax_repair_variations(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'اجازه دسترسی ندارید.'), 403);
        }
        check_ajax_referer('hci_repair_variations');
        $result = self::repair_variations(isset($_POST['source_product_id']) ? (int) $_POST['source_product_id'] : 0);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result, 400);
    }

    /**
     * برای کارگر سمت مرورگر: قفل‌های قدیمی (>۵ دقیقه) را آزاد می‌کند، یک
     * ردیف queued را اتمیک Claim می‌کند و بلافاصله همان‌جا (Synchronous)
     * پردازش می‌کند — نه در پس‌زمینه — تا JS بلافاصله نتیجه را برای همان
     * ردیف نشان دهد. اگر چیزی برای Claim نبود، done=true برمی‌گرداند.
     */
    public static function ajax_import_next(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hci_import_next');

        HCI_DB::release_stale_locks();
        $claim = HCI_DB::claim_next_queued();
        if ($claim === null) {
            wp_send_json_success(array('done' => true));
        }

        $result = self::process_claimed_row($claim['source_product_id']);
        wp_send_json_success(array(
            'done' => false,
            'source_product_id' => $claim['source_product_id'],
            'import_status' => $result['import_status'],
            'error_message' => $result['error_message'],
        ));
    }

    /**
     * هسته پردازش واقعی یک ردیف که همین الان با موفقیت Claim شده (وضعیت
     * از قبل processing است) — هر دو مسیر (Action Scheduler و AJAX
     * کارگر مرورگر) دقیقاً همین یک تابع را صدا می‌زنند تا منطق ساخت
     * محصول یک‌جا و بدون تکرار بماند. هر مرحله زمان‌گیری و در لاگ خطای
     * وردپرس ثبت می‌شود (برای ریشه‌یابی کندی/خطای واقعی صف).
     */
    private static function process_claimed_row(int $source_product_id): array {
        $t0 = microtime(true);
        self::log_step($source_product_id, 'claimed', $t0, $t0);

        $row = HCI_DB::get_map_row($source_product_id);
        if (!$row) {
            return array('import_status' => HCI_DB::STATUS_ERROR, 'error_message' => 'ردیف ردیابی پیدا نشد.');
        }

        $payload = json_decode((string) ($row['import_payload'] ?? ''), true);
        if (!is_array($payload)) {
            $result = array(
                'import_status' => HCI_DB::STATUS_ERROR,
                'error_message' => 'اطلاعات محصول برای Import در دسترس نیست (Snapshot خالی یا نامعتبر).',
            );
            HCI_DB::save_import_result($source_product_id, $result);
            return $result;
        }

        $product_type = ($payload['product_type'] ?? '') === 'variable' ? 'variable' : 'simple';
        $dest_sku = self::transform_sku($payload['sku'] ?? ($row['source_sku'] ?? null));
        $existing_dest_id = !empty($row['dest_product_id']) ? (int) $row['dest_product_id'] : 0;

        $t_dup = microtime(true);
        $duplicate_message = self::check_duplicate((int) $row['id'], $source_product_id, $row['source_sku'] ?? null, $dest_sku, $existing_dest_id);
        self::log_step($source_product_id, 'duplicate_check', $t_dup, $t0);
        if ($duplicate_message !== null) {
            $result = array('import_status' => HCI_DB::STATUS_DUPLICATE, 'error_message' => $duplicate_message);
            HCI_DB::save_import_result($source_product_id, $result);
            return $result;
        }

        try {
            $product = $existing_dest_id > 0 ? wc_get_product($existing_dest_id) : false;
            if (!$product) {
                $product = ($product_type === 'variable') ? new WC_Product_Variable() : new WC_Product_Simple();
            }

            // Draft تا وقتی همه‌چیز (خصوصاً تصاویر) واقعاً موفق شود — طبق
            // خواسته، هیچ‌وقت مستقیم منتشر نمی‌شود.
            $product->set_status('draft');
            $product->set_name((string) ($payload['name'] ?? ''));
            $product->set_short_description((string) ($payload['short_description'] ?? ''));

            $t_cat = microtime(true);
            if (self::should_apply_categories()) {
                $category_ids = self::resolve_category_hierarchy((array) ($payload['category_path'] ?? array()));
                if ($category_ids) {
                    $product->set_category_ids($category_ids);
                }
            }
            // اگر تنظیم «خیر» باشد، set_category_ids() اصلاً صدا زده نمی‌شود —
            // محصول همان دسته پیش‌فرض ووکامرس (Uncategorized) را می‌گیرد.
            self::log_step($source_product_id, 'categories', $t_cat, $t0);

            $t_img = microtime(true);
            $image_error = self::apply_images($product, $payload);
            self::log_step($source_product_id, 'images', $t_img, $t0);

            $t_save = microtime(true);
            $parent_id = ($product_type === 'variable')
                ? self::save_variable_product($product, $payload, $dest_sku)
                : self::save_simple_product($product, $payload, $dest_sku);
            self::log_step($source_product_id, 'save', $t_save, $t0);

            // برای دکمه «ریست داده‌ها»: محصولی که این پلاگین می‌سازد (حتی اگر
            // بعداً Partial/Error بماند) باید همیشه قابل شناسایی باشد تا
            // گزینه اختیاری «حذف محصولات ساخته‌شده توسط این پلاگین» فقط
            // همین‌ها را پاک کند، نه هیچ محصول دیگری.
            update_post_meta($parent_id, '_hci_source_product_id', $source_product_id);

            // تنوع‌ها (Variation) عمداً *قبل* از هر بازگشت زودهنگام ساخته
            // می‌شوند: قبلاً اگر دانلود یک تصویر شکست می‌خورد، تابع همین‌جا
            // برمی‌گشت و هیچ Variationای ساخته نمی‌شد (والد Variable بدون تنوع
            // و «ناموجود» می‌ماند).
            $variation_problem = null;
            if ($product_type === 'variable') {
                $t_var = microtime(true);
                $summary = self::save_variations($parent_id, $source_product_id, (array) ($payload['variations'] ?? array()));
                self::refresh_variable_parent($parent_id, $summary['ids']);
                self::log_step($source_product_id, 'variations', $t_var, $t0);
                $variation_problem = self::variation_problem_message($summary);
            }

            $problem = trim(implode(' ', array_filter(array($image_error, $variation_problem))));
            if ($problem !== '') {
                $result = array(
                    'dest_product_id' => $parent_id,
                    'dest_sku' => $dest_sku,
                    'import_status' => HCI_DB::STATUS_PARTIAL,
                    'error_message' => $problem,
                );
                HCI_DB::save_import_result($source_product_id, $result);
                return $result;
            }

            $final_status = HCI_Pricing::get_default_post_status();
            $product = wc_get_product($parent_id);
            $product->set_status($final_status);
            $product->save();

            $result = array(
                'dest_product_id' => $parent_id,
                'dest_sku' => $dest_sku,
                'import_status' => HCI_DB::STATUS_IMPORTED,
                'error_message' => null,
            );
            HCI_DB::save_import_result($source_product_id, $result);
            self::log_step($source_product_id, 'done', microtime(true), $t0);
            return $result;
        } catch (Throwable $e) {
            $result = array('import_status' => HCI_DB::STATUS_ERROR, 'error_message' => $e->getMessage());
            HCI_DB::save_import_result($source_product_id, $result);
            return $result;
        }
    }

    /**
     * فقط ابزار ریشه‌یابی کندی/خطای واقعی صف (مورد ۴ گزارش تست): مدت هر
     * مرحله و مدت جمعی از لحظه Claim را در لاگ خطای وردپرس ثبت می‌کند.
     * هرگز رفتار Import را تغییر نمی‌دهد و هرگز استثنا پرتاب نمی‌کند.
     */
    private static function log_step(int $source_product_id, string $step, float $step_started_at, float $overall_started_at): void {
        $step_ms = round((microtime(true) - $step_started_at) * 1000, 1);
        $total_ms = round((microtime(true) - $overall_started_at) * 1000, 1);
        error_log(sprintf('[HCI Import] product=%d step=%s step_ms=%s total_ms=%s', $source_product_id, $step, $step_ms, $total_ms));
    }

    /**
     * تصاویر باقی‌مانده بعد از ویرایش کارمند را دانلود و ست می‌کند. اگر یکی
     * شکست خورد بلافاصله متوقف می‌شود (نه ادامه با نادیده‌گرفتن) و پیام خطا
     * برمی‌گرداند تا کالر محصول را Draft/partial نگه دارد؛ هرچه تا همان لحظه
     * موفق دانلود شده (Featured و گالریِ قبل از تصویر شکست‌خورده) همچنان روی
     * محصول ست می‌ماند تا Retry مجبور به دانلود دوباره‌ی آن‌ها نباشد.
     */
    private static function apply_images(WC_Product $product, array $payload): ?string {
        $featured_url = $payload['featured_image'] ?? null;
        $gallery_urls = array_values(array_filter(
            (array) ($payload['images'] ?? array()),
            static fn ($u): bool => $u !== null && $u !== '' && $u !== $featured_url
        ));

        if ($featured_url) {
            $attachment_id = self::get_or_sideload_image((string) $featured_url);
            if ($attachment_id <= 0) {
                return 'دانلود تصویر اصلی (Featured) ناموفق بود.';
            }
            $product->set_image_id($attachment_id);
        }

        $gallery_ids = array();
        foreach ($gallery_urls as $url) {
            $attachment_id = self::get_or_sideload_image((string) $url);
            if ($attachment_id <= 0) {
                if ($gallery_ids) {
                    $product->set_gallery_image_ids($gallery_ids);
                }
                return 'دانلود یکی از تصاویر گالری ناموفق بود.';
            }
            $gallery_ids[] = $attachment_id;
        }
        $product->set_gallery_image_ids($gallery_ids);

        return null;
    }

    private static function save_simple_product(WC_Product $product, array $payload, ?string $dest_sku): int {
        if ($dest_sku !== null) {
            $product->set_sku($dest_sku);
        }

        $final_price = self::resolved_price($payload['price'] ?? null);
        $product->set_regular_price((string) $final_price);
        $product->set_price((string) $final_price);

        self::apply_stock($product, $payload['stock_quantity'] ?? null, $payload['stock_status'] ?? null);

        return (int) $product->save();
    }

    /**
     * محصول Parent از نوع Variable: خودِ Parent قیمت/موجودی مجزا ندارد (این‌ها
     * روی هر Variation هستند)، فقط SKU (اگر باشد) و Attributeهای محلی سطح
     * Parent را می‌سازد. Attribute محلی (نه Taxonomy سراسری pa_*) عمداً
     * انتخاب شده: چون این پلاگین به‌صورت کاملاً خودکار مقادیر Attribute را از
     * منبع می‌سازد، اگر Global بود مقادیر محصولات کاملاً نامرتبط (مثلاً «رنگ»
     * یک تیشرت و یک کفش) در یک Taxonomy مشترک سایت قاطی می‌شدند و Import
     * موازی چند محصول ریسک Race Condition روی ساخت هم‌زمان یک Term مشترک
     * داشت. Attribute محلی کاملاً Scope‌شده به همان محصول، امن‌تر است.
     */
    private static function save_variable_product(WC_Product $product, array $payload, ?string $dest_sku): int {
        if ($dest_sku !== null) {
            $product->set_sku($dest_sku);
        }

        $options_by_name = array();
        foreach ((array) ($payload['variations'] ?? array()) as $variation) {
            foreach ((array) ($variation['attributes'] ?? array()) as $attr) {
                $name = trim((string) ($attr['name'] ?? ''));
                $option = trim((string) ($attr['option'] ?? ''));
                if ($name === '' || $option === '') {
                    continue;
                }
                $options_by_name[$name][$option] = true;
            }
        }

        $wc_attributes = array();
        foreach ($options_by_name as $name => $options) {
            $wc_attribute = new WC_Product_Attribute();
            $wc_attribute->set_id(0);
            $wc_attribute->set_name($name);
            $wc_attribute->set_options(array_map('strval', array_keys($options)));
            $wc_attribute->set_visible(true);
            $wc_attribute->set_variation(true);
            $wc_attributes[] = $wc_attribute;
        }
        $product->set_attributes($wc_attributes);

        return (int) $product->save();
    }

    /**
     * @return array{ids:int[],total:int,failed:int,unknown_stock:int}
     */
    private static function save_variations(int $parent_id, int $parent_source_id, array $variations): array {
        $summary = array('ids' => array(), 'total' => 0, 'failed' => 0, 'unknown_stock' => 0);

        foreach ($variations as $variation_payload) {
            $source_variation_id = (int) ($variation_payload['variation_id'] ?? 0);
            if ($source_variation_id <= 0) {
                continue;
            }
            $summary['total']++;

            try {
                $existing_map = HCI_DB::get_map_row($parent_source_id, $source_variation_id);
                $dest_variation_id = !empty($existing_map['dest_variation_id']) ? (int) $existing_map['dest_variation_id'] : 0;
                $existing_product = $dest_variation_id > 0 ? wc_get_product($dest_variation_id) : false;

                // ترمیم/Retry: تنوعی که قبلاً کامل ساخته شده دوباره ساخته نمی‌شود.
                if ($existing_product && ($existing_map['import_status'] ?? '') === HCI_DB::STATUS_IMPORTED) {
                    $summary['ids'][] = $dest_variation_id;
                    continue;
                }

                $variation_product = $existing_product ?: new WC_Product_Variation();
                $variation_product->set_parent_id($parent_id);
                $variation_product->set_status('publish');

                $attributes_meta = array();
                foreach ((array) ($variation_payload['attributes'] ?? array()) as $attr) {
                    $name = trim((string) ($attr['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $attributes_meta[sanitize_title($name)] = trim((string) ($attr['option'] ?? ''));
                }
                $variation_product->set_attributes($attributes_meta);

                $final_price = self::resolved_price($variation_payload['price'] ?? null);
                $variation_product->set_regular_price((string) $final_price);
                $variation_product->set_price((string) $final_price);

                $stock_status = $variation_payload['stock_status'] ?? null;
                $stock_quantity = $variation_payload['stock_quantity'] ?? null;
                if ($stock_status === null && ($stock_quantity === null || $stock_quantity === '')) {
                    // مبدا قدیمی: نه عدد داریم نه وضعیت. instock حدس نمی‌زنیم.
                    $summary['unknown_stock']++;
                }
                self::apply_stock($variation_product, $stock_quantity, $stock_status, false);

                // SKU مشترک Parent هرگز روی Variation کپی نمی‌شود: فقط اگر خودِ
                // این Variation در منبع SKU مجزا داشت تبدیل و ست می‌شود.
                $source_variation_sku = $variation_payload['sku'] ?? null;
                $dest_variation_sku = self::transform_sku($source_variation_sku);
                if ($dest_variation_sku !== null) {
                    $variation_product->set_sku($dest_variation_sku);
                }

                $saved_dest_variation_id = (int) $variation_product->save();
                if ($saved_dest_variation_id <= 0) {
                    throw new RuntimeException('variation save returned no id');
                }

                HCI_DB::upsert_variation_map(
                    $parent_source_id,
                    $source_variation_id,
                    $source_variation_sku,
                    $saved_dest_variation_id,
                    $dest_variation_sku,
                    HCI_DB::STATUS_IMPORTED
                );
                $summary['ids'][] = $saved_dest_variation_id;
            } catch (Throwable $e) {
                $summary['failed']++;
                error_log('[HCI Import] variation ' . $source_variation_id . ' failed: ' . $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * پیام ساده فارسی برای مشکل تنوع‌ها (یا null اگر همه‌چیز درست است).
     */
    private static function variation_problem_message(array $summary): ?string {
        if ($summary['total'] === 0 || $summary['failed'] > 0 || count($summary['ids']) < $summary['total']) {
            return 'تنوع‌های محصول ساخته نشد.';
        }
        if ($summary['unknown_stock'] > 0) {
            return 'موجودی تنوع‌های این محصول از هی‌مد مشخص نیست؛ لازم است پشتیبانی یک همگام‌سازی کامل در هی‌مد انجام دهد.';
        }
        return null;
    }

    /**
     * والد Variable موجودی خودش را مدیریت نمی‌کند؛ وضعیتش از روی تنوع‌ها
     * حساب می‌شود (حداقل یک تنوع موجود ⇒ والد موجود). سپس Sync خودِ
     * ووکامرس و پاک‌کردن کش‌های محصول هم صدا زده می‌شود.
     */
    public static function refresh_variable_parent(int $parent_id, array $variation_dest_ids): void {
        $parent = wc_get_product($parent_id);
        if (!$parent) {
            return;
        }
        $any_in_stock = false;
        foreach ($variation_dest_ids as $vid) {
            $v = wc_get_product((int) $vid);
            if ($v && in_array($v->get_stock_status(), array('instock', 'onbackorder'), true)) {
                $any_in_stock = true;
                break;
            }
        }
        $parent->set_manage_stock(false);
        $parent->set_stock_status($any_in_stock ? 'instock' : 'outofstock');
        $parent->save();

        if (class_exists('WC_Product_Variable') && method_exists('WC_Product_Variable', 'sync')) {
            WC_Product_Variable::sync($parent_id);
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($parent_id);
        }
    }

    private static function resolved_price($raw_price): float {
        if ($raw_price === null || $raw_price === '') {
            return 0.0;
        }
        return HCI_Pricing::resolve_price((float) $raw_price);
    }

    /**
     * قاعده مشترک موجودی — هم Import هم HCI_Sync از همین یک تابع استفاده
     * می‌کنند تا رفتار جایی دیگر دوباره (و ناهم‌خوان) نوشته نشود:
     *
     *   - stock_quantity عدد مشخص > 0  ⇒ manage_stock=true، همان عدد، instock
     *   - stock_quantity دقیقاً 0      ⇒ manage_stock=true، 0، outofstock
     *   - stock_quantity خالی/NULL     ⇒ manage_stock=false و stock_status
     *     دقیقاً همان چیزی که خودِ مبدا گزارش کرده (نه حدس‌زده از عدد)
     *
     * ریشه باگ گزارش‌شده («مودال: موجود، محصول ساخته‌شده: ناموجود») همین
     * حالت سوم بود: وقتی مدیریت موجودی در مبدا خاموش است، stock_quantity
     * همیشه NULL است، ولی کد قدیم آن را 0 فرض می‌کرد و بدون توجه به
     * stock_status واقعی مبدا (که instock بود) محصول را outofstock می‌ساخت.
     * خروجی bool: آیا واقعاً چیزی روی محصول تغییر کرد (برای شمارنده
     * stock_updated در HCI_Sync لازم است؛ Import آن را نادیده می‌گیرد).
     */
    public static function apply_stock(WC_Product $product, $stock_quantity, ?string $stock_status, bool $unknown_is_instock = true): bool {
        $changed = false;

        if ($stock_quantity !== null && $stock_quantity !== '') {
            $quantity = (float) $stock_quantity;
            $resolved_status = $quantity > 0 ? 'instock' : 'outofstock';
            if (!$product->get_manage_stock()) {
                $product->set_manage_stock(true);
                $changed = true;
            }
            if ((float) $product->get_stock_quantity() !== $quantity) {
                $product->set_stock_quantity($quantity);
                $changed = true;
            }
            if ($product->get_stock_status() !== $resolved_status) {
                $product->set_stock_status($resolved_status);
                $changed = true;
            }
            return $changed;
        }

        // مدیریت موجودی در مبدا خاموش است — عدد دقیقی برای موجودی نداریم،
        // پس اصلاً manage_stock را روشن نمی‌کنیم و فقط وضعیت خام مبدا را
        // منتقل می‌کنیم (پیش‌فرض instock فقط اگر خودِ مبدا هم چیزی نگفته).
        $allowed_statuses = array('instock', 'outofstock', 'onbackorder');
        $resolved_status = in_array($stock_status, $allowed_statuses, true) ? $stock_status : ($unknown_is_instock ? 'instock' : 'outofstock');

        if ($product->get_manage_stock()) {
            $product->set_manage_stock(false);
            $changed = true;
        }
        if ($product->get_stock_status() !== $resolved_status) {
            $product->set_stock_status($resolved_status);
            $changed = true;
        }
        return $changed;
    }
}
