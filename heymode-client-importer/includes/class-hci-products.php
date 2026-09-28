<?php

defined('ABSPATH') || exit;

final class HCI_Products {
    private const CAPABILITY = 'manage_woocommerce';
    private const PER_PAGE = 48;
    public const SELECTION_TRANSIENT_PREFIX = 'hci_selection_';
    private const SELECTION_TTL = 30 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 20);
        add_action('admin_post_hci_next_step', array(__CLASS__, 'handle_next_step'));
        add_action('admin_post_hci_refresh_products', array(__CLASS__, 'handle_refresh_products'));
        add_action('wp_ajax_hci_mark_pending', array(__CLASS__, 'ajax_mark_pending'));
    }

    public static function admin_menu(): void {
        add_submenu_page('heymode-client-importer', 'محصولات', 'محصولات', self::CAPABILITY, 'heymode-client-importer-products', array(__CLASS__, 'render_grid_page'));
        add_submenu_page('heymode-client-importer', 'بازبینی و Import', 'بازبینی و Import', self::CAPABILITY, 'heymode-client-importer-review', array(__CLASS__, 'render_review_page'));
    }

    private static function guard(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Access denied.', 'heymode-client-importer'));
        }
    }

    private static function selection_key(): string {
        return self::SELECTION_TRANSIENT_PREFIX . get_current_user_id();
    }

    // =========================================================================
    // صفحه گرید محصولات
    // =========================================================================

    public static function render_grid_page(): void {
        self::guard();

        $notice = isset($_GET['hci_notice']) ? sanitize_key(wp_unslash($_GET['hci_notice'])) : '';

        $fetch = HCI_Source_Client::get_all_products();
        if (!$fetch['success']) {
            self::render_shell_start();
            echo '<div class="notice notice-error"><p>خطا در دریافت محصولات از منبع: ' . esc_html($fetch['message'] ?? '') . '</p></div>';
            self::render_refresh_button();
            self::render_shell_end();
            return;
        }

        // فقط واحدهای مستقل قابل‌فروش (simple/variable)؛ ردیف‌های تک‌تک Variation
        // به‌صورت جدا کارت نمی‌گیرند — داخل کارت والد variable خودشان نمایش داده می‌شوند.
        $items = array_values(array_filter($fetch['items'], static function (array $item): bool {
            return ($item['product_type'] ?? '') !== 'variation';
        }));

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $category_id = isset($_GET['category']) ? (int) $_GET['category'] : 0;
        $stock = isset($_GET['stock']) ? sanitize_key(wp_unslash($_GET['stock'])) : '';
        $only_not_imported = !empty($_GET['only_not_imported']);
        $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'newest';
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        // «قفل‌شده» = واقعاً Import‌شده یا الان در صف/پردازش (نه هر رکورد با هر
        // وضعیتی — یک Import شکست‌خورده/Partial نباید کارت را برای همیشه
        // غیرقابل‌انتخاب کند؛ کارمند باید بتواند از همین گرید دوباره تلاش کند).
        $locked_ids = array_flip(HCI_DB::get_grid_locked_source_ids());
        // «ردیابی‌شده» = هر رکوردی با هر وضعیتی — فقط برای پاک‌سازی خودکار
        // انتخاب‌های محلی (localStorage) که دیگر معتبر نیستند (چون یک تلاش
        // Import قبلی، موفق یا ناموفق، از قبل برای آن‌ها ثبت شده).
        $tracked_ids = array_flip(HCI_DB::get_imported_source_ids());

        $filtered = self::filter_items($items, $search, $category_id, $stock, $only_not_imported, $locked_ids);
        $sorted = self::sort_items($filtered, $sort);

        $total = count($sorted);
        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $paged = min($paged, $total_pages);
        $page_items = array_slice($sorted, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        $categories = self::collect_categories($items);

        self::render_shell_start();

        if ($notice === 'cache_refreshed') {
            $refresh_result = get_transient('hci_refresh_result_' . get_current_user_id());
            delete_transient('hci_refresh_result_' . get_current_user_id());

            if (is_array($refresh_result) && !empty($refresh_result['partial'])) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html((string) $refresh_result['message']) . '</p></div>';
            } elseif (is_array($refresh_result) && !empty($refresh_result['success'])) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . sprintf('بروزرسانی کامل شد — %s محصول از منبع دریافت شد.', esc_html(number_format((int) $refresh_result['count'])))
                    . '</p></div>';
            } elseif (is_array($refresh_result)) {
                echo '<div class="notice notice-error is-dismissible"><p>بروزرسانی ناموفق بود'
                    . ($refresh_result['message'] !== '' ? ': ' . esc_html((string) $refresh_result['message']) : '.')
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>فهرست محصولات از منبع دوباره دریافت شد.</p></div>';
            }
        }

        self::render_filter_bar($search, $category_id, $stock, $only_not_imported, $sort, $categories);

        printf('<p>تعداد نتایج: <strong>%s</strong></p>', esc_html((string) $total));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="hci-grid-form">';
        echo '<input type="hidden" name="action" value="hci_next_step">';
        wp_nonce_field('hci_next_step');
        echo '<p><label><input type="checkbox" id="hci-select-all-page"> انتخاب همه در این صفحه</label> ';
        echo '<button type="submit" class="button button-primary" style="margin-right:8px">مرحله بعد ←</button> ';
        echo '<button type="button" class="button" id="hci-clear-selection" style="margin-right:8px">پاک کردن انتخاب‌ها</button> ';
        echo '<strong id="hci-selection-counter" style="margin-right:8px"></strong></p>';
        echo '<textarea name="selection_json" id="hci-selection-json" style="display:none"></textarea>';

        $page_statuses = HCI_DB::get_import_statuses(array_map(
            static fn (array $item): int => (int) ($item['source_product_id'] ?? 0),
            $page_items
        ));

        echo '<div class="hci-grid">';
        foreach ($page_items as $item) {
            $item_id = (int) ($item['source_product_id'] ?? 0);
            $item_status = $page_statuses[$item_id]['import_status'] ?? null;
            $badge_text = $item_status === HCI_DB::STATUS_QUEUED || $item_status === HCI_DB::STATUS_PROCESSING
                ? 'در حال Import'
                : 'قبلاً ثبت شده';
            self::render_card($item, isset($locked_ids[$item_id]), $badge_text);
        }
        echo '</div>';

        echo '</form>';

        self::render_pagination($paged, $total_pages);
        self::render_modal();
        self::render_grid_script($page_items, array_keys($tracked_ids));

        self::render_shell_end();
    }

    private static function filter_items(array $items, string $search, int $category_id, string $stock, bool $only_not_imported, array $imported_ids): array {
        return array_values(array_filter($items, static function (array $item) use ($search, $category_id, $stock, $only_not_imported, $imported_ids): bool {
            if ($search !== '' && mb_stripos((string) ($item['name'] ?? ''), $search) === false) {
                return false;
            }
            if ($category_id > 0) {
                $found = false;
                foreach ((array) ($item['category_path'] ?? array()) as $node) {
                    if ((int) ($node['id'] ?? 0) === $category_id) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            if ($stock !== '' && ($item['stock_status'] ?? '') !== $stock) {
                return false;
            }
            if ($only_not_imported && isset($imported_ids[(int) ($item['source_product_id'] ?? 0)])) {
                return false;
            }
            return true;
        }));
    }

    private static function sort_items(array $items, string $sort): array {
        $items = array_values($items);
        usort($items, static function (array $a, array $b) use ($sort): int {
            switch ($sort) {
                case 'oldest':
                    return self::modified_ts($a) <=> self::modified_ts($b);
                case 'cheapest':
                    return self::price_val($a) <=> self::price_val($b);
                case 'priciest':
                    return self::price_val($b) <=> self::price_val($a);
                case 'newest':
                default:
                    return self::modified_ts($b) <=> self::modified_ts($a);
            }
        });
        return $items;
    }

    private static function modified_ts(array $item): int {
        $value = $item['source_modified_gmt'] ?? null;
        if (!$value) {
            return 0;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? 0 : $ts;
    }

    private static function price_val(array $item): float {
        return $item['price'] !== null && $item['price'] !== '' ? (float) $item['price'] : 0.0;
    }

    private static function collect_categories(array $items): array {
        $categories = array();
        foreach ($items as $item) {
            foreach ((array) ($item['category_path'] ?? array()) as $node) {
                $id = (int) ($node['id'] ?? 0);
                if ($id > 0 && !isset($categories[$id])) {
                    $categories[$id] = (string) ($node['name'] ?? '');
                }
            }
        }
        asort($categories, SORT_STRING | SORT_FLAG_CASE);
        return $categories;
    }

    private static function render_shell_start(): void {
        echo '<div class="wrap"><h1>محصولات هی‌مد</h1>';
    }

    private static function render_shell_end(): void {
        echo '</div>';
    }

    private static function render_refresh_button(): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hci_refresh_products">';
        wp_nonce_field('hci_refresh_products');
        echo '<button type="submit" class="button">بروزرسانی از منبع</button>';
        echo '</form>';
    }

    private static function render_filter_bar(string $search, int $category_id, string $stock, bool $only_not_imported, string $sort, array $categories): void {
        ?>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0">
            <input type="hidden" name="page" value="heymode-client-importer-products">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="جستجو در نام محصول">

            <select name="category">
                <option value="0">همه دسته‌ها</option>
                <?php foreach ($categories as $id => $name) : ?>
                    <option value="<?php echo esc_attr((string) $id); ?>" <?php selected($category_id, $id); ?>><?php echo esc_html($name); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="stock">
                <option value="">همه وضعیت‌های انبار</option>
                <option value="instock" <?php selected($stock, 'instock'); ?>>موجود</option>
                <option value="outofstock" <?php selected($stock, 'outofstock'); ?>>ناموجود</option>
            </select>

            <select name="sort">
                <option value="newest" <?php selected($sort, 'newest'); ?>>جدیدترین</option>
                <option value="oldest" <?php selected($sort, 'oldest'); ?>>قدیمی‌ترین</option>
                <option value="cheapest" <?php selected($sort, 'cheapest'); ?>>ارزان‌ترین</option>
                <option value="priciest" <?php selected($sort, 'priciest'); ?>>گران‌ترین</option>
            </select>

            <label><input type="checkbox" name="only_not_imported" value="1" <?php checked($only_not_imported); ?>> فقط وارد‌نشده‌ها</label>

            <button type="submit" class="button">اعمال فیلتر</button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=heymode-client-importer-products')); ?>" class="button">پاک‌کردن فیلترها</a>
        </form>
        <?php
        self::render_refresh_button();
    }

    private static function render_card(array $item, bool $locked, string $badge_text = 'قبلاً ثبت شده'): void {
        $id = (int) ($item['source_product_id'] ?? 0);
        $image = $item['image_url'] ?? '';
        $name = (string) ($item['name'] ?? '');
        $price = $item['price'] !== null && $item['price'] !== '' ? $item['price'] : '-';
        $is_variable = ($item['product_type'] ?? '') === 'variable';
        ?>
        <div class="hci-card" data-id="<?php echo esc_attr((string) $id); ?>">
            <?php if ($is_variable) : ?>
                <div class="hci-card-badge-variable">متغیر</div>
            <?php endif; ?>
            <div class="hci-card-image">
                <?php if ($image) : ?>
                    <img src="<?php echo esc_url($image); ?>" alt="">
                <?php else : ?>
                    <div class="hci-card-noimage">بدون تصویر</div>
                <?php endif; ?>
            </div>
            <div class="hci-card-name"><?php echo esc_html($name); ?></div>
            <div class="hci-card-price"><?php echo esc_html((string) $price); ?></div>
            <div class="hci-card-actions">
                <label>
                    <input type="checkbox" class="hci-card-checkbox" data-id="<?php echo esc_attr((string) $id); ?>" <?php disabled($locked); ?>>
                    انتخاب
                </label>
                <button type="button" class="button hci-sell-btn" data-id="<?php echo esc_attr((string) $id); ?>" <?php disabled($locked); ?>>بفروشش</button>
            </div>
            <?php if ($locked) : ?>
                <div class="hci-card-badge"><?php echo esc_html($badge_text); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_pagination(int $paged, int $total_pages): void {
        if ($total_pages <= 1) {
            return;
        }
        echo '<p class="hci-pagination">';
        for ($p = 1; $p <= $total_pages; $p++) {
            $url = add_query_arg(array_merge($_GET, array('paged' => $p)), admin_url('admin.php'));
            if ($p === $paged) {
                printf('<strong style="margin:0 4px">%d</strong>', $p);
            } else {
                printf('<a href="%s" style="margin:0 4px">%d</a>', esc_url($url), $p);
            }
        }
        echo '</p>';
    }

    private static function render_modal(): void {
        ?>
        <div id="hci-modal-overlay" class="hci-modal-overlay" style="display:none">
            <div class="hci-modal">
                <h2>بفروشش</h2>
                <table class="form-table">
                    <tbody>
                        <tr><th>نام</th><td><input type="text" id="hci-modal-name" class="regular-text" style="width:100%"></td></tr>
                        <tr><th>توضیح کوتاه</th><td><textarea id="hci-modal-desc" rows="4" dir="ltr" style="width:100%;text-align:left"></textarea></td></tr>
                        <tr><th>گالری تصاویر</th><td><div id="hci-modal-gallery"></div></td></tr>
                        <tr><th>سلسله دسته‌بندی</th><td id="hci-modal-categories"></td></tr>
                        <tr><th>قیمت محاسبه‌شده</th><td id="hci-modal-price"></td></tr>
                        <tr><th>وضعیت انبار</th><td id="hci-modal-stock"></td></tr>
                        <tr id="hci-modal-variations-row" style="display:none">
                            <th>تنوع‌ها (Variations)</th>
                            <td>
                                <table class="widefat striped hci-variations-table">
                                    <thead><tr><th>ترکیب</th><th>قیمت خام</th><th>موجودی</th></tr></thead>
                                    <tbody id="hci-modal-variations-tbody"></tbody>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="hci-modal-add">اضافه</button>
                    <button type="button" class="button" id="hci-modal-close">بستن</button>
                </p>
            </div>
        </div>
        <style>
            .hci-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-top:12px; }
            .hci-card { border:1px solid #dcdcde; background:#fff; padding:12px; border-radius:4px; position:relative; }
            .hci-card-image { height:140px; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:8px; background:#f6f7f7; }
            .hci-card-image img { max-width:100%; max-height:100%; }
            .hci-card-noimage { color:#8c8f94; font-size:12px; }
            .hci-card-name { font-weight:600; margin-bottom:4px; min-height:38px; }
            .hci-card-price { color:#2271b1; margin-bottom:8px; }
            .hci-card-actions { display:flex; justify-content:space-between; align-items:center; gap:6px; flex-wrap:wrap; }
            .hci-card-badge { position:absolute; top:8px; left:8px; background:#d63638; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px; }
            .hci-card-badge-variable { position:absolute; top:8px; right:8px; background:#2271b1; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px; z-index:2; }
            .hci-variations-table th, .hci-variations-table td { padding:4px 8px; font-size:12px; }
            .hci-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100000; display:flex; align-items:center; justify-content:center; }
            .hci-modal { background:#fff; padding:24px; max-width:640px; width:92%; max-height:85vh; overflow:auto; border-radius:4px; }
            .hci-gallery-item { display:inline-block; position:relative; margin:4px; text-align:center; }
            .hci-gallery-item img { width:80px; height:80px; object-fit:cover; display:block; }
            .hci-gallery-remove { position:absolute; top:-6px; right:-6px; background:#d63638; color:#fff; border:none; border-radius:50%; width:20px; height:20px; cursor:pointer; }
        </style>
        <?php
    }

    /**
     * یک آیتم محصول (خروجی /products مبدا) را به شکل مختصر برای JSON تعبیه‌شده
     * در صفحه گرید تبدیل می‌کند. برای product_type=variable، آرایه‌ی variations
     * (که خودِ API مبدا از قبل با {variation_id, attributes, price,
     * stock_quantity, sku} برمی‌گرداند) هم منتقل می‌شود تا هم مودال «بفروشش»
     * و هم جدول بازبینی (بعد از عبور از localStorage/selection_json) به آن
     * دسترسی داشته باشند؛ برای simple این آرایه همیشه خالی است.
     */
    private static function build_product_json_entry(array $item): array {
        $id = (int) ($item['source_product_id'] ?? 0);
        // category_names فقط برای نمایش (مودال/جدول بازبینی) است. category_path
        // ساختار کامل (id/name/slug/parent_id) را برای ساخت درست سلسله‌مراتب
        // توسط resolve_category_hierarchy() نگه می‌دارد — نه فقط نام‌های مسطح،
        // چون همان فرض ترتیبی/مسطح باعث باگ «فقط دسته آخر ساخته می‌شود» بود.
        $category_names = array_map(static fn ($n) => (string) ($n['name'] ?? ''), (array) ($item['category_path'] ?? array()));
        $category_path = array_map(static function (array $n): array {
            return array(
                'id' => (int) ($n['id'] ?? 0),
                'name' => (string) ($n['name'] ?? ''),
                'slug' => (string) ($n['slug'] ?? ''),
                'parent_id' => $n['parent_id'] ?? null,
            );
        }, (array) ($item['category_path'] ?? array()));
        $product_type = (string) ($item['product_type'] ?? 'simple');

        $variations = array();
        if ($product_type === 'variable') {
            foreach ((array) ($item['variations'] ?? array()) as $variation) {
                $variations[] = array(
                    'variation_id' => (int) ($variation['variation_id'] ?? 0),
                    'attributes' => (array) ($variation['attributes'] ?? array()),
                    'price' => $variation['price'] ?? null,
                    'stock_quantity' => $variation['stock_quantity'] ?? null,
                    'stock_status' => (string) ($variation['stock_status'] ?? ''),
                    'sku' => $variation['sku'] ?? null,
                );
            }
        }

        return array(
            'id' => $id,
            'name' => (string) ($item['name'] ?? ''),
            'short_description' => (string) ($item['short_description'] ?? ''),
            'sku' => $item['sku'] ?? null,
            'price' => $item['price'],
            'stock_status' => (string) ($item['stock_status'] ?? ''),
            'stock_quantity' => $item['stock_quantity'] ?? null,
            'category_names' => $category_names,
            'category_path' => $category_path,
            'image_url' => $item['image_url'],
            'gallery' => (array) ($item['gallery'] ?? array()),
            'product_type' => $product_type,
            'variations' => $variations,
        );
    }

    private static function render_grid_script(array $page_items, array $tracked_ids = array()): void {
        $products_json = array();
        foreach ($page_items as $item) {
            $id = (int) ($item['source_product_id'] ?? 0);
            $products_json[$id] = self::build_product_json_entry($item);
        }
        ?>
        <script>
        (function () {
            const PRODUCTS = <?php echo wp_json_encode($products_json, JSON_UNESCAPED_UNICODE); ?>;
            // شناسه‌هایی که همین الان حداقل یک رکورد (با هر وضعیتی — موفق یا
            // ناموفق) در جدول ردیابی دارند. اگر یکی از این‌ها هنوز در
            // localStorage به‌عنوان «انتخاب‌شده» مانده، یعنی این یک انتخاب
            // قدیمی/بی‌اعتبار از یک تلاش قبلی است — نه اینکه واقعاً دوباره
            // انتخاب شده — پس باید از selection پاک شود (نه اینکه دوباره
            // Import صف‌بندی شود).
            const TRACKED_IDS = <?php echo wp_json_encode(array_values(array_map('strval', $tracked_ids))); ?>;
            const CURRENT_USER_ID = <?php echo (int) get_current_user_id(); ?>;
            const STORAGE_KEY = 'hci_selection_v1_' + CURRENT_USER_ID;

            function loadSelection() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    return raw ? JSON.parse(raw) : {};
                } catch (e) { return {}; }
            }
            function saveSelection(sel) {
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(sel)); } catch (e) {}
            }

            let selection = loadSelection();

            // پاک‌سازی خودکار: هر ایدی‌ای در selection محلی که الان در دیتابیس
            // سرور هم ردیابی می‌شود (چه Import شده، چه در صف، چه حتی خطا
            // خورده) دیگر یک «انتخاب تازه» محسوب نمی‌شود.
            (function purgeStaleSelection() {
                let changed = false;
                TRACKED_IDS.forEach(function (id) {
                    if (selection[id]) {
                        delete selection[id];
                        changed = true;
                    }
                });
                if (changed) { saveSelection(selection); }
            })();

            function defaultEntry(product) {
                // «انتخاب Featured» و «حذف از گالری» دو وضعیت کاملاً مستقل‌اند:
                // images همیشه فهرست کامل و یکتای همه تصاویر (شامل خودِ تصویر
                // اصلی) است، و featured_image فقط یک اشاره‌گر به یکی از
                // همان‌هاست — نه یک لیست جدا که با عوض شدن featured، عضو
                // قبلی‌اش برای همیشه گم شود.
                const gallery = (product.gallery || []).slice();
                const images = product.image_url
                    ? [product.image_url].concat(gallery.filter(function (u) { return u !== product.image_url; }))
                    : gallery;
                return {
                    source_product_id: product.id,
                    name: product.name,
                    short_description: product.short_description,
                    sku: product.sku,
                    price: product.price,
                    stock_quantity: product.stock_quantity,
                    stock_status: product.stock_status,
                    category_names: product.category_names,
                    category_path: product.category_path || [],
                    featured_image: product.image_url || images[0] || null,
                    images: images,
                    product_type: product.product_type,
                    variations: product.variations || [],
                };
            }

            function updateSelectionCounter() {
                const el = document.getElementById('hci-selection-counter');
                if (!el) return;
                const count = Object.keys(selection).length;
                el.textContent = count > 0 ? (count + ' محصول انتخاب‌شده') : '';
            }

            function syncCheckboxes() {
                document.querySelectorAll('.hci-card-checkbox').forEach(function (cb) {
                    const id = cb.getAttribute('data-id');
                    cb.checked = !!selection[id];
                });
                updateSelectionCounter();
            }
            syncCheckboxes();

            document.querySelectorAll('.hci-card-checkbox').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    const id = cb.getAttribute('data-id');
                    if (cb.checked) {
                        if (!selection[id]) {
                            selection[id] = defaultEntry(PRODUCTS[id]);
                        }
                    } else {
                        delete selection[id];
                    }
                    saveSelection(selection);
                    updateSelectionCounter();
                });
            });

            const clearBtn = document.getElementById('hci-clear-selection');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    selection = {};
                    saveSelection(selection);
                    syncCheckboxes();
                });
            }

            const selectAll = document.getElementById('hci-select-all-page');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.hci-card-checkbox:not(:disabled)').forEach(function (cb) {
                        if (cb.checked !== selectAll.checked) {
                            cb.checked = selectAll.checked;
                            cb.dispatchEvent(new Event('change'));
                        }
                    });
                });
            }

            // ---- Modal ----
            const overlay = document.getElementById('hci-modal-overlay');
            let currentProductId = null;
            let modalState = null;

            function renderGallery() {
                const container = document.getElementById('hci-modal-gallery');
                container.innerHTML = '';

                // modalState.images همیشه فهرست کامل تصاویر است (شامل خودِ
                // تصویر اصلی)؛ featured_image فقط اشاره‌گر است، نه یک لیست
                // جدا — پس اینجا هیچ بازسازی/فیلتر جداگانه‌ای لازم نیست.
                modalState.images.forEach(function (url) {
                    const wrap = document.createElement('div');
                    wrap.className = 'hci-gallery-item';

                    const img = document.createElement('img');
                    img.src = url;
                    wrap.appendChild(img);

                    const isFeatured = (url === modalState.featured_image);

                    const radioLabel = document.createElement('label');
                    radioLabel.style.cssText = 'display:block;font-size:11px;cursor:pointer';
                    const radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'hci-featured';
                    radio.checked = isFeatured;
                    radio.addEventListener('change', function () {
                        // فقط اشاره‌گر featured_image عوض می‌شود — تصویر قبلی
                        // همچنان در modalState.images می‌ماند، مگر با ✕ حذف شود.
                        modalState.featured_image = url;
                        renderGallery();
                    });
                    radioLabel.appendChild(radio);
                    radioLabel.appendChild(document.createTextNode(isFeatured ? ' تصویر اصلی' : ' انتخاب به‌عنوان اصلی'));
                    wrap.appendChild(document.createElement('br'));
                    wrap.appendChild(radioLabel);

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'hci-gallery-remove';
                    removeBtn.textContent = '×';
                    removeBtn.addEventListener('click', function () {
                        modalState.images = modalState.images.filter(function (u) { return u !== url; });
                        if (isFeatured) {
                            // اگر خودِ تصویر اصلی حذف شد، اولین تصویر باقی‌مانده اصلی می‌شود.
                            modalState.featured_image = modalState.images[0] || null;
                        }
                        renderGallery();
                    });
                    wrap.appendChild(removeBtn);

                    container.appendChild(wrap);
                });
            }

            function renderVariations(product) {
                const row = document.getElementById('hci-modal-variations-row');
                const tbody = document.getElementById('hci-modal-variations-tbody');
                tbody.innerHTML = '';

                if (product.product_type !== 'variable' || !product.variations || !product.variations.length) {
                    row.style.display = 'none';
                    return;
                }
                row.style.display = '';

                product.variations.forEach(function (v) {
                    const tr = document.createElement('tr');

                    const attrText = (v.attributes || []).map(function (a) {
                        return (a.name || '') + ': ' + (a.option || '');
                    }).join('، ') || '-';

                    const tdAttr = document.createElement('td');
                    tdAttr.textContent = attrText;

                    const tdPrice = document.createElement('td');
                    tdPrice.textContent = (v.price !== null && v.price !== undefined && v.price !== '') ? v.price : '-';

                    const tdStock = document.createElement('td');
                    tdStock.textContent = (v.stock_quantity !== null && v.stock_quantity !== undefined) ? v.stock_quantity : '-';

                    tr.appendChild(tdAttr);
                    tr.appendChild(tdPrice);
                    tr.appendChild(tdStock);
                    tbody.appendChild(tr);
                });
            }

            document.querySelectorAll('.hci-sell-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = btn.getAttribute('data-id');
                    const product = PRODUCTS[id];
                    if (!product) return;
                    currentProductId = id;
                    modalState = selection[id] ? JSON.parse(JSON.stringify(selection[id])) : defaultEntry(product);

                    document.getElementById('hci-modal-name').value = modalState.name;
                    document.getElementById('hci-modal-desc').value = modalState.short_description;
                    document.getElementById('hci-modal-categories').textContent = (product.category_names || []).join(' > ') || '-';
                    document.getElementById('hci-modal-price').textContent = (product.price !== null ? product.price : '-') + ' (خام؛ فرمول نهایی در جدول بازبینی محاسبه می‌شود)';
                    document.getElementById('hci-modal-stock').textContent = product.stock_status === 'instock' ? 'موجود' : 'ناموجود';
                    renderGallery();
                    renderVariations(product);
                    overlay.style.display = 'flex';
                });
            });

            document.getElementById('hci-modal-close').addEventListener('click', function () {
                overlay.style.display = 'none';
            });

            document.getElementById('hci-modal-add').addEventListener('click', function () {
                if (!currentProductId) return;
                modalState.name = document.getElementById('hci-modal-name').value;
                modalState.short_description = document.getElementById('hci-modal-desc').value;
                selection[currentProductId] = modalState;
                saveSelection(selection);
                const cb = document.querySelector('.hci-card-checkbox[data-id="' + currentProductId + '"]');
                if (cb && !cb.disabled) { cb.checked = true; }
                updateSelectionCounter();
                overlay.style.display = 'none';
            });

            document.getElementById('hci-grid-form').addEventListener('submit', function () {
                document.getElementById('hci-selection-json').value = JSON.stringify(selection);
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // انتقال انتخاب به مرحله بعد (بازبینی)
    // =========================================================================

    public static function handle_next_step(): void {
        self::guard();
        check_admin_referer('hci_next_step');

        $raw = isset($_POST['selection_json']) ? (string) wp_unslash($_POST['selection_json']) : '';
        $decoded = json_decode($raw, true);
        $selection = is_array($decoded) ? $decoded : array();

        set_transient(self::selection_key(), $selection, self::SELECTION_TTL);
        wp_safe_redirect(admin_url('admin.php?page=heymode-client-importer-review'));
        exit;
    }

    public static function handle_refresh_products(): void {
        self::guard();
        check_admin_referer('hci_refresh_products');
        HCI_Source_Client::clear_products_cache();
        $result = HCI_Source_Client::get_all_products(true);
        set_transient(
            'hci_refresh_result_' . get_current_user_id(),
            array(
                'success' => !empty($result['success']),
                'partial' => !empty($result['partial']),
                'count' => count($result['items'] ?? array()),
                'total_count' => $result['total_count'] ?? null,
                'message' => $result['message'] ?? '',
            ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect(add_query_arg('hci_notice', 'cache_refreshed', admin_url('admin.php?page=heymode-client-importer-products')));
        exit;
    }

    // =========================================================================
    // صفحه بازبینی (جدول اکسل‌مانند) — بدون ساخت واقعی محصول در ووکامرس
    // =========================================================================

    /**
     * برای محصول simple یک عدد قیمت نهایی (فرمول‌شده) برمی‌گرداند. برای
     * variable، چون هر Variation قیمت خام خودش را دارد، به‌جای یک عدد
     * گمراه‌کننده، بازه‌ی (کمترین تا بیشترین قیمتِ فرمول‌شده) را برمی‌گرداند و
     * ریز قیمت هر Variation را در 'title' (Tooltip بومی مرورگر، بدون نیاز به
     * JS اضافه) می‌گذارد — کم‌هزینه‌ترین راه سازگار با ساختار فعلی جدول.
     */
    private static function compute_price_display(array $entry): array {
        $is_variable = ($entry['product_type'] ?? 'simple') === 'variable';
        $variations = (array) ($entry['variations'] ?? array());

        if ($is_variable && $variations) {
            $final_prices = array();
            $tooltip_lines = array();
            foreach ($variations as $variation) {
                $raw = $variation['price'] ?? null;
                if ($raw === null || $raw === '') {
                    continue;
                }
                $final = HCI_Pricing::resolve_price((float) $raw);
                $final_prices[] = $final;
                $attr_text = implode('، ', array_map(
                    static fn (array $a): string => (string) ($a['name'] ?? '') . ': ' . (string) ($a['option'] ?? ''),
                    (array) ($variation['attributes'] ?? array())
                ));
                $label = $attr_text !== '' ? $attr_text : ('Variation #' . (int) ($variation['variation_id'] ?? 0));
                $tooltip_lines[] = $label . ' = ' . number_format($final, 0);
            }

            if ($final_prices) {
                $min = min($final_prices);
                $max = max($final_prices);
                return array(
                    'display' => ($min === $max) ? number_format($min, 0) : number_format($min, 0) . ' – ' . number_format($max, 0),
                    'title' => implode("\n", $tooltip_lines),
                    'is_range' => $min !== $max,
                );
            }

            return array('display' => '-', 'title' => '', 'is_range' => false);
        }

        $raw_price = $entry['price'] ?? null;
        $final_price = ($raw_price !== null && $raw_price !== '') ? HCI_Pricing::resolve_price((float) $raw_price) : null;
        return array(
            'display' => $final_price !== null ? number_format($final_price, 0) : '-',
            'title' => '',
            'is_range' => false,
        );
    }

    public static function render_review_page(): void {
        self::guard();

        $selection = get_transient(self::selection_key());
        echo '<div class="wrap"><h1>بازبینی و Import</h1>';

        if (!is_array($selection) || !$selection) {
            echo '<p>چیزی برای بازبینی انتخاب نشده. <a href="' . esc_url(admin_url('admin.php?page=heymode-client-importer-products')) . '">بازگشت به محصولات</a></p></div>';
            return;
        }

        // وضعیت واقعی هر ردیف از جدول ردیابی (نه صرفاً «آیا رکوردی وجود
        // دارد») — چون حالا یک ردیف می‌تواند queued/processing/error/partial
        // هم باشد، نه فقط imported.
        $statuses = HCI_DB::get_import_statuses(array_keys($selection));

        ?>
        <p>
            <button type="button" class="button button-primary" id="hci-import-batch">Import همه (ردیف‌های معتبر)</button>
            <strong id="hci-import-counter" style="margin-right:10px"></strong>
        </p>
        <table class="widefat striped" style="max-width:1200px">
            <thead>
                <tr>
                    <th>ردیف</th><th>عکس</th><th>نام</th><th>سلسله دسته‌بندی</th><th>قیمت</th><th>توضیح کوتاه</th><th>وضعیت</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php $row_number = 1; foreach ($selection as $source_product_id => $entry) :
                    $source_product_id = (int) $source_product_id;
                    $source_sku = (string) ($entry['sku'] ?? '');
                    $status_row = $statuses[$source_product_id] ?? null;
                    $status = $status_row['import_status'] ?? null;
                    $is_blocked = in_array($status, array(HCI_DB::STATUS_IMPORTED, HCI_DB::STATUS_QUEUED, HCI_DB::STATUS_PROCESSING), true);
                    $price_display = self::compute_price_display($entry);

                    $entry_payload = array(
                        'name' => $entry['name'] ?? '',
                        'short_description' => $entry['short_description'] ?? '',
                        'featured_image' => $entry['featured_image'] ?? null,
                        'images' => $entry['images'] ?? array(),
                        'category_names' => $entry['category_names'] ?? array(),
                        'category_path' => $entry['category_path'] ?? array(),
                        'product_type' => $entry['product_type'] ?? 'simple',
                        'variations' => $entry['variations'] ?? array(),
                        'sku' => $source_sku !== '' ? $source_sku : null,
                        'price' => $entry['price'] ?? null,
                        'stock_quantity' => $entry['stock_quantity'] ?? null,
                        'stock_status' => $entry['stock_status'] ?? null,
                    );
                    ?>
                    <tr data-id="<?php echo esc_attr((string) $source_product_id); ?>" data-sku="<?php echo esc_attr($source_sku); ?>" data-entry="<?php echo esc_attr((string) wp_json_encode($entry_payload)); ?>">
                        <td><?php echo esc_html((string) $row_number++); ?></td>
                        <td><?php if (!empty($entry['featured_image'])) : ?><img src="<?php echo esc_url($entry['featured_image']); ?>" style="width:48px;height:48px;object-fit:cover"><?php endif; ?></td>
                        <td><input type="text" class="hci-review-name" value="<?php echo esc_attr((string) ($entry['name'] ?? '')); ?>" style="width:100%"></td>
                        <td><?php echo esc_html(implode(' > ', (array) ($entry['category_names'] ?? array()))); ?></td>
                        <td<?php echo $price_display['title'] !== '' ? ' title="' . esc_attr($price_display['title']) . '"' : ''; ?>>
                            <?php echo esc_html($price_display['display']); ?>
                            <?php if ($price_display['is_range']) : ?><br><span style="font-size:10px;color:#787c82">(چند قیمتی — Hover کنید)</span><?php endif; ?>
                        </td>
                        <td><textarea class="hci-review-desc" rows="2" dir="ltr" style="width:100%;text-align:left"><?php echo esc_textarea((string) ($entry['short_description'] ?? '')); ?></textarea></td>
                        <td class="hci-status-cell" data-id="<?php echo esc_attr((string) $source_product_id); ?>">
                            <?php echo self::render_status_badge($status, $status_row['error_message'] ?? ''); ?>
                        </td>
                        <td>
                            <?php if ($is_blocked) : ?>
                                <button type="button" class="button" disabled><?php echo $status === HCI_DB::STATUS_IMPORTED ? 'وارد شده' : 'در صف/در حال انجام'; ?></button>
                            <?php else : ?>
                                <button type="button" class="button hci-import-btn" data-id="<?php echo esc_attr((string) $source_product_id); ?>">
                                    <?php echo in_array($status, array(HCI_DB::STATUS_ERROR, HCI_DB::STATUS_PARTIAL), true) ? 'تلاش مجدد' : 'وارد کن'; ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=heymode-client-importer-products')); ?>">بازگشت به محصولات</a></p>
        </div>
        <script>
        (function () {
            const queueNonce = <?php echo wp_json_encode(wp_create_nonce('hci_queue_import')); ?>;
            const pollNonce = <?php echo wp_json_encode(wp_create_nonce('hci_poll_import_status')); ?>;
            const importNextNonce = <?php echo wp_json_encode(wp_create_nonce('hci_import_next')); ?>;
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const SELECTION_STORAGE_KEY = 'hci_selection_v1_' + <?php echo (int) get_current_user_id(); ?>;

            // به محض این‌که محصولی واقعاً به صف Import رفت، دیگر یک «انتخاب
            // باز» در صفحه محصولات نیست — همان‌جا از localStorage (که صفحه
            // محصولات هم از همین کلید می‌خواند) پاک می‌شود تا بعد از بازگشت
            // به گرید، دوباره به‌اشتباه تیک‌خورده/قابل Import دیده نشود.
            function removeFromLocalSelection(id) {
                try {
                    const raw = localStorage.getItem(SELECTION_STORAGE_KEY);
                    if (!raw) return;
                    const sel = JSON.parse(raw);
                    if (sel && Object.prototype.hasOwnProperty.call(sel, String(id))) {
                        delete sel[String(id)];
                        localStorage.setItem(SELECTION_STORAGE_KEY, JSON.stringify(sel));
                    }
                } catch (e) { /* localStorage در دسترس نیست — بی‌ضرر نادیده گرفته می‌شود */ }
            }

            const pendingIds = new Set();
            let totalQueued = 0;
            let doneCount = 0;
            let pollTimer = null;

            const statusLabels = {
                imported: { text: 'وارد شد ✓', color: '#008a20' },
                duplicate: { text: 'تکراری ✕', color: '#d63638' },
                error: { text: 'خطا ✕', color: '#d63638' },
                partial: { text: 'ناقص ⚠', color: '#b26b00' },
                processing: { text: 'در حال Import…', color: '#787c82' },
                queued: { text: 'در صف…', color: '#787c82' }
            };

            function updateCounter() {
                const el = document.getElementById('hci-import-counter');
                if (el) el.textContent = totalQueued > 0 ? (doneCount + ' از ' + totalQueued) : '';
            }

            function setRowStatus(id, statusKey, message) {
                const cell = document.querySelector('.hci-status-cell[data-id="' + id + '"]');
                if (!cell) return;
                const info = statusLabels[statusKey] || { text: statusKey, color: '#787c82' };
                cell.innerHTML = '';
                const span = document.createElement('span');
                span.style.color = info.color;
                span.style.fontWeight = 'bold';
                span.textContent = info.text;
                if (message) { span.title = message; }
                cell.appendChild(span);
            }

            function markQueuedUI(id) {
                setRowStatus(id, 'queued', '');
                const btn = document.querySelector('.hci-import-btn[data-id="' + id + '"]');
                if (btn) { btn.disabled = true; btn.textContent = 'در صف…'; }
            }

            function poll() {
                if (pendingIds.size === 0) {
                    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    return;
                }
                const body = new URLSearchParams();
                body.set('action', 'hci_poll_import_status');
                body.set('_ajax_nonce', pollNonce);
                body.set('source_product_ids', Array.from(pendingIds).join(','));
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.success) return;
                        const statuses = d.data.statuses || {};
                        Object.keys(statuses).forEach(function (id) {
                            const info = statuses[id];
                            const status = info.import_status;
                            if (status === 'processing') {
                                setRowStatus(id, 'processing', '');
                                return;
                            }
                            if (['imported', 'duplicate', 'error', 'partial'].indexOf(status) === -1) {
                                return;
                            }
                            setRowStatus(id, status, info.error_message || '');
                            const btn = document.querySelector('.hci-import-btn[data-id="' + id + '"]');
                            if (btn) {
                                if (status === 'error' || status === 'partial') {
                                    btn.disabled = false;
                                    btn.textContent = 'تلاش مجدد';
                                } else {
                                    btn.remove();
                                }
                            }
                            if (pendingIds.has(id)) {
                                pendingIds.delete(id);
                                doneCount++;
                                updateCounter();
                            }
                        });
                    });
            }

            function startPolling() {
                if (pollTimer) return;
                pollTimer = setInterval(poll, 2000);
                poll();
            }

            // «کارگر سمت مرورگر»: به‌جای منتظرِ صرفِ WP-Cron/Action Scheduler
            // Loopback ماندن (که ممکن است تا بازدید بعدی سایت اصلاً اجرا
            // نشود)، همین‌جا JS پشت‌سرهم و تک‌به‌تک hci_import_next را صدا
            // می‌زند؛ هر بار دقیقاً یک محصول از صف پردازش و نتیجه‌اش بلافاصله
            // در همان ردیف نشان داده می‌شود. Action Scheduler به‌عنوان
            // پشتیبان (وقتی کاربر تب را می‌بندد) با همان Claim اتمیک می‌ماند
            // — یک ردیف هرگز دوبار پردازش نمی‌شود.
            let importWorkerRunning = false;
            function runImportWorkerStep() {
                const body = new URLSearchParams();
                body.set('action', 'hci_import_next');
                body.set('_ajax_nonce', importNextNonce);
                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.success || !d.data || d.data.done) {
                            importWorkerRunning = false;
                            return;
                        }
                        const id = String(d.data.source_product_id);
                        const status = d.data.import_status;
                        setRowStatus(id, status, d.data.error_message || '');
                        const btn = document.querySelector('.hci-import-btn[data-id="' + id + '"]');
                        if (btn) {
                            if (status === 'error' || status === 'partial') {
                                btn.disabled = false;
                                btn.textContent = 'تلاش مجدد';
                            } else {
                                btn.remove();
                            }
                        }
                        if (pendingIds.has(id)) {
                            pendingIds.delete(id);
                            doneCount++;
                            updateCounter();
                        }
                        runImportWorkerStep();
                    })
                    .catch(function () { importWorkerRunning = false; });
            }
            function startImportWorker() {
                if (importWorkerRunning) return;
                importWorkerRunning = true;
                runImportWorkerStep();
            }

            function buildEntry(row) {
                let entry = {};
                try { entry = JSON.parse(row.getAttribute('data-entry') || '{}'); } catch (e) { entry = {}; }
                const nameInput = row.querySelector('.hci-review-name');
                const descInput = row.querySelector('.hci-review-desc');
                entry.name = nameInput ? nameInput.value : entry.name;
                entry.short_description = descInput ? descInput.value : entry.short_description;
                return entry;
            }

            document.querySelectorAll('.hci-import-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const row = btn.closest('tr');
                    const id = row.getAttribute('data-id');
                    btn.disabled = true;

                    const body = new URLSearchParams();
                    body.set('action', 'hci_queue_import');
                    body.set('_ajax_nonce', queueNonce);
                    body.set('source_product_id', id);
                    body.set('source_sku', row.getAttribute('data-sku') || '');
                    body.set('entry_json', JSON.stringify(buildEntry(row)));

                    fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d && d.success) {
                                totalQueued++;
                                pendingIds.add(id);
                                markQueuedUI(id);
                                removeFromLocalSelection(id);
                                updateCounter();
                                startPolling();
                                startImportWorker();
                            } else {
                                btn.disabled = false;
                                alert((d && d.data && d.data.message) ? d.data.message : 'خطا در صف‌بندی.');
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            alert('خطا در ارتباط.');
                        });
                });
            });

            const batchBtn = document.getElementById('hci-import-batch');
            if (batchBtn) {
                batchBtn.addEventListener('click', function () {
                    const items = [];
                    const rows = [];
                    document.querySelectorAll('.hci-import-btn:not(:disabled)').forEach(function (btn) {
                        const row = btn.closest('tr');
                        items.push({
                            source_product_id: parseInt(row.getAttribute('data-id'), 10),
                            source_sku: row.getAttribute('data-sku') || '',
                            entry: buildEntry(row)
                        });
                        rows.push(row);
                    });
                    if (!items.length) { return; }
                    batchBtn.disabled = true;

                    const body = new URLSearchParams();
                    body.set('action', 'hci_queue_batch_import');
                    body.set('_ajax_nonce', queueNonce);
                    body.set('items', JSON.stringify(items));

                    fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            batchBtn.disabled = false;
                            if (!d || !d.success) { alert('خطا در صف‌بندی دسته‌ای.'); return; }
                            const skippedIds = (d.data.skipped || []).map(function (s) { return String(s.source_product_id); });
                            rows.forEach(function (row) {
                                const id = row.getAttribute('data-id');
                                if (skippedIds.indexOf(id) === -1) {
                                    totalQueued++;
                                    pendingIds.add(id);
                                    markQueuedUI(id);
                                    removeFromLocalSelection(id);
                                } else {
                                    const btn = row.querySelector('.hci-import-btn');
                                    if (btn) { btn.disabled = false; }
                                }
                            });
                            updateCounter();
                            startPolling();
                            startImportWorker();
                        })
                        .catch(function () {
                            batchBtn.disabled = false;
                            alert('خطا در ارتباط.');
                        });
                });
            }
        })();
        </script>
        <?php
    }

    private static function render_status_badge(?string $status, string $message): string {
        $map = array(
            HCI_DB::STATUS_IMPORTED => array('وارد شد ✓', '#008a20'),
            HCI_DB::STATUS_DUPLICATE => array('تکراری ✕', '#d63638'),
            HCI_DB::STATUS_ERROR => array('خطا ✕', '#d63638'),
            HCI_DB::STATUS_PARTIAL => array('ناقص ⚠', '#b26b00'),
            HCI_DB::STATUS_PROCESSING => array('در حال Import…', '#787c82'),
            HCI_DB::STATUS_QUEUED => array('در صف…', '#787c82'),
        );
        if ($status === null || !isset($map[$status])) {
            return '<span style="color:#787c82">آماده</span>';
        }
        [$label, $color] = $map[$status];
        $title = $message !== '' ? ' title="' . esc_attr($message) . '"' : '';
        return '<span style="color:' . esc_attr($color) . ';font-weight:bold"' . $title . '>' . esc_html($label) . '</span>';
    }
}
