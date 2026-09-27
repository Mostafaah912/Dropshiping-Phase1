<?php

defined('ABSPATH') || exit;

final class HCI_DB {
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

    public static function get_imported_source_skus(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT DISTINCT source_sku FROM " . self::product_map_table() . " WHERE source_sku IS NOT NULL AND source_sku != ''"
        );
        return array_values(array_filter(is_array($rows) ? $rows : array()));
    }

    /**
     * ثبت Placeholder برای فاز ۳ — بدون ساخت واقعی محصول در ووکامرس؛ فقط یک
     * ردیف با import_status='pending_phase3' در جدول ردیابی درج می‌شود.
     */
    public static function insert_pending(int $source_product_id, ?string $source_sku): array {
        global $wpdb;
        $now = current_time('mysql', true);
        $result = $wpdb->insert(self::product_map_table(), array(
            'source_product_id' => $source_product_id,
            'source_sku' => $source_sku,
            'import_status' => 'pending_phase3',
            'last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if ($result === false) {
            return array('success' => false, 'message' => $wpdb->last_error);
        }
        return array('success' => true);
    }
}
