<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — زمان‌بندی و Cursor/Retry سینک (S1-02، S1-03، S1-04) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);

function hci_sched_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $wpdb = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['__test_options'] = array();
    update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
    update_option('hci_api_key', 'test-key');
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    $GLOBALS['__fake_as_single_actions'] = array();
    $GLOBALS['__fake_as_recurring_actions'] = array();
    return $wpdb;
}

function hci_sched_ts(string $local): int {
    return (new DateTimeImmutable($local, new DateTimeZone('Asia/Tehran')))->getTimestamp();
}

function hci_sched_delta(array $items): array {
    return array(
        'response' => array('code' => 200, 'message' => 'OK'),
        'body' => json_encode(array('success' => true, 'data' => $items, 'pagination' => array('total_pages' => 1, 'total' => count($items)))),
    );
}

function hci_sched_error(): array {
    return array(
        'response' => array('code' => 500, 'message' => 'Internal Server Error'),
        'body' => json_encode(array('code' => 'server_error', 'message' => 'x', 'data' => array('status' => 500))),
    );
}

// ----------------------------------------------------------------------------
test_section('S1-02 — نوبت بعد همیشه دقیقاً روی ۰۶:۰۰ تهران است، نه «زمان اجرای واقعی + ۲۴ ساعت»');

test_assert(HCI_Sync::next_slot_timestamp(hci_sched_ts('2026-10-01 09:01:00')) === hci_sched_ts('2026-10-02 06:00:00'), 'اجرای دیرهنگام ۰۹:۰۱ → نوبت بعد فردا ۰۶:۰۰');
test_assert(HCI_Sync::next_slot_timestamp(hci_sched_ts('2026-10-01 05:00:00')) === hci_sched_ts('2026-10-01 06:00:00'), 'قبل از ۰۶:۰۰ → همان روز ۰۶:۰۰');
test_assert(HCI_Sync::next_slot_timestamp(hci_sched_ts('2026-10-01 06:00:00')) === hci_sched_ts('2026-10-02 06:00:00'), 'دقیقاً ۰۶:۰۰ → فردا ۰۶:۰۰ (نه همان لحظه)');

hci_sched_reset();
HCI_Sync::ensure_scheduled();
$slots = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::SLOT_HOOK));
test_assert(count($slots) === 1 && count($GLOBALS['__fake_as_recurring_actions']) === 0, 'ensure_scheduled یک نوبت تکی می‌سازد و هیچ زمان‌بندی تکرارشونده‌ی لغزنده‌ای نمی‌سازد');
test_assert((int) $slots[0]['timestamp'] % 60 === 0 && (new DateTimeImmutable('@' . $slots[0]['timestamp']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('H:i') === '06:00', 'نوبت ساخته‌شده ساعت ۰۶:۰۰ تهران است');

HCI_Sync::ensure_scheduled();
test_assert(count($GLOBALS['__fake_as_single_actions']) === 1, 'ensure_scheduled دوباره صدا زده شود نوبت تکراری نمی‌سازد');

// اجرای دیرهنگام نوبت (۰۹:۰۱) — نوبت بعد باید فردا ۰۶:۰۰ باشد، نه فردا ۰۹:۰۱.
$GLOBALS['__fake_as_single_actions'] = array();
$GLOBALS['__stub_http_response_queue'] = array(hci_sched_delta(array()));
HCI_Sync::run_scheduled_slot(hci_sched_ts('2026-10-09 09:01:00'));
$next = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::SLOT_HOOK));
test_assert(count($next) === 1 && (int) $next[0]['timestamp'] === hci_sched_ts('2026-10-10 06:00:00'), 'بعد از اجرای ۰۹:۰۱، نوبت بعد دقیقاً ۱۰ مهر ۰۶:۰۰ تهران است (لغزش صفر)');

// نوبت قدیمیِ تکرارشونده (نصب‌های قبلی) باید جمع شود و نوبت جدید جایش بیاید.
hci_sched_reset();
as_schedule_recurring_action(hci_sched_ts('2026-10-10 09:01:00'), DAY_IN_SECONDS, HCI_Sync::DAILY_HOOK, array(), 'hci-sync');
HCI_Sync::ensure_scheduled();
test_assert(count($GLOBALS['__fake_as_recurring_actions']) === 0, 'زمان‌بندی تکرارشونده قدیمی (که می‌لغزید) حذف شد');
test_assert(as_next_scheduled_action(HCI_Sync::SLOT_HOOK, array(), 'hci-sync') !== false, 'و به‌جایش نوبت تکی ۰۶:۰۰ ساخته شد');

