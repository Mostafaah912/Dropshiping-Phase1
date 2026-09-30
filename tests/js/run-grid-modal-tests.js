'use strict';

/**
 * بازتولید زنجیره واقعی «کلیک بفروشش → مودال → اضافه → localStorage →
 * فرم مرحله بعد» با اجرای واقعیِ همان جاوااسکریپت embedded در
 * render_grid_script() (نه بازنویسی منطق آن) روی یک DOM جعلی حداقلی.
 */

const path = require('path');
const vm = require('vm');
const { extractScript } = require('./extract-inline-script');
const { createDocument, createLocalStorage, mount } = require('./fake-dom');
const t = require('./test-helpers');

const PHP_FILE = path.join(__dirname, '..', '..', 'heymode-client-importer', 'includes', 'class-hci-products.php');

const GRID_SCRIPT_REPLACEMENTS = [
    ['const PRODUCTS = <?php echo wp_json_encode($products_json, JSON_UNESCAPED_UNICODE); ?>;', 'const PRODUCTS = TEST_PRODUCTS;'],
    ['const TRACKED_IDS = <?php echo wp_json_encode(array_values(array_map(\'strval\', $tracked_ids))); ?>;', 'const TRACKED_IDS = TEST_TRACKED_IDS;'],
    ['const CURRENT_USER_ID = <?php echo (int) get_current_user_id(); ?>;', 'const CURRENT_USER_ID = 1;'],
];

/**
 * دقیقاً همان HTML ثابتی که PHP قبل از این اسکریپت چاپ می‌کند: یک کارت
 * محصول (data-id="123") + فرم گرید + مودال «بفروشش» (همان idهای واقعی
 * render_card()/render_modal()).
 */
function mountGridDom(document, productId) {
    const form = mount(document, 'form', { id: 'hci-grid-form' });
    mount(document, 'input', { id: 'hci-select-all-page' });
    mount(document, 'button', { id: 'hci-clear-selection' });
    mount(document, 'strong', { id: 'hci-selection-counter' });
    mount(document, 'textarea', { id: 'hci-selection-json', parent: form });

    const grid = mount(document, 'div', { className: 'hci-grid', parent: form });
    const card = mount(document, 'div', { className: 'hci-card', attrs: { 'data-id': productId }, parent: grid });
    mount(document, 'input', { className: 'hci-card-checkbox', attrs: { type: 'checkbox', 'data-id': productId }, parent: card });
    mount(document, 'button', { className: 'hci-sell-btn', attrs: { 'data-id': productId }, parent: card });

    mount(document, 'div', { id: 'hci-modal-overlay' });
    mount(document, 'input', { id: 'hci-modal-name' });
    mount(document, 'textarea', { id: 'hci-modal-desc' });
    mount(document, 'div', { id: 'hci-modal-gallery' });
    mount(document, 'td', { id: 'hci-modal-categories' });
    mount(document, 'td', { id: 'hci-modal-price' });
    mount(document, 'td', { id: 'hci-modal-stock' });
    mount(document, 'tr', { id: 'hci-modal-variations-row' });
    mount(document, 'tbody', { id: 'hci-modal-variations-tbody' });
    mount(document, 'button', { id: 'hci-modal-add' });
    mount(document, 'button', { id: 'hci-modal-close' });
}

function loadGridScript(testProducts, trackedIds, presetLocalStorage, productId) {
    const js = extractScript(PHP_FILE, 'render_grid_script', GRID_SCRIPT_REPLACEMENTS);

    const document = createDocument();
    const localStorage = presetLocalStorage || createLocalStorage();
    mountGridDom(document, productId || '123');

    const sandbox = { document, localStorage, TEST_PRODUCTS: testProducts, TEST_TRACKED_IDS: trackedIds || [], console };
    vm.createContext(sandbox);
    vm.runInContext(js, sandbox, { filename: 'render_grid_script.js' });

    return { document, localStorage, sandbox };
}

const TEST_PRODUCTS = {
    123: {
        id: 123,
        name: 'محصول تست',
        short_description: 'توضیح اصلی',
        sku: 'hmp-123',
        price: '100000',
        stock_quantity: 5,
        category_names: ['دسته آ', 'دسته ب'],
        image_url: 'https://source.invalid/img1.jpg',
        gallery: ['https://source.invalid/img1.jpg', 'https://source.invalid/img2.jpg'],
        product_type: 'simple',
        variations: [],
    },
};

