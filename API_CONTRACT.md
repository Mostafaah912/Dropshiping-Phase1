# API_CONTRACT — HeyMode Wholesale (منبع)

این فایل مرجع کامل Schema درخواست/پاسخ REST API پلاگین `heymode-wholesale` است (نسخه پلاگین ≥ 1.9.0).
مصرف‌کننده‌ی این API پلاگین سایت مشتری (`heymode-client-importer`) است، اما هر کلاینت دیگری هم می‌تواند از همین Contract استفاده کند.

نکته زبان: کلیدهای JSON و مقادیر enum همیشه انگلیسی هستند؛ پیام‌های خطا و توضیحات پنل ادمین فارسی‌اند.

---

## 1) Base URL و Namespace

```
{SITE_URL}/wp-json/hmw/v1
```

مسیرها:

| متد | مسیر | توضیح |
|---|---|---|
| GET | `/health` | وضعیت سلامت سرویس و آخرین Full Sync |
| GET | `/products` | فهرست کامل محصولات (با فیلتر/صفحه‌بندی) |
| GET | `/products/{id}` | یک محصول یا Variation با `source_product_id` |
| GET | `/products/delta` | **جدید** — تغییرات سبک قیمت/موجودی از یک زمان مشخص |

---

## 2) Authentication

بدون تغییر نسبت به نسخه‌ی قبلی پلاگین. هر سه Endpoint بالا از یک `permission_callback` مشترک (`HMW_REST_API::authorize`) استفاده می‌کنند که به همین ترتیب بررسی می‌شود:

1. **IP Allowlist** (اختیاری، در صفحه «API مشتری» تنظیم می‌شود) — اگر خالی باشد همه IPها مجازند.
2. **Rate Limit**: حداکثر **120 درخواست در 60 ثانیه** به ازای هر IP (بر اساس `REMOTE_ADDR`).
3. **API Key**: باید در هدر ارسال شود، به یکی از دو شکل:

```
Authorization: Bearer YOUR_API_KEY
```
یا
```
X-HMW-API-Key: YOUR_API_KEY
```

خطاهای Auth:

| کد HTTP | `error.code` | معنی |
|---|---|---|
| 503 | `hmw_api_not_configured` | هنوز API Key ساخته نشده |
| 403 | `hmw_api_ip_denied` | IP در Allowlist نیست |
| 429 | `hmw_api_rate_limited` | عبور از سقف Rate Limit |
| 401 | `hmw_api_missing_key` | هدر Authorization/X-HMW-API-Key ارسال نشده |
| 401 | `hmw_api_invalid_key` | کلید نامعتبر یا باطل‌شده |

---

## 3) GET /health

بدون تغییر. نمونه پاسخ:

```json
{
  "success": true,
  "data": {
    "api": "ok",
    "database": "ok",
    "full_sync_status": "idle",
    "total_records": 4210,
    "active_records": 4180,
    "last_full_sync": {
      "status": "success",
      "started_at_utc": "2026-09-26 00:00:04",
      "finished_at_utc": "2026-09-26 00:12:31",
      "products_fetched": 4210,
      "products_updated": 87,
      "products_deactivated": 3,
      "errors_count": 0
    }
  },
  "server": { "generated_at_utc": "...", "generated_at_tehran": "...", "plugin_version": "1.9.0" }
}
```

---

## 4) GET /products

### پارامترهای Query (بدون تغییر نسبت به نسخه قبل)

| پارامتر | نوع | پیش‌فرض | توضیح |
|---|---|---|---|
| `page` | int | 1 | شماره صفحه |
| `per_page` | int | 100 | حداکثر 100 |
| `include_inactive` | bool | false | نمایش رکوردهای غیرفعال هم |
| `type` | enum | - | `simple` \| `variable` \| `variation` |
| `sku` | string | - | تطبیق دقیق SKU |
| `source_product_id` | int | - | فیلتر بر اساس شناسه محصول منبع |
| `parent_product_id` | int | - | فیلتر Variationهای یک والد |
| `stock_status` | enum | - | `instock` \| `outofstock` \| `onbackorder` |
| `updated_after` | ISO 8601 | - | فقط رکوردهایی که `updated_at` محلی بعد از این زمان تغییر کرده (نگاه کنید به بخش «محدودیت‌های شناخته‌شده») |
| `modified_after` | ISO 8601 | - | فقط رکوردهایی که `source_modified_gmt` (زمان تغییر در منبع) بعد از این زمان است |

