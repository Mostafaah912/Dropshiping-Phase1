<?php
/**
 * Bootstrap سبک برای اجرای منطق واقعی پلاگین‌ها بدون WordPress/MySQL واقعی.
 * هیچ منطق تجاری اینجا بازنویسی نمی‌شود؛ فقط حداقل توابع/کلاس‌های WordPress
 * که کلاس‌های production به آن‌ها نیاز دارند Stub می‌شوند، و $wpdb با یک
 * پیاده‌سازی SQLite واقعی (نه Mock بی‌معنا) پشتیبانی می‌شود تا کوئری‌های
 * واقعی SQL اجرا و بررسی شوند.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fake-wp/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('HMW_VERSION', '1.9.1-test');
define('HMW_TIMEZONE', 'Asia/Tehran');
define('HCI_VERSION', '0.3.0-test');

// ---------------------------------------------------------------------------
// توابع عمومی WordPress (حداقلی، فقط آنچه فایل‌های production واقعاً صدا می‌زنند)
// ---------------------------------------------------------------------------

$GLOBALS['__test_options'] = array();

function get_option(string $name, $default = false) {
    return $GLOBALS['__test_options'][$name] ?? $default;
}

function update_option(string $name, $value, $autoload = null): bool {
    $GLOBALS['__test_options'][$name] = $value;
    return true;
}

function delete_option(string $name): bool {
    unset($GLOBALS['__test_options'][$name]);
    return true;
}

function set_transient(string $key, $value, int $expiration = 0): bool {
    $GLOBALS['__test_options']['_transient_' . $key] = $value;
    return true;
}

function get_transient(string $key) {
    return $GLOBALS['__test_options']['_transient_' . $key] ?? false;
}

function delete_transient(string $key): bool {
    unset($GLOBALS['__test_options']['_transient_' . $key]);
    return true;
}

function wp_json_encode($data, int $options = 0) {
    return json_encode($data, $options);
}

function current_time(string $type, bool $gmt = false) {
    if ($type === 'timestamp') {
        return time();
    }
    return gmdate('Y-m-d H:i:s');
}

function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string {
    $dt = new DateTimeImmutable('@' . ($timestamp ?? time()));
    if ($timezone) {
        $dt = $dt->setTimezone($timezone);
    }
    return $dt->format($format);
}

function sanitize_key(string $key): string {
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
}

function esc_attr($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_html($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea($text): string {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url): string {
    return (string) $url; // ساده‌شده؛ فقط برای رندر HTML در تست، نه Sanitization واقعی
}

function esc_html__($text, $domain = 'default') {
    return $text;
}

function disabled($value, $compare = true, bool $echo = true): string {
    $result = ((string) $value === (string) $compare) ? ' disabled="disabled"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}

function checked($value, $compare = true, bool $echo = true): string {
    $result = ((string) $value === (string) $compare) ? ' checked="checked"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}

function selected($value, $compare = true, bool $echo = true): string {
    $result = ((string) $value === (string) $compare) ? ' selected="selected"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}

function rest_sanitize_boolean($value): bool {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Recorder-هایی برای add_action/add_filter/register_rest_route — یک موتور
 * Hook واقعی وردپرس نیستند، فقط هرچه ثبت می‌شود را نگه می‌دارند تا تست بتواند
 * verify کند «چه چیزی، با چه Namespace/Route‌ای ثبت شد»، بدون نیاز به هسته
 * وردپرس. do_action_test() برای فراخوانی واقعیِ callbackهای ثبت‌شده است (مثلاً
 * برای شبیه‌سازی این‌که rest_api_init واقعاً fire شده).
 */
$GLOBALS['__test_hooks'] = array();
$GLOBALS['__test_registered_routes'] = array();

function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['__test_hooks'][$hook][] = $callback;
}

function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
    $GLOBALS['__test_hooks'][$hook][] = $callback;
}

function do_action_test(string $hook): void {
    foreach ($GLOBALS['__test_hooks'][$hook] ?? array() as $callback) {
        call_user_func($callback);
    }
}

function register_rest_route(string $namespace, string $route, $args): void {
    $GLOBALS['__test_registered_routes'][] = array(
        'namespace' => $namespace,
        'route' => $route,
        'args' => $args,
    );
}

function rest_url(string $path = ''): string {
    return 'https://example-test-site.invalid/wp-json/' . ltrim($path, '/');
}

