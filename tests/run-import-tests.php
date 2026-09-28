<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — Import Engine (PHP " . PHP_VERSION . ")\n";
echo "SKU، Duplicate سه‌لایه، سلسله‌مراتب دسته، Import Simple/Variable، Partial+Retry — روی کد production واقعی.\n";

// =============================================================================
// تست ۱ — تبدیل SKU (hmp → vsp)
// =============================================================================
test_section('تست ۱ — HCI_Import::transform_sku()');

test_assert(HCI_Import::transform_sku('hmp-12345') === 'vsp-12345', 'hmp-12345 → vsp-12345');
test_assert(HCI_Import::transform_sku('HMP-999') === 'vsp-999', 'تطبیق پیشوند بدون حساسیت به بزرگی/کوچکی حروف، خروجی همیشه vsp با حروف کوچک');
test_assert(HCI_Import::transform_sku('ABC-1') === 'ABC-1', 'SKU بدون پیشوند hmp کاملاً دست‌نخورده می‌ماند');
test_assert(HCI_Import::transform_sku(null) === null, 'null → null (بدون SKU مجزا)');
test_assert(HCI_Import::transform_sku('') === null, 'رشته خالی → null');

// =============================================================================
// تست ۲ — Duplicate سه‌لایه
// =============================================================================
test_section('تست ۲ — HCI_Import::check_duplicate(): سه لایه');

hci_test_reset_wc_fakes();
$mapWpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $mapWpdb;

$mapWpdb->insert('wp_hci_product_map', array(
    'source_product_id' => 100,
    'source_sku' => 'SKU-A',
    'dest_product_id' => 5000,
    'dest_sku' => 'vsp-100',
    'import_status' => 'imported',
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
));
$row1 = HCI_DB::get_map_row(100);
$row1Id = (int) $row1['id'];

$dup1 = HCI_Import::check_duplicate(999999, 100, null, null, 0);
test_evidence('لایه ۱ — همان source_product_id، رکورد دیگری imported است', $dup1);
test_assert($dup1 !== null && str_contains($dup1, '5000'), 'لایه ۱: source_product_id تکراری با رکورد دیگر تشخیص داده می‌شود');

$dupSelf = HCI_Import::check_duplicate($row1Id, 100, 'SKU-A', 'vsp-100', 5000);
test_assert($dupSelf === null, 'بررسی خودِ همان ردیف (id واقعی‌اش Exclude شده) هرگز Duplicate خودش تلقی نمی‌شود — این دقیقاً همان سناریوی Retry است');

$dup2 = HCI_Import::check_duplicate(999999, 300, 'SKU-A', null, 0);
test_evidence('لایه ۲ — source_product_id متفاوت (300) ولی source_sku یکسان', $dup2);
test_assert($dup2 !== null, 'لایه ۲: source_sku تکراری با محصول منبع کاملاً متفاوت هم تشخیص داده می‌شود');

$GLOBALS['__fake_wc_sku_index']['vsp-999'] = 7777; // شبیه‌سازی یک محصول واقعی ساخته‌شده خارج از این پلاگین
$dup3 = HCI_Import::check_duplicate(999999, 400, null, 'vsp-999', 0);
test_evidence('لایه ۳ — SKU مقصد از قبل روی یک محصول واقعی ووکامرس هست (خارج از جدول ردیابی)', $dup3);
test_assert($dup3 !== null && str_contains($dup3, '7777'), 'لایه ۳: wc_get_product_id_by_sku تداخل خارجی را تشخیص می‌دهد');

$dup3Retry = HCI_Import::check_duplicate(999999, 400, null, 'vsp-999', 7777);
test_assert($dup3Retry === null, 'لایه ۳ در حالت Retry: اگر SKU مقصد متعلق به همان dest_product_id فعلی خودمان باشد (تلاش قبلی)، Duplicate تشخیص داده نمی‌شود');

// =============================================================================
// تست ۳ — سلسله‌مراتب دسته‌بندی: تطبیق نام+Parent، بدون تکرار
// =============================================================================
test_section('تست ۳ — HCI_Import::resolve_category_hierarchy()');

hci_test_reset_wc_fakes();

$leaf1 = HCI_Import::resolve_category_hierarchy(array('پوشاک', 'کفش'));
test_assert(count($leaf1) === 1, 'فقط شناسه برگ برمی‌گردد');
$leaf1Id = $leaf1[0];
test_assert(count($GLOBALS['__fake_wc_terms']['product_cat'] ?? array()) === 2, 'دقیقاً ۲ Term (ریشه+برگ) ساخته شده');