### شکل هر آیتم (Schema کامل — شامل فیلدهای جدید)

```json
{
  "source_product_id": 1234,
  "parent_product_id": null,
  "product_type": "variable",
  "source_status": "publish",
  "sku": "ABC-123",
  "name": "نام محصول",
  "short_description": "<p>توضیح کوتاه محصول...</p>",
  "price": "350000",
  "stock_quantity": 12,
  "stock_status": "instock",
  "manage_stock": true,
  "image_url": "https://.../main.jpg",
  "gallery": [
    "https://.../gallery-1.jpg",
    "https://.../gallery-2.jpg"
  ],
  "product_url": "https://.../product/...",
  "category_path": [
    { "id": 12, "name": "دسته اصلی", "parent_id": null },
    { "id": 34, "name": "دسته فرعی", "parent_id": 12 }
  ],
  "source_modified_gmt": "2026-09-24T11:30:00+00:00",
  "is_active": true,
  "last_synced_at_utc": "2026-09-24 11:31:02",
  "updated_at_utc": "2026-09-24 11:31:02",

  "attributes": [
    { "name": "رنگ", "options": ["قرمز", "آبی"] },
    { "name": "سایز", "options": ["M", "L"] }
  ],
  "variations": [
    {
      "variation_id": 1235,
      "attributes": [
        { "name": "رنگ", "option": "قرمز" },
        { "name": "سایز", "option": "M" }
      ],
      "price": "350000",
      "stock_quantity": 5,
      "sku": "ABC-123-RED-M"
    },
    {
      "variation_id": 1236,
      "attributes": [
        { "name": "رنگ", "option": "آبی" },
        { "name": "سایز", "option": "L" }
      ],
      "price": "360000",
      "stock_quantity": 0,
      "sku": null
    }
  ]
}
```

### فیلدهای جدید نسبت به نسخه قبلی

| فیلد | نوع | توضیح |
|---|---|---|
| `short_description` | string | توضیح کوتاه محصول (HTML خام WooCommerce)، همیشه رشته (خالی اگر نبود) |
| `gallery` | string[] | آرایه URL تصاویر گالری، **بدون** تصویر اصلی (که در `image_url` است) |
| `category_path` | `{id, name, parent_id}[]` | **تغییر شکل**: قبلاً آرایه‌ای از رشته‌های مسیر بود؛ از این نسخه، آرایه‌ای مسطح و بدون تکرار از ریشه تا برگ، بر اساس شناسه دسته‌بندی‌های منبع. `parent_id` برای دسته‌های ریشه `null` است. |
| `attributes` | `{name, options}[]` | **فقط برای `product_type == "variable"`**؛ ویژگی‌های سطح محصول والد |
| `variations` | آرایه (بخش بعد) | **فقط برای `product_type == "variable"`** |

هر آیتم داخل `variations`:

| فیلد | نوع | توضیح |
|---|---|---|
| `variation_id` | int | `source_product_id` همان Variation در جدول محصولات |
| `attributes` | `{name, option}[]` | ترکیب انتخاب‌شده‌ی این Variation |
| `price` | string\|null | |
| `stock_quantity` | float\|null | عدد دقیق |
| `sku` | string\|null | اگر SKU نداشت `null` |

> برای `product_type` برابر `simple` یا `variation`، کلیدهای `attributes` و `variations` اصلاً در پاسخ ظاهر نمی‌شوند (نه حتی خالی) — تا خروجی محصولات ساده هم‌مثل نسخه‌ی قبلی سبک بماند.

### پاسخ کامل

```json
{
  "success": true,
  "data": [ { "...یک آیتم مطابق schema بالا..." } ],
  "pagination": { "page": 1, "per_page": 100, "total": 4210, "total_pages": 43, "has_next": true, "has_previous": false },
  "filters": { "...": "..." },
  "server": { "generated_at_utc": "...", "generated_at_tehran": "...", "plugin_version": "1.9.0" }
}
```

---

## 5) GET /products/{id}

همان شکل آیتم بالا، درون `data`. رفتار قبلی (404 برای رکورد نامعتبر/غیرفعال بدون `include_inactive=true`) بدون تغییر.

---

## 6) GET /products/delta  (جدید)

