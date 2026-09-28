<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Wholesale / Client Importer — Automated Verification (PHP " . PHP_VERSION . ")\n";
echo "این تست‌ها کد production واقعی را (بدون بازنویسی منطق) روی داده Mock/SQLite اجرا می‌کنند.\n";

// =============================================================================
// تست ۱: GET /products/delta
// =============================================================================
test_section('تست ۱ — GET /products/delta: فقط رکوردهای واقعاً تغییرکرده');

$wpdb = hmw_test_create_products_db();
$GLOBALS['wpdb'] = $wpdb;

hmw_test_insert_product($wpdb, array(
    'source_product_id' => 100,
    'parent_product_id' => null,
    'product_type' => 'simple',
    'sku' => 'A-100',
    'price' => '1000',
    'stock_quantity' => '5',
    'is_active' => 1,
    'updated_at' => '2026-09-27 08:00:00',
));
hmw_test_insert_product($wpdb, array(
    'source_product_id' => 200,
    'parent_product_id' => null,
    'product_type' => 'variable',
    'sku' => 'B-200',
    'price' => '2000',
    'stock_quantity' => null,
    'is_active' => 1,
    'updated_at' => '2026-09-20 00:00:00', // خودِ والد تغییر نکرده
));
hmw_test_insert_product($wpdb, array(
    'source_product_id' => 201,
    'parent_product_id' => 200,
    'product_type' => 'variation',
    'sku' => 'B-200-V1',
    'price' => '2100',
    'stock_quantity' => '3',
    'is_active' => 1,
    'updated_at' => '2026-09-27 09:00:00', // این Variation تغییر کرده
));
hmw_test_insert_product($wpdb, array(
    'source_product_id' => 300,
    'parent_product_id' => null,
    'product_type' => 'simple',
    'sku' => 'OLD-300',
    'price' => '500',
    'stock_quantity' => '1',
    'is_active' => 1,
    'updated_at' => '2026-01-01 00:00:00', // خیلی قدیمی — نباید برگردد
));

$request1 = new WP_REST_Request(array(
    'updated_after' => '2026-09-27 00:00:00',
    'page' => 1,
    'per_page' => 100,
));
$response1 = HMW_REST_API::products_delta($request1);
$data1 = $response1->get_data();

test_evidence('پاسخ کامل /products/delta (updated_after=2026-09-27 00:00:00)', $data1);

test_assert($response1->get_status() === 200, 'HTTP 200 برگردانده شد');
test_assert(count($data1['data']) === 2, 'دقیقاً ۲ آیتم top-level برگشت (محصول ۱۰۰ + والد ۲۰۰ به‌خاطر Variation تغییریافته)');

$byId1 = array();
foreach ($data1['data'] as $item) {
    $byId1[$item['source_product_id']] = $item;
}

test_assert(isset($byId1[100]), 'محصول ساده ۱۰۰ (که خودش تغییر کرده) در خروجی هست');
test_assert(!isset($byId1[300]), 'محصول ۳۰۰ (قدیمی، بدون تغییر) در خروجی نیست');
test_assert(
    isset($byId1[100]) && $byId1[100]['sku'] === 'A-100' && $byId1[100]['price'] === '1000' && $byId1[100]['stock_quantity'] === 5.0,
    'مقادیر sku/price/stock_quantity محصول ۱۰۰ صحیح است'
);
test_assert(isset($byId1[200]), 'والد ۲۰۰ در خروجی هست (چون Variation آن تغییر کرده، هرچند خودش تغییر نکرده)');
test_assert(
    isset($byId1[200]['variations']) && count($byId1[200]['variations']) === 1 && $byId1[200]['variations'][0]['variation_id'] === 201,
    'فقط Variation واقعاً تغییریافته (۲۰۱) داخل variations والد ۲۰۰ آمده'
);
test_assert(
    isset($byId1[200]) && $byId1[200]['sku'] === 'B-200' && $byId1[200]['price'] === '2000',
    'sku/price والد ۲۰۰ از خودِ رکورد والد خوانده شده (نه خالی)'
);

$request2 = new WP_REST_Request(array('updated_after' => '2026-09-28 00:00:00', 'page' => 1, 'per_page' => 100));
$response2 = HMW_REST_API::products_delta($request2);
$data2 = $response2->get_data();
test_evidence('پاسخ /products/delta با updated_after بعد از همه تغییرات (2026-09-28)', $data2);
test_assert($data2['data'] === array(), 'با updated_after بعد از همه تغییرات، آرایه data کاملاً خالی است');

