<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — قاعده موجودی مشترک (PHP " . PHP_VERSION . ")\n";
echo "مورد ۵: hmp-4783 مبدا instock (مدیریت موجودی خاموش) نباید در مقصد ناموجود (۰) شود — روی کد Production واقعی.\n";

// =============================================================================
// تست ۱ — HCI_Import::apply_stock() چهار حالت، روی یک محصول Simple تازه
// (WC_Product_Simple با manage_stock=false و stock_status='outofstock' پیش‌فرض).
// =============================================================================
test_section('تست ۱ — HCI_Import::apply_stock(): هر ۴ حالت روی محصول Simple');

$p1 = new WC_Product_Simple();
$changed1 = HCI_Import::apply_stock($p1, 7.0, 'instock');
test_evidence('quantity=7 (>0)', array('manage_stock' => $p1->get_manage_stock(), 'quantity' => $p1->get_stock_quantity(), 'status' => $p1->get_stock_status(), 'changed' => $changed1));
test_assert($p1->get_manage_stock() === true && (float) $p1->get_stock_quantity() === 7.0 && $p1->get_stock_status() === 'instock', 'عدد مشخص >0 ⇒ manage_stock=true، همان عدد، instock');
test_assert($changed1 === true, 'تغییر واقعی گزارش شد');

$p2 = new WC_Product_Simple();
$changed2 = HCI_Import::apply_stock($p2, 0.0, 'instock');
test_evidence('quantity=0', array('manage_stock' => $p2->get_manage_stock(), 'quantity' => $p2->get_stock_quantity(), 'status' => $p2->get_stock_status()));
test_assert($p2->get_manage_stock() === true && (float) $p2->get_stock_quantity() === 0.0 && $p2->get_stock_status() === 'outofstock', 'عدد دقیقاً 0 ⇒ manage_stock=true، 0، outofstock — حتی اگر مبدا instock گفته باشد (عدد صفر خودش قطعی است)');

// =============================================================================
// این دقیقاً همان محصول گزارش‌شده در تست واقعی است: hmp-4783 → vsp-4783.
// مبدا: مدیریت موجودی خاموش (stock_quantity=NULL) ولی stock_status='instock'.
// رفتار قدیم: quantity را 0 فرض می‌کرد ⇒ outofstock (باگ). رفتار درست:
// manage_stock=false و stock_status دقیقاً همان چیزی که مبدا گفته (instock).
// =============================================================================
$p3 = new WC_Product_Simple();
$changed3 = HCI_Import::apply_stock($p3, null, 'instock');
test_evidence('hmp-4783: quantity=NULL، stock_status مبدا=instock', array('manage_stock' => $p3->get_manage_stock(), 'quantity' => $p3->get_stock_quantity(), 'status' => $p3->get_stock_status()));
test_assert($p3->get_manage_stock() === false, 'رگرسیون دقیقاً همین‌جا بود: مدیریت موجودی روشن نمی‌شود چون مبدا اصلاً عددی مدیریت نمی‌کند');
test_assert($p3->get_stock_status() === 'instock', 'محصول instock می‌ماند — دیگر به‌اشتباه ناموجود (۰) نمی‌شود');

$p4 = new WC_Product_Simple();
HCI_Import::apply_stock($p4, null, 'outofstock');
test_evidence('quantity=NULL، stock_status مبدا=outofstock', array('manage_stock' => $p4->get_manage_stock(), 'status' => $p4->get_stock_status()));
test_assert($p4->get_manage_stock() === false && $p4->get_stock_status() === 'outofstock', 'وقتی مبدا خودش صریحاً outofstock گفته (نه فقط عدد)، همان منتقل می‌شود — نه instock حدسی');

// =============================================================================
// تست ۲ — همین قاعده برای Variation هم عیناً برقرار است.
// =============================================================================
test_section('تست ۲ — همان قاعده روی WC_Product_Variation');

$v1 = new WC_Product_Variation();
HCI_Import::apply_stock($v1, null, 'instock');
test_assert($v1->get_manage_stock() === false && $v1->get_stock_status() === 'instock', 'Variation با moved quantity=NULL و مبدا instock، manage_stock=false و instock می‌ماند');

