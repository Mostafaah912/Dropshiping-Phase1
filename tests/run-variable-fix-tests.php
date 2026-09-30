<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode — ساخت تنوع‌ها (Variation)، موجودی تنوع‌ها، ترمیم (PHP " . PHP_VERSION . ")\n";
require_once HMW_REPO_ROOT . '/includes/class-hmw-sync.php';
HCI_Source_Client::configure_delays_for_tests(0, 0);

function vf_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    return $w;
}

function vf_payload(array $overrides = array()): array {
    return array_merge(array(
        'name' => 'محصول متغیر', 'short_description' => '', 'featured_image' => null, 'images' => array(),
        'category_path' => array(), 'product_type' => 'variable', 'sku' => 'hmp-4992', 'price' => null,
        'stock_quantity' => null, 'stock_status' => 'instock',
        'variations' => array(
            array('variation_id' => 1, 'attributes' => array(array('name' => 'رنگ', 'option' => '01')), 'price' => '10000', 'stock_quantity' => 7.0, 'stock_status' => 'instock', 'sku' => 'hmp-4992-1'),
            array('variation_id' => 2, 'attributes' => array(array('name' => 'رنگ', 'option' => '02')), 'price' => '10000', 'stock_quantity' => null, 'stock_status' => 'instock', 'sku' => null),
            array('variation_id' => 3, 'attributes' => array(array('name' => 'رنگ', 'option' => 'یک ردیف')), 'price' => '12000', 'stock_quantity' => null, 'stock_status' => 'outofstock', 'sku' => null),
        ),
    ), $overrides);
}

function vf_run(int $id, array $payload): array {
    HCI_DB::queue_import($id, 'hmp-' . $id, (string) wp_json_encode($payload));
    HCI_Import::process_import_action($id);
    return HCI_DB::get_map_row($id);
}

function vf_variations(): array {
    return array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation');
}

// ---------------------------------------------------------------------------
test_section('علت الف — شکست یک تصویر نباید ساخت تنوع‌ها را کاملاً متوقف کند');
$w = vf_reset();
hci_test_fail_image_download('https://x.test/bad.jpg');
$row = vf_run(4992, vf_payload(array('featured_image' => 'https://x.test/bad.jpg')));
test_evidence('ردیف', array('status' => $row['import_status'], 'err' => $row['error_message']));
test_assert(count(vf_variations()) === 3, 'حتی با شکست تصویر، هر ۳ تنوع ساخته شد (قبلاً صفر بود چون تابع زودتر برمی‌گشت)');
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL, 'وضعیت partial است (تصویر) نه imported');
$parentId = (int) $row['dest_product_id'];
$GLOBALS['__fake_media_sideload_fail_urls'] = array();
$row = vf_run(4992, vf_payload(array('featured_image' => 'https://x.test/bad.jpg')));
test_evidence('retry', $row['error_message']); test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED, 'بعد از رفع تصویر imported می‌شود');
test_assert((int) $row['dest_product_id'] === $parentId, 'والد تکراری ساخته نشد');
test_assert(count(vf_variations()) === 3, 'تنوع تکراری ساخته نشد');

// ---------------------------------------------------------------------------
test_section('نگاشت attribute، قیمت، SKU و جدول ردیابی');
$w = vf_reset();
$row = vf_run(4992, vf_payload());
$parent = wc_get_product((int) $row['dest_product_id']);
$attrs = $parent->get_attributes();
test_assert(count($attrs) === 1 && $attrs[0]->get_options() === array('01', '02', 'یک ردیف'), 'گزینه‌های والد رشته‌اند و «01» و «یک ردیف» دست‌نخورده');
$key = sanitize_title('رنگ');
$opts = $attrs[0]->get_options();
$ok = true;
foreach (vf_variations() as $v) {
    $a = $v['data']['attributes'];
    $ok = $ok && isset($a[$key]) && in_array($a[$key], $opts, true);
}
test_assert($ok, 'کلید و مقدار attribute هر تنوع دقیقاً با والد یکی است');
$m1 = HCI_DB::get_map_row(4992, 1);
$m2 = HCI_DB::get_map_row(4992, 2);
test_assert(!empty($m1['dest_variation_id']) && $m1['dest_sku'] === 'vsp-4992-1', 'ردیابی تنوع ۱ + SKU مستقل با prefix');
$v2 = wc_get_product((int) $m2['dest_variation_id']);
test_assert($v2->get_sku() === '' && $v2->get_regular_price() === (string) ((10000 + 1000) * 1.10), 'تنوع بدون SKU مستقل SKU والد را نگرفت؛ قیمت با فرمول');
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED, 'همه‌چیز سالم ⇒ imported');
test_assert(in_array((int) $row['dest_product_id'], $GLOBALS['__fake_wc_synced_parents'], true) && in_array((int) $row['dest_product_id'], $GLOBALS['__fake_wc_cleared_transients'], true), 'WC_Product_Variable::sync و wc_delete_product_transients صدا زده شد');

