<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — حذف ردیف‌ها از بازبینی + متن‌های ساده فارسی (PHP " . PHP_VERSION . ")\n";

function call_ajax_pl(callable $fn): array {
    $GLOBALS['__test_last_json_response'] = null;
    try { $fn(); } catch (RuntimeException $e) { }
    return $GLOBALS['__test_last_json_response'] ?? array();
}

function pl_entry(int $id, string $name): array {
    return array('source_product_id' => $id, 'name' => $name, 'short_description' => '', 'sku' => 'hmp-' . $id, 'price' => '1000',
        'stock_quantity' => 1, 'category_names' => array(), 'featured_image' => null, 'images' => array(), 'product_type' => 'simple', 'variations' => array());
}

test_section('مورد ۱ — ردیف واردشده از جدول بازبینی حذف می‌شود');
hci_test_reset_wc_fakes();
$wpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_current_user_id'] = 3;
$now = '2026-01-01 00:00:00';
foreach (array(10 => HCI_DB::STATUS_IMPORTED, 11 => HCI_DB::STATUS_ERROR, 12 => HCI_DB::STATUS_QUEUED) as $pid => $st) {
    $wpdb->insert('wp_hci_product_map', array('source_product_id' => $pid, 'import_status' => $st, 'created_at' => $now, 'updated_at' => $now));
}
set_transient('hci_selection_3', array('10' => pl_entry(10, 'واردشده'), '11' => pl_entry(11, 'ناموفق'), '12' => pl_entry(12, 'درصف'), '13' => pl_entry(13, 'تازه')), 1800);

ob_start();
HCI_Products::render_review_page();
$html = ob_get_clean();
test_assert(strpos($html, 'data-id="10"') === false && strpos($html, 'واردشده') === false, 'ردیف محصول واردشده در جدول نیست');
test_assert(strpos($html, 'data-id="11"') !== false && strpos($html, 'data-id="12"') !== false && strpos($html, 'data-id="13"') !== false, 'ناموفق، در صف و تازه همچنان هستند');
test_assert(!isset(get_transient('hci_selection_3')['10']) && count(get_transient('hci_selection_3')) === 3, 'ردیف واردشده از انتخاب ذخیره‌شده هم پاک شد');
test_assert(substr_count($html, 'حذف از این لیست') >= 3, 'هر ردیف دکمه «حذف از این لیست» دارد');
test_assert(strpos($html, 'disabled title="این محصول در حال وارد شدن است') !== false, 'دکمه حذفِ ردیف در صف غیرفعال است');

test_section('مورد ۱ — حذف دستی ردیف');
$_POST = array('source_product_id' => 13);
$resp = call_ajax_pl(fn () => HCI_Products::ajax_remove_review_row());
test_assert(($resp['success'] ?? false) === true, 'حذف ردیف وارد‌نشده موفق است');
$sel = get_transient('hci_selection_3');
test_assert(!isset($sel['13']) && isset($sel['11']) && isset($sel['12']), 'فقط همان ردیف پاک شد، بقیه دست‌نخورده');
test_assert(HCI_DB::get_map_row(11) !== null, 'جدول ثبت‌شده محصولات دست نخورد');
$_POST = array('source_product_id' => 12);
$resp = call_ajax_pl(fn () => HCI_Products::ajax_remove_review_row());
test_assert(($resp['success'] ?? true) === false && isset(get_transient('hci_selection_3')['12']), 'ردیف در صف حذف نمی‌شود و پیام روشن می‌دهد');
$_POST = array();

test_section('مورد ۲ — صفحه بروزرسانی');
test_assert(HCI_Sync::format_friendly_time(strtotime('2026-05-10 05:31:00 UTC'), strtotime('2026-05-10 12:00:00 UTC')) === 'امروز ساعت ۰۹:۰۱', 'امروز ساعت ۰۹:۰۱ به وقت تهران');
test_assert(HCI_Sync::format_friendly_time(strtotime('2026-05-09 02:30:00 UTC'), strtotime('2026-05-10 12:00:00 UTC')) === 'دیروز ساعت ۰۶:۰۰', 'دیروز ساعت ۰۶:۰۰');
test_assert(HCI_Sync::format_friendly_time(strtotime('2026-05-11 02:30:00 UTC'), strtotime('2026-05-10 12:00:00 UTC')) === 'فردا ساعت ۰۶:۰۰', 'فردا ساعت ۰۶:۰۰');
$lines = HCI_Sync::describe_changes(array('success' => true, 'price_updated' => 3, 'stock_updated' => 1, 'deactivated' => 0, 'errors' => 0));
test_assert($lines === array('۳ محصول قیمتشان تغییر کرد.', '۱ محصول موجودی‌اش تغییر کرد.'), 'خلاصه تغییرات به فارسی ساده');
test_assert(HCI_Sync::describe_changes(array('success' => true))[0] === 'در آخرین بروزرسانی چیزی برای تغییر نبود.', 'حالت بدون تغییر');

$GLOBALS['__test_options']['hci_sync_cursor_gmt'] = '2026-05-10 05:31:00';
ob_start();
HCI_Sync::render_page();
$page = ob_get_clean();
$visible = preg_replace('#<div id="hci-adv-box".*?</div>#s', '', $page);
foreach (array('Sync Now', 'Cursor', 'Action Scheduler', 'WP-CLI', 'Cron', 'نوع اجرا') as $bad) {
    test_assert(strpos($visible, $bad) === false, "کلمه فنی «{$bad}» در بخش عادی صفحه نیست");
}
test_assert(strpos($visible, 'UTC') === false, 'UTC در بخش عادی صفحه نیست');
test_assert(strpos($page, 'بروزرسانی همین الان') !== false && strpos($page, 'تعداد محصولات وارد‌شده از هی‌مد') !== false, 'دکمه ساده و شمارش محصولات هست');
test_assert(strpos($page, 'id="hci-adv-box" style="display:none') !== false, 'بخش پیشرفته پیش‌فرض بسته است');

test_section('مورد ۳ — پیام و صفحه پاک کردن اطلاعات');
$msg = HCI_Admin::describe_reset_result(array('rows_cleared' => 48, 'queue_cleared' => 12, 'cache_cleared' => 3, 'selection_cleared' => 1));
test_assert($msg === '۴۸ محصول ثبت‌شده پاک شد. ۱۲ محصولِ در انتظار وارد شدن هم لغو شد.', 'پیام نتیجه به فارسی ساده');
test_assert(strpos(HCI_Admin::describe_reset_result(array('rows_cleared' => 0)), 'وجود نداشت') !== false, 'وقتی چیزی نبود صریح گفته می‌شود');
$m = new ReflectionMethod('HCI_Admin', 'render_reset_tab'); $m->setAccessible(true);
ob_start(); $m->invoke(null); $tab = ob_get_clean();
foreach (array('wp_hci_product_map', 'Action Scheduler', 'Transient', 'Rate Limit', 'Cursor') as $bad) {
    test_assert(strpos($tab, $bad) === false, "کلمه فنی «{$bad}» در صفحه پاک کردن نیست");
}
test_assert(strpos($tab, 'پاک کردن اطلاعات ثبت‌شده در این پلاگین') !== false && strpos($tab, 'قابل بازگشت نیست') !== false, 'عنوان و هشدار مطابق متن درخواستی');

echo "\n=== جمع‌بندی ===\nPASS: {$GLOBALS['__test_passes']}\nFAIL: {$GLOBALS['__test_failures']}\n";
exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