t.section('تست ۱ — زنجیره واقعی «بفروشش → مودال → اضافه» تا localStorage و فرم مرحله بعد');

const { document, localStorage } = loadGridScript(TEST_PRODUCTS, []);

const sellBtn = document.querySelector('.hci-sell-btn[data-id="123"]');
const checkbox = document.querySelector('.hci-card-checkbox[data-id="123"]');
const overlay = document.getElementById('hci-modal-overlay');

t.assert(!!sellBtn, 'دکمه «بفروشش» در DOM جعلی پیدا شد');
t.assert(overlay.style.display !== 'flex', 'قبل از کلیک، مودال بسته است');

sellBtn.click();

t.assert(overlay.style.display === 'flex', 'با کلیک «بفروشش»، مودال باز شد');
t.assert(document.getElementById('hci-modal-name').value === 'محصول تست', 'نام پیش‌فرض در فیلد نام مودال پر شده');

// کارمند نام/توضیح را ویرایش می‌کند
document.getElementById('hci-modal-name').value = 'نام ویرایش‌شده کارمند';
document.getElementById('hci-modal-desc').value = 'توضیح ویرایش‌شده کارمند';

const addBtn = document.getElementById('hci-modal-add');
addBtn.click();

t.evidence('محتوای localStorage بعد از کلیک «اضافه»', localStorage._dump());

const stored = JSON.parse(localStorage.getItem('hci_selection_v1_1') || '{}');
t.assert(Object.prototype.hasOwnProperty.call(stored, '123'), 'بعد از کلیک «اضافه»، محصول ۱۲۳ در selection ذخیره‌شده در localStorage هست (با کلید مختص کاربر)');
t.assert(stored['123'] && stored['123'].name === 'نام ویرایش‌شده کارمند', 'نام ویرایش‌شده کارمند در entry ذخیره‌شده منعکس شده');
t.assert(stored['123'] && stored['123'].short_description === 'توضیح ویرایش‌شده کارمند', 'توضیح کوتاه ویرایش‌شده هم منعکس شده');
t.assert(overlay.style.display === 'none', 'بعد از «اضافه»، مودال بسته شد');
t.assert(checkbox.checked === true, 'چک‌باکس همان کارت در گرید تیک خورده');
t.assert(document.getElementById('hci-selection-counter').textContent === '1 محصول انتخاب‌شده', 'شمارنده «N محصول انتخاب‌شده» کنار دکمه مرحله بعد به‌روز شد');

// حالا فرم «مرحله بعد» Submit می‌شود — باید همان selection به‌روزشده را حمل کند
form_submit_test: {
    const form = document.getElementById('hci-grid-form');
    form.dispatchEvent({ type: 'submit', target: form });
    const jsonField = document.getElementById('hci-selection-json');
    const submitted = JSON.parse(jsonField.value || '{}');
    t.assert(Object.prototype.hasOwnProperty.call(submitted, '123'), 'فیلد مخفی selection_json فرم «مرحله بعد» هم شامل محصول ۱۲۳ است');
    t.assert(submitted['123'].name === 'نام ویرایش‌شده کارمند', 'مقداری که به سرور Submit می‌شود همان نام ویرایش‌شده است، نه نام خام مبدا');
}

t.section('تست ۲ — دکمه «پاک کردن انتخاب‌ها»');

const clearBtn = document.getElementById('hci-clear-selection');
clearBtn.click();
const afterClear = JSON.parse(localStorage.getItem('hci_selection_v1_1') || '{}');
t.assert(Object.keys(afterClear).length === 0, 'بعد از کلیک «پاک کردن انتخاب‌ها»، selection در localStorage کاملاً خالی شده');
t.assert(checkbox.checked === false, 'چک‌باکس کارت هم دوباره از حالت انتخاب خارج شده');
t.assert(document.getElementById('hci-selection-counter').textContent === '', 'شمارنده بعد از پاک شدن انتخاب‌ها دوباره خالی می‌شود');

t.section('تست ۳ — پاک‌سازی خودکار انتخاب باقی‌مانده از یک تلاش قبلی (Stale Cache)');