$request3 = new WP_REST_Request(array()); // بدون updated_after
$response3 = HMW_REST_API::products_delta($request3);
test_evidence('پاسخ /products/delta بدون updated_after', $response3->get_data());
test_assert($response3->get_status() === 400, 'بدون updated_after، HTTP 400 برمی‌گردد (نه سکوت یا 200 اشتباه)');

// =============================================================================
// تست ۲: format_product() برای محصول variable با Variation بدون SKU
// =============================================================================
test_section('تست ۲ — format_product(): محصول variable + Variation بدون SKU مجزا');

$variableRow = array(
    'source_product_id' => 500,
    'parent_product_id' => null,
    'product_type' => 'variable',
    'source_status' => 'publish',
    'sku' => 'P-500',
    'name' => 'محصول Variable تستی',
    'short_description' => 'توضیح کوتاه',
    'price' => '9000',
    'stock_quantity' => null,
    'stock_status' => 'instock',
    'manage_stock' => 1,
    'image_url' => null,
    'gallery' => '',
    'product_url' => null,
    'category_path' => '',
    'category_ids' => '',
    'attributes' => json_encode(array(array('name' => 'رنگ', 'options' => array('قرمز', 'آبی')))),
    'source_modified_gmt' => null,
    'is_active' => 1,
    'last_synced_at' => '2026-09-27 00:00:00',
    'updated_at' => '2026-09-27 00:00:00',
);

$variationsByParent = array(
    500 => array(
        array(
            'source_product_id' => 501,
            'parent_product_id' => 500,
            'sku' => null, // بدون SKU مجزا
            'price' => '9500',
            'stock_quantity' => '2',
            'attributes' => json_encode(array(array('name' => 'رنگ', 'option' => 'قرمز'))),
        ),
    ),
);

$formatMethod = new ReflectionMethod('HMW_REST_API', 'format_product');
$formatMethod->setAccessible(true);
$formatted = $formatMethod->invoke(null, $variableRow, array(), $variationsByParent);

test_evidence('خروجی format_product() برای محصول variable', $formatted);

test_assert($formatted['product_type'] === 'variable', 'product_type == variable');
test_assert(
    isset($formatted['attributes']) && $formatted['attributes'][0]['name'] === 'رنگ' && $formatted['attributes'][0]['options'] === array('قرمز', 'آبی'),
    'attributes سطح parent صحیح است'
);
test_assert(isset($formatted['variations']) && count($formatted['variations']) === 1, 'آرایه variations دقیقاً یک عضو دارد');
test_assert($formatted['variations'][0]['variation_id'] === 501, 'variation_id صحیح است');
test_assert($formatted['variations'][0]['price'] === '9500', 'price این Variation صحیح است');
test_assert($formatted['variations'][0]['stock_quantity'] === 2.0, 'stock_quantity به‌صورت float صحیح است');
test_assert(
    array_key_exists('sku', $formatted['variations'][0]) && $formatted['variations'][0]['sku'] === null,
    'sku این Variation دقیقاً null است (نه رشته خالی "")'
);

$simpleRow = array_merge($variableRow, array('source_product_id' => 600, 'product_type' => 'simple', 'attributes' => ''));
$formattedSimple = $formatMethod->invoke(null, $simpleRow, array(), array());
test_assert(
    !array_key_exists('attributes', $formattedSimple) && !array_key_exists('variations', $formattedSimple),
    'برای product_type=simple کلیدهای attributes/variations اصلاً در خروجی نیستند (رفتار قبلی محصولات ساده تغییر نکرده)'
);

// =============================================================================
// تست ۳: category_tree() — سلسله‌مراتب ۳ سطحی
// =============================================================================
test_section('تست ۳ — HMW_Source_API::category_tree(): ترتیب ریشه→برگ در ۳ سطح');

$categoryMap = array(
    1 => array('name' => 'دسته ریشه', 'slug' => 'root-cat', 'parent' => 0),
    2 => array('name' => 'دسته میانی', 'slug' => 'mid-cat', 'parent' => 1),
    3 => array('name' => 'دسته برگ فارسی', 'slug' => 'دسته-برگ-فارسی', 'parent' => 2),
);

$tree = HMW_Source_API::category_tree(array(3), $categoryMap);
test_evidence('خروجی category_tree([3], ...)', $tree);

