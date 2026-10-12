<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Wholesale — فعال‌سازی با اثبات مالکیت دامنه (S3-03، S3-09) (PHP " . PHP_VERSION . ")\n";

function oa_reset(): Fake_WPDB {
    $w = hmw_test_create_products_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array('hmw_db_version' => HMW_VERSION);
    $GLOBALS['__test_is_ssl'] = true;
    $GLOBALS['__test_safe_get_calls'] = array();
    $GLOBALS['__test_last_requested_urls'] = array();
    $GLOBALS['__stub_safe_get_responder'] = null;
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    return $w;
}

/** سایت مشتری واقعی که راز نصبش $secret است و به درخواست برگشتی درست جواب می‌دهد. */
function oa_client_with(string $secret): callable {
    return static function (string $url) use ($secret): array {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => json_encode(array('proof' => hash_hmac('sha256', (string) ($q['challenge'] ?? ''), $secret))));
    };
}

function oa_activate(string $domain, string $secret): WP_REST_Response {
    return HMW_REST_API::activate(new WP_REST_Request(array('domain' => $domain, 'secret' => $secret)));
}

function oa_secret(): string {
    return bin2hex(random_bytes(32));
}

// ----------------------------------------------------------------------------
test_section('فعال‌سازی موفق: فقط دامنه‌ی ثبت‌شده و «آماده»، با اثبات مالکیت، کلید فقط یک‌بار');

$w = oa_reset();
$id = (int) HMW_Sites::add('shop.ir')['id'];
$secret = oa_secret();
$GLOBALS['__stub_safe_get_responder'] = oa_client_with($secret);
$r = oa_activate('https://WWW.Shop.ir/', $secret);
$d = $r->get_data();
test_assert($r->get_status() === 200 && $d['success'] === true && !empty($d['data']['api_key']), 'سایت درست کلید می‌گیرد (با نرمال‌سازی دامنه)');
$row = HMW_Sites::find($id);
test_assert($row['status'] === 'active' && $row['key_hash'] === hash('sha256', $d['data']['api_key']), 'وضعیت فعال و فقط hash ذخیره شد');
test_assert(HMW_REST_API::authorize(new WP_REST_Request(array(), array('Authorization' => 'Bearer ' . $d['data']['api_key']))) === true, 'کلید صادرشده واقعاً کار می‌کند');
$calls = $GLOBALS['__test_safe_get_calls'];
test_assert(count($calls) === 1 && parse_url($calls[0]['url'], PHP_URL_SCHEME) === 'https' && parse_url($calls[0]['url'], PHP_URL_HOST) === 'shop.ir', 'درخواست برگشتی HTTPS و به همان دامنه‌ی ثبت‌شده (نه آدرس ادعایی کلاینت) رفت');
parse_str((string) parse_url($calls[0]['url'], PHP_URL_QUERY), $q);
test_assert(($q['rest_route'] ?? '') === '/hci/v1/ownership' && preg_match('/^[a-f0-9]{64}$/', (string) ($q['challenge'] ?? '')) === 1, 'مسیر عمومی کلاینت + challenge تصادفی ۳۲ بایتی');
test_assert(($calls[0]['args']['timeout'] ?? 99) <= 10 && ($calls[0]['args']['redirection'] ?? 5) === 0, 'timeout کوتاه و بدون Redirect');
test_assert(count($GLOBALS['__test_last_requested_urls']) === 0, 'از wp_remote_get ناامن استفاده نشد (فقط wp_safe_remote_get)');
test_assert(strpos(json_encode($w->get_results('SELECT * FROM wp_hmw_client_sites')), $secret) === false && strpos(json_encode($GLOBALS['__test_options']), $secret) === false, 'راز نصب هیچ‌جا ذخیره نشد');

// دومین فعال‌سازی (حتی با راز درست) رد می‌شود: کلید فقط یک‌بار
$again = oa_activate('shop.ir', $secret);
test_assert($again->get_status() === 403 && empty($again->get_data()['data']), 'فعال‌سازی دوباره بدون اقدام مدیر رد می‌شود');
test_assert(HMW_Sites::find($id)['key_hash'] === hash('sha256', $d['data']['api_key']), 'کلید قبلی دست‌نخورده ماند');