$v2 = new WC_Product_Variation();
HCI_Import::apply_stock($v2, 3.0, 'instock');
test_assert($v2->get_manage_stock() === true && (float) $v2->get_stock_quantity() === 3.0 && $v2->get_stock_status() === 'instock', 'Variation با quantity=3، دقیقاً مثل Simple مدیریت می‌شود');

// =============================================================================
// تست ۳ — زنجیره کامل Import واقعی برای hmp-4783 → vsp-4783 (Simple).
// =============================================================================
test_section('تست ۳ — Import سرتاسری محصول hmp-4783 (مدیریت موجودی خاموش، instock)');

hci_test_reset_wc_fakes();
$wpdb = hci_test_create_product_map_db();
$GLOBALS['wpdb'] = $wpdb;
HCI_Pricing::save(0.0, 0.0, 'publish');

$payload4783 = array(
    'name' => 'محصول ۴۷۸۳',
    'short_description' => '',
    'featured_image' => null,
    'images' => array(),
    'category_path' => array(),
    'product_type' => 'simple',
    'sku' => 'hmp-4783',
    'price' => '10000',
    'stock_quantity' => null,
    'stock_status' => 'instock',
    'variations' => array(),
);
HCI_DB::queue_import(4783, 'hmp-4783', (string) wp_json_encode($payload4783));
HCI_Import::process_import_action(4783);

$row4783 = HCI_DB::get_map_row(4783);
test_evidence('ردیف ردیابی بعد از Import', $row4783);
test_assert($row4783['import_status'] === HCI_DB::STATUS_IMPORTED, 'Import کامل و موفق شد');
test_assert($row4783['dest_sku'] === 'vsp-4783', 'SKU مقصد درست تبدیل شده: hmp-4783 → vsp-4783');

$product4783 = wc_get_product((int) $row4783['dest_product_id']);
test_evidence('محصول ساخته‌شده (vsp-4783)', array('manage_stock' => $product4783->get_manage_stock(), 'stock_status' => $product4783->get_stock_status(), 'stock_quantity' => $product4783->get_stock_quantity()));
test_assert($product4783->get_stock_status() === 'instock', 'رگرسیون گزارش‌شده رفع شد: vsp-4783 هم مثل مبدا instock است، نه ناموجود (۰)');
test_assert($product4783->get_manage_stock() === false, 'manage_stock هم مثل مبدا خاموش مانده — عدد جعلی صفر گذاشته نشده');

// =============================================================================
// تست ۴ — همان قاعده در Sync روزانه (HCI_Sync::apply_item_update، از طریق
// Reflection چون private است) — «این تابع مشترک که Import و Sync هر دو
// استفاده کنند».
// =============================================================================
test_section('تست ۴ — HCI_Sync هم دقیقاً همان قاعده مشترک را از طریق HCI_Import::apply_stock() اعمال می‌کند');

$productForSync = new WC_Product_Simple();
$productForSync->set_status('publish');
$productForSync->set_manage_stock(true);
$productForSync->set_stock_quantity(5);
$productForSync->set_stock_status('instock');
$destIdSync = $productForSync->save();

$reflection = new ReflectionClass('HCI_Sync');
$method = $reflection->getMethod('apply_item_update');
$method->setAccessible(true);

// مبدا الان مدیریت موجودی را خاموش کرده (stock_quantity=NULL) ولی همچنان instock است
$outcome = $method->invoke(null, $destIdSync, array(
    'price' => null,
    'stock_quantity' => null,
    'stock_status' => 'instock',
    'is_active' => true,
));
test_evidence('نتیجه apply_item_update با stock_quantity=NULL/instock', $outcome);

$productAfterSync = wc_get_product($destIdSync);
test_assert($productAfterSync->get_manage_stock() === false, 'سینک هم manage_stock را خاموش می‌کند وقتی مبدا دیگر عدد نمی‌دهد');
test_assert($productAfterSync->get_stock_status() === 'instock', 'و instock می‌ماند — نه outofstock ساختگی');
test_assert($outcome['stock_updated'] === true, 'چون واقعاً چیزی تغییر کرد (manage_stock از true به false)، stock_updated=true گزارش شده');

echo "\n=== جمع‌بندی قاعده موجودی مشترک ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
