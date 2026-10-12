<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Wholesale — سایت‌های مشتری، احراز با کلید اختصاصی، Rate Limit، HTTPS، کلید قدیمی (S3-02، S3-04، S3-06، S3-07، S3-08، S3-10) (PHP " . PHP_VERSION . ")\n";

function os_reset(): Fake_WPDB {
    $w = hmw_test_create_products_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array('hmw_db_version' => HMW_VERSION);
    $GLOBALS['__test_is_ssl'] = true;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    return $w;
}

function os_req(string $key): WP_REST_Request {
    return new WP_REST_Request(array(), array('Authorization' => 'Bearer ' . $key));
}

function os_code($r): string {
    return $r instanceof WP_Error ? $r->get_error_code() : 'ok';
}

function os_active_site(string $domain): array {
    $add = HMW_Sites::add($domain);
    $key = HMW_Sites::issue_key((int) $add['id']);
    return array('id' => (int) $add['id'], 'key' => $key);
}

// ----------------------------------------------------------------------------
test_section('نرمال‌سازی دامنه');
test_assert(HMW_Sites::normalize_domain('HTTPS://WWW.Example.COM:8443/path?x=1#y') === 'example.com', 'پروتکل، www، پورت، مسیر و حروف بزرگ حذف/کوچک می‌شود');
test_assert(HMW_Sites::normalize_domain(' www.shop.ir/ ') === 'shop.ir', 'فاصله و اسلش');
test_assert(HMW_Sites::normalize_domain('Example.com.') === 'example.com', 'نقطه‌ی انتهایی');
test_assert(HMW_Sites::normalize_domain('münchen.de') === 'xn--mnchen-3ya.de', 'یونیکد ⇒ punycode');
test_assert(HMW_Sites::normalize_domain('xn--mnchen-3ya.de') === 'xn--mnchen-3ya.de', 'punycode از قبل، دست‌نخورده');
foreach (array('', 'localhost', '192.168.1.1', 'a b.com', 'nodot', '-bad.com', 'ex..com', '[::1]') as $bad) {
    test_assert(HMW_Sites::normalize_domain($bad) === null, 'دامنه نامعتبر رد می‌شود: «' . $bad . '»');
}

// ----------------------------------------------------------------------------
test_section('افزودن، تکراری، وضعیت‌ها و ذخیره فقط hash');
$w = os_reset();
$a = HMW_Sites::add('https://www.Shop.ir/', 'سایت تست');
test_assert($a['success'] === true, 'افزودن موفق');
$row = HMW_Sites::find((int) $a['id']);
test_assert($row['domain'] === 'shop.ir' && $row['status'] === 'pending' && $row['key_hash'] === null && $row['created_at'] !== null && $row['note'] === 'سایت تست', 'ردیف: دامنه نرمال، در انتظار فعال‌سازی، بدون کلید');
test_assert(HMW_Sites::add('shop.ir')['success'] === false && HMW_Sites::add('WWW.SHOP.IR')['success'] === false, 'دامنه تکراری (حتی با www/حروف بزرگ) رد می‌شود');
test_assert(HMW_Sites::add('not a domain')['success'] === false, 'دامنه نامعتبر رد می‌شود');

$key = HMW_Sites::issue_key((int) $a['id']);
$row = HMW_Sites::find((int) $a['id']);
test_assert($row['status'] === 'active' && $row['activated_at'] !== null, 'بعد از صدور کلید: فعال');
test_assert($row['key_hash'] === hash('sha256', $key) && $row['key_prefix'] === substr($key, 0, 12), 'فقط hash و پیشوند ذخیره شد');
$dump = json_encode($w->get_results('SELECT * FROM wp_hmw_client_sites'));
test_assert(strpos((string) $dump, $key) === false, 'کلید خام هیچ‌جای جدول نیست');

