<?php

defined('ABSPATH') || exit;

final class HCI_Source_Client {
    private const PRODUCTS_CACHE_TRANSIENT = 'hci_products_cache';
    private const PRODUCTS_CACHE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MAX_PAGES = 30; // سقف ایمنی (≈۳۰۰۰ محصول) برای جلوگیری از حلقه بی‌پایان روی یک منبع خراب

    /**
     * کل محصولات فعال منبع را برمی‌گرداند. برخلاف /products که فیلتر/مرتب‌سازی
     * سمت سرور کافی برایGrid فاز ۲ ندارد، اینجا یک‌بار همه صفحات را می‌گیریم و
     * در یک Transient کش می‌کنیم تا تغییر فیلتر/صفحه در UI ادمین دوباره به
     * منبع درخواست نزند؛ فقط با $force_refresh یا انقضای کش دوباره Fetch می‌شود.
     */
    public static function get_all_products(bool $force_refresh = false): array {
        if (!$force_refresh) {
            $cached = get_transient(self::PRODUCTS_CACHE_TRANSIENT);
            if (is_array($cached)) {
                return array('success' => true, 'items' => $cached, 'from_cache' => true);
            }
        }

        $api_url = trim((string) get_option('hci_api_url', ''));
        $api_key = trim((string) get_option('hci_api_key', ''));
        if ($api_url === '' || $api_key === '') {
            return array('success' => false, 'items' => array(), 'message' => 'API URL و API Key باید هر دو تنظیم شوند.');
        }

        $items = array();
        $page = 1;
        $total_pages = 1;
        do {
            $result = self::fetch_products_page($api_url, $api_key, $page);
            if (!$result['success']) {
                return array('success' => false, 'items' => array(), 'message' => $result['message']);
            }
            $page_items = is_array($result['data']) ? $result['data'] : array();
            $items = array_merge($items, $page_items);
            $total_pages = (int) ($result['pagination']['total_pages'] ?? $page);
            $page++;
        } while ($page <= $total_pages && $page <= self::MAX_PAGES && !empty($page_items));

        set_transient(self::PRODUCTS_CACHE_TRANSIENT, $items, self::PRODUCTS_CACHE_TTL);
        return array('success' => true, 'items' => $items, 'from_cache' => false);
    }

    public static function clear_products_cache(): void {
        delete_transient(self::PRODUCTS_CACHE_TRANSIENT);
    }

    private static function fetch_products_page(string $api_url, string $api_key, int $page): array {
        // add_query_arg() به‌جای ساخت دستی '?'+http_build_query لازم است: وقتی
        // Permalinks سایت منبع روی Plain باشد، rest_url() آدرسی مثل
        // '.../index.php?rest_route=/hmw/v1' برمی‌گرداند که خودش از قبل یک '?'
        // دارد؛ اضافه‌کردن یک '?' دیگر بعد از '/products' باعث می‌شد کوئری‌استرینگ
        // به‌اشتباه پارس شود (rest_route با '?page=1' آلوده می‌شد و هیچ Route‌ای
        // مچ نمی‌کرد). add_query_arg() هر دو فرمت (Pretty Permalinks و
        // ?rest_route=) را درست merge می‌کند.
        $url = add_query_arg(array(
            'page' => $page,
            'per_page' => 100,
            'include_inactive' => 'false',
        ), rtrim($api_url, '/') . '/products');
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'خطا در ارتباط با سرور منبع: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['success'])) {
            return array(
                'success' => false,
                'message' => sprintf('پاسخ سرور منبع: HTTP %d%s', $status, self::extract_error_message($json, $response)),
            );
        }

        return array(
            'success' => true,
            'data' => $json['data'] ?? array(),
            'pagination' => $json['pagination'] ?? array(),
        );
    }

    private static function extract_error_message($json, $response): string {
        $message = '';
        if (is_array($json)) {
            if (!empty($json['error']['message'])) {
                $message = (string) $json['error']['message'];
            } elseif (!empty($json['message'])) {
                $message = (string) $json['message'];
            }
        }
        if ($message === '') {
            $message = wp_remote_retrieve_response_message($response);
        }
        return $message !== '' ? ' — ' . $message : '';
    }

    public static function test_connection(string $api_url, string $api_key): array {
        $api_url = trim($api_url);
        $api_key = trim($api_key);

        if ($api_url === '' || $api_key === '') {
            return array('success' => false, 'message' => 'API URL و API Key باید هر دو تنظیم شوند.');
        }

        $url = rtrim($api_url, '/') . '/health';
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'خطا در ارتباط با سرور منبع: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            // خطاهای Auth (permission_callback) به شکل استاندارد WP_Error سریالایز
            // می‌شوند: {"code","message","data":{"status"}} — نه شکل سفارشی
            // {"error":{"message"}} که فقط برای خطاهای سطح Handler استفاده شده.
            // هر دو شکل باید هندل شوند وگرنه دلیل واقعی خطا (مثل کلید نامعتبر)
            // بی‌صدا با پیام عمومی HTTP جایگزین می‌شود.
            return array(
                'success' => false,
                'message' => sprintf('پاسخ سرور منبع: HTTP %d%s', $status, self::extract_error_message($json, $response)),
                'status' => $status,
            );
        }

        if (!is_array($json) || empty($json['success'])) {
            return array('success' => false, 'message' => 'پاسخ سرور منبع معتبر (JSON) نبود.', 'status' => $status);
        }

        return array(
            'success' => true,
            'message' => 'اتصال موفق بود.',
            'status' => $status,
            'data' => $json['data'] ?? null,
        );
    }
}
