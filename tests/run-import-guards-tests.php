<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — ذخیره گزینه دسته‌بندی، تشخیص تکراری در بازبینی، لاگ مرحله‌ها (S2-06، S2-07، S2-09) (PHP " . PHP_VERSION . ")\n";

function ig_reset(): Fake_WPDB {
    hci_test_reset_wc_fakes();
    $w = hci_test_create_product_map_db();
    $GLOBALS['wpdb'] = $w;
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__test_current_user_id'] = 4;
    HCI_Pricing::save(1000.0, 10.0, 'publish');
    return $w;
}

function ig_entry(int $id, string $sku): array {
    return array('source_product_id' => $id, 'name' => 'محصول ' . $id, 'short_description' => '', 'sku' => $sku, 'price' => '1000',
        'stock_quantity' => 1, 'category_names' => array(), 'featured_image' => null, 'images' => array(), 'product_type' => 'simple', 'variations' => array());
}

// ----------------------------------------------------------------------------
test_section('S2-06 — گزینه «دسته‌بندی هی‌مد» با برداشتن تیک واقعاً ذخیره می‌شود');

ig_reset();
test_assert(HCI_Import::should_apply_categories() === true, 'پیش‌فرض (گزینه هنوز ساخته نشده): روشن');
HCI_Admin::save_pricing_settings(array('fixed_amount' => '0', 'percent' => '0', 'default_status' => 'draft')); // تیک نخورده
test_assert(HCI_Import::should_apply_categories() === false, 'اولین ذخیره با تیک‌برداشته ⇒ خاموش (قبلاً بی‌اثر بود)');
HCI_Admin::save_pricing_settings(array('fixed_amount' => '0', 'percent' => '0', 'default_status' => 'draft', 'apply_categories' => '1'));
test_assert(HCI_Import::should_apply_categories() === true, 'با تیک ⇒ روشن');
HCI_Admin::save_pricing_settings(array('fixed_amount' => '0', 'percent' => '0', 'default_status' => 'draft'));
test_assert(HCI_Import::should_apply_categories() === false && get_option('hci_apply_categories') === '0', 'دوباره تیک‌برداشتن ⇒ «۰» ذخیره می‌شود');
$GLOBALS['__test_options']['hci_apply_categories'] = ''; // مقدار قدیمیِ ذخیره‌شده با نسخه‌ی قبلی
test_assert(HCI_Import::should_apply_categories() === false, 'مقدار خالیِ قدیمی هم «خاموش» تعبیر می‌شود');

// ----------------------------------------------------------------------------
test_section('S2-07 — ردیف تکراری در بازبینی از همان ابتدا ✕ قرمز با دکمه غیرفعال است و صف‌بندی هم رد می‌شود');

$w = ig_reset();
// ۱) محصولی که قبلاً با SKU مشابه وارد شده (ردیف imported دیگر)
$w->insert('wp_hci_product_map', array('source_product_id' => 20, 'source_sku' => 'hmp-555', 'import_status' => 'imported', 'dest_product_id' => 900, 'created_at' => 'x', 'updated_at' => 'x'));
// ۲) محصولی در فروشگاه مشتری که همین SKU مقصد را دارد
$other = new WC_Product_Simple();
$other->set_sku('vsp-777');
$other->save();
set_transient('hci_selection_4', array('30' => ig_entry(30, 'hmp-555'), '31' => ig_entry(31, 'hmp-777'), '32' => ig_entry(32, 'hmp-888')), 1800);
ob_start();
HCI_Products::render_review_page();
$html = (string) ob_get_clean();
preg_match_all('/<tr data-id="(\d+)".*?<\/tr>/s', $html, $m);
$rows = array_combine($m[1], $m[0]);
test_assert(strpos($rows['30'], 'تکراری ✕') !== false && strpos($rows['30'], 'قبلاً وارد') !== false, 'SKU تکراری با ردیف imported: ✕ و پیام ساده');
test_assert(strpos($rows['31'], 'تکراری ✕') !== false && strpos($rows['31'], 'همین کد') !== false, 'SKU مقصد موجود در فروشگاه: ✕ و پیام ساده');
test_assert(strpos($rows['30'], 'class="button hci-import-btn"') === false && strpos($rows['31'], 'class="button hci-import-btn"') === false, 'دکمه «وارد کن» برای تکراری‌ها وجود ندارد');
test_assert(strpos($rows['32'], 'class="button hci-import-btn"') !== false && strpos($rows['32'], 'تکراری') === false, 'ردیف سالم دکمه فعال دارد');
test_assert(preg_match('/#\d/', strip_tags($rows['30'] . $rows['31'])) === 0, 'پیام ساده بدون شناسه فنی');

