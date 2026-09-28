<?php

defined('ABSPATH') || exit;

final class HMW_DB {
    public static function products_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hmw_products';
    }

    public static function sync_logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hmw_sync_logs';
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $products = self::products_table();
        $logs = self::sync_logs_table();

        $sql_products = "CREATE TABLE {$products} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_product_id BIGINT UNSIGNED NOT NULL,
            parent_product_id BIGINT UNSIGNED NULL,
            product_type VARCHAR(30) NOT NULL DEFAULT 'simple',
            source_status VARCHAR(20) NOT NULL DEFAULT 'publish',
            sku VARCHAR(191) NULL,
            name TEXT NOT NULL,
            price DECIMAL(20,4) NULL,
            stock_quantity DECIMAL(20,4) NULL,
            stock_status VARCHAR(20) NOT NULL DEFAULT 'outofstock',
            manage_stock TINYINT(1) NOT NULL DEFAULT 0,
            image_url TEXT NULL,
            product_url TEXT NULL,
            category_path LONGTEXT NULL,
            short_description LONGTEXT NULL,
            gallery LONGTEXT NULL,
            category_ids LONGTEXT NULL,
            attributes LONGTEXT NULL,
            source_modified_gmt DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            last_sync_run_uuid CHAR(36) NULL,
            last_synced_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_product_id (source_product_id),
            KEY sku (sku),
            KEY stock_status (stock_status),
            KEY manage_stock (manage_stock),
            KEY is_active (is_active),
            KEY parent_product_id (parent_product_id),
            KEY product_type (product_type),
            KEY source_status (source_status),
            KEY source_modified_gmt (source_modified_gmt),
            KEY last_sync_run_uuid (last_sync_run_uuid)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_uuid CHAR(36) NOT NULL,
            sync_type VARCHAR(30) NOT NULL,
            trigger_type VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            duration_ms BIGINT UNSIGNED NULL,
            products_fetched INT UNSIGNED NOT NULL DEFAULT 0,
            products_inserted INT UNSIGNED NOT NULL DEFAULT 0,
            products_updated INT UNSIGNED NOT NULL DEFAULT 0,
            products_unchanged INT UNSIGNED NOT NULL DEFAULT 0,
            products_deactivated INT UNSIGNED NOT NULL DEFAULT 0,
            errors_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_summary LONGTEXT NULL,
            error_details LONGTEXT NULL,
            api_requests INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY status (status),
            KEY sync_type (sync_type),
            KEY started_at (started_at)
        ) {$charset_collate};";

        dbDelta($sql_products);
        dbDelta($sql_logs);
        update_option('hmw_db_version', HMW_VERSION, false);
    }

    public static function ensure_schema(): void {
        $version = (string) get_option('hmw_db_version', '0');
        if (version_compare($version, HMW_VERSION, '<')) {
            self::create_tables();
        }
    }

    public static function get_product_row(int $source_product_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::products_table() . ' WHERE source_product_id = %d LIMIT 1',
                $source_product_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_active_variable_parent_ids(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            'SELECT source_product_id FROM ' . self::products_table() .
            " WHERE is_active = 1 AND parent_product_id IS NULL AND product_type = 'variable' ORDER BY source_product_id ASC"
        );
        return array_values(array_filter(array_map('intval', is_array($rows) ? $rows : array())));
    }

    public static function get_variable_parent_rows(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::products_table() .
            " WHERE is_active = 1 AND parent_product_id IS NULL AND product_type = 'variable' ORDER BY source_product_id ASC",
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    public static function get_variation_rows_for_parents(array $parent_ids, bool $include_inactive = false): array {
        global $wpdb;
        $parent_ids = array_values(array_unique(array_filter(array_map('intval', $parent_ids))));
        if (!$parent_ids) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
        $active_sql = $include_inactive ? '' : ' AND is_active = 1';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT source_product_id, parent_product_id, sku, price, stock_quantity, stock_status, attributes FROM ' . self::products_table() .
                " WHERE parent_product_id IN ({$placeholders}) AND product_type = 'variation'{$active_sql} ORDER BY source_product_id ASC",
                $parent_ids
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return array();
        }
        $grouped = array();
        foreach ($rows as $row) {
            $grouped[(int) $row['parent_product_id']][] = $row;
        }
        return $grouped;
    }

    public static function bulk_upsert_products(array $rows): array {
        global $wpdb;

        if (!$rows) {
            return array('success' => true, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0);
        }

        $normalized = array();
        $ids = array();
        foreach ($rows as $data) {
            $id = (int) ($data['source_product_id'] ?? 0);
            if ($id <= 0) {
                return array('success' => false, 'message' => 'source_product_id نامعتبر است.');
            }
            $normalized[$id] = $data;
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $existing_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::products_table() . ' WHERE source_product_id IN (' . $placeholders . ')',
                $ids
            ),
            ARRAY_A
        );
        if (!is_array($existing_rows)) {
            $existing_rows = array();
        }

        $existing = array();
        foreach ($existing_rows as $row) {
            $existing[(int) $row['source_product_id']] = $row;
        }

        $now = current_time('mysql', true);
        $table = self::products_table();
        $columns = array(
            'source_product_id', 'parent_product_id', 'product_type', 'source_status', 'sku', 'name',
            'price', 'stock_quantity', 'stock_status', 'manage_stock', 'image_url', 'product_url',
            'category_path', 'short_description', 'gallery', 'category_ids', 'attributes',
            'source_modified_gmt', 'is_active', 'last_sync_run_uuid', 'last_synced_at',
            'created_at', 'updated_at'
        );

        $values_sql = array();
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($normalized as $id => $data) {
            $row = array(
                'source_product_id'   => $id,
                'parent_product_id'   => !empty($data['parent_product_id']) ? (int) $data['parent_product_id'] : null,
                'product_type'        => (string) ($data['product_type'] ?? 'simple'),
                'source_status'       => (string) ($data['source_status'] ?? 'publish'),
                'sku'                 => (($data['sku'] ?? '') !== '') ? (string) $data['sku'] : null,
                'name'                => (string) ($data['name'] ?? ''),
                'price'               => (($data['price'] ?? '') !== '') ? (string) $data['price'] : null,
                'stock_quantity'      => array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null ? (string) $data['stock_quantity'] : null,
                'stock_status'        => (string) ($data['stock_status'] ?? 'outofstock'),
                'manage_stock'        => !empty($data['manage_stock']) ? 1 : 0,
                'image_url'           => $data['image_url'] ?? null,
                'product_url'         => $data['product_url'] ?? null,
                'category_path'       => (string) ($data['category_path'] ?? ''),
                'short_description'   => (string) ($data['short_description'] ?? ''),
                'gallery'             => (string) ($data['gallery'] ?? ''),
                'category_ids'        => (string) ($data['category_ids'] ?? ''),
                'attributes'          => (string) ($data['attributes'] ?? ''),
                'source_modified_gmt' => $data['source_modified_gmt'] ?: null,
                'is_active'           => !empty($data['is_active']) ? 1 : 0,
                'last_sync_run_uuid'  => (string) ($data['run_uuid'] ?? ''),
                'last_synced_at'      => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            );

            $old = $existing[$id] ?? null;
            if (!$old) {
                $inserted++;
            } else {
                $compare_fields = array(
                    'parent_product_id', 'product_type', 'source_status', 'sku', 'name', 'price',
                    'stock_quantity', 'stock_status', 'manage_stock', 'image_url', 'product_url',
                    'category_path', 'source_modified_gmt', 'is_active'
                );
                $changed = false;
                foreach ($compare_fields as $field) {
                    if ((string) ($old[$field] ?? null) !== (string) ($row[$field] ?? null)) {
                        $changed = true;
                        break;
                    }
                }
                if ($changed) {
                    $updated++;
                } else {
                    $unchanged++;
                    // فیلدهای غیرقابل‌مقایسه (مثلاً short_description) ممکن است تغییر کرده
                    // باشند، اما چون در تشخیص "تغییر واقعی" لحاظ نمی‌شوند، updated_at را
                    // دست‌نخورده نگه می‌داریم تا فیلتر updated_after/دیتای Delta معتبر بماند.
                    $row['updated_at'] = (string) ($old['updated_at'] ?? $now);
                }
            }

            $vals = array();
            $vals[] = $wpdb->prepare('%d', $row['source_product_id']);
            $vals[] = $row['parent_product_id'] === null ? 'NULL' : $wpdb->prepare('%d', $row['parent_product_id']);
            $vals[] = $wpdb->prepare('%s', $row['product_type']);
            $vals[] = $wpdb->prepare('%s', $row['source_status']);
            $vals[] = $row['sku'] === null ? 'NULL' : $wpdb->prepare('%s', $row['sku']);
            $vals[] = $wpdb->prepare('%s', $row['name']);
            $vals[] = $row['price'] === null ? 'NULL' : $wpdb->prepare('%s', $row['price']);
            $vals[] = $row['stock_quantity'] === null ? 'NULL' : $wpdb->prepare('%s', $row['stock_quantity']);
            $vals[] = $wpdb->prepare('%s', $row['stock_status']);
            $vals[] = $wpdb->prepare('%d', $row['manage_stock']);
            $vals[] = $row['image_url'] === null ? 'NULL' : $wpdb->prepare('%s', $row['image_url']);
            $vals[] = $row['product_url'] === null ? 'NULL' : $wpdb->prepare('%s', $row['product_url']);
            $vals[] = $wpdb->prepare('%s', $row['category_path']);
            $vals[] = $wpdb->prepare('%s', $row['short_description']);
            $vals[] = $wpdb->prepare('%s', $row['gallery']);
            $vals[] = $wpdb->prepare('%s', $row['category_ids']);
            $vals[] = $wpdb->prepare('%s', $row['attributes']);
            $vals[] = $row['source_modified_gmt'] === null ? 'NULL' : $wpdb->prepare('%s', $row['source_modified_gmt']);
            $vals[] = $wpdb->prepare('%d', $row['is_active']);
            $vals[] = $wpdb->prepare('%s', $row['last_sync_run_uuid']);
            $vals[] = $wpdb->prepare('%s', $row['last_synced_at']);
            $vals[] = $wpdb->prepare('%s', $row['created_at']);
            $vals[] = $wpdb->prepare('%s', $row['updated_at']);
            $values_sql[] = '(' . implode(',', $vals) . ')';
        }

        $update_sql = array();
        foreach ($columns as $column) {
            if (in_array($column, array('source_product_id', 'created_at'), true)) {
                continue;
            }
            $update_sql[] = $column . '=VALUES(' . $column . ')';
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', $values_sql)
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $update_sql);

        $result = $wpdb->query($sql);
        if ($result === false) {
            return array('success' => false, 'message' => $wpdb->last_error);
        }

        return array(
            'success' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
        );
    }

    public static function upsert_product(array $data): array {
        global $wpdb;

        $id = (int) ($data['source_product_id'] ?? 0);
        if ($id <= 0) {
            return array('success' => false, 'action' => 'error', 'message' => 'source_product_id نامعتبر است.');
        }

        $existing = self::get_product_row($id);
        $now = current_time('mysql', true);
        $row = array(
            'source_product_id'   => $id,
            'parent_product_id'   => !empty($data['parent_product_id']) ? (int) $data['parent_product_id'] : null,
            'product_type'        => (string) ($data['product_type'] ?? 'simple'),
            'source_status'       => (string) ($data['source_status'] ?? 'publish'),
            'sku'                 => (($data['sku'] ?? '') !== '') ? (string) $data['sku'] : null,
            'name'                => (string) ($data['name'] ?? ''),
            'price'               => (($data['price'] ?? '') !== '') ? (string) $data['price'] : null,
            'stock_quantity'      => array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null ? (string) $data['stock_quantity'] : null,
            'stock_status'        => (string) ($data['stock_status'] ?? 'outofstock'),
            'manage_stock'        => !empty($data['manage_stock']) ? 1 : 0,
            'image_url'           => $data['image_url'] ?? null,
            'product_url'         => $data['product_url'] ?? null,
            'category_path'       => (string) ($data['category_path'] ?? ''),
            'short_description'   => (string) ($data['short_description'] ?? ''),
            'gallery'             => (string) ($data['gallery'] ?? ''),
            'category_ids'        => (string) ($data['category_ids'] ?? ''),
            'attributes'          => (string) ($data['attributes'] ?? ''),
            'source_modified_gmt' => $data['source_modified_gmt'] ?: null,
            'is_active'           => !empty($data['is_active']) ? 1 : 0,
            'last_sync_run_uuid'  => (string) ($data['run_uuid'] ?? ''),
            'last_synced_at'      => $now,
            'updated_at'          => $now,
        );

        if (!$existing) {
            $row['created_at'] = $now;
            $result = $wpdb->insert(self::products_table(), $row);
            if ($result === false) {
                return array('success' => false, 'action' => 'error', 'message' => $wpdb->last_error);
            }
            return array('success' => true, 'action' => 'inserted');
        }

        $compare_fields = array(
            'parent_product_id', 'product_type', 'source_status', 'sku', 'name', 'price',
            'stock_quantity', 'stock_status', 'manage_stock', 'image_url', 'product_url',
            'category_path', 'source_modified_gmt', 'is_active'
        );
        $changed = false;
        foreach ($compare_fields as $field) {
            $a = $existing[$field] ?? null;
            $b = $row[$field] ?? null;
            if ((string) $a !== (string) $b) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            $row['updated_at'] = (string) ($existing['updated_at'] ?? $now);
        }

        $result = $wpdb->update(
            self::products_table(),
            $row,
            array('source_product_id' => $id)
        );
        if ($result === false) {
            return array('success' => false, 'action' => 'error', 'message' => $wpdb->last_error);
        }

        return array('success' => true, 'action' => $changed ? 'updated' : 'unchanged');
    }

    public static function deactivate_product(int $source_product_id, string $run_uuid = ''): int {
        global $wpdb;
        $data = array(
            'is_active' => 0,
            'updated_at' => current_time('mysql', true),
        );
        if ($run_uuid !== '') {
            $data['last_sync_run_uuid'] = $run_uuid;
        }
        $result = $wpdb->update(
            self::products_table(),
            $data,
            array('source_product_id' => $source_product_id)
        );
        return $result === false ? 0 : (int) $result;
    }

    public static function deactivate_variations_of_parent(int $parent_id, string $run_uuid = ''): int {
        global $wpdb;
        $uuid_sql = '';
        $args = array(current_time('mysql', true));
        if ($run_uuid !== '') {
            $uuid_sql = ', last_sync_run_uuid = %s';
            $args[] = $run_uuid;
        }
        $args[] = $parent_id;
        $sql = "UPDATE " . self::products_table() . " SET is_active = 0, updated_at = %s{$uuid_sql} WHERE parent_product_id = %d AND is_active = 1";
        return (int) $wpdb->query($wpdb->prepare($sql, $args));
    }

    public static function deactivate_variations_of_parents(array $parent_ids, string $run_uuid = ''): int {
        global $wpdb;
        $parent_ids = array_values(array_unique(array_filter(array_map('intval', $parent_ids))));
        if (!$parent_ids) {
            return 0;
        }
        $uuid_sql = '';
        $args = array(current_time('mysql', true));
        if ($run_uuid !== '') {
            $uuid_sql = ', last_sync_run_uuid = %s';
            $args[] = $run_uuid;
        }
        $args = array_merge($args, $parent_ids);
        $placeholders = implode(',', array_fill(0, count($parent_ids), '%d'));
        $sql = "UPDATE " . self::products_table() . " SET is_active = 0, updated_at = %s{$uuid_sql} WHERE parent_product_id IN ({$placeholders}) AND is_active = 1";
        return (int) $wpdb->query($wpdb->prepare($sql, $args));
    }

    public static function deactivate_parents_not_seen(string $run_uuid): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::products_table() . ' SET is_active = 0, updated_at = %s WHERE parent_product_id IS NULL AND is_active = 1 AND (last_sync_run_uuid IS NULL OR last_sync_run_uuid <> %s)',
            current_time('mysql', true),
            $run_uuid
        ));
    }

    public static function deactivate_variations_not_seen(int $parent_id, string $run_uuid): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::products_table() . ' SET is_active = 0, updated_at = %s WHERE parent_product_id = %d AND is_active = 1 AND (last_sync_run_uuid IS NULL OR last_sync_run_uuid <> %s)',
            current_time('mysql', true),
            $parent_id,
            $run_uuid
        ));
    }

    public static function finalize_full_sync(string $run_uuid): int {
        global $wpdb;
        $deactivated = (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::products_table() . ' SET is_active = 0, updated_at = %s WHERE is_active = 1 AND (last_sync_run_uuid IS NULL OR last_sync_run_uuid <> %s)',
            current_time('mysql', true),
            $run_uuid
        ));
        return $deactivated;
    }

    public static function mark_deactivated(int $source_product_id, string $run_uuid): int {
        return self::deactivate_product($source_product_id, $run_uuid);
    }
}
