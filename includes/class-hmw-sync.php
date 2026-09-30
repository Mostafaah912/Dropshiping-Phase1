<?php

defined('ABSPATH') || exit;

final class HMW_Sync {
    private const FULL_STATE_OPTION = 'hmw_full_sync_state';
    private const CATEGORY_OPTION = 'hmw_source_category_map';
    private const LOCK_TRANSIENT = 'hmw_sync_lock';
    private const CRON_HOOK = 'hmw_daily_full_sync';
    private const CONTINUE_HOOK = 'hmw_full_continue';
    private const LEGACY_CRON_HOOK = 'hmw_daily_incremental_sync';
    private const LEGACY_CONTINUE_HOOK = 'hmw_incremental_continue';
    private const PER_PAGE = 100;
    private const TIME_BUDGET_MS = 18000;
    private const STOP_MARGIN_MS = 2000;

    public static function activate(): void {
        HMW_DB::create_tables();
        self::ensure_options();
        self::ensure_cron_schedule();
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void {
        self::clear_cron_schedule();
    }

    public static function ensure_options(): void {
        if (get_option(self::FULL_STATE_OPTION, null) === null) {
            add_option(self::FULL_STATE_OPTION, array('status' => 'idle'), '', false);
        }
    }

    public static function get_state(): array {
        $state = get_option(self::FULL_STATE_OPTION, array('status' => 'idle'));
        return is_array($state) ? $state : array('status' => 'idle');
    }

    public static function ensure_cron_schedule(): void {
        // پاک‌سازی رویدادهای نسخه‌های قبلی که Incremental را اجرا می‌کردند.
        self::unschedule_all(self::LEGACY_CRON_HOOK);
        self::unschedule_all(self::LEGACY_CONTINUE_HOOK);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $tz = new DateTimeZone(HMW_TIMEZONE);
            $now = new DateTimeImmutable('now', $tz);
            $next = $now->setTime(4, 0, 0);
            if ($next <= $now) {
                $next = $next->modify('+1 day');
            }
            wp_schedule_event($next->getTimestamp(), 'daily', self::CRON_HOOK);
        }
    }

    public static function clear_cron_schedule(): void {
        self::unschedule_all(self::CRON_HOOK);
        self::unschedule_all(self::CONTINUE_HOOK);
        self::unschedule_all(self::LEGACY_CRON_HOOK);
        self::unschedule_all(self::LEGACY_CONTINUE_HOOK);
    }

    public static function cron_full(): void {
        $state = self::get_state();

        if (($state['status'] ?? 'idle') === 'running') {
            $result = self::tick_full();
            if (($result['state']['status'] ?? 'idle') === 'running') {
                self::schedule_full_continue();
            }
            return;
        }

        $result = self::start_full_sync('cron');
        if (!empty($result['success'])) {
            $tick = self::tick_full();
            if (($tick['state']['status'] ?? 'idle') === 'running') {
                self::schedule_full_continue();
            }
        }
    }

    public static function cron_full_continue(): void {
        $state = self::get_state();
        if (($state['status'] ?? 'idle') !== 'running') {
            self::unschedule_full_continue();
            return;
        }

        $result = self::tick_full();
        if (($result['state']['status'] ?? 'idle') === 'running') {
            self::schedule_full_continue();
        }
    }

