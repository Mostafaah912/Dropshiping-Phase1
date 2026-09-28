<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — چرخه انتخاب/بازبینی (PHP " . PHP_VERSION . ")\n";
echo "مورد ۱ (دکمه «اضافه» محصول را به جدول بازبینی نمی‌رساند) و مورد ۳ (انتخاب‌های قدیمی/کش) — روی کد Production واقعی.\n";

// =============================================================================
// تست ۱ — بازتولید علت واقعی باگ «اضافه در مودال محصول را وارد جدول بازبینی
// نمی‌کند»: قبل از این اصلاح، HCI_Products::render_grid_page() از
// get_imported_source_ids() (هر ردیفی با هر وضعیتی) استفاده می‌کرد؛ یعنی
// همین که یک محصول فقط Queue می‌شد (نه حتی هنوز واقعاً Import)، کارت آن در
// گرید برای همیشه غیرفعال می‌شد و کارمند دیگر هرگز نمی‌توانست از «بفروشش»
// دوباره انتخابش کند — حتی اگر همان Import شکست خورده/گیر کرده بود (مورد ۴).
// =============================================================================
test_section('تست ۱ — HCI_DB::get_grid_locked_source_ids(): فقط imported/queued/processing قفل می‌کنند');

hci_test_reset_wc_fakes();
$wpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['__test_options'] = array();

$rows = array(
    array('source_product_id' => 601, 'import_status' => HCI_DB::STATUS_IMPORTED),
    array('source_product_id' => 602, 'import_status' => HCI_DB::STATUS_QUEUED),
    array('source_product_id' => 603, 'import_status' => HCI_DB::STATUS_PROCESSING),
    array('source_product_id' => 604, 'import_status' => HCI_DB::STATUS_ERROR),
    array('source_product_id' => 605, 'import_status' => HCI_DB::STATUS_PARTIAL),
    array('source_product_id' => 606, 'import_status' => HCI_DB::STATUS_DUPLICATE),
);
foreach ($rows as $row) {
    $wpdb->insert('wp_hci_product_map', array_merge($row, array(
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    )));
}

$locked = HCI_DB::get_grid_locked_source_ids();
sort($locked);
$tracked = HCI_DB::get_imported_source_ids();
sort($tracked);

test_evidence('get_grid_locked_source_ids()', $locked);
test_evidence('get_imported_source_ids() (همه وضعیت‌ها، بدون تغییر)', $tracked);

test_assert($locked === array(601, 602, 603), 'فقط imported/queued/processing کارت گرید را قفل می‌کنند — خطا/Partial/Duplicate اجازه انتخاب دوباره می‌دهند');
test_assert($tracked === array(601, 602, 603, 604, 605, 606), 'get_imported_source_ids() قدیمی دست‌نخورده مانده (هر ۶ ردیف، برای پاک‌سازی localStorage لازم است)');
test_assert(!in_array(604, $locked, true), 'رگرسیون دقیقاً همینجا بود: محصول Error‌شده (۶۰۴) دیگر برای همیشه قفل گرید نمی‌ماند');
test_assert(!in_array(605, $locked, true), 'محصول Partial (۶۰۵) هم دیگر قفل گرید نمی‌ماند');

// =============================================================================
// تست ۲ — زنجیره کامل سروری: انتخاب (همان ساختاری که JS واقعی — تست‌شده در
// tests/js/run-grid-modal-tests.js — در selection_json می‌فرستد) → Transient
// (دقیقاً کاری که handle_next_step() می‌کند) → render_review_page() → ردیف
// با ویرایش‌های کارمند واقعاً در جدول بازبینی ظاهر می‌شود.
// =============================================================================
test_section('تست ۲ — انتخاب واقعاً به جدول بازبینی می‌رسد (زنجیره کامل سمت سرور)');

hci_test_reset_wc_fakes();
$wpdb2 = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb2;
$GLOBALS['__test_current_user_id'] = 7;

