<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — اعتمادپذیری Import: قفل هم‌زمان، دانلود تصویر، علت‌ها (S2-01، S2-02، S2-03) (PHP " . PHP_VERSION . ")\n";

function ir_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array(); // اتصال تنظیم‌نشده ⇒ داده تازه از مبدا گرفته نمی‌شود (اسنپ‌شات)
    $GLOBALS['__fake_sideload_timeouts'] = array();
    $GLOBALS['__test_hooks']['http_request_timeout'] = array();
    $GLOBALS['__fake_wc_on_variation_save'] = null;
    $GLOBALS['__fake_on_sideload'] = null;
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    return $w;
}

function ir_variable_payload(int $variations = 3, array $o = array()): array {
    $vs = array();
    for ($i = 1; $i <= $variations; $i++) {
        $vs[] = array('variation_id' => $i, 'attributes' => array(array('name' => 'رنگ', 'option' => 'رنگ ' . $i)), 'price' => '10000', 'stock_quantity' => 5.0, 'stock_status' => 'instock', 'sku' => null);
    }
    return array_merge(array('name' => 'محصول متغیر', 'short_description' => '', 'featured_image' => null, 'images' => array(), 'category_path' => array(), 'product_type' => 'variable', 'sku' => 'hmp-77', 'price' => null, 'stock_quantity' => null, 'stock_status' => 'instock', 'variations' => $vs), $o);
}

function ir_parents_created(): int {
    $n = 0;
    foreach ($GLOBALS['__fake_postmeta'] as $meta) {
        if (isset($meta['_hci_source_product_id'])) { $n++; }
    }
    return $n;
}

// ----------------------------------------------------------------------------
test_section('S2-03 / S2-01 — محصول Variable کند (بیش از ۵ دقیقه) نباید هم‌زمان توسط کارگر دوم دوباره پردازش شود');