/**
 * پیاده‌سازی وفادار add_query_arg(): چه URL ورودی از قبل یک query string
 * داشته باشد (مثل حالت Plain Permalinks: '?rest_route=/hmw/v1') چه نداشته
 * باشد (Pretty Permalinks)، پارامترهای جدید را با merge درست اضافه می‌کند —
 * برخلاف ساخت دستی با یک '?' اضافه که در همان حالت اول کوئری‌استرینگ را
 * خراب می‌کرد (باگ واقعی HCI_Source_Client::fetch_products_page()).
 */
function add_query_arg(...$args): string {
    if (count($args) === 2 && is_array($args[0])) {
        [$params, $url] = $args;
    } elseif (count($args) === 3) {
        [$key, $value, $url] = $args;
        $params = array($key => $value);
    } else {
        throw new InvalidArgumentException('Unsupported add_query_arg() stub signature');
    }

    $parts = parse_url((string) $url);
    parse_str($parts['query'] ?? '', $existing);
    $merged = array_merge($existing, $params);

    $base = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
    $query = http_build_query($merged);
    return $query !== '' ? $base . '?' . $query : $base;
}

// --- HTTP API (فقط برای HCI_Source_Client::test_connection) ---------------

function is_wp_error($thing): bool {
    return $thing instanceof WP_Error;
}

function wp_remote_get(string $url, array $args = array()) {
    $GLOBALS['__test_last_requested_urls'][] = $url;
    // برای سناریوهایی که هر فراخوانی متوالی باید پاسخ متفاوتی بدهد (مثلاً
    // ۴۲۹ در یک درخواست و ۲۰۰ در بعدی)، یک صف اختیاری از پاسخ‌ها را هم
    // پشتیبانی می‌کنیم؛ اگر خالی/تنظیم‌نشده باشد، رفتار قبلی (یک پاسخ ثابت
    // از __stub_http_response) دست‌نخورده می‌ماند.
    if (!empty($GLOBALS['__stub_http_response_queue'])) {
        return array_shift($GLOBALS['__stub_http_response_queue']);
    }
    return $GLOBALS['__stub_http_response'] ?? array(
        'response' => array('code' => 0, 'message' => ''),
        'body' => '',
    );
}

