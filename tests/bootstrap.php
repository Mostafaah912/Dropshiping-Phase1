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
define('HMW_VERSION', '1.9.0-test');
define('HMW_TIMEZONE', 'Asia/Tehran');
define('HCI_VERSION', '0.2.0-test');

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

function rest_sanitize_boolean($value): bool {
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

// --- HTTP API (فقط برای HCI_Source_Client::test_connection) ---------------

function is_wp_error($thing): bool {
    return $thing instanceof WP_Error;
}

function wp_remote_get(string $url, array $args = array()) {
    // پاسخ توسط هر تست، از طریق $GLOBALS['__stub_http_response'] تزریق می‌شود.
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
            last_synced_at TEXT NULL,
            import_status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NULL,
            updated_at TEXT NULL
        )
    SQL);
    return new Fake_WPDB($pdo);
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