// محصول ۴۵۶ از قبل، در یک بازدید قبلی، انتخاب و در localStorage ذخیره شده
// بود — اما الان سرور می‌گوید یک رکورد (با هر وضعیتی، حتی خطا) برایش در
// جدول ردیابی ثبت شده. با بارگذاری تازه صفحه، این انتخاب قدیمی/بی‌اعتبار
// باید خودش خودکار از localStorage پاک شود، نه اینکه بی‌صدا تیک‌خورده بماند.
const staleLocalStorage = createLocalStorage();
staleLocalStorage.setItem('hci_selection_v1_1', JSON.stringify({
    456: { source_product_id: 456, name: 'محصول قدیمی از تلاش قبلی' },
}));

// محصول ۴۵۶ دیگر در page_items این بار نیست (صفحه/فیلتر عوض شده)، ولی سرور
// می‌گوید هنوز یک رکورد (TRACKED_IDS) برایش هست.
loadGridScript({}, ['456'], staleLocalStorage, '999');

const afterReconcile = JSON.parse(staleLocalStorage.getItem('hci_selection_v1_1') || '{}');
t.evidence('localStorage بعد از بارگذاری صفحه با TRACKED_IDS=[456]', afterReconcile);
t.assert(!Object.prototype.hasOwnProperty.call(afterReconcile, '456'), 'انتخاب قدیمی محصول ۴۵۶ (که سرور می‌گوید الان ردیابی می‌شود) خودکار پاک شد');

// =============================================================================
// تست‌های ۴-۶ — مورد ۲: «تغییر تصویر اصلی، تصویر اصلی قبلی را پاک می‌کند».
// انتخاب Featured و حذف از گالری باید دو وضعیت کاملاً مستقل باشند.
// =============================================================================

const GALLERY_PRODUCTS = {
    777: {
        id: 777,
        name: 'محصول گالری‌دار',
        short_description: '',
        sku: 'hmp-777',
        price: '1000',
        stock_quantity: 1,
        category_names: [],
        image_url: 'https://source.invalid/main.jpg',
        gallery: ['https://source.invalid/g1.jpg', 'https://source.invalid/g2.jpg'],
        product_type: 'simple',
        variations: [],
    },
};

function openGalleryModal() {
    const { document: doc } = loadGridScript(GALLERY_PRODUCTS, [], null, '777');
    doc.querySelector('.hci-sell-btn[data-id="777"]').click();
    return doc;
}

function galleryUrls(doc) {
    return doc.querySelectorAll('img').map((img) => img.getAttribute('src'));
}

function clickFeaturedRadioFor(doc, url) {
    const imgs = doc.querySelectorAll('img');
    const radios = doc.querySelectorAll('input[type="radio"]');
    const idx = imgs.findIndex((img) => img.getAttribute('src') === url);
    radios[idx].checked = true;
    radios[idx].dispatchEvent({ type: 'change' });
}

function clickRemoveFor(doc, url) {
    const imgs = doc.querySelectorAll('img');
    const removeBtns = doc.querySelectorAll('.hci-gallery-remove');
    const idx = imgs.findIndex((img) => img.getAttribute('src') === url);
    removeBtns[idx].click();
}

t.section('تست ۴ — انتخاب یک تصویر دیگر به‌عنوان اصلی، تصویر اصلی قبلی را از گالری حذف نمی‌کند');

let doc = openGalleryModal();
t.evidence('گالری در باز شدن اولیه مودال (باید هر ۳ تصویر باشد، شامل main.jpg)', galleryUrls(doc));
t.assert(galleryUrls(doc).length === 3, 'هر ۳ تصویر (اصلی + ۲ گالری) نمایش داده می‌شوند، نه فقط ۲ گالری جدا از اصلی');
t.assert(galleryUrls(doc).includes('https://source.invalid/main.jpg'), 'تصویر اصلی هم در همان فهرست واحد گالری هست');

clickFeaturedRadioFor(doc, 'https://source.invalid/g1.jpg');

t.evidence('گالری بعد از انتخاب g1.jpg به‌عنوان تصویر اصلی', galleryUrls(doc));
t.assert(galleryUrls(doc).length === 3, 'رگرسیون همین‌جا بود: بعد از تغییر تصویر اصلی، هر ۳ تصویر (شامل اصلیِ قبلی main.jpg) هنوز باید در گالری باشند');
t.assert(galleryUrls(doc).includes('https://source.invalid/main.jpg'), 'تصویر اصلیِ قبلی (main.jpg) هنوز در گالری است — فقط دیگر Featured نیست');
t.assert(galleryUrls(doc).includes('https://source.invalid/g1.jpg'), 'g1.jpg هم همچنان در گالری هست');