// ----------------------------------------------------------------------------
test_section('احراز درخواست با کلید اختصاصی؛ غیرفعال‌سازی همان لحظه اثر می‌کند');
$w = os_reset();
$s = os_active_site('one.ir');
test_assert(HMW_REST_API::authorize(os_req($s['key'])) === true, 'کلید سایت فعال پذیرفته می‌شود');
HMW_Sites::set_status($s['id'], 'disabled');
$r = HMW_REST_API::authorize(os_req($s['key']));
test_assert(os_code($r) === 'hmw_site_disabled' && $r->get_error_data()['status'] === 403, 'بلافاصله بعد از غیرفعال‌سازی: hmw_site_disabled با ۴۰۳ (بدون کش)');
HMW_Sites::set_status($s['id'], 'active');
test_assert(HMW_REST_API::authorize(os_req($s['key'])) === true, 'با فعال‌سازی دوباره، همان کلید همان لحظه دوباره کار می‌کند');
test_assert(os_code(HMW_REST_API::authorize(os_req('hmw_wrong_key'))) === 'hmw_api_invalid_key', 'کلید ناشناخته ⇒ نامعتبر');
test_assert(os_code(HMW_REST_API::authorize(new WP_REST_Request())) === 'hmw_api_missing_key', 'بدون کلید ⇒ ارسال نشده');

HMW_Sites::reissue($s['id']);
$row = HMW_Sites::find($s['id']);
test_assert($row['status'] === 'pending' && $row['key_hash'] === null, 'صدور دوباره: کلید پاک و وضعیت در انتظار فعال‌سازی');
test_assert(os_code(HMW_REST_API::authorize(os_req($s['key']))) === 'hmw_api_invalid_key', 'کلید قبلی همان لحظه نامعتبر شد');
HMW_Sites::delete($s['id']);
test_assert(HMW_Sites::find($s['id']) === null, 'حذف');

