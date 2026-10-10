<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode — ابزار تشخیصی سینک (S1-01): تاریخچه ۳۰ اجرای مشتری + چند Full Sync آخر مبدا (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);
$GLOBALS['__stub_auto_health'] = true;

function sd_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
    update_option('hci_api_key', 'test-key');
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    $GLOBALS['__stub_http_response_queue'] = array();
    $GLOBALS['__fake_as_single_actions'] = array();
    return $w;
}

function sd_ok(array $items = array()): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => $items, 'pagination' => array('total_pages' => 1, 'total' => count($items)))));
}

function sd_err(): array {
    return array('response' => array('code' => 500, 'message' => 'e'), 'body' => json_encode(array('code' => 'server_error', 'message' => 'x', 'data' => array('status' => 500))));
}

// ----------------------------------------------------------------------------
test_section('تاریخچه اجرای سینک مشتری');

sd_reset();
$GLOBALS['__stub_http_response_queue'] = array(sd_ok(), sd_err(), sd_ok());
HCI_Sync::run_scheduled_slot();
HCI_Sync::run_daily_sync(false);   // Retry
HCI_Sync::run_daily_sync(true);    // دستی
$h = HCI_Sync::get_history();
test_evidence('تاریخچه', $h);
test_assert(count($h) === 3, 'سه اجرا، سه ردیف');
test_assert($h[0]['kind'] === 'manual' && $h[1]['kind'] === 'retry' && $h[2]['kind'] === 'scheduled', 'نوع هر اجرا درست ثبت شد و جدیدترین اول است');
test_assert($h[1]['success'] === false && $h[1]['message'] !== '' && $h[2]['success'] === true, 'نتیجه و پیام خطا ثبت شد');
test_assert(isset($h[0]['started_at'], $h[0]['finished_at'], $h[0]['checked'], $h[0]['price_updated'], $h[0]['stock_updated'], $h[0]['deactivated'], $h[0]['errors'], $h[0]['cursor_before'], $h[0]['cursor_advanced']), 'زمان شروع/پایان، شمارش‌ها و وضعیت Cursor ثبت شد');
test_assert($h[2]['cursor_advanced'] === true && $h[1]['cursor_advanced'] === false, 'جلو رفتن/نرفتن Cursor ثبت شد');

// خطای داخلی یک تنوع در نمونه‌ی خطاها بیاید
$w = sd_reset();
$parent = new WC_Product_Variable();
$parentId = $parent->save();
$var = new WC_Product_Variation();
$vid = $var->save();
$w->insert('wp_hci_product_map', array('source_product_id' => 50, 'import_status' => 'imported', 'dest_product_id' => $parentId, 'created_at' => 'x', 'updated_at' => 'x'));
$w->insert('wp_hci_product_map', array('source_product_id' => 50, 'source_variation_id' => 501, 'import_status' => 'imported', 'dest_variation_id' => $vid, 'created_at' => 'x', 'updated_at' => 'x'));
$GLOBALS['__fake_wc_variation_save_throws'] = true;
$GLOBALS['__stub_http_response_queue'] = array(sd_ok(array(array('source_product_id' => 50, 'is_active' => true, 'variations' => array(array('variation_id' => 501, 'price' => '5000', 'stock_quantity' => 1.0))))));
HCI_Sync::run_daily_sync(false);
$h = HCI_Sync::get_history();
test_assert($h[0]['errors'] === 1 && !empty($h[0]['error_samples']) && strpos($h[0]['error_samples'][0], 'شبیه‌سازی') !== false, 'متن خطای واقعی (نه فقط تعداد) در تاریخچه می‌آید');

// سقف ۳۰ ردیف
sd_reset();
for ($i = 0; $i < 35; $i++) {
    $GLOBALS['__stub_http_response_queue'] = array(sd_ok());
    HCI_Sync::run_daily_sync(true);
}
test_assert(count(HCI_Sync::get_history()) === 30, 'فقط ۳۰ اجرای آخر نگه داشته می‌شود');

// نمایش فقط پشت «تنظیمات پیشرفته»
ob_start();
HCI_Sync::render_page();
$html = (string) ob_get_clean();
test_assert(strpos($html, 'hci-adv-box') !== false && strpos($html, 'hci-history-table') !== false, 'جدول تاریخچه داخل بخش «تنظیمات پیشرفته» رندر می‌شود');
test_assert(preg_match('/id="hci-adv-box"[^>]*display:none/', $html) === 1 && strpos($html, 'hci-history-table') > strpos($html, 'hci-adv-box'), 'و به‌طور پیش‌فرض پنهان است');

// ----------------------------------------------------------------------------
test_section('مبدا — چند Full Sync آخر');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE wp_hmw_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, run_uuid TEXT, sync_type TEXT, trigger_type TEXT, status TEXT, started_at TEXT, finished_at TEXT, duration_ms INTEGER, products_fetched INTEGER DEFAULT 0, products_inserted INTEGER DEFAULT 0, products_updated INTEGER DEFAULT 0, products_unchanged INTEGER DEFAULT 0, products_deactivated INTEGER DEFAULT 0, errors_count INTEGER DEFAULT 0, error_summary TEXT, error_details TEXT, api_requests INTEGER DEFAULT 0, created_at TEXT)");
$GLOBALS['wpdb'] = new Fake_WPDB($pdo);
for ($i = 1; $i <= 12; $i++) {
    $GLOBALS['wpdb']->insert('wp_hmw_sync_logs', array('run_uuid' => 'u' . $i, 'sync_type' => $i % 5 === 0 ? 'delta' : 'full', 'trigger_type' => 'cron', 'status' => $i === 12 ? 'running' : 'completed', 'started_at' => sprintf('2026-10-%02d 00:30:00', $i), 'finished_at' => $i === 12 ? null : sprintf('2026-10-%02d 01:10:00', $i), 'products_fetched' => 1000 + $i, 'products_updated' => $i, 'errors_count' => 0, 'created_at' => 'x'));
}
$recent = HMW_DB::get_recent_full_syncs(10);
test_assert(count($recent) === 10, 'ده Full Sync آخر برگردانده می‌شود');
test_assert($recent[0]['started_at'] === '2026-10-12 00:30:00' && $recent[0]['status'] === 'running', 'جدیدترین اول است و Full Sync در حال اجرا هم دیده می‌شود');
test_assert(!in_array('delta', array_column($recent, 'sync_type'), true), 'فقط Full Sync (نه انواع دیگر)');
test_assert(isset($recent[1]['finished_at'], $recent[1]['products_fetched'], $recent[1]['products_updated'], $recent[1]['errors_count']), 'شروع، پایان، وضعیت و تعداد در هر ردیف هست');

echo "\n=== جمع‌بندی تشخیصی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
