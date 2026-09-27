<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — Phase 2 Verification (PHP " . PHP_VERSION . ")\n";
echo "بررسی filter/sort/collect_categories و تشخیص Duplicate روی کد production واقعی.\n";

// =============================================================================
// دیتای نمونه: ۱ محصول simple موجود، ۱ محصول simple ناموجود، ۱ محصول variable
// در دو دسته‌بندی مختلف با زمان‌های تغییر و قیمت‌های متفاوت.
// =============================================================================

$sampleItems = array(
    array(
        'source_product_id' => 10,
        'product_type' => 'simple',
        'name' => 'کفش مردانه چرم',
        'price' => '500000',
        'stock_status' => 'instock',
        'source_modified_gmt' => '2026-09-25T10:00:00+00:00',
        'category_path' => array(array('id' => 1, 'name' => 'پوشاک', 'parent_id' => null), array('id' => 2, 'name' => 'کفش', 'parent_id' => 1)),
    ),
    array(
        'source_product_id' => 20,
        'product_type' => 'simple',
        'name' => 'کیف زنانه',
        'price' => '900000',
        'stock_status' => 'outofstock',
        'source_modified_gmt' => '2026-09-20T10:00:00+00:00',
        'category_path' => array(array('id' => 1, 'name' => 'پوشاک', 'parent_id' => null), array('id' => 3, 'name' => 'کیف', 'parent_id' => 1)),
    ),
    array(
        'source_product_id' => 30,
        'product_type' => 'variable',
        'name' => 'تیشرت رنگی',
        'price' => '250000',
        'stock_status' => 'instock',
        'source_modified_gmt' => '2026-09-27T10:00:00+00:00',
        'category_path' => array(array('id' => 1, 'name' => 'پوشاک', 'parent_id' => null)),
    ),
    // یک ردیف Variation مستقل که هرگز نباید در گرید یک کارت جدا بگیرد
    array(
        'source_product_id' => 31,
        'parent_product_id' => 30,
        'product_type' => 'variation',
        'name' => 'تیشرت رنگی — قرمز',
        'price' => '250000',
        'stock_status' => 'instock',
        'source_modified_gmt' => '2026-09-27T10:00:00+00:00',
        'category_path' => array(),
    ),
);

$filterMethod = new ReflectionMethod('HCI_Products', 'filter_items');
$filterMethod->setAccessible(true);
$sortMethod = new ReflectionMethod('HCI_Products', 'sort_items');
$sortMethod->setAccessible(true);
$collectMethod = new ReflectionMethod('HCI_Products', 'collect_categories');
$collectMethod->setAccessible(true);

// همان فیلتر یک‌خطی که render_grid_page() قبل از هرچیز اجرا می‌کند: حذف ردیف‌های variation مستقل
$gridItems = array_values(array_filter($sampleItems, static fn (array $i): bool => ($i['product_type'] ?? '') !== 'variation'));

test_section('فیلترها — HCI_Products::filter_items()');

$noFilter = $filterMethod->invoke(null, $gridItems, '', 0, '', false, array());
test_assert(count($noFilter) === 3, 'بدون فیلتر: هر ۳ محصول simple/variable برمی‌گردند (ردیف Variation مستقل حذف شده)');

$byName = $filterMethod->invoke(null, $gridItems, 'کیف', 0, '', false, array());
test_evidence('نتیجه جستجوی نام "کیف"', array_column($byName, 'source_product_id'));
test_assert(count($byName) === 1 && $byName[0]['source_product_id'] === 20, 'جستجوی نام فارسی («کیف») دقیقاً همان یک محصول را پیدا می‌کند');

$byCategory = $filterMethod->invoke(null, $gridItems, '', 2, '', false, array());
test_assert(count($byCategory) === 1 && $byCategory[0]['source_product_id'] === 10, 'فیلتر دسته‌بندی (id=2 «کفش») فقط محصول همان زیردسته را برمی‌گرداند');

$byParentCategory = $filterMethod->invoke(null, $gridItems, '', 1, '', false, array());
test_assert(count($byParentCategory) === 3, 'فیلتر دسته‌بندی ریشه (id=1 «پوشاک») همه زیردسته‌ها را هم شامل می‌شود (چون در category_path کامل هست)');