Endpoint سبک برای Polling دوره‌ای: «فقط چه چیزی از یک زمان مشخص تغییر کرده؟». به‌جای اشیاء کامل محصول، فقط شناسه/SKU/قیمت/موجودی برمی‌گرداند.

### پارامترها

| پارامتر | نوع | الزامی؟ | توضیح |
|---|---|---|---|
| `updated_after` | ISO 8601 یا `Y-m-d H:i:s` | **بله** | نبود یا نامعتبر بودن → HTTP 400 |
| `page` | int | خیر (پیش‌فرض 1) | |
| `per_page` | int | خیر (پیش‌فرض 100، حداکثر 200) | |

فقط رکوردهای **فعال** (`is_active = 1`) در نظر گرفته می‌شوند.

### منطق گروه‌بندی

- اگر یک محصول ساده یا والد Variable تغییر کرده باشد → یک آیتم top-level با `sku`/`price`/`stock_quantity` خودش.
- اگر یکی از Variationهای یک محصول Variable تغییر کرده باشد (حتی اگر خودِ والد تغییر نکرده) → یک آیتم top-level برای والد ساخته می‌شود (با خوانده‌شدن `sku`/`price`/`stock_quantity` فعلی والد از دیتابیس، نه لزوماً «تغییریافته») و آن Variation داخل کلید `variations` همان آیتم قرار می‌گیرد.
- **فقط Variationهایی که واقعاً تغییر کرده‌اند** در `variations` می‌آیند — نه همه‌ی Variationهای آن والد.

### نمونه پاسخ

```json
{
  "success": true,
  "data": [
    { "source_product_id": 1200, "sku": "XYZ-1", "price": "410000", "stock_quantity": 3 },
    {
      "source_product_id": 1234,
      "sku": "ABC-123",
      "price": "350000",
      "stock_quantity": 12,
      "variations": [
        { "variation_id": 1236, "sku": null, "price": "365000", "stock_quantity": 0 }
      ]
    }
  ],
  "pagination": { "page": 1, "per_page": 100, "total": 57, "total_pages": 1, "has_next": false, "has_previous": false },
  "filters": { "updated_after": "2026-09-26 00:00:00" },
  "server": { "...": "..." }
}
```

> **مهم — صفحه‌بندی**: `pagination.total`/`total_pages` روی تعداد **ردیف‌های خام تغییریافته** (محصول + Variation) حساب می‌شود، نه تعداد آیتم‌های نهاییِ گروه‌بندی‌شده در `data` (چون چند Variation ممکن است زیر یک والد جمع شوند). اگر والد و Variationهای تغییریافته‌اش در دو صفحه‌ی مختلف بیفتند، هرکدام مستقل ظاهر می‌شوند (والد بدون variations یا Variation در یک آیتم والد جدید). برای Polling معمول با `per_page` بزرگ (مثلاً 100–200) این مورد عملاً نادر است؛ در صورت نیاز به دقت کامل، `per_page` را افزایش دهید یا صفحات را کامل پیمایش کنید.

---

## 7) خطاهای عمومی

```json
{ "success": false, "error": { "code": "invalid_type", "message": "..." } }
```

کدهای رایج: `invalid_type`, `invalid_stock_status`, `invalid_updated_after`, `invalid_modified_after`, `not_found`, `db_error`.

---

## 8) محدودیت‌های شناخته‌شده (Known Limitations)

