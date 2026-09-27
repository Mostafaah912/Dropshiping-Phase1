<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Wholesale — Route Registration & Client/Server Endpoint Contract (PHP " . PHP_VERSION . ")\n";
echo "این تست دقیقاً همان چیزی را می‌سنجد که باعث ۴۰۴ شدن /hmw/v1/health در دیباگ واقعی شد:\n";
echo "آیا register_rest_route() واقعاً از طریق rest_api_init فراخوانی می‌شود، و آیا مسیری که کلاینت درخواست می‌کند\n";
echo "دقیقاً همان مسیری‌ست که سرور ثبت کرده — بدون فرض هیچ دامنه‌ای (چون register_rest_route هرگز دامنه نمی‌گیرد).\n";

// =============================================================================
// ۱) HMW_REST_API::init() باید rest_api_init را هوک کند (نه چیز دیگری، نه هیچ‌جای دیگر)
// =============================================================================
test_section('۱ — HMW_REST_API::init() به rest_api_init وصل است');

HMW_REST_API::init();

test_assert(
    !empty($GLOBALS['__test_hooks']['rest_api_init']),
    'add_action(\'rest_api_init\', ...) واقعاً صدا زده شده'
);
test_assert(
    !empty($GLOBALS['__test_hooks']['rest_authentication_errors']),
    'add_filter(\'rest_authentication_errors\', ...) هم صدا زده شده (برای IP/Rate/Key، نه Routing)'
);

// =============================================================================
// ۲) شبیه‌سازی fire شدن واقعی rest_api_init توسط وردپرس، و بررسی این‌که
//    register_rest_route() برای hmw/v1 + /health واقعاً اجرا شد.
// =============================================================================
test_section('۲ — بعد از fire شدن rest_api_init، مسیر hmw/v1/health واقعاً ثبت شده');

do_action_test('rest_api_init');

$healthRoutes = array_values(array_filter(
    $GLOBALS['__test_registered_routes'],
    static fn (array $r): bool => $r['namespace'] === 'hmw/v1' && $r['route'] === '/health'
));

test_evidence('همه route های ثبت‌شده با namespace=hmw/v1', array_map(
    static fn (array $r): string => $r['route'],
    array_values(array_filter($GLOBALS['__test_registered_routes'], static fn (array $r): bool => $r['namespace'] === 'hmw/v1'))
));

test_assert(count($healthRoutes) === 1, 'دقیقاً یک route با namespace=hmw/v1 و route=/health ثبت شده (نه صفر، نه بیشتر)');

if ($healthRoutes) {
    $healthArgs = $healthRoutes[0]['args'];
    test_assert(($healthArgs['methods'] ?? null) === WP_REST_Server::READABLE, 'متد ثبت‌شده GET است');
    test_assert(is_callable($healthArgs['permission_callback'] ?? null), 'permission_callback واقعاً callable است (یعنی Auth بعد از Routing واقعاً اجرا می‌شود)');
}

// =============================================================================
// ۳) قرارداد نام Endpoint بین کلاینت و سرور — به‌جای فرض دستی، مسیر واقعی را
//    از خودِ فایل سورس HCI_Source_Client استخراج می‌کنیم؛ اگر یک روز کسی
//    مسیر سمت سرور را به‌جای /health چیز دیگری کند (یا برعکس)، این Assertion
//    فوراً قرمز می‌شود — دقیقاً همان سناریویی که در دیباگ واقعی نگرانش بودیم.
// =============================================================================
test_section('۳ — قرارداد مسیر بین HCI_Source_Client (کلاینت) و HMW_REST_API (سرور)');

$clientSource = file_get_contents(HCI_REPO_ROOT . '/includes/class-hci-source-client.php');
preg_match("/rtrim\\(\\\$api_url,\\s*'\\/'\\)\\s*\\.\\s*'(\\/[a-z_\\/]+)'/", $clientSource, $matches);
$clientRequestedPath = $matches[1] ?? null;

test_evidence('مسیری که کلاینت واقعاً درخواست می‌کند (استخراج‌شده از سورس واقعی، نه فرض دستی)', $clientRequestedPath);

test_assert($clientRequestedPath !== null, 'الگوی ساخت URL در HCI_Source_Client پیدا و پارس شد');
test_assert(
    $clientRequestedPath === '/health',
    'مسیری که کلاینت درخواست می‌کند (' . ($clientRequestedPath ?? 'NULL') . ') دقیقاً با مسیر ثبت‌شده سمت سرور (/health) یکی است'
);

// =============================================================================
// ۴) namespace هاردکد در هیچ‌کجا با فرض دامنه خاص نیست — rest_url() در هر دو
//    سمت (نمایش در پنل ادمین و مستندسازی) پویا محاسبه می‌شود.
// =============================================================================
test_section('۴ — namespace مستقل از دامنه است (rest_url فقط path می‌گیرد، نه Domain)');

$reflection = new ReflectionClass('HMW_REST_API');
$namespaceConst = $reflection->getConstant('NAMESPACE');
test_assert($namespaceConst === 'hmw/v1', 'ثابت NAMESPACE داخل کد دقیقاً hmw/v1 است');

$builtBase = rest_url($namespaceConst);
test_evidence('rest_url(NAMESPACE) با دامنه فرضی تست', $builtBase);
test_assert(
    str_ends_with($builtBase, 'wp-json/hmw/v1'),
    'rest_url() مسیر hmw/v1 را زیر هر دامنه‌ای که سایت دارد می‌سازد (اینجا یک دامنه کاملاً فرضی/بی‌ربط برای تست بود)'
);

// =============================================================================
// ۵ (Bonus) — HMW_REST_API::self_test() هر دو حالت را درست تشخیص می‌دهد
// =============================================================================
test_section('۵ (Bonus) — HMW_REST_API::self_test(): تشخیص rest_no_route در برابر route سالم');

$GLOBALS['__stub_http_response'] = array(
    'response' => array('code' => 404, 'message' => 'Not Found'),
    'body' => json_encode(array('code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => array('status' => 404))),
);
$notRegistered = HMW_REST_API::self_test();
test_evidence('self_test() وقتی Permalinks فلاش نشده (rest_no_route)', $notRegistered);
test_assert($notRegistered['ok'] === false && $notRegistered['code'] === 'rest_no_route', 'حالت rest_no_route درست تشخیص داده می‌شود و پیام Permalinks را می‌دهد');

$GLOBALS['__stub_http_response'] = array(
    'response' => array('code' => 401, 'message' => 'Unauthorized'),
    'body' => json_encode(array('code' => 'hmw_api_missing_key', 'message' => 'API Key ارسال نشده است.', 'data' => array('status' => 401))),
);
$routeOk = HMW_REST_API::self_test();
test_evidence('self_test() وقتی مسیر سالم است ولی کلید نداده‌ایم (۴۰۱)', $routeOk);
test_assert($routeOk['ok'] === true && $routeOk['code'] === 'route_ok', 'حالت ۴۰۱ (یعنی Routing سالم، فقط Auth رد شد) به‌درستی «سالم» تشخیص داده می‌شود');

echo "\n=== جمع‌بندی قرارداد Route ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
