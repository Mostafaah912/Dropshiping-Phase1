<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — دکمه «ریست داده‌ها» (PHP " . PHP_VERSION . ")\n";
echo "جدول ردیابی + صف‌های Action Scheduler + کش محصولات پاک می‌شوند؛ تنظیمات/Cursor/سایر Hookها دست‌نخورده می‌مانند.\n";

test_section('تست ۱ — HCI_Admin::reset_data(): جدول، صف‌ها و کش کاملاً پاک می‌شوند');

hci_test_reset_wc_fakes();
$wpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['__test_options'] = array();
$GLOBALS['__fake_as_single_actions'] = array();
$GLOBALS['__fake_as_recurring_actions'] = array();

// --- جدول ردیابی: چند رکورد با وضعیت‌های مختلف ---
$wpdb->insert('wp_hci_product_map', array(
    'source_product_id' => 100,
    'source_sku' => 'hmp-100',
    'dest_product_id' => 5000,
    'dest_sku' => 'vsp-100',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));
$wpdb->insert('wp_hci_product_map', array(
    'source_product_id' => 101,
    'source_sku' => 'hmp-101',
    'import_status' => HCI_DB::STATUS_QUEUED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

// --- کش محصولات (شامل حالت نیمه‌کاره ناشی از Rate Limit) ---
set_transient('hci_products_cache', array(array('source_product_id' => 1)), 600);
set_transient('hci_products_partial_state', array('items' => array(), 'next_page' => 2), 1800);

// --- صف Action Scheduler: چند Import در صف + زمان‌بندی سینک روزانه (Recurring) + یک Retry (Single) ---
as_schedule_single_action(time(), HCI_Import::ACTION_HOOK, array('source_product_id' => 100), HCI_Import::GROUP);
as_schedule_single_action(time(), HCI_Import::ACTION_HOOK, array('source_product_id' => 101), HCI_Import::GROUP);
as_schedule_recurring_action(time() + 3600, DAY_IN_SECONDS, HCI_Sync::DAILY_HOOK, array(), 'hci-sync');
as_schedule_single_action(time() + 300, HCI_Sync::DAILY_HOOK, array(), 'hci-sync'); // یک Retry نیمه‌کاره

// --- یک Hook کاملاً بی‌ربط (متعلق به یک پلاگین دیگر/ووکامرس) که هرگز نباید لمس شود ---
as_schedule_single_action(time() + 60, 'woocommerce_unrelated_hook', array('x' => 1), 'woocommerce-db-updates');

// --- شمارنده Retry سینک (شبیه‌سازی یک زنجیره Backoff نیمه‌کاره) ---
update_option('hci_sync_retry_count', 1, false);

// --- تنظیمات/تاریخچه‌ای که نباید توسط ریست دست‌خورده شود ---
update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1', false);
update_option('hci_api_key', 'secret-key', false);
update_option('hci_price_fixed_amount', 1000.0, false);
update_option('hci_price_percent', 10.0, false);
update_option('hci_sync_cursor_gmt', '2026-09-27 06:00:00', false);
update_option('hci_last_sync_summary', array('checked' => 5), false);

test_assert(count($wpdb->get_results('SELECT * FROM wp_hci_product_map', ARRAY_A)) === 2, 'قبل از ریست، ۲ رکورد در جدول ردیابی هست');
test_assert(is_array(get_transient('hci_products_cache')), 'قبل از ریست، کش محصولات موجود است');
test_assert(is_array(get_transient('hci_products_partial_state')), 'قبل از ریست، حالت نیمه‌کاره Rate Limit هم موجود است');
test_assert(count($GLOBALS['__fake_as_single_actions']) === 4, 'قبل از ریست، ۴ Action تک‌باره در صف است (۲ Import + ۱ Retry سینک + ۱ بی‌ربط)');
test_assert(count($GLOBALS['__fake_as_recurring_actions']) === 1, 'قبل از ریست، ۱ زمان‌بندی تکرارشونده (سینک روزانه) هست');

$result = HCI_Admin::reset_data();
test_evidence('نتیجه HCI_Admin::reset_data()', $result);

test_assert($result['rows_cleared'] === 2, 'reset_data() دقیقاً تعداد رکوردهای پاک‌شده (۲) را گزارش می‌کند');
test_assert(count($wpdb->get_results('SELECT * FROM wp_hci_product_map', ARRAY_A)) === 0, 'جدول ردیابی کاملاً خالی شده');
test_assert(get_transient('hci_products_cache') === false, 'کش محصولات پاک شده');
test_assert(get_transient('hci_products_partial_state') === false, 'حالت نیمه‌کاره Rate Limit هم پاک شده — نه فقط کش نهایی');

$remainingSingle = $GLOBALS['__fake_as_single_actions'];
$remainingRecurring = $GLOBALS['__fake_as_recurring_actions'];
test_evidence('صف‌های باقی‌مانده بعد از ریست', array('single' => $remainingSingle, 'recurring' => $remainingRecurring));

test_assert(
    !in_array(HCI_Import::ACTION_HOOK, array_column($remainingSingle, 'hook'), true),
    'هیچ Action صف Import (hci_import_product) دیگر باقی نمانده'
);
test_assert(
    !in_array(HCI_Sync::DAILY_HOOK, array_column($remainingSingle, 'hook'), true) &&
    !in_array(HCI_Sync::DAILY_HOOK, array_column($remainingRecurring, 'hook'), true),
    'هم Retry نیمه‌کاره سینک هم خودِ زمان‌بندی تکرارشونده سینک پاک شدند'
);
test_assert(
    in_array('woocommerce_unrelated_hook', array_column($remainingSingle, 'hook'), true),
    'Action کاملاً بی‌ربط (متعلق به Hook دیگری) دست‌نخورده باقی مانده — ریست فقط مختص Hookهای همین پلاگین است'
);
test_assert((int) get_option('hci_sync_retry_count', 0) === 0, 'شمارنده Retry سینک به صفر بازنشانی شده (بدون زنجیره Backoff نیمه‌کاره)');

test_assert((string) get_option('hci_api_url', '') === 'https://source.invalid/wp-json/hmw/v1', 'تنظیمات اتصال (API URL) دست‌نخورده مانده');
test_assert((string) get_option('hci_api_key', '') === 'secret-key', 'تنظیمات اتصال (API Key) دست‌نخورده مانده');
test_assert((float) get_option('hci_price_fixed_amount', -1) === 1000.0, 'فرمول قیمت‌گذاری دست‌نخورده مانده');
test_assert((string) get_option('hci_sync_cursor_gmt', '') === '2026-09-27 06:00:00', 'Cursor سینک روزانه دست‌نخورده مانده — ریست فقط جدول/صف/کش است، نه تاریخچه سینک');
test_assert(is_array(get_option('hci_last_sync_summary', null)), 'آخرین گزارش سینک هم دست‌نخورده مانده');

// =============================================================================
// تست ۲ — بعد از ریست، محصول قبلاً Import‌شده دیگر به‌عنوان «Import‌شده» شناخته
// نمی‌شود (چون فقط منبع حقیقت خودِ همین جدول است که حالا خالی است) — این
// دقیقاً همان رفتاری‌ست که در متن هشدار صفحه ریست هم توضیح داده شده.
// =============================================================================
test_section('تست ۲ — بعد از ریست، هیچ محصولی دیگر «قبلاً Import‌شده» شناخته نمی‌شود');

test_assert(HCI_DB::get_imported_source_ids() === array(), 'get_imported_source_ids() بعد از ریست خالی است');
test_assert(HCI_DB::get_import_statuses(array(100, 101)) === array(), 'get_import_statuses() برای همان شناسه‌های قبلی چیزی برنمی‌گرداند');

echo "\n=== جمع‌بندی ریست داده‌ها ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
