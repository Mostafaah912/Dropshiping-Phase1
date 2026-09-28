<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — سینک روزانه (PHP " . PHP_VERSION . ")\n";
echo "فقط Price/Stock، Overwrite فرمول، Out of Stock شدن محصول غیرفعال، شکست API + Backoff، Cursor فقط بعد از موفقیت — روی کد production واقعی.\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);

function hci_sync_delta_response(array $items, int $total_pages = 1): array {
    return array(
        'response' => array('code' => 200, 'message' => 'OK'),
        'body' => json_encode(array(
            'success' => true,
            'data' => $items,
            'pagination' => array('total_pages' => $total_pages, 'total' => count($items)),
        )),
    );
}

function hci_sync_error_response(int $code = 500): array {
    return array(
        'response' => array('code' => $code, 'message' => 'Internal Server Error'),
        'body' => json_encode(array('code' => 'server_error', 'message' => 'منبع در دسترس نیست', 'data' => array('status' => $code))),
    );
}

/**
 * حالت پایه‌ی هر تست: جدول ردیابی SQLite تازه، فروشگاه Fake WooCommerce
 * خالی، گزینه‌ها (کورسر/فرمول قیمت/API) پاک و از نو تنظیم شده.
 */
function hci_sync_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $wpdb = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['__test_options'] = array();
    update_option('hci_api_url', 'https://source.invalid/wp-json/hmw/v1');
    update_option('hci_api_key', 'test-key');
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__fake_as_single_actions'] = array();
    $GLOBALS['__fake_as_recurring_actions'] = array();
    return $wpdb;
}

// =============================================================================
// تست ۱ — فقط Price/Stock به‌روز می‌شوند؛ نام/توضیح/تصویر/دسته هرگز Overwrite
// نمی‌شوند؛ و اگر مقدار واقعاً تغییر نکرده باشد، دوباره نوشته نمی‌شود.
// =============================================================================
test_section('تست ۱ — سینک فقط Price/Stock را دست می‌زند، بقیه فیلدها دست‌نخورده می‌مانند');

$wpdb = hci_sync_reset();

$product = new WC_Product_Simple();
$product->set_status('publish');
$product->set_name('نام ویرایش‌شده کارمند');
$product->set_short_description('توضیح کوتاه ویرایش‌شده کارمند');
$product->set_sku('vsp-500');
$product->set_regular_price('100');
$product->set_price('100');
$product->set_manage_stock(true);
$product->set_stock_quantity(2);
$product->set_stock_status('instock');
$product->set_category_ids(array(55, 56));
$product->set_image_id(999);
$product->set_gallery_image_ids(array(1001, 1002));
$destId = $product->save();

$wpdb->insert('wp_hci_product_map', array(
    'source_product_id' => 500,
    'source_sku' => 'hmp-500',
    'dest_product_id' => $destId,
    'dest_sku' => 'vsp-500',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array('source_product_id' => 500, 'sku' => 'hmp-500', 'price' => '100000', 'stock_quantity' => 7.0, 'is_active' => true, 'variations' => array()),
    )),
);

$summary1 = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه سینک اول', $summary1);

$updated = wc_get_product($destId);
test_assert($summary1['success'] === true, 'سینک با موفقیت تمام شد');
test_assert($summary1['checked'] === 1, 'دقیقاً یک محصول بررسی شد');
test_assert($summary1['price_updated'] === 1 && $summary1['stock_updated'] === 1, 'هم قیمت هم موجودی به‌عنوان تغییریافته گزارش شدند');
test_assert($updated->get_regular_price() === (string) ((100000 + 1000) * 1.10), 'قیمت دقیقاً با فرمول سراسری HCI_Pricing بازمحاسبه شده: (100000+1000)×1.10');
test_assert((float) $updated->get_stock_quantity() === 7.0 && $updated->get_stock_status() === 'instock', 'موجودی دقیقاً از مقدار جدید منبع آمده');
test_assert($updated->get_name() === 'نام ویرایش‌شده کارمند', 'نام ویرایش‌شده کارمند هرگز Overwrite نشد');
test_assert($updated->get_short_description() === 'توضیح کوتاه ویرایش‌شده کارمند', 'توضیح کوتاه هم دست‌نخورده ماند');
test_assert($updated->get_category_ids() === array(55, 56), 'دسته‌بندی‌ها هرگز Overwrite نشدند');
test_assert($updated->get_image_id() === 999 && $updated->get_gallery_image_ids() === array(1001, 1002), 'تصویر Featured و گالری هرگز Overwrite نشدند');