    public static function start_full_sync(string $trigger = 'manual'): array {
        self::ensure_options();
        HMW_DB::ensure_schema();
        if (!HMW_Source_API::is_configured()) {
            return array('success' => false, 'message' => 'Credentialهای WooCommerce تنظیم نشده‌اند.');
        }
        if (self::is_any_sync_running()) {
            return array('success' => false, 'message' => 'Sync دیگری در حال اجرا است.');
        }

        global $wpdb;
        $run_uuid = wp_generate_uuid4();
        $started_at = current_time('mysql', true);

        $ok = $wpdb->insert(HMW_DB::sync_logs_table(), array(
            'run_uuid' => $run_uuid,
            'sync_type' => 'full',
            'trigger_type' => in_array($trigger, array('manual', 'cron'), true) ? $trigger : 'system',
            'status' => 'running',
            'started_at' => $started_at,
            'created_at' => $started_at,
        ));
        if ($ok === false) {
            return array('success' => false, 'message' => 'ساخت لاگ Full Sync ناموفق بود.');
        }

        self::refresh_category_map(true);

        $state = array(
            'status' => 'running',
            'run_uuid' => $run_uuid,
            'log_id' => (int) $wpdb->insert_id,
            'phase' => 'products',
            'page' => 1,
            'total_pages' => 0,
            'index' => 0,
            'variation_parent_index' => 0,
            'variation_parent_total' => 0,
            'variation_parent_ids' => array(),
            'variation_page' => 1,
            'variation_index' => 0,
            'products_fetched' => 0,
            'products_inserted' => 0,
            'products_updated' => 0,
            'products_unchanged' => 0,
            'products_deactivated' => 0,
            'errors_count' => 0,
            'errors' => array(),
            'api_requests' => 0,
            'started_at' => $started_at,
        );
        update_option(self::FULL_STATE_OPTION, $state, false);
        return array('success' => true, 'state' => $state);
    }

    public static function cancel_full_sync(): array {
        $state = self::get_state();
        if (($state['status'] ?? 'idle') !== 'running') {
            return array('success' => false, 'message' => 'Full Sync فعالی وجود ندارد.');
        }
        self::finish_log($state, 'cancelled');
        self::unschedule_full_continue();
        $state['status'] = 'cancelled';
        update_option(self::FULL_STATE_OPTION, $state, false);
        return array('success' => true, 'state' => $state);
    }

