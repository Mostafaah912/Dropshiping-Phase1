<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — اتصال خودکار مبتنی بر دامنه، قطع‌شدن توسط هی‌مد، بدون فاش‌شدن آدرس/کلید (S3-01، S3-03، S3-04، S3-05، S3-09، S3-11) (PHP " . PHP_VERSION . ")\n";

HCI_Source_Client::configure_delays_for_tests(0, 0);

const CT_URL = 'https://origin-secret-host.invalid/wp-json/hmw/v1';

function ct_reset(bool $legacy = true): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__test_post_calls'] = array();
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__stub_http_response_queue'] = array();
    $GLOBALS['__stub_post_responder'] = null;
    $GLOBALS['__stub_auto_health'] = false;
    $GLOBALS['__fake_as_single_actions'] = array();
    $GLOBALS['__test_home_url'] = 'https://client-shop.example';
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    if ($legacy) { // مثل سایت تست فعلی: آدرس و کلید دستی ذخیره‌شده
        update_option('hci_api_url', CT_URL, false);
        update_option('hci_api_key', 'LEGACY-KEY-123', false);
    }
    return $w;
}

function ct_json(int $code, array $body): array {
    return array('response' => array('code' => $code, 'message' => 'x'), 'body' => json_encode($body));
}

function ct_activation_ok(string $key = 'NEW-KEY-456'): callable {
    return static fn (string $url, array $args) => ct_json(200, array('success' => true, 'data' => array('api_key' => $key)));
}

function ct_disabled_resp(): array {
    return ct_json(403, array('code' => 'hmw_site_disabled', 'message' => 'x', 'data' => array('status' => 403)));
}

function ct_invalid_resp(): array {
    return ct_json(401, array('code' => 'hmw_api_invalid_key', 'message' => 'x', 'data' => array('status' => 401)));
}

function ct_health_ok(): array {
    return ct_json(200, array('success' => true, 'data' => array('api' => 'ok', 'full_sync_status' => 'idle')));
}

function ct_page(string $tab = 'connection'): string {
    $_GET = array('page' => 'heymode-client-importer', 'tab' => $tab);
    ob_start();
    HCI_Admin::render_page();
    return (string) ob_get_clean();
}

// ----------------------------------------------------------------------------
test_section('فعال‌سازی خودکار: کلید اختصاصی می‌گیرد، اطلاعات دستی قدیمی بعد از موفقیت پاک می‌شود');

ct_reset();
test_assert(HCI_Connection::key() === 'LEGACY-KEY-123' && HCI_Connection::url() === CT_URL, 'تا فعال‌سازی موفق، مقدار قبلی (سایت تست) همچنان کار می‌کند');
$GLOBALS['__stub_post_responder'] = static fn (string $url, array $args) => new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host: origin-secret-host.invalid');
$r = HCI_Connection::activate();
test_assert($r['success'] === false && HCI_Connection::key() === 'LEGACY-KEY-123' && get_option('hci_api_key') === 'LEGACY-KEY-123', 'فعال‌سازی ناموفق، اتصال قدیمی را خراب نمی‌کند');

$GLOBALS['__stub_post_responder'] = ct_activation_ok();
$r = HCI_Connection::activate();
$call = end($GLOBALS['__test_post_calls']);
$sent = json_decode((string) $call['args']['body'], true);
test_assert($r['success'] === true, 'فعال‌سازی موفق');
test_assert($call['url'] === CT_URL . '/activate', 'درخواست به مسیر فعال‌سازی مبدا رفت');
test_assert($sent['domain'] === 'client-shop.example' && preg_match('/^[a-f0-9]{64}$/', (string) $sent['secret']) === 1 && !isset($sent['api_key']), 'دامنه‌ی خودِ سایت و راز تصادفی نصب ارسال شد');
test_assert(get_option('hci_install_secret') === $sent['secret'], 'همان راز نصب در سایت مشتری نگه داشته شد (برای اثبات مالکیت)');
test_assert(HCI_Connection::key() === 'NEW-KEY-456' && get_option('hci_api_key', null) === null && get_option('hci_api_url', null) === null, 'کلید اختصاصی ذخیره و گزینه‌های دستی قدیمی پاک شد');
test_assert(HCI_Connection::url() === CT_URL, 'آدرس مبدا (چون هنوز ثابت داخل پلاگین تعریف نشده) در گزینه‌ی خصوصی حفظ شد تا اتصال قطع نشود');
foreach (array('hci_conn_key', 'hci_install_secret', 'hci_conn_state', 'hci_conn_source_url') as $o) {
    test_assert(array_key_exists($o, $GLOBALS['__test_option_autoload']) && $GLOBALS['__test_option_autoload'][$o] === false, 'گزینه‌ی ' . $o . ' غیرautoload است');
}
test_assert(HCI_Connection::status() === 'connected', 'وضعیت: وصل');