// اجرای دوباره سینک دقیقاً با همان مقادیر منبع — چون چیزی واقعاً تغییر
// نکرده، نباید دوباره price_updated/stock_updated گزارش شود.
$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array('source_product_id' => 500, 'sku' => 'hmp-500', 'price' => '100000', 'stock_quantity' => 7.0, 'is_active' => true, 'variations' => array()),
    )),
);
$summary1b = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه سینک دوم (بدون تغییر واقعی در مقادیر منبع)', $summary1b);
test_assert($summary1b['price_updated'] === 0 && $summary1b['stock_updated'] === 0, 'وقتی مقدار واقعاً تغییر نکرده، دوباره به‌عنوان به‌روزشده گزارش/نوشته نمی‌شود');

// =============================================================================
// تست ۲ — Overwrite صریح قیمت دستی: حتی اگر کارمند دستی قیمت را چیز دیگری
// گذاشته باشد، سینک همیشه با فرمول سراسری آن را جایگزین می‌کند.
// =============================================================================
test_section('تست ۲ — قیمت دستی کارمند همیشه با فرمول سراسری Overwrite می‌شود');

$wpdb2 = hci_sync_reset();

$product2 = new WC_Product_Simple();
$product2->set_status('publish');
$product2->set_name('محصول دو');
$product2->set_sku('vsp-501');
$product2->set_regular_price('54321'); // قیمتی که کارمند دستی و کاملاً دلخواه تنظیم کرده، نه محصول فرمول
$product2->set_price('54321');
$product2->set_manage_stock(true);
$product2->set_stock_quantity(4);
$product2->set_stock_status('instock');
$destId2 = $product2->save();

$wpdb2->insert('wp_hci_product_map', array(
    'source_product_id' => 501,
    'source_sku' => 'hmp-501',
    'dest_product_id' => $destId2,
    'dest_sku' => 'vsp-501',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array('source_product_id' => 501, 'sku' => 'hmp-501', 'price' => '20000', 'stock_quantity' => 4.0, 'is_active' => true, 'variations' => array()),
    )),
);

$summary2 = HCI_Sync::run_daily_sync(false);
$updated2 = wc_get_product($destId2);
test_evidence('قیمت بعد از سینک', array('regular_price' => $updated2->get_regular_price(), 'انتظار' => (string) ((20000 + 1000) * 1.10)));
test_assert($updated2->get_regular_price() === (string) ((20000 + 1000) * 1.10), 'قیمت دستی کارمند (54321) کاملاً نادیده گرفته شد؛ فقط فرمول سراسری روی قیمت خام منبع اعمال شده');
test_assert($summary2['price_updated'] === 1, 'قیمت به‌عنوان تغییریافته گزارش شد (چون واقعاً با مقدار قبلی فرق داشت)');

// =============================================================================
// تست ۳ — محصول غیرفعال/حذف/Draft‌شده در منبع: فقط Out of Stock، هرگز حذف/
// Draft در مقصد، و قیمت/موجودی دقیق اصلاً لمس نمی‌شوند.
// =============================================================================
test_section('تست ۳ — محصول غیرفعال در منبع (is_active=false) فقط Out of Stock می‌شود، نه حذف/Draft/تغییر قیمت');

$wpdb3 = hci_sync_reset();

$product3 = new WC_Product_Simple();
$product3->set_status('publish');
$product3->set_name('محصول سه');
$product3->set_sku('vsp-502');
$product3->set_regular_price('123');
$product3->set_price('123');
$product3->set_manage_stock(true);
$product3->set_stock_quantity(5);
$product3->set_stock_status('instock');
$destId3 = $product3->save();

