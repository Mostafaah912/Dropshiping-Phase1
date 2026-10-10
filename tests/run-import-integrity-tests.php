<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — قیمت خالی، داده تازه هنگام Import، محصول حذف‌شده (S1-05، S1-06، S1-07) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);

function ii_reset(bool $configured = false): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__stub_http_response_queue'] = array();
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    if ($configured) {
        update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
        update_option('hci_api_key', 'test-key');
    }
    return $w;
}

function ii_payload(array $o = array()): array {
    return array_merge(array(
        'name' => 'نام ویرایش‌شده کارمند', 'short_description' => 'توضیح کارمند', 'featured_image' => null, 'images' => array(),
        'category_path' => array(), 'product_type' => 'simple', 'sku' => 'hmp-900', 'price' => '100000',
        'stock_quantity' => 2.0, 'stock_status' => 'instock', 'variations' => array(),
    ), $o);
}

function ii_run(int $id, array $payload): array {
    HCI_DB::queue_import($id, 'hmp-' . $id, (string) wp_json_encode($payload));
    HCI_Import::process_import_action($id);
    return HCI_DB::get_map_row($id);
}

function ii_source_ok(array $data): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => $data)));
}

function ii_source_err(int $code, string $msg = 'x'): array {
    return array('response' => array('code' => $code, 'message' => 'err'), 'body' => json_encode(array('success' => false, 'error' => array('code' => 'e', 'message' => $msg))));
}

function ii_variations(): array {
    return array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation');
}

// ----------------------------------------------------------------------------
test_section('S1-05 — قیمت خالی در مبدا: محصول رایگان ساخته/منتشر نمی‌شود (D4: partial با پیام روشن)');

ii_reset();
$row = ii_run(900, ii_payload(array('price' => null)));
test_evidence('ردیف محصول ساده بدون قیمت', array('status' => $row['import_status'], 'msg' => $row['error_message']));
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL, 'وضعیت partial است، نه imported');
test_assert(strpos((string) $row['error_message'], 'قیمت') !== false, 'پیام فارسی ساده درباره‌ی قیمت می‌دهد');
$p = wc_get_product((int) $row['dest_product_id']);
test_assert($p->get_regular_price() !== '0' && $p->get_regular_price() === '' && $p->get_status() === 'draft', 'قیمت ۰ ثبت نشد و محصول پیش‌نویس ماند (منتشر نشد)');

ii_reset();
$row = ii_run(901, ii_payload(array('sku' => 'hmp-901', 'product_type' => 'variable', 'price' => null, 'variations' => array(
    array('variation_id' => 1, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '10000', 'stock_quantity' => 3.0, 'stock_status' => 'instock', 'sku' => null),
    array('variation_id' => 2, 'attributes' => array(array('name' => 'رنگ', 'option' => 'آبی')), 'price' => null, 'stock_quantity' => 3.0, 'stock_status' => 'instock', 'sku' => null),
))));
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL && strpos((string) $row['error_message'], 'قیمت') !== false, 'تنوع بدون قیمت ⇒ partial با پیام قیمت');
test_assert(count(ii_variations()) === 1, 'فقط تنوع قیمت‌دار ساخته شد؛ تنوع بدون قیمت با قیمت ۰ ساخته نشد');
foreach (ii_variations() as $v) {
    test_assert($v['data']['regular_price'] !== '0' && $v['data']['regular_price'] !== '', 'قیمت تنوع ساخته‌شده واقعی است');
}

ii_reset();
$row = ii_run(902, ii_payload(array('sku' => 'hmp-902')));
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED, 'محصول با قیمت سالم همچنان imported می‌شود (رفتار قبلی حفظ شد)');

// ----------------------------------------------------------------------------
test_section('S1-06 — هنگام Import قیمت/موجودی/تنوع‌ها تازه از مبدا می‌آید؛ نام/توضیح از ویرایش کارمند');

ii_reset(true);
$GLOBALS['__stub_http_response_queue'] = array(ii_source_ok(array('source_product_id' => 910, 'product_type' => 'simple', 'name' => 'نام مبدا', 'price' => '200000', 'stock_quantity' => 9.0, 'stock_status' => 'instock', 'is_active' => true, 'variations' => array())));
$row = ii_run(910, ii_payload(array('sku' => 'hmp-910', 'price' => '100000', 'stock_quantity' => 2.0)));
$p = wc_get_product((int) $row['dest_product_id']);
test_evidence('آدرس‌های درخواست‌شده', $GLOBALS['__test_last_requested_urls']);
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED, 'Import موفق');
test_assert($p->get_regular_price() === (string) ((200000 + 1000) * 1.10), 'قیمت از مبدا تازه آمد (۲۰۰٬۰۰۰) نه از اسنپ‌شات قدیمی (۱۰۰٬۰۰۰)');
test_assert((float) $p->get_stock_quantity() === 9.0, 'موجودی تازه از مبدا آمد');
test_assert($p->get_name() === 'نام ویرایش‌شده کارمند' && $p->get_short_description() === 'توضیح کارمند', 'نام و توضیح کوتاه همچنان از ویرایش کارمند است');
test_assert(count($GLOBALS['__test_last_requested_urls']) === 1 && strpos($GLOBALS['__test_last_requested_urls'][0], '/products/910') !== false, 'فقط یک درخواست کوچک برای همان یک محصول');

