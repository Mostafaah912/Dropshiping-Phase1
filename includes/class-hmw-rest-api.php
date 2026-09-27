<?php

defined('ABSPATH') || exit;

final class HMW_REST_API {
    private const NAMESPACE = 'hmw/v1';
    private const KEY_HASH_OPTION = 'hmw_api_key_hash';
    private const KEY_PREFIX_OPTION = 'hmw_api_key_prefix';
    private const KEY_CREATED_OPTION = 'hmw_api_key_created_at';
    private const IP_ALLOWLIST_OPTION = 'hmw_api_ip_allowlist';
    private const REVOKED_OPTION = 'hmw_api_revoked_at';
    private const RATE_PREFIX = 'hmw_api_rate_';
    private const RATE_LIMIT = 120;
    private const RATE_WINDOW = 60;

    public static function init(): void {
        // The wholesale site has a global REST authentication hardening rule.
        // Let only our own /hmw/v1/* routes reach WordPress' normal REST routing;
        // the endpoint permission_callback still enforces the HMW API key.
        add_filter('rest_authentication_errors', array(__CLASS__, 'allow_hmw_route'), 99999);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function allow_hmw_route($result) {
        if (self::is_hmw_request()) {
            return null;
        }
        return $result;
    }

    private static function is_hmw_request(): bool {
        $route = isset($_GET['rest_route']) ? (string) wp_unslash($_GET['rest_route']) : '';
        if ($route !== '') {
            $route = '/' . ltrim(rawurldecode($route), '/');
            return (bool) preg_match('#^/hmw/v1(?:/|$)#', $route);
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        return (bool) preg_match(
            '#/' . preg_quote($prefix, '#') . '/hmw/v1(?:/|$)#',
            $path
        );
    }

    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'health'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));

