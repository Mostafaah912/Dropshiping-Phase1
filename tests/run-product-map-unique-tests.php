<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — کلید یکتای جدول ردیابی و مهاجرت امن (S2-08) (PHP " . PHP_VERSION . ")\n";

function pu_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__fake_wpdb_before_insert'] = null;
    return $w;
}

function pu_row(Fake_WPDB $w, int $src, ?int $var, string $status, array $extra = array()): void {
    $w->insert('wp_hci_product_map', array_merge(array('source_product_id' => $src, 'source_variation_id' => $var, 'import_status' => $status, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'), $extra));
}

function pu_count(Fake_WPDB $w, string $where): int {
    return (int) $w->get_var('SELECT COUNT(*) FROM wp_hci_product_map WHERE ' . $where);
}

test_section('مهاجرت: تکراری‌های موجود پاک‌سازی می‌شوند (بهترین ردیف می‌ماند) و کلید یکتا ساخته می‌شود');

$w = pu_reset();
pu_row($w, 10, null, 'queued', array('updated_at' => '2026-02-01 00:00:00'));
pu_row($w, 10, null, 'imported', array('dest_product_id' => 501));
pu_row($w, 11, null, 'error');
pu_row($w, 11, null, 'partial', array('dest_product_id' => 502));
pu_row($w, 12, 121, 'imported', array('dest_variation_id' => 601));
pu_row($w, 12, 121, 'imported', array('dest_variation_id' => 601));
pu_row($w, 13, null, 'imported', array('dest_product_id' => 503)); // تکراری نیست
pu_row($w, 13, 131, 'imported', array('dest_variation_id' => 602)); // والد و تنوع هم‌کلید نیستند
$before = pu_count($w, '1=1');
test_assert(HCI_DB::migrate_unique_key() === true, 'مهاجرت موفق گزارش شد');
test_assert(pu_count($w, '1=1') === $before - 3, 'دقیقاً ۳ ردیف تکراری پاک شد');
test_assert(pu_count($w, "source_product_id = 10 AND source_variation_id IS NULL") === 1 && (string) $w->get_var("SELECT dest_product_id FROM wp_hci_product_map WHERE source_product_id = 10") === '501', 'برای ۱۰ ردیف imported (دارای محصول مقصد) ماند، نه queued');
test_assert((string) $w->get_var("SELECT import_status FROM wp_hci_product_map WHERE source_product_id = 11") === 'partial', 'برای ۱۱ ردیف partial ماند، نه error');
test_assert(pu_count($w, 'source_product_id = 12') === 1, 'تنوع تکراری هم یکی شد');
test_assert(pu_count($w, 'source_product_id = 13') === 2, 'والد و تنوعِ یک محصول دست‌نخورده ماند');
test_assert(pu_count($w, 'variation_key != COALESCE(source_variation_id, 0)') === 0, 'variation_key همه ردیف‌ها پر شد');
test_assert(get_option('hci_unique_key_ready') === '1', 'وضعیت «آماده» ذخیره شد');
test_assert(HCI_DB::migrate_unique_key() === true, 'اجرای دوباره (Idempotent) بی‌خطاست');
pu_row($w, 10, null, 'queued');
test_assert(pu_count($w, 'source_product_id = 10') === 1, 'درج مستقیم یک ردیف تکراری، حالا توسط خود دیتابیس رد می‌شود');

test_section('رقابت هم‌زمان: دو درخواست هم‌زمان ردیف تکراری نمی‌سازند');

$w = pu_reset();
HCI_DB::migrate_unique_key();
// درخواست دیگر، بعد از بررسی «ردیفی نیست» و قبل از درج ما، همان ردیف را می‌سازد.
$GLOBALS['__fake_wpdb_before_insert'] = static function () use ($w): void {
    $w->insert('wp_hci_product_map', array('source_product_id' => 20, 'source_variation_id' => null, 'variation_key' => 0, 'import_status' => 'queued', 'import_payload' => '{"from":"other"}', 'created_at' => 'x', 'updated_at' => 'x'));
};
$r = HCI_DB::queue_import(20, 'hmp-20', '{"from":"me"}');
test_assert($r['success'] === true, 'صف‌بندی با وجود رقابت موفق گزارش شد');
test_assert(pu_count($w, 'source_product_id = 20') === 1, 'فقط یک ردیف وجود دارد');
test_assert((string) $w->get_var("SELECT import_payload FROM wp_hci_product_map WHERE source_product_id = 20") === '{"from":"me"}', 'ردیف موجود با داده‌ی ما به‌روز شد');

$GLOBALS['__fake_wpdb_before_insert'] = static function () use ($w): void {
    $w->insert('wp_hci_product_map', array('source_product_id' => 21, 'source_variation_id' => 211, 'variation_key' => 211, 'import_status' => 'error', 'created_at' => 'x', 'updated_at' => 'x'));
};
HCI_DB::upsert_variation_map(21, 211, 'sku', 700, 'vsp', HCI_DB::STATUS_IMPORTED);
test_assert(pu_count($w, 'source_product_id = 21') === 1 && (string) $w->get_var("SELECT dest_variation_id FROM wp_hci_product_map WHERE source_product_id = 21") === '700', 'همین رقابت برای تنوع‌ها هم یک ردیف و داده‌ی درست دارد');
test_assert((int) $w->get_var("SELECT variation_key FROM wp_hci_product_map WHERE source_product_id = 21") === 211, 'variation_key تنوع درست پر می‌شود');

// درج عادی پارنت/تنوع کلید را ست می‌کند
HCI_DB::queue_import(30, 'hmp-30', '{}');
HCI_DB::upsert_variation_map(30, 301, null, 800, null, HCI_DB::STATUS_IMPORTED);
test_assert((int) $w->get_var("SELECT variation_key FROM wp_hci_product_map WHERE source_product_id = 30 AND source_variation_id IS NULL") === 0 && (int) $w->get_var("SELECT variation_key FROM wp_hci_product_map WHERE source_product_id = 30 AND source_variation_id = 301") === 301, 'کلید والد ۰ و کلید تنوع شناسه‌ی خودش است');

test_section('ensure_schema مهاجرت را هر بار که لازم باشد اجرا می‌کند');
$w = pu_reset();
pu_row($w, 40, null, 'queued');
pu_row($w, 40, null, 'queued');
HCI_DB::run_pending_migrations();
test_assert(pu_count($w, 'source_product_id = 40') === 1 && get_option('hci_unique_key_ready') === '1', 'run_pending_migrations یک‌بار اجرا و ثبت می‌شود');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