function wp_remote_retrieve_response_code($response): int {
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string {
    return (string) ($response['body'] ?? '');
}

function wp_remote_retrieve_response_message($response): string {
    return (string) ($response['response']['message'] ?? '');
}

// ---------------------------------------------------------------------------
// کلاس‌های WordPress حداقلی
// ---------------------------------------------------------------------------

final class WP_Error {
    private string $code;
    private string $message;
    private array $data;

    public function __construct(string $code = '', string $message = '', $data = array()) {
        $this->code = $code;
        $this->message = $message;
        $this->data = is_array($data) ? $data : array();
    }

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_data() {
        return $this->data;
    }
}

final class WP_REST_Server {
    public const READABLE = 'GET';
}

final class WP_REST_Request {
    private array $params;

    public function __construct(array $params = array()) {
        $this->params = $params;
    }

    public function get_param(string $key) {
        return $this->params[$key] ?? null;
    }
}

final class WP_REST_Response {
    private $data;
    private int $status;

    public function __construct($data = null, int $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data() {
        return $this->data;
    }

    public function get_status(): int {
        return $this->status;
    }
}

// ---------------------------------------------------------------------------
// Fake $wpdb — پشتیبانی واقعی SQLite (PDO)، نه یک Mock بی‌معنا.
// فقط زیرمجموعه‌ای از رفتار wpdb که کد production واقعاً استفاده می‌کند.
// ---------------------------------------------------------------------------

final class Fake_WPDB {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public int $insert_id = 0;
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $result = preg_replace_callback('/%[sdf]/', function (array $m) use (&$i, $args): string {
            $value = $args[$i] ?? null;
            $i++;
            if ($m[0] === '%d') {
                return (string) (int) $value;
            }
            if ($m[0] === '%f') {
                return (string) (float) $value;
            }
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
        return $result ?? $query;
    }

    public function get_var(string $query) {
        $stmt = $this->pdo->query($query);
        if ($stmt === false) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    public function get_row(string $query, string $output = ARRAY_A) {
        $stmt = $this->pdo->query($query);
        if ($stmt === false) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function get_results(string $query, string $output = ARRAY_A): array {
        $stmt = $this->pdo->query($query);
        if ($stmt === false) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return array();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
    }

    public function get_col(string $query): array {
        $stmt = $this->pdo->query($query);
        if ($stmt === false) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return array();
        }
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
    }

    public function query(string $query) {
        $result = $this->pdo->exec($query);
        if ($result === false) {
            $this->last_error = implode(' ', $this->pdo->errorInfo());
            return false;
        }
        return $result;
    }

    public function insert(string $table, array $data): int|false {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute(array_values($data));
        if ($ok) {
            $this->insert_id = (int) $this->pdo->lastInsertId();
        }
        return $ok ? 1 : false;
    }

    public function update(string $table, array $data, array $where): int|false {
        $set = array();
        $values = array();
        foreach ($data as $col => $val) {
            $set[] = "{$col} = ?";
            $values[] = $val;
        }
        $conditions = array();
        foreach ($where as $col => $val) {
            $conditions[] = "{$col} = ?";
            $values[] = $val;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', $set) . ' WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($values);
        return $ok ? $stmt->rowCount() : false;
    }
}

// ---------------------------------------------------------------------------
// راه‌اندازی دیتابیس تست (SQLite در حافظه) با Schema واقعی جدول محصولات
// ---------------------------------------------------------------------------

function hmw_test_create_products_db(): Fake_WPDB {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
        CREATE TABLE wp_hmw_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_product_id INTEGER NOT NULL UNIQUE,
            parent_product_id INTEGER NULL,
            product_type TEXT NOT NULL DEFAULT 'simple',
            source_status TEXT NOT NULL DEFAULT 'publish',
            sku TEXT NULL,
            name TEXT NOT NULL DEFAULT '',
            price TEXT NULL,
            stock_quantity TEXT NULL,
            stock_status TEXT NOT NULL DEFAULT 'outofstock',
            manage_stock INTEGER NOT NULL DEFAULT 0,
            image_url TEXT NULL,
            product_url TEXT NULL,
            category_path TEXT NULL,
            short_description TEXT NULL,
            gallery TEXT NULL,
            category_ids TEXT NULL,
            attributes TEXT NULL,
            source_modified_gmt TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 0,
            last_sync_run_uuid TEXT NULL,
            last_synced_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )
    SQL);
    return new Fake_WPDB($pdo);
}

function hmw_test_insert_product(Fake_WPDB $wpdb, array $row): void {
    $defaults = array(
        'parent_product_id' => null,
        'product_type' => 'simple',
        'source_status' => 'publish',
        'sku' => null,
        'name' => 'Test Product',
        'price' => null,
        'stock_quantity' => null,
        'stock_status' => 'instock',
        'manage_stock' => 1,
        'image_url' => null,
        'product_url' => null,
        'category_path' => '',
        'short_description' => '',
        'gallery' => '',
        'category_ids' => '',
        'attributes' => '',
        'source_modified_gmt' => null,
        'is_active' => 1,
        'last_sync_run_uuid' => '',
        'last_synced_at' => '2026-09-27 00:00:00',
        'created_at' => '2026-09-27 00:00:00',
        'updated_at' => '2026-09-27 00:00:00',
    );
    $wpdb->insert('wp_hmw_products', array_merge($defaults, $row));
}

function hci_test_create_product_map_db(): Fake_WPDB {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
        CREATE TABLE wp_hci_product_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_product_id INTEGER NOT NULL,
            source_sku TEXT NULL,
            source_variation_id INTEGER NULL,
            dest_product_id INTEGER NULL,
            dest_variation_id INTEGER NULL,
            dest_sku TEXT NULL,
            import_payload TEXT NULL,
            error_message TEXT NULL,
            last_synced_at TEXT NULL,
            import_status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NULL,
            updated_at TEXT NULL
        )
    SQL);
    return new Fake_WPDB($pdo);
}

// ---------------------------------------------------------------------------
// لایه Fake WooCommerce/WP — فقط زیرمجموعه‌ای از توابع/کلاس‌های واقعی
// WooCommerce که class-hci-import.php/class-hci-sync.php واقعاً صدا می‌زنند،
// پشت یک فروشگاه ساده در حافظه ($GLOBALS). هدف تست‌کردن منطق خودِ ما
// (SKU/Duplicate/Category/Price/Stock) است، نه بازسازی کامل ووکامرس.
// ---------------------------------------------------------------------------

$GLOBALS['__fake_wc_products'] = array();
$GLOBALS['__fake_wc_next_id'] = 1000;
$GLOBALS['__fake_wc_sku_index'] = array();
$GLOBALS['__fake_wc_terms'] = array();
$GLOBALS['__fake_wc_next_term_id'] = 1;
$GLOBALS['__fake_postmeta'] = array();
$GLOBALS['__fake_media_sideload_fail_urls'] = array();
$GLOBALS['__fake_as_single_actions'] = array();
$GLOBALS['__fake_as_recurring_actions'] = array();

function hci_test_reset_wc_fakes(): void {
    $GLOBALS['__fake_wc_products'] = array();
    $GLOBALS['__fake_wc_next_id'] = 1000;
    $GLOBALS['__fake_wc_sku_index'] = array();
    $GLOBALS['__fake_wc_terms'] = array();
    $GLOBALS['__fake_wc_next_term_id'] = 1;
    $GLOBALS['__fake_postmeta'] = array();
    $GLOBALS['__fake_media_sideload_fail_urls'] = array();
    $GLOBALS['__fake_as_single_actions'] = array();
    $GLOBALS['__fake_as_recurring_actions'] = array();
}

class WC_Product {
    protected int $id = 0;
    protected string $product_type = 'simple';
    protected array $data = array(
        'status' => 'draft',
        'name' => '',
        'short_description' => '',
        'sku' => '',
        'regular_price' => '',
        'price' => '',
        'manage_stock' => false,
        'stock_quantity' => null,
        'stock_status' => 'outofstock',
        'category_ids' => array(),
        'image_id' => 0,
        'gallery_image_ids' => array(),
        'parent_id' => 0,
        'attributes' => array(),
    );

    public function __construct(int $id = 0) {
        if ($id > 0 && isset($GLOBALS['__fake_wc_products'][$id])) {
            $this->id = $id;
            $this->data = $GLOBALS['__fake_wc_products'][$id]['data'];
            $this->product_type = $GLOBALS['__fake_wc_products'][$id]['type'];
        }
    }

    public function get_id(): int { return $this->id; }
    public function get_type(): string { return $this->product_type; }

    public function set_status(string $v): void { $this->data['status'] = $v; }
    public function get_status(): string { return $this->data['status']; }
    public function set_name(string $v): void { $this->data['name'] = $v; }
    public function get_name(): string { return $this->data['name']; }
    public function set_short_description(string $v): void { $this->data['short_description'] = $v; }
    public function get_short_description(): string { return $this->data['short_description']; }
    public function set_sku(string $v): void { $this->data['sku'] = $v; }
    public function get_sku(): string { return $this->data['sku']; }
    public function set_regular_price(string $v): void { $this->data['regular_price'] = $v; }
    public function get_regular_price(): string { return $this->data['regular_price']; }
    public function set_price(string $v): void { $this->data['price'] = $v; }
    public function get_price(): string { return $this->data['price']; }
    public function set_manage_stock(bool $v): void { $this->data['manage_stock'] = $v; }
    public function get_manage_stock(): bool { return $this->data['manage_stock']; }
    public function set_stock_quantity($v): void { $this->data['stock_quantity'] = $v; }
    public function get_stock_quantity() { return $this->data['stock_quantity']; }
    public function set_stock_status(string $v): void { $this->data['stock_status'] = $v; }
    public function get_stock_status(): string { return $this->data['stock_status']; }
    public function set_category_ids(array $v): void { $this->data['category_ids'] = $v; }
    public function get_category_ids(): array { return $this->data['category_ids']; }
    public function set_image_id($v): void { $this->data['image_id'] = $v; }
    public function get_image_id() { return $this->data['image_id']; }
    public function set_gallery_image_ids(array $v): void { $this->data['gallery_image_ids'] = $v; }
    public function get_gallery_image_ids(): array { return $this->data['gallery_image_ids']; }
    public function set_parent_id($v): void { $this->data['parent_id'] = $v; }
    public function get_parent_id() { return $this->data['parent_id']; }
    public function set_attributes(array $v): void { $this->data['attributes'] = $v; }
    public function get_attributes(): array { return $this->data['attributes']; }

    public function save(): int {
        if ($this->id <= 0) {
            $this->id = $GLOBALS['__fake_wc_next_id']++;
        }
        $GLOBALS['__fake_wc_products'][$this->id] = array('type' => $this->product_type, 'data' => $this->data);
        if (!empty($this->data['sku'])) {
            $GLOBALS['__fake_wc_sku_index'][$this->data['sku']] = $this->id;
        }
        return $this->id;
    }
}

class WC_Product_Simple extends WC_Product {
    protected string $product_type = 'simple';
}

class WC_Product_Variable extends WC_Product {
    protected string $product_type = 'variable';
}

class WC_Product_Variation extends WC_Product {
    protected string $product_type = 'variation';
}

class WC_Product_Attribute {
    private int $id = 0;
    private string $name = '';
    private array $options = array();
    private bool $visible = false;
    private bool $variation = false;

    public function set_id(int $v): void { $this->id = $v; }
    public function get_id(): int { return $this->id; }
    public function set_name(string $v): void { $this->name = $v; }
    public function get_name(): string { return $this->name; }
    public function set_options(array $v): void { $this->options = $v; }
    public function get_options(): array { return $this->options; }
    public function set_visible(bool $v): void { $this->visible = $v; }
    public function get_visible(): bool { return $this->visible; }
    public function set_variation(bool $v): void { $this->variation = $v; }
    public function get_variation(): bool { return $this->variation; }
}

function wc_get_product(int $id) {
    if (!isset($GLOBALS['__fake_wc_products'][$id])) {
        return false;
    }
    $type = $GLOBALS['__fake_wc_products'][$id]['type'];
    $class = $type === 'variable' ? 'WC_Product_Variable' : ($type === 'variation' ? 'WC_Product_Variation' : 'WC_Product_Simple');
    return new $class($id);
}

function wc_get_product_id_by_sku(string $sku): int {
    return (int) ($GLOBALS['__fake_wc_sku_index'][$sku] ?? 0);
}

function term_exists($term, string $taxonomy = '', $parent = null) {
    foreach ($GLOBALS['__fake_wc_terms'][$taxonomy] ?? array() as $term_id => $t) {
        if ($t['name'] === $term && (int) $t['parent'] === (int) ($parent ?? 0)) {
            return array('term_id' => $term_id, 'term_taxonomy_id' => $term_id);
        }
    }
    return null;
}

function wp_insert_term(string $term, string $taxonomy = '', array $args = array()) {
    $term_id = $GLOBALS['__fake_wc_next_term_id']++;
    $GLOBALS['__fake_wc_terms'][$taxonomy][$term_id] = array('name' => $term, 'parent' => (int) ($args['parent'] ?? 0));
    return array('term_id' => $term_id, 'term_taxonomy_id' => $term_id);
}

function get_posts(array $args = array()) {
    if (($args['post_type'] ?? '') === 'attachment' && !empty($args['meta_key']) && array_key_exists('meta_value', $args)) {
        $matches = array();
        foreach ($GLOBALS['__fake_postmeta'] as $post_id => $meta) {
            if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
                $matches[] = $post_id;
            }
        }
        return $matches;
    }
    return array();
}

