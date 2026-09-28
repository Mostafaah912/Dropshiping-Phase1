<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — کارگر سمت مرورگر Import (PHP " . PHP_VERSION . ")\n";
echo "Claim اتمیک، بازیابی قفل قدیمی، عدم پردازش دوباره، hci_import_next سرتاسری — روی کد Production واقعی.\n";

/**
 * چون wp_send_json_success/error در بوت‌استرپ تست دقیقاً مثل wp_die واقعی
 * یک استثنا پرتاب می‌کنند (درخواست را همان‌جا متوقف می‌کنند)، این کمکی صدا
 * زدن AJAX handlerها را داخل try/catch می‌گیرد و پاسخ ثبت‌شده در
 * __test_last_json_response را برمی‌گرداند — دقیقاً همان چیزی که مرورگر
 * به‌صورت JSON از fetch() می‌گیرد.
 */
function call_ajax(callable $fn): array {
    $GLOBALS['__test_last_json_response'] = null;
    try {
        $fn();
    } catch (RuntimeException $e) {
        // انتظار می‌رود — یعنی wp_send_json_success/error صدا زده شده
    }
    return $GLOBALS['__test_last_json_response'] ?? array();
}

// =============================================================================
// تست ۱ — Claim اتمیک: صدا زدن دوباره روی همان ردیف (که دیگر queued نیست)
// شکست می‌خورد — دقیقاً تضمینی که از پردازش دوباره توسط دو کارگر جلوگیری می‌کند.
// =============================================================================
test_section('تست ۱ — HCI_DB::claim_specific(): دو تلاش هم‌زمان روی یک ردیف، فقط یکی می‌برد');

hci_test_reset_wc_fakes();
$wpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb;

HCI_DB::queue_import(801, 'hmp-801', '{}');

$claim1 = HCI_DB::claim_specific(801);
test_evidence('نتیجه Claim اول', $claim1);
test_assert($claim1 !== null && $claim1['source_product_id'] === 801, 'کارگر اول با موفقیت Claim می‌کند');

$row = HCI_DB::get_map_row(801);
test_assert($row['import_status'] === HCI_DB::STATUS_PROCESSING, 'وضعیت به processing تغییر کرده');
test_assert(!empty($row['started_at']), 'started_at ثبت شده');

$claim2 = HCI_DB::claim_specific(801);
test_assert($claim2 === null, 'کارگر دومی که هم‌زمان همین ردیف را می‌خواست، چیزی گیرش نمی‌آید (دیگر queued نیست)');

// =============================================================================
// تست ۲ — process_import_action() روی ردیفی که از قبل (توسط کارگر دیگر)
// processing شده، هیچ محصولی نمی‌سازد — نه خطا، فقط بی‌صدا صرف‌نظر می‌کند.
// =============================================================================
test_section('تست ۲ — process_import_action() روی ردیف از قبل Claim‌شده، محصول تکراری نمی‌سازد');

$productCountBefore = count($GLOBALS['__fake_wc_products']);
HCI_Import::process_import_action(801); // ردیف از تست ۱ همچنان processing است (Claim قبلی هنوز "تمام" نشده)
$productCountAfter = count($GLOBALS['__fake_wc_products']);
test_assert($productCountAfter === $productCountBefore, 'هیچ محصول جدیدی ساخته نشد — process_import_action() هم همان Claim اتمیک را رعایت می‌کند');
$rowAfter = HCI_DB::get_map_row(801);
test_assert($rowAfter['import_status'] === HCI_DB::STATUS_PROCESSING, 'وضعیت هنوز processing است (دست‌نخورده) — نه imported نه error');

// =============================================================================
// تست ۳ — release_stale_locks(): فقط قفل‌های واقعاً قدیمی (>۵ دقیقه) آزاد
// می‌شوند، نه هر processing‌ای.
// =============================================================================
test_section('تست ۳ — HCI_DB::release_stale_locks(): فقط قفل قدیمی‌تر از حد آزاد می‌شود');

hci_test_reset_wc_fakes();
$wpdb2 = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb2;

$wpdb2->insert('wp_hci_product_map', array(
    'source_product_id' => 810,
    'import_status' => HCI_DB::STATUS_PROCESSING,
    'started_at' => gmdate('Y-m-d H:i:s', time() - 600), // ۱۰ دقیقه پیش — قدیمی
    'created_at' => gmdate('Y-m-d H:i:s', time() - 600),
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 600),
));
$wpdb2->insert('wp_hci_product_map', array(
    'source_product_id' => 811,
    'import_status' => HCI_DB::STATUS_PROCESSING,
    'started_at' => gmdate('Y-m-d H:i:s', time() - 30), // ۳۰ ثانیه پیش — تازه
    'created_at' => gmdate('Y-m-d H:i:s', time() - 30),
    'updated_at' => gmdate('Y-m-d H:i:s', time() - 30),
));

$released = HCI_DB::release_stale_locks(300);
test_evidence('تعداد ردیف آزادشده', $released);
test_assert($released === 1, 'دقیقاً همان یک قفل قدیمی (۸۱۰) آزاد شد');

$row810 = HCI_DB::get_map_row(810);
$row811 = HCI_DB::get_map_row(811);
test_assert($row810['import_status'] === HCI_DB::STATUS_QUEUED, 'ردیف ۸۱۰ (قفل قدیمی) به queued برگشت — دوباره Claim‌پذیر است');
test_assert($row811['import_status'] === HCI_DB::STATUS_PROCESSING, 'ردیف ۸۱۱ (تازه، هنوز در حال کار یک کارگر واقعی) دست‌نخورده ماند');