        register_rest_route(self::NAMESPACE, '/products', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'products'),
                'permission_callback' => array(__CLASS__, 'authorize'),
                'args' => self::product_args(),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/products/delta', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'products_delta'),
                'permission_callback' => array(__CLASS__, 'authorize'),
                'args' => array(
                    'updated_after' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                    'page' => array('default' => 1, 'sanitize_callback' => 'absint'),
                    'per_page' => array('default' => 100, 'sanitize_callback' => 'absint'),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'product'),
                'permission_callback' => array(__CLASS__, 'authorize'),
                'args' => array(
                    'id' => array('required' => true, 'sanitize_callback' => 'absint'),
                ),
            ),
        ));
    }

    public static function authorize(WP_REST_Request $request) {
        if (!self::is_configured()) {
            return new WP_Error('hmw_api_not_configured', 'API Key تنظیم نشده است.', array('status' => 503));
        }

        if (!self::ip_allowed()) {
            return new WP_Error('hmw_api_ip_denied', 'دسترسی از این IP مجاز نیست.', array('status' => 403));
        }

        if (!self::rate_allowed()) {
            return new WP_Error('hmw_api_rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', array('status' => 429));
        }

        $token = self::extract_token($request);
        if ($token === '') {
            return new WP_Error('hmw_api_missing_key', 'API Key ارسال نشده است.', array('status' => 401));
        }

        $stored_hash = (string) get_option(self::KEY_HASH_OPTION, '');
        $candidate_hash = hash('sha256', $token);
        if ($stored_hash === '' || !hash_equals($stored_hash, $candidate_hash)) {
            return new WP_Error('hmw_api_invalid_key', 'API Key نامعتبر است.', array('status' => 401));
        }

        return true;
    }

    public static function products(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = HMW_DB::products_table();

        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        $where = array('1=1');
        $params = array();

        $include_inactive = rest_sanitize_boolean($request->get_param('include_inactive'));
        if (!$include_inactive) {
            $where[] = 'is_active = 1';
        }

        $type = sanitize_key((string) $request->get_param('type'));
        if ($type !== '') {
            $allowed = array('simple', 'variable', 'variation');
            if (!in_array($type, $allowed, true)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => array('code' => 'invalid_type', 'message' => 'type نامعتبر است.'),
                ), 400);
            }
            $where[] = 'product_type = %s';
            $params[] = $type;
        }

        $sku = trim((string) $request->get_param('sku'));
        if ($sku !== '') {
            $where[] = 'sku = %s';
            $params[] = $sku;
        }

        $source_id = (int) $request->get_param('source_product_id');
        if ($source_id > 0) {
            $where[] = 'source_product_id = %d';
            $params[] = $source_id;
        }

        $parent_id = (int) $request->get_param('parent_product_id');
        if ($parent_id > 0) {
            $where[] = 'parent_product_id = %d';
            $params[] = $parent_id;
        }

        $stock_status = sanitize_key((string) $request->get_param('stock_status'));
        if ($stock_status !== '') {
            $allowed_stock = array('instock', 'outofstock', 'onbackorder');
            if (!in_array($stock_status, $allowed_stock, true)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => array('code' => 'invalid_stock_status', 'message' => 'stock_status نامعتبر است.'),
                ), 400);
            }
            $where[] = 'stock_status = %s';
            $params[] = $stock_status;
        }

        $updated_after = self::parse_utc_datetime($request->get_param('updated_after'));
        if ($request->get_param('updated_after') !== null && $updated_after === null) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'invalid_updated_after', 'message' => 'updated_after باید تاریخ معتبر ISO 8601 یا Y-m-d H:i:s باشد.'),
            ), 400);
        }
        if ($updated_after !== null) {
            $where[] = 'updated_at > %s';
            $params[] = $updated_after;
        }

        $modified_after = self::parse_utc_datetime($request->get_param('modified_after'));
        if ($request->get_param('modified_after') !== null && $modified_after === null) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'invalid_modified_after', 'message' => 'modified_after باید تاریخ معتبر ISO 8601 یا Y-m-d H:i:s باشد.'),
            ), 400);
        }
        if ($modified_after !== null) {
            $where[] = 'source_modified_gmt > %s';
            $params[] = $modified_after;
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $data_sql = "SELECT source_product_id, parent_product_id, product_type, source_status, sku, name, price, stock_quantity, stock_status, manage_stock, image_url, product_url, category_path, short_description, gallery, category_ids, attributes, source_modified_gmt, is_active, last_synced_at, updated_at FROM {$table} WHERE {$where_sql} ORDER BY source_product_id ASC LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
            $rows = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, array($per_page, $offset))), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows = $wpdb->get_results($wpdb->prepare($data_sql, $per_page, $offset), ARRAY_A);
        }

        if (!is_array($rows)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'db_error', 'message' => 'خطا در خواندن دیتابیس.'),
            ), 500);
        }

        $category_map = HMW_Sync::get_cached_category_map();
        $variable_ids = array();
        foreach ($rows as $r) {
            if (($r['product_type'] ?? '') === 'variable') {
                $variable_ids[] = (int) $r['source_product_id'];
            }
        }
        $variations_by_parent = $variable_ids ? HMW_DB::get_variation_rows_for_parents($variable_ids, $include_inactive) : array();

        $items = array_map(static function (array $row) use ($category_map, $variations_by_parent): array {
            return HMW_REST_API::format_product($row, $category_map, $variations_by_parent);
        }, $rows);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

        $response = array(
            'success' => true,
            'data' => $items,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_previous' => $page > 1 && $total > 0,
            ),
            'filters' => array(
                'include_inactive' => $include_inactive,
                'type' => $type !== '' ? $type : null,
                'sku' => $sku !== '' ? $sku : null,
                'source_product_id' => $source_id > 0 ? $source_id : null,
                'parent_product_id' => $parent_id > 0 ? $parent_id : null,
                'stock_status' => $stock_status !== '' ? $stock_status : null,
                'updated_after' => $updated_after,
                'modified_after' => $modified_after,
            ),
            'server' => array(
                'generated_at_utc' => gmdate('c'),
                'generated_at_tehran' => wp_date('c', time(), new DateTimeZone(HMW_TIMEZONE)),
                'plugin_version' => HMW_VERSION,
            ),
        );

        return new WP_REST_Response($response, 200);
    }

    public static function product(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = HMW_DB::products_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT source_product_id, parent_product_id, product_type, source_status, sku, name, price, stock_quantity, stock_status, manage_stock, image_url, product_url, category_path, short_description, gallery, category_ids, attributes, source_modified_gmt, is_active, last_synced_at, updated_at FROM {$table} WHERE source_product_id = %d LIMIT 1",
            $id
        ), ARRAY_A);

        if (!$row) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => 'محصول پیدا نشد.'),
            ), 404);
        }

        $include_inactive = rest_sanitize_boolean($request->get_param('include_inactive'));
        if ((int) $row['is_active'] !== 1 && !$include_inactive) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => 'محصول فعال نیست.'),
            ), 404);
        }

        $category_map = HMW_Sync::get_cached_category_map();
        $variations_by_parent = array();
        if (($row['product_type'] ?? '') === 'variable') {
            $variations_by_parent = HMW_DB::get_variation_rows_for_parents(array((int) $row['source_product_id']), $include_inactive);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => self::format_product($row, $category_map, $variations_by_parent),
            'server' => array(
                'generated_at_utc' => gmdate('c'),
                'generated_at_tehran' => wp_date('c', time(), new DateTimeZone(HMW_TIMEZONE)),
                'plugin_version' => HMW_VERSION,
            ),
        ), 200);
    }

    public static function health(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = HMW_DB::products_table();
        $full_state = HMW_Sync::get_state();
        $last_log = $wpdb->get_row(
            'SELECT status, started_at, finished_at, products_fetched, products_updated, products_deactivated, errors_count FROM ' . HMW_DB::sync_logs_table() . " WHERE sync_type = 'full' ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            $table
        )) > 0;

        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'api' => 'ok',
                'database' => $table_exists ? 'ok' : 'missing',
                'full_sync_status' => (string) ($full_state['status'] ?? 'idle'),
                'total_records' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
                'active_records' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1"),
                'last_full_sync' => $last_log ? array(
                    'status' => $last_log['status'],
                    'started_at_utc' => $last_log['started_at'],
                    'finished_at_utc' => $last_log['finished_at'],
                    'products_fetched' => (int) $last_log['products_fetched'],
                    'products_updated' => (int) $last_log['products_updated'],
                    'products_deactivated' => (int) $last_log['products_deactivated'],
                    'errors_count' => (int) $last_log['errors_count'],
                ) : null,
            ),
            'server' => array(
                'generated_at_utc' => gmdate('c'),
                'generated_at_tehran' => wp_date('c', time(), new DateTimeZone(HMW_TIMEZONE)),
                'plugin_version' => HMW_VERSION,
            ),
        ), 200);
    }

    public static function generate_key(): string {
        $raw = 'hmw_' . wp_generate_uuid4() . '_' . bin2hex(random_bytes(24));
        $key = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        update_option(self::KEY_HASH_OPTION, hash('sha256', $key), false);
        update_option(self::KEY_PREFIX_OPTION, substr($key, 0, 12), false);
        update_option(self::KEY_CREATED_OPTION, current_time('mysql', true), false);
        delete_option(self::REVOKED_OPTION);
        return $key;
    }

    public static function revoke_key(): void {
        delete_option(self::KEY_HASH_OPTION);
        delete_option(self::KEY_PREFIX_OPTION);
        update_option(self::REVOKED_OPTION, current_time('mysql', true), false);
    }

    public static function is_configured(): bool {
        return (string) get_option(self::KEY_HASH_OPTION, '') !== '';
    }

    public static function get_key_prefix(): string {
        return (string) get_option(self::KEY_PREFIX_OPTION, '');
    }

    public static function get_key_created_at(): string {
        return (string) get_option(self::KEY_CREATED_OPTION, '');
    }

    public static function get_ip_allowlist(): array {
        $value = get_option(self::IP_ALLOWLIST_OPTION, array());
        return is_array($value) ? array_values(array_filter(array_map('trim', $value))) : array();
    }

    public static function set_ip_allowlist(string $text): void {
        $lines = preg_split('/[\r\n,]+/', $text) ?: array();
        $ips = array();
        foreach ($lines as $line) {
            $ip = trim($line);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        update_option(self::IP_ALLOWLIST_OPTION, array_values(array_unique($ips)), false);
    }

    public static function extract_token(WP_REST_Request $request): string {
        $authorization = trim((string) $request->get_header('authorization'));
        if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        $header = trim((string) $request->get_header('x-hmw-api-key'));
        return $header;
    }

    private static function ip_allowed(): bool {
        $allowlist = self::get_ip_allowlist();
        if (!$allowlist) {
            return true;
        }
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return $remote !== '' && in_array($remote, $allowlist, true);
    }

    private static function rate_allowed(): bool {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : 'unknown';
        $key = self::RATE_PREFIX . md5($ip);
        $bucket = get_transient($key);
        if (!is_array($bucket) || !isset($bucket['count'])) {
            set_transient($key, array('count' => 1), self::RATE_WINDOW);
            return true;
        }
        $count = (int) $bucket['count'];
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $bucket['count'] = $count + 1;
        set_transient($key, $bucket, self::RATE_WINDOW);
        return true;
    }

    private static function parse_utc_datetime($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $raw = trim((string) $value);
        try {
            $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function format_db_datetime_as_iso($value): ?string {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $ts = strtotime((string) $value . ' UTC');
        return $ts === false ? null : gmdate('c', $ts);
    }

    private static function product_args(): array {
        return array(
            'page' => array('default' => 1, 'sanitize_callback' => 'absint'),
            'per_page' => array('default' => 100, 'sanitize_callback' => 'absint'),
            'include_inactive' => array('default' => false, 'sanitize_callback' => 'rest_sanitize_boolean'),
            'type' => array('default' => '', 'sanitize_callback' => 'sanitize_key'),
            'sku' => array('default' => '', 'sanitize_callback' => 'sanitize_text_field'),
            'source_product_id' => array('default' => 0, 'sanitize_callback' => 'absint'),
            'parent_product_id' => array('default' => 0, 'sanitize_callback' => 'absint'),
            'stock_status' => array('default' => '', 'sanitize_callback' => 'sanitize_key'),
            'updated_after' => array('default' => null, 'sanitize_callback' => 'sanitize_text_field'),
            'modified_after' => array('default' => null, 'sanitize_callback' => 'sanitize_text_field'),
        );
    }

    private static function format_product(array $row, array $category_map = array(), array $variations_by_parent = array()): array {
        $category_ids = array();
        if (!empty($row['category_ids'])) {
            $decoded_ids = json_decode((string) $row['category_ids'], true);
            if (is_array($decoded_ids)) {
                $category_ids = array_map('intval', $decoded_ids);
            }
        }
        $category_path = $category_ids ? HMW_Source_API::category_tree($category_ids, $category_map) : array();

        $gallery = array();
        if (!empty($row['gallery'])) {
            $decoded_gallery = json_decode((string) $row['gallery'], true);
            if (is_array($decoded_gallery)) {
                $gallery = array_values(array_map('strval', $decoded_gallery));
            }
        }

        $formatted = array(
            'source_product_id' => (int) $row['source_product_id'],
            'parent_product_id' => !empty($row['parent_product_id']) ? (int) $row['parent_product_id'] : null,
            'product_type' => (string) $row['product_type'],
            'source_status' => (string) $row['source_status'],
            'sku' => $row['sku'] !== null ? (string) $row['sku'] : null,
            'name' => (string) $row['name'],
            'short_description' => (string) ($row['short_description'] ?? ''),
            'price' => $row['price'] !== null ? (string) $row['price'] : null,
            'stock_quantity' => $row['stock_quantity'] !== null ? (float) $row['stock_quantity'] : null,
            'stock_status' => (string) $row['stock_status'],
            'manage_stock' => (bool) $row['manage_stock'],
            'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'gallery' => $gallery,
            'product_url' => $row['product_url'] !== null ? (string) $row['product_url'] : null,
            'category_path' => $category_path,
            'source_modified_gmt' => self::format_db_datetime_as_iso($row['source_modified_gmt']),
            'is_active' => (bool) $row['is_active'],
            'last_synced_at_utc' => (string) $row['last_synced_at'],
            'updated_at_utc' => (string) $row['updated_at'],
        );

        if ((string) $row['product_type'] === 'variable') {
            $attributes = array();
            if (!empty($row['attributes'])) {
                $decoded_attrs = json_decode((string) $row['attributes'], true);
                if (is_array($decoded_attrs)) {
                    $attributes = $decoded_attrs;
                }
            }
            $formatted['attributes'] = $attributes;

            $children = $variations_by_parent[(int) $row['source_product_id']] ?? array();
            $formatted['variations'] = array_map(static function (array $child): array {
                $child_attributes = array();
                if (!empty($child['attributes'])) {
                    $decoded_child_attrs = json_decode((string) $child['attributes'], true);
                    if (is_array($decoded_child_attrs)) {
                        $child_attributes = $decoded_child_attrs;
                    }
                }
                return array(
                    'variation_id' => (int) $child['source_product_id'],
                    'attributes' => $child_attributes,
                    'price' => $child['price'] !== null ? (string) $child['price'] : null,
                    'stock_quantity' => $child['stock_quantity'] !== null ? (float) $child['stock_quantity'] : null,
                    'sku' => $child['sku'] !== null ? (string) $child['sku'] : null,
                );
            }, $children);
        }

        return $formatted;
    }

    public static function products_delta(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = HMW_DB::products_table();

        $updated_after = self::parse_utc_datetime($request->get_param('updated_after'));
        if ($updated_after === null) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'invalid_updated_after', 'message' => 'updated_after الزامی است و باید تاریخ معتبر ISO 8601 یا Y-m-d H:i:s باشد.'),
            ), 400);
        }

        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(200, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE updated_at > %s AND is_active = 1",
            $updated_after
        ));

        $changed = $wpdb->get_results($wpdb->prepare(
            "SELECT source_product_id, parent_product_id, sku, price, stock_quantity FROM {$table} WHERE updated_at > %s AND is_active = 1 ORDER BY source_product_id ASC LIMIT %d OFFSET %d",
            $updated_after,
            $per_page,
            $offset
        ), ARRAY_A);
        if (!is_array($changed)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array('code' => 'db_error', 'message' => 'خطا در خواندن دیتابیس.'),
            ), 500);
        }

        $items_by_id = array();
        $base_filled = array();
        $needed_parent_ids = array();

        foreach ($changed as $row) {
            $parent_id = !empty($row['parent_product_id']) ? (int) $row['parent_product_id'] : null;

            if ($parent_id === null) {
                $sid = (int) $row['source_product_id'];
                $items_by_id[$sid] = array_merge(
                    $items_by_id[$sid] ?? array(),
                    self::delta_item($row)
                );
                $base_filled[$sid] = true;
                continue;
            }

            if (!isset($items_by_id[$parent_id])) {
                $items_by_id[$parent_id] = array(
                    'source_product_id' => $parent_id,
                    'sku' => null,
                    'price' => null,
                    'stock_quantity' => null,
                    'variations' => array(),
                );
            }
            $items_by_id[$parent_id]['variations'][] = array(
                'variation_id' => (int) $row['source_product_id'],
                'sku' => $row['sku'] !== null ? (string) $row['sku'] : null,
                'price' => $row['price'] !== null ? (string) $row['price'] : null,
                'stock_quantity' => $row['stock_quantity'] !== null ? (float) $row['stock_quantity'] : null,
            );
            if (empty($base_filled[$parent_id])) {
                $needed_parent_ids[] = $parent_id;
            }
        }

        $needed_parent_ids = array_values(array_diff(array_unique($needed_parent_ids), array_keys($base_filled)));
        if ($needed_parent_ids) {
            $placeholders = implode(',', array_fill(0, count($needed_parent_ids), '%d'));
            $parent_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT source_product_id, sku, price, stock_quantity FROM {$table} WHERE source_product_id IN ({$placeholders})",
                $needed_parent_ids
            ), ARRAY_A);
            foreach ((array) $parent_rows as $prow) {
                $pid = (int) $prow['source_product_id'];
                if (!isset($items_by_id[$pid])) {
                    continue;
                }
                $items_by_id[$pid]['sku'] = $prow['sku'] !== null ? (string) $prow['sku'] : null;
                $items_by_id[$pid]['price'] = $prow['price'] !== null ? (string) $prow['price'] : null;
                $items_by_id[$pid]['stock_quantity'] = $prow['stock_quantity'] !== null ? (float) $prow['stock_quantity'] : null;
            }
        }

        $items = array_values($items_by_id);
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $items,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_previous' => $page > 1 && $total > 0,
            ),
            'filters' => array(
                'updated_after' => $updated_after,
            ),
            'server' => array(
                'generated_at_utc' => gmdate('c'),
                'generated_at_tehran' => wp_date('c', time(), new DateTimeZone(HMW_TIMEZONE)),
                'plugin_version' => HMW_VERSION,
            ),
        ), 200);
    }

    private static function delta_item(array $row): array {
        return array(
            'source_product_id' => (int) $row['source_product_id'],
            'sku' => $row['sku'] !== null ? (string) $row['sku'] : null,
            'price' => $row['price'] !== null ? (string) $row['price'] : null,
            'stock_quantity' => $row['stock_quantity'] !== null ? (float) $row['stock_quantity'] : null,
        );
    }
}