    public static function tick_full(): array {
        $state = self::get_state();
        if (($state['status'] ?? 'idle') !== 'running') {
            return array('success' => true, 'state' => $state, 'finished' => true);
        }
        if (get_transient(self::LOCK_TRANSIENT)) {
            return array('success' => true, 'state' => $state, 'busy' => true, 'finished' => false);
        }
        set_transient(self::LOCK_TRANSIENT, 1, 90);
        try {
            return self::process_full_tick($state);
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    private static function process_full_tick(array $state): array {
        $runtime = microtime(true);
        $categories = self::get_category_map();
        if (!$categories['success']) {
            return self::fail_full($state, $categories['message']);
        }

        // هر دو Phase تا رسیدن به مرز Time Budget (should_checkpoint) در همان
        // Tick ادامه می‌دهند و چند صفحه/چند Parent را پردازش می‌کنند؛ قبلاً هر
        // Tick فقط دقیقاً یک صفحه (در Variations: یک صفحه از یک Parent) پردازش
        // می‌شد و بلافاصله برمی‌گشت. در اجرای Cron که هر Tick با ۵ ثانیه فاصله
        // زمان‌بندی می‌شود، این یعنی بیشترِ زمان صرف صبر بین Tickها می‌شد نه
        // پردازش واقعی — bottleneck اصلی Full Sync، به‌خصوص فاز Variations.
        if (($state['phase'] ?? '') === 'products') {
            while (true) {
                if (self::should_checkpoint($runtime)) {
                    self::save_full($state);
                    return array('success' => true, 'state' => $state, 'finished' => false);
                }

                $result = HMW_Source_API::get_products_page((int) $state['page'], self::PER_PAGE);
                $state['api_requests'] += (int) ($result['api_requests'] ?? 0);
                if (!$result['success']) {
                    return self::fail_full($state, $result['message']);
                }
                $items = is_array($result['data']) ? $result['data'] : array();
                $state['total_pages'] = !empty($result['headers']['total_pages']) ? (int) $result['headers']['total_pages'] : (int) $state['page'];

                $payloads = array();
                $non_variable_ids = array();
                foreach ($items as $item) {
                    $payloads[] = self::product_payload($item, null, $categories['map'], $state['run_uuid']);
                    if (($item['type'] ?? '') !== 'variable') {
                        $non_variable_ids[] = (int) ($item['id'] ?? 0);
                    }
                }

                if ($payloads) {
                    // یک Bulk Upsert برای کل صفحه، به‌جای یک SELECT + یک INSERT/UPDATE
                    // جداگانه برای هر محصول (همان الگویی که فاز Variations قبلاً داشت).
                    $bulk = HMW_DB::bulk_upsert_products($payloads);
                    if (!$bulk['success']) {
                        return self::fail_full($state, $bulk['message']);
                    }
                    $state['products_fetched'] += count($payloads);
                    $state['products_inserted'] += (int) ($bulk['inserted'] ?? 0);
                    $state['products_updated'] += (int) ($bulk['updated'] ?? 0);
                    $state['products_unchanged'] += (int) ($bulk['unchanged'] ?? 0);
                }
                if ($non_variable_ids) {
                    $state['products_deactivated'] += HMW_DB::deactivate_variations_of_parents($non_variable_ids, $state['run_uuid']);
                }

                $is_last_page = empty($items) || count($items) < self::PER_PAGE || (int) $state['page'] >= (int) $state['total_pages'];
                if ($is_last_page) {
                    $state['variation_parent_ids'] = HMW_DB::get_active_variable_parent_ids();
                    $state['variation_parent_index'] = 0;
                    $state['variation_parent_total'] = count($state['variation_parent_ids']);
                    $state['variation_page'] = 1;
                    $state['variation_index'] = 0;
                    $state['phase'] = 'variations';
                    self::save_full($state);
                    return array('success' => true, 'state' => $state, 'finished' => false);
                }
                $state['page']++;
            }
        }

        if (($state['phase'] ?? '') === 'variations') {
            $parent_ids = array_values(array_map('intval', (array) ($state['variation_parent_ids'] ?? array())));
            $parent_map = null;

            while (true) {
                $parent_index = (int) ($state['variation_parent_index'] ?? 0);

                if ($parent_index >= count($parent_ids)) {
                    $state['products_deactivated'] += HMW_DB::finalize_full_sync($state['run_uuid']);
                    self::finish_log($state, 'success');
                    $state['status'] = 'success';
                    self::unschedule_full_continue();
                    update_option(self::FULL_STATE_OPTION, $state, false);
                    return array('success' => true, 'state' => $state, 'finished' => true);
                }

                if (self::should_checkpoint($runtime)) {
                    self::save_full($state);
                    return array('success' => true, 'state' => $state, 'finished' => false);
                }

                // والدهای Variable را یک‌بار در هر Tick (نه یک‌بار در هر Parent) Bulk
                // می‌خوانیم؛ قبلاً get_product_row() به ازای هر Parent یک SELECT
                // جداگانه اجرا می‌کرد (N+1) که همراه با محدودیت «یک صفحه در هر Tick»
                // دلیل اصلی کندی فاز Variations بود.
                if ($parent_map === null) {
                    $parent_map = array();
                    foreach (HMW_DB::get_variable_parent_rows() as $row) {
                        $parent_map[(int) $row['source_product_id']] = $row;
                    }
                }

                $parent_id = $parent_ids[$parent_index];
                $parent = $parent_map[$parent_id] ?? null;
                if (!$parent) {
                    $state['variation_parent_index']++;
                    $state['variation_page'] = 1;
                    $state['variation_index'] = 0;
                    continue;
                }

                $result = HMW_Source_API::get_variations_page($parent_id, (int) $state['variation_page'], self::PER_PAGE);
                $state['api_requests'] += (int) ($result['api_requests'] ?? 0);
                if (!$result['success']) {
                    return self::fail_full($state, $result['message']);
                }
                $items = is_array($result['data']) ? $result['data'] : array();
                $state['total_pages'] = !empty($result['headers']['total_pages']) ? (int) $result['headers']['total_pages'] : (int) $state['variation_page'];

                $payloads = array();
                foreach ($items as $variation) {
                    $payloads[] = self::variation_payload($variation, $parent, $state['run_uuid']);
                }

                if ($payloads) {
                    $bulk = HMW_DB::bulk_upsert_products($payloads);
                    if (!$bulk['success']) {
                        return self::fail_full($state, $bulk['message']);
                    }
                    $state['products_fetched'] += count($payloads);
                    $state['products_inserted'] += (int) ($bulk['inserted'] ?? 0);
                    $state['products_updated'] += (int) ($bulk['updated'] ?? 0);
                    $state['products_unchanged'] += (int) ($bulk['unchanged'] ?? 0);
                }
                $state['variation_index'] = count($items);

                if (empty($items) || (int) $state['variation_page'] >= (int) $state['total_pages']) {
                    $state['products_deactivated'] += HMW_DB::deactivate_variations_not_seen($parent_id, $state['run_uuid']);
                    $state['variation_parent_index']++;
                    $state['variation_page'] = 1;
                    $state['variation_index'] = 0;
                } else {
                    $state['variation_page']++;
                    $state['variation_index'] = 0;
                }
            }
        }

        return self::fail_full($state, 'Phase مربوط به Full Sync نامعتبر است.');
    }

    private static function product_payload(array $product, ?array $parent, array $category_map, string $run_uuid): array {
        $category_ids = array();
        if (!empty($product['categories']) && is_array($product['categories'])) {
            foreach ($product['categories'] as $category) {
                if (isset($category['id'])) {
                    $category_ids[] = (int) $category['id'];
                }
            }
        }

        $category_path = $category_ids ? HMW_Source_API::category_path($category_ids, $category_map) : '';

        $gallery = array();
        if (!empty($product['images']) && is_array($product['images'])) {
            foreach (array_slice($product['images'], 1) as $image) {
                if (!empty($image['src'])) {
                    $gallery[] = (string) $image['src'];
                }
            }
        }

        $attributes = array();
        if (!empty($product['attributes']) && is_array($product['attributes'])) {
            foreach ($product['attributes'] as $attribute) {
                $attributes[] = array(
                    'name' => (string) ($attribute['name'] ?? ''),
                    'options' => isset($attribute['options']) && is_array($attribute['options'])
                        ? array_values(array_map('strval', $attribute['options']))
                        : array(),
                );
            }
        }

        return array(
            'source_product_id' => (int) ($product['id'] ?? 0),
            'parent_product_id' => !empty($product['parent']) ? (int) $product['parent'] : 0,
            'product_type' => (string) ($product['type'] ?? 'simple'),
            'source_status' => (string) ($product['status'] ?? 'publish'),
            'sku' => ($product['sku'] ?? '') !== '' ? (string) $product['sku'] : null,
            'name' => (string) ($product['name'] ?? ''),
            'price' => ($product['price'] ?? '') !== '' ? (string) $product['price'] : null,
            'stock_quantity' => array_key_exists('stock_quantity', $product) && $product['stock_quantity'] !== null ? (string) $product['stock_quantity'] : null,
            'stock_status' => (string) ($product['stock_status'] ?? 'outofstock'),
            'manage_stock' => !empty($product['manage_stock']) ? 1 : 0,
            'image_url' => self::product_image_url($product, $parent),
            'product_url' => !empty($product['permalink']) ? (string) $product['permalink'] : null,
            'category_path' => $category_path,
            'short_description' => (string) ($product['short_description'] ?? ''),
            'gallery' => $gallery ? wp_json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'category_ids' => $category_ids ? wp_json_encode(array_values(array_unique($category_ids))) : '',
            'attributes' => $attributes ? wp_json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'source_modified_gmt' => self::normalize_gmt($product['date_modified_gmt'] ?? null),
            'is_active' => (($product['status'] ?? 'publish') === 'publish') ? 1 : 0,
            'run_uuid' => $run_uuid,
        );
    }

    private static function variation_payload(array $variation, array $parent, string $run_uuid): array {
        $variation['type'] = 'variation';
        $variation['parent'] = (int) ($parent['source_product_id'] ?? 0);
        $variation['name'] = self::variation_name($parent, $variation);
        $variation['permalink'] = $variation['permalink'] ?? ($parent['product_url'] ?? null);
        $parent_category_path = (string) ($parent['category_path'] ?? '');
        // ووکامرس مبدا در REST برای manage_stock هر Variation یا true/false
        // برمی‌گرداند یا رشته 'parent' (موجودی از والد ارث برده می‌شود). عدد
        // موجودی فقط وقتی خودِ Variation موجودی را مدیریت می‌کند معتبر است؛
        // در حالت 'parent' مقدار stock_quantity در واقع عدد والد است و نباید
        // به‌عنوان موجودی این Variation ذخیره شود. stock_status ووکامرس همیشه
        // همان وضعیت «مؤثر» (چیزی که به مشتری نشان می‌دهد) است.
        $own_stock = ($variation['manage_stock'] ?? false) === true;

        $attributes = array();
        if (!empty($variation['attributes']) && is_array($variation['attributes'])) {
            foreach ($variation['attributes'] as $attribute) {
                $attributes[] = array(
                    'name' => (string) ($attribute['name'] ?? ''),
                    'option' => (string) ($attribute['option'] ?? ''),
                );
            }
        }

        return array(
            'source_product_id' => (int) ($variation['id'] ?? 0),
            'parent_product_id' => (int) ($variation['parent'] ?? 0),
            'product_type' => 'variation',
            'source_status' => (string) ($variation['status'] ?? 'publish'),
            'sku' => ($variation['sku'] ?? '') !== '' ? (string) $variation['sku'] : null,
            'name' => (string) $variation['name'],
            'price' => ($variation['price'] ?? '') !== '' ? (string) $variation['price'] : null,
            'stock_quantity' => $own_stock && array_key_exists('stock_quantity', $variation) && $variation['stock_quantity'] !== null ? (string) $variation['stock_quantity'] : null,
            'stock_status' => in_array($variation['stock_status'] ?? '', array('instock', 'outofstock', 'onbackorder'), true) ? (string) $variation['stock_status'] : 'outofstock',
            'manage_stock' => $own_stock ? 1 : 0,
            'image_url' => self::product_image_url($variation, $parent),
            'product_url' => !empty($variation['permalink']) ? (string) $variation['permalink'] : (string) ($parent['product_url'] ?? ''),
            'category_path' => $parent_category_path,
            'attributes' => $attributes ? wp_json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'source_modified_gmt' => self::normalize_gmt($variation['date_modified_gmt'] ?? null),
            'is_active' => (($variation['status'] ?? 'publish') === 'publish') ? 1 : 0,
            'run_uuid' => $run_uuid,
        );
    }

    private static function product_image_url(array $product, ?array $parent): ?string {
        if (!empty($product['images'][0]['src'])) {
            return (string) $product['images'][0]['src'];
        }
        if (!empty($product['image']['src'])) {
            return (string) $product['image']['src'];
        }
        if ($parent && !empty($parent['image_url'])) {
            return (string) $parent['image_url'];
        }
        return null;
    }

    private static function variation_name(array $parent, array $variation): string {
        $base = (string) ($parent['name'] ?? '');
        $parts = array();
        if (!empty($variation['attributes']) && is_array($variation['attributes'])) {
            foreach ($variation['attributes'] as $attribute) {
                $name = trim((string) ($attribute['name'] ?? ''));
                $option = trim((string) ($attribute['option'] ?? ''));
                if ($name !== '' && $option !== '') {
                    $parts[] = $name . ': ' . $option;
                }
            }
        }
        if ($parts) {
            return $base . ' — ' . implode(' | ', $parts);
        }
        $description = !empty($variation['description']) ? trim(wp_strip_all_tags((string) $variation['description'])) : '';
        return $description !== '' ? $base . ' — ' . $description : $base . ' — Variation #' . (int) ($variation['id'] ?? 0);
    }

    private static function normalize_gmt($value): ?string {
        if (empty($value)) {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    public static function get_cached_category_map(): array {
        $cached = get_option(self::CATEGORY_OPTION, array());
        return is_array($cached) ? $cached : array();
    }

    private static function get_category_map(): array {
        $cached = get_option(self::CATEGORY_OPTION, null);
        if (is_array($cached) && !empty($cached)) {
            return array('success' => true, 'map' => $cached);
        }
        return self::refresh_category_map(true);
    }

    private static function refresh_category_map(bool $force = false): array {
        if (!$force) {
            $cached = get_option(self::CATEGORY_OPTION, null);
            if (is_array($cached) && !empty($cached)) {
                return array('success' => true, 'map' => $cached);
            }
        }
        $result = HMW_Source_API::get_categories_map();
        if (!$result['success']) {
            return $result;
        }
        update_option(self::CATEGORY_OPTION, $result['map'], false);
        return array('success' => true, 'map' => $result['map']);
    }

    private static function should_checkpoint(float $runtime_start): bool {
        return ((microtime(true) - $runtime_start) * 1000) >= (self::TIME_BUDGET_MS - self::STOP_MARGIN_MS);
    }

    private static function is_any_sync_running(): bool {
        return (self::get_state()['status'] ?? 'idle') === 'running';
    }

    private static function increment_action(array &$state, string $action): void {
        if ($action === 'inserted') {
            $state['products_inserted']++;
        } elseif ($action === 'updated') {
            $state['products_updated']++;
        } elseif ($action === 'unchanged') {
            $state['products_unchanged']++;
        }
    }

    private static function save_full(array $state): void {
        self::update_log_progress($state);
        update_option(self::FULL_STATE_OPTION, $state, false);
    }

    private static function update_log_progress(array $state): void {
        global $wpdb;
        $log_id = (int) ($state['log_id'] ?? 0);
        if (!$log_id) {
            return;
        }
        $wpdb->update(
            HMW_DB::sync_logs_table(),
            array(
                'products_fetched' => (int) ($state['products_fetched'] ?? 0),
                'products_inserted' => (int) ($state['products_inserted'] ?? 0),
                'products_updated' => (int) ($state['products_updated'] ?? 0),
                'products_unchanged' => (int) ($state['products_unchanged'] ?? 0),
                'products_deactivated' => (int) ($state['products_deactivated'] ?? 0),
                'errors_count' => (int) ($state['errors_count'] ?? 0),
                'error_summary' => !empty($state['errors']) ? implode("\n", array_slice((array) $state['errors'], -10)) : null,
                'api_requests' => (int) ($state['api_requests'] ?? 0),
            ),
            array('id' => $log_id)
        );
    }

    private static function finish_log(array $state, string $status): void {
        global $wpdb;
        $log_id = (int) ($state['log_id'] ?? 0);
        if (!$log_id) {
            return;
        }
        $finished_at = current_time('mysql', true);
        $started_ts = !empty($state['started_at']) ? strtotime((string) $state['started_at'] . ' UTC') : time();
        $duration = max(0, (int) round((microtime(true) - (float) ($state['_runtime_started'] ?? microtime(true))) * 1000));
        if ($started_ts) {
            $duration = max(0, ((int) $finished_at === 0 ? 0 : (strtotime($finished_at . ' UTC') - $started_ts) * 1000));
        }
        $wpdb->update(
            HMW_DB::sync_logs_table(),
            array(
                'status' => $status,
                'finished_at' => $finished_at,
                'duration_ms' => $duration,
                'products_fetched' => (int) ($state['products_fetched'] ?? 0),
                'products_inserted' => (int) ($state['products_inserted'] ?? 0),
                'products_updated' => (int) ($state['products_updated'] ?? 0),
                'products_unchanged' => (int) ($state['products_unchanged'] ?? 0),
                'products_deactivated' => (int) ($state['products_deactivated'] ?? 0),
                'errors_count' => (int) ($state['errors_count'] ?? 0),
                'error_summary' => !empty($state['errors']) ? implode("\n", array_slice((array) $state['errors'], -10)) : null,
                'error_details' => !empty($state['errors']) ? wp_json_encode($state['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'api_requests' => (int) ($state['api_requests'] ?? 0),
            ),
            array('id' => $log_id)
        );
    }

    private static function fail_full(array $state, string $message): array {
        $state['errors_count']++;
        $state['errors'][] = $message;
        self::finish_log($state, 'error');
        $state['status'] = 'error';
        self::unschedule_full_continue();
        update_option(self::FULL_STATE_OPTION, $state, false);
        return array('success' => false, 'state' => $state, 'finished' => true, 'message' => $message);
    }
    private static function schedule_full_continue(): void {
        if (!wp_next_scheduled(self::CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CONTINUE_HOOK);
        }
    }

    private static function unschedule_full_continue(): void {
        self::unschedule_all(self::CONTINUE_HOOK);
    }

    private static function unschedule_all(string $hook): void {
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
    }
}