// ----------------------------------------------------------------------------
test_section('مسیر عمومی HMAC برای اثبات مالکیت');

ct_reset();
$secret = HCI_Connection::install_secret();
$challenge = bin2hex(random_bytes(32));
$req = new WP_REST_Request(array('challenge' => $challenge));
$res = HCI_Connection::ownership_proof($req);
test_assert($res instanceof WP_Error && $res->get_error_data()['status'] === 404, 'خارج از فرایند فعال‌سازی، هیچ پاسخی نمی‌دهد');
set_transient('hci_activation_pending', 1, 120);
$res = HCI_Connection::ownership_proof($req);
test_assert($res instanceof WP_REST_Response && $res->get_data() === array('proof' => hash_hmac('sha256', $challenge, $secret)), 'در فرایند فعال‌سازی، HMAC(راز، challenge) برمی‌گرداند');
test_assert(strpos(json_encode($res->get_data()), $secret) === false, 'خود راز هرگز برگردانده نمی‌شود');
test_assert(HCI_Connection::ownership_proof(new WP_REST_Request(array('challenge' => 'zz')))->get_error_data()['status'] === 400, 'challenge نامعتبر رد می‌شود');
$ok = 0;
for ($i = 0; $i < 20; $i++) { $x = HCI_Connection::ownership_proof($req); if ($x instanceof WP_REST_Response) { $ok++; } }
test_assert($ok < 20, 'تعداد پاسخ‌ها در دقیقه محدود است');
$routes = array_filter($GLOBALS['__test_registered_routes'] ?? array(), static fn (array $r): bool => $r['route'] === '/ownership');
HCI_Connection::register_routes();
$routes = array_values(array_filter($GLOBALS['__test_registered_routes'], static fn (array $r): bool => $r['namespace'] === 'hci/v1' && $r['route'] === '/ownership'));
test_assert(count($routes) === 1, 'مسیر hci/v1/ownership ثبت می‌شود');

// فعال‌سازی، پنجره‌ی پاسخ‌گویی را باز می‌کند
ct_reset();
$seenPending = null;
$GLOBALS['__stub_post_responder'] = static function (string $url, array $args) use (&$seenPending) {
    $seenPending = get_transient('hci_activation_pending') !== false;
    return ct_json(403, array('success' => false, 'error' => array('code' => 'hmw_activation_failed')));
};
HCI_Connection::activate();
test_assert($seenPending === true, 'هنگام درخواست فعال‌سازی، مسیر عمومی آماده‌ی پاسخ است');
test_assert(get_transient('hci_activation_pending') === false, 'بعد از پایان، پنجره بسته می‌شود');

// ----------------------------------------------------------------------------
test_section('قطع‌شدن توسط هی‌مد (hmw_site_disabled): سینک/Import/فهرست بدون هیچ تغییری متوقف می‌شود');

$w = ct_reset(false);
update_option('hci_conn_key', 'NEW-KEY-456', false);
update_option('hci_conn_source_url', CT_URL, false);
$product = new WC_Product_Simple();
$product->set_status('publish');
$product->set_regular_price('777');
$product->set_price('777');
$product->set_manage_stock(true);
$product->set_stock_quantity(5);
$product->set_stock_status('instock');
$pid = $product->save();
$w->insert('wp_hci_product_map', array('source_product_id' => 9, 'source_sku' => 'hmp-9', 'dest_product_id' => $pid, 'dest_sku' => 'vsp-9', 'import_status' => 'imported', 'created_at' => 'x', 'updated_at' => 'x'));
$GLOBALS['__stub_http_response_queue'] = array(ct_disabled_resp());
$s = HCI_Sync::run_daily_sync(true);
test_assert(HCI_Connection::status() === 'disabled' && HCI_Connection::is_blocked(), 'وضعیت اتصال: قطع‌شده توسط هی‌مد');
test_assert($s['success'] === false && !empty($s['blocked']) && strpos((string) $s['message'], 'هی‌مد') !== false, 'سینک با پیام ساده متوقف شد');
$p = wc_get_product($pid);
test_assert($p->get_regular_price() === '777' && (float) $p->get_stock_quantity() === 5.0 && $p->get_stock_status() === 'instock' && $p->get_status() !== 'draft', 'هیچ قیمت/موجودی/وضعیتی تغییر نکرد (D1)');
test_assert(count($GLOBALS['__fake_as_single_actions']) === 0, 'Retry هم زمان‌بندی نشد');

