<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

echo "HeyMode Client Importer — Variable Product Visibility (PHP " . PHP_VERSION . ")\n";
echo "بررسی Badge گرید، انتقال Variations به مودال/جدول بازبینی، و بازه قیمت.\n";

$cardMethod = new ReflectionMethod('HCI_Products', 'render_card');
$cardMethod->setAccessible(true);
$buildJsonMethod = new ReflectionMethod('HCI_Products', 'build_product_json_entry');
$buildJsonMethod->setAccessible(true);
$priceDisplayMethod = new ReflectionMethod('HCI_Products', 'compute_price_display');
$priceDisplayMethod->setAccessible(true);

// =============================================================================
// داده نمونه: یک محصول variable واقعی با ۲ Variation + یک محصول simple
// =============================================================================

$variableItem = array(
    'source_product_id' => 700,
    'product_type' => 'variable',
    'name' => 'تیشرت رنگی',
    'price' => '250000',
    'stock_status' => 'instock',
    'short_description' => 'توضیح',
    'image_url' => 'https://example.test/main.jpg',
    'gallery' => array(),
    'category_path' => array(array('id' => 1, 'name' => 'پوشاک', 'parent_id' => null)),
    'attributes' => array(array('name' => 'رنگ', 'options' => array('مشکی', 'قرمز'))),
    'variations' => array(
        array('variation_id' => 701, 'attributes' => array(array('name' => 'رنگ', 'option' => 'مشکی'), array('name' => 'سایز', 'option' => 'XL')), 'price' => '250000', 'stock_quantity' => 5.0, 'sku' => 'TS-BLK-XL'),
        array('variation_id' => 702, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز'), array('name' => 'سایز', 'option' => 'L')), 'price' => '300000', 'stock_quantity' => 0.0, 'sku' => null),
    ),
);

$simpleItem = array(
    'source_product_id' => 800,
    'product_type' => 'simple',
    'name' => 'ماگ ساده',
    'price' => '90000',
    'stock_status' => 'instock',
    'short_description' => '',
    'image_url' => null,
    'gallery' => array(),
    'category_path' => array(),
    'attributes' => array(),
    'variations' => array(),
);

// =============================================================================
// تست ۱ — Badge «متغیر» روی کارت گرید
// =============================================================================
test_section('تست ۱ — render_card(): Badge «متغیر» فقط برای variable ظاهر می‌شود');

ob_start();
$cardMethod->invoke(null, $variableItem, false);
$variableCardHtml = ob_get_clean();

ob_start();
$cardMethod->invoke(null, $simpleItem, false);
$simpleCardHtml = ob_get_clean();

test_assert(str_contains($variableCardHtml, 'hci-card-badge-variable') && str_contains($variableCardHtml, 'متغیر'), 'کارت محصول variable شامل Badge «متغیر» است');
test_assert(!str_contains($simpleCardHtml, 'hci-card-badge-variable'), 'کارت محصول simple هیچ Badge «متغیر»ای ندارد');

// =============================================================================
// تست ۲ — build_product_json_entry(): variations فقط برای variable منتقل می‌شود
// =============================================================================
test_section('تست ۲ — build_product_json_entry(): انتقال درست product_type/variations');

$variableJson = $buildJsonMethod->invoke(null, $variableItem);
$simpleJson = $buildJsonMethod->invoke(null, $simpleItem);

test_evidence('build_product_json_entry() برای محصول variable', $variableJson);

test_assert($variableJson['product_type'] === 'variable', 'product_type روی JSON محصول variable درست منتقل شده');
test_assert(count($variableJson['variations']) === 2, 'هر ۲ Variation منتقل شده‌اند');
test_assert(
    $variableJson['variations'][0]['variation_id'] === 701 && $variableJson['variations'][0]['sku'] === 'TS-BLK-XL',
    'فیلدهای هر Variation (variation_id/sku) درست کپی شده‌اند'
);
test_assert($variableJson['variations'][1]['sku'] === null, 'sku خالی یک Variation دقیقاً null می‌ماند (نه رشته خالی)');

test_assert($simpleJson['product_type'] === 'simple', 'product_type روی JSON محصول simple درست منتقل شده');
test_assert($simpleJson['variations'] === array(), 'آرایه variations برای محصول simple همیشه خالی است');

// =============================================================================
// تست ۳ — compute_price_display(): بازه قیمت برای variable، عدد ساده برای simple
// =============================================================================
test_section('تست ۳ — compute_price_display(): بازه قیمت variable در برابر عدد ساده simple');

// دقیقاً همان ورودی‌ای که defaultEntry() سمت JS در selection_json می‌سازد
HCI_Pricing::save(0.0, 0.0, 'draft'); // فرمول خنثی تا اعداد قابل پیش‌بینی بمانند

$variableEntry = array(
    'product_type' => 'variable',
    'price' => '250000',
    'variations' => array(
        array('variation_id' => 701, 'attributes' => array(array('name' => 'رنگ', 'option' => 'مشکی')), 'price' => '250000'),
        array('variation_id' => 702, 'attributes' => array(array('name' => 'رنگ', 'option' => 'قرمز')), 'price' => '300000'),
    ),
);
$simpleEntry = array('product_type' => 'simple', 'price' => '90000', 'variations' => array());
$sameEntryPriceEntry = array(
    'product_type' => 'variable',
    'price' => '100000',
    'variations' => array(
        array('variation_id' => 1, 'attributes' => array(), 'price' => '100000'),
        array('variation_id' => 2, 'attributes' => array(), 'price' => '100000'),
    ),
);

$variablePriceDisplay = $priceDisplayMethod->invoke(null, $variableEntry);
$simplePriceDisplay = $priceDisplayMethod->invoke(null, $simpleEntry);
$samePriceDisplay = $priceDisplayMethod->invoke(null, $sameEntryPriceEntry);

test_evidence('compute_price_display() برای محصول variable', $variablePriceDisplay);
test_evidence('compute_price_display() برای محصول simple', $simplePriceDisplay);

test_assert($variablePriceDisplay['is_range'] === true, 'برای variable با قیمت‌های متفاوت، is_range=true است');
test_assert($variablePriceDisplay['display'] === '250,000 – 300,000', 'بازه قیمت دقیقاً کمترین تا بیشترین قیمت (فرمول‌شده) را نشان می‌دهد');
test_assert(
    str_contains($variablePriceDisplay['title'], 'رنگ: مشکی') && str_contains($variablePriceDisplay['title'], '250,000')
    && str_contains($variablePriceDisplay['title'], 'رنگ: قرمز') && str_contains($variablePriceDisplay['title'], '300,000'),
    'Tooltip (title) شامل ترکیب attributes و قیمت هر Variation به‌طور جداگانه است'
);

test_assert($simplePriceDisplay['is_range'] === false && $simplePriceDisplay['title'] === '', 'برای simple، is_range=false و بدون Tooltip است (رفتار قبلی دست‌نخورده)');
test_assert($simplePriceDisplay['display'] === '90,000', 'قیمت simple همان یک عدد ساده فرمول‌شده است');

test_assert($samePriceDisplay['is_range'] === false, 'وقتی همه Variationها قیمت یکسان دارند، is_range=false (یک عدد ساده نمایش داده می‌شود نه بازه بی‌فایده)');
test_assert($samePriceDisplay['display'] === '100,000', 'در حالت هم‌قیمت، فقط همان یک عدد نشان داده می‌شود');

echo "\n=== جمع‌بندی نمایش محصول Variable ===\n";
echo "PASS: {$GLOBALS['__test_passes']}\n";
echo "FAIL: {$GLOBALS['__test_failures']}\n";

exit($GLOBALS['__test_failures'] > 0 ? 1 : 0);