// =============================================================================
// تست ۴ — زنجیره کامل کارگر مرورگر (hci_import_next): صف‌بندی → چند بار
// صدا زدن AJAX → هر بار دقیقاً یک محصول → در پایان done=true.
// =============================================================================
test_section('تست ۴ — ajax_import_next(): پردازش پشت‌سرهم صف، هر بار یک محصول');

hci_test_reset_wc_fakes();
$wpdb3 = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb3;
HCI_Pricing::save(0.0, 0.0, 'publish');

foreach (array(901, 902) as $pid) {
    HCI_DB::queue_import($pid, 'hmp-' . $pid, (string) wp_json_encode(array(
        'name' => 'محصول ' . $pid,
        'short_description' => '',
        'featured_image' => null,
        'images' => array(),
        'category_path' => array(),
        'product_type' => 'simple',
        'sku' => 'hmp-' . $pid,
        'price' => '1000',
        'stock_quantity' => 1,
        'variations' => array(),
    )));
}

$resp1 = call_ajax(fn () => HCI_Import::ajax_import_next());
test_evidence('پاسخ AJAX اول', $resp1);
test_assert($resp1['success'] === true, 'پاسخ AJAX اول موفق است');
test_assert($resp1['data']['done'] === false && in_array($resp1['data']['source_product_id'], array(901, 902), true), 'دقیقاً یک محصول (از دو تای صف) پردازش شد');
test_assert($resp1['data']['import_status'] === HCI_DB::STATUS_IMPORTED, 'همان محصول با موفقیت Import شد');

$resp2 = call_ajax(fn () => HCI_Import::ajax_import_next());
test_assert($resp2['data']['done'] === false, 'محصول دومِ صف هم در فراخوانی بعدی پردازش شد');

$resp3 = call_ajax(fn () => HCI_Import::ajax_import_next());
test_evidence('پاسخ AJAX سوم (صف خالی)', $resp3);
test_assert($resp3['data']['done'] === true, 'وقتی چیزی در صف نمانده، done=true برمی‌گردد (نه خطا)');

$row901 = HCI_DB::get_map_row(901);
$row902 = HCI_DB::get_map_row(902);
test_assert($row901['import_status'] === HCI_DB::STATUS_IMPORTED && $row902['import_status'] === HCI_DB::STATUS_IMPORTED, 'هر دو محصول واقعاً Imported ثبت شده‌اند');
test_assert(!empty($row901['started_at']) && !empty($row901['finished_at']), 'started_at و finished_at برای ردیابی زمان واقعاً پر شده‌اند');

// =============================================================================
// تست ۵ — شکست دانلود تصویر از مسیر hci_import_next هم partial می‌شود، و
// Retry (دوباره queue_import + دوباره hci_import_next) محصول تکراری نمی‌سازد.
// =============================================================================
test_section('تست ۵ — hci_import_next: شکست تصویر → partial → Retry بدون تکرار محصول');

hci_test_reset_wc_fakes();
$wpdb4 = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb4;
HCI_Pricing::save(0.0, 0.0, 'draft');

$brokenUrl = 'https://example.test/broken-worker.jpg';
hci_test_fail_image_download($brokenUrl);

$payload = (string) wp_json_encode(array(
    'name' => 'محصول با تصویر خراب (کارگر)',
    'short_description' => '',
    'featured_image' => $brokenUrl,
    'images' => array(),
    'category_path' => array(),
    'product_type' => 'simple',
    'sku' => 'hmp-950',
    'price' => '5000',
    'stock_quantity' => 1,
    'variations' => array(),
));
HCI_DB::queue_import(950, 'hmp-950', $payload);

$respFail = call_ajax(fn () => HCI_Import::ajax_import_next());
test_assert($respFail['data']['import_status'] === HCI_DB::STATUS_PARTIAL, 'از مسیر AJAX هم شکست تصویر → partial (نه error کامل)');
$rowFail = HCI_DB::get_map_row(950);
$destIdFirst = (int) $rowFail['dest_product_id'];
test_assert($destIdFirst > 0, 'dest_product_id برای Retry ذخیره شده');
$productCountBeforeRetry = count($GLOBALS['__fake_wc_products']);

$GLOBALS['__fake_media_sideload_fail_urls'] = array();
HCI_DB::queue_import(950, 'hmp-950', $payload); // همان کاری که دکمه «تلاش مجدد» می‌کند
$respRetry = call_ajax(fn () => HCI_Import::ajax_import_next());
test_evidence('پاسخ Retry از مسیر کارگر', $respRetry);
test_assert($respRetry['data']['import_status'] === HCI_DB::STATUS_IMPORTED, 'بعد از رفع مشکل، Retry از همین مسیر هم imported می‌شود');
test_assert((int) HCI_DB::get_map_row(950)['dest_product_id'] === $destIdFirst, 'همان dest_product_id قبلی — محصول تکراری ساخته نشده');
test_assert(count($GLOBALS['__fake_wc_products']) === $productCountBeforeRetry, 'تعداد کل محصولات (Fake) قبل/بعد از Retry یکسان مانده');

echo "\n=== جمع‌بندی کارگر سمت مرورگر Import ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