$wpdb3->insert('wp_hci_product_map', array(
    'source_product_id' => 502,
    'source_sku' => 'hmp-502',
    'dest_product_id' => $destId3,
    'dest_sku' => 'vsp-502',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        // is_active=false از یک محصول غیرفعال/حذف/Draft‌شده در منبع — حتی
        // اگر منبع مقدار price متفاوتی هم برگرداند، نباید اصلاً لمس شود.
        array('source_product_id' => 502, 'sku' => 'hmp-502', 'price' => '999999', 'stock_quantity' => 40.0, 'is_active' => false, 'variations' => array()),
    )),
);

$summary3 = HCI_Sync::run_daily_sync(false);
$updated3 = wc_get_product($destId3);
test_evidence('محصول بعد از غیرفعال شدن در منبع', array('status' => $updated3->get_status(), 'stock_status' => $updated3->get_stock_status(), 'stock_quantity' => $updated3->get_stock_quantity(), 'regular_price' => $updated3->get_regular_price()));
test_assert($updated3->get_status() === 'publish', 'وضعیت پست محصول اصلاً تغییر نکرد — نه حذف نه Draft');
test_assert($updated3->get_stock_status() === 'outofstock', 'فقط Out of Stock شد');
test_assert((float) $updated3->get_stock_quantity() === 5.0, 'مقدار عددی موجودی دست‌نخورده ماند (فقط وضعیت انبار عوض شد)');
test_assert($updated3->get_regular_price() === '123', 'قیمت کاملاً دست‌نخورده ماند — حتی مقدار price ارسالی از منبع هم نادیده گرفته شد');
test_assert($summary3['deactivated'] === 1 && $summary3['price_updated'] === 0 && $summary3['stock_updated'] === 0, 'خلاصه: فقط deactivated++ شد، نه price_updated نه stock_updated');

// =============================================================================
// تست ۴ — محصول Variable: هر Variation جدا Sync می‌شود.
// =============================================================================
test_section('تست ۴ — سینک محصول Variable: هر Variation مستقل به‌روز می‌شود');

$wpdb4 = hci_sync_reset();

$parentProduct = new WC_Product_Variable();
$parentProduct->set_status('publish');
$parentProduct->set_name('محصول Variable');
$parentDestId = $parentProduct->save();

$var1 = new WC_Product_Variation();
$var1->set_parent_id($parentDestId);
$var1->set_sku('vsp-601');
$var1->set_regular_price('10');
$var1->set_price('10');
$var1->set_manage_stock(true);
$var1->set_stock_quantity(1);
$var1->set_stock_status('instock');
$var1DestId = $var1->save();

$var2 = new WC_Product_Variation();
$var2->set_parent_id($parentDestId);
$var2->set_manage_stock(true);
$var2->set_stock_quantity(9);
$var2->set_stock_status('instock');
$var2DestId = $var2->save();

$wpdb4->insert('wp_hci_product_map', array(
    'source_product_id' => 600,
    'source_sku' => 'hmp-600',
    'dest_product_id' => $parentDestId,
    'dest_sku' => 'vsp-600',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));
$wpdb4->insert('wp_hci_product_map', array(
    'source_product_id' => 600,
    'source_variation_id' => 601,
    'source_sku' => 'hmp-601',
    'dest_product_id' => $parentDestId,
    'dest_variation_id' => $var1DestId,
    'dest_sku' => 'vsp-601',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));
$wpdb4->insert('wp_hci_product_map', array(
    'source_product_id' => 600,
    'source_variation_id' => 602,
    'source_sku' => null,
    'dest_product_id' => $parentDestId,
    'dest_variation_id' => $var2DestId,
    'dest_sku' => null,
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array(
            'source_product_id' => 600,
            'sku' => 'hmp-600',
            'price' => null,
            'stock_quantity' => null,
            'is_active' => true,
            'variations' => array(
                array('variation_id' => 601, 'sku' => 'hmp-601', 'price' => '50000', 'stock_quantity' => 3.0, 'is_active' => true),
                array('variation_id' => 602, 'sku' => null, 'price' => '60000', 'stock_quantity' => 0.0, 'is_active' => true),
            ),
        ),
    )),
);

$summary4 = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه سینک Variable', $summary4);
$updatedVar1 = wc_get_product($var1DestId);
$updatedVar2 = wc_get_product($var2DestId);