$selectionFromClient = array(
    '321' => array(
        'source_product_id' => 321,
        'name' => 'نام ویرایش‌شده کارمند',
        'short_description' => 'توضیح ویرایش‌شده کارمند',
        'sku' => 'hmp-321',
        'price' => '50000',
        'stock_quantity' => 3,
        'category_names' => array('دسته'),
        'featured_image' => 'https://source.invalid/f.jpg',
        'images' => array('https://source.invalid/f.jpg'),
        'product_type' => 'simple',
        'variations' => array(),
    ),
);

$reflection = new ReflectionClass('HCI_Products');
$selectionKeyMethod = $reflection->getMethod('selection_key');
$selectionKeyMethod->setAccessible(true);
$key = $selectionKeyMethod->invoke(null);
test_assert($key === 'hci_selection_7', 'selection_key() از get_current_user_id() واقعی استفاده می‌کند (کلید مختص کاربر ۷)');

set_transient($key, $selectionFromClient, 1800);

ob_start();
HCI_Products::render_review_page();
$html = ob_get_clean();

test_assert(strpos($html, 'چیزی برای بازبینی انتخاب نشده') === false, 'صفحه بازبینی حالت خالی نشان نمی‌دهد — یعنی selection درست خوانده شده');
test_assert(strpos($html, 'نام ویرایش‌شده کارمند') !== false, 'نام ویرایش‌شده کارمند واقعاً در HTML جدول بازبینی ظاهر شده');
test_assert(strpos($html, 'data-id="321"') !== false, 'ردیف با همان source_product_id درست ساخته شده');
test_assert(strpos($html, 'وارد کن') !== false, 'دکمه «وارد کن» فعال است (محصول تازه، هنوز هیچ رکوردی ندارد)');

// =============================================================================
// تست ۳ — همان زنجیره، اما این‌بار محصول قبلاً خطا خورده (STATUS_ERROR) —
// باید در جدول بازبینی با دکمه «تلاش مجدد» (نه غیرفعال) ظاهر شود.
// =============================================================================
test_section('تست ۳ — محصولی که قبلاً Error خورده، در بازبینی «تلاش مجدد» را نشان می‌دهد نه غیرفعال');

$wpdb2->insert('wp_hci_product_map', array(
    'source_product_id' => 321,
    'source_sku' => 'hmp-321',
    'import_status' => HCI_DB::STATUS_ERROR,
    'error_message' => 'خطای شبیه‌سازی‌شده',
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));

ob_start();
HCI_Products::render_review_page();
$html2 = ob_get_clean();

test_assert(strpos($html2, 'تلاش مجدد') !== false, 'دکمه «تلاش مجدد» نشان داده می‌شود');
test_assert(strpos($html2, '<button type="button" class="button" disabled>') === false, 'دکمه Import غیرفعال نیست — کارمند می‌تواند دوباره تلاش کند');

// =============================================================================
// تست ۴ — مورد ۸: textarea توضیح کوتاه (هم در جدول بازبینی، هم در مودال)
// باید dir="ltr" داشته باشد تا تگ‌های HTML توضیح مبدا در نمای راست‌به‌چپ
// به‌هم نریزند.
// =============================================================================
test_section('تست ۴ — textarea توضیح کوتاه چپ‌به‌راست است (مورد ۸)');

test_assert(strpos($html2, 'class="hci-review-desc" rows="2" dir="ltr"') !== false, 'textarea توضیح کوتاه جدول بازبینی dir="ltr" دارد');

$modalReflection = new ReflectionClass('HCI_Products');
$modalMethod = $modalReflection->getMethod('render_modal');
$modalMethod->setAccessible(true);
ob_start();
$modalMethod->invoke(null);
$modalHtml = ob_get_clean();
test_assert(strpos($modalHtml, 'id="hci-modal-desc" rows="4" dir="ltr"') !== false, 'textarea توضیح کوتاه مودال «بفروشش» هم dir="ltr" دارد');

echo "\n=== جمع‌بندی چرخه انتخاب/بازبینی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