// ---------------------------------------------------------------------------
test_section('قاعده موجودی تنوع‌ها + وضعیت والد');
$m3 = HCI_DB::get_map_row(4992, 3);
$v1 = wc_get_product((int) $m1['dest_variation_id']);
$v3 = wc_get_product((int) $m3['dest_variation_id']);
test_assert($v1->get_manage_stock() && (float) $v1->get_stock_quantity() === 7.0 && $v1->get_stock_status() === 'instock', 'عدد ⇒ مدیریت موجودی');
test_assert(!$v2->get_manage_stock() && $v2->get_stock_status() === 'instock', 'بدون عدد ولی موجود ⇒ manage_stock=false و instock');
test_assert(!$v3->get_manage_stock() && $v3->get_stock_status() === 'outofstock', 'ناموجود ⇒ outofstock');
$p = wc_get_product((int) $row['dest_product_id']);
test_assert(!$p->get_manage_stock() && $p->get_stock_status() === 'instock', 'والد موجود چون حداقل یک تنوع موجود است');
$w = vf_reset();
$pl = vf_payload();
foreach ($pl['variations'] as &$vv) { $vv['stock_status'] = 'outofstock'; $vv['stock_quantity'] = null; }
unset($vv);
$row = vf_run(4993, $pl);
test_assert(wc_get_product((int) $row['dest_product_id'])->get_stock_status() === 'outofstock', 'همه تنوع‌ها ناموجود ⇒ والد ناموجود');

// ---------------------------------------------------------------------------
test_section('مبدا قدیمی بدون stock_status');
$w = vf_reset();
$pl = vf_payload();
foreach ($pl['variations'] as &$vv) { unset($vv['stock_status']); $vv['stock_quantity'] = null; }
unset($vv);
$row = vf_run(4994, $pl);
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL && strpos((string) $row['error_message'], 'مشخص نیست') !== false, 'بدون کرش؛ partial با پیام ساده');
$any = wc_get_product((int) HCI_DB::get_map_row(4994, 1)['dest_variation_id']);
test_assert($any->get_stock_status() !== 'instock', 'instock فرض نشد');
test_assert(HCI_Products::variation_stock_text(array('stock_quantity' => null)) === 'نامشخص', 'UI: «نامشخص»');
test_assert(HCI_Products::variation_stock_text(array('stock_quantity' => 7.0)) === '۷ عدد', 'UI: «۷ عدد»');
test_assert(HCI_Products::variation_stock_text(array('stock_quantity' => null, 'stock_status' => 'instock')) === 'موجود', 'UI: «موجود»');
test_assert(HCI_Products::variation_stock_text(array('stock_quantity' => null, 'stock_status' => 'outofstock')) === 'ناموجود', 'UI: «ناموجود»');

// ---------------------------------------------------------------------------
test_section('شکست ساخت تنوع ⇒ هرگز imported نمی‌شود + ترمیم بدون والد تکراری');
$w = vf_reset();
$GLOBALS['__fake_wc_variation_save_throws'] = true;
$row = vf_run(4995, vf_payload());
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL && $row['error_message'] === 'تنوع‌های محصول ساخته نشد.', 'partial با پیام ساده فارسی');
$parentId = (int) $row['dest_product_id'];
$GLOBALS['__fake_wc_variation_save_throws'] = false;
$row = vf_run(4995, vf_payload()); // «تلاش مجدد»
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED && (int) $row['dest_product_id'] === $parentId && count(vf_variations()) === 3, 'Retry فقط تنوع‌ها را ساخت، والد تکراری نشد');

test_section('ترمیم محصول قدیمی imported بدون تنوع');
$w = vf_reset();
$now = '2026-01-01 00:00:00';
$parent = new WC_Product_Variable();
$parent->set_status('publish');
$legacyParent = $parent->save();
$w->insert('wp_hci_product_map', array('source_product_id' => 4996, 'source_sku' => 'hmp-4996', 'dest_product_id' => $legacyParent, 'import_status' => 'imported',
    'import_payload' => (string) wp_json_encode(vf_payload()), 'created_at' => $now, 'updated_at' => $now));