$leaf1Again = HCI_Import::resolve_category_hierarchy(array('پوشاک', 'کفش'));
test_assert($leaf1Again[0] === $leaf1Id, 'صدازدن دوباره با همان نام‌ها همان Term موجود را برمی‌گرداند، نه یک کپی جدید');
test_assert(count($GLOBALS['__fake_wc_terms']['product_cat'] ?? array()) === 2, 'هنوز فقط ۲ Term — هیچ تکراری ساخته نشد');

$leaf2 = HCI_Import::resolve_category_hierarchy(array('پوشاک بچه', 'کفش'));
test_assert($leaf2[0] !== $leaf1Id, 'نام "کفش" زیر یک Parent متفاوت ("پوشاک بچه")، Term تازه می‌سازد — تطبیق دقیقاً نام+Parent است، نه فقط نام');
test_assert(count($GLOBALS['__fake_wc_terms']['product_cat'] ?? array()) === 4, 'حالا ۴ Term: پوشاک، کفش(زیر پوشاک)، پوشاک بچه، کفش(زیر پوشاک بچه)');

// =============================================================================
// تست ۴ — Import کامل محصول Simple (شامل فرمول قیمت واقعی + Stock + وضعیت نهایی)
// =============================================================================
test_section('تست ۴ — Import محصول Simple تا انتها (با Stub برای WooCommerce)');

hci_test_reset_wc_fakes();
$mapWpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $mapWpdb;
HCI_Pricing::save(1000.0, 10.0, 'publish'); // فرمول واقعی: (raw+1000)×1.10 ، وضعیت نهایی publish

$simplePayload = array(
    'name' => 'محصول تستی ساده',
    'short_description' => 'توضیح کوتاه',
    'featured_image' => 'https://example.test/f.jpg',
    'images' => array('https://example.test/g1.jpg'),
    'category_names' => array('آرایشی'),
    'product_type' => 'simple',
    'sku' => 'hmp-500',
    'price' => '100000',
    'stock_quantity' => 5,
    'variations' => array(),
);
HCI_DB::queue_import(500, 'hmp-500', (string) wp_json_encode($simplePayload));
HCI_Import::process_import_action(500);

$row4 = HCI_DB::get_map_row(500);
test_evidence('ردیف ردیابی بعد از Import محصول Simple', $row4);
test_assert($row4['import_status'] === HCI_DB::STATUS_IMPORTED, 'وضعیت نهایی imported است');
test_assert($row4['dest_sku'] === 'vsp-500', 'dest_sku با قانون hmp→vsp درست ذخیره شده');

$product4 = wc_get_product((int) $row4['dest_product_id']);
test_assert($product4 !== false, 'محصول واقعاً در (Fake) ووکامرس ساخته شده');
test_assert($product4->get_status() === 'publish', 'چون تصاویر موفق بودند، وضعیت نهایی همان تنظیم پیش‌فرض (publish) اعمال شده — نه Draft باقی‌مانده');
test_assert($product4->get_name() === 'محصول تستی ساده', 'نام درست ست شده');
test_assert($product4->get_sku() === 'vsp-500', 'SKU روی خودِ محصول هم vsp-500 است');
$expectedPrice4 = (string) ((100000.0 + 1000.0) * 1.10);
test_assert($product4->get_regular_price() === $expectedPrice4, 'قیمت دقیقاً با فرمول سراسری HCI_Pricing محاسبه شده: ' . $expectedPrice4);
test_assert((float) $product4->get_stock_quantity() === 5.0 && $product4->get_stock_status() === 'instock', 'موجودی و وضعیت انبار دقیقاً از مقدار خام مبدا آمده');
test_assert($product4->get_image_id() > 0, 'تصویر اصلی (Featured) دانلود و ست شده');
test_assert(count($product4->get_gallery_image_ids()) === 1, 'یک تصویر گالری هم دانلود و ست شده');
test_assert(!empty($product4->get_category_ids()), 'دسته‌بندی ست شده');

// =============================================================================
// تست ۵ — Import کامل محصول Variable (Attribute محلی + هر Variation جدا)
// =============================================================================
test_section('تست ۵ — Import محصول Variable با ۲ Variation (یکی با SKU، یکی بدون)');

HCI_Pricing::save(0.0, 0.0, 'draft'); // فرمول خنثی برای محاسبات ساده در این تست

$variablePayload = array(
    'name' => 'تیشرت متغیر',
    'short_description' => '',
    'featured_image' => null,
    'images' => array(),
    'category_names' => array(),
    'product_type' => 'variable',
    'sku' => 'hmp-600',
    'price' => null,
    'stock_quantity' => null,
    'variations' => array(
        array('variation_id' => 601, 'attributes' => array(array('name' => 'رنگ', 'option' => 'مشکی')), 'price' => '50000', 'stock_quantity' => 3, 'sku' => 'hmp-601'),
        array('variation_id' => 602, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '55000', 'stock_quantity' => 0, 'sku' => null),
    ),
);
HCI_DB::queue_import(600, 'hmp-600', (string) wp_json_encode($variablePayload));
HCI_Import::process_import_action(600);

