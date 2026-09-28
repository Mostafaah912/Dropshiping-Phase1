'use strict';

const fs = require('fs');

/**
 * یک تابع embedded مشخص در class-hci-products.php را پیدا می‌کند و اولین
 * بلوک <script>...</script> داخل آن را استخراج می‌کند — دقیقاً همان متنی که
 * مرورگر واقعی اجرا می‌کند، نه بازنویسی دستی آن. جایگزینی رشته‌ای ساده هم
 * برای بریدن Interpolationهای PHP (مثل <?php echo wp_json_encode(...) ?>)
 * با یک متغیر تزریق‌شده از تست پشتیبانی می‌شود.
 */
function extractScript(phpFilePath, methodName, replacements) {
    const src = fs.readFileSync(phpFilePath, 'utf8');
    const methodIdx = src.indexOf('function ' + methodName);
    if (methodIdx === -1) {
        throw new Error('method not found: ' + methodName);
    }
    const scriptStart = src.indexOf('<script>', methodIdx);
    const scriptEnd = src.indexOf('</script>', scriptStart);
    if (scriptStart === -1 || scriptEnd === -1) {
        throw new Error('script block not found in ' + methodName);
    }
    let js = src.slice(scriptStart + '<script>'.length, scriptEnd);

    (replacements || []).forEach(([search, replace]) => {
        if (!js.includes(search)) {
            throw new Error('replacement anchor not found (production code may have changed): ' + search);
        }
        js = js.split(search).join(replace);
    });

    if (js.includes('<?php')) {
        throw new Error('unreplaced PHP interpolation left in extracted script — add a replacement for it');
    }

    return js;
}

module.exports = { extractScript };