/** برای تست: هر URL در این لیست، شکست دانلود را شبیه‌سازی می‌کند. */
function hci_test_fail_image_download(string $url): void {
    $GLOBALS['__fake_media_sideload_fail_urls'][$url] = true;
}

function media_sideload_image(string $url, $post_id = 0, $desc = null, string $return_type = 'html') {
    if (!empty($GLOBALS['__fake_media_sideload_fail_urls'][$url])) {
        return new WP_Error('media_sideload_image_failed', 'دانلود تصویر شبیه‌سازی‌شده شکست خورد.');
    }
    $id = $GLOBALS['__fake_wc_next_id']++;
    $GLOBALS['__fake_postmeta'][$id] = $GLOBALS['__fake_postmeta'][$id] ?? array();
    return $id;
}

function update_post_meta(int $post_id, string $key, $value): bool {
    $GLOBALS['__fake_postmeta'][$post_id][$key] = $value;
    return true;
}

function get_post_meta(int $post_id, string $key = '', bool $single = false) {
    if ($key === '') {
        return $GLOBALS['__fake_postmeta'][$post_id] ?? array();
    }
    $value = $GLOBALS['__fake_postmeta'][$post_id][$key] ?? ($single ? '' : array());
    return $single ? $value : array($value);
}

function sanitize_title(string $s): string {
    return strtolower(trim(preg_replace('/\s+/', '-', $s) ?? $s));
}