// صف‌بندی (AJAX) هم همان لحظه رد می‌شود، نه بعد از ورود به صف
$_POST = array('source_product_id' => '31', 'source_sku' => 'hmp-777', 'entry_json' => json_encode(ig_entry(31, 'hmp-777')));
try { HCI_Import::ajax_queue_import(); } catch (RuntimeException $e) {}
$resp = $GLOBALS['__test_last_json_response'];
test_assert($resp['success'] === false && !empty($resp['data']['duplicate']), 'ajax_queue_import محصول تکراری را رد می‌کند');
test_assert(HCI_DB::get_map_row(31) === null && count($GLOBALS['__fake_as_single_actions']) === 0, 'ردیفی در صف/جدول ساخته نشد');

// تلاش مجدد خودِ محصول (partial با dest خودش) تکراری حساب نمی‌شود
$w = ig_reset();
$mine = new WC_Product_Simple();
$mine->set_sku('vsp-900');
$mineId = $mine->save();
$w->insert('wp_hci_product_map', array('source_product_id' => 40, 'source_sku' => 'hmp-900', 'import_status' => 'partial', 'dest_product_id' => $mineId, 'dest_sku' => 'vsp-900', 'created_at' => 'x', 'updated_at' => 'x'));
set_transient('hci_selection_4', array('40' => ig_entry(40, 'hmp-900')), 1800);
ob_start();
HCI_Products::render_review_page();
$html = (string) ob_get_clean();
preg_match('/<tr data-id="40".*?<\/tr>/s', $html, $row40);
test_assert(strpos($row40[0], 'class="button hci-import-btn"') !== false && strpos($row40[0], 'تکراری ✕') === false, 'محصول ناقصِ خودمان تکراری نیست و «تلاش مجدد» دارد');

// ----------------------------------------------------------------------------
test_section('S2-09 — لاگ هر مرحله فقط در حالت اشکال‌یابی');

$log = tempnam(sys_get_temp_dir(), 'hcilog');
ini_set('error_log', $log);
$w = ig_reset();
HCI_DB::queue_import(50, 'hmp-50', (string) wp_json_encode(array_merge(ig_entry(50, 'hmp-50'), array('featured_image' => null, 'category_path' => array(), 'stock_status' => 'instock', 'variations' => array()))));
HCI_Import::process_import_action(50);
test_assert(strpos((string) file_get_contents($log), '[HCI Import] product=50 step=') === false, 'در حالت عادی لاگ مرحله‌ای نوشته نمی‌شود');

file_put_contents($log, '');
$w = ig_reset();
update_option('hci_debug_log', '1', false);
HCI_DB::queue_import(51, 'hmp-51', (string) wp_json_encode(array_merge(ig_entry(51, 'hmp-51'), array('featured_image' => null, 'category_path' => array(), 'stock_status' => 'instock', 'variations' => array()))));
HCI_Import::process_import_action(51);
test_assert(strpos((string) file_get_contents($log), '[HCI Import] product=51 step=') !== false, 'با گزینه اشکال‌یابی، لاگ مرحله‌ها نوشته می‌شود');
@unlink($log);

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
