<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode — «تطبیق کامل» (شبکه ایمنی): endpoint دسته‌ای مبدا + تطبیق مشتری، مستقل از Cursor (S1-10) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);
$GLOBALS['__stub_auto_health'] = true;

// ============================================================================
test_section('مبدا — GET /products/batch?ids=… (فقط شناسه‌های خواسته‌شده، با همه تنوع‌ها حتی غیرفعال)');

$GLOBALS['wpdb'] = hmw_test_create_products_db();
$w = $GLOBALS['wpdb'];
hmw_test_insert_product($w, array('source_product_id' => 1, 'sku' => 'A-1', 'price' => '1000', 'stock_quantity' => '5', 'is_active' => 1));
hmw_test_insert_product($w, array('source_product_id' => 2, 'product_type' => 'variable', 'sku' => 'B-2', 'price' => null, 'is_active' => 1));
hmw_test_insert_product($w, array('source_product_id' => 21, 'parent_product_id' => 2, 'product_type' => 'variation', 'sku' => null, 'price' => '2000', 'stock_quantity' => '3', 'is_active' => 1));
hmw_test_insert_product($w, array('source_product_id' => 22, 'parent_product_id' => 2, 'product_type' => 'variation', 'sku' => null, 'price' => '2100', 'stock_quantity' => '0', 'stock_status' => 'outofstock', 'is_active' => 0));
hmw_test_insert_product($w, array('source_product_id' => 3, 'sku' => 'C-3', 'price' => '300', 'is_active' => 0));
hmw_test_insert_product($w, array('source_product_id' => 4, 'sku' => 'D-4', 'price' => '400', 'is_active' => 1));

$resp = HMW_REST_API::products_batch(new WP_REST_Request(array('ids' => '1,2,3,999')));
$d = $resp->get_data();
test_evidence('پاسخ', $d);
test_assert($resp->get_status() === 200 && $d['success'] === true, 'HTTP 200');
$by = array();
foreach ($d['data'] as $it) { $by[$it['source_product_id']] = $it; }
test_assert(count($by) === 3 && !isset($by[4]), 'فقط شناسه‌های خواسته‌شده برگشت (۴ نیست)');
test_assert($by[1]['price'] === '1000' && $by[1]['stock_quantity'] === 5.0 && $by[1]['is_active'] === true, 'مقادیر محصول ساده درست است');
test_assert($by[3]['is_active'] === false, 'محصول غیرفعال هم با is_active=false می‌آید');
test_assert(count($by[2]['variations']) === 2 && $by[2]['variations'][1]['variation_id'] === 22 && $by[2]['variations'][1]['is_active'] === false, 'تنوع‌های والد Variable، حتی غیرفعال، با is_active می‌آیند');
test_assert($d['missing'] === array(999), 'شناسه ناشناخته در missing گزارش می‌شود (نه نادیده)');
test_assert(HMW_REST_API::products_batch(new WP_REST_Request(array('ids' => '')))->get_status() === 400, 'ids خالی ⇒ ۴۰۰');
test_assert(HMW_REST_API::products_batch(new WP_REST_Request(array('ids' => implode(',', range(1, 101)))))->get_status() === 400, 'بیش از ۱۰۰ شناسه ⇒ ۴۰۰');

// ============================================================================
function fr_reset(): Fake_WPDB {
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
    $GLOBALS['__fake_as_recurring_actions'] = array();
    return $w;
}

function fr_simple(Fake_WPDB $w, int $sourceId, string $price = '100', int $stock = 1): int {
    $p = new WC_Product_Simple();
    $p->set_status('publish');
    $p->set_regular_price($price);
    $p->set_price($price);
    $p->set_manage_stock(true);
    $p->set_stock_quantity($stock);
    $p->set_stock_status('instock');
    $id = $p->save();
    $w->insert('wp_hci_product_map', array('source_product_id' => $sourceId, 'source_sku' => 'hmp-' . $sourceId, 'dest_product_id' => $id, 'dest_sku' => 'vsp-' . $sourceId, 'import_status' => HCI_DB::STATUS_IMPORTED, 'created_at' => 'x', 'updated_at' => 'x'));
    return $id;
}

function fr_batch(array $items, array $missing = array()): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => $items, 'missing' => $missing)));
}

test_section('مشتری — تطبیق کامل مستقل از Cursor قیمت/موجودی/وضعیت را اصلاح می‌کند');