function wp_kses_post(string $s): string {
    return $s;
}

function esc_url_raw(string $s): string {
    return $s;
}

function wp_die($message = ''): void {
    throw new RuntimeException(is_string($message) ? $message : 'wp_die');
}

// --- Action Scheduler (WooCommerce) — فقط Recorder، هیچ Queue واقعی اجرا نمی‌کند ---

function as_schedule_single_action(int $timestamp, string $hook, array $args = array(), string $group = ''): int {
    $GLOBALS['__fake_as_single_actions'][] = compact('timestamp', 'hook', 'args', 'group');
    return count($GLOBALS['__fake_as_single_actions']);
}

function as_schedule_recurring_action(int $timestamp, int $interval, string $hook, array $args = array(), string $group = ''): int {
    $GLOBALS['__fake_as_recurring_actions'][] = compact('timestamp', 'interval', 'hook', 'args', 'group');
    return count($GLOBALS['__fake_as_recurring_actions']);
}

function as_next_scheduled_action(string $hook, $args = null, string $group = '') {
    foreach ($GLOBALS['__fake_as_single_actions'] as $a) {
        if ($a['hook'] === $hook) {
            return $a['timestamp'];
        }
    }
    foreach ($GLOBALS['__fake_as_recurring_actions'] as $a) {
        if ($a['hook'] === $hook) {
            return $a['timestamp'];
        }
    }
    return false;
}