// ----------------------------------------------------------------------------
test_section('S1-03 — Cursor با وجود خطا جلو نمی‌رود تا محصول خطادار «دور بعد» دوباره امتحان شود');

$wpdb = hci_sched_reset();
$parent = new WC_Product_Variable();
$parent->set_status('publish');
$parentId = $parent->save();
$var = new WC_Product_Variation();
$var->set_parent_id($parentId);
$var->set_regular_price('10');
$var->set_price('10');
$varId = $var->save();
$wpdb->insert('wp_hci_product_map', array('source_product_id' => 700, 'source_sku' => 'hmp-700', 'dest_product_id' => $parentId, 'dest_sku' => 'vsp-700', 'import_status' => HCI_DB::STATUS_IMPORTED, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'));
$wpdb->insert('wp_hci_product_map', array('source_product_id' => 700, 'source_variation_id' => 701, 'source_sku' => 'hmp-700', 'dest_product_id' => $parentId, 'dest_variation_id' => $varId, 'dest_sku' => 'vsp-700', 'import_status' => HCI_DB::STATUS_IMPORTED, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'));

$GLOBALS['__fake_wc_variation_save_throws'] = true;
$GLOBALS['__stub_http_response_queue'] = array(hci_sched_delta(array(
    array('source_product_id' => 700, 'is_active' => true, 'variations' => array(array('variation_id' => 701, 'price' => '5000', 'stock_quantity' => 3.0, 'is_active' => true))),
)));
$s = HCI_Sync::run_daily_sync(false);
test_assert($s['errors'] >= 1, 'خطا شمرده شد');
test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'با وجود خطا Cursor جلو نرفت — محصول دور بعد دوباره امتحان می‌شود');

$GLOBALS['__fake_wc_variation_save_throws'] = false;
$GLOBALS['__stub_http_response_queue'] = array(hci_sched_delta(array(
    array('source_product_id' => 700, 'is_active' => true, 'variations' => array(array('variation_id' => 701, 'price' => '5000', 'stock_quantity' => 3.0, 'is_active' => true))),
)));
$s2 = HCI_Sync::run_daily_sync(false);
test_assert($s2['errors'] === 0 && (string) get_option('hci_sync_cursor_gmt', '') !== '', 'دور بعد با صفر خطا Cursor جلو می‌رود');
test_assert(wc_get_product($varId)->get_regular_price() === (string) ((5000 + 1000) * 1.10), 'و همان محصول خطادار دور بعد واقعاً به‌روز شد');

// ----------------------------------------------------------------------------
test_section('S1-04 — شمارنده Retry هر روز ریست می‌شود و Sync Now ناموفق سهمیه مصرف نمی‌کند');

hci_sched_reset();
update_option('hci_sync_retry_count', 2, false); // زنجیره دیروز تمام‌شده
$GLOBALS['__stub_http_response_queue'] = array(hci_sched_error());
HCI_Sync::run_scheduled_slot(hci_sched_ts('2026-10-10 06:00:00'));
$retries = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::DAILY_HOOK));
test_assert(count($retries) === 1, 'نوبت روزانه جدید حتی بعد از زنجیره شکست‌خورده‌ی دیروز دوباره Retry می‌گیرد');

hci_sched_reset();
$GLOBALS['__stub_http_response_queue'] = array(hci_sched_error());
$m = HCI_Sync::run_daily_sync(true);
test_assert($m['success'] === false, 'Sync Now ناموفق صادقانه گزارش می‌شود');
test_assert(count($GLOBALS['__fake_as_single_actions']) === 0 && (int) get_option('hci_sync_retry_count', 0) === 0, 'Sync Now ناموفق نه Retry می‌سازد و نه از سهمیه‌ی روزانه مصرف می‌کند');

echo "\n=== جمع‌بندی زمان‌بندی/Cursor/Retry ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