$w = fr_reset();
$a = fr_simple($w, 10, '100', 1);
$b = fr_simple($w, 11, '100', 1);
$c = fr_simple($w, 12, '100', 1);
$d = fr_simple($w, 13, '777', 4);
update_option('hci_sync_cursor_gmt', '2030-01-01 00:00:00', false); // Cursor «جلوتر از همه» — Delta هیچ‌چیز نمی‌آورد
$GLOBALS['__stub_http_response_queue'] = array(fr_batch(array(
    array('source_product_id' => 10, 'product_type' => 'simple', 'price' => '5000', 'stock_quantity' => 8.0, 'stock_status' => 'instock', 'is_active' => true, 'variations' => array()),
    array('source_product_id' => 11, 'product_type' => 'simple', 'price' => '100', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'is_active' => false, 'variations' => array()),
    array('source_product_id' => 12, 'product_type' => 'simple', 'price' => '100', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'is_active' => true, 'variations' => array()),
), array(13)));
$s = HCI_Sync::run_full_reconcile(true);
test_evidence('خلاصه', $s);
test_assert($s['success'] === true && $s['kind'] === 'reconcile', 'موفق و از نوع reconcile');
test_assert(wc_get_product($a)->get_regular_price() === (string) ((5000 + 1000) * 1.10) && (float) wc_get_product($a)->get_stock_quantity() === 8.0, 'قیمت و موجودی قدیمی اصلاح شد');
test_assert(wc_get_product($b)->get_stock_status() === 'outofstock', 'محصول غیرفعال‌شده در مبدا ناموجود شد');
test_assert(wc_get_product($c)->get_regular_price() === '100' || $s['checked'] >= 3, 'محصول سالم بررسی شد');
test_assert(wc_get_product($d)->get_regular_price() === '777' && wc_get_product($d)->get_stock_status() === 'instock', 'محصولی که مبدا نمی‌شناسد دست‌نخورده ماند');
test_assert($s['missing'] === 1, 'تعداد «مبدا نمی‌شناسد» در خلاصه آمد');
test_assert((string) get_option('hci_sync_cursor_gmt') === '2030-01-01 00:00:00', 'Cursor اصلاً دست نخورد');
test_assert(HCI_Sync::get_history()[0]['kind'] === 'reconcile', 'در تاریخچه با نوع «تطبیق کامل» ثبت شد');
test_assert(count($GLOBALS['__test_last_requested_urls']) === 1 && strpos($GLOBALS['__test_last_requested_urls'][0], '/products/batch') !== false && strpos($GLOBALS['__test_last_requested_urls'][0], 'ids=') !== false, 'یک درخواست دسته‌ای (نه یک درخواست برای هر محصول)');

// دسته‌بندی ۵۰تایی
$w = fr_reset();
for ($i = 1; $i <= 120; $i++) { fr_simple($w, 1000 + $i); }
$GLOBALS['__stub_http_response_queue'] = array(fr_batch(array()), fr_batch(array()), fr_batch(array()));
HCI_Sync::run_full_reconcile(true);
test_assert(count($GLOBALS['__test_last_requested_urls']) === 3, '۱۲۰ محصول در ۳ درخواست ۵۰تایی');

// Variable: تنوع غیرفعال
$w = fr_reset();
$parent = new WC_Product_Variable();
$parent->set_status('publish');
$parent->set_stock_status('instock');
$pid = $parent->save();
$w->insert('wp_hci_product_map', array('source_product_id' => 20, 'dest_product_id' => $pid, 'import_status' => 'imported', 'created_at' => 'x', 'updated_at' => 'x'));
$vids = array();
foreach (array(201, 202) as $sv) {
    $v = new WC_Product_Variation();
    $v->set_regular_price('11000'); $v->set_manage_stock(true); $v->set_stock_quantity(2); $v->set_stock_status('instock');
    $vids[$sv] = $v->save();
    $w->insert('wp_hci_product_map', array('source_product_id' => 20, 'source_variation_id' => $sv, 'dest_variation_id' => $vids[$sv], 'import_status' => 'imported', 'created_at' => 'x', 'updated_at' => 'x'));
}
$GLOBALS['__stub_http_response_queue'] = array(fr_batch(array(array('source_product_id' => 20, 'product_type' => 'variable', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'is_active' => true, 'variations' => array(
    array('variation_id' => 201, 'price' => '3000', 'stock_quantity' => 5.0, 'stock_status' => 'instock', 'sku' => null, 'is_active' => true),
    array('variation_id' => 202, 'price' => '3000', 'stock_quantity' => 0.0, 'stock_status' => 'outofstock', 'sku' => null, 'is_active' => false),
)))));
$s = HCI_Sync::run_full_reconcile(true);
test_assert((float) wc_get_product($vids[201])->get_stock_quantity() === 5.0 && wc_get_product($vids[202])->get_stock_status() === 'outofstock', 'هر تنوع طبق مبدا اصلاح شد (تنوع غیرفعال ناموجود)');
test_assert(wc_get_product($pid)->get_stock_status() === 'instock', 'والد از روی تنوع‌ها موجود می‌ماند');

