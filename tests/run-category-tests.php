<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — دسته‌بندی: تطبیق «نام دقیق + Parent» و خطای ساخت دسته (S2-04، S2-05) (PHP " . PHP_VERSION . ")\n";

function ct_node(int $id, string $name, ?int $parent): array {
    return array('id' => $id, 'name' => $name, 'slug' => '', 'parent_id' => $parent);
}

function ct_terms_named(string $name): array {
    return array_keys(array_filter($GLOBALS['__fake_wc_terms']['product_cat'] ?? array(), static fn (array $t): bool => $t['name'] === $name));
}

test_section('S2-05 — دسته ریشه با نام یکسان به دسته‌ی هم‌نامِ فرزند (زیر والد دیگر) وصل نمی‌شود');

hci_test_reset_wc_fakes();
$a = HCI_Import::resolve_category_hierarchy(array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1)));
$shoeUnderClothes = $a[0];
$b = HCI_Import::resolve_category_hierarchy(array(ct_node(9, 'کفش', null)));
test_assert($b[0] !== $shoeUnderClothes, 'ریشه‌ی «کفش» دسته‌ی «کفش» زیر «پوشاک» نیست');
test_assert($GLOBALS['__fake_wc_terms']['product_cat'][$b[0]]['parent'] === 0, 'دسته جدید واقعاً ریشه است');
test_assert(count(ct_terms_named('کفش')) === 2, 'دو دسته‌ی هم‌نام زیر دو والد متفاوت وجود دارد');
$again = HCI_Import::resolve_category_hierarchy(array(ct_node(9, 'کفش', null)));
test_assert($again[0] === $b[0] && count(ct_terms_named('کفش')) === 2, 'بار دوم همان ریشه پیدا می‌شود، تکراری ساخته نمی‌شود');
$again2 = HCI_Import::resolve_category_hierarchy(array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1)));
test_assert($again2[0] === $shoeUnderClothes, 'مسیر پوشاک > کفش همچنان همان دسته‌ی قبلی را پیدا می‌کند');

test_section('S2-04 — خطای ساخت دسته: فرزند هرگز زیر والد اشتباه نمی‌رود و محصول به دسته‌ی والد نمی‌افتد');

hci_test_reset_wc_fakes();
$GLOBALS['__fake_wp_insert_term_fail'] = array('کفش ورزشی');
$r = HCI_Import::resolve_category_hierarchy_checked(array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1), ct_node(3, 'کفش ورزشی', 2), ct_node(4, 'دویدن', 3)));
test_evidence('نتیجه', $r);
test_assert($r['ids'] === array() && strpos((string) $r['error'], 'کفش ورزشی') !== false, 'خطا با نام دسته‌ی مشکل‌دار گزارش شد و هیچ دسته‌ای به محصول داده نشد');
test_assert(ct_terms_named('دویدن') === array(), 'نوه‌ی دسته‌ی خراب، به‌جای ریشه یا والد اشتباه ساخته نشد');
test_assert(ct_terms_named('کفش ورزشی') === array(), 'دسته‌ی خراب ساخته نشده است');

// مسیر سالم همان رفتار قبل را دارد
hci_test_reset_wc_fakes();
$ok = HCI_Import::resolve_category_hierarchy_checked(array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1)));
test_assert($ok['error'] === null && count($ok['ids']) === 1, 'مسیر سالم بدون خطا و با شناسه‌ی برگ');

// یکپارچه با Import: محصول partial با پیام روشن، در دسته‌ی پیش‌فرض
hci_test_reset_wc_fakes();
$GLOBALS['wpdb'] = hci_test_create_product_map_db();
$GLOBALS['__test_options'] = array();
HCI_Pricing::save(1000.0, 10.0, 'publish');
$GLOBALS['__fake_wp_insert_term_fail'] = array('کفش');
HCI_DB::queue_import(300, 'hmp-300', (string) wp_json_encode(array('name' => 'کتانی', 'short_description' => '', 'featured_image' => null, 'images' => array(), 'category_path' => array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1)), 'product_type' => 'simple', 'sku' => 'hmp-300', 'price' => '5000', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'variations' => array())));
HCI_Import::process_import_action(300);
$row = HCI_DB::get_map_row(300);
test_assert($row['import_status'] === HCI_DB::STATUS_PARTIAL && strpos((string) $row['error_message'], 'دسته') !== false, 'Import partial با پیام درباره‌ی دسته');
test_assert(wc_get_product((int) $row['dest_product_id'])->get_category_ids() === array(), 'محصول به دسته‌ی «پوشاک» (والد) نرفت');
$GLOBALS['__fake_wp_insert_term_fail'] = array();
HCI_DB::queue_import(300, 'hmp-300', (string) wp_json_encode(array('name' => 'کتانی', 'short_description' => '', 'featured_image' => null, 'images' => array(), 'category_path' => array(ct_node(1, 'پوشاک', null), ct_node(2, 'کفش', 1)), 'product_type' => 'simple', 'sku' => 'hmp-300', 'price' => '5000', 'stock_quantity' => 1.0, 'stock_status' => 'instock', 'variations' => array())));
HCI_Import::process_import_action(300);
$row = HCI_DB::get_map_row(300);
test_assert($row['import_status'] === HCI_DB::STATUS_IMPORTED && count(wc_get_product((int) $row['dest_product_id'])->get_category_ids()) === 1, 'تلاش مجدد بعد از رفع مشکل کامل می‌شود');

echo "\n=== جمع‌بندی ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