// وقتی قطع است، نه سینک خودکار نه Import نه فهرست به مبدا درخواست نمی‌زنند
$GLOBALS['__test_last_requested_urls'] = array();
$GLOBALS['__stub_http_response_queue'] = array(ct_disabled_resp());
$sched = HCI_Sync::run_scheduled_slot();
// سینک روزانه فقط یک بررسی سبک (health) می‌زند
test_assert(count($GLOBALS['__test_last_requested_urls']) === 1 && strpos($GLOBALS['__test_last_requested_urls'][0], '/health') !== false, 'سینک روزانه در حالت قطع فقط یک بررسی سبک (health) می‌کند');
$GLOBALS['__test_last_requested_urls'] = array();
$list = HCI_Source_Client::get_all_products(true);
test_assert($list['success'] === false && !empty($list['blocked']) && count($GLOBALS['__test_last_requested_urls']) === 0, 'دریافت فهرست محصولات بدون درخواست به مبدا متوقف است');
$one = HCI_Source_Client::get_product(5);
$batch = HCI_Source_Client::get_products_batch(array(5));
test_assert($one['success'] === false && $batch['success'] === false && count($GLOBALS['__test_last_requested_urls']) === 0, 'دریافت تازه محصول/تطبیق کامل هم متوقف است');
HCI_DB::queue_import(5, 'hmp-5', (string) wp_json_encode(array('name' => 'x', 'product_type' => 'simple', 'sku' => 'hmp-5', 'price' => '1000', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'variations' => array(), 'category_path' => array(), 'images' => array(), 'featured_image' => null, 'short_description' => '')));
HCI_Import::process_import_action(5);
$row = HCI_DB::get_map_row(5);
test_assert($row['import_status'] === 'error' && strpos((string) $row['error_message'], 'هی‌مد') !== false && count(array_filter($GLOBALS['__fake_wc_products'], static fn ($x) => $x['type'] !== 'simple' || $x === null)) === 0, 'Import با پیام ساده خطا می‌دهد و محصولی نمی‌سازد');
test_assert(count($GLOBALS['__fake_wc_products']) === 1, 'هیچ محصول تازه‌ای ساخته نشد (فقط محصول قبلی هست)');

// فعال شدن دوباره: بررسی روزانه کافی است
$GLOBALS['__stub_http_response_queue'] = array(ct_health_ok(), ct_health_ok(), ct_json(200, array('success' => true, 'data' => array(), 'pagination' => array('total_pages' => 1, 'total' => 0))));
$re = HCI_Sync::run_scheduled_slot();
test_assert(HCI_Connection::status() === 'connected' && $re['success'] === true, 'وقتی هی‌مد دوباره فعال کند، بررسی روزانه اتصال را برمی‌گرداند و سینک ادامه می‌یابد');

// ----------------------------------------------------------------------------
test_section('کلید نامعتبر (مثلاً بعد از صدور دوباره): یک بار فعال‌سازی خودکار دوباره');

ct_reset(false);
update_option('hci_conn_key', 'OLD-KEY', false);
update_option('hci_conn_source_url', CT_URL, false);
$GLOBALS['__stub_post_responder'] = ct_activation_ok('REISSUED-KEY');
$GLOBALS['__stub_http_response_queue'] = array(ct_invalid_resp(), ct_json(200, array('success' => true, 'data' => array('p' => 1), 'pagination' => array('total_pages' => 1))));
$d = HCI_Source_Client::get_delta_products('2026-01-01 00:00:00');
test_assert($d['success'] === true && HCI_Connection::key() === 'REISSUED-KEY' && count($GLOBALS['__test_post_calls']) === 1, 'کلید نامعتبر ⇒ فعال‌سازی خودکار ⇒ همان درخواست با کلید تازه موفق شد');
$GLOBALS['__stub_http_response_queue'] = array(ct_invalid_resp());
$d2 = HCI_Source_Client::get_delta_products('2026-01-01 00:00:00');
test_assert($d2['success'] === false && count($GLOBALS['__test_post_calls']) === 1, 'بار دوم بلافاصله، دوباره فعال‌سازی نمی‌شود (فقط یک بار)');

// ----------------------------------------------------------------------------
test_section('صفحه اتصال: فقط وضعیت ساده و «بررسی دوباره»؛ هیچ آدرس/کلید/فیلد فنی');