t.section('تست ۵ — برچسب رادیو: انتخاب‌شده «تصویر اصلی»، بقیه «انتخاب به‌عنوان اصلی»');

doc = openGalleryModal();
const labelTexts = doc.querySelectorAll('label').map((l) => l.textContent.trim());
t.evidence('متن برچسب‌های رادیو (اولین باز شدن، main.jpg اصلی است)', labelTexts);
t.assert(labelTexts.filter((t2) => t2 === 'تصویر اصلی').length === 1, 'دقیقاً یک برچسب «تصویر اصلی» است (همان تصویر انتخاب‌شده)');
t.assert(labelTexts.filter((t2) => t2 === 'انتخاب به‌عنوان اصلی').length === 2, 'بقیه برچسب‌ها «انتخاب به‌عنوان اصلی» هستند');

t.section('تست ۶ — حذف تصویر اصلی با ✕، اولین تصویر باقی‌مانده را خودکار اصلی می‌کند');

doc = openGalleryModal();
clickRemoveFor(doc, 'https://source.invalid/main.jpg');
t.evidence('گالری بعد از حذف تصویر اصلی (main.jpg)', galleryUrls(doc));
t.assert(galleryUrls(doc).length === 2, 'تصویر حذف‌شده واقعاً از گالری کم شده (نه فقط از حالت Featured خارج شده)');
t.assert(!galleryUrls(doc).includes('https://source.invalid/main.jpg'), 'main.jpg دیگر در گالری نیست');
const labelsAfterRemove = doc.querySelectorAll('label').map((l) => l.textContent.trim());
t.assert(labelsAfterRemove.filter((t2) => t2 === 'تصویر اصلی').length === 1, 'یکی از تصاویر باقی‌مانده خودکار اصلی شده (نه این‌که هیچ‌کدام اصلی نباشند)');

t.section('تست ۷ — حذف همه تصاویر: گالری خالی می‌ماند، چیزی کرش نمی‌کند');

doc = openGalleryModal();
clickRemoveFor(doc, 'https://source.invalid/main.jpg');
clickRemoveFor(doc, 'https://source.invalid/g1.jpg');
clickRemoveFor(doc, 'https://source.invalid/g2.jpg');
t.evidence('گالری بعد از حذف هر ۳ تصویر', galleryUrls(doc));
t.assert(galleryUrls(doc).length === 0, 'هر ۳ تصویر با موفقیت حذف شدند، بدون خطا');


t.section('تست ۸ — ستون موجودی تنوع‌ها در مودال');
const VAR_PRODUCTS = { 888: { id: 888, name: 'متغیر', short_description: '', sku: 'hmp-888', price: null, stock_quantity: null, category_names: [], category_path: [],
    image_url: null, gallery: [], product_type: 'variable', variations: [
        { variation_id: 1, attributes: [{ name: 'رنگ', option: '01' }], price: '1', stock_quantity: 7, stock_status: 'instock' },
        { variation_id: 2, attributes: [{ name: 'رنگ', option: '02' }], price: '1', stock_quantity: null, stock_status: 'instock' },
        { variation_id: 3, attributes: [{ name: 'رنگ', option: '03' }], price: '1', stock_quantity: null, stock_status: 'outofstock' },
        { variation_id: 4, attributes: [{ name: 'رنگ', option: '04' }], price: '1', stock_quantity: null },
    ] } };
const { document: vdoc } = loadGridScript(VAR_PRODUCTS, [], null, '888');
vdoc.querySelector('.hci-sell-btn[data-id="888"]').click();
const stockCells = vdoc.getElementById('hci-modal-variations-tbody').children.map(function (tr) { return tr.children[2].textContent; });
t.evidence('ستون موجودی', stockCells);
t.assert(stockCells[0] === '۷ عدد' && stockCells[1] === 'موجود' && stockCells[2] === 'ناموجود' && stockCells[3] === 'نامشخص', 'عدد / موجود / ناموجود / نامشخص (مبدا قدیمی) — هرگز «-»');

t.summary('جمع‌بندی زنجیره مودال «اضافه» ← جدول بازبینی');