$row5 = HCI_DB::get_map_row(600);
test_assert($row5['import_status'] === HCI_DB::STATUS_IMPORTED, 'Parent محصول Variable با موفقیت imported شد');

$parent5 = wc_get_product((int) $row5['dest_product_id']);
test_assert($parent5->get_type() === 'variable', 'نوع محصول ساخته‌شده واقعاً variable است');
test_assert($parent5->get_sku() === 'vsp-600', 'SKU خودِ Parent هم تبدیل شده');
test_assert(count($parent5->get_attributes()) === 1, 'دقیقاً یک Attribute محلی («رنگ») روی Parent ساخته شده');

$var1Map = HCI_DB::get_map_row(600, 601);
test_assert($var1Map !== null && $var1Map['import_status'] === HCI_DB::STATUS_IMPORTED, 'ردیف ردیابی Variation اول (601) ساخته و imported شده');
$var1 = wc_get_product((int) $var1Map['dest_variation_id']);
test_assert($var1->get_sku() === 'vsp-601', 'Variation دارای SKU مجزا، درست تبدیل و ست شده');
test_assert((float) $var1->get_regular_price() === 50000.0 && (float) $var1->get_stock_quantity() === 3.0, 'قیمت/موجودی مخصوص همین Variation درست است');

$var2Map = HCI_DB::get_map_row(600, 602);
$var2 = wc_get_product((int) $var2Map['dest_variation_id']);
test_evidence('Variation دوم (بدون SKU مجزا در منبع)', array('sku' => $var2->get_sku(), 'stock_status' => $var2->get_stock_status()));
test_assert($var2->get_sku() === '', 'Variation بدون SKU مجزا در منبع، هیچ SKUای نمی‌گیرد — SKU مشترک Parent هرگز روی آن کپی نمی‌شود');
test_assert($var2->get_stock_status() === 'outofstock', 'موجودی صفر → outofstock');

// =============================================================================
// تست ۶ — شکست جزئی تصویر + Retry بدون ساخت محصول تکراری
// =============================================================================
test_section('تست ۶ — شکست دانلود تصویر → partial → Retry روی همان محصول (نه محصول جدید)');

hci_test_reset_wc_fakes();
$mapWpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $mapWpdb;
HCI_Pricing::save(0.0, 0.0, 'draft');

$brokenImageUrl = 'https://example.test/broken.jpg';
hci_test_fail_image_download($brokenImageUrl);

$partialPayload = array(
    'name' => 'محصول با تصویر خراب',
    'short_description' => '',
    'featured_image' => $brokenImageUrl,
    'images' => array(),
    'category_names' => array(),
    'product_type' => 'simple',
    'sku' => 'hmp-700',
    'price' => '20000',
    'stock_quantity' => 2,
    'variations' => array(),
);
HCI_DB::queue_import(700, 'hmp-700', (string) wp_json_encode($partialPayload));
HCI_Import::process_import_action(700);

$row6 = HCI_DB::get_map_row(700);
test_evidence('ردیف بعد از شکست دانلود تصویر', $row6);
test_assert($row6['import_status'] === HCI_DB::STATUS_PARTIAL, 'وضعیت partial ثبت شده (نه error کامل، نه imported)');
test_assert(!empty($row6['dest_product_id']), 'با وجود شکست، dest_product_id ذخیره شده تا Retry بتواند ادامه دهد');
$firstDestId = (int) $row6['dest_product_id'];
$productAfterFail = wc_get_product($firstDestId);
test_assert($productAfterFail->get_status() === 'draft', 'محصول Draft باقی مانده — هرگز منتشر نشده چون کامل موفق نبوده');

$productCountBefore = count($GLOBALS['__fake_wc_products']);

// تصویر را "درست" می‌کنیم و همان محصول را Retry می‌کنیم
$GLOBALS['__fake_media_sideload_fail_urls'] = array();
HCI_Import::process_import_action(700);

$row6b = HCI_DB::get_map_row(700);
test_evidence('ردیف بعد از Retry موفق', $row6b);
test_assert($row6b['import_status'] === HCI_DB::STATUS_IMPORTED, 'بعد از رفع مشکل و Retry، وضعیت imported شده');
test_assert((int) $row6b['dest_product_id'] === $firstDestId, 'دقیقاً همان محصول قبلی (dest_product_id یکسان) تکمیل شده، نه یک محصول جدید');
test_assert(count($GLOBALS['__fake_wc_products']) === $productCountBefore, 'هیچ محصول تکراری در (Fake) ووکامرس ساخته نشده — تعداد کل محصولات قبل/بعد از Retry یکسان است');

echo "\n=== جمع‌بندی Import Engine ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
