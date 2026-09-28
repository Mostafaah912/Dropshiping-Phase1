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
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
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

    /**
     * شناسه‌هایی که واقعاً باید کارت‌شان در گرید غیرفعال شود: Import کامل شده
     * (imported) یا الان در حال پردازش/صف است (queued/processing — برای
     * جلوگیری از صف‌بندی دوباره‌ی هم‌زمان). عمداً error/partial/duplicate را
     * شامل نمی‌شود — برخلاف نسخه قبلی (get_imported_source_ids، که هر ردیفی
     * با هر وضعیتی را «قبلاً ثبت شده» می‌دانست)، چون همان رفتار باعث می‌شد
     * محصولی که Import‌اش شکست خورد یا در صف کند/خراب گیر کرد، برای همیشه از
     * گرید غیرفعال و غیرقابل‌انتخاب دوباره بماند — دقیقاً همان چیزی که باعث
     * می‌شد «اضافه» در مودال دیگر هرگز محصول را به جدول بازبینی نرساند.
     */
    public static function get_grid_locked_source_ids(): array {
        global $wpdb;
        $table = self::product_map_table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT source_product_id FROM {$table} WHERE source_variation_id IS NULL AND import_status IN (%s, %s, %s)",
            self::STATUS_IMPORTED,
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING
        ));
        return array_values(array_map('intval', is_array($rows) ? $rows : array()));
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

    /**
     * قفل‌های قدیمی (ردیف‌هایی که processing مانده‌اند ولی started_at از حد
     * مشخص قدیمی‌تر است — یعنی کارگری که وسط کار قطع/کرش شده) را به queued
     * برمی‌گرداند تا دوباره Claim‌پذیر شوند. هم مسیر AJAX (hci_import_next)
     * هم Action Scheduler قبل از Claim کردن این را صدا می‌زنند.
     */
    public static function release_stale_locks(int $stale_after_seconds = 300): int {
        global $wpdb;
        $table = self::product_map_table();
        $threshold = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET import_status = %s, updated_at = %s WHERE import_status = %s AND started_at IS NOT NULL AND started_at < %s",
            self::STATUS_QUEUED,
            current_time('mysql', true),
            self::STATUS_PROCESSING,
            $threshold
        ));
        return $result === false ? 0 : (int) $result;
    }

    /**
     * Claim اتمیک یک ردیف مشخص: فقط اگر هنوز واقعاً 'queued' باشد به
     * 'processing' تغییر می‌کند (UPDATE ... WHERE id=... AND
     * import_status='queued' — همان شرطی که تضمین می‌کند اگر یک کارگر
     * دیگر (مسیر AJAX یا Action Scheduler) هم‌زمان همین ردیف را می‌خواست،
     * فقط یکی از آن‌ها affected_rows=1 می‌گیرد و دیگری صفر — بدون نیاز به
     * قفل صریح دیتابیس). خروجی null یعنی ردیف یا وجود ندارد یا کس دیگری
     * از قبل بُرده — کالر باید بی‌صدا صرف‌نظر کند، نه دوباره پردازش کند.
     */
    public static function claim_specific(int $source_product_id): ?array {
        global $wpdb;
        $row = self::get_map_row($source_product_id);
        if (!$row || $row['import_status'] !== self::STATUS_QUEUED) {
            return null;
        }
        $now = current_time('mysql', true);
        $affected = $wpdb->update(
            self::product_map_table(),
            array('import_status' => self::STATUS_PROCESSING, 'started_at' => $now, 'updated_at' => $now),
            array('id' => (int) $row['id'], 'import_status' => self::STATUS_QUEUED)
        );
        if ((int) $affected !== 1) {
            return null;
        }
        return array('id' => (int) $row['id'], 'source_product_id' => $source_product_id);
    }

    /**
     * برای کارگر سمت مرورگر (hci_import_next): قدیمی‌ترین ردیف queued را
     * پیدا و همان‌جا اتمیک Claim می‌کند. همان تضمین بالا را دارد؛ اگر بین
     * SELECT و UPDATE کس دیگری برد، null برمی‌گردد (نه خطا) — JS باید
     * ساده دوباره صدا بزند.
     */
    public static function claim_next_queued(): ?array {
        global $wpdb;
        $table = self::product_map_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, source_product_id FROM {$table} WHERE import_status = %s AND source_variation_id IS NULL ORDER BY id ASC LIMIT 1",
            self::STATUS_QUEUED
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        return self::claim_specific((int) $row['source_product_id']);
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
        $now = current_time('mysql', true);
        $update = array('updated_at' => $now, 'finished_at' => $now);
        foreach (array('dest_product_id', 'dest_sku', 'import_status', 'error_message') as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (($data['import_status'] ?? '') === self::STATUS_IMPORTED) {
            $update['last_synced_at'] = $now;
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

    /**
     * برای پیام دقیق دکمه «ریست داده‌ها» («۱۲ صف پاک شد»): چند ردیف الان
     * واقعاً در صف Action Scheduler هستند (queued/processing) — باید قبل از
     * truncate_product_map() صدا زده شود.
     */
    public static function count_queued_or_processing(): int {
        global $wpdb;
        $table = self::product_map_table();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE import_status IN (%s, %s)",
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING
        ));
        return (int) $count;
    }

    /**
     * فقط برای گزینه اختیاری «حذف محصولات ساخته‌شده توسط این پلاگین»: قبل
     * از خالی‌شدن جدول، dest_product_id همه ردیف‌ها را برمی‌گرداند — برای
     * ردیف‌های قدیمی‌تری که هنوز متای _hci_source_product_id ندارند (از
     * قبل از اضافه‌شدن آن Meta)، این تنها راه شناسایی محصول ساخته‌شده است.
     */
    public static function get_all_dest_product_ids(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            'SELECT DISTINCT dest_product_id FROM ' . self::product_map_table() . ' WHERE dest_product_id IS NOT NULL'
        );
        return array_values(array_map('intval', is_array($rows) ? $rows : array()));
    }
}
