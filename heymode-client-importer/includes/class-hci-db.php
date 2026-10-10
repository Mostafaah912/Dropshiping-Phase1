<?php

defined('ABSPATH') || exit;

final class HCI_DB {
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR = 'error';
    // محصولی که مشتری بعداً از ووکامرس پاک/Trash کرده؛ از شمارش و سینک خارج و گرید آزاد است.
    public const STATUS_REMOVED = 'removed';

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
            variation_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
        self::run_pending_migrations();
    }

    public static function run_pending_migrations(): void {
        if ((string) get_option('hci_unique_key_ready', '') !== '1') {
            self::migrate_unique_key();
        }
    }

    /**
     * کلید یکتا روی (source_product_id + شناسه تنوع یا ۰): جلوگیری از ردیف
     * تکراری با دو درخواست هم‌زمان. چون MySQL ستون NULL را در کلید یکتا یکتا
     * نمی‌داند، ستون variation_key (۰ برای والد) نگهداری می‌شود. مهاجرت امن:
     * اول ستون پر می‌شود، تکراری‌های موجود (بهترین ردیف می‌ماند) پاک می‌شوند،
     * بعد کلید ساخته می‌شود؛ هر مرحله Idempotent است.
     */
    public static function migrate_unique_key(): bool {
        global $wpdb;
        $table = self::product_map_table();

        $wpdb->query("UPDATE {$table} SET variation_key = COALESCE(source_variation_id, 0)");

        $groups = $wpdb->get_results(
            "SELECT source_product_id, variation_key FROM {$table} GROUP BY source_product_id, variation_key HAVING COUNT(*) > 1",
            ARRAY_A
        );
        $rank = array(
            self::STATUS_IMPORTED => 0,
            self::STATUS_PARTIAL => 1,
            self::STATUS_PROCESSING => 2,
            self::STATUS_QUEUED => 3,
            self::STATUS_ERROR => 4,
            self::STATUS_DUPLICATE => 5,
            self::STATUS_REMOVED => 6,
        );
        foreach ((array) $groups as $group) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, import_status, dest_product_id, dest_variation_id, updated_at FROM {$table} WHERE source_product_id = %d AND variation_key = %d",
                (int) $group['source_product_id'],
                (int) $group['variation_key']
            ), ARRAY_A);
            usort($rows, static function (array $a, array $b) use ($rank): int {
                $ra = $rank[$a['import_status']] ?? 9;
                $rb = $rank[$b['import_status']] ?? 9;
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }
                $da = (!empty($a['dest_product_id']) || !empty($a['dest_variation_id'])) ? 0 : 1;
                $db = (!empty($b['dest_product_id']) || !empty($b['dest_variation_id'])) ? 0 : 1;
                if ($da !== $db) {
                    return $da <=> $db;
                }
                if ((string) $a['updated_at'] !== (string) $b['updated_at']) {
                    return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
                }
                return (int) $a['id'] <=> (int) $b['id'];
            });
            foreach (array_slice($rows, 1) as $extra) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id = %d", (int) $extra['id']));
            }
        }

        $created = $wpdb->query("CREATE UNIQUE INDEX hci_source_variation ON {$table} (source_product_id, variation_key)");
        $already = $created === false && (stripos((string) $wpdb->last_error, 'already exists') !== false || stripos((string) $wpdb->last_error, 'Duplicate key name') !== false);
        if ($created === false && !$already) {
            error_log('[HCI] ساخت کلید یکتای جدول ردیابی ناموفق بود: ' . $wpdb->last_error);
            return false;
        }
        update_option('hci_unique_key_ready', '1', false);
        return true;
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
                "SELECT * FROM {$table} WHERE id != %d AND source_variation_id IS NULL AND import_status = 'imported' AND (source_product_id = %d OR source_sku = %s) LIMIT 1",
                $exclude_row_id,
                $source_product_id,
                $source_sku
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id != %d AND source_variation_id IS NULL AND import_status = 'imported' AND source_product_id = %d LIMIT 1",
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
            'variation_key' => 0,
            'source_sku' => $source_sku,
            'import_payload' => $payload_json,
            'import_status' => self::STATUS_QUEUED,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($result === false) {
            // درخواست هم‌زمان دیگری همین ردیف را ساخته (کلید یکتا): همان را به‌روز می‌کنیم.
            $winner = self::get_map_row($source_product_id);
            if ($winner) {
                $updated = $wpdb->update(
                    self::product_map_table(),
                    array('source_sku' => $source_sku, 'import_payload' => $payload_json, 'import_status' => self::STATUS_QUEUED, 'error_message' => null, 'updated_at' => $now),
                    array('id' => (int) $winner['id'])
                );
                if ($updated !== false) {
                    return array('success' => true, 'id' => (int) $winner['id']);
                }
            }
            return array('success' => false, 'message' => $wpdb->last_error);
        }
        return array('success' => true, 'id' => (int) $wpdb->insert_id);
    }

    /**
     * ضربان کارگر: ردیفی که هنوز در حال پردازش است «زنده» می‌ماند و قفلش
     * (حتی اگر پردازشش طولانی باشد) توسط کارگر دیگر آزاد نمی‌شود.
     */
    public static function touch_processing(int $source_product_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::product_map_table() . ' SET updated_at = %s WHERE source_product_id = %d AND source_variation_id IS NULL AND import_status = %s',
            current_time('mysql', true),
            $source_product_id,
            self::STATUS_PROCESSING
        ));
    }

    /**
     * قفل‌های قدیمی (ردیف‌هایی که processing مانده‌اند ولی started_at از حد
     * مشخص قدیمی‌تر است — یعنی کارگری که وسط کار قطع/کرش شده) را به queued
     * برمی‌گرداند تا دوباره Claim‌پذیر شوند. هم مسیر AJAX (hci_import_next)
     * هم Action Scheduler قبل از Claim کردن این را صدا می‌زنند.
     */
    public static function release_stale_locks(int $stale_after_seconds = 900): int {
        global $wpdb;
        $table = self::product_map_table();
        $threshold = gmdate('Y-m-d H:i:s', time() - $stale_after_seconds);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET import_status = %s, updated_at = %s WHERE import_status = %s AND started_at IS NOT NULL AND COALESCE(updated_at, started_at) < %s",
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
        $fields['variation_key'] = $source_variation_id;
        $fields['created_at'] = $now;
        if ($wpdb->insert(self::product_map_table(), $fields) === false) {
            // رقابت هم‌زمان: ردیف همین تنوع قبلاً ساخته شده؛ همان را به‌روز کن.
            $winner = self::get_map_row($parent_source_id, $source_variation_id);
            if ($winner) {
                unset($fields['source_product_id'], $fields['source_variation_id'], $fields['variation_key'], $fields['created_at']);
                $wpdb->update(self::product_map_table(), $fields, array('id' => (int) $winner['id']));
            }
        }
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
     * محصول (یا تنوع) مقصد پاک/Trash شده: ردیف‌های ردیابی‌اش را removed می‌کند.
     * برای والد، تنوع‌هایش هم removed می‌شوند. خروجی: تعداد ردیف‌های تغییرکرده.
     */
    public static function mark_removed_by_dest_id(int $dest_id): int {
        return self::transition_by_dest_id($dest_id, self::STATUS_IMPORTED, self::STATUS_REMOVED);
    }

    /**
     * محصول از Trash برگشت: ردیف‌های removed همان محصول دوباره imported می‌شوند.
     */
    public static function restore_removed_by_dest_id(int $dest_id): int {
        return self::transition_by_dest_id($dest_id, self::STATUS_REMOVED, self::STATUS_IMPORTED);
    }

    private static function transition_by_dest_id(int $dest_id, string $from, string $to): int {
        global $wpdb;
        if ($dest_id <= 0) {
            return 0;
        }
        $table = self::product_map_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_product_id, source_variation_id FROM {$table} WHERE import_status = %s AND ((source_variation_id IS NULL AND dest_product_id = %d) OR dest_variation_id = %d)",
            $from,
            $dest_id,
            $dest_id
        ), ARRAY_A);
        $changed = 0;
        $now = current_time('mysql', true);
        foreach ((array) $rows as $row) {
            if ($row['source_variation_id'] === null) {
                $changed += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET import_status = %s, updated_at = %s WHERE source_product_id = %d AND import_status = %s",
                    $to,
                    $now,
                    (int) $row['source_product_id'],
                    $from
                ));
            } else {
                $changed += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET import_status = %s, updated_at = %s WHERE id = %d AND import_status = %s",
                    $to,
                    $now,
                    (int) $row['id'],
                    $from
                ));
            }
        }
        return $changed;
    }

    /**
     * بررسی وجود: محصولات imported که در ووکامرس دیگر نیستند (حذف همیشگی بدون
     * هوک، یا Trash) removed می‌شوند. خروجی: تعداد رویدادهای حذف.
     */
    public static function reconcile_removed(): int {
        global $wpdb;
        $table = self::product_map_table();
        $rows = $wpdb->get_results(
            "SELECT source_variation_id, dest_product_id, dest_variation_id FROM {$table} WHERE import_status = 'imported' AND ((source_variation_id IS NULL AND dest_product_id IS NOT NULL) OR dest_variation_id IS NOT NULL) ORDER BY (source_variation_id IS NULL) DESC, id ASC",
            ARRAY_A
        );
        $events = 0;
        foreach ((array) $rows as $row) {
            $id = $row['source_variation_id'] === null ? (int) $row['dest_product_id'] : (int) $row['dest_variation_id'];
            $status = get_post_status($id);
            if ($status === false || $status === 'trash') {
                if (self::mark_removed_by_dest_id($id) > 0) {
                    $events++;
                }
            }
        }
        return $events;
    }

    /**
     * همان reconcile_removed ولی حداکثر هر $seconds ثانیه یک‌بار (برای رندر صفحه‌ها).
     */
    public static function reconcile_removed_throttled(int $seconds = 300): void {
        if (get_transient('hci_reconcile_removed_ts') !== false) {
            return;
        }
        set_transient('hci_reconcile_removed_ts', 1, $seconds);
        self::reconcile_removed();
    }

    /**
     * قبل از Import دوباره‌ی یک محصول removed: شناسه‌های مرده‌ی مقصد و ردیف
     * تنوع‌ها پاک می‌شوند تا محصول تازه ساخته شود.
     */
    public static function forget_removed(int $source_product_id): void {
        global $wpdb;
        $table = self::product_map_table();
        $row = self::get_map_row($source_product_id);
        if (!$row || $row['import_status'] !== self::STATUS_REMOVED) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE source_product_id = %d AND source_variation_id IS NOT NULL",
            $source_product_id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET dest_product_id = NULL, dest_sku = NULL WHERE id = %d",
            (int) $row['id']
        ));
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

    /**
     * محصولات Variable که «وارد شده» ثبت شده‌اند ولی همه تنوع‌هایشان ساخته
     * نشده (مثلاً Importهای قدیمی‌تر). فقط ردیف‌هایی که Snapshot تنوع‌ها هنوز
     * در import_payload هست قابل ترمیم‌اند.
     */
    public static function get_broken_variable_rows(): array {
        $broken = array();
        foreach (self::get_imported_parent_rows() as $row) {
            $payload = json_decode((string) ($row['import_payload'] ?? ''), true);
            if (!is_array($payload) || ($payload['product_type'] ?? '') !== 'variable') {
                continue;
            }
            $wanted = 0;
            foreach ((array) ($payload['variations'] ?? array()) as $v) {
                if ((int) ($v['variation_id'] ?? 0) > 0) {
                    $wanted++;
                }
            }
            if ($wanted === 0) {
                continue;
            }
            $have = count(self::get_imported_variation_rows_for_parents(array((int) $row['source_product_id']))[(int) $row['source_product_id']] ?? array());
            if ($have < $wanted) {
                $row['payload_name'] = (string) ($payload['name'] ?? '');
                $broken[] = $row;
            }
        }
        return $broken;
    }

    /**
     * ردیف را برای ترمیم دوباره «در صف» می‌گذارد (dest_product_id حفظ می‌شود
     * تا والد دوباره ساخته نشود).
     */
    public static function requeue_for_repair(int $source_product_id): bool {
        global $wpdb;
        $row = self::get_map_row($source_product_id);
        if (!$row || $row['import_status'] === self::STATUS_QUEUED || $row['import_status'] === self::STATUS_PROCESSING) {
            return false;
        }
        $wpdb->update(
            self::product_map_table(),
            array('import_status' => self::STATUS_QUEUED, 'error_message' => null, 'updated_at' => current_time('mysql', true)),
            array('id' => (int) $row['id'])
        );
        return true;
    }
}