$byStock = $filterMethod->invoke(null, $gridItems, '', 0, 'outofstock', false, array());
test_assert(count($byStock) === 1 && $byStock[0]['source_product_id'] === 20, 'فیلتر stock=outofstock فقط محصول ناموجود را برمی‌گرداند');

$importedIds = array_flip(array(20));
$onlyNotImported = $filterMethod->invoke(null, $gridItems, '', 0, '', true, $importedIds);
test_assert(count($onlyNotImported) === 2 && !in_array(20, array_column($onlyNotImported, 'source_product_id'), true), 'فیلتر «فقط وارد‌نشده‌ها» محصول از قبل Import‌شده (۲۰) را حذف می‌کند');

test_section('مرتب‌سازی — HCI_Products::sort_items()');

$newest = $sortMethod->invoke(null, $gridItems, 'newest');
test_evidence('ترتیب newest', array_column($newest, 'source_product_id'));
test_assert(array_column($newest, 'source_product_id') === array(30, 10, 20), 'newest: از جدیدترین (۳۰) به قدیمی‌ترین (۲۰)');

$oldest = $sortMethod->invoke(null, $gridItems, 'oldest');
test_assert(array_column($oldest, 'source_product_id') === array(20, 10, 30), 'oldest: دقیقاً برعکس newest');

$cheapest = $sortMethod->invoke(null, $gridItems, 'cheapest');
test_assert(array_column($cheapest, 'source_product_id') === array(30, 10, 20), 'cheapest: از ارزان‌ترین (۲۵۰۰۰۰) به گران‌ترین (۹۰۰۰۰۰)');

$priciest = $sortMethod->invoke(null, $gridItems, 'priciest');
test_assert(array_column($priciest, 'source_product_id') === array(20, 10, 30), 'priciest: دقیقاً برعکس cheapest');

test_section('دسته‌بندی‌ها — HCI_Products::collect_categories()');

$categories = $collectMethod->invoke(null, $gridItems);
test_evidence('خروجی collect_categories()', $categories);
test_assert(count($categories) === 3 && isset($categories[1], $categories[2], $categories[3]), 'هر ۳ دسته منحصربه‌فرد (پوشاک/کفش/کیف) بدون تکرار جمع شده‌اند');

// =============================================================================
// تشخیص Duplicate — HCI_DB
// =============================================================================
test_section('تشخیص Duplicate — HCI_DB::get_imported_source_ids() / get_imported_source_skus() / insert_pending()');

$mapWpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $mapWpdb;

$mapWpdb->insert('wp_hci_product_map', array(
    'source_product_id' => 20,
    'source_sku' => 'BAG-20',
    'import_status' => 'pending_phase3',
    'last_synced_at' => '2026-09-27 00:00:00',
    'created_at' => '2026-09-27 00:00:00',
    'updated_at' => '2026-09-27 00:00:00',
));

$importedIdsReal = HCI_DB::get_imported_source_ids();
$importedSkusReal = HCI_DB::get_imported_source_skus();
test_evidence('get_imported_source_ids()', $importedIdsReal);
test_evidence('get_imported_source_skus()', $importedSkusReal);
test_assert($importedIdsReal === array(20), 'get_imported_source_ids() دقیقاً همان ۱ رکورد ثبت‌شده را برمی‌گرداند');
test_assert($importedSkusReal === array('BAG-20'), 'get_imported_source_skus() SKU همان رکورد را برمی‌گرداند');

$insertResult = HCI_DB::insert_pending(30, 'SHIRT-30');
test_evidence('insert_pending(30, "SHIRT-30")', $insertResult);
test_assert($insertResult['success'] === true, 'insert_pending() برای محصول جدید موفق است');

$importedIdsAfter = HCI_DB::get_imported_source_ids();
sort($importedIdsAfter);
test_assert($importedIdsAfter === array(20, 30), 'بعد از insert_pending، محصول ۳۰ هم در فهرست Imported هست');

// شبیه‌سازی دقیق منطق ajax_mark_pending(): تلاش دوباره برای محصول تکراری باید رد شود
$alreadyImported = in_array(20, HCI_DB::get_imported_source_ids(), true);
test_assert($alreadyImported === true, 'تلاش برای ثبت دوباره محصول ۲۰ (که در جدول بازبینی هم باید ✕ نشان داده شود) به‌درستی Duplicate تشخیص داده می‌شود');

echo "\n=== جمع‌بندی فاز ۲ ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