// با «صدور دوباره» مدیر، دوباره ممکن می‌شود
HMW_Sites::reissue($id);
$r2 = oa_activate('shop.ir', $secret);
test_assert($r2->get_status() === 200 && $r2->get_data()['data']['api_key'] !== $d['data']['api_key'], 'بعد از صدور دوباره توسط مدیر، کلید تازه صادر می‌شود');
test_assert(os_old_key_dead($d['data']['api_key']), 'کلید قبلی نامعتبر است');
function os_old_key_dead(string $k): bool {
    $r = HMW_REST_API::authorize(new WP_REST_Request(array(), array('Authorization' => 'Bearer ' . $k)));
    return $r instanceof WP_Error && $r->get_error_code() === 'hmw_api_invalid_key';
}

// ----------------------------------------------------------------------------
test_section('ادعای دامنه با راز اشتباه یا بدون پاسخ: رد؛ کلید صادر نمی‌شود');

$w = oa_reset();
$id = (int) HMW_Sites::add('victim.ir')['id'];
$real = oa_secret();
$GLOBALS['__stub_safe_get_responder'] = oa_client_with($real); // سایت واقعی با راز خودش جواب می‌دهد
$attacker = oa_secret();
$r = oa_activate('victim.ir', $attacker); // مهاجم دامنه را می‌داند ولی رازش با سایت واقعی یکی نیست
test_assert($r->get_status() === 403 && HMW_Sites::find($id)['status'] === 'pending' && HMW_Sites::find($id)['key_hash'] === null, 'مهاجمی که نام دامنه را می‌داند کلید نمی‌گیرد');

$GLOBALS['__stub_safe_get_responder'] = static fn (string $u) => new WP_Error('http_request_failed', 'timeout');
test_assert(oa_activate('victim.ir', $real)->get_status() === 403 && HMW_Sites::find($id)['key_hash'] === null, 'اگر مبدا به سایت نرسد (پلاگین نصب نیست/سایت دیگر)، رد می‌شود');

$GLOBALS['__stub_safe_get_responder'] = static fn (string $u) => array('response' => array('code' => 200), 'body' => json_encode(array('proof' => 'deadbeef')));
test_assert(oa_activate('victim.ir', $real)->get_status() === 403, 'proof اشتباه رد می‌شود');

$GLOBALS['__stub_safe_get_responder'] = static fn (string $u) => array('response' => array('code' => 200), 'body' => 'not json');
test_assert(oa_activate('victim.ir', $real)->get_status() === 403, 'پاسخ نامعتبر رد می‌شود');

$calls_before = count($GLOBALS['__test_safe_get_calls']);
test_assert(oa_activate('victim.ir', 'short')->get_status() === 403 && count($GLOBALS['__test_safe_get_calls']) === $calls_before, 'راز با قالب نامعتبر بدون هیچ درخواست برگشتی رد می‌شود');

// challenge هر بار تازه
$w = oa_reset();
HMW_Sites::add('fresh.ir');
$seen = array();
$GLOBALS['__stub_safe_get_responder'] = static function (string $u) use (&$seen) { parse_str((string) parse_url($u, PHP_URL_QUERY), $q); $seen[] = $q['challenge']; return new WP_Error('x', 'x'); };
oa_activate('fresh.ir', oa_secret());
oa_activate('fresh.ir', oa_secret());
test_assert(count(array_unique($seen)) === 2, 'هر تلاش challenge تصادفی تازه دارد (apex و www همان تلاش یک challenge می‌گیرند)');

// ----------------------------------------------------------------------------
test_section('پاسخ شکست یکسان: ثبت‌نشده / غیرفعال / فعال‌شده / آماده‌با‌اثبات‌ناموفق، قابل تشخیص نیستند');