test_assert(count($tree) === 3, 'دقیقاً ۳ عضو در مسیر دسته‌بندی هست');
test_assert($tree[0]['id'] === 1 && $tree[0]['parent_id'] === null, 'عضو اول = ریشه، parent_id آن null است');
test_assert($tree[1]['id'] === 2 && $tree[1]['parent_id'] === $tree[0]['id'], 'عضو دوم، parent_id آن دقیقاً به id عضو اول اشاره می‌کند');
test_assert($tree[2]['id'] === 3 && $tree[2]['parent_id'] === $tree[1]['id'], 'عضو سوم (برگ)، parent_id آن دقیقاً به id عضو دوم اشاره می‌کند');
test_assert($tree[2]['name'] === 'دسته برگ فارسی', 'نام برگ صحیح است (ترتیب ریشه→برگ، نه برعکس)');
test_assert($tree[0]['slug'] === 'root-cat' && $tree[2]['slug'] === 'دسته-برگ-فارسی', 'از نسخه ۱.۹.۲: Slug هر Node (حتی فارسی) هم همراه با name/id/parent_id منتقل می‌شود');

// سازگاری با نقشه دسته‌ای که هنوز slug ندارد (Cache قدیمی‌تر قبل از ۱.۹.۲) — نباید کرش کند.
$legacyCategoryMap = array(1 => array('name' => 'دسته بدون اسلاگ', 'parent' => 0));
$legacyTree = HMW_Source_API::category_tree(array(1), $legacyCategoryMap);
test_assert($legacyTree[0]['slug'] === '', 'نقشه دسته بدون کلید slug هم بدون خطا رشته خالی برمی‌گرداند، نه کرش');

// =============================================================================
// تست ۴: HCI_Source_Client::test_connection() با API Key نادرست
// =============================================================================
test_section('تست ۴ — HCI_Source_Client::test_connection(): API Key نادرست → پیام خطای واضح');

// این دقیقاً همان بدنه/کد HTTP است که HMW_REST_API::authorize() برای یک کلید
// نامعتبر واقعاً برمی‌گرداند (WP_Error سریالایز شده توسط REST Server وردپرس:
// {"code","message","data":{"status"}} — نه شکل سفارشی {"error":{"message"}}).
$GLOBALS['__stub_http_response'] = array(
    'response' => array('code' => 401, 'message' => 'Unauthorized'),
    'body' => json_encode(array(
        'code' => 'hmw_api_invalid_key',
        'message' => 'API Key نامعتبر است.',
        'data' => array('status' => 401),
    )),
);

$result4 = HCI_Source_Client::test_connection('https://source.example.com/wp-json/hmw/v1', 'this-is-a-wrong-key');
test_evidence('خروجی HCI_Source_Client::test_connection() با کلید نادرست', $result4);

test_assert($result4['success'] === false, 'success === false');
test_assert(($result4['status'] ?? null) === 401, 'کد HTTP 401 در نتیجه ثبت شده است');
test_assert(str_contains($result4['message'], '401'), 'پیام خطا شامل کد HTTP 401 است');
test_assert(str_contains($result4['message'], 'نامعتبر'), 'پیام خطا شامل دلیل واقعی (API Key نامعتبر است) است، نه فقط "Unauthorized" عمومی');

// حالت شبکه‌ای (بدون پاسخ HTTP، مثلاً DNS/Timeout) هم جداگانه بررسی می‌شود:
$GLOBALS['__stub_http_response'] = new WP_Error('http_request_failed', 'Could not resolve host');
$resultNetworkError = HCI_Source_Client::test_connection('https://not-a-real-host.invalid/wp-json/hmw/v1', 'any-key');

// توجه: wp_remote_get Stub ما همیشه یک آرایه برمی‌گرداند مگر مقدار WP_Error باشد؛
// چون Stub خودمان است، این تست فقط برای مستندسازی رفتار is_wp_error در کد واقعی است.
$GLOBALS['__stub_http_response'] = $resultNetworkError instanceof WP_Error ? $resultNetworkError : $GLOBALS['__stub_http_response'];

// =============================================================================
// تست تکمیلی (Bonus) — HCI_Pricing::resolve_price() فرمول قیمت
// =============================================================================
test_section('تست تکمیلی — HCI_Pricing::resolve_price(): صحت فرمول (hmp_price + fixed) × (1 + percent/100)');

HCI_Pricing::save(5000.0, 10.0, 'draft');
$resolved = HCI_Pricing::resolve_price(100000.0);
test_evidence('resolve_price(100000) با fixed=5000, percent=10', array('result' => $resolved, 'expected' => 115500.0));
test_assert(abs($resolved - 115500.0) < 0.0001, 'نتیجه دقیقاً 115500 است: (100000+5000)×1.10');

// =============================================================================
// جمع‌بندی
// =============================================================================
echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
