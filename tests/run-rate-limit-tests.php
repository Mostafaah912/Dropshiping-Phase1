<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — Rate Limit Resilience (PHP " . PHP_VERSION . ")\n";
echo "شبیه‌سازی HTTP 429 واقعی که class-hmw-rest-api.php::rate_allowed() برمی‌گرداند\n";
echo "(RATE_LIMIT=120 در RATE_WINDOW=60 ثانیه)، بدون sleep واقعی طولانی در تست.\n";

// در تست هرگز واقعاً sleep/usleep نمی‌کنیم.
HCI_Source_Client::configure_delays_for_tests(0, 0);

function hci_rl_products_response(array $items, int $page_count, int $total): array {
    return array(
        'response' => array('code' => 200, 'message' => 'OK'),
        'body' => json_encode(array(
            'success' => true,
            'data' => $items,
            'pagination' => array('total_pages' => $page_count, 'total' => $total),
        )),
    );
}

function hci_rl_429_response(): array {
    return array(
        'response' => array('code' => 429, 'message' => 'Too Many Requests'),
        'body' => json_encode(array(
            'code' => 'hmw_api_rate_limited',
            'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.',
            'data' => array('status' => 429),
        )),
    );
}

// =============================================================================
// سناریو ۱ — یک ۴۲۹ گذرا: Retry تک‌باره کافی است و کل عملیات با موفقیت
// (بدون از دست دادن هیچ صفحه‌ای) تمام می‌شود.
// =============================================================================
test_section('سناریو ۱ — ۴۲۹ گذرا در صفحه ۲: یک Retry کافی است، همه ۳ صفحه نهایتاً می‌آیند');

update_option('hci_api_url', 'https://rl-site.invalid/wp-json/hmw/v1');
update_option('hci_api_key', 'test-key');
delete_transient('hci_products_cache');
delete_transient('hci_products_partial_state');
$GLOBALS['__test_last_requested_urls'] = array();

$GLOBALS['__stub_http_response_queue'] = array(
    hci_rl_products_response(array(array('source_product_id' => 1)), 3, 3),
    hci_rl_429_response(),                                                    // صفحه ۲: اولین تلاش ۴۲۹
    hci_rl_products_response(array(array('source_product_id' => 2)), 3, 3),   // صفحه ۲: بعد از Retry موفق
    hci_rl_products_response(array(array('source_product_id' => 3)), 3, 3),   // صفحه ۳
);

$result1 = HCI_Source_Client::get_all_products(true);
test_evidence('نتیجه سناریو ۱', array('success' => $result1['success'], 'count' => count($result1['items']), 'requests' => count($GLOBALS['__test_last_requested_urls'])));

test_assert($result1['success'] === true, 'کل عملیات موفق تمام شد (با وجود یک ۴۲۹ گذرا)');
test_assert(count($result1['items']) === 3, 'هر ۳ محصول (از هر ۳ صفحه) نهایتاً جمع شدند — هیچ‌کدام گم نشد');
test_assert(count($GLOBALS['__test_last_requested_urls']) === 4, 'دقیقاً ۴ درخواست HTTP رفت (۱ برای صفحه۱ + ۲ برای صفحه۲ به‌خاطر Retry + ۱ برای صفحه۳)');
test_assert(get_transient('hci_products_partial_state') === false, 'بعد از موفقیت کامل، هیچ حالت جزئی/ناقصی در Transient باقی نمانده');
test_assert(is_array(get_transient('hci_products_cache')), 'کش نهایی محصولات با موفقیت ذخیره شده');

// =============================================================================
// سناریو ۲ — ۴۲۹ پایدار (حتی بعد از Retry): باید تمیز متوقف شود، پیشرفت
// جزئی را نگه دارد، و تلاش بعدی باید دقیقاً از همان صفحه ادامه یابد (نه از صفر).
// =============================================================================
test_section('سناریو ۲ — ۴۲۹ پایدار: توقف تمیز با پیام واضح + پیشرفت جزئی حفظ می‌شود');