// مبدا هنوز آپدیت نشده (endpoint ندارد)
$w = fr_reset();
fr_simple($w, 30, '100', 1);
$GLOBALS['__stub_http_response_queue'] = array(array('response' => array('code' => 404, 'message' => 'Not Found'), 'body' => json_encode(array('code' => 'rest_no_route', 'message' => 'No route', 'data' => array('status' => 404)))));
$s = HCI_Sync::run_full_reconcile(true);
test_assert($s['success'] === false && strpos((string) $s['message'], 'آپدیت') !== false, 'اگر افزونه مبدا آپدیت نشده باشد، پیام فارسی روشن می‌دهد');

// فقط محصولات Import‌شده؛ removed/partial شرکت نمی‌کنند
$w = fr_reset();
fr_simple($w, 40);
$w->insert('wp_hci_product_map', array('source_product_id' => 41, 'dest_product_id' => 5000, 'import_status' => 'partial', 'created_at' => 'x', 'updated_at' => 'x'));
$GLOBALS['__stub_http_response_queue'] = array(fr_batch(array()));
HCI_Sync::run_full_reconcile(true);
parse_str((string) parse_url($GLOBALS['__test_last_requested_urls'][0], PHP_URL_QUERY), $q);
test_assert(($q['ids'] ?? '') === '40', 'فقط محصول imported فرستاده شد (partial نه)');

// ============================================================================
test_section('تطبیق هفتگی خودکار + دکمه دستی');

$fri = static fn (string $local): int => (new DateTimeImmutable($local, new DateTimeZone('Asia/Tehran')))->getTimestamp();
$fmt = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('D Y-m-d H:i');
test_assert($fmt(HCI_Sync::next_reconcile_timestamp($fri('2026-10-10 09:00:00'))) === 'Fri 2026-10-16 07:00', 'از شنبه ۱۸ مهر ← جمعه بعد ساعت ۰۷:۰۰');
test_assert($fmt(HCI_Sync::next_reconcile_timestamp($fri('2026-10-16 08:00:00'))) === 'Fri 2026-10-23 07:00', 'جمعه بعد از ۰۷:۰۰ ← جمعه هفته بعد');
test_assert($fmt(HCI_Sync::next_reconcile_timestamp($fri('2026-10-16 06:00:00'))) === 'Fri 2026-10-16 07:00', 'جمعه قبل از ۰۷:۰۰ ← همان روز');

fr_reset();
HCI_Sync::ensure_scheduled();
$hooks = array_column($GLOBALS['__fake_as_single_actions'], 'hook');
test_assert(in_array(HCI_Sync::RECONCILE_HOOK, $hooks, true), 'ensure_scheduled تطبیق هفتگی را هم زمان‌بندی می‌کند');
HCI_Sync::ensure_scheduled();
test_assert(count(array_keys($hooks = array_column($GLOBALS['__fake_as_single_actions'], 'hook'), HCI_Sync::RECONCILE_HOOK)) === 1, 'تکراری ساخته نمی‌شود');

$GLOBALS['__fake_as_single_actions'] = array();
$GLOBALS['__stub_http_response_queue'] = array(fr_batch(array()));
HCI_Sync::run_scheduled_reconcile($fri('2026-10-16 07:03:00'));
$next = array_values(array_filter($GLOBALS['__fake_as_single_actions'], static fn (array $a): bool => $a['hook'] === HCI_Sync::RECONCILE_HOOK));
test_assert(count($next) === 1 && (int) $next[0]['timestamp'] === $fri('2026-10-23 07:00:00'), 'بعد از اجرا، نوبت بعد جمعه هفته بعد ۰۷:۰۰ است');

ob_start();
HCI_Sync::render_page();
$html = (string) ob_get_clean();
test_assert(strpos($html, 'hci_reconcile_now') !== false && strpos($html, 'hci-adv-box') < strpos($html, 'hci_reconcile_now'), 'دکمه دستی داخل «تنظیمات پیشرفته» است');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
