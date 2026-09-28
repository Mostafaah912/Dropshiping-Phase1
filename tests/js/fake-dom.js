'use strict';

/**
 * یک DOM جعلی و بسیار حداقلی — فقط همان زیرمجموعه‌ای از API که اسکریپت‌های
 * embedded در class-hci-products.php واقعاً صدا می‌زنند (getElementById،
 * querySelectorAll با چند نوع Selector ساده، createElement، addEventListener/
 * dispatchEvent، localStorage). هدف بازتولید واقعی رفتار مرورگر با Node است،
 * نه Mock کردن منطق خودمان — چون این تست‌ها دقیقاً همان کد Production
 * (استخراج‌شده از PHP) را اجرا می‌کنند، نه بازنویسی آن.
 */

class FakeStyle {
    constructor() { this._props = {}; }
    get display() { return this._props.display || ''; }
    set display(v) { this._props.display = v; }
    get cssText() { return this._props.cssText || ''; }
    set cssText(v) { this._props.cssText = v; }
}

class FakeElement {
    constructor(tagName, registry) {
        this.tagName = (tagName || 'div').toUpperCase();
        this._registry = registry;
        this.id = '';
        this._classes = [];
        this._attrs = {};
        this.children = [];
        this.parentNode = null;
        this._listeners = {};
        this.style = new FakeStyle();
        this.value = '';
        this.checked = false;
        this.disabled = false;
        this._textContent = '';
        this._innerHTML = '';
        registry.push(this);
    }

    get className() { return this._classes.join(' '); }
    set className(v) { this._classes = String(v).split(/\s+/).filter(Boolean); }

    get textContent() {
        if (this.isTextNode) { return this._textContent; }
        if (this.children.length === 0) { return this._textContent; }
        return this.children.map((c) => c.textContent).join('');
    }
    set textContent(v) {
        this._textContent = v;
        this.children = [];
    }

    get innerHTML() { return this._innerHTML; }
    set innerHTML(v) {
        this._innerHTML = v;
        if (v === '') {
            // مثل مرورگر واقعی: پاک‌کردن innerHTML باید همه فرزندان (و
            // نوادگان‌شان) را واقعاً از سند حذف کند، نه فقط از children این
            // عنصر — وگرنه querySelectorAll سراسری هنوز عناصر «حذف‌شده» از
            // رندرهای قبلی (مثل renderGallery() که هر بار innerHTML='' و
            // دوباره می‌سازد) را پیدا می‌کند.
            this.children.slice().forEach((child) => child.remove());
            this.children = [];
        }
    }

    setAttribute(name, value) {
        if (name === 'id') { this.id = String(value); return; }
        if (name === 'class') { this.className = value; return; }
        this._attrs[name] = String(value);
    }

    getAttribute(name) {
        if (name === 'id') { return this.id || null; }
        if (name === 'class') { return this.className || null; }
        if (Object.prototype.hasOwnProperty.call(this._attrs, name)) { return this._attrs[name]; }
        // مثل مرورگر واقعی: مقداردهی مستقیم پراپرتی (el.type = 'radio'،
        // img.src = url) هم باید با getAttribute() قابل خواندن باشد، نه فقط
        // چیزی که صریحاً با setAttribute() ست شده.
        if (['type', 'src'].includes(name) && this[name]) { return this[name]; }
        return null;
    }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    remove() {
        if (this.parentNode) {
            this.parentNode.children = this.parentNode.children.filter((c) => c !== this);
            this.parentNode = null;
        }
        this.children.slice().forEach((child) => child.remove());
        this.children = [];
        const idx = this._registry.indexOf(this);
        if (idx !== -1) { this._registry.splice(idx, 1); }
    }

    closest(selector) {
        let node = this;
        while (node) {
            if (matches(node, selector)) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    addEventListener(type, handler) {
        if (!this._listeners[type]) { this._listeners[type] = []; }
        this._listeners[type].push(handler);
    }

    dispatchEvent(event) {
        const handlers = this._listeners[event.type] || [];
        handlers.forEach((h) => h.call(this, event));
        return true;
    }

    click() { this.dispatchEvent({ type: 'click', target: this }); }
    change() { this.dispatchEvent({ type: 'change', target: this }); }
}

function matches(el, selector) {
    // پشتیبانی از ترکیب‌های ساده‌ای که واقعاً در کد Production استفاده شده:
    // '#id', '.cls', '.cls.cls2', '.cls:not(:disabled)', '.cls[data-id="X"]'
    const notDisabled = selector.includes(':not(:disabled)');
    selector = selector.replace(':not(:disabled)', '');

    const attrMatch = selector.match(/\[([a-zA-Z-]+)="([^"]*)"\]/);
    if (attrMatch) {
        selector = selector.slice(0, attrMatch.index);
        if (el.getAttribute(attrMatch[1]) !== attrMatch[2]) { return false; }
    }

    if (selector.startsWith('#')) {
        if (el.id !== selector.slice(1)) { return false; }
    } else if (selector.startsWith('.')) {
        const classes = selector.slice(1).split('.');
        for (const c of classes) {
            if (!el._classes.includes(c)) { return false; }
        }
    } else if (selector !== '') {
        if (el.tagName !== selector.toUpperCase()) { return false; }
    }

    if (notDisabled && el.disabled) { return false; }
    return true;
}

function createDocument() {
    const registry = [];

    function walkAll() { return registry.slice(); }

    const document = {
        _registry: registry,
        createElement(tag) { return new FakeElement(tag, registry); },
        createTextNode(text) {
            const node = new FakeElement('#text', registry);
            registry.pop(); // متن نباید توسط Selectorها قابل پیدا شدن باشد؛ فقط برای appendChild لازم است
            node._textContent = text;
            node.isTextNode = true;
            return node;
        },
        getElementById(id) {
            return walkAll().find((el) => el.id === id) || null;
        },
        querySelectorAll(selector) {
            return walkAll().filter((el) => matches(el, selector));
        },
        querySelector(selector) {
            return walkAll().find((el) => matches(el, selector)) || null;
        },
    };

    return document;
}

function createLocalStorage() {
    const store = {};
    return {
        getItem(key) { return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null; },
        setItem(key, value) { store[key] = String(value); },
        removeItem(key) { delete store[key]; },
        _dump() { return Object.assign({}, store); },
    };
}

/**
 * یک عنصر ثابت (مثل چیزی که PHP در HTML چاپ کرده) با id/class/attribute
 * مشخص می‌سازد و به سند اضافه می‌کند — برای صحنه‌سازی وضعیت اولیه DOM قبل
 * از اجرای اسکریپت.
 */
function mount(document, tag, opts) {
    const el = document.createElement(tag);
    opts = opts || {};
    if (opts.id) { el.id = opts.id; }
    if (opts.className) { el.className = opts.className; }
    if (opts.attrs) {
        Object.keys(opts.attrs).forEach((k) => el.setAttribute(k, opts.attrs[k]));
    }
    if (opts.disabled) { el.disabled = true; }
    if (opts.parent) { opts.parent.appendChild(el); }
    return el;
}

module.exports = { createDocument, createLocalStorage, mount, FakeElement };