delete_transient('hci_products_cache');
delete_transient('hci_products_partial_state');
$GLOBALS['__test_last_requested_urls'] = array();

$GLOBALS['__stub_http_response_queue'] = array(
    hci_rl_products_response(array(array('source_product_id' => 10), array('source_product_id' => 11)), 3, 6),
    hci_rl_429_response(), // صفحه ۲: تلاش اول
    hci_rl_429_response(), // صفحه ۲: بعد از Retry هم بازهم ۴۲۹
);

$result2 = HCI_Source_Client::get_all_products(true);
test_evidence('نتیجه سناریو ۲ (باید partial=true باشد)', $result2);

test_assert($result2['success'] === false, 'success=false گزارش می‌شود (نه یک شکست بی‌صدا که انگار موفق بوده)');
test_assert(($result2['partial'] ?? false) === true, 'partial=true — یعنی صراحتاً می‌گوید این نتیجه کامل نیست');
test_assert(count($result2['items']) === 2, 'دو محصولی که تا لحظه توقف موفق واکشی شده بودند (صفحه ۱) در خروجی حفظ شده‌اند، نه خالی');
test_assert(
    str_contains($result2['message'], '۲') || str_contains($result2['message'], '2'),
    'پیام خطا تعداد واقعی دریافت‌شده را ذکر می‌کند (نه فقط «خطا»ی مبهم)'
);
test_assert(str_contains($result2['message'], 'Rate Limit') || str_contains($result2['message'], 'نرخ'), 'پیام خطا صریحاً به محدودیت نرخ اشاره می‌کند');

$partialState = get_transient('hci_products_partial_state');
test_evidence('حالت جزئی ذخیره‌شده در Transient', $partialState);
test_assert(is_array($partialState) && $partialState['next_page'] === 2, 'حالت جزئی می‌گوید ادامه باید از صفحه ۲ شروع شود (همان‌جا که متوقف شد)');
test_assert(is_array($partialState) && count($partialState['items']) === 2, 'حالت جزئی خودِ ۲ محصول صفحه ۱ را هم نگه داشته، نه فقط شماره صفحه');

// حالا کاربر دوباره دکمه را می‌زند: باید از صفحه ۲ ادامه یابد، نه از صفحه ۱
$GLOBALS['__test_last_requested_urls'] = array();
$GLOBALS['__stub_http_response_queue'] = array(
    hci_rl_products_response(array(array('source_product_id' => 12)), 3, 6), // صفحه ۲ این‌بار موفق
    hci_rl_products_response(array(array('source_product_id' => 13)), 3, 6), // صفحه ۳
);

$result3 = HCI_Source_Client::get_all_products(false); // دکمه دوباره را بدون force هم می‌زنیم؛ باید resume شود
test_evidence('نتیجه تلاش دوم (باید resume شود، نه از صفر)', array('success' => $result3['success'], 'items' => array_column($result3['items'], 'source_product_id'), 'requests' => count($GLOBALS['__test_last_requested_urls'])));

test_assert($result3['success'] === true, 'تلاش دوم این‌بار کامل موفق می‌شود');
test_assert(count($GLOBALS['__test_last_requested_urls']) === 2, 'فقط ۲ درخواست جدید رفت (صفحه ۲ و ۳) — صفحه ۱ دوباره درخواست نشد، یعنی واقعاً Resume شد نه شروع از صفر');
test_assert(
    array_column($result3['items'], 'source_product_id') === array(10, 11, 12, 13),
    'محصولات صفحه ۱ (که از تلاش قبلی ذخیره شده بودند) با محصولات جدید صفحه ۲/۳ به‌درستی ترکیب شدند: 10,11,12,13'
);
test_assert(get_transient('hci_products_partial_state') === false, 'بعد از تکمیل موفق، حالت جزئی پاک شده');

echo "\n=== جمع‌بندی مقاومت در برابر Rate Limit ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
