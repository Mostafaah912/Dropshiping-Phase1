<?php

defined('ABSPATH') || exit;

final class HCI_DB {
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';

    public static function product_map_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hci_product_map';
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::product_map_table();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_product_id BIGINT UNSIGNED NOT NULL,
            source_sku VARCHAR(191) NULL,
            source_variation_id BIGINT UNSIGNED NULL,
            dest_product_id BIGINT UNSIGNED NULL,
            dest_variation_id BIGINT UNSIGNED NULL,
            dest_sku VARCHAR(191) NULL,
            import_payload LONGTEXT NULL,
            error_message TEXT NULL,
            last_synced_at DATETIME NULL,
            import_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY source_product_id (source_product_id),
            KEY source_sku (source_sku),
            KEY dest_product_id (dest_product_id),
            KEY import_status (import_status)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option('hci_db_version', HCI_VERSION, false);
    }

    public static function ensure_schema(): void {
        $version = (string) get_option('hci_db_version', '0');
        if (version_compare($version, HCI_VERSION, '<')) {
            self::create_tables();
        }
    }

    /**
     * شناسه‌های source_product_id که قبلاً حداقل یک رکورد (هر import_status)
     * در جدول ردیابی دارند — برای فیلتر «فقط وارد‌نشده‌ها» در گرید و تشخیص
     * Duplicate در جدول بازبینی.
     */
    public static function get_imported_source_ids(): array {
        global $wpdb;
        $rows = $wpdb->get_col('SELECT DISTINCT source_product_id FROM ' . self::product_map_table());
        return array_values(array_map('intval', is_array($rows) ? $rows : array()));
    }

    /**
     * لایه ۱/۲ تشخیص Duplicate واقعیِ Import (نه فیلتر گرید/جدول بازبینی):
     * آیا رکورد دیگری (با id متفاوت از ردیف در حال پردازش) با همین
     * source_product_id یا همین source_sku از قبل واقعاً 'imported' است؟
     * عمداً id فعلی را Exclude می‌کند چون ردیفِ خودِ محصولِ در حال پردازش هم
     * در جدول هست (با وضعیت queued/processing) و نباید Duplicate خودش تلقی شود.
     */
    public static function find_other_imported_row(int $exclude_row_id, int $source_product_id, ?string $source_sku): ?array {
        global $wpdb;
        $table = self::product_map_table();
        if ($source_sku !== null && $source_sku !== '') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id != %d AND import_status = 'imported' AND (source_product_id = %d OR source_sku = %s) LIMIT 1",
                $exclude_row_id,
                $source_product_id,
                $source_sku
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id != %d AND import_status = 'imported' AND source_product_id = %d LIMIT 1",
                $exclude_row_id,
                $source_product_id
            ), ARRAY_A);
        }
        return $row ?: null;
    }

    public static function get_imported_source_skus(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT source_sku FROM " . self::product_map_table() . " WHERE source_sku IS NOT NULL AND source_sku != ''"
        );
        return array_values(array_filter(is_array($rows) ? $rows : array()));
    }

    /**
     * والدِ (source_variation_id IS NULL) یک محصول را برمی‌گرداند، یا یک
     * Variation مشخص از آن اگر $source_variation_id داده شود.
     */
    public static function get_map_row(int $source_product_id, ?int $source_variation_id = null): ?array {
        global $wpdb;
        $table = self::product_map_table();
        if ($source_variation_id === null) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_product_id = %d AND source_variation_id IS NULL LIMIT 1",
                $source_product_id
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_product_id = %d AND source_variation_id = %d LIMIT 1",
                $source_product_id,
                $source_variation_id
            ), ARRAY_A);
        }
        return $row ?: null;
    }

    /**
     * محصول را برای Import صف می‌کند: اگر رکورد والد از قبل وجود دارد (مثلاً
     * از insert_pending فاز ۲ قدیمی، یا یک تلاش Import قبلی) همان ردیف را
     * به‌روزرسانی می‌کند — نه یک ردیف جدید — تا Retry واقعاً روی همان ردیف
     * (و همان dest_product_id احتمالی‌اش) ادامه پیدا کند، نه محصول تکراری.
     * $payload_json همان تصویر کامل ویرایش‌شده توسط کارمند (نام/توضیح/
     * تصاویر/Featured/attributes/variations) است که چون از Transient موقت
     * ۳۰ دقیقه‌ای عبور می‌کند، همین‌جا به‌صورت دائمی ذخیره می‌شود تا وقتی
     * Action Scheduler بعداً این محصول را پردازش کرد، هنوز در دسترس باشد.
     */
    public static function queue_import(int $source_product_id, ?string $source_sku, string $payload_json): array {
        global $wpdb;
        $now = current_time('mysql', true);
        $existing = self::get_map_row($source_product_id);

        if ($existing) {
            $result = $wpdb->update(
                self::product_map_table(),
                array(
                    'source_sku' => $source_sku,
                    'import_payload' => $payload_json,
                    'import_status' => self::STATUS_QUEUED,
                    'error_message' => null,
                    'updated_at' => $now,
                ),
                array('id' => (int) $existing['id'])
            );
            if ($result === false) {
                return array('success' => false, 'message' => $wpdb->last_error);
            }
            return array('success' => true, 'id' => (int) $existing['id']);
        }

        $result = $wpdb->insert(self::product_map_table(), array(
            'source_product_id' => $source_product_id,
            'source_sku' => $source_sku,
            'import_payload' => $payload_json,
            'import_status' => self::STATUS_QUEUED,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($result === false) {
            return array('success' => false, 'message' => $wpdb->last_error);
        }
        return array('success' => true, 'id' => (int) $wpdb->insert_id);
    }

    public static function mark_processing(int $source_product_id): void {
        global $wpdb;
        // عمداً با id (کلید اصلی) نه source_product_id+source_variation_id=NULL
        // فیلتر می‌کنیم: wpdb::update() مقدار null در آرایه‌ی $where را به‌شکل
        // ستون‌های نسخه‌های قدیمی به‌صورت «= NULL» می‌سازد که در SQL هرگز
        // Match نمی‌شود (باید IS NULL باشد) — استفاده از id این ابهام را کلاً
        // کنار می‌زند.
        $row = self::get_map_row($source_product_id);
        if (!$row) {
            return;
        }
        $wpdb->update(
            self::product_map_table(),
            array('import_status' => self::STATUS_PROCESSING, 'updated_at' => current_time('mysql', true)),
            array('id' => (int) $row['id'])
        );
    }

    /**
     * نتیجه‌ی نهایی (یا جزئی) پردازش والد یک محصول را ذخیره می‌کند.
     * dest_product_id عمداً پاک نمی‌شود مگر صریحاً در $data داده شود — چون
     * همین مقدار کلید Retry بدون ساخت محصول تکراری است.
     */
    public static function save_import_result(int $source_product_id, array $data): void {
        global $wpdb;
        $row = self::get_map_row($source_product_id);
        if (!$row) {
            return;
        }
        $update = array('updated_at' => current_time('mysql', true));
        foreach (array('dest_product_id', 'dest_sku', 'import_status', 'error_message') as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (($data['import_status'] ?? '') === self::STATUS_IMPORTED) {
            $update['last_synced_at'] = current_time('mysql', true);
        }
        $wpdb->update(
            self::product_map_table(),
            $update,
            array('id' => (int) $row['id'])
        );
    }

    /**
     * رکورد ردیابی یک Variation را درج/به‌روزرسانی می‌کند (Upsert دستی چون
     * ستون یکتای ترکیبی روی source_product_id+source_variation_id نداریم).
     */
    public static function upsert_variation_map(
        int $parent_source_id,
        int $source_variation_id,
        ?string $source_sku,
        ?int $dest_variation_id,
        ?string $dest_sku,
        string $import_status
    ): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $existing = self::get_map_row($parent_source_id, $source_variation_id);

        $fields = array(
            'source_sku' => $source_sku,
            'dest_variation_id' => $dest_variation_id,
            'dest_sku' => $dest_sku,
            'import_status' => $import_status,
            'last_synced_at' => $import_status === self::STATUS_IMPORTED ? $now : ($existing['last_synced_at'] ?? null),
            'updated_at' => $now,
        );

        if ($existing) {
            $wpdb->update(self::product_map_table(), $fields, array('id' => (int) $existing['id']));
            return;
        }

        $fields['source_product_id'] = $parent_source_id;
        $fields['source_variation_id'] = $source_variation_id;
        $fields['created_at'] = $now;
        $wpdb->insert(self::product_map_table(), $fields);
    }

    /**
     * برای Polling سبک صفحه بازبینی: فقط از همین جدول می‌خواند، هیچ درخواستی
     * به منبع نمی‌زند.
     */
    public static function get_import_statuses(array $source_product_ids): array {
        global $wpdb;
        $source_product_ids = array_values(array_unique(array_filter(array_map('intval', $source_product_ids))));
        if (!$source_product_ids) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($source_product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT source_product_id, import_status, dest_product_id, error_message FROM ' . self::product_map_table() .
            " WHERE source_product_id IN ({$placeholders}) AND source_variation_id IS NULL",
            $source_product_ids
        ), ARRAY_A);
        $result = array();
        foreach ((array) $rows as $row) {
            $result[(int) $row['source_product_id']] = array(
                'import_status' => (string) $row['import_status'],
                'dest_product_id' => $row['dest_product_id'] !== null ? (int) $row['dest_product_id'] : null,
                'error_message' => $row['error_message'],
            );
        }
        return $result;
    }

    /**
     * والدهای کاملاً Import‌شده — فقط این‌ها در سینک روزانه شرکت می‌کنند
     * (هیچ محصول ناخواسته‌ای هرگز ساخته نمی‌شود، فقط قیمت/موجودی رکوردهای
     * از قبل Import‌شده به‌روز می‌شود).
     */
    public static function get_imported_parent_rows(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::product_map_table() .
            " WHERE import_status = 'imported' AND source_variation_id IS NULL AND dest_product_id IS NOT NULL",
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    public static function get_imported_variation_rows_for_parents(array $parent_source_ids): array {
        global $wpdb;
        $parent_source_ids = array_values(array_unique(array_filter(array_map('intval', $parent_source_ids))));
        if (!$parent_source_ids) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($parent_source_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::product_map_table() .
            " WHERE source_product_id IN ({$placeholders}) AND source_variation_id IS NOT NULL AND import_status = 'imported' AND dest_variation_id IS NOT NULL",
            $parent_source_ids
        ), ARRAY_A);
        if (!is_array($rows)) {
            return array();
        }
        $grouped = array();
        foreach ($rows as $row) {
            $grouped[(int) $row['source_product_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * برای دکمه «ریست داده‌ها»: کل جدول ردیابی را خالی می‌کند (نه DROP، خودِ
     * ساختار جدول دست‌نخورده می‌ماند). DELETE به‌جای TRUNCATE استفاده شده چون
     * هم روی MySQL واقعی هم روی SQLite تست‌ها یکسان کار می‌کند.
     */
    public static function truncate_product_map(): int {
        global $wpdb;
        $result = $wpdb->query('DELETE FROM ' . self::product_map_table());
        return $result === false ? 0 : (int) $result;
    }
}
