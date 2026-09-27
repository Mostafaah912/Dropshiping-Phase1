<?php

defined('ABSPATH') || exit;

final class HCI_Products {
    private const CAPABILITY = 'manage_woocommerce';
    private const PER_PAGE = 48;
    private const SELECTION_TRANSIENT_PREFIX = 'hci_selection_';
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

        $imported_ids = array_flip(HCI_DB::get_imported_source_ids());

        $filtered = self::filter_items($items, $search, $category_id, $stock, $only_not_imported, $imported_ids);
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
        echo '<button type="submit" class="button button-primary" style="margin-right:8px">مرحله بعد ←</button></p>';
        echo '<textarea name="selection_json" id="hci-selection-json" style="display:none"></textarea>';

        echo '<div class="hci-grid">';
        foreach ($page_items as $item) {
            self::render_card($item, isset($imported_ids[(int) $item['source_product_id']]));
        }
        echo '</div>';

        echo '</form>';

        self::render_pagination($paged, $total_pages);
        self::render_modal();
        self::render_grid_script($page_items);

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

    private static function render_card(array $item, bool $already_imported): void {
        $id = (int) ($item['source_product_id'] ?? 0);
        $image = $item['image_url'] ?? '';
        $name = (string) ($item['name'] ?? '');
        $price = $item['price'] !== null && $item['price'] !== '' ? $item['price'] : '-';
        ?>
        <div class="hci-card" data-id="<?php echo esc_attr((string) $id); ?>">
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
                    <input type="checkbox" class="hci-card-checkbox" data-id="<?php echo esc_attr((string) $id); ?>" <?php disabled($already_imported); ?>>
                    انتخاب
                </label>
                <button type="button" class="button hci-sell-btn" data-id="<?php echo esc_attr((string) $id); ?>" <?php disabled($already_imported); ?>>بفروشش</button>
            </div>
            <?php if ($already_imported) : ?>
                <div class="hci-card-badge">قبلاً ثبت شده</div>
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
                        <tr><th>توضیح کوتاه</th><td><textarea id="hci-modal-desc" rows="4" style="width:100%"></textarea></td></tr>
                        <tr><th>گالری تصاویر</th><td><div id="hci-modal-gallery"></div></td></tr>
                        <tr><th>سلسله دسته‌بندی</th><td id="hci-modal-categories"></td></tr>
                        <tr><th>قیمت محاسبه‌شده</th><td id="hci-modal-price"></td></tr>
                        <tr><th>وضعیت انبار</th><td id="hci-modal-stock"></td></tr>
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
            .hci-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:100000; display:flex; align-items:center; justify-content:center; }
            .hci-modal { background:#fff; padding:24px; max-width:640px; width:92%; max-height:85vh; overflow:auto; border-radius:4px; }
            .hci-gallery-item { display:inline-block; position:relative; margin:4px; text-align:center; }
            .hci-gallery-item img { width:80px; height:80px; object-fit:cover; display:block; }
            .hci-gallery-remove { position:absolute; top:-6px; right:-6px; background:#d63638; color:#fff; border:none; border-radius:50%; width:20px; height:20px; cursor:pointer; }
        </style>
        <?php
    }

    private static function render_grid_script(array $page_items): void {
        $products_json = array();
        foreach ($page_items as $item) {
            $id = (int) ($item['source_product_id'] ?? 0);
            $category_names = array_map(static fn ($n) => (string) ($n['name'] ?? ''), (array) ($item['category_path'] ?? array()));
            $products_json[$id] = array(
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'short_description' => (string) ($item['short_description'] ?? ''),
                'price' => $item['price'],
                'stock_status' => (string) ($item['stock_status'] ?? ''),
                'category_names' => $category_names,
                'image_url' => $item['image_url'],
                'gallery' => (array) ($item['gallery'] ?? array()),
            );
        }
        ?>
        <script>
        (function () {
            const PRODUCTS = <?php echo wp_json_encode($products_json, JSON_UNESCAPED_UNICODE); ?>;
            const STORAGE_KEY = 'hci_selection_v1';

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

            function defaultEntry(product) {
                return {
                    source_product_id: product.id,
                    name: product.name,
                    short_description: product.short_description,
                    price: product.price,
                    category_names: product.category_names,
                    featured_image: product.image_url,
                    images: (product.gallery || []).slice(),
                };
            }

            function syncCheckboxes() {
                document.querySelectorAll('.hci-card-checkbox').forEach(function (cb) {
                    const id = cb.getAttribute('data-id');
                    cb.checked = !!selection[id];
                });
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
                });
            });

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
                const allImages = [];
                if (modalState.featured_image) allImages.push(modalState.featured_image);
                modalState.images.forEach(function (url) { if (url !== modalState.featured_image) allImages.push(url); });

                allImages.forEach(function (url) {
                    const wrap = document.createElement('div');
                    wrap.className = 'hci-gallery-item';

                    const img = document.createElement('img');
                    img.src = url;
                    wrap.appendChild(img);

                    const radioLabel = document.createElement('label');
                    radioLabel.style.cssText = 'display:block;font-size:11px;cursor:pointer';
                    const radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'hci-featured';
                    radio.checked = (url === modalState.featured_image);
                    radio.addEventListener('change', function () {
                        modalState.featured_image = url;
                        renderGallery();
                    });
                    radioLabel.appendChild(radio);
                    radioLabel.appendChild(document.createTextNode(' تصویر اصلی'));
                    wrap.appendChild(document.createElement('br'));
                    wrap.appendChild(radioLabel);

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'hci-gallery-remove';
                    removeBtn.textContent = '×';
                    removeBtn.addEventListener('click', function () {
                        modalState.images = modalState.images.filter(function (u) { return u !== url; });
                        if (modalState.featured_image === url) {
                            modalState.featured_image = modalState.images[0] || null;
                        }
                        renderGallery();
                    });
                    wrap.appendChild(removeBtn);

                    container.appendChild(wrap);
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

    public static function render_review_page(): void {
        self::guard();

        $selection = get_transient(self::selection_key());
        echo '<div class="wrap"><h1>بازبینی و Import</h1>';

        if (!is_array($selection) || !$selection) {
            echo '<p>چیزی برای بازبینی انتخاب نشده. <a href="' . esc_url(admin_url('admin.php?page=heymode-client-importer-products')) . '">بازگشت به محصولات</a></p></div>';
            return;
        }

        $imported_ids = array_flip(HCI_DB::get_imported_source_ids());
        $imported_skus = array_flip(HCI_DB::get_imported_source_skus());

        ?>
        <table class="widefat striped" style="max-width:1200px">
            <thead>
                <tr>
                    <th>ردیف</th><th>عکس</th><th>نام</th><th>سلسله دسته‌بندی</th><th>قیمت</th><th>توضیح کوتاه</th><th>وضعیت SKU</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php $row_number = 1; foreach ($selection as $source_product_id => $entry) :
                    $source_product_id = (int) $source_product_id;
                    $source_sku = (string) ($entry['source_sku'] ?? '');
                    $is_duplicate = isset($imported_ids[$source_product_id]) || ($source_sku !== '' && isset($imported_skus[$source_sku]));
                    $raw_price = $entry['price'] ?? null;
                    $final_price = ($raw_price !== null && $raw_price !== '') ? HCI_Pricing::resolve_price((float) $raw_price) : null;
                    ?>
                    <tr data-id="<?php echo esc_attr((string) $source_product_id); ?>">
                        <td><?php echo esc_html((string) $row_number++); ?></td>
                        <td><?php if (!empty($entry['featured_image'])) : ?><img src="<?php echo esc_url($entry['featured_image']); ?>" style="width:48px;height:48px;object-fit:cover"><?php endif; ?></td>
                        <td><input type="text" value="<?php echo esc_attr((string) ($entry['name'] ?? '')); ?>" style="width:100%"></td>
                        <td><?php echo esc_html(implode(' > ', (array) ($entry['category_names'] ?? array()))); ?></td>
                        <td><?php echo $final_price !== null ? esc_html(number_format($final_price, 0)) : '-'; ?></td>
                        <td><textarea rows="2" style="width:100%"><?php echo esc_textarea((string) ($entry['short_description'] ?? '')); ?></textarea></td>
                        <td>
                            <?php if ($is_duplicate) : ?>
                                <span style="color:#d63638;font-weight:bold">✕ قبلاً وارد شده</span>
                            <?php else : ?>
                                <span style="color:#008a20">✓ جدید</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_duplicate) : ?>
                                <button type="button" class="button" disabled>وارد کن</button>
                            <?php else : ?>
                                <button type="button" class="button hci-import-btn" data-id="<?php echo esc_attr((string) $source_product_id); ?>" data-sku="<?php echo esc_attr($source_sku); ?>">وارد کن</button>
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
            const nonce = <?php echo wp_json_encode(wp_create_nonce('hci_mark_pending')); ?>;
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            document.querySelectorAll('.hci-import-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    const body = new URLSearchParams();
                    body.set('action', 'hci_mark_pending');
                    body.set('_ajax_nonce', nonce);
                    body.set('source_product_id', btn.getAttribute('data-id'));
                    body.set('source_sku', btn.getAttribute('data-sku') || '');
                    fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d && d.success) {
                                btn.textContent = 'ثبت شد ✓';
                            } else {
                                btn.disabled = false;
                                alert((d && d.data && d.data.message) ? d.data.message : 'خطا در ثبت.');
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            alert('خطا در ارتباط.');
                        });
                });
            });
        })();
        </script>
        <?php
    }

    public static function ajax_mark_pending(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hci_mark_pending');

        $source_product_id = isset($_POST['source_product_id']) ? (int) $_POST['source_product_id'] : 0;
        $source_sku = isset($_POST['source_sku']) ? sanitize_text_field(wp_unslash($_POST['source_sku'])) : '';

        if ($source_product_id <= 0) {
            wp_send_json_error(array('message' => 'source_product_id نامعتبر است.'), 400);
        }

        if (in_array($source_product_id, HCI_DB::get_imported_source_ids(), true)) {
            wp_send_json_error(array('message' => 'این محصول قبلاً ثبت شده است.'), 409);
        }

        $result = HCI_DB::insert_pending($source_product_id, $source_sku !== '' ? $source_sku : null);
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result, 500);
    }
}
