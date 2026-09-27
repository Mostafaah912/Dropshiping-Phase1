<?php

defined('ABSPATH') || exit;

final class HMW_Source_API {
    private static function request(string $path, array $query = array(), int $timeout = 20): array {
        if (!self::is_configured()) {
            return array('success' => false, 'message' => 'Credentialهای WooCommerce در wp-config.php تعریف نشده‌اند.', 'api_requests' => 0);
        }

        $url = rtrim((string) HMW_SOURCE_URL, '/') . '/wp-json/wc/v3/' . ltrim($path, '/');
        if ($query) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'redirection' => 2,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode((string) HMW_WC_CONSUMER_KEY . ':' . (string) HMW_WC_CONSUMER_SECRET),
                'Accept' => 'application/json',
            ),
            'user-agent' => 'HeyMode-Wholesale/' . HMW_VERSION,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'خطا در ارتباط با هی‌مد: ' . $response->get_error_message(),
                'api_requests' => 1,
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = array(
            'total' => (int) wp_remote_retrieve_header($response, 'X-WP-Total'),
            'total_pages' => (int) wp_remote_retrieve_header($response, 'X-WP-TotalPages'),
        );

        if ($status < 200 || $status >= 300) {
            $message = wp_remote_retrieve_response_message($response);
            $detail = '';
            $json_error = json_decode($body, true);
            if (is_array($json_error)) {
                if (!empty($json_error['message'])) {
                    $detail .= ' — ' . wp_strip_all_tags((string) $json_error['message']);
                }
                if (!empty($json_error['code'])) {
                    $detail .= ' [' . sanitize_key((string) $json_error['code']) . ']';
                }
            }
            return array(
                'success' => false,
                'message' => sprintf('پاسخ WooCommerce: HTTP %d%s%s', $status, $message ? ' — ' . $message : '', $detail),
                'status' => $status,
                'headers' => $headers,
                'api_requests' => 1,
            );
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return array(
                'success' => false,
                'message' => 'پاسخ WooCommerce JSON معتبر نیست.',
                'status' => $status,
                'api_requests' => 1,
            );
        }