ct_reset();
$GLOBALS['__stub_post_responder'] = ct_activation_ok('PAGE-KEY-789');
$html = ct_page();
test_assert(strpos($html, 'اتصال شما با هی‌مد برقرار است') !== false, 'بعد از فعال‌سازی خودکار: «اتصال شما با هی‌مد برقرار است»');
foreach (array('origin-secret-host', 'LEGACY-KEY', 'NEW-KEY', 'PAGE-KEY', 'wp-json', 'hmw/v1', 'api_url', 'api_key', 'API Key', 'API URL', 'type="password"', 'type="url"') as $needle) {
    test_assert(stripos($html, $needle) === false, 'صفحه اتصال شامل «' . $needle . '» نیست');
}
test_assert(strpos($html, 'بررسی دوباره') !== false && strpos($html, 'hci_recheck_connection') !== false, 'دکمه «بررسی دوباره» (با Nonce) هست');

ct_reset(false);
$GLOBALS['__stub_post_responder'] = static fn (string $u, array $a) => ct_json(403, array('success' => false, 'error' => array('code' => 'hmw_activation_failed')));
update_option('hci_conn_source_url', CT_URL, false);
$html = ct_page();
test_assert(strpos($html, 'اتصال برقرار نیست') !== false && strpos($html, 'هنوز فعال نشده') !== false, 'فعال‌نشده: «اتصال برقرار نیست» + دلیل ساده');

ct_reset(false);
update_option('hci_conn_key', 'K', false);
update_option('hci_conn_source_url', CT_URL, false);
update_option('hci_conn_state', array('status' => 'disabled', 'checked_at' => time()), false);
update_option('hci_conn_last_attempt', time(), false);
$GLOBALS['__stub_http_response_queue'] = array(ct_disabled_resp());
$html = ct_page();
test_assert(strpos($html, 'قطع شده') !== false && strpos($html, 'هی‌مد') !== false, 'قطع‌شده توسط هی‌مد: دلیل ساده');

ct_reset(false);
update_option('hci_conn_key', 'K', false);
update_option('hci_conn_source_url', CT_URL, false);
update_option('hci_conn_state', array('status' => 'unreachable', 'checked_at' => time()), false);
update_option('hci_conn_last_attempt', time(), false);
$html = ct_page();
test_assert(strpos($html, 'ارتباط با سرور ممکن نشد') !== false, 'ارتباط ممکن نشد: دلیل ساده');

// صفحه‌ی بازبینی/سینک/محصولات هم چیزی از مبدا ندارند
ct_reset();
$GLOBALS['__stub_post_responder'] = ct_activation_ok('PAGE-KEY-789');
HCI_Connection::activate();
$GLOBALS['__test_current_user_id'] = 2;
set_transient('hci_selection_2', array('1' => array('source_product_id' => 1, 'name' => 'n', 'short_description' => '', 'sku' => 'hmp-1', 'price' => '1000', 'stock_quantity' => 1, 'category_names' => array(), 'featured_image' => null, 'images' => array(), 'product_type' => 'simple', 'variations' => array())), 1800);
ob_start(); HCI_Products::render_review_page(); $review = (string) ob_get_clean();
ob_start(); HCI_Sync::render_page(); $syncPage = (string) ob_get_clean();
foreach (array('origin-secret-host', 'PAGE-KEY', 'LEGACY-KEY', 'wp-json/hmw') as $needle) {
    test_assert(strpos($review, $needle) === false && strpos($syncPage, $needle) === false && strpos(ct_page('pricing'), $needle) === false && strpos(ct_page('reset'), $needle) === false, 'صفحه‌های بازبینی، سینک، قیمت‌گذاری و ریست شامل «' . $needle . '» نیستند');
}

// ----------------------------------------------------------------------------
test_section('هیچ آدرس/کلیدی در پیام خطا یا لاگ');