ii_reset(true);
$GLOBALS['__stub_http_response_queue'] = array(ii_source_ok(array('source_product_id' => 911, 'product_type' => 'variable', 'is_active' => true, 'variations' => array(
    array('variation_id' => 1, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '30000', 'stock_quantity' => 5.0, 'stock_status' => 'instock', 'sku' => null),
))));
$row = ii_run(911, ii_payload(array('sku' => 'hmp-911', 'product_type' => 'variable', 'price' => null, 'variations' => array(
    array('variation_id' => 1, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '10000', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'sku' => null),
))));
$m = HCI_DB::get_map_row(911, 1);
$v = wc_get_product((int) $m['dest_variation_id']);
test_assert($v->get_regular_price() === (string) ((30000 + 1000) * 1.10) && (float) $v->get_stock_quantity() === 5.0, 'قیمت و موجودی تنوع هم تازه از مبدا آمد');

ii_reset(true);
$GLOBALS['__stub_http_response_queue'] = array(ii_source_err(404, 'محصول فعال نیست.'));
$row = ii_run(912, ii_payload(array('sku' => 'hmp-912')));
test_assert($row['import_status'] === HCI_DB::STATUS_ERROR && strpos((string) $row['error_message'], 'هی‌مد') !== false, 'محصولی که دیگر در مبدا فعال نیست Import نمی‌شود، با پیام روشن');
test_assert(count($GLOBALS['__fake_wc_products']) === 0, 'هیچ محصولی ساخته نشد');

ii_reset(true);
$GLOBALS['__stub_http_response_queue'] = array(ii_source_err(500));
$row = ii_run(913, ii_payload(array('sku' => 'hmp-913')));
test_assert($row['import_status'] === HCI_DB::STATUS_ERROR && strpos((string) $row['error_message'], 'دوباره') !== false, 'قطعی موقت مبدا ⇒ خطای قابل تلاش دوباره (نه ساخت با داده قدیمی)');

// ----------------------------------------------------------------------------
test_section('S1-07 — محصول پاک/Trash‌شده در ووکامرس: وضعیت removed، آزاد شدن گرید، Import دوباره');

$w = ii_reset();
$row = ii_run(920, ii_payload(array('sku' => 'hmp-920', 'product_type' => 'variable', 'price' => null, 'variations' => array(
    array('variation_id' => 1, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '10000', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'sku' => null),
))));
$destId = (int) $row['dest_product_id'];
test_assert(in_array(920, HCI_DB::get_grid_locked_source_ids(), true), 'قبل از حذف، کارت در گرید قفل است');

$trashed = wc_get_product($destId);
$trashed->set_status('trash');
$trashed->save();
HCI_DB::mark_removed_by_dest_id($destId); // هوک wp_trash_post
$row = HCI_DB::get_map_row(920);
test_assert($row['import_status'] === HCI_DB::STATUS_REMOVED, 'ردیف به removed رفت');
test_assert(!in_array(920, HCI_DB::get_grid_locked_source_ids(), true), 'گرید آزاد شد (دیگر «قبلاً ثبت شده» نیست)');
test_assert(count(HCI_DB::get_imported_parent_rows()) === 0 && count(HCI_DB::get_imported_variation_rows_for_parents(array(920))) === 0, 'محصول و تنوع‌هایش از شمارش/سینک خارج شدند');

// بازگردانی از سطل زباله
$restored = wc_get_product($destId);
$restored->set_status('draft');
$restored->save();
HCI_DB::restore_removed_by_dest_id($destId);
test_assert(HCI_DB::get_map_row(920)['import_status'] === HCI_DB::STATUS_IMPORTED && count(HCI_DB::get_imported_parent_rows()) === 1, 'اگر از سطل زباله برگردد، دوباره imported است');

// حذف همیشگی بدون هوک (مثلاً مستقیم از دیتابیس) — بررسی وجود هنگام رندر
unset($GLOBALS['__fake_wc_products'][$destId], $GLOBALS['__fake_wc_sku_index']['vsp-920']); // ووکامرس واقعی SKU محصول پاک‌شده را آزاد می‌کند
$n = HCI_DB::reconcile_removed();
test_assert($n === 1 && HCI_DB::get_map_row(920)['import_status'] === HCI_DB::STATUS_REMOVED, 'reconcile_removed محصول ناپدیدشده را removed می‌کند');
test_assert(HCI_DB::reconcile_removed() === 0, 'اجرای دوباره چیزی عوض نمی‌کند');

// Import دوباره: محصول جدید ساخته می‌شود، نه استفاده از شناسه‌ی مرده
$_POST = array('source_product_id' => '920', 'source_sku' => 'hmp-920', 'entry_json' => json_encode(ii_payload(array('sku' => 'hmp-920')), JSON_UNESCAPED_UNICODE));
try { HCI_Import::ajax_queue_import(); } catch (RuntimeException $e) {}
test_assert(($GLOBALS['__test_last_json_response']['success'] ?? false) === true, 'صف‌بندی دوباره‌ی محصول removed پذیرفته شد (دیگر «قبلاً Import شده» نیست)');
HCI_Import::process_import_action(920);
$row = HCI_DB::get_map_row(920);
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED && (int) $row['dest_product_id'] !== $destId && wc_get_product((int) $row['dest_product_id']) !== false, 'محصول تازه ساخته شد و ردیف دوباره imported است');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