test_assert($summary4['checked'] === 3, 'Parent + هر ۲ Variation جدا شمرده شدند (۳ بررسی)');
test_assert($updatedVar1->get_regular_price() === (string) ((50000 + 1000) * 1.10), 'Variation ۶۰۱: قیمت با همان فرمول سراسری، مستقل از بقیه محاسبه شد');
test_assert((float) $updatedVar1->get_stock_quantity() === 3.0 && $updatedVar1->get_stock_status() === 'instock', 'Variation ۶۰۱: موجودی خودش جدا به‌روز شد');
test_assert($updatedVar2->get_regular_price() === (string) ((60000 + 1000) * 1.10), 'Variation ۶۰۲: قیمت خودش کاملاً مستقل از Variation دیگر به‌روز شد');
test_assert((float) $updatedVar2->get_stock_quantity() === 0.0 && $updatedVar2->get_stock_status() === 'outofstock', 'Variation ۶۰۲: موجودی صفر → outofstock');
test_assert($summary4['price_updated'] === 2 && $summary4['stock_updated'] === 2, 'دقیقاً همان ۲ Variation به‌عنوان تغییریافته گزارش شدند (نه Parent، چون Parent فیلد price/stock مستقلی ندارد)');

// =============================================================================
// تست ۵ — شکست API منبع: هیچ تغییری در مقصد اعمال نمی‌شود، Cursor جلو
// نمی‌رود، و Retry با Backoff دقیق (۵ دقیقه → ۱۰ دقیقه → تسلیم) زمان‌بندی می‌شود.
// =============================================================================
test_section('تست ۵ — شکست API منبع: بدون تغییر مقصد، بدون پیشروی Cursor، Backoff دقیق');

$wpdb5 = hci_sync_reset();

$product5 = new WC_Product_Simple();
$product5->set_status('publish');
$product5->set_regular_price('42');
$product5->set_price('42');
$product5->set_manage_stock(true);
$product5->set_stock_quantity(1);
$product5->set_stock_status('instock');
$destId5 = $product5->save();

$wpdb5->insert('wp_hci_product_map', array(
    'source_product_id' => 503,
    'source_sku' => 'hmp-503',
    'dest_product_id' => $destId5,
    'dest_sku' => 'vsp-503',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'قبل از هر سینکی، Cursor خالی است');

// تلاش اول: شکست
$GLOBALS['__stub_http_response_queue'] = array(hci_sync_error_response(500));
$beforeFail1 = time();
$fail1 = HCI_Sync::run_daily_sync(false);
test_evidence('خلاصه شکست اول', $fail1);
test_assert($fail1['success'] === false, 'شکست صادقانه گزارش می‌شود');
test_assert($fail1['price_updated'] === 0 && $fail1['stock_updated'] === 0 && $fail1['deactivated'] === 0, 'هیچ شمارنده‌ای در حالت شکست افزایش نمی‌یابد');
test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'Cursor بعد از شکست همچنان دست‌نخورده (خالی) است — جلو نرفته');

$productAfterFail1 = wc_get_product($destId5);
test_assert($productAfterFail1->get_regular_price() === '42' && (float) $productAfterFail1->get_stock_quantity() === 1.0, 'محصول مقصد کاملاً دست‌نخورده مانده — نه قیمت نه موجودی تغییر کرد');

test_assert(count($GLOBALS['__fake_as_single_actions']) === 1, 'دقیقاً یک Retry زمان‌بندی شد');
$retry1 = $GLOBALS['__fake_as_single_actions'][0];
test_assert($retry1['hook'] === HCI_Sync::DAILY_HOOK, 'Retry روی همان Hook سینک روزانه زمان‌بندی شده');
test_assert($retry1['timestamp'] >= $beforeFail1 + 295 && $retry1['timestamp'] <= $beforeFail1 + 320, 'Retry اول ≈ ۵ دقیقه بعد زمان‌بندی شده (۰۶:۰۰→۰۶:۰۵)');