1. **معنای `updated_at`**: این ستون فقط وقتی تغییر می‌کند که حداقل یکی از فیلدهای مقایسه‌شده (`price`, `stock_quantity`, `stock_status`, `sku`, `name`, `category_path`, `is_active`, ...) واقعاً تغییر کرده باشد؛ فیلدهای توصیفی جدید (`short_description`, `gallery`, `attributes`, `category_ids`) در این مقایسه لحاظ نمی‌شوند و به‌تنهایی `updated_at` را جلو نمی‌برند. بنابراین `/products/delta` دقیقاً بازه‌ی «تغییر price/stock» را پوشش نمی‌دهد بلکه بازه‌ی «تغییر یکی از فیلدهای اصلیِ بالا» را پوشش می‌دهد — که price/stock را همیشه شامل می‌شود، اما ممکن است گاهی به‌خاطر تغییر یک فیلد دیگر (مثل `name`) هم یک رکورد را برگرداند.
2. **IP Allowlist و Rate Limit روی `REMOTE_ADDR`**: اگر سایت پشت CDN/Reverse Proxy باشد، این مقدار ممکن است IP واقعی کلاینت نباشد.
3. **`/products/delta` و صفحه‌بندی**: طبق توضیح بخش ۶، در حجم‌های خیلی بالا و `per_page` کوچک، یک والد Variable ممکن است در پاسخ دو صفحه‌ی متفاوت (یک‌بار بدون variations، یک‌بار با variations) ظاهر شود.
4. این تغییرات نیازمند migration خودکار دیتابیس (ستون‌های جدید در جدول محصولات) هستند که با ارتقای نسخه پلاگین به 1.9.0 و اجرای `dbDelta` در `plugins_loaded` به‌صورت خودکار انجام می‌شود؛ برای اعمال فوری کافی است پلاگین را از حالت غیرفعال/فعال دوباره عبور دهید یا صرفاً یک بار صفحه‌ی ادمین را باز کنید (چون `ensure_schema()` در `plugins_loaded` هم صدا زده می‌شود).

---

## 9) راه‌اندازی روی ساب‌دامین

پلاگین `heymode-wholesale` کاملاً مستقل از دامنه‌ی نصب کار می‌کند — هیچ آدرس/دامنه‌ای در کد Hardcode نشده و تمام Route‌ها با `register_rest_route()` + `rest_url()` وردپرس ساخته می‌شوند (این توابع همیشه دامنه‌ی *همان* سایتی را برمی‌گردانند که در آن اجرا می‌شوند، نه یک دامنه‌ی ثابت). یعنی نصب روی هر ساب‌دامین/دامنه‌ای (مثلاً `wholesale.example.com`) بدون هیچ تغییر کدی درست کار می‌کند.

**اما** یک قدم راه‌اندازی (نه کد) لازم است: روی یک نصب تازه‌ی وردپرس، اگر بعد از فعال‌سازی پلاگین (یا بعد از هر Migrate/Clone/تغییر دامنه) صفحه‌ی **Permalinks** حتی یک‌بار «Save» نشده باشد، rewrite rules مربوط به کل REST API وردپرس (نه فقط این پلاگین) ساخته نمی‌شوند و **هر** مسیر زیر `/wp-json/` — حتی `/wp-json/` خودِ وردپرس، بدون ربط به این پلاگین — با HTTP 404 (`rest_no_route`, «هیچ مسیری منطبق با URL و روش درخواست پیدا نشد») جواب می‌دهد.

**راه‌حل** (یک‌بار، بعد از هر نصب/آپدیت/Migrate روی دامنه‌ی جدید):
1. در وردپرسِ **همان سایتی که `heymode-wholesale` روی آن نصب است** برو به **Settings → Permalinks**.
2. فقط دکمه‌ی **«Save Changes»** را بزن (نیازی به تغییر گزینه‌ها نیست).
3. برای اطمینان، در پنل ادمین همان پلاگین (**HeyMode Wholesale → API مشتری**) دکمه‌ی **«بررسی سلامت REST API (خودِ این سایت)»** را بزن — این یک درخواست Loopback به `/wp-json/hmw/v1/health` خودِ همان سایت می‌زند و صریحاً می‌گوید مسیر شناخته شده یا نه، بدون نیاز به تست از سمت سایت مشتری.

اگر بعد از Flush کردن Permalinks همچنان `/wp-json/` (حتی بدون `hmw/v1`) روی آن سایت 404 می‌دهد، علت دیگری دارد که به این پلاگین ربطی ندارد: یک پلاگین امنیتی/فایروال (Wordfence، iThemes Security، …) یا محافظت سطح میزبانی (مثلاً محدودیت‌های محیط Staging) کل REST API وردپرس را مسدود کرده است.

---

## 10) فازهای بعدی (خارج از محدوده این نسخه)

- Import واقعی محصولات به ووکامرس مشتری (فاز ۲)
- صفحه Grid برای انتخاب/مدیریت محصولات Import‌شده (فاز ۲)
- Cron خودکار برای Import/به‌روزرسانی دوره‌ای در سایت مشتری با استفاده از `/products/delta` (فاز ۳)
- Override فرمول قیمت به ازای هر دسته‌بندی (لایه `resolve_price()` در `heymode-client-importer` از قبل برای این آماده شده است)