test_assert(count(HCI_DB::get_broken_variable_rows()) === 1, 'محصول ناقص شناسایی شد');
$rep = HCI_Import::repair_variations(4996);
test_assert($rep['success'] === true && HCI_DB::get_map_row(4996)['import_status'] === HCI_DB::STATUS_QUEUED, 'ترمیم ردیف را دوباره به صف می‌برد');
HCI_Import::process_import_action(4996);
$row = HCI_DB::get_map_row(4996);
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED && (int) $row['dest_product_id'] === $legacyParent && count(vf_variations()) === 3, 'تنوع‌ها ساخته شد و والد همان قبلی است');
test_assert(HCI_DB::get_broken_variable_rows() === array() && HCI_Import::repair_variations(4996)['success'] === false, 'بعد از ترمیم دیگر ناقص نیست');

// ---------------------------------------------------------------------------
test_section('سینک: فقط تغییر stock_status بدون تغییر عدد');
$w = vf_reset();
$row = vf_run(4997, vf_payload());
$m2 = HCI_DB::get_map_row(4997, 2);
test_assert(wc_get_product((int) $m2['dest_variation_id'])->get_stock_status() === 'instock', 'قبل از سینک: instock');
update_option('hci_api_url', 'https://s.invalid/wp-json/hmw/v1');
update_option('hci_api_key', 'k');
$GLOBALS['__stub_http_response_queue'] = array(array(
    'response' => array('code' => 200, 'message' => 'OK'),
    'body' => json_encode(array('success' => true, 'data' => array(array(
        'source_product_id' => 4997, 'sku' => 'hmp-4992', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'is_active' => true,
        'variations' => array(
            array('variation_id' => 2, 'sku' => null, 'price' => '10000', 'stock_quantity' => null, 'stock_status' => 'outofstock', 'is_active' => true),
            array('variation_id' => 1, 'sku' => 'hmp-4992-1', 'price' => '10000', 'stock_quantity' => 0.0, 'stock_status' => 'outofstock', 'is_active' => true),
            array('variation_id' => 3, 'sku' => null, 'price' => '12000', 'stock_quantity' => null, 'stock_status' => 'outofstock', 'is_active' => true),
        ),
    )), 'pagination' => array('total_pages' => 1))),
));
$sum = HCI_Sync::run_daily_sync(true);
test_assert(wc_get_product((int) $m2['dest_variation_id'])->get_stock_status() === 'outofstock', 'وضعیت تنوع ۲ فقط با تغییر stock_status به‌روز شد');
test_assert((float) wc_get_product((int) HCI_DB::get_map_row(4997, 1)['dest_variation_id'])->get_stock_quantity() === 0.0, 'عدد تنوع ۱ هم طبق مبدا به‌روز شد');
test_assert(wc_get_product((int) $row['dest_product_id'])->get_stock_status() === 'outofstock', 'والد بعد از سینک دوباره از روی تنوع‌ها ناموجود شد');
test_assert($sum['stock_updated'] >= 1, 'در خلاصه سینک شمرده شد');

// ---------------------------------------------------------------------------
test_section('مبدا (heymode-wholesale): موجودی مؤثر Variation');
$m = new ReflectionMethod('HMW_Sync', 'variation_payload');
$m->setAccessible(true);
$parentRow = array('source_product_id' => 9, 'name' => 'والد', 'product_url' => null, 'category_path' => '', 'image_url' => null);
$base = array('id' => 100, 'status' => 'publish', 'price' => '5', 'attributes' => array(), 'stock_status' => 'instock');
$own = $m->invoke(null, $base + array('manage_stock' => true, 'stock_quantity' => 4), $parentRow, 'u');
$inherit = $m->invoke(null, $base + array('manage_stock' => 'parent', 'stock_quantity' => 50), $parentRow, 'u');
$off = $m->invoke(null, array_merge($base, array('manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock')), $parentRow, 'u');
test_assert($own['stock_quantity'] === '4' && $own['manage_stock'] === 1, 'مدیریت موجودی خودِ تنوع ⇒ عدد');
test_assert($inherit['stock_quantity'] === null && $inherit['manage_stock'] === 0 && $inherit['stock_status'] === 'instock', "manage_stock='parent' ⇒ عدد والد ذخیره نمی‌شود، وضعیت مؤثر می‌ماند");
test_assert($off['stock_quantity'] === null && $off['stock_status'] === 'outofstock', 'مدیریت خاموش ⇒ فقط وضعیت');

echo "\n=== جمع‌بندی ===\nPASS: {$GLOBALS['__test_passes']}\nFAIL: {$GLOBALS['__test_failures']}\n";
exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