$w = oa_reset();
HMW_Sites::add('pending.ir');
$disabled = (int) HMW_Sites::add('disabled.ir')['id'];
HMW_Sites::set_status($disabled, 'disabled');
$activeId = (int) HMW_Sites::add('active.ir')['id'];
HMW_Sites::issue_key($activeId);
$GLOBALS['__stub_safe_get_responder'] = oa_client_with(oa_secret()); // راز اشتباه برای pending
$bodies = array();
foreach (array('unknown.ir', 'disabled.ir', 'active.ir', 'pending.ir') as $dom) {
    $resp = oa_activate($dom, oa_secret());
    $bodies[$dom] = $resp->get_status() . '|' . json_encode($resp->get_data(), JSON_UNESCAPED_UNICODE);
}
test_evidence('پاسخ‌ها', $bodies);
test_assert(count(array_unique($bodies)) === 1 && strpos(reset($bodies), '403|') === 0, 'چهار حالت دقیقاً یک پاسخ دارند');
test_assert(strpos(reset($bodies), 'domain') === false, 'پاسخ نام دامنه را بازتاب نمی‌دهد');
// برای دامنه‌های غیرِ «آماده» هیچ درخواست برگشتی نمی‌رود
$N = count($GLOBALS['__test_safe_get_calls']);
test_assert($N >= 1 && count(array_filter($GLOBALS['__test_safe_get_calls'], static fn (array $c): bool => !in_array(parse_url($c['url'], PHP_URL_HOST), array('pending.ir', 'www.pending.ir'), true))) === 0, 'فقط برای دامنه‌ی «آماده فعال‌سازی» درخواست برگشتی رفت');
test_assert(count(HMW_Sites::reject_log()) >= 4 && strpos(json_encode(HMW_Sites::reject_log()), 'secret') === false, 'تلاش‌های ردشده بدون ذخیره راز ثبت می‌شود (S3-08)');

// ----------------------------------------------------------------------------
test_section('Rate Limit فعال‌سازی: بر اساس دامنه و IP؛ و HTTPS');

$w = oa_reset();
HMW_Sites::add('limited.ir');
$GLOBALS['__stub_safe_get_responder'] = static fn (string $u) => new WP_Error('x', 'x');
$statuses = array();
for ($i = 0; $i < 7; $i++) { $statuses[] = oa_activate('limited.ir', oa_secret())->get_status(); }
test_assert(array_slice($statuses, 0, 5) === array(403, 403, 403, 403, 403) && $statuses[5] === 429, 'بعد از ۵ تلاش برای یک دامنه، ۴۲۹');
$n = count($GLOBALS['__test_safe_get_calls']);
oa_activate('limited.ir', oa_secret());
test_assert(count($GLOBALS['__test_safe_get_calls']) === $n, 'تلاش محدودشده هیچ درخواست برگشتی نمی‌سازد');

$w = oa_reset();
$st = array();
for ($i = 0; $i < 22; $i++) { $st[] = oa_activate('d' . $i . '.ir', oa_secret())->get_status(); } // هر بار دامنه‌ی متفاوت، یک IP
test_assert($st[19] === 403 && $st[20] === 429, 'بعد از ۲۰ تلاش از یک IP (حتی با دامنه‌های مختلف)، ۴۲۹');

$w = oa_reset();
HMW_Sites::add('plain.ir');
$GLOBALS['__test_is_ssl'] = false;
$GLOBALS['__stub_safe_get_responder'] = oa_client_with('x');
test_assert(oa_activate('plain.ir', oa_secret())->get_status() === 403 && count($GLOBALS['__test_safe_get_calls']) === 0, 'فعال‌سازی روی HTTP ساده پذیرفته نمی‌شود');
$GLOBALS['__test_is_ssl'] = true;

// ----------------------------------------------------------------------------
test_section('هم‌زمانی: دو فعال‌سازی هم‌زمان فقط یک کلید می‌سازند');
$w = oa_reset();
$id = (int) HMW_Sites::add('race.ir')['id'];
$secret = oa_secret();
$stolen = null;
$GLOBALS['__stub_safe_get_responder'] = static function (string $u) use ($secret, &$stolen, $id) {
    if ($stolen === null) { $stolen = HMW_Sites::issue_key_if_pending($id); } // درخواست دیگر وسط کار کلید را گرفت
    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    return array('response' => array('code' => 200), 'body' => json_encode(array('proof' => hash_hmac('sha256', (string) $q['challenge'], $secret))));
};
$r = oa_activate('race.ir', $secret);
test_assert($stolen !== null && $r->get_status() === 403, 'درخواست دوم که بعد از اولی رسید کلید نمی‌گیرد');
test_assert(HMW_Sites::find($id)['key_hash'] === hash('sha256', (string) $stolen), 'فقط کلیدِ اولی معتبر است');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