/**
 * کد Production همیشه این تابع را فقط با $hook (بدون args/group) صدا می‌زند
 * تا مسیر واقعی Bulk-Cancel-By-Hook خودِ Action Scheduler را بگیرد (همه
 * نمونه‌های آن Hook را صرف‌نظر از آرگومان پاک می‌کند) — همین رفتار اینجا هم
 * شبیه‌سازی شده؛ فراخوانی با args/group مشخص در این Stub ساده پشتیبانی نشده
 * چون هیچ‌جای Production این‌طور صدایش نمی‌زند.
 */
function as_unschedule_all_actions(string $hook, array $args = array(), string $group = ''): void {
    if (!empty($args) || !empty($group)) {
        return;
    }
    $GLOBALS['__fake_as_single_actions'] = array_values(array_filter(
        $GLOBALS['__fake_as_single_actions'],
        static fn (array $a): bool => $a['hook'] !== $hook
    ));
    $GLOBALS['__fake_as_recurring_actions'] = array_values(array_filter(
        $GLOBALS['__fake_as_recurring_actions'],
        static fn (array $a): bool => $a['hook'] !== $hook
    ));
}

// ---------------------------------------------------------------------------
// بارگذاری فایل‌های واقعی production (بدون تغییر منطق)
// ---------------------------------------------------------------------------

define('HMW_REPO_ROOT', dirname(__DIR__));
define('HCI_REPO_ROOT', dirname(__DIR__) . '/heymode-client-importer');

require_once HMW_REPO_ROOT . '/includes/class-hmw-db.php';
require_once HMW_REPO_ROOT . '/includes/class-hmw-source-api.php';
require_once HMW_REPO_ROOT . '/includes/class-hmw-rest-api.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-db.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-source-client.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-pricing.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-products.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-import.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-sync.php';
require_once HCI_REPO_ROOT . '/includes/class-hci-admin.php';

// ---------------------------------------------------------------------------
// Assertion helpers ساده
// ---------------------------------------------------------------------------

$GLOBALS['__test_failures'] = 0;
$GLOBALS['__test_passes'] = 0;

function test_assert(bool $condition, string $label): void {
    if ($condition) {
        $GLOBALS['__test_passes']++;
        echo "  [PASS] {$label}\n";
    } else {
        $GLOBALS['__test_failures']++;
        echo "  [FAIL] {$label}\n";
    }
}

function test_section(string $title): void {
    echo "\n=== {$title} ===\n";
}

function test_evidence(string $label, $value): void {
    echo "  [EVIDENCE] {$label}:\n";
    echo '    ' . str_replace("\n", "\n    ", json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
}