ct_reset();
$logFile = tempnam(sys_get_temp_dir(), 'hcilog');
ini_set('error_log', $logFile);
$GLOBALS['__stub_http_response_queue'] = array(new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host: origin-secret-host.invalid for ' . CT_URL . ' key LEGACY-KEY-123'));
$res = HCI_Source_Client::get_delta_products('2026-01-01 00:00:00');
test_assert(strpos((string) $res['message'], 'origin-secret-host') === false && strpos((string) $res['message'], 'LEGACY-KEY') === false, 'پیام خطای ارتباط شامل آدرس/کلید نیست');
$GLOBALS['__stub_http_response_queue'] = array(ct_json(500, array('message' => 'oops at ' . CT_URL . ' with LEGACY-KEY-123')));
$res = HCI_Source_Client::get_delta_products('2026-01-01 00:00:00');
test_assert(strpos((string) $res['message'], 'origin-secret-host') === false && strpos((string) $res['message'], 'LEGACY-KEY') === false, 'پیام خطای پاسخ سرور شامل آدرس/کلید نیست');
$GLOBALS['__stub_http_response_queue'] = array(new WP_Error('x', 'host origin-secret-host.invalid'));
HCI_Sync::run_daily_sync(true);
$GLOBALS['__stub_post_responder'] = static fn ($u, $a) => new WP_Error('x', 'fail at origin-secret-host.invalid');
HCI_Connection::activate();
$log = (string) file_get_contents($logFile);
test_assert(strpos($log, 'origin-secret-host') === false && strpos($log, 'LEGACY-KEY') === false && strpos($log, 'NEW-KEY') === false, 'لاگ خطا شامل آدرس/کلید نیست');
$summary = json_encode(array(get_option('hci_last_sync_summary'), get_option('hci_sync_history')), JSON_UNESCAPED_UNICODE);
test_assert(strpos((string) $summary, 'origin-secret-host') === false && strpos((string) $summary, 'LEGACY-KEY') === false, 'خلاصه و تاریخچه‌ی سینک شامل آدرس/کلید نیست');
@unlink($logFile);

// ----------------------------------------------------------------------------
test_section('زمان‌بندی تلاش‌ها: باز شدن صفحه (حداکثر هر ۱۰ دقیقه)، فعال‌سازی افزونه، دکمه');

ct_reset(false);
update_option('hci_conn_source_url', CT_URL, false);
$GLOBALS['__stub_post_responder'] = static fn ($u, $a) => ct_json(403, array('success' => false, 'error' => array('code' => 'hmw_activation_failed')));
ct_page(); ct_page();
test_assert(count($GLOBALS['__test_post_calls']) === 1, 'باز شدن مکرر صفحه در ۱۰ دقیقه فقط یک تلاش می‌سازد');
update_option('hci_conn_last_attempt', time() - 601, false);
ct_page();
test_assert(count($GLOBALS['__test_post_calls']) === 2, 'بعد از ۱۰ دقیقه، دوباره تلاش می‌شود');
HCI_Connection::refresh(true);
test_assert(count($GLOBALS['__test_post_calls']) === 3, 'دکمه «بررسی دوباره» بدون انتظار تلاش می‌کند');
ct_reset(false);
update_option('hci_conn_source_url', CT_URL, false);
$GLOBALS['__stub_post_responder'] = ct_activation_ok('ACT-KEY');
HCI_Connection::on_plugin_activation();
test_assert(HCI_Connection::key() === 'ACT-KEY', 'هنگام فعال‌سازی افزونه، اتصال خودکار برقرار می‌شود');

// ----------------------------------------------------------------------------
test_section('ریست داده‌ها اتصال را پاک نمی‌کند');
ct_reset();
$GLOBALS['__stub_post_responder'] = ct_activation_ok('KEEP-KEY');
HCI_Connection::activate();
$secretBefore = get_option('hci_install_secret');
HCI_Admin::reset_data();
test_assert(HCI_Connection::key() === 'KEEP-KEY' && HCI_Connection::url() === CT_URL && get_option('hci_install_secret') === $secretBefore && HCI_Connection::status() === 'connected', 'کلید، آدرس، راز نصب و وضعیت بعد از ریست سالم است');

// ----------------------------------------------------------------------------
test_section('آدرس مبدا ثابت داخل پلاگین، قابل override با wp-config');
ct_reset(false);
test_assert(HCI_Connection::url() === '' , 'بدون ثابت و بدون مقدار قبلی: خالی (هیچ دامنه‌ای hardcode نشده)');
define('HCI_SOURCE_URL', 'https://staging-origin.invalid/wp-json/hmw/v1');
update_option('hci_conn_source_url', CT_URL, false);
test_assert(HCI_Connection::url() === 'https://staging-origin.invalid/wp-json/hmw/v1', 'ثابت HCI_SOURCE_URL بر مقدار ذخیره‌شده غالب است');
$GLOBALS['__stub_post_responder'] = ct_activation_ok('STG-KEY');
HCI_Connection::activate();
test_assert(end($GLOBALS['__test_post_calls'])['url'] === 'https://staging-origin.invalid/wp-json/hmw/v1/activate' && get_option('hci_conn_source_url', null) === null, 'فعال‌سازی به آدرس ثابت می‌رود و آدرس دیگری ذخیره نمی‌شود');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
