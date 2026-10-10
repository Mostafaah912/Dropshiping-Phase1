<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — سینک محصول Variable: والد قیمت/موجودی نمی‌گیرد، غیرفعال‌شدن والد همه تنوع‌ها را ناموجود می‌کند (S1-08، S1-09) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);

function sv_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
    update_option('hci_api_key', 'test-key');
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__stub_http_response_queue'] = array();
    return $w;
}

function sv_delta(array $items): array {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => $items, 'pagination' => array('total_pages' => 1, 'total' => count($items)))));
}

/** @return array{parent:int,variations:int[]} */
function sv_make_variable(Fake_WPDB $w, int $sourceId): array {
    $parent = new WC_Product_Variable();
    $parent->set_status('publish');
    $parent->set_manage_stock(false);
    $parent->set_stock_status('instock');
    $parentId = $parent->save();
    $w->insert('wp_hci_product_map', array('source_product_id' => $sourceId, 'source_sku' => 'hmp-' . $sourceId, 'dest_product_id' => $parentId, 'dest_sku' => 'vsp-' . $sourceId, 'import_status' => HCI_DB::STATUS_IMPORTED, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'));
    $vids = array();
    foreach (array(1, 2) as $n) {
        $v = new WC_Product_Variation();
        $v->set_parent_id($parentId);
        $v->set_regular_price('11000');
        $v->set_price('11000');
        $v->set_manage_stock(true);
        $v->set_stock_quantity(4);
        $v->set_stock_status('instock');
        $vid = $v->save();
        $vids[$n] = $vid;
        $w->insert('wp_hci_product_map', array('source_product_id' => $sourceId, 'source_variation_id' => $sourceId * 10 + $n, 'source_sku' => null, 'dest_product_id' => null, 'dest_variation_id' => $vid, 'dest_sku' => null, 'import_status' => HCI_DB::STATUS_IMPORTED, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'));
    }
    return array('parent' => $parentId, 'variations' => $vids);
}

// ----------------------------------------------------------------------------
test_section('S1-08 — والد Variable در سینک قیمت/موجودی خودش را اعمال نمی‌کند و شمارنده باد نمی‌کند');

$w = sv_reset();
$m = sv_make_variable($w, 800);
$GLOBALS['__stub_http_response_queue'] = array(sv_delta(array(
    array('source_product_id' => 800, 'sku' => 'hmp-800', 'price' => '5000', 'stock_quantity' => 3.0, 'stock_status' => 'instock', 'is_active' => true),
)));
$s = HCI_Sync::run_daily_sync(false);
$parent = wc_get_product($m['parent']);
test_assert($s['price_updated'] === 0 && $s['stock_updated'] === 0, 'شمارنده‌های قیمت/موجودی با ردیف والد Variable بالا نرفتند');
test_assert($parent->get_regular_price() === '' && $parent->get_manage_stock() === false, 'قیمت/موجودی روی والد Variable نوشته نشد');
test_assert(wc_get_product($m['variations'][1])->get_regular_price() === '11000', 'تنوع‌ها دست‌نخورده ماندند (در delta نبودند)');

// ----------------------------------------------------------------------------
test_section('S1-09 — والد Variable در مبدا غیرفعال شد ⇒ همه تنوع‌ها (حتی آن‌ها که در delta نیامده‌اند) ناموجود می‌شوند');

$w = sv_reset();
$m = sv_make_variable($w, 801);
$GLOBALS['__stub_http_response_queue'] = array(sv_delta(array(
    array('source_product_id' => 801, 'sku' => 'hmp-801', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'is_active' => false),
)));
$s = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه', $s);
test_assert(wc_get_product($m['variations'][1])->get_stock_status() === 'outofstock' && wc_get_product($m['variations'][2])->get_stock_status() === 'outofstock', 'هر دو تنوع ناموجود شدند');
test_assert(wc_get_product($m['parent'])->get_stock_status() === 'outofstock', 'والد هم ناموجود شد');
test_assert($s['deactivated'] === 1 && $s['errors'] === 0, 'یک محصول «دیگر فروخته نمی‌شود» شمرده شد');
test_assert(wc_get_product($m['variations'][1])->get_regular_price() === '11000', 'قیمت تنوع‌ها لمس نشد');

// اجرای دوباره همان وضعیت: چیزی دوباره شمرده نشود
$GLOBALS['__stub_http_response_queue'] = array(sv_delta(array(
    array('source_product_id' => 801, 'sku' => 'hmp-801', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'is_active' => false),
)));
$s = HCI_Sync::run_daily_sync(false);
test_assert($s['deactivated'] === 0, 'اجرای دوباره، محصول از قبل ناموجود را دوباره نمی‌شمارد');

// فعال شدن دوباره والد: delta تنوع‌ها را نمی‌آورد ⇒ از مبدا تازه گرفته می‌شود
$GLOBALS['__stub_http_response_queue'] = array(
    sv_delta(array(array('source_product_id' => 801, 'sku' => 'hmp-801', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'is_active' => true))),
    array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('success' => true, 'data' => array(
        'source_product_id' => 801, 'product_type' => 'variable', 'is_active' => true,
        'variations' => array(
            array('variation_id' => 8011, 'price' => '20000', 'stock_quantity' => 6.0, 'stock_status' => 'instock', 'sku' => null, 'attributes' => array()),
            array('variation_id' => 8012, 'price' => '20000', 'stock_quantity' => 0.0, 'stock_status' => 'outofstock', 'sku' => null, 'attributes' => array()),
        ),
    )))),
);
$s = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه فعال‌سازی دوباره', $s);
test_assert(wc_get_product($m['variations'][1])->get_stock_status() === 'instock' && (float) wc_get_product($m['variations'][1])->get_stock_quantity() === 6.0, 'تنوع موجود دوباره موجود شد');
test_assert(wc_get_product($m['variations'][2])->get_stock_status() === 'outofstock', 'تنوع ناموجود در مبدا ناموجود ماند');
test_assert(wc_get_product($m['parent'])->get_stock_status() === 'instock', 'والد دوباره موجود شد');

// تنوعِ تغییرکرده‌ی والد غیرفعال (والد در delta با is_active=false و فقط یک تنوع)
$w = sv_reset();
$m = sv_make_variable($w, 802);
$GLOBALS['__stub_http_response_queue'] = array(sv_delta(array(
    array('source_product_id' => 802, 'sku' => null, 'price' => null, 'stock_quantity' => null, 'stock_status' => null, 'is_active' => false,
        'variations' => array(array('variation_id' => 8021, 'price' => '9000', 'stock_quantity' => 2.0, 'stock_status' => 'instock', 'sku' => null, 'is_active' => true))),
)));
$s = HCI_Sync::run_daily_sync(false);
test_assert(wc_get_product($m['variations'][1])->get_stock_status() === 'outofstock' && wc_get_product($m['variations'][2])->get_stock_status() === 'outofstock', 'وقتی والد غیرفعال است، تنوعِ تغییرکرده هم دوباره موجود نمی‌شود');

echo "\n=== جمع‌بندی سینک Variable ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