// ----------------------------------------------------------------------------
test_section('آخرین ارتباط: حداکثر هر ۵ دقیقه یک‌بار نوشته می‌شود');
$w = os_reset();
$s = os_active_site('seen.ir');
$w->query("UPDATE wp_hmw_client_sites SET last_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 600) . "' WHERE id = " . $s['id']);
HMW_REST_API::authorize(os_req($s['key']));
$t1 = HMW_Sites::find($s['id'])['last_seen_at'];
test_assert(strtotime($t1 . ' UTC') >= time() - 5, 'بعد از ۱۰ دقیقه، آخرین ارتباط به‌روز شد');
$w->query("UPDATE wp_hmw_client_sites SET last_seen_at = '" . gmdate('Y-m-d H:i:s', time() - 100) . "' WHERE id = " . $s['id']);
$before = HMW_Sites::find($s['id'])['last_seen_at'];
HMW_REST_API::authorize(os_req($s['key']));
test_assert(HMW_Sites::find($s['id'])['last_seen_at'] === $before, 'کمتر از ۵ دقیقه بعد، دوباره نوشته نشد');

// ----------------------------------------------------------------------------
test_section('Rate Limit: بر اساس کلید/سایت (نه IP) و پنجره ثابت');
$w = os_reset();
$a = os_active_site('a.ir');
$b = os_active_site('b.ir'); // هر دو از «یک IP» (مثل پشت CDN)
$ok = 0;
for ($i = 0; $i < 120; $i++) { if (HMW_REST_API::authorize(os_req($a['key'])) === true) { $ok++; } }
test_assert($ok === 120, '۱۲۰ درخواست اول سایت A مجاز است');
test_assert(os_code(HMW_REST_API::authorize(os_req($a['key']))) === 'hmw_api_rate_limited', 'درخواست ۱۲۱ام سایت A رد می‌شود (۴۲۹)');
test_assert(HMW_REST_API::authorize(os_req($b['key'])) === true, 'سایت B با همان IP تحت‌تأثیر نیست');

$t0 = 1_800_000_000;
$GLOBALS['__test_options'] = array();
for ($i = 0; $i < 5; $i++) { HMW_Rate_Limiter::allow('probe', 5, 60, $t0 + $i * 10); }
test_assert(HMW_Rate_Limiter::allow('probe', 5, 60, $t0 + 59) === false, 'پنجره ثابت: در ثانیه ۵۹ هنوز پر است');
test_assert(HMW_Rate_Limiter::allow('probe', 5, 60, $t0 + 60) === true, 'ثانیه ۶۰: با وجود درخواست‌های ردشده‌ی وسط، پنجره تمدید نشده و ریست شد');

// تلاش‌های ناموفق: محدودیت جدا بر اساس IP (برای حدس زدن کلید)
$w = os_reset();
$codes = array();
for ($i = 0; $i < 32; $i++) { $codes[] = os_code(HMW_REST_API::authorize(os_req('bad_' . $i))); }
test_assert($codes[0] === 'hmw_api_invalid_key' && end($codes) === 'hmw_api_rate_limited', 'حدس‌زدن پشت‌سرهم کلید توسط یک IP محدود می‌شود');

// ----------------------------------------------------------------------------
test_section('HTTPS اجباری');
$w = os_reset();
$s = os_active_site('https.ir');
$GLOBALS['__test_is_ssl'] = false;
$r = HMW_REST_API::authorize(os_req($s['key']));
test_assert(os_code($r) === 'hmw_https_required' && $r->get_error_data()['status'] === 403, 'روی HTTP ساده رد می‌شود');
$GLOBALS['__test_is_ssl'] = true;
test_assert(HMW_REST_API::authorize(os_req($s['key'])) === true, 'روی HTTPS پذیرفته می‌شود');

// ----------------------------------------------------------------------------
test_section('کلید مشترک قدیمی: تا خاموش‌کردن صریح کار می‌کند؛ راه برگشت');
$w = os_reset();
$legacy = HMW_REST_API::generate_key();
test_assert(HMW_REST_API::authorize(os_req($legacy)) === true, 'بعد از آپدیت، کلید قدیمی پیش‌فرض روشن و کار می‌کند');
test_assert(HMW_REST_API::legacy_enabled() === true, 'وضعیت پیش‌فرض: روشن');
test_assert(HMW_REST_API::set_legacy_enabled(false, false) === false && HMW_REST_API::legacy_enabled() === true, 'خاموش‌کردن بدون تأیید دوباره انجام نمی‌شود');
test_assert(HMW_REST_API::set_legacy_enabled(false, true) === true && HMW_REST_API::legacy_enabled() === false, 'با تأیید صریح خاموش می‌شود');
test_assert(os_code(HMW_REST_API::authorize(os_req($legacy))) === 'hmw_api_invalid_key', 'بعد از خاموش‌شدن، کلید قدیمی رد می‌شود');
$sx = os_active_site('new.ir');
test_assert(HMW_REST_API::authorize(os_req($sx['key'])) === true, 'کلید اختصاصی همچنان کار می‌کند');
HMW_REST_API::set_legacy_enabled(true, true);
test_assert(HMW_REST_API::authorize(os_req($legacy)) === true, 'راه برگشت: روشن‌کردن دوباره کلید قدیمی با یک کلیک');

// ----------------------------------------------------------------------------
test_section('صفحه مدیریت «سایت‌های مشتری»');
$w = os_reset();
$p = os_active_site('listed.ir');
HMW_Sites::add('waiting.ir');
HMW_Sites::set_status(HMW_Sites::add('off.ir')['id'], 'disabled');
ob_start();
HMW_Admin::render_sites_page();
$html = (string) ob_get_clean();
test_assert(strpos($html, 'listed.ir') !== false && strpos($html, 'در انتظار فعال‌سازی') !== false && strpos($html, 'فعال') !== false && strpos($html, 'غیرفعال') !== false, 'دامنه‌ها و هر سه وضعیت نمایش داده می‌شود');
test_assert(strpos($html, $p['key']) === false && strpos($html, hash('sha256', $p['key'])) === false, 'نه کلید و نه hash آن در صفحه نیست');
test_assert(strpos($html, 'hmw_site_add') !== false && strpos($html, 'hmw_site_reissue') !== false && strpos($html, 'hmw_site_delete') !== false && strpos($html, 'hmw_legacy_toggle') !== false, 'فرم‌های افزودن/صدور دوباره/حذف و سوییچ کلید قدیمی هست');

// ----------------------------------------------------------------------------
test_section('آخرین سینک سایت ثبت می‌شود (فقط با کلید اختصاصی)');
$w = os_reset();
$s = os_active_site('sync.ir');
HMW_REST_API::authorize(os_req($s['key']));
HMW_Sites::touch_sync_current();
test_assert(HMW_Sites::find($s['id'])['last_sync_at'] !== null, 'last_sync_at ثبت شد');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
