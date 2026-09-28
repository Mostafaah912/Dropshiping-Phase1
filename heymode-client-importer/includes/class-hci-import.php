<?php

defined('ABSPATH') || exit;

final class HCI_Import {
    private const CAPABILITY = 'manage_woocommerce';
    public const ACTION_HOOK = 'hci_import_product';
    private const SKU_SOURCE_PREFIX = 'hmp';
    private const SKU_DEST_PREFIX = 'vsp';
    private const IMAGE_URL_META_KEY = '_hci_source_image_url';

    public static function init(): void {
        add_action(self::ACTION_HOOK, array(__CLASS__, 'process_import_action'), 10, 1);
        add_action('wp_ajax_hci_queue_import', array(__CLASS__, 'ajax_queue_import'));
        add_action('wp_ajax_hci_queue_batch_import', array(__CLASS__, 'ajax_queue_batch_import'));
        add_action('wp_ajax_hci_poll_import_status', array(__CLASS__, 'ajax_poll_import_status'));
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
        as_schedule_single_action(time(), self::ACTION_HOOK, array('source_product_id' => $source_product_id), 'hci-import');

        return array('success' => true, 'message' => 'صف‌بندی شد.');
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
                'stock_quantity' => isset($v['stock_quantity']) && $v['stock_quantity'] !== null ? (float) $v['stock_quantity'] : null,
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
            'category_names' => array_values(array_filter(array_map(
                static fn ($n): string => sanitize_text_field((string) $n),
                (array) ($decoded['category_names'] ?? array())
            ))),
            'product_type' => $product_type,
            'sku' => !empty($decoded['sku']) ? sanitize_text_field((string) $decoded['sku']) : null,
            'price' => isset($decoded['price']) && $decoded['price'] !== null && $decoded['price'] !== '' ? (string) $decoded['price'] : null,
            'stock_quantity' => isset($decoded['stock_quantity']) && $decoded['stock_quantity'] !== null ? (float) $decoded['stock_quantity'] : null,
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
     * سلسله‌مراتب کامل را از ریشه تا برگ، با تطبیق نام دقیق + Parent (نه
     * Slug)، پیدا یا می‌سازد. term_exists($name, $tax, $parent) خودِ وردپرس
     * این تطبیق را انجام می‌دهد — یک نام یکسان زیر Parent متفاوت، Match
     * نمی‌شود و یک Term جدید (درست) ساخته می‌شود. فقط شناسه‌ی برگ را
     * برمی‌گرداند؛ چون آرشیوهای دسته در وردپرس به‌صورت پیش‌فرض
     * include_children دارند، همین برای نمایش در همه سطوح مسیر کافی است.
     */
    public static function resolve_category_hierarchy(array $category_names): array {
        $parent_id = 0;
        $leaf_term_id = 0;

        foreach ($category_names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $existing = term_exists($name, 'product_cat', $parent_id);
            if (is_array($existing) && !empty($existing['term_id'])) {
                $term_id = (int) $existing['term_id'];
            } elseif (is_numeric($existing) && (int) $existing > 0) {
                $term_id = (int) $existing;
            } else {
                $inserted = wp_insert_term($name, 'product_cat', array('parent' => $parent_id));
                if (is_wp_error($inserted)) {
                    break;
                }
                $term_id = (int) $inserted['term_id'];
            }

            $parent_id = $term_id;
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
        return (int) $attachment_id;
    }

    // =========================================================================
    // پردازشِ خودِ Action Scheduler — یک محصول در هر Action
    // =========================================================================

    public static function process_import_action(int $source_product_id): void {
        $row = HCI_DB::get_map_row($source_product_id);
        if (!$row) {
            return;
        }

        HCI_DB::mark_processing($source_product_id);

        $payload = json_decode((string) ($row['import_payload'] ?? ''), true);
        if (!is_array($payload)) {
            HCI_DB::save_import_result($source_product_id, array(
                'import_status' => HCI_DB::STATUS_ERROR,
                'error_message' => 'اطلاعات محصول برای Import در دسترس نیست (Snapshot خالی یا نامعتبر).',
            ));
            return;
        }

        $product_type = ($payload['product_type'] ?? '') === 'variable' ? 'variable' : 'simple';
        $dest_sku = self::transform_sku($payload['sku'] ?? ($row['source_sku'] ?? null));
        $existing_dest_id = !empty($row['dest_product_id']) ? (int) $row['dest_product_id'] : 0;

        $duplicate_message = self::check_duplicate((int) $row['id'], $source_product_id, $row['source_sku'] ?? null, $dest_sku, $existing_dest_id);
        if ($duplicate_message !== null) {
            HCI_DB::save_import_result($source_product_id, array(
                'import_status' => HCI_DB::STATUS_DUPLICATE,
                'error_message' => $duplicate_message,
            ));
            return;
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

            $category_ids = self::resolve_category_hierarchy((array) ($payload['category_names'] ?? array()));
            if ($category_ids) {
                $product->set_category_ids($category_ids);
            }

            $image_error = self::apply_images($product, $payload);

            $parent_id = ($product_type === 'variable')
                ? self::save_variable_product($product, $payload, $dest_sku)
                : self::save_simple_product($product, $payload, $dest_sku);

            if ($image_error !== null) {
                HCI_DB::save_import_result($source_product_id, array(
                    'dest_product_id' => $parent_id,
                    'dest_sku' => $dest_sku,
                    'import_status' => HCI_DB::STATUS_PARTIAL,
                    'error_message' => $image_error,
                ));
                return;
            }

            $final_status = HCI_Pricing::get_default_post_status();
            $product = wc_get_product($parent_id);
            $product->set_status($final_status);
            $product->save();

            if ($product_type === 'variable') {
                self::save_variations($parent_id, $source_product_id, (array) ($payload['variations'] ?? array()));
            }

            HCI_DB::save_import_result($source_product_id, array(
                'dest_product_id' => $parent_id,
                'dest_sku' => $dest_sku,
                'import_status' => HCI_DB::STATUS_IMPORTED,
                'error_message' => null,
            ));
        } catch (Throwable $e) {
            HCI_DB::save_import_result($source_product_id, array(
                'import_status' => HCI_DB::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ));
        }
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

        self::apply_stock($product, $payload['stock_quantity'] ?? null);

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
            $wc_attribute->set_options(array_keys($options));
            $wc_attribute->set_visible(true);
            $wc_attribute->set_variation(true);
            $wc_attributes[] = $wc_attribute;
        }
        $product->set_attributes($wc_attributes);

        return (int) $product->save();
    }

    private static function save_variations(int $parent_id, int $parent_source_id, array $variations): void {
        foreach ($variations as $variation_payload) {
            $source_variation_id = (int) ($variation_payload['variation_id'] ?? 0);
            if ($source_variation_id <= 0) {
                continue;
            }

            $existing_map = HCI_DB::get_map_row($parent_source_id, $source_variation_id);
            $dest_variation_id = !empty($existing_map['dest_variation_id']) ? (int) $existing_map['dest_variation_id'] : 0;

            $variation_product = $dest_variation_id > 0 ? wc_get_product($dest_variation_id) : false;
            if (!$variation_product) {
                $variation_product = new WC_Product_Variation();
                $variation_product->set_parent_id($parent_id);
            }

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

            self::apply_stock($variation_product, $variation_payload['stock_quantity'] ?? null);

            // SKU مشترک Parent هرگز روی Variation کپی نمی‌شود: فقط اگر خودِ
            // این Variation در منبع SKU مجزا داشت تبدیل و ست می‌شود.
            $source_variation_sku = $variation_payload['sku'] ?? null;
            $dest_variation_sku = self::transform_sku($source_variation_sku);
            if ($dest_variation_sku !== null) {
                $variation_product->set_sku($dest_variation_sku);
            }

            $saved_dest_variation_id = (int) $variation_product->save();

            HCI_DB::upsert_variation_map(
                $parent_source_id,
                $source_variation_id,
                $source_variation_sku,
                $saved_dest_variation_id,
                $dest_variation_sku,
                HCI_DB::STATUS_IMPORTED
            );
        }
    }

    private static function resolved_price($raw_price): float {
        if ($raw_price === null || $raw_price === '') {
            return 0.0;
        }
        return HCI_Pricing::resolve_price((float) $raw_price);
    }

    private static function apply_stock(WC_Product $product, $stock_quantity): void {
        $product->set_manage_stock(true);
        $quantity = $stock_quantity !== null ? (float) $stock_quantity : 0.0;
        $product->set_stock_quantity($quantity);
        $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
    }
}
