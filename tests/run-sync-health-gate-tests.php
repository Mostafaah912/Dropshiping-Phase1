<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — هماهنگی با Full Sync ساعت ۰۴:۰۰ مبدا (S1-11) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);
$GLOBALS['__stub_auto_health'] = true;

function hg_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
    update_option('hci_api_key', 'test-key');
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__stub_http_response_queue'] = array();
    $GLOBALS['__stub_health_response_override'] = null;
    $GLOBALS['__fake_as_single_actions'] = array();
    return $w;
}

function hg_health(string $status): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => array('api' => 'ok', 'full_sync_status' => $status))));
}

function hg_delta_calls(): int {
    return count(array_filter($GLOBALS['__test_last_requested_urls'], static fn (string $u): bool => strpos($u, '/products/delta') !== false));
}

function hg_ok(): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => array(), 'pagination' => array('total_pages' => 1, 'total' => 0))));
}

test_section('S1-11 — اگر Full Sync مبدا در حال اجراست، سینک صبر می‌کند و با Backoff دوباره تلاش می‌کند');

hg_reset();
$GLOBALS['__stub_health_response_override'] = hg_health('running');
$GLOBALS['__stub_http_response_queue'] = array(hg_ok());
$before = time();
$s = HCI_Sync::run_scheduled_slot();
test_assert(hg_delta_calls() === 0, 'وقتی مبدا در حال Full Sync است، هیچ Delta گرفته نشد');
test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'Cursor دست‌نخورده ماند');
$retries = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::DAILY_HOOK));
test_assert(count($retries) === 1 && $retries[0]['timestamp'] >= $before + 295 && $retries[0]['timestamp'] <= $before + 320, 'تلاش بعدی ≈ ۵ دقیقه بعد زمان‌بندی شد');
test_assert(!empty($s['deferred']) && strpos((string) $s['message'], 'هی‌مد') !== false, 'خلاصه، انتظار را با پیام فارسی ساده ثبت می‌کند');
test_assert(HCI_Sync::get_history()[0]['deferred'] === true, 'در تاریخچه هم «به تعویق افتاد» ثبت شد');

// Backoff: دومین انتظار ۱۰ دقیقه
$before2 = time();
HCI_Sync::run_daily_sync(false);
$retries = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::DAILY_HOOK));
test_assert(hg_delta_calls() === 0 && count($retries) === 2 && $retries[1]['timestamp'] >= $before2 + 595 && $retries[1]['timestamp'] <= $before2 + 620, 'تلاش دوم هم منتظر ماند و این بار ≈ ۱۰ دقیقه بعد زمان‌بندی شد');

// سهمیه Backoff تمام شد ⇒ سینک بی‌خیال انتظار می‌شود (نه بی‌نهایت منتظر)
$GLOBALS['__stub_http_response_queue'] = array(hg_ok());
$s3 = HCI_Sync::run_daily_sync(false);
test_assert(hg_delta_calls() === 1 && $s3['success'] === true, 'بعد از دو انتظار، سینک حتی با Full Sync در حال اجرا انجام می‌شود (Cursor امن است)');

// Sync Now دستی منتظر نمی‌ماند
hg_reset();
$GLOBALS['__stub_health_response_override'] = hg_health('running');
$GLOBALS['__stub_http_response_queue'] = array(hg_ok());
$m = HCI_Sync::run_daily_sync(true);
test_assert(hg_delta_calls() === 1 && $m['success'] === true && count($GLOBALS['__fake_as_single_actions']) === 0, 'بروزرسانی دستی همیشه همان لحظه انجام می‌شود');

// مبدا آزاد
hg_reset();
$GLOBALS['__stub_health_response_override'] = hg_health('idle');
$GLOBALS['__stub_http_response_queue'] = array(hg_ok());
$ok = HCI_Sync::run_scheduled_slot();
test_assert(hg_delta_calls() === 1 && $ok['success'] === true && empty($ok['deferred']), 'مبدا آزاد ⇒ سینک عادی');

// خرابی خودِ health مانع سینک نمی‌شود (Delta خودش خطا را مدیریت می‌کند)
hg_reset();
$GLOBALS['__stub_health_response_override'] = array('response' => array('code' => 500, 'message' => 'e'), 'body' => '{}');
$GLOBALS['__stub_http_response_queue'] = array(hg_ok());
$h = HCI_Sync::run_scheduled_slot();
test_assert(hg_delta_calls() === 1 && $h['success'] === true, 'اگر خود بررسی سلامت جواب ندهد، سینک ادامه پیدا می‌کند');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