// تلاش دوم (شبیه‌سازی اجرای همان Retry): بازهم شکست
$GLOBALS['__stub_http_response_queue'] = array(hci_sync_error_response(503));
$beforeFail2 = time();
$fail2 = HCI_Sync::run_daily_sync(false);
test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'Cursor بعد از شکست دوم هم همچنان خالی است');
test_assert(count($GLOBALS['__fake_as_single_actions']) === 2, 'یک Retry دوم هم زمان‌بندی شد (مجموعاً ۲)');
$retry2 = $GLOBALS['__fake_as_single_actions'][1];
test_assert($retry2['timestamp'] >= $beforeFail2 + 595 && $retry2['timestamp'] <= $beforeFail2 + 620, 'Retry دوم ≈ ۱۰ دقیقه بعد زمان‌بندی شده (۰۶:۰۵→۰۶:۱۵)');

// تلاش سوم (متوالی): باید تسلیم شود و دیگر Retry جدیدی زمان‌بندی نکند
$GLOBALS['__stub_http_response_queue'] = array(hci_sync_error_response(500));
$fail3 = HCI_Sync::run_daily_sync(false);
test_assert(count($GLOBALS['__fake_as_single_actions']) === 2, 'بعد از ۲ شکست متوالی، دیگر Retry سوم زمان‌بندی نمی‌شود — تسلیم تا اجرای بعدیِ برنامه‌ریزی‌شده');

// حالا موفقیت: هم مقصد به‌روز می‌شود هم Cursor جلو می‌رود هم شمارنده Retry صفر می‌شود
$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array('source_product_id' => 503, 'sku' => 'hmp-503', 'price' => '5000', 'stock_quantity' => 9.0, 'is_active' => true, 'variations' => array()),
    )),
);
$success5 = HCI_Sync::run_daily_sync(false);
test_assert($success5['success'] === true, 'تلاش بعدی با منبع سالم موفق تمام می‌شود');
test_assert((string) get_option('hci_sync_cursor_gmt', '') !== '', 'Cursor فقط حالا، بعد از اولین موفقیت کامل، جلو رفته');
test_assert((int) get_option('hci_sync_retry_count', -1) === 0, 'بعد از موفقیت، شمارنده Retry صفر می‌شود');

// =============================================================================
// تست ۶ — Cursor فقط بعد از موفقیت کامل جلو می‌رود (نه زودتر، نه هرگز عقب).
// =============================================================================
test_section('تست ۶ — Cursor دقیقاً از لحظه‌ی «قبل از شروع درخواست» جلو می‌رود');

$wpdb6 = hci_sync_reset();
$product6 = new WC_Product_Simple();
$product6->set_status('publish');
$destId6 = $product6->save();
$wpdb6->insert('wp_hci_product_map', array(
    'source_product_id' => 504,
    'source_sku' => 'hmp-504',
    'dest_product_id' => $destId6,
    'dest_sku' => 'vsp-504',
    'import_status' => HCI_DB::STATUS_IMPORTED,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

test_assert((string) get_option('hci_sync_cursor_gmt', '') === '', 'قبل از اولین سینک، Cursor خالی است');
$beforeRequest = current_time('mysql', true);
$GLOBALS['__stub_http_response_queue'] = array(
    hci_sync_delta_response(array(
        array('source_product_id' => 504, 'sku' => 'hmp-504', 'price' => '1000', 'stock_quantity' => 1.0, 'is_active' => true, 'variations' => array()),
    )),
);
$successCursor = HCI_Sync::run_daily_sync(false);
$cursorAfter = (string) get_option('hci_sync_cursor_gmt', '');
test_evidence('Cursor بعد از موفقیت', array('قبل_از_درخواست' => $beforeRequest, 'cursor_ذخیره‌شده' => $cursorAfter));
test_assert($cursorAfter !== '' && $cursorAfter >= $beforeRequest, 'Cursor برابر (یا بلافاصله بعد از) لحظه‌ی شروع درخواست ذخیره شده، نه قبل و نه با تاخیر زیاد بعد از پایان');
test_assert($cursorAfter === $successCursor['started_at'], 'Cursor دقیقاً همان started_at ثبت‌شده در خلاصه سینک است');

echo "\n=== جمع‌بندی سینک روزانه ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