$w = ir_reset();
HCI_DB::queue_import(77, 'hmp-77', (string) wp_json_encode(ir_variable_payload(3, array('featured_image' => 'https://x.test/slow.jpg'))));
// کارگر A مرحله‌ی کند دانلود تصویر را می‌گذراند؛ کارگر B (درخواست بعدی مرورگر یا Action Scheduler) وسط آن
// می‌رسد، وقتی A «۴۰۰ ثانیه است شروع کرده ولی هنوز زنده است» و هنوز والد را ذخیره نکرده.
$GLOBALS['__fake_on_sideload'] = static function () use ($w): void {
    $w->query("UPDATE wp_hci_product_map SET started_at = '" . gmdate('Y-m-d H:i:s', time() - 400) . "' WHERE source_product_id = 77 AND source_variation_id IS NULL");
    HCI_DB::release_stale_locks();
    HCI_Import::process_import_action(77);
};
HCI_Import::process_import_action(77);
$row = HCI_DB::get_map_row(77);
test_evidence('ردیف بعد از دو کارگر', array('status' => $row['import_status'], 'parents' => ir_parents_created(), 'variations' => count(array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation'))));
test_assert(ir_parents_created() === 1, 'فقط یک محصول والد ساخته شد (کارگر دوم قفلِ زنده را نشکست)');
test_assert(count(array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation')) === 3, 'دقیقاً ۳ تنوع ساخته شد، نه بیشتر');
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED, 'ردیف در نهایت imported است');

// قفل واقعاً مرده (هیچ ضربان زنده‌ای در ۱۵ دقیقه) همچنان آزاد می‌شود
$w = ir_reset();
$w->insert('wp_hci_product_map', array('source_product_id' => 5, 'import_status' => 'processing', 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'created_at' => 'x'));
$w->insert('wp_hci_product_map', array('source_product_id' => 6, 'import_status' => 'processing', 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'updated_at' => gmdate('Y-m-d H:i:s', time() - 30), 'created_at' => 'x'));
HCI_DB::release_stale_locks();
test_assert(HCI_DB::get_map_row(5)['import_status'] === 'queued', 'قفل بدون ضربان (یک ساعت) آزاد شد');
test_assert(HCI_DB::get_map_row(6)['import_status'] === 'processing', 'قفلی که ۳۰ ثانیه پیش ضربان داشته آزاد نشد');

// ----------------------------------------------------------------------------
test_section('S2-02 — دانلود تصویر: timeout حدود ۲۰ ثانیه و بارگذاری توابع رسانه در مسیر Action Scheduler/Cron');

ir_reset();
HCI_Import::get_or_sideload_image('https://x.test/a.jpg');
test_assert($GLOBALS['__fake_sideload_timeouts'] === array(20), 'هنگام دانلود، timeout مؤثر ۲۰ ثانیه است (نه ۳۰۰)');
test_assert(($GLOBALS['__test_hooks']['http_request_timeout'] ?? array()) === array(), 'فیلتر timeout بعد از دانلود برداشته می‌شود (روی بقیه سایت اثر نمی‌گذارد)');
test_assert(array_map('basename', HCI_Import::media_include_paths()) === array('file.php', 'image.php', 'media.php'), 'file.php، image.php و media.php صریحاً require می‌شوند');
test_assert(HCI_Import::media_include_paths()[0] === ABSPATH . 'wp-admin/includes/file.php', 'مسیرها از ABSPATH ساخته می‌شوند، نه hardcode');

// ----------------------------------------------------------------------------
test_section('S2-01 — علت ناقص ماندن Variable به زبان ساده در خود ردیف + «تلاش مجدد» فقط بخش ناقص را کامل می‌کند');

$w = ir_reset();
$GLOBALS['__fake_wc_variation_save_throws'] = true;
HCI_DB::queue_import(78, 'hmp-78', (string) wp_json_encode(ir_variable_payload(2, array('sku' => 'hmp-78'))));
HCI_Import::process_import_action(78);
$row = HCI_DB::get_map_row(78);
test_evidence('پیام', $row['error_message']);
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL, 'partial');
test_assert(strpos((string) $row['error_message'], 'رنگ 1') !== false && strpos((string) $row['error_message'], 'رنگ 2') !== false, 'پیام نام تنوع‌های ساخته‌نشده را می‌گوید (نه فقط «ساخته نشد»)');

$GLOBALS['__fake_wc_variation_save_throws'] = false;
$parentsBefore = ir_parents_created();
$GLOBALS['__fake_sideload_timeouts'] = array();
HCI_DB::queue_import(78, 'hmp-78', (string) wp_json_encode(ir_variable_payload(2, array('sku' => 'hmp-78', 'featured_image' => 'https://x.test/f.jpg'))));
HCI_Import::process_import_action(78);
$row = HCI_DB::get_map_row(78);
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED && ir_parents_created() === $parentsBefore, 'تلاش مجدد همان والد را کامل کرد، والد تازه نساخت');
test_assert(count(array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation')) === 2, 'هر دو تنوع ساخته شد');

// تلاش مجددِ دوم، تنوع‌ها و تصویرِ سالم را دوباره نمی‌سازد/دانلود نمی‌کند
$GLOBALS['__fake_sideload_timeouts'] = array();
$varsBefore = array_keys(array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation'));
$w->query("UPDATE wp_hci_product_map SET import_status = 'partial' WHERE source_product_id = 78 AND source_variation_id IS NULL");
HCI_DB::queue_import(78, 'hmp-78', (string) wp_json_encode(ir_variable_payload(2, array('sku' => 'hmp-78', 'featured_image' => 'https://x.test/f.jpg'))));
HCI_Import::process_import_action(78);
test_assert($varsBefore === array_keys(array_filter($GLOBALS['__fake_wc_products'], static fn ($p) => $p['type'] === 'variation')), 'تنوع‌های سالم دوباره ساخته نشدند');
test_assert($GLOBALS['__fake_sideload_timeouts'] === array(), 'تصویر قبلاً دانلودشده دوباره دانلود نشد');

$badge = new ReflectionMethod('HCI_Products', 'render_status_badge');
$badge->setAccessible(true);
$html = (string) $badge->invoke(null, HCI_DB::STATUS_PARTIAL, 'این تنوع‌ها ساخته نشدند: «رنگ 1».');
test_assert(strpos($html, 'hci-status-msg') !== false && strpos($html, 'ساخته نشدند') !== false, 'علت در خود ردیف (نه فقط tooltip) نمایش داده می‌شود');
test_assert(strpos((string) $badge->invoke(null, HCI_DB::STATUS_IMPORTED, ''), 'hci-status-msg') === false, 'ردیف سالم پیام اضافه ندارد');
$src = (string) file_get_contents(HCI_REPO_ROOT . '/includes/class-hci-products.php');
test_assert(substr_count($src, 'hci-status-msg') >= 2, 'بعد از Polling/وارد کن هم (جاوااسکریپت) همان پیام داخل ردیف می‌آید');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