        return array(
            'success' => true,
            'status' => $status,
            'data' => $json,
            'headers' => $headers,
            'api_requests' => 1,
        );
    }

    public static function is_configured(): bool {
        return defined('HMW_SOURCE_URL') && defined('HMW_WC_CONSUMER_KEY') && defined('HMW_WC_CONSUMER_SECRET')
            && HMW_SOURCE_URL !== '' && HMW_WC_CONSUMER_KEY !== '' && HMW_WC_CONSUMER_SECRET !== '';
    }

    public static function test_connection(): array {
        return self::request('products', array(
            'status' => 'publish',
            'per_page' => 1,
            'page' => 1,
            '_fields' => 'id,date_modified_gmt',
        ), 10);
    }

    public static function get_categories_map(): array {
        $map = array();
        $page = 1;
        while ($page <= 1000) {
            $result = self::request('products/categories', array(
                'page' => $page,
                'per_page' => 100,
                'orderby' => 'id',
                'order' => 'asc',
                '_fields' => 'id,name,parent',
            ));
            if (!$result['success']) {
                return $result;
            }
            $items = is_array($result['data']) ? $result['data'] : array();
            foreach ($items as $item) {
                if (isset($item['id'])) {
                    $map[(int) $item['id']] = array(
                        'name' => (string) ($item['name'] ?? ''),
                        'parent' => (int) ($item['parent'] ?? 0),
                    );
                }
            }
            $total_pages = !empty($result['headers']['total_pages']) ? (int) $result['headers']['total_pages'] : $page;
            if ($page >= $total_pages || empty($items)) {
                break;
            }
            $page++;
        }
        return array('success' => true, 'map' => $map, 'api_requests' => count($map));
    }

    public static function get_products_page(int $page, int $per_page = 100): array {
        return self::request('products', array(
            'status' => 'publish',
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $per_page)),
            'orderby' => 'id',
            'order' => 'asc',
            '_fields' => 'id,parent,type,status,sku,name,short_description,price,stock_quantity,stock_status,manage_stock,images,permalink,categories,attributes,date_modified_gmt',
        ));
    }

    public static function get_products_modified_page(int $page, int $per_page, string $after_gmt, string $before_gmt): array {
        return self::request('products', array(
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $per_page)),
            'orderby' => 'modified',
            'order' => 'asc',
            'modified_after' => self::iso($after_gmt),
            'modified_before' => self::iso($before_gmt),
            'dates_are_gmt' => 'true',
            '_fields' => 'id,parent,type,status,date_modified_gmt',
        ));
    }

    public static function get_product(int $product_id): array {
        return self::request('products/' . $product_id, array(
            '_fields' => 'id,parent,type,status,sku,name,short_description,price,stock_quantity,stock_status,manage_stock,images,permalink,categories,attributes,date_modified_gmt',
        ));
    }

    public static function get_variations_page(int $parent_id, int $page, int $per_page = 100): array {
        return self::request("products/{$parent_id}/variations", array(
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $per_page)),
            'orderby' => 'id',
            'order' => 'asc',
            '_fields' => 'id,sku,description,price,stock_quantity,stock_status,manage_stock,image,permalink,attributes,date_modified_gmt,status',
        ));
    }

    public static function get_variations_modified_page(int $parent_id, int $page, int $per_page, string $after_gmt, string $before_gmt): array {
        return self::request("products/{$parent_id}/variations", array(
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $per_page)),
            'orderby' => 'modified',
            'order' => 'asc',
            'modified_after' => self::iso($after_gmt),
            'modified_before' => self::iso($before_gmt),
            'dates_are_gmt' => 'true',
            '_fields' => 'id,sku,description,price,stock_quantity,stock_status,manage_stock,image,permalink,attributes,date_modified_gmt,status',
        ));
    }

    public static function get_variation(int $parent_id, int $variation_id): array {
        return self::request("products/{$parent_id}/variations/{$variation_id}", array(
            '_fields' => 'id,sku,description,price,stock_quantity,stock_status,manage_stock,image,permalink,attributes,date_modified_gmt,status',
        ));
    }

    private static function iso(string $gmt): string {
        $ts = strtotime($gmt . ' UTC');
        if ($ts === false) {
            return gmdate('c');
        }
        return gmdate('c', $ts);
    }

    public static function category_path(array $category_ids, array $map): string {
        $paths = array();
        foreach ($category_ids as $category_id) {
            $current = (int) $category_id;
            $parts = array();
            $visited = array();
            $depth = 0;
            while ($current > 0 && isset($map[$current]) && $depth < 20) {
                if (isset($visited[$current])) {
                    break;
                }
                $visited[$current] = true;
                $name = trim((string) ($map[$current]['name'] ?? ''));
                if ($name !== '') {
                    array_unshift($parts, $name);
                }
                $current = (int) ($map[$current]['parent'] ?? 0);
                $depth++;
            }
            if ($parts) {
                $paths[] = implode(' > ', $parts);
            }
        }
        $paths = array_values(array_unique($paths));
        return $paths ? wp_json_encode($paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    }

    /**
     * آرایه‌ی مسطح [{id, name, parent_id}] از ریشه تا برگ برای همه‌ی دسته‌های
     * یک محصول، بدون تکرار (برای خروجی REST؛ جدا از category_path() که فقط
     * برای ذخیره‌ی رشته‌ی نام‌محور استفاده می‌شود).
     */
    public static function category_tree(array $category_ids, array $map): array {
        $result = array();
        $seen = array();
        foreach ($category_ids as $category_id) {
            $current = (int) $category_id;
            $chain = array();
            $visited = array();
            $depth = 0;
            while ($current > 0 && isset($map[$current]) && $depth < 20) {
                if (isset($visited[$current])) {
                    break;
                }
                $visited[$current] = true;
                $chain[] = $current;
                $current = (int) ($map[$current]['parent'] ?? 0);
                $depth++;
            }
            $chain = array_reverse($chain);
            foreach ($chain as $category_node_id) {
                if (isset($seen[$category_node_id])) {
                    continue;
                }
                $seen[$category_node_id] = true;
                $parent_id = (int) ($map[$category_node_id]['parent'] ?? 0);
                $result[] = array(
                    'id' => $category_node_id,
                    'name' => (string) ($map[$category_node_id]['name'] ?? ''),
                    'parent_id' => $parent_id > 0 ? $parent_id : null,
                );
            }
        }
        return $result;
    }
}
